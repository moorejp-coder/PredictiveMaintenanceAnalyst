#!/bin/bash
# Ships the audit log file to CloudWatch Logs.
#
# PHP's standard error does not reach CloudWatch on this platform, so the app
# also writes each audit line to /var/log/prism/audit.log (AUDIT_LOG_PATH, set
# in .ebextensions/03-audit-log.config). This script makes that folder, hands
# it to the web user and tells the CloudWatch agent to read the file. The log
# group is /aws/elasticbeanstalk/<environment>/var/log/prism/audit.log.
#
# It never fails the deploy. If a step fails, the audit lines still go to
# standard error and the file stays on the server only. Whether the agent
# accepts this configuration on the live platform is not confirmed. Check by
# failing one sign-in and searching the log group for auth.login.failed.

LOG_DIR=/var/log/prism
LOG_FILE="$LOG_DIR/audit.log"
AGENT_CTL=/opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl
AGENT_CONFIG=/opt/aws/amazon-cloudwatch-agent/etc/prism-audit.json

mkdir -p "$LOG_DIR" || true
touch "$LOG_FILE" || true
chown -R webapp:webapp "$LOG_DIR" || true
chmod 750 "$LOG_DIR" || true
chmod 640 "$LOG_FILE" || true

# Environment name, for the log group. Hooks may not see it as a variable.
ENV_NAME="${PRISM_ENV_NAME:-}"
if [ -z "$ENV_NAME" ] && [ -x /opt/elasticbeanstalk/bin/get-config ]; then
  ENV_NAME="$(/opt/elasticbeanstalk/bin/get-config container -k environment_name 2>/dev/null || true)"
fi
if [ -z "$ENV_NAME" ]; then
  echo "PRISM audit log: environment name not found. The audit file is not shipped."
  exit 0
fi

if [ ! -x "$AGENT_CTL" ]; then
  echo "PRISM audit log: the CloudWatch agent is not installed. The audit file is not shipped."
  exit 0
fi

mkdir -p "$(dirname "$AGENT_CONFIG")" || true
cat > "$AGENT_CONFIG" <<JSON || { echo "PRISM audit log: could not write $AGENT_CONFIG."; exit 0; }
{
  "logs": {
    "logs_collected": {
      "files": {
        "collect_list": [
          {
            "file_path": "$LOG_FILE",
            "log_group_name": "/aws/elasticbeanstalk/$ENV_NAME/var/log/prism/audit.log",
            "log_stream_name": "{instance_id}",
            "timezone": "UTC"
          }
        ]
      }
    }
  }
}
JSON

# append-config adds this file to the agent's running configuration and keeps
# the configuration Elastic Beanstalk wrote for its own logs.
"$AGENT_CTL" -a append-config -m ec2 -s -c "file:$AGENT_CONFIG" \
  || echo "PRISM audit log: the CloudWatch agent refused the configuration. The audit file is not shipped."

# Also list the file for the console's "Request logs" bundle.
BUNDLE_DIR=/opt/elasticbeanstalk/config/private/logtasks/bundle
if [ -d "$BUNDLE_DIR" ]; then
  echo "$LOG_FILE" > "$BUNDLE_DIR/prism-audit.conf" || true
fi

exit 0
