<?php

declare(strict_types=1);

namespace App\Support;

use App\Notifications\SecurityAlert;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Security alerts: the detectors that watch the audit trail for patterns, and
 * the delivery of an alert by email and Slack.
 *
 * An alert is an audit line (event "alert.<name>", outcome "alert") plus a
 * message to the addresses in ALERT_EMAIL and the Slack webhook in
 * ALERT_SLACK_WEBHOOK. With neither set, the audit line is the whole alert.
 *
 * The detectors:
 *   failed_logins     more than 10 failed sign-ins for one account in 5 minutes
 *   ip_many_accounts  one IP address names more than 50 different accounts
 *   bulk_read_large   one request reads more than 100 records
 *   bulk_read_rapid   more than 10 bulk reads by one account or address in a minute
 *   password_links    3 or more password links sent to one account in an hour
 *   auth_failures     401 and 403 answers above the usual level (AlertAuthFailures)
 *   login_new_country, login_new_network   see LoginOrigins
 * Every number is a setting in config/prism.php (the ALERT_* variables).
 *
 * Three rules keep the alerts usable:
 * - One alert per detector and subject per cooldown (an hour by default), so a
 *   long attack sends one message, not one per request. The audit lines for
 *   the attack itself keep coming.
 * - At most 20 messages an hour per channel. After that the alerts still go
 *   into the audit log, and one alert.notifications_suppressed line says so.
 * - A failed send never changes the answer a visitor gets. The error class goes
 *   to the application log, never the webhook address (it is a secret).
 *
 * A message holds counts, keyed codes (LogId) and the request's IP address.
 * Never an email address, a password or a token.
 */
final class Alerts
{
    /** @var array<string, array{title: string, check: string}> */
    private const CATALOG = [
        'failed_logins' => [
            'title' => 'Many failed sign-ins for one account',
            'check' => 'Search the audit log for auth.login.failed with this email_code. Many IP addresses means a distributed guessing attack. The account locks itself at 10 wrong passwords in a row.',
        ],
        'ip_many_accounts' => [
            'title' => 'One IP address tried many accounts',
            'check' => 'Search the audit log for this IP address. A script is working through a list of emails. Block the address at the load balancer or a web application firewall.',
        ],
        'bulk_read_large' => [
            'title' => 'One request read a large number of records',
            'check' => 'Search the audit log for data.bulk_read from this account or address. Confirm the reader is who it should be.',
        ],
        'bulk_read_rapid' => [
            'title' => 'Many bulk reads in a short time',
            'check' => 'Search the audit log for data.bulk_read and data.export from this account or address. A script may be copying data.',
        ],
        'login_new_country' => [
            'title' => 'Sign-in from a new country',
            'check' => 'Ask the account owner whether they signed in from this country. If not, end their sessions and have them change the password.',
        ],
        'login_new_network' => [
            'title' => 'Sign-in from a new network',
            'check' => 'Mobile and campus networks change often. Ask the account owner only if other alerts for this account appear too.',
        ],
        'password_links' => [
            'title' => 'Several password links sent to one account',
            'check' => 'Search the audit log for account.invite_sent with this email_code. Check who sent the links and whether the owner asked for them.',
        ],
        'auth_failures' => [
            'title' => 'Refused requests above the usual level',
            'check' => 'Search the audit log for authz.denied and authz.unauthenticated in this period. Group them by IP address and path.',
        ],
    ];

    /**
     * Raise an alert once per cooldown for this alert and key.
     *
     * @param  array<string, mixed>  $detail
     * @return bool false when an earlier alert for the same key is still cooling down
     */
    public static function fire(string $alert, string $key, array $detail, ?Request $request = null): bool
    {
        $cooldown = max(1, (int) config('prism.alerts.cooldown_seconds'));
        if (! Cache::add('alert-fired:'.$alert.':'.hash('sha256', $key), true, $cooldown)) {
            return false;
        }

        AuditLog::record('alert.'.$alert, $detail, AuditLog::ALERT, $request);
        self::notify($alert, $detail, $request);

        return true;
    }

    // ------------------------------------------------------------------
    // Detectors
    // ------------------------------------------------------------------

    /** Detector 1. Call for every failed sign-in, whatever the reason. */
    public static function failedLogin(string $email, Request $request): void
    {
        $limit = (int) config('prism.alerts.failed_logins_per_account');
        $window = max(1, (int) config('prism.alerts.failed_logins_window_seconds'));
        $code = LogId::email($email);

        $key = 'alert-failed-logins:'.$code;
        RateLimiter::hit($key, $window);
        $attempts = RateLimiter::attempts($key);
        if ($limit < 1 || $attempts <= $limit) {
            return;
        }

        self::fire('failed_logins', $code, [
            'email_code' => $code,
            'failed_attempts' => $attempts,
            'limit' => $limit,
            'window_seconds' => $window,
        ], $request);
    }

