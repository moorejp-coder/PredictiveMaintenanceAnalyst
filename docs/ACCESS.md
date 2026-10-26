# Sign in, Judge Demo and testing

The README gives the short version. This page holds the detail.

## Sign in and judge invites

The sign-in is on at the live site (`REQUIRE_LOGIN=true`), which runs on HTTPS. The contest asks for a working login. The invite flow is built, but PRISM sends no invite on its own. With the switch off, which is the default for a local copy, the dashboard is public, `/login` goes back to `/`, and the login, invite and set-password routes return 404.

Judge Demo (the way judges get in): open `/demo`, or press "Open the Judge Demo" on the sign-in page. No account, password or email is involved. The page sets a flag in the visitor's session that opens the dashboard and the AI explanation, and blocks the two routes that write, the override log and AI ratings (HTTP 403, nothing saved). The demo header also links to `/overrides`, which for a demo session shows a sample override log: three made-up entries and three made-up AI ratings that live in code (`app/Support/DemoSamples.php`), with no password and no database read. The sample scores come from the same scorer as the dashboard. A demo session never sees a real entry. Without the demo, `/overrides` still needs its password. The link works any number of times and has no end date. If a session times out, open `/demo` again. "Exit Demo" in the header ends the demo session. A signed-in account is never treated as a demo visitor. Tests: `JudgeDemoTest`. With the sign-in off, `/demo` goes to `/`.

Judges do not receive a link. They use the Judge Demo. A person who asks for a link gets one from the entrant, who sends it by hand. The invite flow works like this.

How an invited user gets in:

1. The entrant opens `/admin/invites`, signs in with the `INVITE_ADMIN_PASSWORD` value and types the requester's email address.
2. PRISM creates the account with a random password nobody knows and emails the requester a link. The email holds a link, never a password.
3. The link opens `/set-password/{token}`. The requester chooses a password of at least 12 characters. The link works once and expires after 7 days.
4. The requester signs in at `/login` with that email address and password.

To send a fresh link (a lost email, an expired link), type the same address again. A second invite inside one minute is held back.

To turn it on (this is how the live site was set up). Use HTTPS only, since a password sent over HTTP is readable on the network:

1. Set up an email sender (see Email below). Any SMTP provider works with no added package.
2. In the Elastic Beanstalk console, set the environment properties `REQUIRE_LOGIN=true`, `INVITE_ADMIN_PASSWORD` and the `MAIL_*` values. Use a password for the invite page that no other PRISM password uses.
3. Deploy, then load `/admin/invites`, invite yourself first and walk through steps 2 to 4.
4. Send an invite when someone asks for a link.

Email: set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` as environment properties. With the default `MAIL_MAILER=log`, nothing is sent and the invite email is written to the log. If the mail service refuses the message, the invite page shows an error, keeps the account and lets you try again. Amazon SES is one option. While an SES account is in the sandbox, it sends only to verified addresses (AWS SES FAQ), so each requester's address would need verifying first. Mail from a new sender is sometimes filtered, so test with an address like the requester's.

How it works when on:

- Laravel's built-in session login and password broker, no added package. The invites use their own broker named `invites` in `config/auth.php`, with a 7 day life. Laravel deletes a token after it sets a password, so a link works once.
- `RequireLogin` (`app/Http/Middleware/RequireLogin.php`) guards `/` and the `/api/` routes. The pages `/ai`, `/faq`, `/privacy`, `/terms` and `/login` stay public. A separate password still guards `/overrides`.
- Logins are limited to 5 tries a minute. The invite and set-password forms are limited to 10 a minute. The session is renewed at sign-in.
- An invited account stores a name, an email address and a password hash. Every reading is simulated, so the account holds no real personal data. The override form asks judges to type made-up names.
- Limit: accounts live in the same SQLite file as the override log, so a replaced or rebuilt server erases every invited account. Invite again after a rebuild. Steps are in [RECOVERY.md](RECOVERY.md).
- Optional: `JudgeSeeder` can still make one fixed account from `JUDGE_EMAIL` and `JUDGE_PASSWORD`, as a backup if email fails. It comes back after every deploy. The invite page is the main way.
- The sitemap drops `/` while the sign-in is on.

## Test the live app

Live site: https://prismcua.duckdns.org/ . Every reading is simulated. Judges: open https://prismcua.duckdns.org/demo . It needs no account and is read-only. Anyone who asked the entrant for a link signs in with the account from that invite email (see Sign in and judge invites).

Checks that need no account. Run on Oct 22, 2026 against the live site:
- `/api/readings`, `/api/diagnosis`, `/overrides` and `/admin/invites` return 401 when you are signed out.
- A path that does not exist, such as `/nope-xyz`, returns 404.
- The sign-in page sends `Strict-Transport-Security`, `X-Content-Type-Options: nosniff` and a Content-Security-Policy header.

Checks after you sign in. The entrant ran steps 1 to 6 on the live site on Oct 22, 2026 and all worked. The entrant also ran one invite from start to finish (email, set password, sign in) and signed in again after a deploy to confirm accounts survive a normal deploy. The entrant also signed in with the backup judge account (`JUDGE_EMAIL` and `JUDGE_PASSWORD`) on Oct 22, 2026. The entrant also used the live site on a phone and in a second browser on Oct 22, 2026 and saw nothing broken. The expected results also come from the code and the automated tests in `tests/`.

1. Open the dashboard. A new reading arrives about every 1.2 seconds.
2. Choose Overload under Simulated situation. Wait for the score to reach the High band. The panel shows "High Predicted Failure Risk" and the instruction to stop the machine (`tests/Feature/OverrideLogTest.php` uses Overload at reading 59 as a High reading).
3. Press "Explain this reading". The answer carries the label AI-GENERATED. If the AI is slow, off or wrong in format, the label reads "RULE-BASED, NO AI USED" and a built-in explanation appears (see [AI.md](AI.md)).
4. Rate the answer Helpful or Wrong. A thank-you appears beside the buttons.
5. Press "Keep running anyway". Type a made-up name and a reason of at least 10 characters. The server accepts the entry only for a High reading. For a Healthy reading it returns 422 (`tests/Feature/OverrideLogTest.php`).
6. Open `/overrides`. It asks for its own password, which only the entrant holds. In the Judge Demo, the header link "Override Log" opens the same page with made-up sample rows.

Edge cases the tests cover: an unknown scenario name, an override for a reading that is not High, a score sent by the browser (the server ignores it), a missing AI key, an AI timeout, an unknown AI tool, a wrong invite token, an invite link used twice, an invite link older than 7 days, a short or mismatched password, an invalid invite email, a wrong sign-in password (one generic message), the rate limit on sign-in, diagnosis, work order, override, rating, set-password and invite routes (429 after the allowed number), the styled 419 and 429 pages, and the health check. Test files: `OverrideLogTest`, `AiStepsTest`, `InvestigatorTest`, `InviteTest`, `LoginTest`, `ErrorPagesTest`, `RateLimitTest`, `HealthTest`.

`/health` is public. It returns `{"status":"ok"}` when the app reaches its database and the `users`, `override_logs` and `ai_feedback` tables exist. Otherwise it returns 503 with `{"status":"unavailable"}` and writes the reason to the log. It names no table in the reply. It returned ok on the live site on Oct 22, 2026. Elastic Beanstalk does not use it yet. Since Oct 22, 2026 an external UptimeRobot keyword monitor checks `/health` every 5 minutes for the text `"status":"ok"` and emails the entrant when the text is missing. The entrant received the test alert email on Oct 22, 2026.
