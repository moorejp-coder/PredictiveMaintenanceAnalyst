#!/usr/bin/env python3
"""Train PRISM's learned failure estimate on the AI4I 2020 dataset.

Run from the project folder:

    php artisan prism:export-features data/ai4i2020.csv --out=ml/features.csv
    python3 ml/train_model.py

Needs Python 3.10+ with numpy, pandas and scikit-learn (see ml/requirements.txt).

What it does, in this order:
 1. Reads ml/features.csv, which holds the raw readings, the failure labels, PRISM's
    rule score and the stress of each AI4I check. It drops the 18 records whose only
    failure is random failure, as `php artisan prism:backtest` does.
 2. Locks a 30% test set (stratified, seed 42). Nothing below uses it until step 5.
 3. Picks a gradient boosting size by 5-fold cross-validation on the other 70%.
 4. Fits on that 70%.
 5. Scores the locked 30% once, next to PRISM's rule score and four simpler models.
 6. Measures how often each estimate range failed, using out-of-fold predictions on all rows.
 7. Fits the shipped model on all rows and writes it, with its model card and a parity
    fixture for the PHP tests, to resources/ml/ and tests/fixtures/.

The model and the rules see the same data. The rule limits come from the AI4I documentation,
not from this fit. AI4I is synthetic, so the results describe the dataset, not a real machine.
"""
import json
import math
import sys
from datetime import date
from pathlib import Path

import numpy as np
import pandas as pd
import sklearn
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import average_precision_score, brier_score_loss, precision_score, recall_score, roc_auc_score
from sklearn.model_selection import RepeatedStratifiedKFold, StratifiedKFold, train_test_split
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler
from sklearn.tree import DecisionTreeClassifier

ROOT = Path(__file__).resolve().parent.parent
FEATURES_CSV = ROOT / "ml" / "features.csv"
MODEL_JSON = ROOT / "resources" / "ml" / "failure_model.json"
CARD_JSON = ROOT / "resources" / "ml" / "model_card.json"
PARITY_JSON = ROOT / "tests" / "fixtures" / "model_parity.json"

# The order the PHP code builds the feature vector in. Keep both in step.
FEATURES = ["air", "proc", "rpm", "torque", "wear", "typeM", "typeH",
            "dT", "power_w", "wear_x_torque",
            "s_tool_wear", "s_heat", "s_power", "s_overstrain"]
STRESS = ["s_tool_wear", "s_heat", "s_power", "s_overstrain"]
SEED_SPLIT = 42
HIGH_FLAG_SHARE = 645 / 9982  # share of records PRISM's High band flags (README backtest)
CAL_EDGES = [0.0, 0.01, 0.05, 0.20, 0.50, 1.0001]


def load():
    df = pd.read_csv(FEATURES_CSV)
    only_random = (df.RNF == 1) & (df[["TWF", "HDF", "PWF", "OSF"]].sum(axis=1) == 0)
    return df[~only_random].reset_index(drop=True)


def engineered(d):
    X = pd.DataFrame({
        "air": d.air, "proc": d.proc, "rpm": d.rpm, "torque": d.torque, "wear": d.wear,
        "typeM": (d.Type == "M").astype(int), "typeH": (d.Type == "H").astype(int),
    })
    X["dT"] = d.proc - d.air
    X["power_w"] = d.torque * d.rpm * 2 * math.pi / 60
    X["wear_x_torque"] = d.wear * d.torque
    for s in STRESS:
        X[s] = d[s]
    return X[FEATURES]


def booster(n_iter, depth):
    return HistGradientBoostingClassifier(max_depth=depth, learning_rate=0.08, max_iter=n_iter, random_state=0)


def flag_metrics(y, score, thr):
    flag = (score >= thr).astype(int)
    return {"precision": precision_score(y, flag, zero_division=0), "recall": recall_score(y, flag),
            "flagged": float(flag.mean())}


def rank_metrics(y, score):
    return {"pr_auc": average_precision_score(y, score), "roc_auc": roc_auc_score(y, score)}


