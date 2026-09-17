"""
forecasting/stages/s6_reorder.py

Stage 6 -- decide the order.

WHEN to reorder and HOW MUCH to buy are two independent questions, answered
by two independent numbers -- neither is derived from the other:

    WHEN   = the item's own configured reorder_level (typed on the
             Inventory item form, never computed here)
    HOW MUCH = this item's 7-day forecast demand + its safety stock,
             net of current stock and whatever open PO quantity actually
             qualifies

reorder_level is deliberately NOT lead-time-demand-plus-safety-stock.
inventory_items.reorder_level is a required field on the Inventory item
form (same page that owns critical_level and safety_stock_qty) and already
drives that page's own low-stock badge -- an owner typing "Beef: 8 kg" is
choosing that number on purpose. This stage used to overwrite it every run
with a computed figure, which silently discarded whatever the owner had
just typed. It no longer does; applyForecastReorderLevels() (the caller
downstream of this stage) has been told to stop writing it too.

The trigger check is a real day-by-day projection, not a single lead-time
window:

    Projected Stock(day) = Projected Stock(day-1)
                          + PO receipts landing on that day
                          - that day's forecast demand

An open PO only ever counts on the day it is actually expected -- never
assumed available from today. Walking the whole planning horizon (not just
the supplier's lead time) is what lets the system say "you're covered until
day 5, then short" instead of only ever answering for the next few days.
The first day the walked balance falls to or below reorder_level is the day
replenishment was due; a PO landing before that day pushes it back or
avoids it entirely, a PO landing after does not help.

Once triggered, sizing the order asks a different question -- not "what
gets me back over the line" but "what gets me through the whole horizon
plus a buffer":

    target_stock = 7-day forecast demand + safety_stock_qty
    suggested    = round_up(target_stock - on_hand - qualifying_incoming)

qualifying_incoming is only the PO quantity whose expected delivery date
actually falls inside the walked horizon -- a receipt landing after the
horizon does not offset today's shortfall, so it is not credited here
either. If the qualifying incoming quantity alone already meets
target_stock, no new PO is suggested (`suppressed_by_open_commitment`),
which is what prevents a duplicate order for something already on its way.

Supplier lead time is retained purely as reference data (it still names how
long a supplier takes, still feeds urgency), never as the trigger.

Every branch writes a row -- even "no action" -- so a buyer can always
answer "why did nothing happen for this item today?"
"""

from __future__ import annotations

from decimal import ROUND_CEILING, Decimal
from math import ceil

import pandas as pd

import config
from db import execute, read_sql


def item_policy() -> pd.DataFrame:
    """Everything the decision needs about each ingredient, in one read."""
    return read_sql(
        """
        SELECT
            i.item_id,
            i.item_name,
            i.lead_time_days,
            i.reorder_level,
            COALESCE(i.safety_stock_qty, 0) AS safety_stock_qty,
            i.preferred_supplier_id,
            i.last_purchase_cost,
            u.unit_code,
            u.unit_type,
            s.supplier_name,
            COALESCE(b.on_hand, 0)   AS on_hand,
            COALESCE(po.on_order, 0) AS on_order
        FROM inventory_items i
        JOIN unit_of_measures u   ON u.unit_id = i.base_unit_id
        LEFT JOIN suppliers s     ON s.supplier_id = i.preferred_supplier_id
        LEFT JOIN (
            SELECT item_id, SUM(quantity_remaining) AS on_hand
            FROM inventory_batches
            WHERE quantity_remaining > 0
            GROUP BY item_id
        ) b ON b.item_id = i.item_id
        LEFT JOIN (
            -- Total open PO quantity, ANY date -- informational only
            -- ("Incoming PO" total on the page). Which of it actually
            -- QUALIFIES toward covering a shortfall is decided per item,
            -- per day, in decide() below via open_po_receipts().
            SELECT poi.item_id,
                   SUM(poi.quantity_ordered - COALESCE(poi.quantity_received, 0)) AS on_order
            FROM purchase_order_items poi
            JOIN purchase_orders p ON p.po_id = poi.po_id
            WHERE p.status IN ('ordered', 'partially_received')
            GROUP BY poi.item_id
        ) po ON po.item_id = i.item_id
        WHERE i.is_active = 1
        """
    )


