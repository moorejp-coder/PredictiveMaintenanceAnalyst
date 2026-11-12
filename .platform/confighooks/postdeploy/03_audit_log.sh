#!/bin/bash
# Puts the audit log shipping back after a configuration change.
#
# Turning log streaming on or off in the Elastic Beanstalk console is a
# configuration update. It rewrites the CloudWatch agent configuration and runs
# only .platform/confighooks/, so the agent can lose the audit file. This file
# runs the deploy script again. That script is safe to repeat and never fails
# the update.

SCRIPT=/var/app/current/.platform/hooks/postdeploy/03_audit_log.sh

if [ ! -f "$SCRIPT" ]; then
  echo "PRISM audit log: $SCRIPT is missing. Shipping was not restored."
  exit 0
fi

bash "$SCRIPT"
exit 0
