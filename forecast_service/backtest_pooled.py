"""
forecast_service/backtest_pooled.py

Rolling-origin backtest reported at the level purchasing actually buys at:
the BASKET, not the individual ingredient.

Why this exists alongside backtest_real.py
------------------------------------------
backtest_real.py averages each ingredient's own WAPE. That is the standard
per-series score, but on this data it is dominated by intermittency rather
than by model quality: the median ingredient moves on a handful of days a
month, so its WAPE denominator is a fraction of a kilogram and a
rounding-sized miss reads as several hundred percent. Averaging fifty such
numbers produces a headline nobody can defend and nobody should trust.

A purchase order is not raised per ingredient per day. It is raised for a
basket of ingredients covering a replenishment window. So the question that
matters is "across everything we buy, over the next N days, how far off was
the total?" -- which pools absolute error over every ingredient and every
fold before dividing. That is a weighted WAPE, and it is the figure this
script reports first.

Both levels are printed. The per-item figure is kept deliberately, so the
harder number is on the record next to the flattering one rather than
quietly dropped.

Usage:
    venv/Scripts/python backtest_pooled.py path/to/export.csv [--json out.json]

The CSV is the ingredient_demand export from
owner/demand_forecast_backtest_export.php (entity_id,entity_name,date,quantity),
which clamps to the last day with real sales -- exporting through "yesterday"
would pad the series with trailing zero-days and measure the padding.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent))

from backtest_real import (  # noqa: E402
    HORIZONS,
    load_series,
    rolling_origin_folds,
    seasonal_naive_forecast,
)
from engine import forecasting  # noqa: E402


def pooled_backtest(series_by_entity: dict, horizon_days: int) -> dict | None:
    """
    Runs every fold for every entity at one horizon, accumulating raw
    absolute error and raw actuals so the division happens ONCE, at the end,
    across the whole basket.

    Per-entity WAPEs are collected too, but only so the harder number can be
    reported beside the pooled one -- they are never averaged into it.
    """
    abs_error_total = 0.0
    baseline_error_total = 0.0
    actual_total = 0.0
    forecast_total = 0.0

    per_item_wapes: list[float] = []
    per_item_baselines: list[float] = []
    fold_counts: list[int] = []
    entities_scored = 0

    # Window-level accumulators. A purchase order covers a whole
    # replenishment window, so the error that matters is on the window TOTAL,
    # not on each day inside it: being a kilo high on Tuesday and a kilo low
    # on Wednesday is a perfect week for restocking purposes and a terrible
    # one by daily scoring. Both are reported.
    window_abs_error = 0.0
    window_baseline_error = 0.0
    window_actual_total = 0.0

    for _entity_id, (entity_name, series) in series_by_entity.items():
        folds = rolling_origin_folds(series, horizon_days)
        if not folds:
            continue

        item_actual: list[float] = []
        item_system: list[float] = []
        item_baseline: list[float] = []

        for train_series, actual_test in folds:
            # Same dispatcher a live request goes through, so an early fold
            # with too little history legitimately scores the trailing-average
            # branch, exactly as production would for that item.
            _classification, forecast_df = forecasting.forecast_demand(train_series, horizon_days)
            fold_system = forecast_df["yhat"].values
            fold_baseline = seasonal_naive_forecast(train_series, horizon_days)

            item_system.extend(fold_system.tolist())
            item_baseline.extend(fold_baseline.tolist())
            item_actual.extend(actual_test.tolist())

            # Same fold, scored once on its total rather than day by day.
            fold_actual_sum = float(np.asarray(actual_test, dtype=float).sum())
            window_abs_error += abs(float(fold_system.sum()) - fold_actual_sum)
            window_baseline_error += abs(float(np.asarray(fold_baseline).sum()) - fold_actual_sum)
            window_actual_total += fold_actual_sum

        a = np.asarray(item_actual, dtype=float)
        s = np.asarray(item_system, dtype=float)
        b = np.asarray(item_baseline, dtype=float)

        # An ingredient with no consumption at all across every fold tells us
        # nothing either way -- it is not evidence of accuracy or of error.
        if a.sum() <= 0:
            continue

        abs_error_total += float(np.abs(a - s).sum())
        baseline_error_total += float(np.abs(a - b).sum())
        actual_total += float(a.sum())
        forecast_total += float(s.sum())

        per_item_wapes.append(float(np.abs(a - s).sum() / a.sum() * 100.0))
        per_item_baselines.append(float(np.abs(a - b).sum() / a.sum() * 100.0))
        fold_counts.append(len(folds))
        entities_scored += 1

    if entities_scored == 0 or actual_total <= 0:
        return None

    pooled_wape = abs_error_total / actual_total * 100.0
    pooled_baseline = baseline_error_total / actual_total * 100.0
    bias = (forecast_total - actual_total) / actual_total * 100.0

    window_wape = (window_abs_error / window_actual_total * 100.0) if window_actual_total > 0 else None
    window_baseline_wape = (window_baseline_error / window_actual_total * 100.0) if window_actual_total > 0 else None

    return {
        "window_wape_pct": round(window_wape, 2) if window_wape is not None else None,
        "window_baseline_wape_pct": round(window_baseline_wape, 2) if window_baseline_wape is not None else None,
        "window_beats_baseline": (
            window_wape is not None and window_baseline_wape is not None
            and window_wape < window_baseline_wape
        ),
        "horizon_days": horizon_days,
        "entities_scored": entities_scored,
        "folds_min": min(fold_counts),
        "folds_max": max(fold_counts),
        "actual_total": round(actual_total, 3),
        "forecast_total": round(forecast_total, 3),
        "pooled_wape_pct": round(pooled_wape, 2),
        "pooled_baseline_wape_pct": round(pooled_baseline, 2),
        "pooled_beats_baseline": pooled_wape < pooled_baseline,
        "bias_pct": round(abs(bias), 2),
        "bias_direction": "over" if bias >= 0 else "under",
        "per_item_mean_wape_pct": round(float(np.mean(per_item_wapes)), 2),
        "per_item_median_wape_pct": round(float(np.median(per_item_wapes)), 2),
        "per_item_baseline_wape_pct": round(float(np.mean(per_item_baselines)), 2),
    }


def main() -> None:
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)

    csv_path = sys.argv[1]
    if not Path(csv_path).exists():
        print(f"File not found: {csv_path}")
        sys.exit(1)

    json_out = None
    if "--json" in sys.argv:
        json_out = sys.argv[sys.argv.index("--json") + 1]

    series_by_entity = load_series(csv_path)
    any_series = next(iter(series_by_entity.values()))[1]
    period_start = str(any_series.index.min().date())
    period_end = str(any_series.index.max().date())

    print("=" * 78)
    print("POOLED REAL-DATA BACKTEST -- rolling-origin, basket level")
    print(f"Ingredients: {len(series_by_entity)}   History: {period_start} .. {period_end}"
          f" ({len(any_series)} days)")
    print("=" * 78)

    # Prophet fits one model per ingredient per fold, so a full three-horizon
    # sweep over 50 ingredients runs into the tens of minutes. Allow a subset
    # to be requested; 7 days is the headline (it yields the most folds).
    horizons = HORIZONS
    if "--horizons" in sys.argv:
        horizons = tuple(int(h) for h in sys.argv[sys.argv.index("--horizons") + 1].split(","))

    results = {}
    for horizon_days in horizons:
        r = pooled_backtest(series_by_entity, horizon_days)
        print(f"\n--- Horizon: {horizon_days} days ---")
        if r is None:
            print("  No ingredient has enough history for a single fold at this horizon.")
            continue
        results[str(horizon_days)] = r
        print(f"  Ingredients scored : {r['entities_scored']}   "
              f"Folds each: {r['folds_min']}-{r['folds_max']}")
        print(f"  WINDOW-TOTAL WAPE  : {r['window_wape_pct']:.1f}%   "
              f"(seasonal-naive baseline {r['window_baseline_wape_pct']:.1f}%)  "
              f"-> beats baseline: {'YES' if r['window_beats_baseline'] else 'NO'}")
        print("     ^ the level a purchase order is actually raised at")
        print(f"  BASKET WAPE        : {r['pooled_wape_pct']:.1f}%   "
              f"(seasonal-naive baseline {r['pooled_baseline_wape_pct']:.1f}%)  "
              f"-> beats baseline: {'YES' if r['pooled_beats_baseline'] else 'NO'}")
        print(f"  Basket total       : forecast {r['forecast_total']:.1f} vs "
              f"actual {r['actual_total']:.1f}  ({r['bias_pct']:.1f}% {r['bias_direction']})")
        print(f"  Per-item WAPE      : mean {r['per_item_mean_wape_pct']:.1f}%  "
              f"median {r['per_item_median_wape_pct']:.1f}%  "
              f"(baseline mean {r['per_item_baseline_wape_pct']:.1f}%)")

    if json_out and results:
        payload = {
            "period_start": period_start,
            "period_end": period_end,
            "history_days": len(any_series),
            "ingredients": len(series_by_entity),
            "horizons": results,
        }
        Path(json_out).write_text(json.dumps(payload, indent=2), encoding="utf-8")
        print(f"\nWrote {json_out}")

    print("\nDone.\n")


if __name__ == "__main__":
    main()
