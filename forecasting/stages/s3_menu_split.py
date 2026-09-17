"""
forecasting/stages/s3_menu_split.py

Stage 3 -- split the predicted order count across the menu.

For each dish and each weekday, measure its trailing 8-week share of item units
per order. Multiply the predicted order count by that share to get predicted
servings, then renormalise so the menu sums back to the predicted total: the
split must not be able to invent or lose demand.

Per weekday, not overall, so a dish that is a Friday favourite is not diluted by
its quiet Mondays.

No model here. This is a measured share, and every number can be recomputed by
hand from order_items.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

import config
from db import read_sql

MODEL_NAME = "prophet_mix"


def weekday_shares(training_end: str, weeks: int | None = None) -> pd.DataFrame:
    """
    Returns menu_item_id, weekday (0=Mon), units, orders, share.

    `share` is units-per-order for that dish on that weekday: how many servings
    of it a single order tends to contain.
    """
    weeks = weeks or config.get_int("mix_window_weeks")
    days = weeks * 7

    sql = """
        SELECT
            oi.menu_item_id,
            WEEKDAY(COALESCE(op.paid_at, op.created_at))  AS weekday,
            SUM(oi.quantity)                              AS units,
            COUNT(DISTINCT o.order_id)                    AS orders_with_item
        FROM order_items oi
        JOIN orders o          ON o.order_id = oi.order_id
        JOIN order_payments op ON op.order_id = o.order_id
        JOIN menu_items m      ON m.item_id  = oi.menu_item_id
        WHERE op.payment_status = 'paid'
          AND oi.status <> 'cancelled'
          AND m.is_active = 1
          AND DATE(COALESCE(op.paid_at, op.created_at))
              BETWEEN DATE_SUB(:end, INTERVAL :days DAY) AND :end
        GROUP BY oi.menu_item_id, weekday
    """
    units = read_sql(sql, {"end": training_end, "days": days})

    orders_sql = """
        SELECT
            WEEKDAY(COALESCE(op.paid_at, op.created_at)) AS weekday,
            COUNT(DISTINCT o.order_id)                   AS orders
        FROM orders o
        JOIN order_payments op ON op.order_id = o.order_id
        WHERE op.payment_status = 'paid'
          AND o.order_status <> 'cancelled'
          AND DATE(COALESCE(op.paid_at, op.created_at))
              BETWEEN DATE_SUB(:end, INTERVAL :days DAY) AND :end
        GROUP BY weekday
    """
    orders = read_sql(orders_sql, {"end": training_end, "days": days})

    df = units.merge(orders, on="weekday", how="left")
    df["units"] = df["units"].astype(float)
    df["orders"] = df["orders"].astype(float).replace(0.0, pd.NA)
    df["share"] = (df["units"] / df["orders"]).fillna(0.0)
    return df[["menu_item_id", "weekday", "units", "orders", "share"]]


def split(forecast: pd.DataFrame, shares: pd.DataFrame) -> pd.DataFrame:
    """
    forecast: ds, yhat, yhat_lower, yhat_upper (from stage 2)
    shares:   menu_item_id, weekday, share

    Returns one row per dish per forecast day:
        menu_item_id, ds, servings, servings_lower, servings_upper
    """
    if forecast.empty or shares.empty:
        return pd.DataFrame(
            columns=["menu_item_id", "ds", "servings", "servings_lower", "servings_upper"]
        )

    fc = forecast.copy()
    fc["weekday"] = fc["ds"].dt.weekday

    out = fc.merge(shares, on="weekday", how="inner")
    for src, dst in (("yhat", "servings"), ("yhat_lower", "servings_lower"), ("yhat_upper", "servings_upper")):
        out[dst] = out[src].astype(float) * out["share"].astype(float)

    return out[["menu_item_id", "ds", "servings", "servings_lower", "servings_upper", "share"]]


def renormalise(split_df: pd.DataFrame, forecast: pd.DataFrame, expected_units_per_order: float) -> pd.DataFrame:
    """
    Force each day's servings to sum to the predicted total item count.

    Measured shares are computed independently per dish, so nothing makes them
    add up on their own -- rounding and a changing menu both nudge the total.
    Scaling each day by one factor preserves the relative mix (which is the part
    that was measured) while making the total honest.
    """
    if split_df.empty:
        return split_df

    target = forecast[["ds", "yhat"]].copy()
    target["target_units"] = target["yhat"].astype(float) * expected_units_per_order

    day_totals = split_df.groupby("ds", as_index=False)["servings"].sum()
    day_totals = day_totals.rename(columns={"servings": "raw_total"})

    factors = target.merge(day_totals, on="ds", how="left")
    factors["factor"] = (factors["target_units"] / factors["raw_total"]).replace(
        [float("inf"), float("-inf")], 1.0
    ).fillna(1.0)

    out = split_df.merge(factors[["ds", "factor"]], on="ds", how="left")
    for c in ("servings", "servings_lower", "servings_upper"):
        out[c] = out[c] * out["factor"]
    return out.drop(columns=["factor"])


def round_servings_up(split_df: pd.DataFrame) -> pd.DataFrame:
    """
    Ceiling every dish-day to a whole serving.

    The kitchen cannot cook 9.31 portions of sisig; it cooks 10, and 10 portions
    of ingredients leave the store. Rounding here rather than at the purchase
    order means the ingredient explosion is fed the quantity that is really
    consumed, which is the point of the change.

    What it costs, stated plainly: this is the only step in the pipeline that is
    deliberately NOT an unbiased estimate. Every dish-day can only move upward,
    so the bias is one-directional and compounds across dishes and days -- 47
    dishes rounded up daily adds materially more than any single row suggests.
    `rounding_inflation_pct` in the run record measures exactly how much was
    added, so the cost is visible rather than buried.

    The band is ceilinged with the point estimate so the three stay ordered;
    only `servings` reaches stage 4.

    Turn off with system_settings.forecast_round_servings_up = 0.
    """
    out = split_df.copy()
    for c in ("servings", "servings_lower", "servings_upper"):
        if c in out.columns:
            # Settle to 6 decimals before the ceiling: floating point leaves
            # 9.000000000000002 lying around, and that would round a clean 9 to
            # 10 for no reason. Same guard s6_reorder.round_up() uses.
            out[c] = np.ceil(out[c].astype(float).round(6))
    return out


def run(run_id: int, forecast: pd.DataFrame, training_end: str) -> tuple[pd.DataFrame, dict]:
    shares = weekday_shares(training_end)

    # Average items per order over the same window -- the scale the split is
    # renormalised to. Read, not assumed.
    upo = read_sql(
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
        {"end": training_end, "days": config.get_int("mix_window_weeks") * 7},
    )["upo"][0]
    units_per_order = float(upo or 1.0)

    raw = split(forecast, shares)
    final = renormalise(raw, forecast, units_per_order)

    rounded = config.get_bool("forecast_round_servings_up")
    inflation = 0.0
    if rounded and not final.empty:
        before = float(final["servings"].sum())
        final = round_servings_up(final)
        after = float(final["servings"].sum())
        inflation = (after - before) / before * 100.0 if before > 0 else 0.0

    meta = {
        "mix_window_weeks": config.get_int("mix_window_weeks"),
        "units_per_order": round(units_per_order, 4),
        "dishes_with_history": int(shares["menu_item_id"].nunique()),
        "model_name": MODEL_NAME,
        # Recorded because it is the one place the pipeline deliberately stops
        # being an unbiased estimate. A reader of the run should be able to see
        # how much was added, not just that something was.
        "servings_rounded_up": bool(rounded),
        "rounding_inflation_pct": round(inflation, 2),
    }
    return final, meta


def persist(run_id: int, split_df: pd.DataFrame) -> int:
    """menu_item_demand_forecast is keyed on (item, date, period, model), so a
    re-run for the same day legitimately overwrites its own prior guess."""
    if split_df.empty:
        return 0

    rows = [
        {
            "menu_item_id": int(r.menu_item_id),
            "forecast_date": r.ds.strftime("%Y-%m-%d"),
            "model_name": MODEL_NAME,
            "run_id": run_id,
            "predicted_quantity": round(float(r.servings), 2),
            "yhat_lower": round(float(r.servings_lower), 2),
            "yhat_upper": round(float(r.servings_upper), 2),
        }
        for r in split_df.itertuples()
    ]

    from db import execute

    execute(
        """
        INSERT INTO menu_item_demand_forecast
            (menu_item_id, forecast_date, period_type, model_name, run_id,
             predicted_quantity, yhat_lower, yhat_upper, generated_at)
        VALUES
            (:menu_item_id, :forecast_date, 'daily', :model_name, :run_id,
             :predicted_quantity, :yhat_lower, :yhat_upper, NOW())
        ON DUPLICATE KEY UPDATE
            run_id             = VALUES(run_id),
            predicted_quantity = VALUES(predicted_quantity),
            yhat_lower         = VALUES(yhat_lower),
            yhat_upper         = VALUES(yhat_upper),
            generated_at       = NOW()
        """,
        rows,
    )
    return len(rows)
