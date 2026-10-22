# PRISM recovery runbook

Use this when the live site loses its data or its server. It covers option A of the storage plan: no new AWS service, only a documented way back.

## What survives and what does not

Source: `.ebextensions/01-laravel.config` and `.platform/hooks/postdeploy/01_override_log_db.sh`.

- The SQLite file lives at `/var/app/prism-data/prism.sqlite`, outside the app folder.
- A normal deploy (uploading a new zip) keeps the file. The hook only creates it when missing, then runs `php artisan migrate`.
- A rebuilt or replaced server starts with no file. These are lost: judge accounts, the override log, AI answer ratings.
- Sessions use files, so everyone is signed out after a replacement.
- The Let's Encrypt certificate lives on the server. A new server requests a new one (`02_https.sh`).
- The fallback judge account (`JudgeSeeder`, from `JUDGE_EMAIL` and `JUDGE_PASSWORD`) is created again on every deploy.

## Before judging

1. Do not rebuild or replace the environment. Upload zips only.
2. Keep the list of judge email addresses in a private note outside the repo.
3. Keep a private copy of the environment property names and values. Never put the values in the repo or in a chat.
4. Prove a normal deploy keeps data: invite your own address, upload a new zip, sign in with that account.
5. After any AWS change, run the checks in "Verify" below.

## If the server was replaced

1. Open the Elastic Beanstalk console. Check the environment is green and note its public IP address.
2. Check these environment properties are still set: `APP_KEY`, `APP_URL`, `APP_DOMAIN`, `CERTBOT_EMAIL`, `REQUIRE_LOGIN`, `INVITE_ADMIN_PASSWORD`, `OVERRIDE_LOG_PASSWORD`, `ANTHROPIC_API_KEY`, the `MAIL_*` values, `JUDGE_EMAIL`, `JUDGE_PASSWORD`. Add any that are missing.
3. Update the DuckDNS record to the new public IP. A new server has a new IP unless an Elastic IP is attached. Without the right IP, the certificate request in `02_https.sh` fails.
4. Upload the latest deploy zip. The hooks create the database, run the migrations, create the fallback judge account and request the certificate.
5. Read the deploy logs for lines starting with `PRISM:` or `PRISM HTTPS:`. They name any step that failed.

## Verify

1. `https://prismcua.duckdns.org/` loads over HTTPS with the dark theme, logo and fonts.
2. Sign in with the fallback judge account.
3. On the dashboard, press "Explain this reading" in an Elevated or High scenario. The label reads AI-GENERATED, or RULE-BASED, NO AI USED if the key is missing.
4. Open `/admin/invites`, sign in with `INVITE_ADMIN_PASSWORD`, and invite your own address. Open the email, set a password, sign in.

## Bring the judges back

1. Open `/admin/invites` and type each judge's address again. Each judge gets a fresh link. Links work once and expire after 7 days.
2. A second invite to the same address inside one minute is held back. Wait and send again.
3. Tell each judge to sign in with the new link. Old passwords no longer work.

## Known limits

- The override log history and AI ratings from before the replacement cannot be recovered.
- This runbook does not back up the database. Backing it up to S3 or moving to RDS were the other options considered.
- Not confirmed: which AWS events replace the instance, and whether an Elastic IP is attached.
