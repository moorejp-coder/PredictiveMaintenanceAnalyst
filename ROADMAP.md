# PRISM contest roadmap

Last updated: Oct 22, 2026. Ideas for new product features that are not built yet are in the Roadmap section of the [README](README.md). This file tracks contest deliverables and dates.

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
- The README matches what the app does. It claims nothing the app does not do or measure.
- Deploy zips store files at 644 and scripts at 755, and include the branding files.
