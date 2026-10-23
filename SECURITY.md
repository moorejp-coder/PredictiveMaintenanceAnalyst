# Security

PRISM is a student contest project. Every sensor reading is simulated. PRISM connects to no real machine and holds no real personal data.

## Report a problem

Email moorejp@cua.edu with the subject "PRISM security". Do not post details in a public issue. Name the page or route, the steps to repeat the problem and what you saw. This is a student project with no bug bounty, and I do not promise a reply time.

## How PRISM handles security

- Secrets: keys and passwords live in `.env` on a local copy (ignored by git) and in Elastic Beanstalk environment properties on the live site. `.env.example` holds blank placeholders only. A search of the 200 most recent commits on Oct 22, 2026 found no AWS, Anthropic or GitHub token patterns. That search is not a full audit.
- Sign-in: the live site runs with `REQUIRE_LOGIN=true`. Judges get an emailed link to choose a password of at least 12 characters. The link works once and expires after 7 days. Laravel stores passwords as hashes.
- Separate passwords guard `/admin/invites` and `/overrides`. Both are checked with `hash_equals`, and both pages return 404 when no password is set.
- Rate limits: login 5 a minute; invite and set-password forms 10 a minute; AI diagnosis 10 a minute; AI work order 30 a minute; override log 10 a minute; AI ratings 20 a minute. `tests/Feature/RateLimitTest.php` and `tests/Feature/LoginTest.php` check them.
- Browser headers: a Content-Security-Policy with a per-request nonce, HSTS over HTTPS, and the other headers listed in the README under "Security headers". `tests/Feature/SecurityHeadersTest.php` checks them.
- AI: the Anthropic API key stays on the server. The AI tools only read data. PRISM has no tool that acts on a machine.
- Data kept: judge accounts (name, email, password hash), the override log, and AI answer ratings (no name, text or IP address). All of it sits in a SQLite file on the server.
- Dependencies: GitHub Dependabot alerts are on. On Oct 22, 2026 it showed 0 open and 0 closed alerts (see README, "Code quality").

## Known limits

- No independent security scan or penetration test has been run.
- Inline `style="..."` attributes are still allowed by the policy.
- Files nginx serves directly (CSS, fonts, images) carry no PRISM headers.
- The SQLite file has no backup. A replaced server erases judge accounts and the override log (see `docs/RECOVERY.md`).

## If you run your own copy

Never commit `.env`. Use a different password for each of `OVERRIDE_LOG_PASSWORD`, `INVITE_ADMIN_PASSWORD` and any judge account. Turn on `REQUIRE_LOGIN` only behind HTTPS.