    /**
     * Detector 2. Call for every request that names an account by email: a
     * sign-in, a password page, an unlock page. The cache keeps one keyed
     * code per account for each IP address inside the window.
     */
    public static function accountProbed(string $email, Request $request): void
    {
        $limit = (int) config('prism.alerts.accounts_per_ip');
        $window = max(1, (int) config('prism.alerts.accounts_per_ip_window_seconds'));
        $ip = (string) $request->ip();
        $now = now()->getTimestamp();

        $key = 'alert-ip-accounts:'.$ip;
        $bag = Cache::get($key);
        if (! is_array($bag) || ! isset($bag['since'], $bag['codes']) || ! is_array($bag['codes']) || $now >= $bag['since'] + $window) {
            $bag = ['since' => $now, 'codes' => []];
        }

        $code = LogId::email($email);
        if (! in_array($code, $bag['codes'], true)) {
            // Past limit + 1 codes the answer cannot change, so stop growing.
            if (count($bag['codes']) <= $limit) {
                $bag['codes'][] = $code;
            }
            Cache::put($key, $bag, max(1, (int) $bag['since'] + $window - $now));
        }

        $accounts = count($bag['codes']);
        if ($limit < 1 || $accounts <= $limit) {
            return;
        }

        self::fire('ip_many_accounts', $ip, [
            'different_accounts' => $accounts,
            'limit' => $limit,
            'window_seconds' => $window,
        ], $request);
    }

    /**
     * Detector 3, and the audit line for it. Replaces the plain
     * data.bulk_read call: one record per list a person reads.
     */
    public static function bulkRead(string $scope, int $records, Request $request): void
    {
        AuditLog::record('data.bulk_read', ['scope' => $scope, 'count' => $records], AuditLog::SUCCESS, $request);
        self::watchBulk($scope, $records, $request);
    }

    /**
     * Counts one bulk read or export toward the rapid-request rule, and checks
     * the size rule when the number of records is known.
     */
    public static function watchBulk(string $scope, ?int $records, Request $request): void
    {
        try {
            $maxRecords = (int) config('prism.alerts.bulk_records_per_query');
            $user = $request->user();
            $who = $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.($request->ip() ?? 'unknown');

            if ($records !== null && $maxRecords >= 1 && $records > $maxRecords) {
                self::fire('bulk_read_large', $who.'|'.$scope, [
                    'scope' => $scope,
                    'records' => $records,
                    'limit' => $maxRecords,
                    'admin' => $user !== null && $user->isAdmin(),
                ], $request);
            }

            $maxRequests = (int) config('prism.alerts.bulk_requests_per_window');
            $window = max(1, (int) config('prism.alerts.bulk_window_seconds'));
            $key = 'alert-bulk:'.$who;
            RateLimiter::hit($key, $window);
            $requests = RateLimiter::attempts($key);
            if ($maxRequests >= 1 && $requests > $maxRequests) {
                self::fire('bulk_read_rapid', $who, [
                    'requests' => $requests,
                    'limit' => $maxRequests,
                    'window_seconds' => $window,
                    'last_scope' => $scope,
                ], $request);
            }
        } catch (Throwable $e) {
            Log::error('alert_check_failed', ['alert' => 'bulk_read', 'class' => $e::class]);
        }
    }

    /**
     * Detector 5. Call when PRISM sends a password link to an account. PRISM
     * has no "forgot password" page: a password link comes from the admin's
     * invite page. If a self-service reset is ever added, call this from it.
     */
    public static function passwordLinkSent(string $email, Request $request): void
    {
        $limit = (int) config('prism.alerts.password_links_per_account');
        $window = max(1, (int) config('prism.alerts.password_links_window_seconds'));
        $code = LogId::email($email);

        $key = 'alert-password-links:'.$code;
        RateLimiter::hit($key, $window);
        $links = RateLimiter::attempts($key);
        if ($limit < 1 || $links < $limit) {
            return;
        }

        self::fire('password_links', $code, [
            'email_code' => $code,
            'links_sent' => $links,
            'window_seconds' => $window,
        ], $request);
    }

