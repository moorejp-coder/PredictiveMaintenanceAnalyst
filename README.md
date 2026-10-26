# PRISM: Predictive Real-time Infrastructure Sensor Monitoring

[![Tests](https://github.com/moorejp-coder/PredictiveMaintenanceAnalyst/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/moorejp-coder/PredictiveMaintenanceAnalyst/actions/workflows/tests.yml)

- Live site: https://prismcua.duckdns.org/ (HTTPS)
- Judge Demo (no login, no password, read-only): https://prismcua.duckdns.org/demo
- Local site: http://127.0.0.1:8000/ (run `php artisan serve` in the project folder first)

Stack: Laravel (PHP 8.4), SQLite, the Anthropic Claude API for explanations and GitHub Actions for tests. The live site runs on AWS Elastic Beanstalk with nginx and a Let's Encrypt certificate (see [docs/DEPLOY.md](docs/DEPLOY.md)).

PRISM is a failure-risk dashboard for one industrial milling machine (M-01). It scores risk from 0 to 100 with fixed rules, explains the score in plain English, and shows the stress on each failure check. Every reading on the dashboard is simulated. The score and the action come from fixed rules. A trained gradient boosting model adds a second opinion, a learned estimate of failure chance, and never changes the score.

Safety disclaimer: PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern.

## What PRISM does

- Shows a failure-risk score from 0 to 100 with a band: Low, Elevated (40 and above) or High (70 and above).
- Explains the score in plain English ("Why this score") and shows the stress on each of six failure checks: tool wear, heat buildup, power draw, overstrain, vibration and machine run hours. The score is the highest of the six.
- Shows a seventh gray bar, rotational speed, as context. Speed has no limit of its own and is not part of the score. The bar shows how close speed is to the 1,380 rpm heat limit.
- Runs seven simulated sensor readings (process and air temperature, vibration, machine run hours, rotational speed, torque, tool wear), a new reading about every 1.2 seconds, and a chart of the last 30 risk scores.
- Offers four simulated situations (worn tool, overheating, overload, healthy) to show how the score reacts.
- Offers "Explain this reading". Claude checks the reading with six read-only tools, then gives a likely cause and a drafted work order. The page lists each tool call. A built-in explanation shows if the AI is slow or off. The simulated feed pauses while the pop-up is open and resumes on Close. A visitor who paused the feed stays paused.
- Labels every AI answer "AI-GENERATED" and lets a visitor rate it Helpful or Wrong.
- Shows a learned estimate of failure chance next to the score.
- Records an override when an operator keeps a machine running after PRISM says to stop it.
- Offers a sign-in and a read-only Judge Demo at `/demo`.
- Includes a FAQ, Terms and Conditions and a Privacy Policy. Each says what PRISM does not do.
- Explains its AI use on `/ai`: where AI runs, what it receives, who decides, the backtest limits, a NIST AI RMF mapping and an accessibility statement.

## What PRISM does not do

- It reads no real machine. Every reading comes from `app/Services/SensorSimulator.php`, and a technician cannot enter real readings.
- It sends no alerts. The only email it sends is an invite, and only when the entrant sends one.
- It gives no failure date. The projection looks a few readings ahead on simulated trends.
- It records no stops, downtime or confirmed faults.
- It lets neither the AI nor the learned estimate change the score, the band or the action.
- It does not verify the operator name typed into the override log.

## How PRISM meets the judging criteria

Criteria and weights come from the CUA AI Vibe Coding Competition, Fall 2026.

| Criterion | Weight | Where to look |
|---|---|---|
| Problem/Opportunity Identification & Relevance | 20% | The user is a maintenance technician at a small machine shop with no data scientist. Sources and their limits are in [docs/EVIDENCE.md](docs/EVIDENCE.md). The data is synthetic. |
| Functionality & Technical Execution | 25% | Live over HTTPS. The Judge Demo needs no account. AI calls have timeouts, token caps and a built-in fallback. Server-side checks and rate limits guard the override log and the AI routes. A per-request Content-Security-Policy nonce is set (see [SECURITY.md](SECURITY.md)). Tests run on every push (PHP 8.4 and 8.5). |
| AI Integration & Innovation | 20% | Rules score the machine. A gradient boosting model gives a second opinion. Claude investigates with six read-only tools, then drafts a work order. Code rejects a cause that names a tool the AI did not call or quotes a number it was not given. See AI steps below and [docs/AI.md](docs/AI.md). |
| Code Quality, Documentation & GitHub Transparency | 15% | One class per failure check in `app/Services/Checks`, with every limit and its source in `config/prism.php`. Secrets live in environment properties. Login attempts are rate limited. See [docs/QUALITY.md](docs/QUALITY.md). |
| User Experience, Design & Polish | 10% | "Why this score" in plain English, risk bands shown as words, light and dark themes, keyboard use and reduced motion. See Ethical AI and accessibility below. |
| Business/Real-World Impact & Presentation | 10% | [docs/BUSINESS.md](docs/BUSINESS.md) holds the value case, the model cost per investigation and a break-even formula, with each assumption marked. PRISM measures no downtime saving. |
| Bonus (up to 5) | | AI-GENERATED labels, a rating on every AI answer and a NIST AI RMF mapping on `/ai`. Accessibility known gaps are listed in [docs/AI.md](docs/AI.md). |

## How the score works

- The score is rule-based. Each failure check is its own class in `app/Services/Checks`. Every limit and its source lives in `config/prism.php`. `app/Services/RiskScorer.php` collects the results.
- The same scenario and reading number always give the same reading.
- Run hours alone never score above 50 (Elevated). Tool wear alone never scores above 60.
- AI4I has no vibration or machine run hours column. Those two readings are simulated, and their checks are not backtested. The vibration and run hour limits are demo values.
- Backtest of the four AI4I checks (tool wear, heat, power, overstrain) on 9,982 records (18 random-failure records left out): Low band 6 of 7,448 failed (0.1%), Elevated 42 of 1,889 (2.2%), High 291 of 645 (45.1%). The High band holds 291 of the 339 labeled failures (85.8%) in 6.5% of scored records.
- The backtest checks that the rules agree with the dataset labels, because the limits come from AI4I. It does not show how they perform on a real machine. AI4I is synthetic. 354 of the 645 High records did not fail.
- Reproduce the figures with `php artisan prism:backtest`. The dataset file is `data/ai4i2020.csv`. More detail is in [docs/SCORING.md](docs/SCORING.md).

## Override log

- When PRISM says "Stop the machine and inspect it now," the dashboard shows a "Keep running anyway" button.
- The form asks for a name (2 to 100 characters) and a reason (10 to 500 characters).
- The server rebuilds the reading and the score from the scenario and reading number. It accepts an entry only for a High-risk reading and ignores any score the browser sends.
- `/overrides` lists the newest 200 entries behind its own password (`OVERRIDE_LOG_PASSWORD`). With no password set, the page returns 404.
- The write route is limited to 10 requests a minute. The log is a SQLite file on the host, so a replaced server erases it.

## AI steps

- The rules do the math. AI runs only when a visitor presses "Explain this reading" and the band is Elevated or High.
- Call 1, Investigate: Claude calls up to six read-only tools in at most four rounds, then returns a likely cause and one or two physical checks. The tools are `get_check`, `get_trend`, `time_to_limit`, `what_if`, `get_learned_estimate` and `get_backtest`. No tool writes anything.
- Code checks the answer. It must name only tools it called, and every number in it must appear in the input or in a tool result. An answer that fails is dropped.
- Tested fix: when a `what_if` lowers the score, code writes one sentence from that result. The AI picks the value. The rules do the scoring.
- Call 2, Work order: Claude returns a title and steps. Code sets the priority from the band.
- If the key is missing, the API is slow or errors, or the reply fails a check, the page shows a built-in explanation labeled "RULE-BASED, NO AI USED". Good replies are cached for an hour and fallbacks for a minute.
- The investigation stops after four rounds or 12 seconds. The diagnosis route is limited to 10 requests a minute and the work order route to 30.
- Set `ANTHROPIC_API_KEY` in `.env` (local) or as an environment property (live). `ANTHROPIC_MODEL`, `ANTHROPIC_TIMEOUT` and `PRISM_INVESTIGATOR` are optional. With no key, PRISM makes no AI call.
- `php artisan prism:eval-investigator` runs the investigator over simulated readings and counts how many answers pass the code checks. The recorded run is in [docs/EVAL.md](docs/EVAL.md). The command does not grade the advice, and no technician graded the answers.

## Learned estimate

- The dashboard shows "Learned estimate: X% chance of failure". A gradient boosting model produces it from 14 inputs. The rules still decide the score, the band and the action.
- Training: `ml/train_model.py` (scikit-learn) on the AI4I records. The trained trees are exported to `resources/ml/failure_model.json` and run in PHP. A test checks PHP against Python on 300 saved rows.
- On a locked test set the model has higher recall and lower precision than the rules at the chosen flag rate. AI4I is synthetic, so the model mostly relearns the dataset's rules. Full numbers are in [docs/AI.md](docs/AI.md) and `resources/ml/model_card.json`.
- The tool `get_learned_estimate` also returns a straight-line projection of the chance a few readings ahead (check: `php artisan prism:forecast-check`, results in [docs/FORECAST.md](docs/FORECAST.md)).

## Sign in and the Judge Demo

- With `REQUIRE_LOGIN=false` (the default for a local copy), the dashboard is public and `/login` goes back to `/`. The live site runs with `REQUIRE_LOGIN=true`, so the dashboard and the `/api/` routes need a sign-in. `/ai`, `/faq`, `/privacy`, `/terms` and `/login` stay public.
- Judge Demo: open `/demo`. No account, password or email is involved. The demo is read-only. The override log and AI ratings return HTTP 403 and save nothing. For a demo session `/overrides` shows three made-up entries. "Exit Demo" ends the session.
- Judges receive no link. A person who asks for one gets an invite from the entrant, who types the address on `/admin/invites` (its own password). The link works once, expires after 7 days and lets the person choose a password of at least 12 characters.
- Logins are limited to 5 tries a minute. Details: [docs/ACCESS.md](docs/ACCESS.md).

## Ethical AI and accessibility

- The AI only explains, on request. Code sets the work order priority.
- Every AI answer carries an "AI-GENERATED" label and a reminder that a qualified technician decides.
- A rating (Helpful or Wrong) stores the rating, step, source, scenario, reading number and time. It stores no name, free text or IP address.
- `/ai` maps PRISM to the NIST AI Risk Management Framework. The mapping is mine. NIST has not reviewed PRISM.
- Accessibility: skip link, main landmark, visible focus outline, risk bands as words, labeled `role="meter"` stress bars, a table view of the chart data, status and alert regions, light and dark themes and reduced motion.
- The goal is WCAG 2.2 Level AA. No independent audit has been run. Dated checks and known gaps are in [docs/AI.md](docs/AI.md).

## Run locally

Requirements: PHP 8.4 or newer with the mbstring, xml, curl, zip and sqlite3 extensions (the set `scripts/run-tests-cloud.sh` installs), and Composer. No Node or npm step is needed. The pages load the CSS and JavaScript already committed in `public/css` and `public/js`.

1. `composer install`
2. `cp .env.example .env && php artisan key:generate`
3. `touch database/database.sqlite && php artisan migrate` (creates the override log and AI feedback tables)
4. Optional: set `OVERRIDE_LOG_PASSWORD` and `ANTHROPIC_API_KEY` in `.env`
5. Optional, to try the sign-in: set `REQUIRE_LOGIN=true` and `INVITE_ADMIN_PASSWORD` in `.env`. Keep `MAIL_MAILER=log`, then open `/admin/invites`, invite an address and copy the link from `storage/logs/laravel.log`. Or set `JUDGE_EMAIL` and `JUDGE_PASSWORD` and run `php artisan db:seed --class=JudgeSeeder`
6. `php artisan serve`
7. Open http://127.0.0.1:8000/
8. Run the tests: `php artisan test`

Style and static checks: `vendor/bin/pint --test`, then `vendor/bin/phpstan analyse --memory-limit=1G`.

Secret scan before each commit: `cp scripts/git-hooks/pre-commit .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit`. The hook blocks staged `.env` files, API keys, tokens and passwords, and prints only the file and line.

## Documentation

| File | Contents |
|---|---|
| [docs/ACCESS.md](docs/ACCESS.md) | Sign in, invites, Judge Demo and the checks to run on the live site |
| [docs/AI.md](docs/AI.md) | AI steps in full, ethical AI, accessibility and the learned estimate |
| [docs/SCORING.md](docs/SCORING.md) | The full backtest and the override log |
| [docs/EVIDENCE.md](docs/EVIDENCE.md) | The problem, sources, literature and the dataset |
| [docs/BUSINESS.md](docs/BUSINESS.md) | Goals, business value, cost to run the AI, break-even and lessons |
| [docs/QUALITY.md](docs/QUALITY.md) | Code style, static analysis and dependency checks |
| [docs/DEPLOY.md](docs/DEPLOY.md) | Deploying, HTTPS and environment properties |
| [docs/RECOVERY.md](docs/RECOVERY.md) | What to do if the server is replaced |
| [docs/EVAL.md](docs/EVAL.md), [docs/FORECAST.md](docs/FORECAST.md) | Recorded results of the investigator evaluation and the projection check |
| [docs/BRAND.md](docs/BRAND.md) | Brand rules, logo assets and color tokens |
| [SECURITY.md](SECURITY.md) | Security practices, browser headers and known limits |
| [ROADMAP.md](ROADMAP.md) | Contest dates and product ideas that are not built |

## Dataset and citation

PRISM takes its limits from, and backtests against, the AI4I 2020 Predictive Maintenance Dataset from the UCI Machine Learning Repository. It holds 10,000 records and 14 features and is synthetic. PRISM trains one model on it. More in [docs/EVIDENCE.md](docs/EVIDENCE.md).

Matzka, S. (2020). AI4I 2020 Predictive Maintenance Dataset [Dataset]. UCI Machine Learning Repository. https://doi.org/10.24432/C5HS5C

License: Creative Commons Attribution 4.0 International (CC BY 4.0). Source page: https://archive.ics.uci.edu/dataset/601/ai4i+2020+predictive+maintenance+dataset

James Moore: "CUA Busch School AI Vibe Coding Contest, Fall 2026"
