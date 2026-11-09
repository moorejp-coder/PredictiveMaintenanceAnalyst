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

    // Sign-in gate. Off by default: the dashboard is public and /login goes
    // back to /. Set REQUIRE_LOGIN=true (an Elastic Beanstalk property) with
    // JUDGE_EMAIL and JUDGE_PASSWORD to turn it on. Planned for after HTTPS,
    // because a password sent over HTTP is readable on the network.
    // Days a signed-in session lasts at most, however active (EnforceSessionAge).
    'session_max_days' => (int) env('SESSION_MAX_DAYS', 7),
    // Fails closed: with no REQUIRE_LOGIN property set, production requires
    // sign-in. Any other environment keeps it off unless the property is set.
    'require_login' => (bool) env('REQUIRE_LOGIN', env('APP_ENV') === 'production'),

    // Most AI answers the whole site serves per rolling day, counting every
    // visitor and the Judge Demo together. Caps the AI bill. See CapAiCalls.
    'ai_daily_cap' => (int) env('PRISM_AI_DAILY_CAP', 1000),

    // AI spending in US dollars, worked out from the token counts the API
    // reports (app/Support/AiBudget.php). Default prices are the Claude Haiku
    // 5.5 list prices for prompts up to 100,000 tokens, from
    // https://platform.claude.com/docs/en/about-claude/pricing (checked
    // November 2, 2026). PRISM's prompts are about 6,000 tokens. Change the
    // prices if ANTHROPIC_MODEL changes. One fresh "Explain this reading"
    // costs about $0.0013 for the investigation (5,911 input and 1,377 output
    // tokens, docs/EVAL.md) plus the short work order call. Answers already
    // in the cache cost nothing.
    'ai_budget' => [
        'input_usd_per_mtok' => (float) env('PRISM_AI_INPUT_USD_PER_MTOK', 0.10),
        'output_usd_per_mtok' => (float) env('PRISM_AI_OUTPUT_USD_PER_MTOK', 0.50),
        // The whole site, per rolling day.
        'daily_usd' => (float) env('PRISM_AI_DAILY_BUDGET_USD', 1.00),
        // One account, or one IP address without an account, per rolling hour.
        'user_hourly_usd' => (float) env('PRISM_AI_USER_HOURLY_BUDGET_USD', 0.05),
    ],

    // Most emails the whole site sends per rolling day: judge invites and
    // account lock emails together (app/Support/EmailBudget.php).
    'email_daily_cap' => (int) env('PRISM_EMAIL_DAILY_CAP', 100),

    // Admin accounts. The entrant's own accounts open /admin/invites and the
    // /overrides review page. There is no shared password. An account is an
    // admin when its email is verified and equals ADMIN_EMAIL or appears in
    // ADMIN_EMAILS (comma separated). AdminSeeder creates the ADMIN_EMAIL
    // account from ADMIN_PASSWORD on every deploy, like JudgeSeeder. Set all
    // of these as Elastic Beanstalk environment properties, never in the
    // code. With none set, nobody is an admin and both pages return 403.
    // Needs REQUIRE_LOGIN=true. Invites also need working MAIL_* settings.
    'admin' => [
        'name' => env('ADMIN_NAME', 'PRISM Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'emails' => env('ADMIN_EMAILS', ''),
    ],

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
    // The folder of PRISM's own log files. An account deletion removes the
    // lines in them that name the account (App\Services\PersonalData).
    // Null means storage/logs. This file is also read without Laravel booted,
    // so it cannot call storage_path() itself.
    'log_folder' => null,

    'policy_dates' => [
        'faq' => ['date' => 'November 3, 2026', 'hash' => 'fb93756a9d1e256245b8f0a41e5391218855f6b2684a646e3aa0f3e34740aa0d'],
        'privacy' => ['date' => 'November 9, 2026', 'hash' => '9971bbd8aca911ee8322ad10a9aab55ab3969afdbd7ee003b08496f27b8e40e7'],
        'terms' => ['date' => 'October 26, 2026', 'hash' => '4c4064afa204cc5d5b0440e6d8c33c62371284721fd364ba3be8fd171033c036'],
        'ai' => ['date' => 'November 3, 2026', 'hash' => '4b82ca8bab07fd15a7503b0cad0264e934d8debadca0101e986bdc739e82cd83'],
    ],

    // Security audit log (App\Support\AuditLog). An api.error_rate_exceeded line
    // goes into it when the /api/ routes answer with this many errors (any 4xx
    // or 5xx) inside the window, per IP address or from all addresses together
    // (App\Http\Middleware\AuditApiErrors). The dashboard polls every 1.2
    // seconds, so a signed-out tab that keeps polling reaches 20 in under half
    // a minute. That is a real signal, not noise: the tab shows nothing useful.
    'audit' => [
        'api_error_window_seconds' => (int) env('AUDIT_API_ERROR_WINDOW', 60),
        'api_errors_per_ip' => (int) env('AUDIT_API_ERRORS_PER_IP', 20),
        'api_errors_total' => (int) env('AUDIT_API_ERRORS_TOTAL', 100),
    ],

    'bands' => [
        'elevated_from' => 40,
        'high_from' => 70,
    ],

];
