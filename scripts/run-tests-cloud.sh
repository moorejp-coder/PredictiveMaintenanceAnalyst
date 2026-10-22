#!/usr/bin/env bash
# Run the PRISM PHP tests in a Linux cloud session.
# Installs PHP 8.4 from the Ondrej PPA when the installed PHP is older than 8.4.1
# (composer.json requires ^8.4), then runs php artisan test.
# Usage: bash scripts/run-tests-cloud.sh [extra php artisan test args]
set -euo pipefail
cd "$(dirname "$0")/.."

NEEDED=80401
CURRENT=$(php -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)

if [ "$CURRENT" -lt "$NEEDED" ]; then
  SUDO=""
  [ "$(id -u)" -ne 0 ] && SUDO="sudo"
  export DEBIAN_FRONTEND=noninteractive
  CODENAME=$(. /etc/os-release && echo "$VERSION_CODENAME")
  $SUDO apt-get install -y -qq gnupg curl ca-certificates >/dev/null
  curl -sS "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x71DAEAAB4AD4CAB6" \
    | $SUDO gpg --batch --yes --dearmor -o /usr/share/keyrings/ondrej-php.gpg
  echo "deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${CODENAME} main" \
    | $SUDO tee /etc/apt/sources.list.d/ondrej-php.list >/dev/null
  $SUDO apt-get update -qq || true
  $SUDO apt-get install -y -qq php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-sqlite3
  $SUDO update-alternatives --set php /usr/bin/php8.4 || true
fi

php -v | head -1

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=.\+' .env || php artisan key:generate
php artisan config:clear >/dev/null 2>&1 || true
php artisan test "$@"
