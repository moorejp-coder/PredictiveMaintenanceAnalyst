# Investigator evaluation

Run on October 22, 2026 with model `claude-haiku-5-5`. Scenarios: worn_tool, overheating, overload, healthy. One reading every 3 ticks. Low-band readings are skipped, because the AI does not run on them.

Reproduce with `php artisan prism:eval-investigator`. Results vary from run to run.

| Measure | Result |
|---|---|
| Readings investigated | 38 |
| Answers accepted by the code checks | 35 of 38 (92.1%) |
| Fell back to the built-in text | 3 of 38 (bad_reply 1, ungrounded 2) |
| Runs that called what_if | 38 of 38 (100.0%) |
| Runs that called get_learned_estimate | 38 of 38 (100.0%) |
| Accepted answers with a tested fix | 28 of 35 (80.0%) |
| Tool calls per run | 3 |
| Tokens per accepted answer (input, output) | 5292, 877 |
| Time per run, median and 95th percentile | 5.8 s, 9.6 s |

Limits:
- The readings are simulated. The run shows how the investigator behaves, not how it performs on a real machine.
- "Accepted" means the answer passed the code checks: valid JSON, only tools it called, and only numbers it was given. A passed check does not prove the advice is correct. No technician graded the answers.
- Each reading ran once. Nearby ticks are not independent readings of different machines.