def open_po_receipts() -> pd.DataFrame:
    """
    Every open PO line's item_id, expected_delivery_date and outstanding
    quantity -- unfiltered by date. decide() below buckets each item's
    lines by day itself while walking that item's own horizon, rather than
    a SQL predicate deciding up front which dates "count". A line with no
    expected_delivery_date is excluded here (it can never land on a
    specific day of the walk), the same as before.
    """
    return read_sql(
        """
        SELECT poi.item_id, p.expected_delivery_date,
               (poi.quantity_ordered - COALESCE(poi.quantity_received, 0)) AS qty
        FROM purchase_order_items poi
        JOIN purchase_orders p ON p.po_id = poi.po_id
        WHERE p.status IN ('ordered', 'partially_received')
          AND p.expected_delivery_date IS NOT NULL
          AND (poi.quantity_ordered - COALESCE(poi.quantity_received, 0)) > 0
        """
    )


def round_up(qty: float, unit_type: str) -> float:
    """
    Round the order UP to something a supplier can actually measure out.

    `count` goes to a whole unit -- you cannot order 6.4 eggs. That is
    arithmetic, not a preference, which is why it is a constant rather than a
    setting.

    Weight and volume go to two decimals: 10 g, or 10 ml. Three decimals was
    false precision -- it asked for 2.724 kg of beef and 0.034 l of concentrate,
    quantities nobody weighs out at a market stall. Two decimals is the finest
    amount that can really be handed over, and rounding UP keeps the property
    that an order never lands short.

    This is NOT the old pack-size step returning. That rounded to 0.25 on an
    unverified assumption about the suppliers and inflated a 0.296 l order to
    0.500 l -- a 69% overbuy. Rounding up to 10 g adds at most 10 g.

    The value is settled to 6 decimals before the ceiling. Without that,
    binary floating point turns a clean 6.42 into 6.420000000000001, and
    rounding UP faithfully carries that phantom digit to 6.43 -- an order
    0.01 larger than the arithmetic actually asked for. 6 decimals is far
    below the 2 the answer is quantized to, so no real quantity moves.
    """
    settled = Decimal(str(round(qty, 6)))
    if (unit_type or "count").lower() == "count":
        return float(settled.quantize(Decimal("1"), rounding=ROUND_CEILING))
    return float(settled.quantize(Decimal("0.01"), rounding=ROUND_CEILING))


def window_sum(demand: pd.DataFrame, item_id: int, days: int) -> float:
    """
    Forecast demand for one ingredient over the next `days` days.

    Used today only for the informational lead_time_demand_qty reference
    figure -- the trigger and the target-stock sizing both walk the full
    horizon day by day in decide() instead of summing a window.
    """
    rows = demand[demand["item_id"] == item_id].sort_values("ds").head(max(0, days))
    return float(rows["overlaid_qty"].sum()) if not rows.empty else 0.0


