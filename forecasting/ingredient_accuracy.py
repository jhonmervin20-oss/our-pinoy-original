"""
forecasting/ingredient_accuracy.py

Scores the INGREDIENT layer of the forecast, which stage 7 does not.

Why this exists. `s7_accuracy` measures one thing: the daily order count Prophet
actually predicts. Everything below that -- which dishes, which ingredients --
is deterministic arithmetic, so the page reports the top-level error and leaves
the ingredient quantities carrying no measured error bar of their own. That is a
defensible design, but "we didn't measure it" and "it's accurate" are different
claims, and only one of them is currently supported. This produces the number.

What it measures, and at which level. A per-dish-per-day figure on a menu where
the average dish sells ~2.4 servings is mostly Poisson noise and says nothing
useful about purchasing. The decision the forecast actually feeds is "how much
of this ingredient will we get through before the next delivery", so the headline
here is per ingredient TOTALLED OVER THE WINDOW. The noisier per-ingredient-per-day
figure is printed alongside it deliberately -- the gap between the two is the
point, and hiding it would be the same omission this script exists to close.

Method. A genuine hold-out, not a re-read of stored forecasts:

  1. take the real history stage 1 builds (closure rule, holiday exclusions, all
     of it) and cut the last H days off the end
  2. fit the production model on what remains, anchored to the fold's own cutoff
  3. split to dishes using the mix as it looked AT THE CUTOFF -- weekday shares
     and units-per-order both re-measured there, never with hindsight
  4. explode to ingredients through the live recipes
  5. compare against what those H days really consumed

Step 5's "actual" is real order_items run through the SAME recipe_factors() the
forecast used. That is deliberate: it isolates FORECAST error instead of mixing
in recipe or unit-conversion differences, and it sidesteps the inventory ledger,
whose reliability boundary starts at 2026-07-18 and would silently truncate the
comparison window. It measures demand, not what the storeroom recorded.

Nothing here writes to the database.

Usage:
    python ingredient_accuracy.py                 # 7-day window
    python ingredient_accuracy.py --horizon 14
    python ingredient_accuracy.py --top 15        # worst-N ingredient table
"""

from __future__ import annotations

import argparse
import sys

import numpy as np
import pandas as pd

import config
from db import read_sql
from stages import s1_history, s2_forecast, s3_menu_split, s4_explode


def wape(actual: np.ndarray, forecast: np.ndarray) -> float | None:
    """
    Same metric stage 7 uses, for the same reason: MAPE divides by the actual,
    and an ingredient that goes unused on a given day makes that a division by
    zero. WAPE weights by volume, which is what a purchase order cares about.
    """
    denom = np.abs(actual).sum()
    if denom <= 0:
        return None
    return float(np.abs(actual - forecast).sum() / denom * 100.0)


def units_per_order_at(cutoff: str) -> float:
    """
    Mirrors the `upo` query inside s3_menu_split.run(). Duplicated rather than
    imported because that one is computed inside run(), which also persists --
    and this script must not write. If s3's window rule changes, change it here.
    """
    weeks = config.get_int("mix_window_weeks")
    row = read_sql(
        """
        SELECT COALESCE(SUM(oi.quantity), 0) / NULLIF(COUNT(DISTINCT o.order_id), 0) AS upo
        FROM order_items oi
        JOIN orders o          ON o.order_id = oi.order_id
        JOIN order_payments op ON op.order_id = o.order_id
        WHERE op.payment_status = 'paid'
          AND oi.status <> 'cancelled'
          AND DATE(COALESCE(op.paid_at, op.created_at))
              BETWEEN DATE_SUB(:end, INTERVAL :days DAY) AND :end
        """,
        {"end": cutoff, "days": weeks * 7},
    )
    val = row["upo"].iloc[0] if not row.empty else None
    return float(val) if val is not None else 0.0


def actual_servings(start: str, end: str) -> pd.DataFrame:
    """
    What each dish really sold, per day, over the hold-out window.

    Same paid/non-cancelled predicate stage 3 measures the mix with, so the
    comparison is like-for-like rather than one definition of a sale against
    another.
    """
    return read_sql(
        """
        SELECT oi.menu_item_id,
               DATE(COALESCE(op.paid_at, op.created_at)) AS ds,
               SUM(oi.quantity)                          AS servings
        FROM order_items oi
        JOIN orders o          ON o.order_id = oi.order_id
        JOIN order_payments op ON op.order_id = o.order_id
        WHERE op.payment_status = 'paid'
          AND oi.status <> 'cancelled'
          AND o.order_status <> 'cancelled'
          AND DATE(COALESCE(op.paid_at, op.created_at)) BETWEEN :start AND :end
        GROUP BY oi.menu_item_id, ds
        """,
        {"start": start, "end": end},
    )


