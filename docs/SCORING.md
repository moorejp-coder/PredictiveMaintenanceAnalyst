# Scoring and override log

The README gives the short version. This page holds the full backtest and the override log.

## How the score works

- The score is rule-based. A learned estimate sits beside it (see [AI.md](AI.md)). Each failure check is its own class in `app/Services/Checks`. Every limit and its source lives in `config/prism.php`. `app/Services/RiskScorer.php` only collects the results.
- Per the code comments, the AI4I 2020 limits for heat, power and overstrain reproduce those labels in the dataset. Random failures cannot be predicted from any reading, so PRISM does not score them.
- Readings come from `app/Services/SensorSimulator.php`. The same scenario and reading number always give the same reading.
- AI4I has no vibration or machine run hours column. Those two readings are simulated, and their checks are not backtested. The vibration check uses two limits, 1.4 mm/s RMS (normal below) and 4.5 mm/s RMS (damage likely above). They match Group 2 rigid-foundation zone boundaries listed for ISO 20816-3, which replaced ISO 10816-3. I checked them in secondary sources, not in the standard's text. That standard covers industrial machines above 15 kW and does not list machine tools, so these are demo values for a milling machine. The 8,000 hour overhaul interval is a placeholder.
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
- The review page `/overrides` lists the newest 200 entries. Only a signed-in admin account opens it. Guests are sent to the sign-in page and other accounts get 403. With `REQUIRE_LOGIN` off, the page returns 404.
- The write route is limited to 10 requests a minute.
- Limits: the name is whatever the operator types and is not tied to any sign-in, so it is not verified. The log sends no notification to anyone. The log is a SQLite file on the host, so a replaced or rebuilt server erases it.
