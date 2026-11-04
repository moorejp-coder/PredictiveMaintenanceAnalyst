<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OverrideLog;
use App\Models\User;
use App\Support\AccountTraces;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Everything PRISM holds about one account, as a JSON-ready array (GDPR right
 * of access and data portability).
 *
 * Every field is picked by name. Nothing is exported by dumping a row, so a
 * column added later never leaks by accident. Left out on purpose:
 * - Internal fields: the database id, password hash, remember token, link
 *   token hashes, session ids and rate-limit counters. They are security
 *   material or bookkeeping, not information about the person.
 * - Other people: only rows this account wrote are read. IP addresses of
 *   wrong passwords and of failed sign-ins could belong to someone else, so
 *   they are counted, not listed. Ids of other people's entries in a logged
 *   path are replaced with "{id}".
 */
final class PersonalDataExport
{
    public const FORMAT_VERSION = 1;

    /** Log context fields copied for a line from this account's own signed-in requests. */
    private const OWN_LOG_FIELDS = ['ip', 'method', 'path', 'route', 'reason', 'field', 'length', 'excerpt'];

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $entries = OverrideLog::withTrashed()->where('user_id', $user->getKey())->orderBy('id')->get();
        $ownIds = array_values(array_map(fn ($id): string => (string) $id, $entries->pluck('uuid')->all()));

