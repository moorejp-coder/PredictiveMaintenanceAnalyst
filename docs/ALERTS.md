# Security alerts

PRISM watches its own audit log for eight patterns. When one trips, PRISM writes an `alert.*` line into the audit log. That line is the whole alert. PRISM sends no email or Slack message (decision of Nov 10, 2026). Code: `app/Support/Alerts.php`, `app/Support/LoginOrigins.php`. Tests: `tests/Feature/SecurityAlertsTest.php`. Every number below is a setting in `config/prism.php` with an `ALERT_*` environment variable.

## The alerts

| # | Event | Trips when | Defaults |
|---|-------|------------|----------|
| 1 | `alert.failed_logins` | More than 10 failed sign-ins for one account (or one email, even if unknown) | 10 in 300 seconds. Counts every refusal reason, a locked account too. |
| 2 | `alert.ip_many_accounts` | One IP address names more than 50 different accounts | 50 in 3600 seconds. Counts the sign-in, set-password and unlock requests. A request the rate limit refuses still counts. |
| 3a | `alert.bulk_read_large` | One request reads more than 100 records | 100. Covers the override list, the admin override log and the account list. |
| 3b | `alert.bulk_read_rapid` | More than 10 bulk reads or exports by one account (or address, if signed out) in a minute | 10 in 60 seconds |
| 4a | `alert.login_new_country` | A sign-in from a country the account has not used | Needs `ALERT_COUNTRY_HEADER`. The first sign-in sets the baseline. |
| 4b | `alert.login_new_network` | A sign-in from a network the account has not used (the /24 of an IPv4 address, the /48 of an IPv6 address) | On. Set `ALERT_NEW_NETWORK=false` to turn it off. |
| 5 | `alert.password_links` | 3 or more password links sent to one account | 3 in 3600 seconds. See the note on password reset below. |
| 6 | `alert.auth_failures` | 401 and 403 answers (and a refusal shown as 404) above the usual level | A 5-minute count that reaches 30 and passes 3 times the average 5-minute count of the last 24 hours. One IP address adds at most 20 to a count. |

Rules for all of them:

- One alert per detector and subject per hour (`ALERT_COOLDOWN`). A long attack writes one alert line. The audit lines for the attack keep coming.
- The alert line holds counts, keyed codes (the same one-way code the audit log uses for an email), the request's IP address and the detector's fields. Never an email address, a password or a token.

Password reset: PRISM has no "forgot password" page. A password link comes only from the admin's invite page (`/admin/invites`). Alert 5 watches those links. If you add a self-service reset, call `Alerts::passwordLinkSent($email, $request)` from it.

## No email or Slack

PRISM sends no message for an alert. The delivery code (email and Slack) was removed on Nov 10, 2026, and the settings `ALERT_EMAIL`, `ALERT_SLACK_WEBHOOK` and `ALERT_MAX_PER_HOUR` no longer exist. The Privacy Policy, the FAQ and "How PRISM uses AI" say PRISM sends no alert messages. Read the alerts in the audit log, or build a message path outside the app (last section).

Test after you deploy: run `php artisan tinker --execute='App\Support\Alerts::fire("failed_logins", "test", ["note" => "test message"]);'` on the server. It writes a real `alert.failed_logins` audit line. Wait an hour or clear the cache before you run the same test again, because of the cooldown.

### Alert 4: where the country comes from

PRISM does not look up where an IP address is. For the country alert, a CDN in front of PRISM must add a header. Set `ALERT_COUNTRY_HEADER` to its name:

