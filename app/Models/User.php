<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\EncryptedList;
use App\Notifications\JudgeInvite;
use App\Support\AuditLog;
use App\Support\LogId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $failed_login_attempts Wrong passwords in a row since the last good sign-in.
 * @property Carbon|null $first_failed_login_at Time of the first wrong password in the current run.
 * @property list<string>|null $failed_login_ips Up to 10 IP addresses the wrong passwords came from, stored encrypted.
 * @property Carbon|null $locked_at Set after 10 wrong passwords in a row. The lock ends 30 minutes later.
 * @property string|null $unlock_token_hash SHA-256 of the newest emailed unlock token.
 * @property Carbon|null $unlock_sent_at When the newest unlock link was sent.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'unlock_token_hash', 'failed_login_ips'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Account changes go into the audit log from here, so every code path that
     * saves a User is covered, including ones added later: a new account, a
     * changed email address and a changed password. PRISM has no page that
     * changes an email address today. The hook is there so one cannot be added
     * without a log line. Only a keyed one-way code of the email is logged,
     * never the address or a password hash.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            AuditLog::record('account.created', [
                'target_user_id' => $user->getKey(),
                'email_code' => LogId::email((string) $user->email),
            ]);
        });

        static::updated(function (User $user): void {
            if ($user->wasChanged('email')) {
                AuditLog::record('account.email_changed', [
                    'target_user_id' => $user->getKey(),
                    'old_email_code' => LogId::email((string) $user->getOriginal('email')),
                    'new_email_code' => LogId::email((string) $user->email),
                ]);
            }

            $request = app()->bound('request') ? app('request') : null;
            $skip = $request !== null && $request->attributes->get(AuditLog::SKIP_PASSWORD_EVENT) === true;
            if ($user->wasChanged('password') && ! $skip) {
                AuditLog::record('account.password_changed', [
                    'target_user_id' => $user->getKey(),
                    'email_code' => LogId::email((string) $user->email),
                ]);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'failed_login_attempts' => 'integer',
            'first_failed_login_at' => 'datetime',
            // Encrypted at rest with APP_KEY (AES-256-CBC with an HMAC). A stored
            // value that does not decrypt reads as empty (App\Casts\EncryptedList).
            'failed_login_ips' => EncryptedList::class,
            'locked_at' => 'datetime',
            'unlock_sent_at' => 'datetime',
        ];
    }

    /**
     * The only password email PRISM sends is the judge invite. The password
     * broker calls this with the token.
     */
    /**
     * An admin has a verified email that equals ADMIN_EMAIL or appears in
     * ADMIN_EMAILS (config/prism.php). Accounts exist only through the invite
     * page or the seeders, so an unverified account has no known password.
     */
    public function isAdmin(): bool
    {
        if ($this->email_verified_at === null) {
            return false;
        }

        $listed = array_merge(
            explode(',', (string) config('prism.admin.emails')),
            [(string) config('prism.admin.email')],
        );
        $admins = array_filter(array_map(fn (string $email): string => Str::lower(trim($email)), $listed));

        return in_array(Str::lower((string) $this->email), $admins, true);
    }

    public const LOCK_MINUTES = 30;

    /** True for 30 minutes after the tenth wrong password in a row, unless the emailed link ends it sooner. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null && $this->locked_at->getTimestamp() > now()->subMinutes(self::LOCK_MINUTES)->getTimestamp();
    }

    /** Forgets the wrong-password run and any lock. */
    public function clearFailedLogins(): void
    {
        $this->forceFill([
            'failed_login_attempts' => 0,
            'first_failed_login_at' => null,
            'failed_login_ips' => null,
            'locked_at' => null,
        ]);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new JudgeInvite($token));
    }
}
