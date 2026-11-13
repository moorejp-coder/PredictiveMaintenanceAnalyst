#!/bin/bash
# Gives the override log a place to save and creates its table.
#
# SQLite file lives in /var/app/prism-data, outside the app folder, so a normal
# deploy does not erase it. A rebuilt or replaced instance does erase it.
# Elastic Beanstalk hooks run as root and see the environment properties
# (DB_CONNECTION, DB_DATABASE, APP_KEY).
#
# This script never fails the deploy. If a step fails, the override log or the
# judge account is unavailable. Without the judge account nobody can sign in.

DATA_DIR=/var/app/prism-data

# The folder and the database file start owner only, so no other account on the
# server reads the override log or the accounts, even for a moment. The umask
# is limited to these two commands: artisan below also writes files under
# storage/ as root, and those must stay readable to the web app.
(umask 077; mkdir -p "$DATA_DIR") || true
(umask 077; touch "$DATA_DIR/prism.sqlite") || true

cd /var/app/current || exit 0
php artisan migrate --force || echo "PRISM: migrate failed. The override log is unavailable."

# Optional backup: create or update one fixed judge account from JUDGE_EMAIL and
# JUDGE_PASSWORD (set in the Elastic Beanstalk console). Judges normally come
# from the invite page, so a missing value here is fine and no account is made.
# Invited judges live in the same SQLite file, so a rebuilt server erases them.
# This backup account is the one that comes back on its own.
php artisan db:seed --class=JudgeSeeder --force || echo "PRISM: the backup judge account was not created."

# The entrant's admin account for /admin/invites and /overrides, from
# ADMIN_EMAIL and ADMIN_PASSWORD. Without it nobody is an admin and both pages
# answer 403.
php artisan db:seed --class=AdminSeeder --force || echo "PRISM: the admin account was not created."

# Removes invite links older than their 30-minute life, so an unused link
# does not keep an email address in the token table.
php artisan auth:clear-resets invites || echo "PRISM: expired invite links were not cleared."

# Only the web app user (webapp, which runs PHP) reads or writes the file and
# its folder. Group and other get no access.
chown -R webapp:webapp "$DATA_DIR" || true
chmod 700 "$DATA_DIR" || true
chmod 600 "$DATA_DIR/prism.sqlite" || true

exit 0
