"""
forecast_service/engine/forecasting.py

Two forecasting branches for ingredient-level daily demand, dispatched by
demand_classification.classify_demand():

  - forecast_prophet()          -- fast/medium movers, a genuine Prophet fit.
  - forecast_trailing_average() -- slow movers, a flat conservative estimate
                                    (a deliberate, permanent design choice
                                    for sparse series, not a degraded
                                    fallback -- Prophet's trend/seasonality
                                    decomposition has no real statistical
                                    basis when most days are zero).

forecast_demand() ties the two together behind one call so callers
(app.py's /forecast_policy endpoint, run_example.py, backtest.py) don't
have to duplicate the classify-then-route logic.
"""

from __future__ import annotations

import pandas as pd
from prophet import Prophet

from . import demand_classification



# Yearly seasonality is fitted only once there is a year of history to fit
# it to. Measured on this app's real data (97 days, top 12 ingredients by
# volume, rolling-origin backtest at a 7-day horizon):
#
#   seasonal-naive baseline   window 19.1%   daily 46.9%
#   Prophet, yearly off       window 22.8%   daily 46.3%   bias  -3.9%
#   Prophet, yearly = 4       window 61.0%   daily 75.2%   bias +14.1%
#
# With under a year of data Prophet cannot observe an annual cycle, so the
# yearly component absorbs noise and extrapolates it forward: it nearly
# tripled the error a purchase order actually depends on, and pushed the
# forecast 14% high. The component is not removed -- it switches on by itself
# once the history supports it, which is the point at which it starts
# describing a real annual cycle instead of inventing one.
# Reproduce with: venv/Scripts/python compare_seasonality.py <export.csv>
YEARLY_SEASONALITY_MIN_DAYS = 365

def _future_dates(daily_quantities: pd.Series, horizon_days: int) -> pd.DatetimeIndex:
    last_date = pd.to_datetime(daily_quantities.index[-1])
    return pd.date_range(start=last_date + pd.Timedelta(days=1), periods=horizon_days, freq="D")


def build_holidays_df(holiday_points: list[dict] | None) -> pd.DataFrame | None:
    """
    Builds a Prophet-compatible holidays DataFrame (columns [ds, holiday])
    from a list of {"date": "YYYY-MM-DD", "holiday_type": "regular"|"special"}
    dicts, pooled by TYPE rather than by individual holiday name.

    Prophet fits one independent effect per distinct `holiday` label. This
    app's real history is currently ~91 days, so a typical window contains
    maybe 3-5 holiday occurrences, each with its own unique NAME (e.g.
    "Rizal Day", "Bonifacio Day") -- fitting a separate effect per name
    would mean most effects are single-observation and degenerate (Prophet
    would memorize that one day and misapply the "learned" coefficient to
    a future, differently-named holiday). Pooling by holiday_type collapses
    this to at most 2 effects (regular/special), each with a handful of
    observations -- still thin, but not degenerate. holiday_name stays in
    the `holidays` table/UI for humans only, never reaches Prophet.

    Returns None (not an empty DataFrame) when given no points, so callers
    can pass the result straight into Prophet(holidays=...) unconditionally
    -- Prophet treats holidays=None as "no holiday regressor", same as
    omitting the argument.
    """
    if not holiday_points:
        return None

    df = pd.DataFrame([
        {"ds": pd.to_datetime(p["date"]), "holiday": p.get("holiday_type") or "regular"}
        for p in holiday_points
    ])
    return df if not df.empty else None


