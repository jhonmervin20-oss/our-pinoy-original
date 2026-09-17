"""
forecasting/holdout.py

Produces the evidence behind the "Forecast vs actual" chart on
owner/demand_forecast.php: what the model WOULD have predicted for a stretch of
days, trained only on the days before them, next to what actually happened.

Why this exists at all. The page already reports an accuracy percentage, but a
percentage is a claim. A reader has no way to check it, and no feel for whether
the model is wrong by a little on many days or badly on a few. Drawing the
prediction against the truth is the only presentation that lets someone judge
the forecast for themselves.

Why it is not read from the stored forecasts. `menu_item_demand_forecast` only
ever holds predictions for days that have NOT happened yet -- old rows are
pruned with their run -- so `actual_quantity` is null on every row and there is
no historical prediction on file to score. Rather than fabricate one, this
refits the model on a genuine hold-out: the last H days are removed from
training, predicted, and then compared against the sales that really occurred.

The model is not reimplemented here. It calls the same s2_forecast.run() the
production pipeline calls, with the same settings, so the chart shows the real
model's behaviour and not an approximation of it. `anchor` is passed the fold's
own cutoff, which s2_forecast documents as mandatory for a backtest -- without
it the model would forecast forward from today and be scored against days
months earlier.

Usage:
    python holdout.py            # JSON to stdout
    python holdout.py --horizon 14
"""
from __future__ import annotations

import argparse
import json
import sys

import numpy as np
import pandas as pd

from stages import s1_history, s2_forecast


def seasonal_naive(train: pd.DataFrame, horizon: int, anchor: pd.Timestamp) -> np.ndarray:
    """
    The benchmark the model has to beat: each future day predicted as the mean
    of that same weekday in the training data. Simple, and genuinely hard to
    beat on a business with a strong weekly rhythm -- which is exactly why it is
    the standard comparison rather than a straw man.
    """
    by_weekday = train.groupby(train["ds"].dt.weekday)["y"].mean()
    overall = float(train["y"].mean())
    out = []
    for i in range(1, horizon + 1):
        day = pd.Timestamp(anchor) + pd.Timedelta(days=int(i))
        out.append(float(by_weekday.get(day.weekday(), overall)))
    return np.array(out)


def wape(actual: np.ndarray, forecast: np.ndarray) -> float | None:
    """
    Weighted absolute percentage error: total error over total demand. MAPE is
    unusable here -- it divides by each day's actual, so a single quiet day
    produces a division by zero or a percentage large enough to swamp the mean.
    """
    total = float(np.abs(actual).sum())
    if total <= 0:
        return None
    return float(np.abs(actual - forecast).sum() / total * 100.0)


def build(horizon: int) -> dict | None:
    series, _meta = s1_history.run()
    if series is None or len(series) == 0:
        return None

    series = series.sort_values("ds").reset_index(drop=True)

    # Not enough history to both train and hold out is not an error -- it is a
    # legitimate "not yet", and the page says so rather than drawing an empty
    # chart. 28 days leaves four whole weekly cycles to learn from.
    min_train = 28
    if len(series) < min_train + horizon:
        return {
            "ok": False,
            "reason": f"needs {min_train + horizon} days of history, has {len(series)}",
            "horizon_days": horizon,
        }

    train = series.iloc[:-horizon].copy()
    test = series.iloc[-horizon:].copy()
    cutoff = pd.Timestamp(train["ds"].max())

    forecast, _fmeta = s2_forecast.run(train, horizon, anchor=cutoff)

    # Align on the date rather than on position: s2 returns one row per future
    # day starting the day after the anchor, but matching by date means a gap
    # in either frame can never silently shift the comparison by a day.
    fc = forecast[["ds", "yhat", "yhat_lower", "yhat_upper"]].copy()
    fc["ds"] = pd.to_datetime(fc["ds"]).dt.normalize()
    test["ds"] = pd.to_datetime(test["ds"]).dt.normalize()
    merged = test.merge(fc, on="ds", how="inner").sort_values("ds")

    if merged.empty:
        return {"ok": False, "reason": "no overlapping dates between forecast and hold-out",
                "horizon_days": horizon}

    actual = merged["y"].to_numpy(dtype=float)
    pred = merged["yhat"].to_numpy(dtype=float)
    base = seasonal_naive(train, len(merged), cutoff)

    model_wape = wape(actual, pred)
    base_wape = wape(actual, base)

    inside = int(((actual >= merged["yhat_lower"].to_numpy(dtype=float)) &
                  (actual <= merged["yhat_upper"].to_numpy(dtype=float))).sum())

    return {
        "ok": True,
        "horizon_days": horizon,
        "trained_on_days": int(len(train)),
        "train_start": train["ds"].min().strftime("%Y-%m-%d"),
        "cutoff": cutoff.strftime("%Y-%m-%d"),
        "test_start": merged["ds"].min().strftime("%Y-%m-%d"),
        "test_end": merged["ds"].max().strftime("%Y-%m-%d"),
        "days": [d.strftime("%Y-%m-%d") for d in merged["ds"]],
        "actual": [round(float(v), 2) for v in actual],
        "predicted": [round(float(v), 2) for v in pred],
        "lower": [round(float(v), 2) for v in merged["yhat_lower"]],
        "upper": [round(float(v), 2) for v in merged["yhat_upper"]],
        "baseline": [round(float(v), 2) for v in base],
        "wape_pct": round(model_wape, 2) if model_wape is not None else None,
        "baseline_wape_pct": round(base_wape, 2) if base_wape is not None else None,
        "beats_baseline": (model_wape is not None and base_wape is not None
                           and model_wape < base_wape),
        "actual_total": round(float(actual.sum()), 2),
        "predicted_total": round(float(pred.sum()), 2),
        "inside_band": inside,
        "band_pct": round(inside / len(merged) * 100.0, 1),
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--horizon", type=int, default=14)
    args = ap.parse_args()

    result = build(args.horizon)
    if result is None:
        print(json.dumps({"ok": False, "reason": "no sales history"}))
        return 1
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    sys.exit(main())
