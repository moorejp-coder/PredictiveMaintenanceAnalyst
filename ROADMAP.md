# PRISM contest roadmap

Last updated: Nov 16, 2026. This file tracks contest deliverables and dates, then lists product ideas that are not built.

## Dates
- Mon Nov 16, 11:59 p.m. ET: code freeze. Judges evaluate the last commit on main before this time. No redeploy to AWS after it.
- Final submission email: repo, live link, one-click demo login, video under 5 minutes and a two-sentence summary.

## Done
- [x] Branch protection on main: a pull request is required, the PHP 8.4, PHP 8.5 and Code style (Pint) checks are required, and deletion and force pushes are blocked.
- [x] Judge access: the Judge Demo at https://prismcua.duckdns.org/demo is read-only, needs no login and sends no emails (pull request #20).
- [x] Access link and demo video link at the top of the README (pull request #68).
- [x] README checked against the contest rubric, with screenshots added (pull request #63).
- [x] One-page summary (problem, solution, AI usage, key learnings).

## To do
- [ ] Send the final submission email.
- [ ] Demo script for the 5 to 10 minute presentation, if the contest still requires one.

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