def decide(policy: pd.DataFrame, demand: pd.DataFrame, receipts: pd.DataFrame,
           conversion_gap_items: set[int], horizon_days: int | None = None) -> pd.DataFrame:
    """The five branches from section 7.4, in order. Nothing falls through."""
    horizon = max(1, int(horizon_days or config.get_int("forecast_horizon_days")))
    results = []

    for p in policy.itertuples():
        item_id = int(p.item_id)
        lead = int(p.lead_time_days or 1)
        unit_type = p.unit_type or "count"
        reorder_level = float(p.reorder_level or 0)
        safety_stock_qty = float(p.safety_stock_qty or 0)
        on_hand = float(p.on_hand or 0)
        on_order = float(p.on_order or 0)

        # This item's own daily forecast, in date order, for the horizon.
        daily = list(
            demand[demand["item_id"] == item_id]
            .sort_values("ds")
            .head(horizon)[["ds", "overlaid_qty"]]
            .itertuples(index=False)
        )
        horizon_demand = sum(float(d.overlaid_qty) for d in daily)

        # This item's open PO lines, bucketed by expected delivery date.
        # Keyed by a plain "YYYY-MM-DD" string rather than the raw value --
        # expected_delivery_date comes back from read_sql() as a plain
        # datetime.date, while demand["ds"] is a pandas Timestamp (it
        # originates from Prophet's own output upstream); comparing those
        # two types directly is exactly the kind of mismatch that matches
        # nothing and fails silently, so both sides are normalised to the
        # same string before ever being used as a dict key.
        item_receipts = receipts[receipts["item_id"] == item_id]
        receipts_by_date: dict = {}
        for r in item_receipts.itertuples(index=False):
            key = r.expected_delivery_date.strftime("%Y-%m-%d")
            receipts_by_date[key] = receipts_by_date.get(key, 0.0) + float(r.qty)

        # -- Day-by-day projection over the planning horizon ---------------
        # A receipt only ever credits the balance on its own expected day;
        # it is never pulled forward to cover an earlier shortfall. The
        # first day the balance reaches or falls below THIS ITEM'S OWN
        # reorder_level is when replenishment was due.
        balance = on_hand
        reorder_hit_day = None
        qualifying_incoming = 0.0
        for day_number, row in enumerate(daily, start=1):
            receipt = receipts_by_date.get(row.ds.strftime("%Y-%m-%d"), 0.0)
            qualifying_incoming += receipt
            balance = balance + receipt - float(row.overlaid_qty)
            if reorder_hit_day is None and balance <= reorder_level:
                reorder_hit_day = day_number
        projected_stock = balance  # end-of-horizon position
        triggered = reorder_hit_day is not None

        # -- HOW MUCH: independent of the trigger above ---------------------
        target_stock = horizon_demand + safety_stock_qty
        if unit_type == "count":
            target_stock = float(ceil(target_stock))

        raw = max(0.0, target_stock - on_hand - qualifying_incoming) if triggered else 0.0
        rounded = round_up(raw, unit_type) if raw > 0 else 0.0
        final = rounded

        if item_id in conversion_gap_items:
            decision, reason, urgency = "skipped_conversion_gap", \
                "Recipe unit cannot be converted to this item's base unit.", "none"
        elif p.preferred_supplier_id is None or pd.isna(p.preferred_supplier_id):
            decision, reason, urgency = "flagged_no_supplier", \
                "No preferred supplier set, so this cannot be ordered automatically.", "none"
        elif final <= 0:
            # Nothing to buy either way, but WHY differs and the row should
            # say which: an item whose reorder_level sits above what the
            # 7-day-demand-plus-safety target actually needs can cross that
            # level (triggered=True) purely because on-hand stock alone
            # already satisfies the target -- no PO involved at all, so that
            # is genuinely "Sufficient Stock", not "covered by a PO" that
            # does not exist. Only when a qualifying incoming quantity is
            # actually part of what brought this to zero does it get the PO
            # reason.
            decision = "no_action"
            reason = ("Covered by an existing purchase order."
                      if triggered and qualifying_incoming > 0 else None)
            urgency = "none"
        else:
            decision, reason = "drafted", None
            # Already at/under the reorder level TODAY, with nothing
            # incoming to help before then, versus a dip the walk sees
            # coming later in the week.
            urgency = "critical" if reorder_hit_day == 1 else "normal"

        results.append({
            "item_id": item_id,
            "item_name": p.item_name,
            "supplier_id": None if pd.isna(p.preferred_supplier_id) else int(p.preferred_supplier_id),
            "supplier_name": p.supplier_name,
            "unit_code": p.unit_code,
            "unit_type": unit_type,
            "lead_time_days": lead,
            "on_hand": on_hand,
            "on_order": on_order,
            "available": on_hand + on_order,
            # The forecast demand actually used for sizing: the full
            # planning horizon, not a lead-time window.
            "forecast_demand_qty": horizon_demand,
            "projected_stock": projected_stock,
            # Reference only -- see window_sum()'s docstring.
            "lead_time_demand_qty": window_sum(demand, item_id, lead),
            "safety_stock_qty": safety_stock_qty,
            # The item's OWN configured value, never computed here.
            "reorder_level": reorder_level,
            "restock_target": target_stock,
            "coverage_days": horizon,
            "raw_suggested_qty": raw,
            "rounded_order_qty": rounded,
            "final_purchase_qty": final,
            "decision": decision,
            "urgency": urgency,
            "gated_reason": reason,
            "suppressed_by_open_commitment": 1 if (decision == "no_action" and triggered and qualifying_incoming > 0) else 0,
            "unit_cost": float(p.last_purchase_cost or 0),
        })

    return pd.DataFrame(results)


