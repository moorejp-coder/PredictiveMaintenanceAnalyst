# Evidence and sources

These sources support the problem. None tests PRISM.

## The problem

PRISM is for the maintenance technician at a small machine shop who runs CNC milling machines and has no data scientist. On this page, small means fewer than 100 employees. That is my assumption, chosen because 44 of the 71 firms in the NIST survey below were that size. Raw sensor numbers do not say which failure is developing. PRISM turns the numbers into one score, a reason a technician reads in a few seconds, and an instruction.

How maintenance is done without a tool like PRISM, according to the sources below:
- 21% of the industrial businesses ABB surveyed still run equipment until it fails.
- Neugebauer and Fischer (2009) report that time-based maintenance plans are often not followed consistently.

What unplanned stops cost, according to the sources below:
- ABB: close to $125,000 per hour for the typical industrial business (median, self-reported, not machine shops).
- NIST: the half of respondents with the highest reactive maintenance spending averaged 10.4% unplanned downtime as a share of planned production time. The other half averaged 4.9%. The authors call this anecdotal.

Reading these sources, I see a gap between running to failure and following a calendar: a technician with no reading-based reason to stop a machine. That reading is my interpretation. No source states it.

## Evidence behind the problem

Three sources support the problem. None tests PRISM.

- ABB, Value of Reliability survey, press release of October 11, 2023. ABB surveyed 3,215 plant maintenance decision-makers worldwide. Unplanned downtime cost close to $125,000 per hour for the typical business. ABB used the median, and the figures are self-reported. More than two-thirds of the industrial businesses surveyed had unplanned outages at least once a month. The industries listed include energy, chemicals, metals and food and beverage. The release does not name machine shops or break results out by shop size. ABB also reports that 21 percent of businesses surveyed still rely on run-to-fail maintenance. Source: https://new.abb.com/news/detail/107660/abb-survey-reveals-unplanned-downtime-costs-125000-per-hour. Dan Zeiger quotes the figure in an ISM article dated August 27, 2024.
- Adu-Amankwa, Attia, Janardhanan and Patel (2019), "A predictive maintenance cost model for CNC SMEs in the era of industry 4.0," The International Journal of Advanced Manufacturing Technology 104, 3567 to 3587. The authors found no earlier study with a predictive maintenance cost model for small and medium CNC shops. Their model predicts savings of 22,804 to 48,585 British pounds for setups of 1 to 50 machines. These are model predictions, not measured results. The page I read does not state the time period. Source: https://doi.org/10.1007/s00170-019-04094-2.
- Thomas and Weiss (NIST), International Journal of Prognostics and Health Management 12(1). The survey has 71 usable responses, and 44 come from establishments with fewer than 100 employees. The authors estimate annual U.S. maintenance costs and losses at $222.0 billion on average. The half of respondents with the highest reactive maintenance spending averaged 10.4% unplanned downtime as a share of planned production time. The other half averaged 4.9%, which is 52.7% lower (Table 6). The paper does not report group sizes. Firms relying more on predictive maintenance spent 212.3% more on direct maintenance relative to shipments. The authors treat the results as anecdotal evidence. The paper does not report results for small firms or machine shops alone, and it cites earlier work saying small firms might rely more on reactive maintenance. Source: https://papers.phmsociety.org/index.php/ijphm/article/download/2883/1748
- No technician or shop owner was interviewed for PRISM. The user need rests on published sources. The Thomas and Weiss NIST survey collected responses from 71 manufacturing establishments, 44 of them with fewer than 100 employees. It reports lower unplanned downtime among firms that rely less on reactive maintenance. The authors treat the results as anecdotal evidence. These sources show the problem exists in manufacturing. They do not show that a small shop would use PRISM.

What the evidence does not show: PRISM's own savings. No shop has used PRISM, and AI4I is synthetic data. The value stays a goal (see [BUSINESS.md](BUSINESS.md)).

## What the literature monitors

Four published sources describe what condition monitoring covers on machine tools. They describe monitoring in general. None reports what technicians say they check, and none studies small shops. I read each at its original page.

- Neugebauer and Fischer (2009), "Condition-Based Preventive Maintenance of Machine Tools," Journal of Machine Engineering 9(3), Fraunhofer Institute. For the main spindle it lists bearing temperature, vibration, motor current and active power, and it lists operating hours as a general input. It does not list tool wear. It says time-based plans are often not followed consistently and argues for condition-based maintenance.
- Rastegari and Archenti (2017), "Condition Based Maintenance of Machine Tools: Vibration Monitoring of Spindle Units," IEEE Reliability and Maintainability Symposium. It says spindle bearing wear is common and vibration analysis diagnoses it. The page I read mentions warning limits but gives no numeric limits and names no standard.
- Korn (2010), "Taking Spindle Health Seriously," Production Machining magazine. A shop sets alarm limits on spindle temperature, and warning and alarm limits on vibration by machine type. The article does not mention power or load.
- Zhou and Xue (2018), "Review of tool condition monitoring methods in milling processes," The International Journal of Advanced Manufacturing Technology 96, 2509 to 2523. The abstract says accurate tool condition monitoring is essential for fully automated milling but hard to implement. The page I read names no signals.

| PRISM check | Support in these sources | Where PRISM's rule comes from |
|---|---|---|
| Vibration | Strong. Neugebauer and Fischer, Rastegari and Archenti, and Korn all monitor vibration. | No source gives numeric limits. PRISM uses ISO 20816-3 zone boundaries as demo values. |
| Heat | Partial. Neugebauer and Fischer (bearing temperature) and Korn (spindle temperature limits). They monitor the spindle bearing. | The AI4I labels. |
| Power draw | Partial. Neugebauer and Fischer list motor current and active power. Korn does not mention power or load. | The AI4I labels. |
| Tool wear | Weak. Zhou and Xue show tool condition monitoring is an established topic in milling research. They support the topic, not a limit. Neugebauer and Fischer do not list tool wear. | The AI4I data. |
| Overstrain | Weak. Neugebauer and Fischer list torque for axle drives. They do not describe overstrain as PRISM defines it. | The AI4I labels. |
| Run hours | Partial. Neugebauer and Fischer list operating hours as an input and warn that time-based plans are often not followed. | PRISM caps run hours alone at 50, so hours alone never give a High score. The 8,000 hour interval is a placeholder. |

These parts of PRISM are my own choices, and none of the four sources supports them: the 0 to 100 score as the highest of six checks, the Elevated (40 and above) and High (70 and above) bands, and the simulated readings.

Limits: the sources are from 2009 to 2018. For Rastegari and Archenti and for Zhou and Xue I read the abstract page, not the full paper. I found no machine builder guide, and I could not open some pages I tried. Tool wear support rests on one review's abstract and reference titles.

## Dataset

PRISM takes its limits from, and backtests against, the AI4I 2020 Predictive Maintenance Dataset from the UCI Machine Learning Repository. It holds 10,000 records and 14 features. PRISM trains one model on it (see [AI.md](AI.md)).

- Synthetic data: AI4I was generated to mirror real milling data. It is not pulled from a factory floor.
- Rare failures: failures make up roughly 3 percent of records. A rule or model that predicts "no failure" every time scores about 97 percent accuracy and has no value. The backtest above reports how often failures fall in each band, not accuracy.
- Multiple modes: one record carries more than one failure mode at once.
- Random failure is a known limit: the dataset's random failures occur with a 0.1 percent probability independent of every parameter (UCI), so no sensor reading predicts them. PRISM does not score random failure.
