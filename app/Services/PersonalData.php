<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OverrideLog;
use App\Models\User;
use App\Notifications\AccountDeleted;
use App\Support\AccountTraces;
use App\Support\AuditLog;
use App\Support\EmailBudget;
use App\Support\LogId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deletes personal data for a deletion request (Privacy Policy, section 7).
 * Everything here removes rows for good. The override log's own delete
 * (DELETE /api/overrides/{id}) stays a soft delete for the audit trail, so
 * this class is the only way a reason leaves the database.
 *
 * Logs record counts and a keyed hash of the email, never the email or reason.
 */
final class PersonalData
{
    /** Named rate limits counted per account (App\Support\RateLimits). Two-window limits add a suffix. */
    private const PER_ACCOUNT_LIMITS = [
        'invites' => [''], 'heavy-read' => [''], 'api-read' => [''], 'api-write' => [''], 'uploads' => [''],
        'ai-diagnosis' => ['|minute', '|hour'], 'ai-work-order' => ['|minute', '|hour'],
    ];

    /**
     * Removes everything PRISM holds about one account, for good:
     *
     * - Database: the account row, every override entry it wrote (soft-deleted
     *   ones too), its invite links and any database session rows.
     * - Server files: every session file signed in to the account, and the
     *   lines in PRISM's own log files that name the account (by user id or
     *   email hash). PRISM stores no uploaded files.
     * - Cache: the rate-limit and AI-spend counters kept for the account.
     *
     * AI ratings carry no account, so none of them can be tied to the person.
     * A log the hosting platform keeps (the live site logs to stderr, which
     * Elastic Beanstalk stores) is outside the app's reach.
     *
     * @return array{account: int, overrides: int, links: int, sessions: int, counters: int, log_lines: int}
     */
    public function deleteAccount(User $user): array
    {
        $userId = (int) $user->getKey();
        $email = (string) $user->email;

        $result = DB::transaction(function () use ($user, $email, $userId): array {
            $overrides = (int) $this->overridesOf($user)->forceDelete();
            $links = DB::table((string) config('auth.passwords.invites.table', 'password_reset_tokens'))
                ->where('email', $email)
                ->delete();
            $sessionRows = Schema::hasTable((string) config('session.table', 'sessions'))
                ? DB::table((string) config('session.table', 'sessions'))->where('user_id', $userId)->delete()
                : 0;
            $account = (int) $user->delete();

            return ['account' => $account, 'overrides' => $overrides, 'links' => $links, 'sessions' => $sessionRows];
        });

        $result['sessions'] += $this->deleteSessionFiles($userId);
        $result['counters'] = $this->forgetCounters($userId);
        $result['log_lines'] = $this->scrubLogFiles($userId, $email);

        // A record that a deletion happened, with no way back to the person:
        // the account id no longer exists and the email is a keyed hash.
        Log::notice('Account deleted on request', $result);
        // The audit line names the account by its number and the keyed email
        // code. The actor field shows who asked: the same account (self
        // deletion), an admin, or nobody for the artisan command.
        AuditLog::record('account.deleted', ['target_user_id' => $userId, 'email_code' => LogId::email($email), 'removed' => $result]);

        return $result;
    }

    /**
     * Emails the confirmation to the address the account used. A mail
     * failure or the daily email cap never undoes the deletion. The address
     * is not stored anywhere for this.
     *
     * @param  array{account: int, overrides: int, links: int, sessions: int, counters: int, log_lines: int}  $removed
     */
    public function sendConfirmation(string $email, array $removed): bool
    {
        if (! EmailBudget::available()) {
            Log::error('Account deletion email skipped: daily email cap reached');

            return false;
        }
        EmailBudget::record();

        try {
            Notification::route('mail', $email)->notify(new AccountDeleted($removed));
        } catch (Throwable $e) {
            Log::error('Could not send the account deletion email', ['class' => $e::class]);

            return false;
        }

        return true;
    }

    /** Deletes the session files whose signed-in user is this account. Other drivers keep no files. */
    private function deleteSessionFiles(int $userId): int
    {
        $deleted = 0;
        foreach (AccountTraces::sessionFiles($userId) as $path) {
            if (@unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Clears the counters keyed by the account id. They hold numbers only, but name the account. */
    private function forgetCounters(int $userId): int
    {
        $keys = ['ai-spend-user:user:'.$userId];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                $name = is_string($middleware) && str_starts_with($middleware, 'throttle:') ? substr($middleware, 9) : '';
                foreach (self::PER_ACCOUNT_LIMITS[$name] ?? [] as $suffix) {
                    foreach ($route->methods() as $method) {
                        // Same key ThrottleRequests builds: md5(limiter name + limit key).
                        $keys[] = md5($name.$method.' '.$route->uri().'|user:'.$userId.$suffix);
                    }
                }
            }
        }

        $cleared = 0;
        foreach (array_unique($keys) as $key) {
            if (RateLimiter::attempts($key) > 0) {
                $cleared++;
            }
            RateLimiter::clear($key);
        }

        return $cleared;
    }

    /**
     * Removes the lines in PRISM's own log files (storage/logs) that name the
     * account by user id or by email hash, keyed or plain.
     */
    private function scrubLogFiles(int $userId, string $email): int
    {
        $folder = AccountTraces::logFolder();
        if (! is_dir($folder)) {
            return 0;
        }

        $needles = [
            '"user_id":'.$userId.',', '"user_id":'.$userId.'}',
            ...array_map(fn (string $hash): string => '"email_hash":"'.$hash.'"', AccountTraces::emailHashes($email)),
        ];
        $removed = 0;
        foreach (File::glob($folder.'/*.log') as $path) {
            $lines = file($path) ?: [];
            $kept = array_filter($lines, fn (string $line): bool => ! Str::contains($line, $needles));
            if (count($kept) !== count($lines)) {
                $removed += count($lines) - count($kept);
                File::put($path, implode('', $kept));
            }
        }

        return $removed;
    }

    /**
     * Every entry the account wrote, soft-deleted ones too.
     *
     * @return Builder<OverrideLog>
     */
    public function overridesOf(User $user): Builder
    {
        return OverrideLog::withTrashed()->where('user_id', $user->getKey());
    }

    /** Removes one override entry for good, soft-deleted or not. */
    public function eraseOverride(OverrideLog $log): void
    {
        $log->forceDelete();
        Log::notice('Override entry erased on request', ['entry' => substr((string) $log->uuid, -8)]);
        AuditLog::record('data.erased', ['scope' => 'override_entry', 'entry' => substr((string) $log->uuid, -8), 'count' => 1]);
    }

    /**
     * Removes every entry the query finds, for good.
     *
     * @param  Builder<OverrideLog>  $query
     */
    public function eraseOverrides(Builder $query): int
    {
        $count = (int) $query->forceDelete();
        Log::notice('Override entries erased on request', ['count' => $count]);
        AuditLog::record('data.erased', ['scope' => 'override_entries', 'count' => $count]);

        return $count;
    }
}
