<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\AuditLog;
use App\Support\LogId;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates or updates the entrant's admin account from environment
 * properties: ADMIN_EMAIL, ADMIN_PASSWORD (at least 12 characters) and
 * optionally ADMIN_NAME. No credential lives in the code. With a value
 * missing or the password too short, no account is changed. The Elastic
 * Beanstalk deploy hook runs this seeder after every migrate, so a rebuilt
 * server gets the account back. The account is an admin because its email
 * equals ADMIN_EMAIL (see User::isAdmin).
 *
 * Run it on its own: php artisan db:seed --class=AdminSeeder --force
 */
class AdminSeeder extends Seeder
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function run(): void
    {
        $email = Str::lower(trim((string) config('prism.admin.email')));
        $password = (string) config('prism.admin.password');

        if ($email === '' || $password === '') {
            Log::warning('ADMIN_EMAIL or ADMIN_PASSWORD is not set. No admin account was created.');

            return;
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            Log::warning('ADMIN_PASSWORD is shorter than '.self::MIN_PASSWORD_LENGTH.' characters. No admin account was changed.');

            return;
        }

        $user = User::firstOrNew(['email' => $email]);
        $wasAdmin = $user->isAdmin();
        $user->forceFill([
            'name' => (string) config('prism.admin.name'),
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        // There is no role column. An account is an admin because its verified
        // email equals ADMIN_EMAIL (User::isAdmin), so this is where a role
        // takes effect. Removing an admin means changing the environment
        // properties, which this log cannot see.
        if (! $wasAdmin && $user->isAdmin()) {
            AuditLog::record('account.role_changed', [
                'target_user_id' => $user->getKey(),
                'email_code' => LogId::email($email),
                'role' => 'admin',
                'change' => 'granted',
                'source' => 'ADMIN_EMAIL seeder',
            ]);
        }

        Log::info('Admin account is ready.');
    }
}
