"""
forecasting/stages/s7_accuracy.py

Stage 7 -- score the forecast against what actually sold.

Rolling-origin backtest: train to a cutoff, forecast forward, compare with what
really happened, move the cutoff, repeat.

Scored at the horizon that matters for ORDERING -- lead time plus review period,
about 7 days -- not the 30-day display horizon. With 90 days of history a 7-day
horizon yields roughly six folds; a 30-day horizon yields one, and one number is
an anecdote rather than a measurement.

The baseline is seasonal-naive: the mean of the same weekday over the trailing
four weeks. Beating a flat average would prove nothing when the weekend effect
is 1.70x.

This stage is the control loop. `beats_baseline` decides whether the next run
trusts Prophet or falls back to the baseline, so a bad model cannot quietly keep
driving purchase orders.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from db import execute

MIN_TRAIN_DAYS = 56  # Prophet's own cross_validation default for this shape


def wape(actual: np.ndarray, forecast: np.ndarray) -> float | None:
    """
    WAPE, not MAPE. MAPE divides by the actual, and on a dish that sells zero
    some days that is a division by zero or an absurd percentage. WAPE weights
    by volume, which is also what the kitchen cares about.
    """
    denom = np.abs(actual).sum()
    if denom <= 0:
        return None
    return float(np.abs(actual - forecast).sum() / denom * 100.0)


def seasonal_naive(train: pd.DataFrame, horizon: int) -> np.ndarray:
    """Mean of the same weekday over the trailing 4 weeks."""
    recent = train.tail(28)
    by_weekday = recent.groupby(recent["ds"].dt.weekday)["y"].mean()
    overall = float(train["y"].mean())

    last = train["ds"].max()
    out = []
    for step in range(1, horizon + 1):
        wd = (last + pd.Timedelta(days=step)).weekday()
        out.append(float(by_weekday.get(wd, overall)))
    return np.array(out)


def rolling_origin(series: pd.DataFrame, horizon: int) -> list[tuple[pd.DataFrame, np.ndarray]]:
    """Non-overlapping test windows, sliding forward by one horizon each time."""
    folds = []
    cursor = MIN_TRAIN_DAYS
    while cursor + horizon <= len(series):
        train = series.iloc[:cursor]
        test = series.iloc[cursor:cursor + horizon]["y"].to_numpy(dtype=float)
        folds.append((train, test))
        cursor += horizon
    return folds


def backtest(series: pd.DataFrame, horizon: int) -> dict | None:
    """
    Returns pooled WAPE for Prophet and for the baseline, plus fold count.

    Errors are pooled across folds before dividing -- averaging per-fold
    percentages would let a quiet fold count as much as a busy one.
    """
    from stages.s2_forecast import run as forecast_run

    folds = rolling_origin(series, horizon)
    if not folds:
        return None

    actuals, model_preds, base_preds = [], [], []
    for train, test in folds:
        try:
            # Anchor each fold on its own cutoff. Without this the forecast
            # jumps to today's real date and gets scored against actuals from
            # months earlier -- which is not a measurement of anything.
            fc, _ = forecast_run(
                train,
                horizon_days=horizon,
                anchor=train["ds"].max() + pd.Timedelta(days=1),
            )
        except ValueError:
            continue
        yhat = fc["yhat"].to_numpy(dtype=float)[:len(test)]
        if len(yhat) < len(test):
            continue
        actuals.append(test)
        model_preds.append(yhat)
        base_preds.append(seasonal_naive(train, horizon)[:len(test)])

    if not actuals:
        return None

    a = np.concatenate(actuals)
    m = np.concatenate(model_preds)
    b = np.concatenate(base_preds)

    model_wape = wape(a, m)
    base_wape = wape(a, b)
    bias = float((m.sum() - a.sum()) / a.sum() * 100.0) if a.sum() > 0 else None

    return {
        "horizon_days": horizon,
        "fold_count": len(actuals),
        "points": int(len(a)),
        "wape_pct": model_wape,
        "baseline_wape_pct": base_wape,
        "beats_baseline": (model_wape is not None and base_wape is not None
                           and model_wape < base_wape),
        "bias_pct": bias,
        "actual_total": float(a.sum()),
        "forecast_total": float(m.sum()),
    }


def run(series: pd.DataFrame, horizons: tuple[int, ...] = (7, 14, 30)) -> tuple[list[dict], dict]:
    results = []
    period_start = series["ds"].min().strftime("%Y-%m-%d") if not series.empty else None
    period_end = series["ds"].max().strftime("%Y-%m-%d") if not series.empty else None
    for h in horizons:
        r = backtest(series, h)
        if r is not None:
            r["period_start"] = period_start
            r["period_end"] = period_end
            results.append(r)

    decision = next((r for r in results if r["horizon_days"] == 7), None)
    meta = {
        "scored_horizons": [r["horizon_days"] for r in results],
        # Only the 7-day figure gates the model: it is the horizon every purchase
        # order is made on, and the only one 90 days of history can score
        # properly. 14 informs, 30 is shown with a caution.
        "gate_horizon": 7,
        "beats_baseline": bool(decision["beats_baseline"]) if decision else None,
        "model_in_use": ("prophet" if decision and decision["beats_baseline"]
                         else "trailing_average" if decision else "prophet"),
    }
    return results, meta


def persist(run_id: int, results: list[dict]) -> int:
    if not results:
        return 0
    rows = [
        {
            "run_id": run_id,
            "horizon_days": r["horizon_days"],
            "wape_pct": None if r["wape_pct"] is None else round(min(r["wape_pct"], 9999.99), 2),
            "baseline_wape_pct": None if r["baseline_wape_pct"] is None
                                 else round(min(r["baseline_wape_pct"], 9999.99), 2),
            "beats_baseline": 1 if r["beats_baseline"] else 0,
            "bias_pct": None if r["bias_pct"] is None else round(max(min(r["bias_pct"], 9999.99), -9999.99), 2),
            "fold_count": r["fold_count"],
            "reconciled_count": r["points"],
            "period_start": r.get("period_start"),
            "period_end": r.get("period_end"),
        }
        for r in results
    ]
    execute(
        """
        INSERT INTO forecast_accuracy
            (run_id, forecast_domain, entity_id, horizon_days, lag_days,
             period_start, period_end, wape_pct, baseline_wape_pct, beats_baseline,
             bias_pct, reconciled_count, fold_count, computed_at)
        VALUES
            (:run_id, 'menu_item_sales', NULL, :horizon_days, NULL,
             :period_start, :period_end, :wape_pct, :baseline_wape_pct, :beats_baseline,
             :bias_pct, :reconciled_count, :fold_count, NOW())
        """,
        rows,
    )
    return len(rows)
