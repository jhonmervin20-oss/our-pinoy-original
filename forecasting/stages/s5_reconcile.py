"""
forecasting/stages/s5_reconcile.py

Stage 5 -- reconcile the forecast with what is already known.

Two things beat a forecast.

An advance order is a promise, not a prediction, so it sets a FLOOR rather than
being added. A guest who pre-ordered sisig would probably have ordered something
anyway; adding the booking on top of the forecast counts the same guest twice.
The floor says "at least this much, whatever the model thinks."

Trend Setter deltas are added on top, as a percentage of the forecast -- never of
the floor, because a promise does not become larger because a dish is trending.

    overlaid_qty = GREATEST(0, max(predicted_qty, committed_floor_qty) + adjustment_delta_qty)
"""

from __future__ import annotations

import pandas as pd

from db import execute, read_sql


def committed_floor(factors: pd.DataFrame, start: str, end: str) -> pd.DataFrame:
    """
    Advance orders exploded through the SAME recipe factors stage 4 used, so a
    booked serving and a forecast serving consume identical ingredients.

    Cancelled and no-show reservations are excluded -- those are not promises
    any more.
    """
    booked = read_sql(
        """
        SELECT r.reservation_date AS ds,
               rao.menu_item_id,
               SUM(rao.quantity)  AS servings
        FROM reservation_advance_orders rao
        JOIN reservations r ON r.reservation_id = rao.reservation_id
        WHERE r.reservation_date BETWEEN :a AND :b
          AND r.status NOT IN ('cancelled', 'no_show')
        GROUP BY r.reservation_date, rao.menu_item_id
        """,
        {"a": start, "b": end},
    )
    if booked.empty or factors.empty:
        return pd.DataFrame(columns=["item_id", "ds", "committed_floor_qty"])

    booked["ds"] = pd.to_datetime(booked["ds"])
    usable = factors[factors["convertible"]]
    merged = booked.merge(usable, on="menu_item_id", how="inner")
    merged["committed_floor_qty"] = (
        merged["servings"].astype(float) * merged["per_unit_required"].astype(float)
    )
    return (
        merged.groupby(["item_id", "ds"], as_index=False)["committed_floor_qty"].sum()
    )


def adjustment_deltas(totals: pd.DataFrame) -> pd.DataFrame:
    """
    Active demand_adjustments, resolved to a signed quantity per ingredient-day.

    Scoped either to one ingredient directly, or to a dish -- in which case the
    delta applies to whatever that dish contributes. Empty until Trend Setter
    runs, which is the case today (0 rows).
    """
    if totals.empty:
        return pd.DataFrame(columns=["item_id", "ds", "adjustment_delta_qty"])

    adj = read_sql(
        """
        SELECT adjustment_id, menu_item_id, inventory_item_id, delta_pct,
               effective_from, effective_to
        FROM demand_adjustments
        WHERE is_active = 1
        """
    )
    if adj.empty:
        return pd.DataFrame(columns=["item_id", "ds", "adjustment_delta_qty"])

    adj["effective_from"] = pd.to_datetime(adj["effective_from"])
    adj["effective_to"] = pd.to_datetime(adj["effective_to"])

    rows = []
    for r in adj.itertuples():
        window = totals[(totals["ds"] >= r.effective_from) & (totals["ds"] <= r.effective_to)]
        if pd.notna(r.inventory_item_id):
            window = window[window["item_id"] == int(r.inventory_item_id)]
        if window.empty:
            continue
        scaled = window.copy()
        scaled["adjustment_delta_qty"] = (
            scaled["predicted_qty"].astype(float) * (float(r.delta_pct) / 100.0)
        )
        rows.append(scaled[["item_id", "ds", "adjustment_delta_qty"]])

    if not rows:
        return pd.DataFrame(columns=["item_id", "ds", "adjustment_delta_qty"])
    return (
        pd.concat(rows, ignore_index=True)
        .groupby(["item_id", "ds"], as_index=False)["adjustment_delta_qty"]
        .sum()
    )


def reconcile(totals: pd.DataFrame, floor: pd.DataFrame, delta: pd.DataFrame) -> pd.DataFrame:
    """The formula, unchanged from what the schema already documents."""
    out = totals.copy()
    out = out.merge(floor, on=["item_id", "ds"], how="left")
    out = out.merge(delta, on=["item_id", "ds"], how="left")
    # astype before fillna: merging an empty adjustments frame leaves an object
    # column, and filling that is deprecated in pandas 2.x.
    out["committed_floor_qty"] = out["committed_floor_qty"].astype(float).fillna(0.0)
    out["adjustment_delta_qty"] = out["adjustment_delta_qty"].astype(float).fillna(0.0)

    out["overlaid_qty"] = (
        out[["predicted_qty", "committed_floor_qty"]].max(axis=1)
        + out["adjustment_delta_qty"]
    ).clip(lower=0.0)
    return out


def run(run_id: int, totals: pd.DataFrame, factors: pd.DataFrame) -> tuple[pd.DataFrame, dict]:
    if totals.empty:
        return totals, {"floored_rows": 0, "adjusted_rows": 0}

    start = totals["ds"].min().strftime("%Y-%m-%d")
    end = totals["ds"].max().strftime("%Y-%m-%d")

    floor = committed_floor(factors, start, end)
    delta = adjustment_deltas(totals)
    out = reconcile(totals, floor, delta)

    meta = {
        "floored_rows": int((out["committed_floor_qty"] > 0).sum()),
        "adjusted_rows": int((out["adjustment_delta_qty"] != 0).sum()),
        "floor_beat_forecast": int(
            (out["committed_floor_qty"] > out["predicted_qty"]).sum()
        ),
    }
    return out, meta


def persist(run_id: int, reconciled: pd.DataFrame) -> int:
    if reconciled.empty:
        return 0
    rows = [
        {
            "run_id": run_id,
            "item_id": int(r.item_id),
            "forecast_date": r.ds.strftime("%Y-%m-%d"),
            "committed_floor_qty": round(float(r.committed_floor_qty), 3),
            "adjustment_delta_qty": round(float(r.adjustment_delta_qty), 3),
            "overlaid_qty": round(float(r.overlaid_qty), 3),
        }
        for r in reconciled.itertuples()
    ]
    execute(
        """
        UPDATE ingredient_demand_forecast
           SET committed_floor_qty  = :committed_floor_qty,
               adjustment_delta_qty = :adjustment_delta_qty,
               overlaid_qty         = :overlaid_qty
         WHERE run_id = :run_id AND item_id = :item_id AND forecast_date = :forecast_date
        """,
        rows,
    )
    return len(rows)
