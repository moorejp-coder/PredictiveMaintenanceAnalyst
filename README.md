# PRISM: Predictive Real-time Infrastructure Sensor Monitoring

Live site: https://prismcua.duckdns.org/ (HTTPS)
Local site: http://127.0.0.1:8000/ (run `php artisan serve` in the project folder first)

Stack: Laravel (PHP 8.4), SQLite, AWS Elastic Beanstalk with nginx and a Let's Encrypt certificate, the Anthropic Claude API for explanations, GitHub Actions for tests.

PRISM is a failure-risk dashboard for one industrial milling machine (M-01). It scores risk from 0 to 100 with fixed rules, explains the score in plain English, and shows the stress on each failure check. Every reading on the dashboard is simulated. PRISM is rule-based. It does not use a trained machine-learning model.

## What PRISM does today

- Shows a failure-risk score from 0 to 100 with a band: Low, Elevated (40 and above) or High (70 and above).
- Explains the score in plain English ("Why this score") and shows the stress on each of six failure checks: tool wear, heat buildup, power draw, overstrain, vibration and machine run hours. The score is the highest of the six.
- Shows a seventh gray bar, rotational speed, as context. Speed has no limit of its own. It feeds the heat and power rules. The bar shows how close speed is to the 1,380 rpm heat limit and is not part of the score.
- Runs seven simulated sensor readings (process and air temperature, vibration, machine run hours, rotational speed, torque, tool wear) and a chart of the last 30 risk scores.
- Offers four simulated situations (worn tool, overheating, overload, healthy) to show how the score reacts.
- "Explain this reading" asks Claude for a likely cause and a drafted work order. A built-in explanation shows if the AI is slow or off (see AI steps).
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
| Problem/Opportunity Identification & Relevance | 20% | Names the user: a maintenance technician at a small machine shop with no data scientist (see The problem). The score is backtested on all 10,000 AI4I 2020 records (`php artisan prism:backtest`), and the limits of that test are stated. |
| Functionality & Technical Execution | 25% | Live on AWS Elastic Beanstalk over HTTPS. Sign-in is on, with an email invite flow for judges. AI calls have timeouts, token caps and a built-in fallback. Server-side checks and rate limits guard the override log and the AI routes. Automated tests run on every push (GitHub Actions, PHP 8.4 and 8.5). |
| AI Integration & Innovation | 20% | Rules score the machine and the AI only explains, on request, in two small Claude calls (Diagnose, then Work order). PHP builds a short structured input for each call, and code sets the work order priority. See AI steps. |
| Code Quality, Documentation & GitHub Transparency | 15% | One class per failure check in `app/Services/Checks`, with every limit and its source in `config/prism.php`. Secrets live in environment properties, not in the repo. Login attempts are rate limited. Commit history starts Sep 28, 2026. See Run locally for setup steps. |
| User Experience, Design & Polish | 10% | Plain-English "Why this score" and risk bands shown as words. Lighthouse accessibility score of 100 on the pages tested on Oct 20 and 21, 2026. Light and dark themes, keyboard and VoiceOver pass, reduced motion respected. See Ethical AI, accessibility and sources. |
| Business/Real-World Impact & Presentation | 10% | Goals are fewer unneeded repairs and less unplanned downtime (see What PRISM aims to deliver). The README states that the dataset cannot prove dollar savings, and the Roadmap lists what is not built. |
| Bonus (up to 5) | | Ethical AI: AI-GENERATED labels, a rating on every AI answer, a NIST AI RMF mapping on `/ai`. Accessibility: WCAG 2.2 AA goal with the known gaps listed. Cross-disciplinary: data science, mechanical engineering, occupational safety, law and privacy. |

## HTTPS

The live site uses a free Let's Encrypt certificate on a free DuckDNS name. nginx on the Elastic Beanstalk instance ends the encrypted connection. The setup is configured to send http requests for this name to https and to renew the certificate on its own. The files are `.ebextensions/02-https.config`, `.platform/nginx/conf.d/elasticbeanstalk/acme.conf` and `.platform/hooks/postdeploy/02_https.sh`. The name and the contact email are environment properties (`APP_DOMAIN`, `CERTBOT_EMAIL`, `APP_URL`), not code.

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
- Limit: accounts live in the same SQLite file as the override log, so a replaced or rebuilt server erases every invited judge. Invite again after a rebuild.
- Optional: `JudgeSeeder` can still make one fixed account from `JUDGE_EMAIL` and `JUDGE_PASSWORD`, as a backup if email fails. It comes back after every deploy. The invite page is the main way.
- The sitemap drops `/` while the sign-in is on.

## The problem

PRISM is for the maintenance technician at a small machine shop who runs CNC milling machines and has no data scientist. Raw sensor numbers do not say which failure is developing, so the shop repairs healthy machines or loses production to unplanned breakdowns. PRISM turns the numbers into one score, a reason a technician reads in a few seconds, and an instruction.

## How the score works

