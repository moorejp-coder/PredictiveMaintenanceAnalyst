# Code quality

- All JavaScript is in `public/js` (no inline scripts). Each file is loaded with a version query so a deploy never serves stale code.
- `.github/workflows/lint.yml` runs on every push and pull request: Pint (code style, `vendor/bin/pint --test`), Larastan at level 8 (`phpstan.neon`) and a syntax check of every JavaScript file. `.github/workflows/tests.yml` runs the PHPUnit suite on PHP 8.4 and 8.5.
- Security policy and known limits: [SECURITY.md](../SECURITY.md).
- Run the same checks locally: `vendor/bin/pint --test`, then `vendor/bin/phpstan analyse --memory-limit=1G`. PHPStan stops at PHP's default 128M limit without the flag.
- Limits: Larastan 3.13.1 (PHPStan 2.3.1) is in `composer.lock`. Level 8 passes with no ignored errors and no baseline (checked locally on Oct 22, 2026). Level 9 (the strictest) reports about 70 findings, mostly values read from `config()` that are not cast to a type, and I have not fixed them. The JavaScript has syntax checks but no unit tests. The browser behavior was checked by hand and with Playwright, which is not part of CI.
- Dependency check, Oct 22, 2026: GitHub Dependabot alerts are on. The dependency graph listed 257 dependencies, 109 of them from `composer.lock`. The list also includes packages from `package-lock.json` and `ml/requirements.txt`. Dependabot showed 0 open and 0 closed vulnerability alerts and 0 open and 0 closed malware alerts. This covers only the advisories GitHub knows about on that date. It is not a full security audit.