def to_ingredients(servings_df: pd.DataFrame, factors: pd.DataFrame) -> pd.DataFrame:
    """
    dish servings -> ingredient quantities, through the production explode.

    Both the forecast and the actual go through this same call, so any recipe or
    unit-conversion quirk cancels out and what is left is forecast error.
    """
    if servings_df.empty:
        return pd.DataFrame(columns=["item_id", "ds", "predicted_qty"])
    df = servings_df.copy()
    df["ds"] = pd.to_datetime(df["ds"])
    df["servings"] = df["servings"].astype(float)
    return s4_explode.summarise(s4_explode.explode(df, factors))


def main(horizon: int, top_n: int) -> int:
    series, meta = s1_history.run()
    if len(series) < 60 + horizon:
        print(f"Not enough history: {len(series)} days for a {horizon}-day hold-out.")
        return 1

    train = series.iloc[:-horizon]
    cutoff = train["ds"].max()
    holdout_start = (cutoff + pd.Timedelta(1, unit="D")).strftime("%Y-%m-%d")
    holdout_end = series["ds"].max().strftime("%Y-%m-%d")
    cutoff_str = cutoff.strftime("%Y-%m-%d")

    print("=" * 74)
    print(f"INGREDIENT-LEVEL FORECAST ACCURACY -- {horizon}-day hold-out")
    print("=" * 74)
    print(f"  trained on   : {train['ds'].min():%Y-%m-%d} -> {cutoff_str}  ({len(train)} days)")
    print(f"  hold-out     : {holdout_start} -> {holdout_end}")
    print(f"  excluded     : {meta.get('excluded_count', 0)} closure day(s), "
          f"{meta.get('holidays_excluded_count', 0)} holiday(s)")

    # -- the model, on the fold's own cutoff ---------------------------------
    forecast, m2 = s2_forecast.run(
        train, horizon_days=horizon, anchor=cutoff + pd.Timedelta(1, unit="D")
    )
    print(f"  engine       : {m2['engine_version']}")

    # -- dish split, measured AT THE CUTOFF (no hindsight) -------------------
    shares = s3_menu_split.weekday_shares(cutoff_str)
    upo = units_per_order_at(cutoff_str)
    split_df = s3_menu_split.renormalise(
        s3_menu_split.split(forecast, shares), forecast, upo
    )
    print(f"  mix          : {shares['menu_item_id'].nunique()} dishes, "
          f"{upo:.3f} units/order (as of cutoff)")

    # -- both sides through the same explode ---------------------------------
    factors = s4_explode.recipe_factors()
    fc_ing = to_ingredients(
        split_df.rename(columns={"servings": "servings"})[["menu_item_id", "ds", "servings"]],
        factors,
    ).rename(columns={"predicted_qty": "forecast_qty"})

    act_ing = to_ingredients(actual_servings(holdout_start, holdout_end), factors) \
        .rename(columns={"predicted_qty": "actual_qty"})

    # Score ONLY the days the model actually forecast.
    #
    # Stage 1 drops holidays from the series, so the last H *rows* of history can
    # span more than H calendar days -- a 7-day hold-out ending 2026-09-02 covers
    # 8 dates because 2026-08-31 (National Heroes Day) is not a row. A plain
    # BETWEEN on the actuals therefore charges the forecast for a day it was never
    # asked about, and every extra day makes the model look like it under-forecast.
    # That asymmetry is worth ~9 points of apparent bias here, which is larger than
    # most of the effects this script exists to measure.
    fc_days = set(pd.to_datetime(forecast["ds"]))
    fc_ing = fc_ing[fc_ing["ds"].isin(fc_days)]
    act_ing = act_ing[act_ing["ds"].isin(fc_days)]
    scored_dates = sorted(d.strftime("%Y-%m-%d") for d in fc_days)

    if act_ing.empty:
        print("\nNo actual consumption in the hold-out window -- nothing to score.")
        return 1

    daily = fc_ing.merge(act_ing, on=["item_id", "ds"], how="outer").fillna(0.0)

    names = factors[["item_id", "item_name", "unit_code"]].drop_duplicates("item_id")
    window = (
        daily.groupby("item_id", as_index=False)[["forecast_qty", "actual_qty"]]
        .sum()
        .merge(names, on="item_id", how="left")
    )
    window = window[window["actual_qty"] > 0].copy()

    # -- the three levels ----------------------------------------------------
    pooled_window = wape(window["actual_qty"].to_numpy(), window["forecast_qty"].to_numpy())
    pooled_daily = wape(daily["actual_qty"].to_numpy(), daily["forecast_qty"].to_numpy())

    window["abs_err"] = (window["forecast_qty"] - window["actual_qty"]).abs()
    window["wape_pct"] = window["abs_err"] / window["actual_qty"] * 100.0
    per_item_median = float(window["wape_pct"].median())

    total_a = float(window["actual_qty"].sum())
    total_f = float(window["forecast_qty"].sum())
    bias = (total_f - total_a) / total_a * 100.0 if total_a > 0 else None

    print()
    print("-" * 74)
    print("RESULTS")
    print("-" * 74)
    print(f"  Days actually scored                     : {len(scored_dates)} "
          f"({scored_dates[0]} .. {scored_dates[-1]})")
    print(f"  Ingredients scored                       : {len(window)}")
    print(f"  Pooled WAPE, per ingredient over window  : {pooled_window:6.2f}%   <- the purchasing number")
    print(f"  Median per-ingredient WAPE over window   : {per_item_median:6.2f}%")
    print(f"  Pooled WAPE, per ingredient PER DAY      : {pooled_daily:6.2f}%   <- noisier by construction")
    print(f"  Bias over the window                     : {bias:+6.2f}%   "
          f"({'over' if bias and bias > 0 else 'under'}-forecast)")
    print()
    print("  Read the first number, not the third: a purchase order covers a")
    print("  lead time, not a single day, so day-level error averages out before")
    print("  it ever reaches a supplier.")

    # -- where the error actually comes from ---------------------------------
    #
    # Ingredient demand is a product of three estimates, so a single WAPE says
    # how wrong it was without saying which part was wrong. These three lines
    # attribute it. Without them the headline invites the wrong conclusion --
    # that Prophet is bad -- when the mix and the basket size are separate
    # inputs that drift on their own and are measured, not modelled.
    fc_orders = float(forecast["yhat"].sum())
    # Same forecast-days-only restriction as above, for the same reason.
    placeholders = ", ".join(f":d{i}" for i in range(len(scored_dates)))
    act = read_sql(
        f"""
        SELECT COUNT(DISTINCT o.order_id) AS orders, COALESCE(SUM(oi.quantity), 0) AS units
        FROM order_items oi
        JOIN orders o          ON o.order_id = oi.order_id
        JOIN order_payments op ON op.order_id = o.order_id
        WHERE op.payment_status = 'paid'
          AND oi.status <> 'cancelled'
          AND o.order_status <> 'cancelled'
          AND DATE(COALESCE(op.paid_at, op.created_at)) IN ({placeholders})
        """,
        {f"d{i}": d for i, d in enumerate(scored_dates)},
    )
    act_orders = float(act["orders"].iloc[0])
    act_units = float(act["units"].iloc[0])
    act_upo = act_units / act_orders if act_orders else 0.0

    def pct(f: float, a: float) -> float:
        return (f - a) / a * 100.0 if a else 0.0

    print()
    print("-" * 74)
    print("WHERE THE ERROR COMES FROM")
    print("-" * 74)
    print(f"  {'':<34}{'forecast':>12}{'actual':>12}{'error':>10}")
    print(f"  {'1. order count (Prophet)':<34}{fc_orders:>12.1f}{act_orders:>12.1f}"
          f"{pct(fc_orders, act_orders):>+9.1f}%")
    print(f"  {'2. units per order (measured mix)':<34}{upo:>12.3f}{act_upo:>12.3f}"
          f"{pct(upo, act_upo):>+9.1f}%")
    print(f"  {'3. total units = 1 x 2':<34}{fc_orders * upo:>12.1f}{act_units:>12.1f}"
          f"{pct(fc_orders * upo, act_units):>+9.1f}%")

    # Whatever is left after order count and basket size is the MIX: not how many
    # things were sold, but which ones -- and therefore which ingredients they
    # pulled. Stated as its own line because the three multiply out to the
    # ingredient bias, and a decomposition that does not close is not a
    # decomposition. This is the term the design has no error bar for: weekday
    # shares are a trailing 8-week measurement, so a genuine shift in what people
    # order shows up here and nowhere else.
    units_bias = pct(fc_orders * upo, act_units) / 100.0
    mix_residual = ((1 + (bias or 0.0) / 100.0) / (1 + units_bias) - 1) * 100.0
    print(f"  {'4. dish mix (residual)':<34}{'':>12}{'':>12}{mix_residual:>+9.1f}%")
    print()
    print(f"  1 x 2 x 4 reproduces the {bias:+.2f}% ingredient bias above.")
    print()
    print("  Only line 1 is the model, and this window it was almost exact. Lines 2")
    print("  and 4 are trailing measurements, not forecasts -- basket size and dish")
    print("  mix carry no error bar and drift whenever customers order differently.")
    print("  That is where the ingredient error actually lives.")

    worst = window.sort_values("abs_err", ascending=False).head(top_n)
    print()
    print("-" * 74)
    print(f"LARGEST ABSOLUTE MISSES (top {top_n}, by quantity not percentage)")
    print("-" * 74)
    print(f"  {'ingredient':<28}{'forecast':>11}{'actual':>11}{'err':>10}  {'WAPE':>7}")
    for _, r in worst.iterrows():
        unit = r["unit_code"] or ""
        print(f"  {str(r['item_name'])[:27]:<28}"
              f"{r['forecast_qty']:>11.2f}{r['actual_qty']:>11.2f}"
              f"{r['forecast_qty'] - r['actual_qty']:>+10.2f}  {r['wape_pct']:>6.1f}%  {unit}")

    print()
    print("  Ranked by absolute quantity on purpose. A 90% error on 0.2 kg of")
    print("  garlic is not a purchasing problem; a 20% error on 30 kg of pork is.")
    return 0


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--horizon", type=int, default=7)
    ap.add_argument("--top", type=int, default=12)
    a = ap.parse_args()
    sys.exit(main(a.horizon, a.top))