def run(run_id: int, demand: pd.DataFrame, conversion_gap_items: set[int],
        training_end: str | None = None) -> tuple[pd.DataFrame, dict]:
    policy = item_policy()
    receipts = open_po_receipts()
    out = decide(policy, demand, receipts, conversion_gap_items)

    meta = {
        "items_evaluated": int(len(out)),
        "driven_by": "each item's configured reorder level",
        "decisions": out["decision"].value_counts().to_dict(),
        "critical": int((out["urgency"] == "critical").sum()),
    }
    return out, meta


def persist(run_id: int, suggestions: pd.DataFrame) -> int:
    if suggestions.empty:
        return 0
    rows = [
        {
            "run_id": run_id,
            "item_id": int(r.item_id),
            "on_hand_qty": round(r.on_hand, 3),
            "on_order_qty": round(r.on_order, 3),
            "available_stock": round(r.available, 3),
            "forecast_demand_qty": round(r.forecast_demand_qty, 3),
            "projected_stock_qty": round(r.projected_stock, 3),
            "lead_time_demand_qty": round(r.lead_time_demand_qty, 3),
            "safety_stock_qty": round(r.safety_stock_qty, 3),
            "reorder_level": round(r.reorder_level, 3),
            "restock_target": round(r.restock_target, 3),
            "coverage_days": int(r.coverage_days),
            "raw_suggested_qty": round(r.raw_suggested_qty, 3),
            "rounded_order_qty": round(r.rounded_order_qty, 3),
            "final_suggested_qty": round(r.rounded_order_qty, 3),
            "final_purchase_qty": round(r.final_purchase_qty, 3),
            "has_conversion_gap": 1 if r.decision == "skipped_conversion_gap" else 0,
            "suppressed_by_open_commitment": int(r.suppressed_by_open_commitment),
            "decision": r.decision,
            "urgency": r.urgency,
            "gated_reason": r.gated_reason,
        }
        for r in suggestions.itertuples()
    ]
    execute(
        """
        INSERT INTO reorder_suggestions
            (run_id, item_id, on_hand_qty, on_order_qty, available_stock,
             forecast_demand_qty, projected_stock_qty,
             lead_time_demand_qty, safety_stock_qty,
             reorder_level, restock_target, coverage_days,
             raw_suggested_qty, rounded_order_qty, final_suggested_qty, final_purchase_qty,
             has_conversion_gap, suppressed_by_open_commitment, decision, urgency, gated_reason)
        VALUES
            (:run_id, :item_id, :on_hand_qty, :on_order_qty, :available_stock,
             :forecast_demand_qty, :projected_stock_qty,
             :lead_time_demand_qty, :safety_stock_qty,
             :reorder_level, :restock_target, :coverage_days,
             :raw_suggested_qty, :rounded_order_qty, :final_suggested_qty, :final_purchase_qty,
             :has_conversion_gap, :suppressed_by_open_commitment, :decision, :urgency, :gated_reason)
        """,
        rows,
    )
    return len(rows)
