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

mkdir -p "$DATA_DIR" || true
touch "$DATA_DIR/prism.sqlite" || true

cd /var/app/current || exit 0
php artisan migrate --force || echo "PRISM: migrate failed. The override log is unavailable."

# Create or update the judge account from JUDGE_EMAIL and JUDGE_PASSWORD (set
# in the Elastic Beanstalk console). With either missing, no account is made
# and nobody can sign in. A rebuilt server loses the SQLite file, so this
# runs on every deploy.
php artisan db:seed --class=JudgeSeeder --force || echo "PRISM: judge account was not created. Nobody can sign in."

# The web server user must be able to write the file and its folder.
chown -R webapp:webapp "$DATA_DIR" || true
chmod 750 "$DATA_DIR" || true
chmod 640 "$DATA_DIR/prism.sqlite" || true

exit 0
