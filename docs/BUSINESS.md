# Business value, goals and lessons

Everything here is a goal, a plan or a lesson. Nothing here is a measured result unless it says so.

## What PRISM aims to deliver

- Less unplanned downtime, by catching risky operating conditions before they break production.
- Alerts a technician trusts.

These are goals. PRISM does not measure either one today. The app records no stops, no downtime and no confirmed faults. Besides judge accounts, it stores only the override log and the Helpful and Wrong ratings on AI answers. The dataset cannot prove dollar savings. A pilot would need to add the two measures in [ROADMAP.md](../ROADMAP.md).

Measured so far, on the AI4I backtest only: the High band holds 291 of 339 labeled failures (85.8%) in 6.5% of scored records, and 45.1% of High records failed, so most High alerts in that data did not. The backtest limits are in [SCORING.md](SCORING.md).

Dropped claim: an earlier draft aimed at fewer unneeded repairs and longer equipment life. I found no verified source for either, so they are not goals here.

## Business value and scale

Assumptions are labeled. Nothing here is a measured result.

- Who uses PRISM: the maintenance technician described in [EVIDENCE.md](EVIDENCE.md).
- Who would pay (assumption, not researched): the owner or maintenance manager of a small CNC shop.
- Pricing: no PRISM price is set and this page gives no revenue figure. For reference, maintenance work-order tools list these prices per user per month: MaintainX Essential $20 billed annually ($25 monthly) and Premium $65 ($75); UpKeep Essential $24 and Premium $55. MaintainX lists IoT sensor integrations under its Enterprise plan with custom pricing. I read these vendor pages on Oct 22, 2026. The pages show no effective date. I did not test whether these tools score failure risk from readings.
- What exists today: one simulated milling machine (M-01), one server and one SQLite file. All readings come from `app/Services/SensorSimulator.php`, and one set of limits lives in `config/prism.php`.

### Cost to run the AI

A press of "Explain this reading" makes the investigation call and then the work order call. Only the investigation call has been measured.

- Measured tokens: [EVAL.md](EVAL.md) (run Oct 22, 2026) reports 5,911 input and 1,377 output tokens per accepted answer.
- Price: Anthropic lists Claude Haiku 5.5, the default model in `config/services.php`, at $0.10 per million input tokens and $0.50 per million output tokens for prompts up to 100,000 tokens. I read the pricing page on Oct 23, 2026. The page shows no effective date.
- Arithmetic: 5,911 x $0.10 / 1,000,000 = $0.00059. 1,377 x $0.50 / 1,000,000 = $0.00069. The sum is $0.00128 per investigation, or about $1.28 per 1,000.
- Illustration with a made-up volume, not measured: 1,000 presses a month would cost about $1.28 in model fees.
- Not included: the work order call, which has not been measured. The pricing page does not say how thinking tokens are billed, so the figure may differ from a real bill. Good replies are cached for an hour, and a cached reply makes no model call.
- The Claude API is a separate paid service and is not part of the AWS Free Tier.

### Break-even check

A shop owner asks how many hours of unplanned downtime a tool must prevent to pay for itself.

Hours needed per year = annual tool cost / cost of one downtime hour for the shop.

- Annual tool cost: no PRISM price exists. For reference only, MaintainX Premium lists $65 per user per month, so $65 x 12 = $780 per year (vendor page read Oct 22, 2026).
- Cost of one downtime hour: the shop owner supplies this number. The ABB figure of about $125,000 per hour is a median for industrial businesses of all kinds, not machine shops, so it does not apply here.
- Illustration with made-up inputs, not sourced: at $500 per hour, $780 / $500 = 1.56 hours per year. At $2,000 per hour, $780 / $2,000 = 0.39 hours per year.

Limits of this check:
- Hours avoided are not measured. PRISM records no stops and no downtime (see the goals above). The formula says how much PRISM must prevent, not that it prevents it.
- False alarms cost time. In the AI4I backtest 45.1% of High records failed, so 100 - 45.1 = 54.9% did not. That is 54.9 / 45.1 = about 1.2 High records without a failure for each High record with one. Stop time spent on a false alarm counts against any saving.
- The price is a competitor's price, used as a reference. It is not a PRISM price.

### Path past one machine

None of this is built. The order is a plan, not a commitment. Each phase ends with a check.

1. Real readings. A technician enters readings from a real machine in place of the simulator (see [ROADMAP.md](../ROADMAP.md)). Ends when the README states how many real readings PRISM scored.
2. One set of limits per machine. Each machine gets its own score and limits. The vibration limits are demo values, since ISO 20816-3 does not list machine tools. Ends when every limit for a real machine names a source, such as the machine maker's manual.
3. A shared database in place of the single SQLite file. Rebuilding the server erases that file today (see [ACCESS.md](ACCESS.md)). Ends when a test rebuild that follows [RECOVERY.md](RECOVERY.md) loses no data.
4. A test on real data and a pilot. The backtest checks the rules against AI4I labels only. Record the two measures in [ROADMAP.md](../ROADMAP.md): unplanned stops per machine per month, and the share of High alerts followed by a confirmed fault. Ends when the README reports both for a stated period.

## Feedback from my last project and what changed

My last vibe coding project (a course planner) got this feedback: one large `system_prompt.txt` did the heavy lifting, with the database only storing users and classes. The judge asked for more dynamic use of AI, a few smaller prompts, and filtering in code so the model sees only what applies. The judge also noted AI times out fast on a small token window.

- One rule file became six check classes (`app/Services/Checks`) with every limit and its source in `config/prism.php`. `RiskScorer` only collects the results.
- AI work is split into two small calls. Each gets a short structured input built in PHP, not a rule dump.
- Every AI call has a timeout, a token cap, a cache and a built-in fallback, and runs on a button press, never inside the 1.2 second reading poll.

## Lessons from this build

What changed during this project, and what I took from each change.

- HTTPS. AWS CloudFront returned "Your account must be verified before you can add new CloudFront resources". I opened a support case on Oct 18, 2026 and it was still open on Oct 22. I moved to a free DuckDNS name with a Let's Encrypt certificate, live on Oct 21. Lesson: when a service outside my control blocks the work, I need a second route I control.
- Judge access. I first built an email invite so each judge sets a password. The faculty reviewer said on Oct 22 that judges must never create accounts or set passwords. I built the read-only Judge Demo instead (pull request #20). The invite flow stays built. PRISM sends no invite on its own, and the entrant sends one only when a person asks for a link. Lesson: ask the person who owns a rule before building around my reading of it.
- Checking the AI. The evaluation run ([EVAL.md](EVAL.md)) led to two fixes: the grounding check now reads a number with a thousands separator as one number (pull request #12), and the investigator answer gets more output room (pull request #13). Lesson: run the AI over the full set of scenarios, then fix what the code checks reject.
- Scope. On Oct 22 I stopped adding AI features, because each one adds more to explain and test. Lesson: finish and measure what exists before adding more.
