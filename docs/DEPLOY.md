# Deploying PRISM

PRISM runs on one AWS Elastic Beanstalk environment (PHP platform, single instance, AWS Free Tier) with a SQLite file and no separate database. nginx on the instance serves HTTPS with a Let's Encrypt certificate (see HTTPS below). A deploy is a zip file uploaded in the Elastic Beanstalk console. Elastic Beanstalk runs `composer install` itself.

## 1. Merge through a pull request
Branch protection blocks direct pushes to `main`. Every change goes through a pull request with a short what-and-why description. The PHP 8.4, PHP 8.5 and Code style (Pint) checks must pass. Then pull `main` to your computer. The zip is built from committed files, so it matches GitHub.

## 2. Build the zip
```
python3 scripts/build_deploy_zip.py deploy-YYYY-MM-DD<letter>.zip <zip that is live now>
```
The script:
- stores files as 644 and scripts (`artisan`, the `.sh` hooks) as 755, and fails if a file is not world-readable. Owner-only files leave the web server unable to read the CSS, fonts and brand files.
- fails if `public/css/prism.css`, `public/brand/`, `public/fonts/`, `public/build/`, the `.ebextensions` and `.platform` files, `composer.json` or `composer.lock` are missing.
- fails if `vendor`, `node_modules`, `.env` or `.git-credentials` are inside.
- lists the files added and removed against the comparison zip. Explain any removed file before you upload.

The zip leaves out `data/` and `docs/`. The live site does not need them. Deploy zips are listed in `.gitignore` and are never committed.

## 3. Upload and check the live site
1. Upload the zip in the Elastic Beanstalk console and wait for the environment to turn green.
2. Hard-refresh the site. Check the dark theme, the logo and the fonts. If the page is unstyled, open the browser's Network tab, click `prism.css` and read the status code.
3. Open `/demo`. The dashboard loads with a "Read-only Demo" label. "Exit Demo" returns to the sign-in page.
4. Press "Explain this reading", then Helpful. The message says the demo is read-only and ratings are not saved.
5. Signed out, open `/`. It redirects to `/login`, which shows the "Open the Judge Demo" button.
6. Open `/health`. It returns `{"status":"ok"}`.

## Environment properties
Set these in the Elastic Beanstalk console, never in the repository:
- `ANTHROPIC_API_KEY`. Without it PRISM makes no AI call. `ANTHROPIC_MODEL`, `ANTHROPIC_TIMEOUT` and `PRISM_INVESTIGATOR` are optional.
- `APP_URL`, `APP_DOMAIN` for the HTTPS setup. `CERTBOT_EMAIL` is optional and best left unset: Let's Encrypt ended its expiry emails on June 4, 2025, and the certificate renews on its own.
- `REQUIRE_LOGIN=true` turns the sign-in on. The Judge Demo needs no other property. With the property missing, production requires sign-in anyway.
- `PRISM_AI_DAILY_CAP` is optional. It sets the most AI answers the whole site serves per day (default 1000).
- `PRISM_AI_DAILY_BUDGET_USD` (default 1.00) and `PRISM_AI_USER_HOURLY_BUDGET_USD` (default 0.05) are optional. They cap AI spending in dollars for the whole site per day and per user per hour. `PRISM_AI_INPUT_USD_PER_MTOK` (default 0.10) and `PRISM_AI_OUTPUT_USD_PER_MTOK` (default 0.50) are the model prices used to count it. Change them if `ANTHROPIC_MODEL` changes.
- `PRISM_EMAIL_DAILY_CAP` is optional. It sets the most emails the site sends per day, invites and account lock emails together (default 100).
- `ADMIN_EMAIL` and `ADMIN_PASSWORD` (12 or more characters) create the admin account on each deploy. Only an admin opens `/overrides` and `/admin/invites`. Optional: `ADMIN_NAME`, and `ADMIN_EMAILS` (comma-separated addresses that also count as admins).
- Only for invited accounts: the `MAIL_*` values (see [ACCESS.md](ACCESS.md)).
- Optional backup account: `JUDGE_EMAIL` and `JUDGE_PASSWORD`.

