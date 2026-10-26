# PRISM contest roadmap

Last updated: Oct 22, 2026. This file tracks contest deliverables and dates, then lists product ideas that are not built.

## Dates
- Fri Oct 30: reply to the faculty reviewer with the branch protection confirmation, the judge access option and a test link.
- Fri Nov 20: access link and a 2 to 3 minute screen recording at the top of the README.
- Thu Nov 26: contest final deadline.

## Done
- [x] Branch protection on main: a pull request is required, the PHP 8.4, PHP 8.5 and Code style (Pint) checks are required, and deletion and force pushes are blocked.
- [x] Judge access: the Judge Demo at https://prismcua.duckdns.org/demo is read-only, needs no login and sends no emails (pull request #20).
- [x] Access link at the top of the README.

## To do
- [ ] Reply to the faculty reviewer (by Oct 30).
- [ ] Screen recording, 2 to 3 minutes (by Nov 20), added at the top of the README through a pull request.
  - Open the Judge Demo and show the dashboard and the four situations.
  - Press "Explain this reading" and show the AI-GENERATED label, the tool calls and the work order.
  - State what PRISM does not do: the data is simulated, the score is rule-based and no technician was interviewed.
- [ ] Demo script for the 5 to 10 minute presentation, written in the last week before Nov 26.
- [ ] One-page summary for the final submission (problem, solution, AI usage, key learnings), written in the last week before Nov 26. It is the last task before submitting.

## Rules for every change
- Work on a branch, open a pull request with a short what-and-why description, wait for green checks, then merge.
- The README says only what the app does. Goals, plans and dated checks of the live site go in `docs/`.
- Deploy zips store files at 644 and scripts at 755, and include the branding files.

## Product ideas not built

These items are not in the app today. They are goals, not commitments.

- Letting the learned estimate drive the score or the action. Today the rules decide. The estimate needs real machine data first, because AI4I is synthetic.
- Entry of real readings by a technician. Every reading today is simulated.
- A live sensor feed.
- Testing PRISM with a maintenance technician at a machine shop. No user has tried it yet.
- Measuring results in a pilot: unplanned stops per machine per month, and the share of High alerts followed by a confirmed fault. PRISM records neither today.
- Automated alerts. PRISM today shows its instruction on screen and sends nothing.
- A predicted failure date. AI4I is a snapshot of operating conditions, not a time series, so PRISM flags risk under current conditions and does not forecast a date. The projection described in [docs/AI.md](docs/AI.md) looks a few readings ahead on simulated trends and is not a failure date.
