<?php

namespace Database\Seeders;

use App\Models\User;
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
        $user->forceFill([
            'name' => (string) config('prism.admin.name'),
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Log::info('Admin account is ready.');
    }
}
