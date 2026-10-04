# PRISM: Predictive Real-time Infrastructure Sensor Monitoring

PRISM is a predictive maintenance dashboard for industrial milling machines. It uses machine-learning models trained on the AI4I 2020 dataset to flag failure risk under current operating conditions. Maintenance teams enter equipment sensor readings, and PRISM classifies which failure mode the readings point to, with a confidence score.

## MVP

PRISM is for the maintenance technician at a small machine shop who runs CNC milling machines and has no data scientist. The technician cannot tell from raw sensor readings which failure is developing, so the shop repairs healthy machines or loses production to unplanned breakdowns. The technician enters five readings (air temperature, process temperature, rotational speed, torque, tool wear) and PRISM shows the predicted failure mode with a confidence score. PRISM sends an alert when failure risk crosses a threshold. A machine learning classifier trained on the AI4I 2020 dataset does the classification. The model weighs all readings together and names one or more of four failure modes: tool wear, heat dissipation, power, and overstrain. A single record carries more than one failure mode at once, so PRISM treats the task as multi-label. AI4I is a snapshot of operating conditions, not a time series. PRISM flags failure risk under current conditions and does not forecast a failure date.

Safety disclaimer: PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern.

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