    /**
     * Detector 6. Call for every 401 and 403 answer (and a refusal shown as
     * 404). The count per 5-minute bucket is compared with the average of the
     * buckets of the last 24 hours.
     *
     * - One address adds at most auth_per_ip_cap to a bucket, so a single tab
     *   that keeps polling after sign-out cannot raise the alarm alone.
     * - The alert needs the count to reach auth_floor and to pass
     *   auth_multiplier times the 24-hour average. A new deployment has no
     *   history, so the floor decides until the average has grown.
     */
    public static function authFailure(Request $request): void
    {
        $size = max(60, (int) config('prism.alerts.auth_bucket_seconds'));
        $hours = max(1, (int) config('prism.alerts.auth_baseline_hours'));
        $floor = (int) config('prism.alerts.auth_floor');
        $cap = max(1, (int) config('prism.alerts.auth_per_ip_cap'));
        $multiplier = max(1.0, (float) config('prism.alerts.auth_multiplier'));
        $buckets = intdiv($hours * 3600, $size);
        $bucket = intdiv(now()->getTimestamp(), $size);

        $ipKey = 'alert-authz:'.$bucket.':ip:'.($request->ip() ?? 'unknown');
        Cache::add($ipKey, 0, $size * 2);
        if ((int) Cache::increment($ipKey) > $cap) {
            return;
        }

        $totalKey = 'alert-authz:'.$bucket.':total';
        Cache::add($totalKey, 0, $size * ($buckets + 2));
        $total = (int) Cache::increment($totalKey);
        if ($floor < 1 || $total < $floor) {
            return;
        }

        $baseline = (float) Cache::remember('alert-authz:'.$bucket.':baseline', $size, function () use ($bucket, $buckets): float {
            $keys = [];
            for ($i = 1; $i <= $buckets; $i++) {
                $keys[] = 'alert-authz:'.($bucket - $i).':total';
            }
            $sum = 0;
            foreach (Cache::many($keys) as $value) {
                $sum += (int) $value;
            }

            return $sum / max(1, $buckets);
        });

        if ($total <= $multiplier * $baseline) {
            return;
        }

        self::fire('auth_failures', 'all', [
            'refused_requests' => $total,
            'window_seconds' => $size,
            'baseline_per_window' => round($baseline, 2),
            'baseline_hours' => $hours,
            'multiplier' => $multiplier,
            'floor' => $floor,
        ], $request);
    }

    // ------------------------------------------------------------------
    // Delivery
    // ------------------------------------------------------------------

    /** @param  array<string, mixed>  $detail */
    private static function notify(string $alert, array $detail, ?Request $request): void
    {
        $request ??= app()->bound('request') ? app('request') : null;
        $message = [
            'title' => self::CATALOG[$alert]['title'] ?? $alert,
            'check' => self::CATALOG[$alert]['check'] ?? 'Search the audit log for this event.',
            'lines' => self::lines($alert, $detail, $request),
        ];

        // Sends once. A long-running process (a test, a queue worker) keeps its
        // terminating callbacks and runs them again after the next request.
        $sent = false;
        $send = static function () use ($message, &$sent): void {
            if ($sent) {
                return;
            }
            $sent = true;
            self::deliver($message);
        };

        // Inside a web request the send waits until the response has gone out,
        // so a slow mail server or Slack never slows a visitor down.
        if ($request !== null && $request->route() !== null) {
            app()->terminating($send);

            return;
        }
        $send();
    }

    /**
     * @param  array{title: string, check: string, lines: list<string>}  $message
     */
    private static function deliver(array $message): void
    {
        $emails = config('prism.alerts.email');
        if (is_array($emails) && $emails !== [] && self::allow('email')) {
            try {
                Notification::route('mail', $emails)->notify(new SecurityAlert($message['title'], $message['check'], $message['lines']));
            } catch (Throwable $e) {
                Log::error('alert_notify_failed', ['channel' => 'email', 'class' => $e::class]);
            }
        }

        $webhook = config('prism.alerts.slack_webhook');
        if (is_string($webhook) && str_starts_with($webhook, 'https://') && self::allow('slack')) {
            try {
                $text = "PRISM security alert: {$message['title']}\n".implode("\n", $message['lines'])."\nWhat to check: {$message['check']}";
                Http::connectTimeout(2)->timeout(4)->asJson()
                    ->post($webhook, ['text' => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text)])
                    ->throw();
            } catch (Throwable $e) {
                // The exception text can hold the webhook address. Log the class and the status only.
                Log::error('alert_notify_failed', [
                    'channel' => 'slack',
                    'class' => $e::class,
                    'status' => $e instanceof RequestException ? $e->response->status() : null,
                ]);
            }
        }
    }

    /** One channel gets at most max_notifications_per_hour messages an hour. */
    private static function allow(string $channel): bool
    {
        $key = 'alert-notify:'.$channel;
        $max = max(1, (int) config('prism.alerts.max_notifications_per_hour'));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            if (Cache::add($key.':reported', true, 3600)) {
                AuditLog::record('alert.notifications_suppressed', ['channel' => $channel, 'limit_per_hour' => $max], AuditLog::ALERT);
            }

            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return list<string>
     */
    private static function lines(string $alert, array $detail, ?Request $request): array
    {
        $lines = ['Event: alert.'.$alert, 'Time (UTC): '.now()->utc()->format('Y-m-d H:i:s')];
        $ip = $request?->ip();
        if (is_string($ip) && $ip !== '') {
            $lines[] = 'Request IP address: '.$ip;
        }
        foreach ($detail as $name => $value) {
            $lines[] = $name.': '.self::plain($value);
        }

        return $lines;
    }

    private static function plain(mixed $value): string
    {
        $text = match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', array_map(fn ($item): string => is_scalar($item) ? (string) $item : '', $value)),
            is_scalar($value) => (string) $value,
            default => 'none',
        };

        return mb_substr((string) preg_replace('/\p{C}+/u', ' ', mb_scrub($text)), 0, 200);
    }
}
