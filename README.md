# PRISM: Predictive Real-time Infrastructure Sensor Monitoring

[![Tests](https://github.com/moorejp-coder/PredictiveMaintenanceAnalyst/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/moorejp-coder/PredictiveMaintenanceAnalyst/actions/workflows/tests.yml)

Live site: https://prismcua.duckdns.org/ (HTTPS)
Local site: http://127.0.0.1:8000/ (run `php artisan serve` in the project folder first)

Stack: Laravel (PHP 8.4), SQLite, AWS Elastic Beanstalk with nginx and a Let's Encrypt certificate, the Anthropic Claude API for explanations, GitHub Actions for tests.

Hosting: the server runs on the AWS Free Tier. The entrant confirmed this in the AWS account on Oct 22, 2026. The Claude API is a separate service and is not part of the free tier.

PRISM is a failure-risk dashboard for one industrial milling machine (M-01). It scores risk from 0 to 100 with fixed rules, explains the score in plain English, and shows the stress on each failure check. Every reading on the dashboard is simulated. The score and the action come from fixed rules. A trained gradient boosting model adds a second opinion, a learned estimate of failure chance, and never changes the score.

## What PRISM does today

- Shows a failure-risk score from 0 to 100 with a band: Low, Elevated (40 and above) or High (70 and above).
- Explains the score in plain English ("Why this score") and shows the stress on each of six failure checks: tool wear, heat buildup, power draw, overstrain, vibration and machine run hours. The score is the highest of the six.
- Shows a seventh gray bar, rotational speed, as context. Speed has no limit of its own. It feeds the heat and power rules. The bar shows how close speed is to the 1,380 rpm heat limit and is not part of the score.
- Runs seven simulated sensor readings (process and air temperature, vibration, machine run hours, rotational speed, torque, tool wear) and a chart of the last 30 risk scores.
- Offers four simulated situations (worn tool, overheating, overload, healthy) to show how the score reacts.
- "Explain this reading" lets Claude check the reading with five read-only tools, then give a likely cause and a drafted work order. The page lists each tool call. A built-in explanation shows if the AI is slow or off (see AI steps). The simulated feed pauses while the pop-up is open and resumes on Close, so the answer matches the reading on screen. A visitor who paused the feed stays paused. The pop-up is wide, with the cause on the left and the work order on the right, so a long answer fits a laptop screen without scrolling. On a phone it stacks in one column and scrolls.
- Records an override when an operator keeps a machine running after PRISM says to stop it (see Override log).
- Has a sign-in and an email invite flow for contest judges. The sign-in is on at the live site, so the dashboard needs a login (see Sign in and judge invites).
- Includes a FAQ, Terms and Conditions and a Privacy Policy. Each says what PRISM does not do.
- Explains its AI use on the page `/ai`: where AI runs, what it receives, who decides, the backtest limits, a NIST AI RMF mapping and an accessibility statement.
- Labels every AI answer "AI-GENERATED" and lets a visitor rate it Helpful or Wrong (see Ethical AI, accessibility and sources).

Safety disclaimer: PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern.

## How PRISM meets the judging criteria

Criteria and weights come from the CUA AI Vibe Coding Competition, Fall 2026. The right column points to where each claim lives in this repo.

| Criterion | Weight | How PRISM addresses it |
|---|---|---|
| Problem/Opportunity Identification & Relevance | 20% | Names the user: a maintenance technician at a small machine shop with no data scientist (see The problem). Cites three sources and states their limits (see Evidence behind the problem). On the AI4I test data, the High band holds 85.8% of labeled failures in 6.5% of records, and 45.1% of High records failed (see How the score works). The data is synthetic. No technician was interviewed and no user has tried PRISM. |
| Functionality & Technical Execution | 25% | Live on AWS Elastic Beanstalk over HTTPS. Sign-in is on, with an email invite flow for judges. AI calls have timeouts, token caps and a built-in fallback. Server-side checks and rate limits guard the override log and the AI routes. Security headers include a per-request Content-Security-Policy nonce (see Security headers). Automated tests run on every push (GitHub Actions, PHP 8.4 and 8.5). |
| AI Integration & Innovation | 20% | Rules score the machine. On request, Claude investigates the reading with five read-only tools (a check, a trend, a time-to-limit estimate, a what-if on a copy of the reading, and the backtest figures), then a second call drafts the work order. Code rejects any answer with a number or tool the AI was not given, and code sets the work order priority. The AI never changes the score. See AI steps. |
| Code Quality, Documentation & GitHub Transparency | 15% | One class per failure check in `app/Services/Checks`, with every limit and its source in `config/prism.php`. Secrets live in environment properties, not in the repo. Login attempts are rate limited. Commit history starts Sep 28, 2026. See Run locally for setup steps. |
| User Experience, Design & Polish | 10% | Plain-English "Why this score" and risk bands shown as words. Lighthouse accessibility score of 100 on the pages tested on Oct 20 and 21, 2026. Light and dark themes, keyboard and VoiceOver pass, reduced motion respected. See Ethical AI, accessibility and sources. |
| Business/Real-World Impact & Presentation | 10% | Goals are fewer unneeded repairs and less unplanned downtime (see What PRISM aims to deliver). The README states that the dataset cannot prove dollar savings, and the Roadmap lists what is not built. Business value and scale marks each assumption and lists what growing past one machine needs. |
| Bonus (up to 5) | | Ethical AI: AI-GENERATED labels, a rating on every AI answer, a NIST AI RMF mapping on `/ai`. Accessibility: WCAG 2.2 AA goal with the known gaps listed. Cross-disciplinary: data science, mechanical engineering, occupational safety, law and privacy. |

## HTTPS

The live site uses a free Let's Encrypt certificate on a free DuckDNS name. nginx on the Elastic Beanstalk instance ends the encrypted connection. The setup is configured to send http requests for this name to https and to renew the certificate on its own. The files are `.ebextensions/02-https.config`, `.platform/nginx/conf.d/elasticbeanstalk/acme.conf` and `.platform/hooks/postdeploy/02_https.sh`. The name and the contact email are environment properties (`APP_DOMAIN`, `CERTBOT_EMAIL`, `APP_URL`), not code.

## Security headers

`app/Http/Middleware/SecurityHeaders.php` adds browser security headers to every page Laravel serves. `tests/Feature/SecurityHeadersTest.php` checks them.

- Content-Security-Policy: all JavaScript lives in files under `public/js`, and `script-src` allows only PRISM's own origin. The views hold no inline `<script>`, and a test fails if one appears. Inline `<style>` blocks run only with a random nonce the server makes for each request. The policy allows no `unsafe-inline` or `unsafe-eval` for scripts, loads everything from PRISM's own origin, blocks plugins and blocks framing (`frame-ancestors 'none'`). The site loads no outside scripts, styles or fonts, so none are listed.
- Strict-Transport-Security (one year) and `upgrade-insecure-requests`, sent only when the request is HTTPS or `APP_URL` starts with `https://`.
- X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and Cross-Origin-Opener-Policy. A controller that sets its own value keeps it (the set-password page sends `no-referrer`).
- The session cookie is marked Secure when `APP_URL` starts with `https://`. `SESSION_SECURE_COOKIE` overrides this.
- Limits: inline `style="..."` attributes are still allowed (`style-src-attr`), because several pages use them. Files nginx serves directly (CSS, fonts, images) do not pass through Laravel and carry no PRISM headers. No independent scan has been run.

## Sign in and judge invites

The sign-in and the judge invite flow are on at the live site (`REQUIRE_LOGIN=true`), which runs on HTTPS. The contest asks for a working login. With the switch off, which is the default for a local copy, the dashboard is public, `/login` goes back to `/`, and the login, invite and set-password routes return 404.

How a judge gets in:

1. The entrant opens `/admin/invites`, signs in with the `INVITE_ADMIN_PASSWORD` value and types the judge's email address.
2. PRISM creates the account with a random password nobody knows and emails the judge a link. The email holds a link, never a password.
3. The link opens `/set-password/{token}`. The judge chooses a password of at least 12 characters. The link works once and expires after 7 days.
4. The judge signs in at `/login` with that email address and password.

To send a fresh link (a lost email, an expired link), type the same address again. A second invite inside one minute is held back.

To turn it on (this is how the live site was set up). Use HTTPS only, since a password sent over HTTP is readable on the network:

1. Set up an email sender (see Email below). Any SMTP provider works with no added package.
2. In the Elastic Beanstalk console, set the environment properties `REQUIRE_LOGIN=true`, `INVITE_ADMIN_PASSWORD` and the `MAIL_*` values. Use a password for the invite page that no other PRISM password uses.
3. Deploy, then load `/admin/invites`, invite yourself first and walk through steps 2 to 4.
4. Invite the judges.

Email: set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` as environment properties. With the default `MAIL_MAILER=log`, nothing is sent and the invite email is written to the log. If the mail service refuses the message, the invite page shows an error, keeps the account and lets you try again. Amazon SES is one option. While an SES account is in the sandbox, it sends only to verified addresses (AWS SES FAQ), so each judge's address would need verifying first. Mail from a new sender is sometimes filtered, so test with an address like a judge's.

How it works when on:

- Laravel's built-in session login and password broker, no added package. The invites use their own broker named `invites` in `config/auth.php`, with a 7 day life. Laravel deletes a token after it sets a password, so a link works once.
- `RequireLogin` (`app/Http/Middleware/RequireLogin.php`) guards `/` and the `/api/` routes. The pages `/ai`, `/faq`, `/privacy`, `/terms` and `/login` stay public. A separate password still guards `/overrides`.
- Logins are limited to 5 tries a minute. The invite and set-password forms are limited to 10 a minute. The session is renewed at sign-in.
- A judge account stores a name, an email address and a password hash. Every reading is simulated, so the account holds no real personal data. The override form asks judges to type made-up names.
- Limit: accounts live in the same SQLite file as the override log, so a replaced or rebuilt server erases every invited judge. Invite again after a rebuild. Steps are in [docs/RECOVERY.md](docs/RECOVERY.md).
- Optional: `JudgeSeeder` can still make one fixed account from `JUDGE_EMAIL` and `JUDGE_PASSWORD`, as a backup if email fails. It comes back after every deploy. The invite page is the main way.
- The sitemap drops `/` while the sign-in is on.

## Test the live app

Live site: https://prismcua.duckdns.org/ . Every reading is simulated. Sign in with the account from your invite email (see Sign in and judge invites). If you have no account yet, ask the entrant.

Checks that need no account. Run on Oct 22, 2026 against the live site:
- `/api/readings`, `/api/diagnosis`, `/overrides` and `/admin/invites` return 401 when you are signed out.
- A path that does not exist, such as `/nope-xyz`, returns 404.
- The sign-in page sends `Strict-Transport-Security`, `X-Content-Type-Options: nosniff` and a Content-Security-Policy header.

Checks after you sign in. The entrant ran steps 1 to 6 on the live site on Oct 22, 2026 and all worked. The entrant also ran one invite from start to finish (email, set password, sign in) and signed in again after a deploy to confirm accounts survive a normal deploy. The entrant also signed in with the backup judge account (`JUDGE_EMAIL` and `JUDGE_PASSWORD`) on Oct 22, 2026. The expected results also come from the code and the automated tests in `tests/`.

1. Open the dashboard. A new reading arrives about every 1.2 seconds.
2. Choose Overload under Simulated situation. Wait for the score to reach the High band. The panel shows "High Predicted Failure Risk" and the instruction to stop the machine (`tests/Feature/OverrideLogTest.php` uses Overload at reading 59 as a High reading).
3. Press "Explain this reading". The answer carries the label AI-GENERATED. If the AI is slow, off or wrong in format, the label reads "RULE-BASED, NO AI USED" and a built-in explanation appears (see AI steps).
4. Rate the answer Helpful or Wrong. A thank-you appears beside the buttons.
5. Press "Keep running anyway". Type a made-up name and a reason of at least 10 characters. The server accepts the entry only for a High reading. For a Healthy reading it returns 422 (`tests/Feature/OverrideLogTest.php`).
6. Open `/overrides`. It asks for its own password, which only the entrant holds.

Edge cases the tests cover: an unknown scenario name, an override for a reading that is not High, a score sent by the browser (the server ignores it), a missing AI key, an AI timeout, an unknown AI tool, a wrong invite token, an invite link used twice, an invite link older than 7 days, a short or mismatched password, an invalid invite email, a wrong sign-in password (one generic message), the rate limit on sign-in, diagnosis, work order, override, rating, set-password and invite routes (429 after the allowed number), the styled 419 and 429 pages, and the health check. Test files: `OverrideLogTest`, `AiStepsTest`, `InvestigatorTest`, `InviteTest`, `LoginTest`, `ErrorPagesTest`, `RateLimitTest`, `HealthTest`.

`/health` is public. It returns `{"status":"ok"}` when the app reaches its database and the `users`, `override_logs` and `ai_feedback` tables exist. Otherwise it returns 503 with `{"status":"unavailable"}` and writes the reason to the log. It names no table in the reply. It returned ok on the live site on Oct 22, 2026. Elastic Beanstalk does not use it yet.

## The problem

PRISM is for the maintenance technician at a small machine shop who runs CNC milling machines and has no data scientist. In this README, small means fewer than 100 employees. That is my assumption, chosen because 44 of the 71 firms in the NIST survey below were that size. Raw sensor numbers do not say which failure is developing. PRISM turns the numbers into one score, a reason a technician reads in a few seconds, and an instruction.

How maintenance is done without a tool like PRISM, according to the sources below:
- 21% of the industrial businesses ABB surveyed still run equipment until it fails.
- Neugebauer and Fischer (2009) report that time-based maintenance plans are often not followed consistently.

What unplanned stops cost, according to the sources below:
- ABB: close to $125,000 per hour for the typical industrial business (median, self-reported, not machine shops).
- NIST: the half of respondents with the highest reactive maintenance spending averaged 10.4% unplanned downtime as a share of planned production time. The other half averaged 4.9%. The authors call this anecdotal.

Reading these sources, I see a gap between running to failure and following a calendar: a technician with no reading-based reason to stop a machine. That reading is my interpretation. No source states it.

## Evidence behind the problem

Three sources support the problem. None tests PRISM.

- ABB, Value of Reliability survey, press release of October 11, 2023. ABB surveyed 3,215 plant maintenance decision-makers worldwide. Unplanned downtime cost close to $125,000 per hour for the typical business. ABB used the median, and the figures are self-reported. More than two-thirds of the industrial businesses surveyed had unplanned outages at least once a month. The industries listed include energy, chemicals, metals and food and beverage. The release does not name machine shops or break results out by shop size. ABB also reports that 21 percent of businesses surveyed still rely on run-to-fail maintenance. Source: https://new.abb.com/news/detail/107660/abb-survey-reveals-unplanned-downtime-costs-125000-per-hour. Dan Zeiger quotes the figure in an ISM article dated August 27, 2024.
- Adu-Amankwa, Attia, Janardhanan and Patel (2019), "A predictive maintenance cost model for CNC SMEs in the era of industry 4.0," The International Journal of Advanced Manufacturing Technology 104, 3567 to 3587. The authors found no earlier study with a predictive maintenance cost model for small and medium CNC shops. Their model predicts savings of 22,804 to 48,585 British pounds for setups of 1 to 50 machines. These are model predictions, not measured results. The page I read does not state the time period. Source: https://doi.org/10.1007/s00170-019-04094-2.
- Thomas and Weiss (NIST), International Journal of Prognostics and Health Management 12(1). The survey has 71 usable responses, and 44 come from establishments with fewer than 100 employees. The authors estimate annual U.S. maintenance costs and losses at $222.0 billion on average. The half of respondents with the highest reactive maintenance spending averaged 10.4% unplanned downtime as a share of planned production time. The other half averaged 4.9%, which is 52.7% lower (Table 6). The paper does not report group sizes. Firms relying more on predictive maintenance spent 212.3% more on direct maintenance relative to shipments. The authors treat the results as anecdotal evidence. The paper does not report results for small firms or machine shops alone, and it cites earlier work saying small firms might rely more on reactive maintenance. Source: https://papers.phmsociety.org/index.php/ijphm/article/download/2883/1748
- No technician or shop owner was interviewed for PRISM. The user need rests on published sources. The Thomas and Weiss NIST survey collected responses from 71 manufacturing establishments, 44 of them with fewer than 100 employees. It reports lower unplanned downtime among firms that rely less on reactive maintenance. The authors treat the results as anecdotal evidence. These sources show the problem exists in manufacturing. They do not show that a small shop would use PRISM.

What the evidence does not show: PRISM's own savings. No shop has used PRISM, and AI4I is synthetic data. The value stays a goal (see What PRISM aims to deliver).

## What the literature monitors

Four published sources describe what condition monitoring covers on machine tools. They describe monitoring in general. None reports what technicians say they check, and none studies small shops. I read each at its original page.

- Neugebauer and Fischer (2009), "Condition-Based Preventive Maintenance of Machine Tools," Journal of Machine Engineering 9(3), Fraunhofer Institute. For the main spindle it lists bearing temperature, vibration, motor current and active power, and it lists operating hours as a general input. It does not list tool wear. It says time-based plans are often not followed consistently and argues for condition-based maintenance.
- Rastegari and Archenti (2017), "Condition Based Maintenance of Machine Tools: Vibration Monitoring of Spindle Units," IEEE Reliability and Maintainability Symposium. It says spindle bearing wear is common and vibration analysis diagnoses it. The page I read mentions warning limits but gives no numeric limits and names no standard.
- Korn (2010), "Taking Spindle Health Seriously," Production Machining magazine. A shop sets alarm limits on spindle temperature, and warning and alarm limits on vibration by machine type. The article does not mention power or load.
- Zhou and Xue (2018), "Review of tool condition monitoring methods in milling processes," The International Journal of Advanced Manufacturing Technology 96, 2509 to 2523. The abstract says accurate tool condition monitoring is essential for fully automated milling but hard to implement. The page I read names no signals.

| PRISM check | Support in these sources | Where PRISM's rule comes from |
|---|---|---|
| Vibration | Strong. Neugebauer and Fischer, Rastegari and Archenti, and Korn all monitor vibration. | No source gives numeric limits. PRISM uses ISO 20816-3 zone boundaries as demo values. |
| Heat | Partial. Neugebauer and Fischer (bearing temperature) and Korn (spindle temperature limits). They monitor the spindle bearing. | The AI4I labels. |
| Power draw | Partial. Neugebauer and Fischer list motor current and active power. Korn does not mention power or load. | The AI4I labels. |
| Tool wear | Weak. Zhou and Xue show tool condition monitoring is an established topic in milling research. They support the topic, not a limit. Neugebauer and Fischer do not list tool wear. | The AI4I data. |
| Overstrain | Weak. Neugebauer and Fischer list torque for axle drives. They do not describe overstrain as PRISM defines it. | The AI4I labels. |
| Run hours | Partial. Neugebauer and Fischer list operating hours as an input and warn that time-based plans are often not followed. | PRISM caps run hours alone at 50, so hours alone never give a High score. The 8,000 hour interval is a placeholder. |

These parts of PRISM are my own choices, and none of the four sources supports them: the 0 to 100 score as the highest of six checks, the Elevated (40 and above) and High (70 and above) bands, and the simulated readings.

Limits: the sources are from 2009 to 2018. For Rastegari and Archenti and for Zhou and Xue I read the abstract page, not the full paper. I found no machine builder guide, and I could not open some pages I tried. Tool wear support rests on one review's abstract and reference titles.

## How the score works

- The score is rule-based. A learned estimate sits beside it (see "Learned estimate" below). Each failure check is its own class in `app/Services/Checks`. Every limit and its source lives in `config/prism.php`. `app/Services/RiskScorer.php` only collects the results.
- Per the code comments, the AI4I 2020 limits for heat, power and overstrain reproduce those labels in the dataset. Random failures cannot be predicted from any reading, so PRISM does not score them.
- Readings come from `app/Services/SensorSimulator.php`. The same scenario and reading number always give the same reading.
- AI4I has no vibration or machine run hours column. Those two readings are simulated, and their checks are not backtested. The vibration limits (1.4, 2.8 and 4.5 mm/s RMS) match the Group 2 rigid-foundation zone boundaries listed for ISO 20816-3, which replaced ISO 10816-3. That standard covers industrial machines above 15 kW and does not list machine tools, so these are demo values for a milling machine. The 8,000 hour overhaul interval is a placeholder.
- Run hours alone never score above 50 (Elevated). Tool wear alone never scores above 60.
- Backtest of the four AI4I checks (tool wear, heat, power, overstrain) on the 10,000 AI4I records. A record counts as failed when its Machine failure label is 1. 18 records are left out (see below). Low: 6 of 7,448 failed (0.1%). Elevated: 42 of 1,889 failed (2.2%). High: 291 of 645 failed (45.1%).
- Catch rate, from the same counts: the High band holds 291 of the 339 labeled failures (291 / 339 = 85.8%) while holding 645 of the 9,982 scored records (645 / 9,982 = 6.5%). The Low band holds 6 of the 339 failures (1.8%). The limits below apply to these figures.
- Reproduce the figures with `php artisan prism:backtest`. The dataset file is `data/ai4i2020.csv`. The command also prints failures by AI4I failure mode.

Read the backtest with three limits in mind:
- The rules use limits taken from AI4I, and the test runs on AI4I. The backtest checks that the rules agree with the dataset labels. It does not show how they perform on a real machine.
- AI4I is synthetic data. It was generated to mirror real milling data.
- 45.1% means 354 of the 645 High records did not fail in the data.
- 9 of the 339 labeled failures have no failure mode flagged in the dataset. 6 of them land in Low, 2 in Elevated and 1 in High. No check can explain a failure with no cause recorded.
- The 18 records left out are flagged random failure (RNF) but carry a Machine failure label of 0. Random failure cannot be predicted from any reading. Scoring them too (`php artisan prism:backtest --keep-random`) puts 13 in Low, 4 in Elevated and 1 in High, with no added failures: Low 6 of 7,461 (0.1%), Elevated 42 of 1,893 (2.2%), High 291 of 646 (45.0%).

## Override log

When PRISM says "Stop the machine and inspect it now," the dashboard shows a "Keep running anyway" button.

- The button opens a form for the operator's name (2 to 100 characters) and a reason (10 to 500 characters).
- The server rebuilds the reading and the score from the scenario and reading number. It accepts an entry only for a High-risk reading, and it ignores any score the browser sends.
- Each entry stores the name, the reason, the time, the machine, the risk score, the action shown and the reading.
- The review page `/overrides` lists the newest 200 entries. It asks for a password: sign in with any user name and the value of the `OVERRIDE_LOG_PASSWORD` environment property. With no password set, the page returns 404.
- The write route is limited to 10 requests a minute.
- Limits: the name is whatever the operator types and is not tied to any sign-in, so it is not verified. The log sends no notification to anyone. The log is a SQLite file on the host, so a replaced or rebuilt server erases it.

## AI steps

The rules do the math. AI investigates and explains the result, only when a visitor presses "Explain this reading" and only when the band is Elevated or High.

- Call 1, Investigate (`app/Services/Ai/Investigator.php`): sends the score, the band, the scored drivers at 40 or above, the fact sentences the checks already wrote, and the derived metrics. Claude then calls up to five read-only tools (`app/Services/Ai/ToolBox.php`), in at most four rounds, and returns a likely cause, one or two physical checks and the names of the tools it used.
- The five tools: `get_check` (one check's stress and sentence), `get_trend` (a channel's slope over the current cycle), `time_to_limit` (a straight-line estimate in readings and minutes, simulated data only), `what_if` (scores a copy of the reading with one channel changed) and `get_backtest` (AI4I failure counts by band). Arguments are fixed choices or numbers in a fixed range. No tool writes anything and no tool changes the real score.
- Checked in code: the answer must name only tools it called, and every number in its words must appear in the input or in a tool result (whole numbers may be rounded, minutes may be written as hours). An answer that fails is dropped.
- The what_if gate: every `what_if` result carries an `effect` (`lowers_score`, `no_change` or `raises_score`). Only a change that lowers the score is a tested fix. If the model tries to answer before any `what_if`, code sends it back once. A value that did not lower the score is removed from the numbers the answer may use, so the answer cannot name it as a target. The prompt also tells the model to treat each limit as a failure zone and never advise a move toward it. Code cannot check the direction of every sentence, so a technician still decides.
- Tested fix: when a `what_if` lowers the score, code writes one sentence from the best result ("PRISM scored a copy of the reading with torque set to 40 Nm. The score falls from 72 to 60 (elevated)") and the page shows it under "Tested on a copy". The AI picks the value. The rules do the scoring, and the sentence is not AI text.
- Time estimates: `time_to_limit` is a straight-line extrapolation of simulated data. The prompt asks the AI to say so. If the cause quotes the estimate without the words "straight-line", code adds "This is a straight-line estimate on simulated data." The work order prompt bans time estimates in the steps.
- If the investigation fails, the older single Diagnose call runs (`app/Services/Ai/Diagnoser.php`), but only when the model answered and the answer failed a check. After a timeout or an API error PRISM goes straight to the built-in text, so the visitor does not wait for a second call. Set `PRISM_INVESTIGATOR=false` to use the single Diagnose call only.
- Call 2, Work order (`app/Services/Ai/WorkOrderStep.php`): sends the diagnosis from call 1. Gets back a title and steps. Code sets the priority from the band. The model does not.
- The score and the work order priority never depend on the AI. The server rebuilds the reading from the scenario and tick, so no visitor text reaches a prompt or a tool.
- Limits: the investigation stops after four rounds or 12 seconds, and each round asks for 400 output tokens plus 1,024 tokens of thinking headroom. The work order call asks for 260 plus 1,024 and has an 8 second timeout. A test checks the Diagnose input stays under 1,500 characters. The page shows the token counts the API reports, summed over the rounds.
- If the key is missing, the API is slow, the API errors, or the reply does not parse or fails the number check, the page shows the built-in explanation from the checks. Good replies are cached for an hour. Fallbacks are cached for a minute.
- The diagnosis route is rate limited to 10 requests a minute, because each press makes up to four model calls. The work order route is limited to 30 a minute.
- Tests (`tests/Feature/ToolBoxTest.php`, `tests/Feature/InvestigatorTest.php`) fake the API and cover a tool call then an answer, the round cap, a refused tool, an invented number, a tool the answer did not call, a timeout, no key and the off switch.

Set `ANTHROPIC_API_KEY` in `.env` (local) or as an Elastic Beanstalk environment property (live). `ANTHROPIC_MODEL`, `ANTHROPIC_TIMEOUT` and `PRISM_INVESTIGATOR` are optional. With no key, PRISM makes no AI call.

## Ethical AI, accessibility and sources

Ethical AI
- Rules score the machine. The AI only explains, on request. It cannot change the score, the band or the action. Code sets the work order priority.
- Every AI answer shows an "AI-GENERATED" label and a reminder that a qualified technician decides. When PRISM falls back to its built-in text, the label reads "RULE-BASED, NO AI USED" so it never claims AI that did not run.
- The dashboard shows how often a High score was right in the AI4I test (291 of 645, 45.1%), so the score is not read as certain. The numbers live in `config/prism.php` under `backtest` and must match `php artisan prism:backtest`.
- A visitor rates an AI answer Helpful or Wrong. The route is `POST /api/ai-feedback`. It accepts fixed choices only. The table `ai_feedback` stores the rating, the step, the source, the scenario, the reading number and the time. It stores no name, no free text and no IP address. The override log page shows the counts.
- The page `/ai` maps PRISM to the NIST AI Risk Management Framework functions (Govern, Map, Measure, Manage). The mapping is mine. NIST has not reviewed PRISM.
- Privacy Policy and FAQ list the stored records: the override log and AI answer ratings, plus judge accounts and the invite email when the sign-in is on.

Accessibility (goal: WCAG 2.2 Level AA, no independent audit)
- Lighthouse accessibility audit, October 20, 2026, on the live site: 100 on every page the author tested, including the dashboard and `/ai`, in desktop and mobile modes and in both the light and dark themes. An earlier run on a local copy also gave 100 on both (Lighthouse 13.4.1, axe-core 4.12.1). Lighthouse checks only a subset of issues. The author also walked the dashboard with VoiceOver on macOS and with the keyboard only (Chrome) on October 20, 2026 and found no issues. At 200% and 400% browser zoom the layout reflows and the two pop-ups scroll vertically inside the window.
- Sign-in pages, October 21, 2026, on the live site: Lighthouse accessibility score of 100 on `/login`, `/set-password` and `/admin/invites`. An axe-core 4.10.2 scan of `/login` found 0 violations in the light and dark themes. The author did not run a keyboard-only or screen reader pass on these three pages.
- Skip link, main landmark, a visible focus outline and native dialogs that return focus.
- Risk bands appear as words, not color alone.
- Each stress bar is a labeled `role="meter"` with a value and a band.
- The risk chart keeps its text description and adds a "View the chart data as a table" control. The table fills only while open, so screen readers are not interrupted every 1.2 seconds.
- Band changes use a status region. A new High score uses an alert.
- Light and dark themes. PRISM follows the system setting, and a header switch overrides it (choice saved in the browser, `localStorage` key `prism-theme`). Colors are CSS tokens in `public/css/prism.css`. The chart reads its colors from the same tokens. Light theme contrast ratios are in `docs/BRAND.md`.
- Animations stop under reduced motion.
- Known gaps: no independent audit, no test with people who use assistive technology, only VoiceOver tried.

Fields PRISM draws on
- Data science: AI4I 2020 dataset (Matzka, 2020).
- Mechanical and reliability engineering: ISO 20816-3 Group 2 vibration zones (replaced ISO 10816-3).
- Occupational safety: the stop instruction sits beside OSHA lockout/tagout, 29 CFR 1910.147. PRISM does not lock out or tag out anything.
- Law and privacy: Terms, Privacy Policy and the `/ai` disclosure.

## Learned estimate

Next to the rule score, the dashboard shows "Learned estimate: X% chance of failure". A gradient boosting model produces it. The rules still decide the score, the band and the action.

- Inputs (14): air and process temperature, speed, torque, tool wear, machine type, temperature gap, power, wear times torque, and the stress of the four AI4I checks.
- Training: `ml/train_model.py`, scikit-learn, on the AI4I records (9,982 after dropping 18 random-failure-only rows). The trained trees are exported to `resources/ml/failure_model.json` and run in PHP (`app/Services/Ml`). A test checks PHP against Python on 300 saved rows to within 0.000001.
- Method: 30% of records were locked as a test set before any model choice (seed 42). The model size was picked by 5-fold cross-validation on the other 70%. The test set was scored once.
- Calibration: next to the percentage the page shows how often readings in the same range failed, from predictions made on rows the model did not train on.

Locked test set (2,995 records, 102 failures). Flag rate set to about the share the rules flag as High.

| Model | PR-AUC | Recall | Precision |
|---|---|---|---|
| PRISM rules, High band | 0.860 | 0.902 | 0.495 |
| Gradient boosting (shipped) | 0.919 | 0.951 | 0.449 |
| Decision tree, depth 4 | 0.914 | 0.971 | 0.318 |
| Decision tree, depth 6 | 0.914 | 0.951 | 0.383 |
| Logistic regression, engineered inputs | 0.506 | 0.725 | 0.346 |
| Logistic regression, raw inputs | 0.458 | 0.637 | 0.301 |

- PR-AUC gap, boosting minus rules: +0.059. A bootstrap 95% interval is +0.020 to +0.103. In 15 repeated cross-validation folds, boosting was ahead in all 15 (mean PR-AUC 0.906 vs 0.837).
- Precision is lower than the rules at the chosen flag rate. Recall is higher. The estimate is not a clear win on every measure.
- Caveats: AI4I is synthetic. Its failure labels come from the same rules the dataset documents, and PRISM's rule limits come from those documents, so the model mostly relearns those rules plus smoother edges. Each model type got one configuration, not a full search. Cross-validation folds share rows, so the 15 folds are not independent. Nothing here shows the model works on a real machine.
- Full numbers: `resources/ml/model_card.json`. Retrain with `php artisan prism:export-features data/ai4i2020.csv --out=ml/features.csv` and `python3 ml/train_model.py`.

## Roadmap (not built)

These items are not in the app today. They are goals, not commitments.

- Letting the learned estimate drive the score or the action. Today the rules decide. The estimate needs real machine data first, because AI4I is synthetic.
- Entry of real readings by a technician. Every reading today is simulated.
- A live sensor feed.
- Testing PRISM with a maintenance technician at a machine shop. No user has tried it yet.
- Measuring results in a pilot: unplanned stops per machine per month, and the share of High alerts followed by a confirmed fault. PRISM records neither today.
- Automated alerts. PRISM today shows its instruction on screen and sends nothing.
- A predicted failure date. AI4I is a snapshot of operating conditions, not a time series, so PRISM flags risk under current conditions and does not forecast a date.

## Feedback from my last project and what changed

My last vibe coding project (a course planner) got this feedback: one large `system_prompt.txt` did the heavy lifting, with the database only storing users and classes. The judge asked for more dynamic use of AI, a few smaller prompts, and filtering in code so the model sees only what applies. The judge also noted AI times out fast on a small token window.

- One rule file became six check classes (`app/Services/Checks`) with every limit and its source in `config/prism.php`. `RiskScorer` only collects the results.
- AI work is split into two small calls. Each gets a short structured input built in PHP, not a rule dump.
- Every AI call has a timeout, a token cap, a cache and a built-in fallback, and runs on a button press, never inside the 1.2 second reading poll.

## Code quality

- All JavaScript is in `public/js` (no inline scripts). Each file is loaded with a version query so a deploy never serves stale code.
- `.github/workflows/lint.yml` runs on every push and pull request: Pint (code style, `vendor/bin/pint --test`), Larastan at level 8 (`phpstan.neon`) and a syntax check of every JavaScript file. `.github/workflows/tests.yml` runs the PHPUnit suite on PHP 8.4 and 8.5.
- Run the same checks locally: `vendor/bin/pint --test`, then `composer require --dev larastan/larastan:^3.0` once and `vendor/bin/phpstan analyse`.
- Limits: Larastan is installed during the CI run and is not in `composer.lock` yet. Level 8 passes with no ignored errors and no baseline. Level 9 (the strictest) reports about 70 findings, mostly values read from `config()` that are not cast to a type, and I have not fixed them. The JavaScript has syntax checks but no unit tests. The browser behavior was checked by hand and with Playwright, which is not part of CI.

## Run locally

1. `composer install`
2. `cp .env.example .env && php artisan key:generate`
3. `touch database/database.sqlite && php artisan migrate` (creates the override log and AI feedback tables)
4. Optional: set `OVERRIDE_LOG_PASSWORD` and `ANTHROPIC_API_KEY` in `.env`
5. Optional, to try the sign-in: set `REQUIRE_LOGIN=true` and `INVITE_ADMIN_PASSWORD` in `.env`. Keep `MAIL_MAILER=log`, then open `/admin/invites`, invite an address and copy the link from `storage/logs/laravel.log`. Or set `JUDGE_EMAIL` and `JUDGE_PASSWORD` and run `php artisan db:seed --class=JudgeSeeder`
6. `php artisan serve`
7. Open http://127.0.0.1:8000/

## What PRISM aims to deliver

- Less unplanned downtime, by catching risky operating conditions before they break production.
- Alerts a technician trusts.

These are goals. PRISM does not measure either one today. The app records no stops, no downtime and no confirmed faults. Besides judge accounts, it stores only the override log and the Helpful and Wrong ratings on AI answers. The dataset cannot prove dollar savings. A pilot would need to add the two measures in the Roadmap.

Measured so far, on the AI4I backtest only: the High band holds 291 of 339 labeled failures (85.8%) in 6.5% of scored records, and 45.1% of High records failed, so most High alerts in that data did not. The backtest limits are in How the score works.

Dropped claim: an earlier draft aimed at fewer unneeded repairs and longer equipment life. I found no verified source for either, so they are not goals here.

## Business value and scale

Assumptions are labeled. Nothing here is a measured result.

- Who uses PRISM: the maintenance technician described in The problem.
- Who would pay (assumption, not researched): the owner or maintenance manager of a small CNC shop.
- Pricing: no PRISM price is set and this README gives no revenue figure. For reference, maintenance work-order tools list these prices per user per month: MaintainX Essential $20 billed annually ($25 monthly) and Premium $65 ($75); UpKeep Essential $24 and Premium $55. MaintainX lists IoT sensor integrations under its Enterprise plan with custom pricing. I read these vendor pages on Oct 22, 2026. The pages show no effective date. I did not test whether these tools score failure risk from readings.
- What exists today: one simulated milling machine (M-01), one server and one SQLite file. All readings come from `app/Services/SensorSimulator.php`, and one set of limits lives in `config/prism.php`.

What growing past one machine would need (none of this is built):
- Real readings from a technician or a sensor feed in place of the simulator (see Roadmap).
- One score and one set of limits per machine. The vibration limits are demo values, since ISO 20816-3 does not list machine tools.
- A shared database in place of the single SQLite file. Rebuilding the server erases that file today (see Sign in and judge invites).
- A test of the rules on real machine data. The backtest checks the rules against AI4I labels only.

## Dataset

PRISM takes its limits from, and backtests against, the AI4I 2020 Predictive Maintenance Dataset from the UCI Machine Learning Repository. It holds 10,000 records and 14 features. PRISM trains one model on it (see "Learned estimate").

- Synthetic data: AI4I was generated to mirror real milling data. It is not pulled from a factory floor.
- Rare failures: failures make up roughly 3 percent of records. A rule or model that predicts "no failure" every time scores about 97 percent accuracy and has no value. The backtest above reports how often failures fall in each band, not accuracy.
- Multiple modes: one record carries more than one failure mode at once.
- Random failure is a known limit: the dataset's random failures occur with a 0.1 percent probability independent of every parameter (UCI), so no sensor reading predicts them. PRISM does not score random failure.

## Citation

Matzka, S. (2020). AI4I 2020 Predictive Maintenance Dataset [Dataset]. UCI Machine Learning Repository. https://doi.org/10.24432/C5HS5C

License: Creative Commons Attribution 4.0 International (CC BY 4.0). Source page: https://archive.ics.uci.edu/dataset/601/ai4i+2020+predictive+maintenance+dataset

James Moore: "CUA Busch School AI Vibe Coding Contest, Fall 2026"

## Brand
Brand rules, logo assets and color tokens: [docs/BRAND.md](docs/BRAND.md).