        return [
            'export' => [
                'service' => 'PRISM (prismcua.duckdns.org)',
                'format_version' => self::FORMAT_VERSION,
                'generated_at' => now()->utc()->toIso8601String(),
                'times' => 'UTC, ISO 8601',
            ],
            'profile' => $this->profile($user),
            'override_entries' => $entries->map(fn (OverrideLog $log): array => $this->entry($log))->values()->all(),
            'activity' => [
                'pending_invite_link' => $this->inviteLink((string) $user->email),
                'signed_in_sessions' => $this->sessions((int) $user->getKey()),
                'log_lines' => $this->logLines((int) $user->getKey(), (string) $user->email, $ownIds),
            ],
            'connected_services' => $this->connectedServices(),
            'not_included' => [
                'AI answer ratings carry no account, so none of them can be tied to you.',
                'PRISM stores no uploaded files.',
                'Logs kept by the hosting platform (AWS Elastic Beanstalk) are outside the app. The live site writes its application log there too, so log_lines above lists only what is in PRISM\'s own log files on the server.',
                'Security material and bookkeeping: the password hash, link token hashes, session ids, the internal account number and rate-limit counters.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $ips = $user->failed_login_ips ?? [];

        return [
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->isAdmin() ? 'admin' : 'user',
            'email_verified_at' => self::time($user->email_verified_at),
            'created_at' => self::time($user->created_at),
            'updated_at' => self::time($user->updated_at),
            'wrong_password_attempts_in_a_row' => (int) $user->failed_login_attempts,
            'first_wrong_password_at' => self::time($user->first_failed_login_at),
            // Counted, not listed: a wrong password may come from someone else.
            'wrong_password_ip_addresses_on_record' => count($ips),
            'locked_at' => self::time($user->locked_at),
            'unlock_email_sent_at' => self::time($user->unlock_sent_at),
        ];
    }

    /** @return array<string, mixed> */
    private function entry(OverrideLog $log): array
    {
        return [
            'id' => $log->uuid,
            'created_at' => self::time($log->created_at),
            'reason' => $log->reason,
            'original_reason' => $log->original_reason,
            'edited_at' => self::time($log->edited_at),
            'deleted_by_you_at' => self::time($log->deleted_at),
            'machine' => $log->machine,
            'scenario' => $log->scenario,
            'reading_number' => $log->tick,
            'risk_score' => $log->risk_score,
            'action_shown' => $log->action_shown,
            'simulated_reading' => $log->reading,
        ];
    }

    /** @return array{created_at: string|null}|null */
    private function inviteLink(string $email): ?array
    {
        $table = (string) config('auth.passwords.invites.table', 'password_reset_tokens');
        $created = DB::table($table)->where('email', $email)->value('created_at');

        return $created === null ? null : ['created_at' => self::time(Carbon::parse((string) $created))];
    }

    /** @return list<array{last_active_at: string|null}> */
    private function sessions(int $userId): array
    {
        $found = array_map(
            fn (string $path): array => ['last_active_at' => self::time(Carbon::createFromTimestamp((int) filemtime($path)))],
            AccountTraces::sessionFiles($userId),
        );

        $table = (string) config('session.table', 'sessions');
        if (Schema::hasTable($table)) {
            foreach (DB::table($table)->where('user_id', $userId)->pluck('last_activity') as $time) {
                $found[] = ['last_active_at' => self::time(Carbon::createFromTimestamp((int) $time))];
            }
        }

        return $found;
    }

    /**
     * Lines in PRISM's own log files about this account. A line from its own
     * signed-in request keeps the request details. A line about a sign-in
     * attempt on its email keeps only the time and the event, because the
     * attempt may have come from someone else.
     *
     * @param  list<string>  $ownIds
     * @return list<array<string, mixed>>
     */
    private function logLines(int $userId, string $email, array $ownIds): array
    {
        $folder = AccountTraces::logFolder();
        if (! is_dir($folder)) {
            return [];
        }

        $hashes = AccountTraces::emailHashes($email);
        $lines = [];
        foreach (File::glob($folder.'/*.log') as $path) {
            foreach (file($path) ?: [] as $raw) {
                // Laravel's line format: "[time] channel.LEVEL: message {context}",
                // with " []" or " {extra}" after it only when extra data exists.
                if (preg_match('/^\[([^\]]+)\] [\w-]+\.(\w+): (.*?) (\{.*?\})(?: (?:\[\]|\{.*\}))?\s*$/', $raw, $m) !== 1) {
                    continue;
                }
                $context = json_decode($m[4], true);
                if (! is_array($context)) {
                    continue;
                }

                $own = isset($context['user_id']) && is_int($context['user_id']) && $context['user_id'] === $userId;
                $aboutEmail = isset($context['email_hash']) && in_array($context['email_hash'], $hashes, true);
                if (! $own && ! $aboutEmail) {
                    continue;
                }

                $line = ['time' => $m[1], 'level' => strtolower($m[2]), 'event' => $m[3]];
                if ($own) {
                    foreach (self::OWN_LOG_FIELDS as $field) {
                        if (isset($context[$field]) && is_scalar($context[$field])) {
                            $line[$field] = $field === 'path' ? self::maskIds((string) $context[$field], $ownIds) : $context[$field];
                        }
                    }
                }
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return list<array{service: string, what_it_received: string, held_by_prism: string}> */
    private function connectedServices(): array
    {
        return [
            [
                'service' => 'Amazon Simple Email Service (AWS)',
                'what_it_received' => 'Your email address and the text of each PRISM email to you: the invite link and any account lock email.',
                'held_by_prism' => 'No copy of the emails and no send history. AWS keeps its own delivery records under the AWS Privacy Notice.',
            ],
            [
                'service' => 'Anthropic API ("Explain this reading")',
                'what_it_received' => 'Only the simulated score result: numbers and labels. No account data and no text you typed.',
                'held_by_prism' => 'Nothing tied to you.',
            ],
            [
                'service' => 'Have I Been Pwned (password breach check)',
                'what_it_received' => 'The first 5 characters of a one-way hash of a new password, never the password or your email.',
                'held_by_prism' => 'Nothing.',
            ],
        ];
    }

    /**
     * Replaces any UUID in a path with "{id}" unless it is one of this
     * account's own entries, so a refused request for someone else's entry
     * does not reveal that entry's id.
     *
     * @param  list<string>  $ownIds
     */
    private static function maskIds(string $path, array $ownIds): string
    {
        return (string) preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            fn (array $m): string => in_array(strtolower($m[0]), $ownIds, true) ? $m[0] : '{id}',
            $path,
        );
    }

    /** A stored time as ISO 8601 in UTC, or null when none is stored. */
    private static function time(mixed $time): ?string
    {
        if ($time instanceof CarbonInterface) {
            return $time->copy()->utc()->toIso8601String();
        }

        return is_string($time) && $time !== '' ? Carbon::parse($time)->utc()->toIso8601String() : null;
    }
}