- Amazon CloudFront: `CloudFront-Viewer-Country`. It holds the two-letter country code, and CloudFront sends it to the origin only when you add it to the distribution's origin request policy. [CloudFront request headers](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/adding-cloudfront-headers.html).
- Cloudflare: `CF-IPCountry`, added by IP geolocation or the "Add visitor location headers" managed transform. [Cloudflare IP geolocation](https://developers.cloudflare.com/network/ip-geolocation/).

Leave it empty if PRISM is not behind such a CDN. A visitor can send any header, so a value PRISM reads without a CDN that overwrites it proves nothing. With it empty, only alert 4b (new network) runs. PRISM ignores a value that is not two letters, and `XX`, `T1` and `ZZ` (Cloudflare's codes for unknown and Tor).

What PRISM keeps for alert 4 is in the privacy policy: per account, up to 20 networks, each as a keyed one-way code with the country and the first and last sign-in time, for 180 days after the last sign-in from it. The account's deletion removes the rows. The data export lists the countries and times.

## Send one alert to its own place (off the server)

The alerts run inside the app, and anyone who controls the app can switch them off. For a path that survives that, and to get a message at all, build it outside the app from the audit log in CloudWatch Logs. This needs the audit log to reach CloudWatch first. See "Audit log protection" in `SECURITY.md`. I have not set this up or tested it against the live account.

1. Create an SNS topic for each destination, then subscribe an email address to it. Choose Create subscription, Protocol Email, enter the address, then open the confirmation email and choose Confirm subscription. The subscription stays pending until you do, and a pending email subscription is deleted after 48 hours. [SNS email subscriptions](https://docs.aws.amazon.com/sns/latest/dg/sns-email-notifications.html).
2. For Slack, configure Amazon Q Developer in chat applications (formerly AWS Chatbot): add the Slack client, add the app to the channel, and map the SNS topic to the channel. [Slack setup](https://docs.aws.amazon.com/chatbot/latest/adminguide/slack-setup.md). CloudWatch alarms reach the channel through the topic. [Monitoring with Amazon Q Developer in chat applications](https://docs.aws.amazon.com/chatbot/latest/adminguide/related-services.html).
3. In CloudWatch, open the audit log group, choose Create metric filter, and enter one pattern per alert from the table. Set the metric value to 1. [Filter pattern syntax](https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/FilterAndPatternSyntax.html).
4. Create an alarm on each metric filter (log group, Metric filters, select the filter, create alarm). Use the Sum statistic over 5 minutes, threshold greater than or equal to 1, and choose your SNS topic as the action. [Alarming on logs](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/Alarm-On-Logs.html).

The audit line is JSON. Its `message` field holds the event name, so these patterns match:

| Alert | Metric filter pattern |
|-------|-----------------------|
| 1 Failed sign-ins | `{ $.message = "alert.failed_logins" }` |
| 2 One IP, many accounts | `{ $.message = "alert.ip_many_accounts" }` |
| 3a Large read | `{ $.message = "alert.bulk_read_large" }` |
| 3b Rapid bulk reads | `{ $.message = "alert.bulk_read_rapid" }` |
| 4a New country | `{ $.message = "alert.login_new_country" }` |
| 4b New network | `{ $.message = "alert.login_new_network" }` |
| 5 Password links | `{ $.message = "alert.password_links" }` |
| 6 401 and 403 above the usual level | `{ $.message = "alert.auth_failures" }` |

An alarm on these filters tells you that PRISM raised the alert. It does not repeat the detail of the message. Open the log group and search for the event to read the fields.

## Limits

- The numbers for alerts 1 (10 in 5 minutes), 2 (50 accounts) and 3a (100 records) are the entrant's. The window for 2, the rate for 3b, the count for 5 and every setting of alert 6 are my choices. They have not been tuned on real traffic. Look at the audit log after a week and change the `ALERT_*` values.
- Alert 6 learns its average from the first deploy on. Until a day has passed the floor (30) decides.
- The counters live in the cache, a file on the live site. Clearing the cache resets every window and every cooldown.
- A detector counts in a fixed window that starts with the first event. A burst that straddles two windows can add up to more than the limit without tripping.
- An alert about a signed-in user's own list (`own_override_entries`) also trips for an account that has more than 100 entries of its own. That is the rule as asked.
