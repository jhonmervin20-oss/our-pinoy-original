"""
forecast_service/engine/po_generation.py

Purchase-order trigger check, order sizing, supplier grouping, and
forecast-based shortage-day projection. Pure functions over plain
dicts/lists/pandas DataFrames -- no DB access, no HTTP; PHP is expected to
call these building blocks (or the equivalent logic ported to PHP) against
its own live stock/supplier data.
"""

from __future__ import annotations

import math

import pandas as pd


def check_trigger(available_stock: float, reorder_level: float) -> bool:
    """Today's reorder trigger: stock has fallen to or below the reorder level."""
    return available_stock <= reorder_level


def size_order(
    restock_target: float,
    available_stock: float,
    unit_type: str = "count",
) -> float:
    """
    Size a purchase order to bring stock up to restock_target -- i.e. order
    EXACTLY the forecast shortfall, in a quantity the unit can be bought in.

    raw = max(0, restock_target - available_stock)

    Supplier minimum/maximum order quantities were deliberately removed.
    Measured against this app's real sweep data, min_order_qty was binding on
    23 of 29 drafted lines (79%), inflating orders well above forecast demand
    (2.96 kg -> 5.00 kg); max_order_qty was binding on 0 of 29 and could only
    ever truncate an order BELOW restock_target, defeating the policy that
    computed it. Both made "forecast-driven purchasing" untrue in practice.

    Rounding is unit-aware rather than a blanket int() cast:
      - "count" (pcs)      -> ceil to a whole unit; you can't buy 33.27 bananas.
      - "weight"/"volume"  -> keep 3 decimals; 3.76 kg is genuinely orderable.
    A blanket ceil would re-introduce a smaller version of the same
    over-ordering on every kg/L item.

    Pack-size rounding was removed for the same reason as the supplier
    minimums: inventory_items.pack_size was NULL on all 52 items, so the
    branch had never once executed, while the engine and its UI advertised a
    rounding behaviour the system did not actually perform.

    Mirrors owner/includes/inventory_policy_functions.php::sizeAutoOrderQuantity();
    the two must not diverge.
    """
    qty = max(0.0, restock_target - available_stock)
    if qty <= 0.0:
        return 0.0

    return float(math.ceil(qty)) if unit_type == "count" else round(qty, 3)


def group_by_supplier(triggered_items: list[dict]) -> dict:
    """
    Group triggered reorder items by supplier so one PO can be generated
    per supplier. Each item dict must have at least a "supplier_id" key.

    Returns:
        dict mapping supplier_id -> list of that supplier's item dicts,
        in the order they were encountered.
    """
    grouped: dict = {}
    for item in triggered_items:
        supplier_id = item["supplier_id"]
        grouped.setdefault(supplier_id, []).append(item)
    return grouped


def project_shortage_breach_day(
    available_stock: float,
    forecast_df: pd.DataFrame,
    reorder_level: float,
    horizon_days: int,
) -> int | None:
    """
    Early-warning check, independent of check_trigger(): walks the
    forecast day by day and finds the first day the *projected* stock
    level (today's stock minus cumulative forecast demand) would fall to
    or below the reorder level, even if today's actual trigger is still
    false.

    projected_stock(t) = available_stock - cumsum(yhat)[t]   for t = 1..horizon_days

    Returns:
        The first t (1-indexed day number) where projected_stock(t) <=
        reorder_level, or None if the stock is projected to stay above the
        reorder level for the whole horizon.
    """
    rows = forecast_df.head(horizon_days).reset_index(drop=True)
    cumulative_demand = rows["yhat"].cumsum()

    for idx, cum_demand in enumerate(cumulative_demand):
        day = idx + 1
        projected_stock = available_stock - float(cum_demand)
        if projected_stock <= reorder_level:
            return day

    return None
