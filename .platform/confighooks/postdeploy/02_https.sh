#!/bin/bash
# Puts HTTPS back after a configuration change.
#
# Changing a setting in the Elastic Beanstalk console (for example turning on
# log streaming) is a configuration update, not a deploy. Elastic Beanstalk
# then copies its own nginx config over the server's and runs only the
# scripts in .platform/confighooks/, not the ones in .platform/hooks/. Without
# this file the HTTPS settings written by 02_https.sh are gone after such a
# change, nginx stops listening on port 443 and the site refuses connections
# while the environment still shows green.
#
# This file runs the same script as a deploy does. That script is safe to run
# again: it reuses the stored certificate, rewrites the nginx HTTPS file and
# reloads nginx. It never fails the update.

SCRIPT=/var/app/current/.platform/hooks/postdeploy/02_https.sh

if [ ! -f "$SCRIPT" ]; then
  echo "PRISM HTTPS: $SCRIPT is missing. HTTPS was not restored."
  exit 0
fi

# Deploy hooks see the environment properties as variables. If a configuration
# hook does not, read them from Elastic Beanstalk.
GET_CONFIG=/opt/elasticbeanstalk/bin/get-config
for NAME in APP_DOMAIN CERTBOT_EMAIL; do
  if [ -z "${!NAME:-}" ] && [ -x "$GET_CONFIG" ]; then
    VALUE="$("$GET_CONFIG" environment -k "$NAME" 2>/dev/null || true)"
    if [ -n "$VALUE" ]; then
      export "$NAME=$VALUE"
    fi
  fi
done

bash "$SCRIPT"
exit 0
