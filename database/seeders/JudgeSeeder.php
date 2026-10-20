<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

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
        $email = trim((string) config('prism.judge.email'));
        $password = (string) config('prism.judge.password');

        if ($email === '' || $password === '') {
            $this->command?->warn('JUDGE_EMAIL or JUDGE_PASSWORD is not set. No judge account was created.');

            return;
        }

        // The User model hashes the password on save.
        User::updateOrCreate(
            ['email' => $email],
            ['name' => (string) config('prism.judge.name'), 'password' => $password],
        );

        $this->command?->info('Judge account is ready.');
    }
}
