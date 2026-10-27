<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\JudgeInvite;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new JudgeInvite($token));
    }
}
