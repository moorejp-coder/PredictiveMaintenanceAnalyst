<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates or updates the one judge account from environment properties:
 * JUDGE_EMAIL, JUDGE_PASSWORD and optionally JUDGE_NAME. No credential lives
 * in the code. With either value missing, no account is created. The
 * Elastic Beanstalk deploy hook runs this seeder after every migrate, so a
 * rebuilt server gets the account back.
 *
 * Run it on its own: php artisan db:seed --class=JudgeSeeder --force
 */
class JudgeSeeder extends Seeder
{
    public function run(): void
    {
        $email = Str::lower(trim((string) config('prism.judge.email')));
        $password = (string) config('prism.judge.password');

        if ($email === '' || $password === '') {
            Log::warning('JUDGE_EMAIL or JUDGE_PASSWORD is not set. No judge account was created.');

            return;
        }

        // The User model hashes the password on save. email_verified_at is
        // not mass assignable, so set the fields directly. A seeded account
        // counts as ready, so the invite page lists it as Active.
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => (string) config('prism.judge.name'),
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Log::info('Judge account is ready.');
    }
}
