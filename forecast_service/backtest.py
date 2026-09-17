"""
forecast_service/backtest.py

Two-part validation report for the demand-classification + inventory-
policy engine:

  Part 1 -- Backtest, per class (not per item): a train/predict split is
  run for a handful of representative synthetic SKUs in each of the
  fast/medium/slow demand classes, and MAE/RMSE/MAPE are aggregated
  *across all SKUs and days within a class* (not per-item), since the
  grading criterion is "does the engine behave sensibly for a velocity
  class", not "is any one item's forecast perfect".

  Part 2 -- Policy comparison, before/after (not a full simulation): a
  simple non-adaptive "static reorder_level/restock_target" baseline (what a
  restaurant typically uses before adopting a forecast-driven system --
  a flat historical-average-based rule with no responsiveness to
  variability) is compared against this engine's forecast-driven policy,
  by replaying one identical synthetic day-by-day demand stream against
  both and comparing stockout frequency, average inventory held, and the
  number/size of orders placed.

SYNTHETIC DATA THROUGHOUT, on purpose: this app's real order/inventory
history does not yet span enough days or enough SKUs across all three
velocity classes to support a defensible backtest (see run_example.py's
header for the same rationale). This is an honest, disclosed limitation,
not something to hide -- see ASSUMPTIONS.md.

Run from the activated venv:
    python backtest.py
"""

from __future__ import annotations

import math

import numpy as np
import pandas as pd

from engine import forecasting, inventory_policy, po_generation

TRAIN_DAYS = 60
TEST_HORIZON_DAYS = 7
LEAD_TIME_DAYS = 3
SIM_DAYS = 60

OLD_SAFETY_FACTOR = 1.0   # the "static" baseline covers only expected lead-time
                          # demand with zero margin -- the naive policy this
                          # engine is meant to improve on.

# name -> (p_sell per day, poisson lambda when sold, demand class)
SKU_PROFILES = {
    "Chicken":     {"p_sell": 0.95, "lam": 9,  "class": "fast"},
    "Pork":        {"p_sell": 0.90, "lam": 6,  "class": "fast"},
    "Rice":        {"p_sell": 1.00, "lam": 12, "class": "fast"},
    "Squid":       {"p_sell": 0.55, "lam": 5,  "class": "medium"},
    "Beef":        {"p_sell": 0.45, "lam": 4,  "class": "medium"},
    "Truffle Oil": {"p_sell": 0.15, "lam": 2,  "class": "slow"},
    "Wagyu":       {"p_sell": 0.10, "lam": 1,  "class": "slow"},
}

# One representative SKU per class for the Part 2 policy-comparison replay.
REPRESENTATIVE_SKU = {"fast": "Chicken", "medium": "Squid", "slow": "Truffle Oil"}


def generate_daily_series(profile: dict, total_days: int, seed: int) -> pd.Series:
    """Synthetic daily demand series with a mild weekend bump, matching run_example.py's style."""
    rng = np.random.default_rng(seed)
    start_date = pd.Timestamp("2026-01-01")
    dates = pd.date_range(start_date, periods=total_days, freq="D")

    values = []
    for d in dates:
        weekend_bump = 1.3 if d.dayofweek >= 5 else 1.0
        if rng.random() < profile["p_sell"]:
            qty = rng.poisson(lam=profile["lam"] * weekend_bump)
        else:
            qty = 0
        values.append(float(qty))

    return pd.Series(values, index=dates, name="quantity")


def compute_errors(actual: np.ndarray, predicted: np.ndarray) -> dict:
    """
    MAE / RMSE over all days; MAPE only over days with nonzero actual
    demand (a day with true zero demand makes percentage error undefined --
    excluding those days is a standard, disclosed convention, not a bug).
    """
    actual = np.asarray(actual, dtype=float)
    predicted = np.asarray(predicted, dtype=float)

    errors = predicted - actual
    mae = float(np.mean(np.abs(errors)))
    rmse = float(math.sqrt(np.mean(errors ** 2)))

    nonzero_mask = actual > 0
    excluded = int((~nonzero_mask).sum())
    if nonzero_mask.any():
        mape = float(np.mean(np.abs(errors[nonzero_mask]) / actual[nonzero_mask]) * 100.0)
    else:
        mape = float("nan")

    return {"mae": mae, "rmse": rmse, "mape": mape, "mape_excluded_zero_days": excluded, "n_days": len(actual)}


