#!/bin/bash
# Gives the override log a place to save and creates its table.
#
# SQLite file lives in /var/app/prism-data, outside the app folder, so a normal
# deploy does not erase it. A rebuilt or replaced instance does erase it.
# Elastic Beanstalk hooks run as root and see the environment properties
# (DB_CONNECTION, DB_DATABASE, APP_KEY).
#
# This script never fails the deploy. If a step fails, the dashboard still
# works and only the override log is unavailable.

DATA_DIR=/var/app/prism-data

mkdir -p "$DATA_DIR" || true
touch "$DATA_DIR/prism.sqlite" || true

cd /var/app/current || exit 0
php artisan migrate --force || echo "PRISM: migrate failed. The override log is unavailable."

# The web server user must be able to write the file and its folder.
chown -R webapp:webapp "$DATA_DIR" || true
chmod 750 "$DATA_DIR" || true
chmod 640 "$DATA_DIR/prism.sqlite" || true

exit 0
