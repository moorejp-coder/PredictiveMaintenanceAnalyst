# Security

PRISM is a student contest project. Every sensor reading is simulated. PRISM connects to no real machine and holds no real personal data.

## Report a problem

Email moorejp@cua.edu with the subject "PRISM security". Do not post details in a public issue. Name the page or route, the steps to repeat the problem and what you saw. This is a student project with no bug bounty, and I do not promise a reply time.

## How PRISM handles security

- Secrets: keys and passwords live in `.env` on a local copy (ignored by git) and in Elastic Beanstalk environment properties on the live site. `.env.example` holds blank placeholders only. A search of the 200 most recent commits on Oct 22, 2026 found no AWS, Anthropic or GitHub token patterns. That search is not a full audit.
- Sign-in: the live site runs with `REQUIRE_LOGIN=true`. Judges open the read-only Judge Demo and need no account. PRISM sends no invite on its own. The entrant sends an invite link only when a person asks for one. The link lets the person choose a password of at least 12 characters, works once and expires after 7 days. Laravel stores passwords as hashes.
- Separate passwords guard `/admin/invites` and `/overrides`. Both are checked with `hash_equals`, and both pages return 404 when no password is set.
- Rate limits: login 5 a minute; invite and set-password forms 10 a minute; AI diagnosis 10 a minute; AI work order 30 a minute; override log 10 a minute; AI ratings 20 a minute. `tests/Feature/RateLimitTest.php` and `tests/Feature/LoginTest.php` check them.
- Input checks: every field on every form and API route is validated on the server for type, length, range and allowed values. A JSON route answers 400 for input outside its bounds (`tests/Feature/ApiValidationTest.php`). Free text loses control characters before it is stored, and every page escapes it on output. Queries use bound values only.
- Abuse screening: input that looks like an attack (script tags, SQL syntax, text over 1,000 characters) or a filled hidden `homepage` field is rejected and logged with the IP address, field, reason and the first 100 characters. Passwords and tokens are never logged. This screening is a second layer; bound queries and output escaping are the defense. A bot that posts straight to a route without the hidden field passes the honeypot. `tests/Feature/AbuseGuardTest.php` checks it.
- Browser headers: a Content-Security-Policy with a per-request nonce, HSTS over HTTPS, and the other headers listed below under "Browser security headers". `tests/Feature/SecurityHeadersTest.php` checks them.
- AI: the Anthropic API key stays on the server. The AI tools only read data. PRISM has no tool that acts on a machine.
- Data kept: judge accounts (name, email, password hash), the override log, and AI answer ratings (no name, text or IP address). All of it sits in a SQLite file on the server.
- Dependencies: GitHub Dependabot alerts are on. On Oct 22, 2026 it showed 0 open and 0 closed alerts (see [docs/QUALITY.md](docs/QUALITY.md)).

## Browser security headers

`app/Http/Middleware/SecurityHeaders.php` adds browser security headers to every page Laravel serves. `tests/Feature/SecurityHeadersTest.php` checks them.

- Content-Security-Policy: all JavaScript lives in files under `public/js`, and `script-src` allows only PRISM's own origin. The views hold no inline `<script>`, and a test fails if one appears. Inline `<style>` blocks run only with a random nonce the server makes for each request. The policy allows no `unsafe-inline` or `unsafe-eval` for scripts, loads everything from PRISM's own origin, blocks plugins and blocks framing (`frame-ancestors 'none'`). The site loads no outside scripts, styles or fonts, so none are listed.
- Strict-Transport-Security (one year) and `upgrade-insecure-requests`, sent only when the request is HTTPS or `APP_URL` starts with `https://`.
- X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and Cross-Origin-Opener-Policy. A controller that sets its own value keeps it (the set-password page sends `no-referrer`).
- The session cookie is marked Secure when `APP_URL` starts with `https://`. `SESSION_SECURE_COOKIE` overrides this.

## Known limits

- No independent security scan or penetration test has been run.
- Inline `style="..."` attributes are still allowed by the policy.
- Files nginx serves directly (CSS, fonts, images) carry no PRISM headers.
- The SQLite file has no backup. A replaced server erases judge accounts and the override log (see `docs/RECOVERY.md`).

## If you run your own copy

Never commit `.env`. Use a different password for each of `OVERRIDE_LOG_PASSWORD`, `INVITE_ADMIN_PASSWORD` and any judge account. Turn on `REQUIRE_LOGIN` only behind HTTPS.
