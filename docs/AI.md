# AI, ethics and accessibility

The README gives the short version. This page holds the detail.

## AI steps

The rules do the math. AI investigates and explains the result, only when a visitor presses "Explain this reading" and only when the band is Elevated or High.

- Call 1, Investigate (`app/Services/Ai/Investigator.php`): sends the score, the band, the scored drivers at 40 or above, the fact sentences the checks already wrote, and the derived metrics. Claude then calls up to six read-only tools (`app/Services/Ai/ToolBox.php`), in at most four rounds, and returns a likely cause, one or two physical checks and the names of the tools it used.
- The six tools: `get_check` (one check's stress and sentence), `get_trend` (a channel's slope over the current cycle), `time_to_limit` (a straight-line estimate in readings and minutes, simulated data only), `what_if` (scores a copy of the reading with one channel changed, and reports the learned chance of failure before and after), `get_learned_estimate` (the trained model's chance of failure, how often readings in the same range failed in AI4I, and a projection of the chance a few readings ahead) and `get_backtest` (AI4I failure counts by band). Arguments are fixed choices or numbers in a fixed range. No tool writes anything and no tool changes the real score.
- Checked in code: the answer must name only tools it called, and every number in its words must appear in the input or in a tool result (whole numbers may be rounded, minutes may be written as hours). An answer that fails is dropped.
- The what_if gate: every `what_if` result carries an `effect` (`lowers_score`, `no_change` or `raises_score`). Only a change that lowers the score is a tested fix. If the model tries to answer before any `what_if`, code sends it back once. The learned chance never decides whether a change is a fix: only the rule score does. When both chances are known, the tested fix sentence also shows how the learned chance moves. A value that did not lower the score is removed from the numbers the answer may use, so the answer cannot name it as a target. The prompt also tells the model to treat each limit as a failure zone and never advise a move toward it. Code cannot check the direction of every sentence, so a technician still decides.
- Tested fix: when a `what_if` lowers the score, code writes one sentence from the best result ("PRISM scored a copy of the reading with torque set to 40 Nm. The score falls from 72 to 60 (elevated)") and the page shows it under "Tested on a copy". The AI picks the value. The rules do the scoring, and the sentence is not AI text.
- Time estimates: `time_to_limit` is a straight-line extrapolation of simulated data. The prompt asks the AI to say so. If the cause quotes the estimate without the words "straight-line", code adds "This is a straight-line estimate on simulated data." The work order prompt bans time estimates in the steps.
- If the investigation fails, the older single Diagnose call runs (`app/Services/Ai/Diagnoser.php`), but only when the model answered and the answer failed a check. After a timeout or an API error PRISM goes straight to the built-in text, so the visitor does not wait for a second call. Set `PRISM_INVESTIGATOR=false` to use the single Diagnose call only.
- Call 2, Work order (`app/Services/Ai/WorkOrderStep.php`): sends the diagnosis from call 1. Gets back a title and steps. Code sets the priority from the band. The model does not.
- The score and the work order priority never depend on the AI. The server rebuilds the reading from the scenario and tick, so no visitor text reaches a prompt or a tool.
- Limits: the investigation stops after four rounds or 12 seconds, and each round asks for 1,000 output tokens plus 1,024 tokens of thinking headroom. The work order call asks for 260 plus 1,024 and has an 8 second timeout. A test checks the Diagnose input stays under 1,500 characters. The page shows the token counts the API reports, summed over the rounds.
- If the key is missing, the API is slow, the API errors, or the reply does not parse or fails the number check, the page shows the built-in explanation from the checks. Good replies are cached for an hour. Fallbacks are cached for a minute.
- The diagnosis route is rate limited to 10 requests a minute, because each press makes up to four model calls. The work order route is limited to 30 a minute.
- Tests (`tests/Feature/ToolBoxTest.php`, `tests/Feature/InvestigatorTest.php`) fake the API and cover a tool call then an answer, the round cap, a refused tool, an invented number, a tool the answer did not call, a timeout, no key and the off switch. `tests/Feature/EvalInvestigatorCommandTest.php` and `tests/Unit/EvalReportTest.php` cover the evaluation command and its report.

Set `ANTHROPIC_API_KEY` in `.env` (local) or as an Elastic Beanstalk environment property (live). `ANTHROPIC_MODEL`, `ANTHROPIC_TIMEOUT` and `PRISM_INVESTIGATOR` are optional. With no key, PRISM makes no AI call.

Evaluate the investigator: `php artisan prism:eval-investigator` runs the investigator on a fixed grid of simulated readings (one every 3 ticks of the 60 tick cycle, Elevated and High only, 60 runs at most) and prints how many answers passed the code checks, how many fell back and why, how often it called `what_if` and `get_learned_estimate`, how many accepted answers carry a tested fix, tokens per answer and time per run. `--dry-run` lists the readings without calling the API. `--write=docs/EVAL.md` saves the report. The command calls the live API, so it needs `ANTHROPIC_API_KEY` and uses tokens. It counts what happened and does not grade the advice. The recorded run (October 22, 2026, 38 simulated readings) is in [EVAL.md](EVAL.md). Results vary from run to run. Passing the checks does not show the advice is correct, and no technician graded the answers.

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
- Light and dark themes. PRISM follows the system setting, and a header switch overrides it (choice saved in the browser, `localStorage` key `prism-theme`). Colors are CSS tokens in `public/css/prism.css`. The chart reads its colors from the same tokens. Light theme contrast ratios are in [BRAND.md](BRAND.md).
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
- Projection: `get_learned_estimate` also returns the learned chance 10 readings ahead (5 hours, at 30 minutes a reading) and the first reading ahead where the chance reaches 20% and 50%. `app/Services/Ml/Forecaster.php` projects the five model inputs forward in a straight line from the last 10 readings of the current cycle, then scores each projected reading with the trained model. It sees no reading after the current one. The AI is told to call it a straight-line projection on simulated data, and code adds that sentence when the cause quotes a projected crossing. When the cause says the model disagrees with the band and the model projects a crossing, code adds "The learned model projects 20% in about N readings." so the cause does not read as if the model sees no problem. The projection stops at the end of the 60 reading cycle and does not appear with fewer than 3 readings in the cycle.
- Projection check: `php artisan prism:forecast-check` (no API key needed) projects from 76 start readings across the four scenarios and compares the first projected crossing of 20% with the first real simulated crossing. The table is in [FORECAST.md](FORECAST.md).
- The simulator scenarios climb in straight lines, so a straight-line projection suits them. The check does not show how the projection performs on a real machine, and the trained model scores both sides, so it checks the projection of the inputs, not the model.
- Full numbers: `resources/ml/model_card.json`. Retrain with `php artisan prism:export-features data/ai4i2020.csv --out=ml/features.csv` and `python3 ml/train_model.py`.
