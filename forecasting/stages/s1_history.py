"""
forecasting/stages/s1_history.py

Stage 1 -- build the trading history.

Aggregate completed orders into one row per business day. Drop days the
restaurant did not trade, and drop them as MISSING ROWS rather than zeros:
Prophet is happy with gaps in the dates and very unhappy with false zeros.

This is the stage that decides whether everything downstream is sane. Live data
shows 17-32 orders/day through 2026-08-11 and then near-nothing; train on that
as-is and the trend bends sharply downward through the final fortnight and
carries that slope into every future day. The model would forecast a restaurant
closing down. That is a data problem, not a modelling one, and it is fixed here.
"""

from __future__ import annotations

import pandas as pd

import config
from db import read_sql


def _cutoff_hour() -> int:
    """business_day_cutoff_time is 04:00, so a 01:30 order belongs to the
    previous day. Stored as HH:MM:SS."""
    raw = str(config.raw("business_day_cutoff_time"))
    try:
        return int(raw.split(":")[0])
    except (ValueError, IndexError):
        return 4


def daily_orders() -> pd.DataFrame:
    """
    One row per business day that had trade: ds, orders, covers, item_units.

    Cancelled and void orders are excluded. Only paid orders count -- an open
    order is not yet demand that happened.
    """
    hours = _cutoff_hour()
    sql = f"""
        SELECT
            DATE(COALESCE(op.paid_at, op.created_at) - INTERVAL {hours} HOUR) AS ds,
            COUNT(DISTINCT o.order_id)                                        AS orders,
            COALESCE(SUM(oi.quantity), 0)                                     AS item_units
        FROM orders o
        JOIN order_payments op ON op.order_id = o.order_id
        LEFT JOIN order_items oi
               ON oi.order_id = o.order_id AND oi.status <> 'cancelled'
        WHERE op.payment_status = 'paid'
          AND o.order_status <> 'cancelled'
        GROUP BY ds
        ORDER BY ds
    """
    df = read_sql(sql)
    df["ds"] = pd.to_datetime(df["ds"])
    df["orders"] = df["orders"].astype(int)
    df["item_units"] = df["item_units"].astype(float)
    return df


def detect_closures(df: pd.DataFrame, pct: int) -> list[str]:
    """
    A day is treated as a closure when its order count falls below `pct`% of the
    trailing 28-day median.

    Nothing in the schema records a closure -- system_settings.operating_days is
    1,2,3,4,5,6,7 -- so the pipeline has to decide, and then say what it decided.
    A median is used rather than a mean because it does not move when a couple
    of dead days appear beside it, which is exactly the situation being detected.
    """
    if df.empty:
        return []

    median28 = df["orders"].rolling(window=28, min_periods=7).median()
    threshold = median28 * (pct / 100.0)
    closed = df["orders"] < threshold
    # Before 7 days of history there is no median to judge against; keep those.
    closed = closed & threshold.notna()
    return [d.strftime("%Y-%m-%d") for d in df.loc[closed, "ds"]]


def active_holidays(start: str, end: str) -> list[dict]:
    """
    Active holidays inside the training window.

    These are EXCLUDED from training, not fitted as an effect. Two different
    decisions, for two different reasons:

      * Not fitted, because each holiday appears once in 90 days. One
        observation is not an effect -- Prophet would fit the noise on that
        single day and project it forward every year.

      * Excluded, because a holiday is an abnormal day. A Christmas trading at
        triple normal would distort the weekly seasonality for whichever weekday
        it happened to fall on, and that distortion would then be applied to
        every ordinary instance of that weekday.

    The closure rule already removes a holiday the restaurant closed for (zero
    orders). It does NOT catch one that traded abnormally HIGH, which is exactly
    what this covers.
    """
    return read_sql(
        """
        SELECT holiday_date, holiday_name, holiday_type
        FROM holidays
        WHERE is_active = 1
          AND holiday_date BETWEEN :a AND :b
        ORDER BY holiday_date
        """,
        {"a": start, "b": end},
    ).assign(holiday_date=lambda d: pd.to_datetime(d["holiday_date"]).dt.strftime("%Y-%m-%d"))      .to_dict("records")


def run(run_id: int | None = None) -> tuple[pd.DataFrame, dict]:
    """
    Returns (series, meta).

    `series` is the clean training frame: ds + y (order count), most recent
    `forecast_history_days` trading days, closures removed.
    `meta` carries what was excluded and why, for forecast_runs.params_json.
    """
    history_days = config.get_int("forecast_history_days")
    pct = config.get_int("closure_detect_pct")

    raw_df = daily_orders()
    excluded = detect_closures(raw_df, pct)

    # Holidays come out too -- see active_holidays(). Read across the full span
    # of recorded trade, then intersected with what actually survived, so the
    # run record names only holidays that were really in the training set.
    holidays: list[dict] = []
    if not raw_df.empty:
        holidays = active_holidays(
            raw_df["ds"].min().strftime("%Y-%m-%d"),
            raw_df["ds"].max().strftime("%Y-%m-%d"),
        )
    holiday_dates = {h["holiday_date"] for h in holidays}

    drop = set(excluded) | holiday_dates
    clean = raw_df[~raw_df["ds"].dt.strftime("%Y-%m-%d").isin(drop)].copy()
    # Rolling window: always the freshest N trading days, taken AFTER exclusion
    # so a fortnight of closures cannot eat into the usable history.
    clean = clean.tail(history_days).reset_index(drop=True)

    series = clean.rename(columns={"orders": "y"})[["ds", "y", "item_units"]]

    # Which holidays actually sat inside the window that was trained on -- a
    # holiday outside the rolling 90 days was never a candidate for exclusion.
    kept_span = raw_df[raw_df["ds"] >= clean["ds"].min()] if not clean.empty else raw_df.iloc[0:0]
    kept_dates = set(kept_span["ds"].dt.strftime("%Y-%m-%d")) & holiday_dates
    holidays_in_window = [h for h in holidays if h["holiday_date"] in kept_dates]

    meta = {
        "history_days_requested": history_days,
        "history_days_used": int(len(series)),
        "closure_detect_pct": pct,
        "excluded_dates": excluded,
        "excluded_count": len(excluded),
        # Reported separately from closures: "we were shut" and "it was a public
        # holiday" are different facts, and a run record that blurs them cannot
        # answer either question.
        "holidays_excluded": holidays_in_window,
        "holidays_excluded_count": len(holidays_in_window),
        "training_start": series["ds"].min().strftime("%Y-%m-%d") if not series.empty else None,
        "training_end": series["ds"].max().strftime("%Y-%m-%d") if not series.empty else None,
        "settings_defaulted": config.missing_keys(),
    }
    return series, meta
