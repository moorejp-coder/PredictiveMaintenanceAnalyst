# Forecast check

Run on October 22, 2026. Scenarios: worn_tool, overheating, overload, healthy. One start reading every 3 ticks of the first 60 tick cycle. Each start projects ahead from its last 10 readings only, then the projection is compared with the readings that follow.

Reproduce with `php artisan prism:forecast-check`. The check is deterministic and makes no API call.

| Chance threshold | Start readings below it | Projected and real crossing both found | Median error (readings) | Within 3 readings | Real crossing missed | Projected crossing, no real one | Neither |
|---|---|---|---|---|---|---|---|
| 20% | 53 | 25 | 2 | 18 of 25 | 5 | 2 | 21 |
| 50% | 53 | 25 | 2 | 18 of 25 | 5 | 2 | 21 |

There were 76 start readings in all. The rows above count the start readings that began below the threshold.

The learned chance rises steeply near a limit. Of 27 projected crossings of the lower threshold, 26 reached the higher threshold at the same reading. Of 30 real crossings, 30 did. This is why the two rows can match.

Limits:
- The simulator scenarios climb in straight lines, so a straight-line projection suits them. The result describes the simulator, not a real machine.
- The trained model scores both sides, so this checks the projection of the inputs, not the model.
- Start readings from the same scenario overlap and are not independent cases.