def main():
    if not FEATURES_CSV.exists():
        sys.exit(f"Missing {FEATURES_CSV}. Run: php artisan prism:export-features data/ai4i2020.csv --out=ml/features.csv")
    df = load()
    y_all = df.fail.values
    print(f"rows {len(df)}, failures {int(y_all.sum())}")

    # 2. locked test set
    tr_i, te_i = train_test_split(np.arange(len(df)), test_size=0.30, stratify=y_all, random_state=SEED_SPLIT)
    tr, te = df.iloc[tr_i], df.iloc[te_i]
    Xtr, Xte = engineered(tr), engineered(te)

    # 3. choose a size on the training rows only
    grid = [(n, d) for n in (100, 200, 300) for d in (3, 4)]
    cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=0)
    grid_ap = {}
    for n, d in grid:
        aps = []
        for a, b in cv.split(Xtr, tr.fail):
            m = booster(n, d).fit(Xtr.iloc[a], tr.fail.iloc[a])
            aps.append(average_precision_score(tr.fail.iloc[b], m.predict_proba(Xtr.iloc[b])[:, 1]))
        grid_ap[(n, d)] = float(np.mean(aps))
        print(f"  size iter={n} depth={d}: cv PR-AUC {grid_ap[(n, d)]:.4f}")
    best = max(grid_ap.values())
    # smallest model within 0.005 of the best, so the shipped file stays small
    chosen = min((k for k, v in grid_ap.items() if v >= best - 0.005), key=lambda k: (k[0] * (2 ** k[1]), k))
    print("chosen size:", chosen)

    # 4. fit on the 70%
    model70 = booster(*chosen).fit(Xtr, tr.fail)
    s_tr = model70.predict_proba(Xtr)[:, 1]
    thr = float(np.quantile(s_tr, 1 - HIGH_FLAG_SHARE))   # set on training rows only
    s_te = model70.predict_proba(Xte)[:, 1]

    # 5. locked test, once
    y = te.fail.values
    rows = {}
    rows["PRISM rules, High band"] = {**rank_metrics(y, te.rule_score.values), **flag_metrics(y, te.rule_score.values, 70)}
    rows["PRISM rules, Elevated or High"] = {**rank_metrics(y, te.rule_score.values), **flag_metrics(y, te.rule_score.values, 40)}
    rows["Gradient boosting (shipped type)"] = {**rank_metrics(y, s_te), **flag_metrics(y, s_te, thr), "brier": brier_score_loss(y, s_te)}
    others = {
        "Logistic regression, raw inputs": (["air", "proc", "rpm", "torque", "wear", "typeM", "typeH"], lambda: make_pipeline(StandardScaler(), LogisticRegression(max_iter=3000, class_weight="balanced"))),
        "Logistic regression, engineered inputs": (FEATURES[:10], lambda: make_pipeline(StandardScaler(), LogisticRegression(max_iter=3000, class_weight="balanced"))),
        "Decision tree, depth 4": (FEATURES, lambda: DecisionTreeClassifier(max_depth=4, min_samples_leaf=20, class_weight="balanced", random_state=0)),
        "Decision tree, depth 6": (FEATURES, lambda: DecisionTreeClassifier(max_depth=6, min_samples_leaf=20, class_weight="balanced", random_state=0)),
    }
    for name, (cols, mk) in others.items():
        m = mk().fit(Xtr[cols], tr.fail)
        a = m.predict_proba(Xtr[cols])[:, 1]
        o_thr = float(np.quantile(a, 1 - HIGH_FLAG_SHARE))
        s = m.predict_proba(Xte[cols])[:, 1]
        rows[name] = {**rank_metrics(y, s), **flag_metrics(y, s, o_thr)}

    # paired bootstrap of the PR-AUC gap on the locked test rows
    rng = np.random.default_rng(0)
    gaps = []
    for _ in range(2000):
        idx = rng.integers(0, len(y), len(y))
        if y[idx].sum() == 0:
            continue
        gaps.append(average_precision_score(y[idx], s_te[idx]) - average_precision_score(y[idx], te.rule_score.values[idx]))
    gap_ci = [float(np.percentile(gaps, 2.5)), float(np.percentile(gaps, 97.5))]
    print("locked test:")
    print(pd.DataFrame(rows).T[["pr_auc", "roc_auc", "precision", "recall", "flagged"]].round(3).to_string())
    print("PR-AUC gap boosting minus rules, 95% bootstrap interval:", [round(g, 3) for g in gap_ci])

    # 6. out-of-fold calibration on all rows, and a repeated-CV comparison with the rules
    X_all = engineered(df)
    oof = np.zeros(len(df))
    oof_n = np.zeros(len(df))
    cv_gap, cv_ap_m, cv_ap_r, cv_rec_m, cv_rec_r, cv_prec_m, cv_prec_r = [], [], [], [], [], [], []
    for a, b in RepeatedStratifiedKFold(n_splits=5, n_repeats=3, random_state=7).split(X_all, y_all):
        m = booster(*chosen).fit(X_all.iloc[a], y_all[a])
        p = m.predict_proba(X_all.iloc[b])[:, 1]
        oof[b] += p
        oof_n[b] += 1
        t = float(np.quantile(m.predict_proba(X_all.iloc[a])[:, 1], 1 - HIGH_FLAG_SHARE))
        ap_m = average_precision_score(y_all[b], p)
        ap_r = average_precision_score(y_all[b], df.rule_score.values[b])
        cv_ap_m.append(ap_m); cv_ap_r.append(ap_r); cv_gap.append(ap_m - ap_r)
        fm = flag_metrics(y_all[b], p, t); fr = flag_metrics(y_all[b], df.rule_score.values[b], 70)
        cv_rec_m.append(fm["recall"]); cv_rec_r.append(fr["recall"]); cv_prec_m.append(fm["precision"]); cv_prec_r.append(fr["precision"])
    oof /= oof_n
    cal = []
    for lo, hi in zip(CAL_EDGES[:-1], CAL_EDGES[1:]):
        sel = (oof >= lo) & (oof < hi)
        cal.append({"from": lo, "to": min(hi, 1.0), "records": int(sel.sum()), "failed": int(y_all[sel].sum()),
                    "rate": float(y_all[sel].mean()) if sel.sum() else None})
    print("calibration (out-of-fold):")
    for c in cal:
        print("  ", c)

    # 7. fit on all rows and export
    final = booster(*chosen).fit(X_all, y_all)
    pred = final._predictors
    trees = []
    for it in pred:
        nodes = it[0].nodes
        trees.append({
            "f": [int(v) if not leaf else -1 for v, leaf in zip(nodes["feature_idx"], nodes["is_leaf"])],
            "t": [float(v) for v in nodes["num_threshold"]],
            "l": [int(v) for v in nodes["left"]],
            "r": [int(v) for v in nodes["right"]],
            "v": [float(v) for v in nodes["value"]],
        })
    baseline = float(np.ravel(final._baseline_prediction)[0])
    MODEL_JSON.parent.mkdir(parents=True, exist_ok=True)
    MODEL_JSON.write_text(json.dumps({"version": 1, "features": FEATURES, "baseline": baseline, "trees": trees}, separators=(",", ":")))

    # check the exported trees reproduce scikit-learn before anything else uses them
    def run_json(x_row):
        raw = baseline
        for t in trees:
            i = 0
            while t["f"][i] != -1:
                i = t["l"][i] if x_row[t["f"][i]] <= t["t"][i] else t["r"][i]
            raw += t["v"][i]
        return 1 / (1 + math.exp(-raw))
    chk = X_all.sample(500, random_state=3)
    worst = max(abs(run_json(r.values) - p) for (_, r), p in zip(chk.iterrows(), final.predict_proba(chk)[:, 1]))
    print("largest gap between the exported trees and scikit-learn on 500 rows:", worst)
    assert worst < 1e-6, "export does not match scikit-learn"

    # parity fixture: raw inputs plus the probability the PHP code must reproduce
    fx = df.sample(300, random_state=1)
    fixture = [{"type": r.Type, "air": r.air, "proc": r.proc, "rpm": int(r.rpm), "torque": r.torque, "wear": int(r.wear),
                "expected": round(float(p), 9)}
               for (_, r), p in zip(fx.iterrows(), final.predict_proba(engineered(fx))[:, 1])]
    PARITY_JSON.parent.mkdir(parents=True, exist_ok=True)
    PARITY_JSON.write_text(json.dumps(fixture, separators=(",", ":")))

    card = {
        "trained": date.today().isoformat(),
        "library": f"scikit-learn {sklearn.__version__}",
        "algorithm": "Gradient boosted decision trees (HistGradientBoostingClassifier)",
        "trees": chosen[0], "tree_depth": chosen[1],
        "dataset": {"file": "AI4I 2020", "records_used": int(len(df)), "failures": int(y_all.sum()),
                    "left_out": "18 records whose only failure is random failure"},
        "inputs": FEATURES,
        "locked_test": {"records": int(len(te)), "failures": int(y.sum()), "seed": SEED_SPLIT,
                        "flag_share": round(HIGH_FLAG_SHARE, 4),
                        "results": {k: {m: (round(float(v), 4) if v is not None else None) for m, v in r.items()} for k, r in rows.items()},
                        "pr_auc_gap_boosting_minus_rules_95pct_interval": [round(g, 3) for g in gap_ci]},
        "cross_validation": {"folds": 15, "pr_auc_boosting": round(float(np.mean(cv_ap_m)), 3), "pr_auc_rules": round(float(np.mean(cv_ap_r)), 3),
                             "pr_auc_gap_mean": round(float(np.mean(cv_gap)), 3), "folds_boosting_ahead": int(sum(1 for g in cv_gap if g > 0)),
                             "recall_boosting": round(float(np.mean(cv_rec_m)), 3), "recall_rules": round(float(np.mean(cv_rec_r)), 3),
                             "precision_boosting": round(float(np.mean(cv_prec_m)), 3), "precision_rules": round(float(np.mean(cv_prec_r)), 3)},
        "calibration": cal,
    }
    CARD_JSON.write_text(json.dumps(card, indent=1))
    print("wrote", MODEL_JSON.relative_to(ROOT), f"({MODEL_JSON.stat().st_size // 1024} KB)", CARD_JSON.relative_to(ROOT), PARITY_JSON.relative_to(ROOT))


if __name__ == "__main__":
    main()
