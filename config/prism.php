<?php

/*
|--------------------------------------------------------------------------
| PRISM scoring limits
|--------------------------------------------------------------------------
|
| Every limit the risk checks use lives here, with its source. This file
| returns a plain array and calls no Laravel helper, so unit tests load it
| without booting the app (see App\Services\Limits).
|
| Sources:
| - AI4I limits: AI4I 2020 dataset documentation (Matzka, 2020). The heat,
|   power and overstrain rules reproduce every label in the 10,000 records.
| - Vibration: Group 2 rigid-foundation zone boundaries listed for ISO 20816-3,
|   which replaced ISO 10816-3. Checked in secondary sources, not in the
|   standard's text. The standard covers industrial machines above 15 kW and
|   does not list machine tools, so these are demo values. Not in AI4I, not
|   backtested.
| - Run hours: demo placeholder. Not in AI4I, not backtested.
|
*/

return [

    'tool_wear' => [
        // Failures fall in this window of tool wear minutes (AI4I).
        'window_start_min' => 200,
        'window_end_min' => 240,
        // Stress starts rising here and reaches the cap at window_start_min.
        'ramp_start_min' => 150,
        // Tool wear alone never reads as certain failure: only about 5% of
        // records in the 200-240 min window failed.
        'cap' => 0.6,
    ],

    'heat' => [
        // Heat fails when the process runs under this many K above the air
        // and the speed is under the speed limit (AI4I).
        'gap_limit_k' => 8.6,
        'gap_margin_k' => 1.0,
        'speed_limit_rpm' => 1380,
        'speed_margin_rpm' => 100,
    ],

    'power' => [
        // Safe power range in watts (AI4I).
        'min_w' => 3500,
        'max_w' => 9000,
        'margin_w' => 1000,
    ],

    'overstrain' => [
        // Limit in minute-newton-meters, by product quality variant (AI4I).
        'limit_by_quality' => ['L' => 11000, 'M' => 12000, 'H' => 13000],
        'ramp_start' => 0.8,
        'ramp_width' => 0.2,
    ],

    'vibration' => [
        // mm/s RMS. Below warn is normal, at limit damage is likely. Demo values.
        'warn' => 1.4,
        'limit' => 4.5,
    ],

    'run_hours' => [
        // Machine hours since the last overhaul. Demo placeholder.
        'start' => 6000,
        'limit' => 8000,
        // Run hours alone never read as certain failure: a machine past its
        // interval needs service, not a stop.
        'cap' => 0.5,
    ],

    // Password for the /overrides review page. Leave unset and the page returns 404.
    'override_log_password' => env('OVERRIDE_LOG_PASSWORD'),

    // Sign-in gate. Off by default: the dashboard is public and /login goes
    // back to /. Set REQUIRE_LOGIN=true (an Elastic Beanstalk property) with
    // JUDGE_EMAIL and JUDGE_PASSWORD to turn it on. Planned for after HTTPS,
    // because a password sent over HTTP is readable on the network.
    'require_login' => (bool) env('REQUIRE_LOGIN', false),

    // Password for the /admin/invites page, where the entrant invites judges
    // by email. Leave unset and the page returns 404. Use a value that no
    // other PRISM password uses. Needs REQUIRE_LOGIN=true and working MAIL_*
    // settings.
    'invite_admin_password' => env('INVITE_ADMIN_PASSWORD'),

    // Optional: one fixed judge account made by JudgeSeeder. The invite page
    // is the main way to make judge accounts. JudgeSeeder reads these. Set them as Elastic
    // Beanstalk environment properties, never in the code. With either
    // JUDGE_EMAIL or JUDGE_PASSWORD missing, no account exists.
    'judge' => [
        'name' => env('JUDGE_NAME', 'Contest Judge'),
        'email' => env('JUDGE_EMAIL'),
        'password' => env('JUDGE_PASSWORD'),
    ],

    // Backtest of the four AI4I checks on the 10,000 AI4I records, 18 random
    // failure records left out. Reproduce with `php artisan prism:backtest`.
    // Keep these in step with the README. Shown on the dashboard and /ai.
    // The AI investigator: Claude checks the reading with PRISM's own read-only
    // tools before it names a cause. Set PRISM_INVESTIGATOR=false to go back to
    // the single Diagnose call. The score never depends on this switch.
    'investigator' => (bool) env('PRISM_INVESTIGATOR', true),

    'backtest' => [
        'high' => ['failed' => 291, 'total' => 645],
        'elevated' => ['failed' => 42, 'total' => 1889],
        'low' => ['failed' => 6, 'total' => 7448],
    ],

    // Page dates. Each page shows its date from here. The hash is a SHA-256 of
    // the page text from <main> to </main>. tests/Feature/PolicyDatesTest.php
    // fails when the text changes and the hash here does not, and the failure
    // message prints the new hash. When you update a hash, set that page's date
    // to the day you changed the text.
    'policy_dates' => [
        'faq' => ['date' => 'October 22, 2026', 'hash' => 'c064c9fbf9d484054e0e8796af744fae90e120ded497756bac5b894faad517c6'],
        'privacy' => ['date' => 'October 21, 2026', 'hash' => '8d24a9913d4420b47772b5fc80b024959dc4fba2502d60eb2f0dff21003efe06'],
        'terms' => ['date' => 'October 22, 2026', 'hash' => '264283f7882e9ae3b9832ee003de003f3296b36e6c19dd938f2ff3e0513b1dab'],
        'ai' => ['date' => 'October 22, 2026', 'hash' => 'aa8a9565529e11c9e4f3ad563cfaede7ddf8d9aa2961a41af79f205d2d63ac07'],
    ],

    'bands' => [
        'elevated_from' => 40,
        'high_from' => 70,
    ],

];
