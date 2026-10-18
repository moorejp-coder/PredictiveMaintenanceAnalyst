Live site: http://prism-cua-env.eba-fsf9rffw.us-east-2.elasticbeanstalk.com/
Local site: http://127.0.0.1:8000/ (run `php artisan serve` in the project folder first)

# PRISM: Predictive Real-time Infrastructure Sensor Monitoring

PRISM is a predictive maintenance dashboard for industrial milling machines. It uses machine-learning models trained on the AI4I 2020 dataset to flag failure risk under current operating conditions. Maintenance teams enter equipment sensor readings, and PRISM classifies which failure mode the readings point to, with a confidence score.

## MVP

PRISM is for the maintenance technician at a small machine shop who runs CNC milling machines and has no data scientist. The technician cannot tell from raw sensor readings which failure is developing, so the shop repairs healthy machines or loses production to unplanned breakdowns. The technician enters five readings (air temperature, process temperature, rotational speed, torque, tool wear) and PRISM shows the predicted failure mode with a confidence score. PRISM sends an alert when failure risk crosses a threshold. A machine learning classifier trained on the AI4I 2020 dataset does the classification. The model weighs all readings together and names one or more of four failure modes: tool wear, heat dissipation, power, and overstrain. A single record carries more than one failure mode at once, so PRISM treats the task as multi-label. AI4I is a snapshot of operating conditions, not a time series. PRISM flags failure risk under current conditions and does not forecast a failure date.

Safety disclaimer: PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern.

## Dashboard (first version)

The home page is one dashboard for one milling machine (M-01) running on simulated sensor readings. It shows a failure-risk score from 0 to 100, a plain-English reason, the stress on each check, seven simulated sensor readings (process and air temperature, vibration, machine run hours, rotational speed, torque, tool wear) and the last 30 readings. Four demo situations (worn tool, overheating, overload, healthy) show how the score reacts.

- The score is rule-based. It uses the AI4I failure limits, which reproduce the heat, power and overstrain labels exactly. A trained model can replace `app/Services/RiskScorer.php` later.
- Readings come from `app/Services/SensorSimulator.php`. They are repeatable, so the app needs no database.
- AI4I has no vibration or machine run hours column. Those two readings are simulated, and their checks are not backtested. The vibration limits (1.4, 2.8 and 4.5 mm/s RMS) are the ISO 10816-3 Group 2 zone boundaries. The 8,000 hour overhaul interval is a placeholder.
- Run hours alone never score above 50 (Elevated). Tool wear alone never scores above 60.
- Backtest of the four AI4I checks (tool wear, heat, power, overstrain) on the 10,000 AI4I records (18 random-failure records left out): 0 of 7,448 Low records failed, 40 of 1,889 Elevated (2.1%), 290 of 645 High (45.0%).

## Run locally

1. `composer install`
2. `cp .env.example .env && php artisan key:generate`
3. `php artisan serve`
4. Open http://127.0.0.1:8000/

## What PRISM aims to deliver

- Fewer unneeded repairs, by avoiding preventive maintenance on healthy equipment.
- Less unplanned downtime, by catching risky operating conditions before they break production.
- Longer equipment life, through timely intervention.

These are goals. The dataset cannot prove dollar savings.

## Dataset

PRISM trains and tests on the AI4I 2020 Predictive Maintenance Dataset from the UCI Machine Learning Repository. It holds 10,000 records and 14 features.

- Synthetic data: AI4I was generated to mirror real milling data. It is not pulled from a factory floor.
- Rare failures: failures make up roughly 3 percent of records. A model that predicts "no failure" every time scores about 97 percent accuracy and has no value. PRISM reports precision and recall for each failure class, not accuracy.
- Multiple modes: one record carries more than one failure mode at once. PRISM treats failure classification as multi-label.
- Random failure is a known limit: the dataset's random failures occur with a 0.1 percent probability independent of every parameter (UCI), so no sensor reading predicts them. PRISM does not classify random failure.

## Citation

Matzka, S. (2020). AI4I 2020 Predictive Maintenance Dataset [Dataset]. UCI Machine Learning Repository. https://doi.org/10.24432/C5HS5C

License: Creative Commons Attribution 4.0 International (CC BY 4.0). Source page: https://archive.ics.uci.edu/dataset/601/ai4i+2020+predictive+maintenance+dataset

James Moore: "CUA Busch School AI Vibe Coding Contest, Fall 2026"