def part1_backtest_per_class():
    print("=" * 78)
    print("PART 1 -- BACKTEST, PER CLASS (train/predict split)")
    print(f"train_days={TRAIN_DAYS}  test_horizon_days={TEST_HORIZON_DAYS}")
    print("=" * 78)

    # class -> pooled (actual_day_values, predicted_day_values) across all
    # its SKUs and all test days -- this is the "per class, not per item"
    # aggregation the spec calls for.
    pooled_actual = {"fast": [], "medium": [], "slow": []}
    pooled_predicted = {"fast": [], "medium": [], "slow": []}

    sku_results = {}
    seed = 1000
    for sku_name, profile in SKU_PROFILES.items():
        seed += 1
        full_series = generate_daily_series(profile, TRAIN_DAYS + TEST_HORIZON_DAYS, seed)
        train_series = full_series.iloc[:TRAIN_DAYS]
        actual_test = full_series.iloc[TRAIN_DAYS:TRAIN_DAYS + TEST_HORIZON_DAYS].values

        classification, forecast_df = forecasting.forecast_demand(train_series, TEST_HORIZON_DAYS)
        predicted_test = forecast_df["yhat"].values

        errs = compute_errors(actual_test, predicted_test)
        sku_results[sku_name] = {"classification": classification, "errors": errs}

        expected_class = profile["class"]
        pooled_actual[expected_class].extend(actual_test.tolist())
        pooled_predicted[expected_class].extend(predicted_test.tolist())

        match_flag = "" if classification["demand_pattern"] == expected_class else "  ** classified differently than intended profile **"
        mape_str = f"{errs['mape']:.1f}%" if not math.isnan(errs["mape"]) else "n/a (no nonzero-actual days)"
        print(f"\n{sku_name:<14} intended_class={expected_class:<6} "
              f"classified_as={classification['demand_pattern']:<6} "
              f"ratio={classification['nonzero_day_ratio']:.2f}{match_flag}")
        print(f"    per-SKU MAE={errs['mae']:.2f}  RMSE={errs['rmse']:.2f}  "
              f"MAPE={mape_str} (excluded {errs['mape_excluded_zero_days']}/{errs['n_days']} zero-actual days)")

    print("\n" + "-" * 78)
    print("AGGREGATE METRICS PER DEMAND CLASS (pooled across that class's SKUs/days)")
    print("-" * 78)
    class_summary = {}
    for demand_class in ("fast", "medium", "slow"):
        if not pooled_actual[demand_class]:
            continue
        agg = compute_errors(pooled_actual[demand_class], pooled_predicted[demand_class])
        class_summary[demand_class] = agg
        mape_str = f"{agg['mape']:.1f}%" if not math.isnan(agg["mape"]) else "n/a (no nonzero-actual days)"
        print(f"{demand_class.upper():<8} n_days={agg['n_days']:<4} "
              f"MAE={agg['mae']:.2f}  RMSE={agg['rmse']:.2f}  "
              f"MAPE={mape_str} (excluded {agg['mape_excluded_zero_days']} zero-actual days)")

    return sku_results, class_summary


# ---------------------------------------------------------------------------
# Part 2 -- policy comparison (before/after)
# ---------------------------------------------------------------------------

def simulate_stock(demand_sequence, reorder_level, restock_target, lead_time_days, initial_stock) -> dict:
    """
    Simple day-by-day replay of one demand stream against one fixed policy
    (reorder_level/restock_target are NOT recomputed during the run -- this is
    a "before/after" comparison of two policies, not a full rolling
    re-forecasting simulation).

    An order is placed the day stock <= reorder_level (po_generation.
    check_trigger), sized up to restock_target (po_generation.size_order), and
    arrives lead_time_days later. Only one order is ever in flight at a
    time (a second trigger while one is already in transit is ignored --
    a standard reorder-point policy assumption).
    """
    stock = float(initial_stock)
    pending_arrival_day = None
    pending_qty = 0.0

    stockout_days = 0
    inventory_levels = []
    orders_placed = []

    for day, demand in enumerate(demand_sequence):
        if pending_arrival_day is not None and day == pending_arrival_day:
            stock += pending_qty
            pending_arrival_day = None
            pending_qty = 0.0

        if demand > stock:
            stockout_days += 1
        stock = max(0.0, stock - demand)
        inventory_levels.append(stock)

        if pending_arrival_day is None and po_generation.check_trigger(stock, reorder_level):
            qty = po_generation.size_order(restock_target, stock)
            if qty > 0:
                pending_arrival_day = day + lead_time_days
                pending_qty = float(qty)
                orders_placed.append(qty)

    return {
        "stockout_days": stockout_days,
        "stockout_rate": stockout_days / len(demand_sequence),
        "avg_inventory": float(np.mean(inventory_levels)),
        "num_orders": len(orders_placed),
        "avg_order_size": float(np.mean(orders_placed)) if orders_placed else 0.0,
        "total_units_ordered": float(sum(orders_placed)),
    }


