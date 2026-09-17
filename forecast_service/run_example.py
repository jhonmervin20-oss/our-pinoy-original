"""
forecast_service/run_example.py

Standalone, runnable demonstration of the full demand-classification +
inventory-policy engine (engine/data_prep.py -> engine/forecasting.py ->
engine/inventory_policy.py -> engine/po_generation.py) end to end.

SYNTHETIC DATA, on purpose: this app's real historical sales/inventory
data is too sparse (short history, few SKUs with enough volume) to be a
defensible demo of three distinct velocity classes side by side. This
script generates a small synthetic order-history + recipe (BOM) dataset
for 5 ingredients with deliberately different velocity profiles so that
all three demand_classification branches (fast / medium / slow) actually
get exercised, then walks each one through the full pipeline and prints a
human-readable summary, ending with the purchase orders the engine would
generate, grouped by supplier.

Run from the activated venv:
    python run_example.py
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from engine import data_prep, forecasting, inventory_policy, po_generation

RNG_SEED = 42
HISTORY_DAYS = 60
FORECAST_HORIZON_DAYS = 7
LEAD_TIME_DAYS = 3


# ---------------------------------------------------------------------------
# 1. Synthetic menu items, each using exactly one ingredient (kept 1:1 for
#    a readable demo -- the BOM math in data_prep.py handles many-menu-
#    items-per-ingredient the same way, only the recipe table would grow).
# ---------------------------------------------------------------------------

INGREDIENTS = {
    101: "Chicken (kg)",
    102: "Pork Belly (kg)",
    103: "Squid (kg)",
    104: "Beef Chuck (kg)",
    105: "Truffle Oil (bottle)",
}

# menu_item_id -> (name, ingredient_item_id, quantity_required)
# quantity_required is what one serving consumes.
MENU_ITEMS = {
    1: ("Chicken Adobo", 101, 0.5),      # 0.5 kg chicken per serving
    2: ("Pork Sinigang", 102, 0.5),      # 0.5 kg pork per serving
    3: ("Calamares", 103, 0.3),          # 0.3 kg squid per serving
    4: ("Beef Kaldereta", 104, 0.4),     # 0.4 kg beef per serving
    5: ("Truffle Pasta", 105, 0.05),     # 0.05 bottle truffle oil per serving
}

# Ingredient -> supplier, for the supplier-grouped PO step.
SUPPLIER_OF = {
    101: "SUP-MEAT-A",
    102: "SUP-MEAT-A",
    103: "SUP-SEAFOOD-B",
    104: "SUP-MEAT-A",
    105: "SUP-SPECIALTY-C",
}

# Per-ingredient current stock, pack size, and order-qty bounds, for the
# po_generation step. Deliberately set so some ingredients are already at
# or below their reorder level (should trigger) and some are not.
STOCK_STATE = {
    101: {"available_stock": 8.0, "unit_type": "count"},
    102: {"available_stock": 25.0, "unit_type": "count"},
    103: {"available_stock": 3.0, "unit_type": "weight"},
    104: {"available_stock": 15.0, "unit_type": "weight"},
    105: {"available_stock": 1.0, "unit_type": "count"},
}

# Velocity profile per menu item: (sell_probability_per_day, poisson_lambda_when_sold)
# probability of 1.0 -> effectively "fast" (sold basically every day)
# probability around 0.45-0.55 -> "medium"
# probability under 0.2 -> "slow"
VELOCITY_PROFILE = {
    1: {"p_sell": 0.95, "lam": 9},   # Chicken Adobo -> fast
    2: {"p_sell": 0.90, "lam": 6},   # Pork Sinigang -> fast
    3: {"p_sell": 0.50, "lam": 5},   # Calamares -> medium
    4: {"p_sell": 0.40, "lam": 4},   # Beef Kaldereta -> medium
    5: {"p_sell": 0.15, "lam": 2},   # Truffle Pasta -> slow
}


def generate_synthetic_order_items(rng: np.random.Generator) -> pd.DataFrame:
    """
    Build a synthetic order_items_df with columns [date, menu_item_id,
    quantity], already aggregated to daily grain, covering the last
    HISTORY_DAYS days. Realistically, a day with zero sales of a menu
    item simply has no row (mirrors how an order_items table works) --
    data_prep.build_ingredient_daily_series() is responsible for
    dense-filling the gaps with 0.
    """
    end_date = pd.Timestamp("2026-07-30")
    start_date = end_date - pd.Timedelta(days=HISTORY_DAYS - 1)
    dates = pd.date_range(start_date, end_date, freq="D")

    rows = []
    for menu_item_id, profile in VELOCITY_PROFILE.items():
        for d in dates:
            # Small weekend bump so Prophet's weekly seasonality has
            # something real to pick up on for the fast/medium items.
            weekend_bump = 1.3 if d.dayofweek >= 5 else 1.0
            if rng.random() < profile["p_sell"]:
                qty = rng.poisson(lam=profile["lam"] * weekend_bump)
                if qty > 0:
                    rows.append({"date": d, "menu_item_id": menu_item_id, "quantity": float(qty)})

    return pd.DataFrame(rows), start_date, end_date


def build_menu_item_ingredients_df() -> pd.DataFrame:
    rows = []
    for menu_item_id, (_name, ingredient_item_id, qty_required) in MENU_ITEMS.items():
        rows.append({
            "menu_item_id": menu_item_id,
            "ingredient_item_id": ingredient_item_id,
            "quantity_required": qty_required,
        })
    return pd.DataFrame(rows)


def main():
    rng = np.random.default_rng(RNG_SEED)

    order_items_df, start_date, end_date = generate_synthetic_order_items(rng)
    menu_item_ingredients_df = build_menu_item_ingredients_df()

    print("=" * 78)
    print("DEMAND CLASSIFICATION + INVENTORY POLICY ENGINE -- synthetic demo")
    print(f"History window: {start_date.date()} .. {end_date.date()} "
          f"({HISTORY_DAYS} days) | forecast horizon: {FORECAST_HORIZON_DAYS} days "
          f"| lead time: {LEAD_TIME_DAYS} days")
    print("=" * 78)

    triggered_items = []
    all_results = {}

    for ingredient_item_id, ingredient_name in INGREDIENTS.items():
        daily_series = data_prep.build_ingredient_daily_series(
            order_items_df, menu_item_ingredients_df, ingredient_item_id, start_date, end_date
        )

        classification, forecast_df = forecasting.forecast_demand(daily_series, FORECAST_HORIZON_DAYS)

        if classification["demand_pattern"] == "slow":
            model_used = "trailing_average"
            trailing_weekly_avg = float(daily_series.mean()) * 7.0
            policy = inventory_policy.compute_policy(
                forecast_df, "slow", LEAD_TIME_DAYS, FORECAST_HORIZON_DAYS,
                trailing_weekly_avg=trailing_weekly_avg,
            )
        else:
            model_used = "prophet"
            policy = inventory_policy.compute_policy(
                forecast_df, classification["demand_pattern"], LEAD_TIME_DAYS, FORECAST_HORIZON_DAYS,
            )

        stock_state = STOCK_STATE[ingredient_item_id]
        available_stock = stock_state["available_stock"]

        triggered = po_generation.check_trigger(available_stock, policy["reorder_level"])
        breach_day = po_generation.project_shortage_breach_day(
            available_stock, forecast_df, policy["reorder_level"], FORECAST_HORIZON_DAYS
        )
        order_qty = po_generation.size_order(
            policy["restock_target"], available_stock,
            stock_state.get("unit_type", "count"),
        )

        all_results[ingredient_item_id] = {
            "name": ingredient_name,
            "classification": classification,
            "model_used": model_used,
            "forecast_df": forecast_df,
            "policy": policy,
            "available_stock": available_stock,
            "triggered": triggered,
            "breach_day": breach_day,
            "order_qty": order_qty,
        }

        print(f"\n--- {ingredient_name} (ingredient_item_id={ingredient_item_id}) ---")
        print(f"  Demand pattern     : {classification['demand_pattern'].upper()}  "
              f"(nonzero_day_ratio={classification['nonzero_day_ratio']:.2f}, "
              f"model={model_used}, interval_width={classification['interval_width']})")
        print(f"  Forecast (first 3d): "
              + ", ".join(f"day{int(r.day)}={r.yhat:.2f} [{r.yhat_lower:.2f}-{r.yhat_upper:.2f}]"
                           for r in forecast_df.head(3).itertuples()))
        print(f"  Policy             : lead_time_demand={policy['lead_time_demand_qty']:.2f}  "
              f"reorder_level={policy['reorder_level']:.2f}  "
              f"review_demand={policy['review_period_demand_qty']:.2f}  "
              f"restock_target={policy['restock_target']:.2f}  "
              f"safety_stock={policy['safety_stock_qty']:.2f}")
        print(f"  Stock check        : available_stock={available_stock:.2f}  "
              f"trigger_reorder={triggered}  "
              f"projected_shortage_breach_day={breach_day if breach_day is not None else 'none within horizon'}")

        if triggered:
            triggered_items.append({
                "ingredient_item_id": ingredient_item_id,
                "ingredient_name": ingredient_name,
                "supplier_id": SUPPLIER_OF[ingredient_item_id],
                "order_qty": order_qty,
            })
            print(f"  >>> PO TRIGGERED   : order_qty={order_qty} "
                  f"(supplier={SUPPLIER_OF[ingredient_item_id]})")
        else:
            print(f"  No PO triggered today (would order {order_qty} units if it were).")

    # -----------------------------------------------------------------
    # Supplier-grouped purchase orders.
    # -----------------------------------------------------------------
    print("\n" + "=" * 78)
    print("PURCHASE ORDERS TO GENERATE (grouped by supplier)")
    print("=" * 78)

    if not triggered_items:
        print("No ingredients triggered a reorder today.")
    else:
        grouped = po_generation.group_by_supplier(triggered_items)
        for supplier_id, items in grouped.items():
            print(f"\nPO for supplier {supplier_id}:")
            for item in items:
                print(f"  - {item['ingredient_name']:<24} qty={item['order_qty']}")

    # -----------------------------------------------------------------
    # Synthetic shortage scenario: manually drop one fast-mover's stock
    # to demonstrate the early-warning breach-day projection even when
    # today's trigger is still (barely) false.
    # -----------------------------------------------------------------
    print("\n" + "=" * 78)
    print("SYNTHETIC SHORTAGE SCENARIO -- Chicken stock drops to 30% of normal")
    print("=" * 78)

    chicken = all_results[101]
    reduced_stock = chicken["available_stock"] * 0.3
    reduced_triggered = po_generation.check_trigger(reduced_stock, chicken["policy"]["reorder_level"])
    reduced_breach_day = po_generation.project_shortage_breach_day(
        reduced_stock, chicken["forecast_df"], chicken["policy"]["reorder_level"], FORECAST_HORIZON_DAYS
    )
    print(f"  available_stock={reduced_stock:.2f} (reorder_level={chicken['policy']['reorder_level']:.2f})")
    print(f"  trigger_reorder={reduced_triggered}")
    print(f"  projected_shortage_breach_day="
          f"{reduced_breach_day if reduced_breach_day is not None else 'none within horizon'}")

    print("\nDone.\n")


if __name__ == "__main__":
    main()