def forecast_prophet(
    daily_quantities: pd.Series,
    horizon_days: int,
    interval_width: float,
    holidays_df: pd.DataFrame | None = None,
) -> pd.DataFrame:
    """
    Fit Prophet on a daily demand series and return the next `horizon_days`
    days of forecast only (history rows are dropped from the output).

    Args:
        daily_quantities: pd.Series with a DatetimeIndex (dense, daily).
        horizon_days: number of future days to forecast.
        interval_width: Prophet's uncertainty interval width (e.g. 0.80 or
            0.95 -- wired through from demand_classification.classify_demand()).
        holidays_df: optional output of build_holidays_df() -- passed
            straight through to Prophet's own `holidays` regressor.

    Returns:
        pd.DataFrame with columns [day, date, yhat, yhat_lower, yhat_upper],
        one row per future day, `day` 1-indexed, all three quantity columns
        clipped at 0 (no negative demand -- matches app.py's existing
        /forecast endpoint convention).
    """
    df = pd.DataFrame({"ds": pd.to_datetime(daily_quantities.index), "y": daily_quantities.values})

    # Reduced Fourier order (4 rather than Prophet's default 10) so the annual
    # shape stays smooth instead of chasing individual weeks. Gated on history
    # length -- see YEARLY_SEASONALITY_MIN_DAYS. Must stay identical to app.py's
    # sales model; if the two disagree, the sales forecast and the ingredient
    # forecast are fitting different models of the same business.
    yearly = 4 if len(df) >= YEARLY_SEASONALITY_MIN_DAYS else False

    model = Prophet(
        daily_seasonality=True,
        weekly_seasonality=True,
        yearly_seasonality=yearly,
        interval_width=interval_width,
        holidays=holidays_df,
    )
    model.fit(df)

    future = model.make_future_dataframe(periods=horizon_days, freq="D")
    predicted = model.predict(future)

    future_rows = predicted.tail(horizon_days).reset_index(drop=True)

    out = pd.DataFrame({
        "day": range(1, horizon_days + 1),
        "date": future_rows["ds"].dt.strftime("%Y-%m-%d"),
        "yhat": future_rows["yhat"].clip(lower=0.0),
        "yhat_lower": future_rows["yhat_lower"].clip(lower=0.0),
        "yhat_upper": future_rows["yhat_upper"].clip(lower=0.0),
    })
    return out


def forecast_trailing_average(daily_quantities: pd.Series, horizon_days: int) -> pd.DataFrame:
    """
    Flat forecast for slow-moving items: every future day gets the same
    predicted quantity, the average daily rate over the whole provided
    window. No confidence interval is computed -- yhat_lower and
    yhat_upper both equal yhat, since this is a flat conservative
    estimate rather than a statistical interval.

    trailing_weekly_avg = mean(daily_quantities) * 7
    yhat (per day)       = trailing_weekly_avg / 7   (i.e. just the mean)

    Returns:
        pd.DataFrame with columns [day, date, yhat, yhat_lower, yhat_upper].
    """
    trailing_weekly_avg = float(pd.Series(daily_quantities).mean()) * 7.0
    daily_rate = max(0.0, trailing_weekly_avg / 7.0)

    dates = _future_dates(daily_quantities, horizon_days)

    out = pd.DataFrame({
        "day": range(1, horizon_days + 1),
        "date": [d.strftime("%Y-%m-%d") for d in dates],
        "yhat": [daily_rate] * horizon_days,
        "yhat_lower": [daily_rate] * horizon_days,
        "yhat_upper": [daily_rate] * horizon_days,
    })
    return out


def forecast_demand(
    daily_quantities: pd.Series,
    horizon_days: int,
    holidays_df: pd.DataFrame | None = None,
) -> tuple[dict, pd.DataFrame]:
    """
    Dispatcher: classifies the series, then routes to Prophet (fast/medium)
    or the trailing average (slow). holidays_df is ignored on the slow
    branch (forecast_trailing_average() has no seasonality/regressor model
    to feed it into -- it's a flat mean by design).

    Returns:
        (classification_dict, forecast_df) where classification_dict is
        demand_classification.classify_demand()'s return value and
        forecast_df is whichever of the two forecast functions' output.
    """
    classification = demand_classification.classify_demand(daily_quantities)

    if classification["demand_pattern"] == "slow":
        forecast_df = forecast_trailing_average(daily_quantities, horizon_days)
    else:
        forecast_df = forecast_prophet(daily_quantities, horizon_days, classification["interval_width"], holidays_df)

    return classification, forecast_df