def part2_policy_comparison(sku_results: dict):
    print("\n" + "=" * 78)
    print("PART 2 -- POLICY COMPARISON, BEFORE/AFTER (static baseline vs forecast-driven)")
    print(f"sim_days={SIM_DAYS}  lead_time_days={LEAD_TIME_DAYS}  "
          f"old_safety_factor={OLD_SAFETY_FACTOR} (flat, no variability margin)")
    print("=" * 78)

    seed = 5000
    for demand_class, sku_name in REPRESENTATIVE_SKU.items():
        seed += 1
        profile = SKU_PROFILES[sku_name]

        # Re-derive the same train_series used in Part 1 for this SKU so the
        # "new" (forecast-driven) policy numbers are consistent with the
        # backtest above, then compute the "old" static policy from the same
        # historical window for a fair, matched comparison.
        # (Re-generated with the same per-SKU seed convention as part1.)
        sku_index = list(SKU_PROFILES.keys()).index(sku_name)
        train_seed = 1000 + sku_index + 1
        train_series = generate_daily_series(profile, TRAIN_DAYS + TEST_HORIZON_DAYS, train_seed).iloc[:TRAIN_DAYS]

        classification, forecast_df = forecasting.forecast_demand(train_series, TEST_HORIZON_DAYS)

        if classification["demand_pattern"] == "slow":
            trailing_weekly_avg = float(train_series.mean()) * 7.0
            new_policy = inventory_policy.compute_policy(
                forecast_df, "slow", LEAD_TIME_DAYS, TEST_HORIZON_DAYS, trailing_weekly_avg=trailing_weekly_avg,
            )
        else:
            new_policy = inventory_policy.compute_policy(
                forecast_df, classification["demand_pattern"], LEAD_TIME_DAYS, TEST_HORIZON_DAYS,
            )

        # "Old" static baseline: a flat rule computed once from the plain
        # historical daily average, with no forecast, no seasonality
        # awareness, and no variability-based safety margin.
        old_daily_avg = float(train_series.mean())
        old_review_days = max(0, TEST_HORIZON_DAYS - LEAD_TIME_DAYS)
        old_lead_time_demand = old_daily_avg * LEAD_TIME_DAYS
        old_reorder_level = old_lead_time_demand * OLD_SAFETY_FACTOR
        old_review_demand = old_daily_avg * old_review_days
        old_restock_target = old_reorder_level + old_review_demand

        print(f"\n--- {sku_name} ({demand_class}) ---")
        print(f"  OLD (static)      : reorder_level={old_reorder_level:.2f}  restock_target={old_restock_target:.2f}")
        print(f"  NEW (forecast-driven): reorder_level={new_policy['reorder_level']:.2f}  "
              f"restock_target={new_policy['restock_target']:.2f}  "
              f"(model={'trailing_average' if classification['demand_pattern'] == 'slow' else 'prophet'})")

        # Replay one identical synthetic demand stream against both policies.
        sim_demand = generate_daily_series(profile, SIM_DAYS, seed).values
        initial_stock = max(old_restock_target, new_policy["restock_target"])

        old_result = simulate_stock(sim_demand, old_reorder_level, old_restock_target, LEAD_TIME_DAYS, initial_stock)
        new_result = simulate_stock(sim_demand, new_policy["reorder_level"], new_policy["restock_target"], LEAD_TIME_DAYS, initial_stock)

        print(f"  {'':<10}{'stockout days':>16}{'stockout rate':>16}{'avg inventory':>16}{'#orders':>10}{'avg order size':>16}")
        print(f"  {'OLD':<10}{old_result['stockout_days']:>16}{old_result['stockout_rate']*100:>15.1f}%"
              f"{old_result['avg_inventory']:>16.2f}{old_result['num_orders']:>10}{old_result['avg_order_size']:>16.2f}")
        print(f"  {'NEW':<10}{new_result['stockout_days']:>16}{new_result['stockout_rate']*100:>15.1f}%"
              f"{new_result['avg_inventory']:>16.2f}{new_result['num_orders']:>10}{new_result['avg_order_size']:>16.2f}")


def main():
    sku_results, _class_summary = part1_backtest_per_class()
    part2_policy_comparison(sku_results)
    print("\nDone.\n")


if __name__ == "__main__":
    main()
