#!/bin/bash
# Turns on HTTPS with a free Lets Encrypt certificate for a DuckDNS name.
#
# Needs two Elastic Beanstalk environment properties:
#   APP_DOMAIN     the DuckDNS name, for example prism-cua.duckdns.org
#   CERTBOT_EMAIL  an address Lets Encrypt uses for expiry notices
# Without both, this script does nothing and the site stays on http.
#
# Steps: install certbot once, request the certificate once, add the HTTPS
# settings to nginx, and install a twice-daily renewal check.
# This script never fails the deploy. Each failure prints a PRISM HTTPS line.
# Certificates live on the server. A rebuilt server requests a new one.

DOMAIN="${APP_DOMAIN:-}"
EMAIL="${CERTBOT_EMAIL:-}"
WEBROOT=/var/app/current/public
CERTBOT=/opt/certbot/bin/certbot
SSL_CONF=/etc/nginx/conf.d/elasticbeanstalk/prism-ssl.conf

if [ -z "$DOMAIN" ] || [ -z "$EMAIL" ]; then
  echo "PRISM HTTPS: APP_DOMAIN or CERTBOT_EMAIL is not set. Skipping HTTPS."
  exit 0
fi

# 1. Install certbot once, in its own folder.
if [ ! -x "$CERTBOT" ]; then
  if ! python3 -m venv /opt/certbot; then
    dnf install -y python3-pip >/dev/null 2>&1 || true
    python3 -m venv /opt/certbot || { echo "PRISM HTTPS: could not create the certbot environment."; exit 0; }
  fi
  /opt/certbot/bin/pip install --quiet --upgrade pip certbot \
    || { echo "PRISM HTTPS: could not install certbot."; exit 0; }
fi

# 2. Request the certificate once.
if [ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
  "$CERTBOT" certonly --webroot -w "$WEBROOT" -d "$DOMAIN" \
    --email "$EMAIL" --agree-tos --no-eff-email --non-interactive \
    || { echo "PRISM HTTPS: certificate request failed. Check that $DOMAIN points at this server's public IP and that port 80 is open."; exit 0; }
fi

# 3. Add HTTPS to nginx. The settings join the existing server block, so the
# app, PHP and static file rules are the same on port 80 and port 443.
cat > "$SSL_CONF" <<'CONF'
listen 443 ssl;
ssl_certificate /etc/letsencrypt/live/__DOMAIN__/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/__DOMAIN__/privkey.pem;
ssl_protocols TLSv1.2 TLSv1.3;

# Send http requests for this name to https. Certificate checks stay on http.
set $prism_r "${scheme}://${host}";
if ($request_uri ~* "^/\.well-known/acme-challenge/") { set $prism_r "skip"; }
if ($prism_r = "http://__DOMAIN__") { return 301 https://__DOMAIN__$request_uri; }
CONF
sed -i "s/__DOMAIN__/$DOMAIN/g" "$SSL_CONF"

if nginx -t >/dev/null 2>&1; then
  systemctl reload nginx || echo "PRISM HTTPS: nginx reload failed."
else
  echo "PRISM HTTPS: nginx test failed. Removing the HTTPS settings so the site stays up."
  rm -f "$SSL_CONF"
  nginx -t >/dev/null 2>&1 && systemctl reload nginx
  exit 0
fi

# 4. Renewal check twice a day. Certificates renew when under 30 days remain.
cat > /etc/systemd/system/prism-certbot-renew.service <<'UNIT'
[Unit]
Description=Renew Lets Encrypt certificates for PRISM

[Service]
Type=oneshot
ExecStart=/opt/certbot/bin/certbot renew --quiet --deploy-hook "systemctl reload nginx"
UNIT

cat > /etc/systemd/system/prism-certbot-renew.timer <<'UNIT'
[Unit]
Description=Twice daily PRISM certificate renewal check

[Timer]
OnCalendar=*-*-* 03,15:17:00
RandomizedDelaySec=3600
Persistent=true

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload || true
systemctl enable --now prism-certbot-renew.timer || echo "PRISM HTTPS: renewal timer was not started."

exit 0
