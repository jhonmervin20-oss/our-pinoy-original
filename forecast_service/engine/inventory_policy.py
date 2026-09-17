"""
forecast_service/engine/inventory_policy.py

Order-up-to (min-max) inventory policy derived directly from the forecast
produced by forecasting.py -- no independently-derived safety-stock
formula. For the Prophet branch, Prophet's own upper confidence bound IS
the safety buffer; for the trailing-average branch (slow movers), a flat
conservative multiplier stands in for a statistical interval, since a
sparse series has no real confidence interval to lean on.
"""

from __future__ import annotations

import pandas as pd

# Flat conservative buffer for slow movers -- deliberately not a
# fake-precision statistical interval, since Prophet's own decomposition
# has no real basis on a mostly-zero series. Module constant by design
# (see demand_classification.py's docstring for the same rationale).
SLOW_MOVER_BUFFER_MULTIPLIER = 2.0


def compute_policy(
    forecast_df: pd.DataFrame,
    demand_pattern: str,
    lead_time_days: int,
    forecast_horizon_days: int,
    trailing_weekly_avg: float | None = None,
    committed_floor_daily: list[float] | None = None,
    adjustment_delta_daily: list[float] | None = None,
) -> dict:
    """
    Compute order-up-to (min-max) policy levels from a forecast.

    review_period_days = max(0, forecast_horizon_days - lead_time_days)
        Whatever's left of the forecast window after lead time is
        consumed. Clamped at 0 if lead_time_days > forecast_horizon_days
        -- a supplier lead time longer than the forecast window is a real
        degenerate case; it doesn't crash, it just means there's no
        "review period" left to plan for, only lead-time coverage.

    A shelf-life cap on the order-up-to window used to sit here. It was
    removed rather than kept as an unused option: inventory_items.shelf_life_days
    was NULL on all 52 items, so it had never once capped anything, while
    the engine, the API response and the UI all advertised it as active
    behaviour. Re-introducing it needs the column populated first.

    Known-demand overlay (committed_floor_daily / adjustment_delta_daily,
    both optional, day-aligned with forecast_df -- index 0 is the first
    forecast day): per day, overlaid = GREATEST(0, max(yhat, floor) + delta).
        - committed_floor_daily is a genuine FLOOR (e.g. BOM-exploded
          advance-order bookings) -- combined via max(), never additive,
          so it can only raise a day's demand up to the booked amount, not
          double-count on top of an already-higher forecast.
        - adjustment_delta_daily is ADDITIVE, never blended via max() --
          a manager's "+40% this week" must always show up, even on a day
          Prophet already forecasts above the multiplied baseline (max()
          would silently discard it otherwise). It is signed: a
          below-1.0 multiplier produces a negative delta, which is why
          the final GREATEST(0, ...) clamp exists -- an overlay can never
          drive demand negative.
    committed_floor_daily/adjustment_delta_daily arrive as raw per-day
    lists; this function sums each into a lead-time-window total and a
    review-window total (via _window_sum() below) so both the "slow" and
    "fast"/"medium" branches apply the identical overlay logic against the
    same two window totals, despite computing their base numbers differently.

    Prophet branch (demand_pattern "fast" or "medium"): overlay is applied
    to BOTH yhat (feeds lead_time_demand/review_demand) and yhat_upper
    (feeds reorder_level) identically, since a real committed floor or
    manager delta is more reliable than a statistical interval and must
    move the safety-stock-implying reorder_level too, not just the point
    estimate.

    Trailing-average branch (demand_pattern "slow"): overlay is applied to
    the flat daily_rate-derived lead_time_demand/review_demand; reorder_level
    is SLOW_MOVER_BUFFER_MULTIPLIER applied AFTER the overlay, so the
    buffer still scales the combined (real + forecast) picture rather than
    being computed on a stale pre-overlay number.

    Args:
        forecast_df: output of forecasting.forecast_prophet() or
            forecast_trailing_average() -- columns [day, date, yhat,
            yhat_lower, yhat_upper], ordered by day ascending.
        demand_pattern: "fast" | "medium" | "slow" (from classify_demand()).
        lead_time_days: supplier lead time in days.
        forecast_horizon_days: length of the forecast window in days
            (should equal len(forecast_df)).
        trailing_weekly_avg: required for the "slow" branch; ignored for
            "fast"/"medium".
        committed_floor_daily: optional list[float], length
            forecast_horizon_days, day-aligned with forecast_df.
        adjustment_delta_daily: optional list[float], same shape/alignment.

    Returns:
        dict with keys: lead_time_demand_qty, reorder_level,
        review_period_demand_qty, restock_target, safety_stock_qty,
        coverage_days.
    """
    lead_time_days = max(0, lead_time_days)
    review_period_days = max(0, forecast_horizon_days - lead_time_days)

    coverage_days = lead_time_days + review_period_days

    def _window_sum(daily: list[float] | None, start: int, end: int) -> float:
        if not daily:
            return 0.0
        return float(sum(daily[start:end]))

    committed_floor_lead   = _window_sum(committed_floor_daily, 0, lead_time_days)
    committed_floor_review = _window_sum(committed_floor_daily, lead_time_days, lead_time_days + review_period_days)
    adjustment_delta_lead   = _window_sum(adjustment_delta_daily, 0, lead_time_days)
    adjustment_delta_review = _window_sum(adjustment_delta_daily, lead_time_days, lead_time_days + review_period_days)

    if demand_pattern == "slow":
        if trailing_weekly_avg is None:
            raise ValueError("trailing_weekly_avg is required for the 'slow' demand_pattern branch")

        daily_rate = trailing_weekly_avg / 7.0
        lead_time_demand = daily_rate * lead_time_days
        review_demand = daily_rate * review_period_days

        lead_time_demand = max(0.0, max(lead_time_demand, committed_floor_lead) + adjustment_delta_lead)
        review_demand = max(0.0, max(review_demand, committed_floor_review) + adjustment_delta_review)
        reorder_level = lead_time_demand * SLOW_MOVER_BUFFER_MULTIPLIER

    else:
        # Prophet branch (fast/medium). Slice by row position (the first
        # lead_time_days rows are day 1..lead_time_days by construction).
        lead_rows = forecast_df.iloc[:lead_time_days]
        review_rows = forecast_df.iloc[lead_time_days:lead_time_days + review_period_days]

        lead_time_demand = float(lead_rows["yhat"].sum())
        reorder_level = float(lead_rows["yhat_upper"].sum())
        review_demand = float(review_rows["yhat"].sum())

        lead_time_demand = max(0.0, max(lead_time_demand, committed_floor_lead) + adjustment_delta_lead)
        reorder_level = max(0.0, max(reorder_level, committed_floor_lead) + adjustment_delta_lead)
        review_demand = max(0.0, max(review_demand, committed_floor_review) + adjustment_delta_review)

    restock_target = reorder_level + review_demand
    safety_stock_qty = reorder_level - lead_time_demand

    return {
        "lead_time_demand_qty": float(lead_time_demand),
        "reorder_level": float(reorder_level),
        "review_period_demand_qty": float(review_demand),
        "restock_target": float(restock_target),
        "safety_stock_qty": float(safety_stock_qty),
        "coverage_days": int(coverage_days),
    }
