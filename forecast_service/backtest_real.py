"""
forecast_service/backtest_real.py

Real-data counterpart to backtest.py's Part 1: a rolling-origin
(walk-forward) backtest run against ACTUAL historical daily series
exported by PHP (owner/demand_forecast_backtest_export.php), not
synthetic profiles. backtest.py stays as the disclosed synthetic
stress-test tool (exercises demand classes/edge cases this app's ~91
days of real data may not currently cover on its own); this script is
what actually validates the live system's forecast quality for the
capstone defense.

WAPE (Weighted Absolute Percentage Error), not MAPE: this app's real
history has plenty of zero-demand days for slower-moving items, and MAPE
divides by the actual on each individual day, which is undefined on a
zero-demand day. WAPE = sum(|actual-predicted|) / sum(actual) * 100 is
well-defined for a whole window as long as that window's total actual
demand is nonzero -- the food-service-standard choice for exactly this
situation.

Fold-count discipline: with ~91 real days of history, a 30-day-horizon
walk-forward backtest yields at most 1-2 folds -- reporting a 30-day WAPE
without its fold count would misrepresent how much data that number is
actually based on. Every printed number carries its fold count. The
7-day horizon (4-6+ folds from 91 days) is the one to lead with; 14/30
are reported honestly alongside how thin their fold count is.

CSV format expected (one row per entity per calendar day, dense -- zero
days included, not omitted; entity is a menu item or an ingredient
depending on which export this was run against):
    entity_id,entity_name,date,quantity
    12,"Sisig",2026-05-07,3
    12,"Sisig",2026-05-08,0
    ...

Run from the activated venv:
    python backtest_real.py path/to/export.csv
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd

from engine import forecasting

MIN_HISTORY_DAYS = 14  # mirrors data_prep.MIN_HISTORY_DAYS -- below this, don't even attempt a fold
HORIZONS = (7, 14, 30)
SEASONAL_NAIVE_LOOKBACK_WEEKS = 4  # same-weekday average over the trailing N weeks


def load_series(csv_path: str) -> dict[int, tuple[str, pd.Series]]:
    """
    Reads the export CSV and returns {entity_id: (entity_name, dense_daily_series)}.
    Each series is reindexed over its own [min_date, max_date] range with
    missing days filled at 0 -- a quiet day must count as 0, not be
    skipped (skipping would bias every mean/WAPE upward), matching the
    same dense-fill convention used throughout the PHP side
    (see denseDailyMap() in owner/includes/demand_forecast_functions.php).
    """
    df = pd.read_csv(csv_path, parse_dates=["date"])
    series_by_entity: dict[int, tuple[str, pd.Series]] = {}
    for entity_id, group in df.groupby("entity_id"):
        entity_name = group["entity_name"].iloc[0]
        group = group.sort_values("date")
        full_index = pd.date_range(group["date"].min(), group["date"].max(), freq="D")
        s = pd.Series(group.set_index("date")["quantity"], index=full_index).fillna(0.0)
        series_by_entity[int(entity_id)] = (str(entity_name), s)
    return series_by_entity


def seasonal_naive_forecast(train_series: pd.Series, horizon_days: int) -> np.ndarray:
    """
    Same-weekday trailing average over the last SEASONAL_NAIVE_LOOKBACK_WEEKS
    weeks of the training window -- the baseline this engine's Prophet
    forecast must beat to mean anything (a result that doesn't beat this
    is a more expensive way to be wrong, not a result). Falls back to the
    plain mean of the whole training window for a weekday with no prior
    occurrence in the lookback (short training windows near MIN_HISTORY_DAYS).
    """
    train_series = train_series.sort_index()
    last_date = train_series.index[-1]
    future_dates = pd.date_range(last_date + pd.Timedelta(days=1), periods=horizon_days, freq="D")

    overall_mean = float(train_series.mean())
    preds = []
    for d in future_dates:
        same_weekday = train_series[train_series.index.dayofweek == d.dayofweek]
        recent = same_weekday.tail(SEASONAL_NAIVE_LOOKBACK_WEEKS)
        preds.append(float(recent.mean()) if len(recent) > 0 else overall_mean)
    return np.array(preds)


def wape(actual: np.ndarray, predicted: np.ndarray) -> float | None:
    """WAPE = sum(|actual-predicted|) / sum(actual) * 100. None (not 0) if sum(actual) == 0 -- genuinely undefined, not a fabricated perfect score."""
    actual = np.asarray(actual, dtype=float)
    predicted = np.asarray(predicted, dtype=float)
    total_actual = actual.sum()
    if total_actual <= 0:
        return None
    return float(np.abs(actual - predicted).sum() / total_actual * 100.0)


def rolling_origin_folds(series: pd.Series, horizon_days: int) -> list[tuple[pd.Series, np.ndarray]]:
    """
    Walk-forward folds: train on [0:cursor], test on the next horizon_days
    days, then slide the cursor forward by horizon_days (non-overlapping
    test windows) and repeat until the series runs out. Starts once at
    least MIN_HISTORY_DAYS of training data exists.

    Returns a list of (train_series, actual_test_array) pairs -- one per
    fold. The number of pairs returned IS the fold count this backtest
    reports; callers must not claim a confidence level the fold count
    doesn't support (see the module docstring's fold-count discipline note).
    """
    folds = []
    cursor = MIN_HISTORY_DAYS
    while cursor + horizon_days <= len(series):
        train = series.iloc[:cursor]
        test = series.iloc[cursor:cursor + horizon_days].values
        folds.append((train, test))
        cursor += horizon_days
    return folds


def backtest_entity(entity_name: str, series: pd.Series, horizon_days: int) -> dict | None:
    """
    Runs every rolling-origin fold for one entity at one horizon, returns
    pooled WAPE + baseline WAPE + fold_count, or None if no fold could be run.

    Calls forecasting.forecast_demand() -- the same dispatcher a live
    request goes through, not a Prophet-only call -- so an early fold whose
    training window is still below
    demand_classification.PROPHET_MIN_TRAINING_DAYS legitimately scores the
    trailing-average branch instead of Prophet, exactly like production
    would for an item with that little history. The pooled result is
    therefore "this app's model" for that fold count, not "Prophet in
    isolation"; see the mean_wape print label in main().
    """
    folds = rolling_origin_folds(series, horizon_days)
    if not folds:
        return None

    all_actual: list[float] = []
    all_system: list[float] = []
    all_baseline: list[float] = []
    for train_series, actual_test in folds:
        _classification, forecast_df = forecasting.forecast_demand(train_series, horizon_days)
        system_pred = forecast_df["yhat"].values
        baseline_pred = seasonal_naive_forecast(train_series, horizon_days)

        all_actual.extend(actual_test.tolist())
        all_system.extend(system_pred.tolist())
        all_baseline.extend(baseline_pred.tolist())

    return {
        "entity_name": entity_name,
        "fold_count": len(folds),
        "wape_pct": wape(np.array(all_actual), np.array(all_system)),
        "baseline_wape_pct": wape(np.array(all_actual), np.array(all_baseline)),
    }


def main():
    if len(sys.argv) < 2:
        print("Usage: python backtest_real.py path/to/export.csv")
        sys.exit(1)

    csv_path = sys.argv[1]
    if not Path(csv_path).exists():
        print(f"File not found: {csv_path}")
        sys.exit(1)

    series_by_entity = load_series(csv_path)
    print("=" * 78)
    print("REAL-DATA BACKTEST -- rolling-origin, WAPE vs. seasonal-naive baseline")
    print(f"Entities: {len(series_by_entity)}  Source: {csv_path}")
    print("=" * 78)

    for horizon_days in HORIZONS:
        print(f"\n--- Horizon: {horizon_days} days ---")
        entity_results = []
        for _entity_id, (entity_name, series) in series_by_entity.items():
            result = backtest_entity(entity_name, series, horizon_days)
            if result is not None:
                entity_results.append(result)

        if not entity_results:
            print("  No entity has enough history for a single fold at this horizon.")
            continue

        min_folds = min(r["fold_count"] for r in entity_results)
        max_folds = max(r["fold_count"] for r in entity_results)
        valid_wape = [r["wape_pct"] for r in entity_results if r["wape_pct"] is not None]
        valid_baseline = [r["baseline_wape_pct"] for r in entity_results if r["baseline_wape_pct"] is not None]

        mean_wape = float(np.mean(valid_wape)) if valid_wape else None
        mean_baseline = float(np.mean(valid_baseline)) if valid_baseline else None
        beats_baseline = mean_wape is not None and mean_baseline is not None and mean_wape < mean_baseline

        print(f"  Entities scored: {len(entity_results)}  Folds per entity: {min_folds}-{max_folds}")
        if mean_wape is not None:
            print(f"  Mean WAPE (this app's model): {mean_wape:.1f}%")
        else:
            print("  Mean WAPE (this app's model): n/a (no entity had nonzero actual demand across its folds)")
        if mean_baseline is not None:
            print(f"  Mean WAPE (seasonal-naive): {mean_baseline:.1f}%")
            print(f"  Beats baseline:             {'YES' if beats_baseline else 'NO'}")
        else:
            print("  Mean WAPE (seasonal-naive): n/a")

        if horizon_days == 7:
            print("  ^ Headline number -- most folds available from ~91 days of real history.")
        elif min_folds <= 1:
            print("  ^ Single-fold result -- report alongside its fold count, never as a standalone percentage.")

    print("\nDone.\n")


if __name__ == "__main__":
    main()