## If the server is replaced
The SQLite file lives on the server, so a rebuilt server loses the override log and every invited account. Follow [RECOVERY.md](RECOVERY.md).

## Encryption at rest

What the app encrypts itself, with `APP_KEY` (Laravel's encrypter, AES-256-CBC with an HMAC-SHA-256 check):

- Override reasons, current and original (`override_logs.reason`, `override_logs.original_reason`). They are free text, so a person may type personal details.
- The IP addresses of wrong passwords (`users.failed_login_ips`).
- Session files (`SESSION_ENCRYPT=true`) and the session cookie.

What is hashed, never stored as written: passwords (bcrypt, cost 12), invite tokens (bcrypt), unlock tokens (SHA-256 of a 64-character random token). Emails in log lines are keyed hashes (`app/Support/LogId.php`).

What stays readable in the database: account emails and names, timestamps, scores and simulated readings. Sign-in, invites and the password broker look accounts up by email, so the email column is not encrypted. PRISM stores no SSN, payment card or health data.

The key: `APP_KEY` is an Elastic Beanstalk environment property. It is not in the repo, not in the deploy zip and not in the SQLite file. It is on the same server at run time, so app encryption protects a copied database file or backup, not a server an attacker controls. Changing `APP_KEY` makes the encrypted values unreadable: put the old key in `APP_PREVIOUS_KEYS` (comma-separated) when you add a new one.

The disk: Elastic Beanstalk keeps the SQLite file, sessions and logs on the instance's EBS root volume. To encrypt that volume (AES-256, with the AWS managed key `aws/ebs`):

1. EC2 console, Region us-east-2. Settings, then Data protection and security. Under EBS encryption choose Manage, select Enable, keep `aws/ebs`, then Update EBS encryption.
2. This covers new volumes only. The current volume stays unencrypted until the instance is replaced. In the Elastic Beanstalk console choose Actions, then Rebuild environment. A rebuild erases the SQLite file, so follow [RECOVERY.md](RECOVERY.md) afterwards.
3. Check: EC2 console, Volumes. The new volume shows Encrypted: Yes.


The live site uses a free Let's Encrypt certificate on a free DuckDNS name. nginx on the Elastic Beanstalk instance ends the encrypted connection. The setup is configured to send every http request, on the DuckDNS name, the Elastic Beanstalk URL or the raw IP, to `https://` plus the DuckDNS name, and to renew the certificate on its own. The files are `.ebextensions/02-https.config`, `.platform/nginx/conf.d/elasticbeanstalk/acme.conf` and `.platform/hooks/postdeploy/02_https.sh`. A change in the Elastic Beanstalk console, such as turning on log streaming, is a configuration update. Elastic Beanstalk then replaces the nginx config and runs only `.platform/confighooks/`, so `.platform/confighooks/postdeploy/02_https.sh` runs the same script again. Without it the site refuses connections on HTTPS after such a change while the environment shows green. Redeploying the current zip also fixes that. The name is an environment property (`APP_DOMAIN`, `APP_URL`), not code. A contact email (`CERTBOT_EMAIL`) is optional.

## Hosting cost

The server runs on the AWS Free Tier. The entrant confirmed this in the AWS account on Oct 22, 2026. The Claude API is a separate paid service and is not part of the free tier.

## Audit log in CloudWatch

The app writes each audit line to standard error and to `/var/log/prism/audit.log` (`.ebextensions/03-audit-log.config`). `.platform/hooks/postdeploy/03_audit_log.sh` creates the folder and asks the CloudWatch agent to ship the file. `.platform/confighooks/postdeploy/03_audit_log.sh` repeats that after a configuration change in the console.

Check after each deploy:

1. Fail one sign-in on the live site.
2. Open CloudWatch Logs and the group `/aws/elasticbeanstalk/<environment>/var/log/prism/audit.log`.
3. Search for `auth.login.failed`.

If the group is missing, read `eb-hooks.log` for lines that start with `PRISM audit log:`. The instance role needs `logs:CreateLogGroup`, `logs:CreateLogStream` and `logs:PutLogEvents`. I have not confirmed this setup on the live platform.
