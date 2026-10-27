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
- `APP_URL`, `APP_DOMAIN`, `CERTBOT_EMAIL` for the HTTPS setup.
- `REQUIRE_LOGIN=true` turns the sign-in on. The Judge Demo needs no other property. With the property missing, production requires sign-in anyway.
- `PRISM_AI_DAILY_CAP` is optional. It sets the most AI answers the whole site serves per day (default 1000).
- `OVERRIDE_LOG_PASSWORD` protects the `/overrides` review page.
- Only for invited accounts: `INVITE_ADMIN_PASSWORD` and the `MAIL_*` values (see [ACCESS.md](ACCESS.md)).
- Optional backup account: `JUDGE_EMAIL` and `JUDGE_PASSWORD`.

## If the server is replaced
The SQLite file lives on the server, so a rebuilt server loses the override log and every invited account. Follow [RECOVERY.md](RECOVERY.md).

## HTTPS

The live site uses a free Let's Encrypt certificate on a free DuckDNS name. nginx on the Elastic Beanstalk instance ends the encrypted connection. The setup is configured to send http requests for this name to https and to renew the certificate on its own. The files are `.ebextensions/02-https.config`, `.platform/nginx/conf.d/elasticbeanstalk/acme.conf` and `.platform/hooks/postdeploy/02_https.sh`. The name and the contact email are environment properties (`APP_DOMAIN`, `CERTBOT_EMAIL`, `APP_URL`), not code.

## Hosting cost

The server runs on the AWS Free Tier. The entrant confirmed this in the AWS account on Oct 22, 2026. The Claude API is a separate paid service and is not part of the free tier.
