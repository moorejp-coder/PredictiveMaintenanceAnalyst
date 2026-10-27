# Security

PRISM is a student contest project. Every sensor reading is simulated. PRISM connects to no real machine and holds no real personal data.

## Report a problem

Email moorejp@cua.edu with the subject "PRISM security". Do not post details in a public issue. Name the page or route, the steps to repeat the problem and what you saw. This is a student project with no bug bounty, and I do not promise a reply time.

## How PRISM handles security

- Secrets: keys and passwords live in `.env` on a local copy (ignored by git) and in Elastic Beanstalk environment properties on the live site. `.env.example` holds blank placeholders only. A search of the 200 most recent commits on Oct 22, 2026 found no AWS, Anthropic or GitHub token patterns. That search is not a full audit.
- Sign-in: the live site runs with `REQUIRE_LOGIN=true`. With no `REQUIRE_LOGIN` property set, a production copy requires sign-in too (`config/prism.php`). Judges open the read-only Judge Demo and need no account. PRISM sends no invite on its own. The entrant sends an invite link only when a person asks for one. The link lets the person choose a password of at least 12 characters, works once and expires after 30 minutes. Laravel stores passwords as hashes (bcrypt, 12 rounds). A password change ends the account's other sessions.
- Record access: override entries and AI ratings have a random UUID as their public id, so a URL or API response never shows a row number. A signed-in user lists, views, edits and deletes only the entries that user logged: `GET /api/overrides`, and `GET`, `PUT` and `DELETE /api/overrides/{uuid}`. The `authorize.resource` middleware and `OverrideLogPolicy` check the owner on the server. A guest or a demo session gets 401. Another user, or an entry with no owner (logged before accounts were recorded, or with the sign-in off), gets 403. A refused request is written to the Laravel log with the user id, IP address, route and entry id. These routes have no screen in the dashboard. `tests/Feature/OverrideOwnershipTest.php`, `AuthorizeResourceTest.php` and `PublicIdTest.php` check them.
- Override log history: an edit keeps the operator's original name and reason, and a delete only marks the entry deleted. The password-protected `/overrides` page shows both.
- Separate passwords guard `/admin/invites` and `/overrides`. Both are checked with `hash_equals`, and both pages return 404 when no password is set.
- Rate limits: login 5 a minute per IP address, plus a 15 minute lock after 5 wrong tries per email and address or 15 per email from anywhere; invite form 10 a minute; set-password form 5 a minute; AI diagnosis 10 a minute; AI work order 30 a minute; override log 10 a minute; AI ratings 20 a minute; sensor readings 120 a minute; your own override entries 60 a minute to view and 20 a minute to edit or delete. One site-wide cap also stops the AI routes after 1,000 answers a day (`PRISM_AI_DAILY_CAP`), counting every visitor and the Judge Demo together. `tests/Feature/AccessHardeningTest.php` checks the readings limit, the daily cap and that dashboard polling does not use up the other limits. `tests/Feature/RateLimitTest.php` and `tests/Feature/LoginTest.php` check them.
- Input checks: every field on every form and API route is validated on the server for type, length, range and allowed values. A JSON route answers 400 for input outside its bounds (`tests/Feature/ApiValidationTest.php`). Free text loses control characters before it is stored, and every page escapes it on output. Queries use bound values only.
- Abuse screening: input that looks like an attack (script tags, SQL syntax, text over 1,000 characters) or a filled hidden `homepage` field is rejected and logged with the IP address, field, reason and the first 100 characters. Passwords and tokens are never logged. This screening is a second layer; bound queries and output escaping are the defense. A bot that posts straight to a route without the hidden field passes the honeypot. `tests/Feature/AbuseGuardTest.php` checks it.
- Browser headers: a Content-Security-Policy with a per-request nonce, HSTS over HTTPS, and the other headers listed below under "Browser security headers". `tests/Feature/SecurityHeadersTest.php` checks them.
- AI: the Anthropic API key stays on the server. The AI tools only read data. PRISM has no tool that acts on a machine.
- Data kept: judge accounts (name, email, password hash), the override log (with the id of the account that logged each entry, and the original text of an edited entry), and AI answer ratings (no name, text or IP address). All of it sits in a SQLite file on the server.
- Dependencies: GitHub Dependabot alerts are on. On Oct 22, 2026 it showed 0 open and 0 closed alerts (see [docs/QUALITY.md](docs/QUALITY.md)).

## Browser security headers

`app/Http/Middleware/SecurityHeaders.php` adds browser security headers to every page Laravel serves. `tests/Feature/SecurityHeadersTest.php` checks them.

- Content-Security-Policy: all JavaScript lives in files under `public/js`, and `script-src` allows only PRISM's own origin. The views hold no inline `<script>`, and a test fails if one appears. Inline `<style>` blocks run only with a random nonce the server makes for each request. The policy allows no `unsafe-inline` or `unsafe-eval` for scripts, loads everything from PRISM's own origin, blocks plugins and blocks framing (`frame-ancestors 'none'`). The site loads no outside scripts, styles or fonts, so none are listed.
- Strict-Transport-Security (one year) and `upgrade-insecure-requests`, sent only when the request is HTTPS or `APP_URL` starts with `https://`.
- X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and Cross-Origin-Opener-Policy. A controller that sets its own value keeps it (the set-password page sends `no-referrer`).
- Sessions: PRISM keeps no access or refresh tokens in the browser. Sign-in is one server-side session behind an `HttpOnly`, `SameSite=Lax` cookie, marked Secure everywhere except a local copy (`APP_ENV=local` over http), and always when `APP_URL` starts with `https://` (`SESSION_SECURE_COOKIE` overrides). The framework's `XSRF-TOKEN` cookie follows the same setting. A new session ID is issued at every sign-in. A session also ends when the browser (User-Agent) changes, and an IP address change is logged. The Account page (`/account`) signs the user out of every other browser after a password check. A session ends after 30 idle minutes (`SESSION_LIFETIME`) and 7 days after sign-in at most (`SESSION_MAX_DAYS`). Sign-out deletes the session on the server. The browser stores only theme, cookie-notice and judge-guide flags, never a credential.

## Known limits

- No independent security scan or penetration test has been run.
- `/overrides` and `/admin/invites` need a signed-in admin account (a verified email equal to `ADMIN_EMAIL` or listed in `ADMIN_EMAILS`). Refused attempts are logged. Every admin has full access to both pages.
- The Judge Demo needs no account and reaches the AI routes. Only the per-IP rate limits and the site-wide daily cap limit its use.
- The operator name on an override is typed text. The entry also records the signed-in account, but the name is not checked against it.
- Inline `style="..."` attributes are still allowed by the policy.
- Files nginx serves directly (CSS, fonts, images) carry no PRISM headers.
- The SQLite file has no backup. A replaced server erases judge accounts and the override log (see `docs/RECOVERY.md`).

## If you run your own copy

Never commit `.env`. Use a different password for `ADMIN_PASSWORD` and any judge account. Turn on `REQUIRE_LOGIN` only behind HTTPS.
