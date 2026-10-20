<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The only account PRISM ever creates is the judge account, and only
     * when JUDGE_EMAIL and JUDGE_PASSWORD are set. No default or test user.
     */
    public function run(): void
    {
        $this->call(JudgeSeeder::class);
    }
}
