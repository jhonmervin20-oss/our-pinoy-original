"""
forecast_service/compare_seasonality.py

Measures what the yearly-seasonality component is actually worth on this
app's data, by running the same rolling-origin backtest twice -- once with
yearly seasonality enabled, once without -- and comparing.

Why this exists: yearly seasonality was switched on by request, but the app
only has ~97 days of sales history. Prophet will happily FIT a yearly
component to a single quarter; whether that component describes a real
annual cycle or just absorbs noise is an empirical question, not a matter
of opinion, and it is cheap to answer. If it costs accuracy it should be
switched off (and the reason recorded); if it is free it can stay.

Runs on a subset of the highest-volume ingredients by default, since the
question is about the model and a full 50-ingredient sweep costs ~10 minutes
per configuration.

Usage:
    venv/Scripts/python compare_seasonality.py path/to/export.csv [--top 15] [--horizon 7]
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd
from prophet import Prophet

sys.path.insert(0, str(Path(__file__).resolve().parent))

from backtest_real import load_series, rolling_origin_folds, seasonal_naive_forecast  # noqa: E402
from engine import data_prep  # noqa: E402


def fit_predict(train: pd.Series, horizon: int, yearly) -> np.ndarray:
    """One Prophet fit with a given yearly setting; everything else matches
    engine/forecasting.py's live configuration."""
    df = pd.DataFrame({"ds": train.index, "y": train.values})
    m = Prophet(
        daily_seasonality=True,
        weekly_seasonality=True,
        yearly_seasonality=yearly,
        interval_width=0.80,
    )
    m.fit(df)
    future = m.make_future_dataframe(periods=horizon)
    fc = m.predict(future).tail(horizon)
    return np.clip(fc["yhat"].values, 0, None)


def score(series_by_entity: dict, horizon: int, yearly) -> dict:
    window_err = 0.0
    daily_err = 0.0
    actual_total = 0.0
    forecast_total = 0.0

    for _eid, (_name, series) in series_by_entity.items():
        for train, actual in rolling_origin_folds(series, horizon):
            pred = fit_predict(train, horizon, yearly)
            a = np.asarray(actual, dtype=float)
            window_err += abs(float(pred.sum()) - float(a.sum()))
            daily_err += float(np.abs(a - pred).sum())
            actual_total += float(a.sum())
            forecast_total += float(pred.sum())

    if actual_total <= 0:
        return {}
    return {
        "window_wape": window_err / actual_total * 100.0,
        "daily_wape": daily_err / actual_total * 100.0,
        "bias": (forecast_total - actual_total) / actual_total * 100.0,
    }


def baseline_score(series_by_entity: dict, horizon: int) -> dict:
    window_err = 0.0
    daily_err = 0.0
    actual_total = 0.0
    for _eid, (_name, series) in series_by_entity.items():
        for train, actual in rolling_origin_folds(series, horizon):
            pred = np.asarray(seasonal_naive_forecast(train, horizon), dtype=float)
            a = np.asarray(actual, dtype=float)
            window_err += abs(float(pred.sum()) - float(a.sum()))
            daily_err += float(np.abs(a - pred).sum())
            actual_total += float(a.sum())
    if actual_total <= 0:
        return {}
    return {
        "window_wape": window_err / actual_total * 100.0,
        "daily_wape": daily_err / actual_total * 100.0,
    }


def main() -> None:
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)

    csv_path = sys.argv[1]
    top = int(sys.argv[sys.argv.index("--top") + 1]) if "--top" in sys.argv else 15
    horizon = int(sys.argv[sys.argv.index("--horizon") + 1]) if "--horizon" in sys.argv else 7

    all_series = load_series(csv_path)
    # Highest-volume ingredients: the ones a purchase order is actually about,
    # and the ones whose error dominates a pooled score.
    ranked = sorted(all_series.items(), key=lambda kv: kv[1][1].sum(), reverse=True)[:top]
    subset = dict(ranked)

    print("=" * 74)
    print(f"YEARLY SEASONALITY -- IS IT EARNING ITS PLACE?  (horizon {horizon}d)")
    print(f"Top {len(subset)} ingredients by volume, "
          f"{len(next(iter(subset.values()))[1])} days of history")
    print("=" * 74)

    base = baseline_score(subset, horizon)
    print(f"\n  seasonal-naive baseline : window {base['window_wape']:6.1f}%   "
          f"daily {base['daily_wape']:6.1f}%")

    for label, yearly in [("yearly OFF", False), ("yearly = 4", 4)]:
        r = score(subset, horizon, yearly)
        print(f"  Prophet, {label:<12}: window {r['window_wape']:6.1f}%   "
              f"daily {r['daily_wape']:6.1f}%   bias {r['bias']:+.1f}%")

    print("\nLower is better. 'window' is the total over the ordering cycle,")
    print("which is the level a purchase order is actually raised at.\n")


if __name__ == "__main__":
    main()