- The score is rule-based. Each failure check is its own class in `app/Services/Checks`. Every limit and its source lives in `config/prism.php`. `app/Services/RiskScorer.php` only collects the results.
- Per the code comments, the AI4I 2020 limits for heat, power and overstrain reproduce those labels in the dataset. Random failures cannot be predicted from any reading, so PRISM does not score them.
- Readings come from `app/Services/SensorSimulator.php`. The same scenario and reading number always give the same reading.
- AI4I has no vibration or machine run hours column. Those two readings are simulated, and their checks are not backtested. The vibration limits (1.4, 2.8 and 4.5 mm/s RMS) match the Group 2 rigid-foundation zone boundaries listed for ISO 20816-3, which replaced ISO 10816-3. That standard covers industrial machines above 15 kW and does not list machine tools, so these are demo values for a milling machine. The 8,000 hour overhaul interval is a placeholder.
- Run hours alone never score above 50 (Elevated). Tool wear alone never scores above 60.
- Backtest of the four AI4I checks (tool wear, heat, power, overstrain) on the 10,000 AI4I records. A record counts as failed when its Machine failure label is 1. 18 records are left out (see below). Low: 6 of 7,448 failed (0.1%). Elevated: 42 of 1,889 failed (2.2%). High: 291 of 645 failed (45.1%).
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

The rules do the math. AI explains the result in plain words, only when a visitor presses "Explain this reading" and only when the band is Elevated or High.

- Call 1, Diagnose (`app/Services/Ai/Diagnoser.php`): sends the score, the band, the scored drivers at 40 or above, the fact sentences the checks already wrote, and the derived metrics. Gets back a likely cause and one or two physical checks.
- Call 2, Work order (`app/Services/Ai/WorkOrderStep.php`): sends the diagnosis from call 1. Gets back a title and steps. Code sets the priority from the band. The model does not.
- Neither call sees the scoring rules or the reading history. The server rebuilds the reading from the scenario and tick, so no visitor text reaches a prompt.
- Each call asks for 220 or 260 output tokens, plus 1,024 tokens of thinking headroom, so the request sends a max_tokens of 1,244 or 1,284. Each call has an 8 second timeout, and is not retried. A test checks the input stays under 1,500 characters. The page shows the token counts the API reports.
- If the key is missing, the API is slow, the API errors, or the reply does not parse, the page shows the built-in explanation from the checks. Good replies are cached for an hour. Fallbacks are cached for a minute.
- Both routes are rate limited to 30 requests a minute.

Set `ANTHROPIC_API_KEY` in `.env` (local) or as an Elastic Beanstalk environment property (live). `ANTHROPIC_MODEL` and `ANTHROPIC_TIMEOUT` are optional. With no key, PRISM makes no AI call.

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

## Roadmap (not built)

These items are not in the app today. They are goals, not commitments.

- A trained machine-learning classifier with confidence scores. The scorer class is built so a model could replace it without changing the page. A model would need to beat the rule-based score on the same backtest first.
- Entry of real readings by a technician. Every reading today is simulated.
- A live sensor feed.
- Automated alerts. PRISM today shows its instruction on screen and sends nothing.
- A predicted failure date. AI4I is a snapshot of operating conditions, not a time series, so PRISM flags risk under current conditions and does not forecast a date.

## Feedback from my last project and what changed

My last vibe coding project (a course planner) got this feedback: one large `system_prompt.txt` did the heavy lifting, with the database only storing users and classes. The judge asked for more dynamic use of AI, a few smaller prompts, and filtering in code so the model sees only what applies. The judge also noted AI times out fast on a small token window.

- One rule file became six check classes (`app/Services/Checks`) with every limit and its source in `config/prism.php`. `RiskScorer` only collects the results.
- AI work is split into two small calls. Each gets a short structured input built in PHP, not a rule dump.
- Every AI call has a timeout, a token cap, a cache and a built-in fallback, and runs on a button press, never inside the 1.2 second reading poll.

## Run locally

1. `composer install`
2. `cp .env.example .env && php artisan key:generate`
3. `touch database/database.sqlite && php artisan migrate` (creates the override log and AI feedback tables)
4. Optional: set `OVERRIDE_LOG_PASSWORD` and `ANTHROPIC_API_KEY` in `.env`
5. Optional, to try the sign-in: set `REQUIRE_LOGIN=true` and `INVITE_ADMIN_PASSWORD` in `.env`. Keep `MAIL_MAILER=log`, then open `/admin/invites`, invite an address and copy the link from `storage/logs/laravel.log`. Or set `JUDGE_EMAIL` and `JUDGE_PASSWORD` and run `php artisan db:seed --class=JudgeSeeder`
6. `php artisan serve`
7. Open http://127.0.0.1:8000/

## What PRISM aims to deliver

- Fewer unneeded repairs, by avoiding preventive maintenance on healthy equipment.
- Less unplanned downtime, by catching risky operating conditions before they break production.
- Longer equipment life, through timely intervention.

These are goals. The dataset cannot prove dollar savings.

## Dataset

PRISM takes its limits from, and backtests against, the AI4I 2020 Predictive Maintenance Dataset from the UCI Machine Learning Repository. It holds 10,000 records and 14 features. PRISM trains no model on it.

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
