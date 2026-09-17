"""
forecasting/stages/s2_forecast.py

Stage 2 -- forecast how busy the restaurant will be. The only model in the
pipeline; everything downstream is arithmetic.

Why one model on the daily order count rather than 47 models, one per dish:
35 of 47 menu items average under 3 servings a day, with zeros scattered through
the week. Fitting each its own trend and seasonality mostly fits noise. The
restaurant's total busy-ness, at ~23 orders a day with a 1.70x weekend effect
and 12-13 observations of every weekday, is a pattern that can be estimated
honestly.

Configuration is frozen in CLAUDE.md and passed explicitly here -- never left on
'auto'. A model whose settings move every night cannot be defended.
"""

from __future__ import annotations

import logging
from datetime import date

import pandas as pd
from prophet import Prophet

import config

# Prophet/cmdstanpy narrate every fit; a nightly job does not need it.
logging.getLogger("prophet").setLevel(logging.WARNING)
logging.getLogger("cmdstanpy").setLevel(logging.WARNING)


def _build_model() -> Prophet:
    """Every seasonality flag is stated. `yearly_seasonality` left on 'auto'
    switches itself on once the history passes ~2 years, which would change the
    model silently and long after anyone remembered why."""
    return Prophet(
        yearly_seasonality=False,
        weekly_seasonality=True,
        daily_seasonality=False,
        seasonality_mode="multiplicative",
        seasonality_prior_scale=5.0,
        changepoint_prior_scale=0.05,
        interval_width=0.80,
    )


def run(series: pd.DataFrame, horizon_days: int | None = None,
        anchor: "pd.Timestamp | None" = None) -> tuple[pd.DataFrame, dict]:
    """
    Fit one Prophet model to daily order counts and predict forward.

    Returns (forecast, meta). `forecast` has one row per future day:
        ds, yhat, yhat_lower, yhat_upper, trend, weekly

    `anchor` is the day the forecast should START from. The live run leaves it
    None, meaning today, so a screen headed "next 7 days" really does show the
    next 7 days even when recording has lapsed. A BACKTEST must pass the fold's
    own cutoff instead: each fold trains to a date in the past and has to be
    scored on the days immediately after it, not on days months later. Getting
    this wrong is not subtle -- it took measured accuracy from 13.4% to 38.9%.

    The trend and weekly components come back alongside the prediction so the UI
    can plot them separately. Being able to show "this is the trend, this is the
    Friday effect" is most of Prophet's value here -- it is what makes the
    forecast something a panel can read rather than a number to take on faith.
    """
    horizon = horizon_days or config.get_int("forecast_horizon_days")

    if series.empty or len(series) < 14:
        raise ValueError(
            f"Not enough clean trading days to fit: {len(series)}. "
            "Stage 1 excluded too much, or the restaurant genuinely has little history."
        )

    train = series[["ds", "y"]].copy()
    train["y"] = train["y"].astype(float)

    model = _build_model()
    model.fit(train)

    # Prophet extends from the end of TRAINING, not from today, and those are
    # different dates whenever recording has lapsed. Training here ends at the
    # last day of continuous trading; ask only for `horizon` days and the
    # forecast covers the week AFTER that day -- which may already have
    # happened. A screen headed "next 7 days" would then be showing last month.
    #
    # So ask for the gap plus the horizon, and keep only the tail from today
    # onward. The model is unchanged; this is about which of its predictions
    # are worth showing.
    train_end = train["ds"].max()
    start_at = anchor if anchor is not None else pd.Timestamp(date.today())
    gap_days = max(0, (start_at - train_end).days)

    future = model.make_future_dataframe(periods=gap_days + horizon, freq="D")
    fc = model.predict(future)

    # Keep only the future, and only the columns anything downstream uses.
    cols = ["ds", "yhat", "yhat_lower", "yhat_upper", "trend"]
    if "weekly" in fc.columns:
        cols.append("weekly")
    start_from = max(start_at, train_end + pd.Timedelta(days=1))
    out = fc.loc[fc["ds"] >= start_from, cols].head(horizon).copy()

    # An order count cannot be negative. Prophet's lower bound can be, on a
    # quiet series -- clip rather than let a negative propagate into a recipe.
    for c in ("yhat", "yhat_lower", "yhat_upper"):
        out[c] = out[c].clip(lower=0.0)

    import prophet

    # The decomposition, kept so the UI can SHOW the model rather than just its
    # output. Prophet fits yhat = trend x (1 + weekly) under multiplicative
    # seasonality, so these two series are literally what the forecast is made
    # of. Small enough to live in the run's params_json -- 30 trend points and
    # 7 weekday factors -- which avoids a table for what is really run metadata.
    trend_series = [
        {"date": r.ds.strftime("%Y-%m-%d"), "value": round(float(r.trend), 4)}
        for r in out.itertuples()
    ]
    # The FITTED trend across the training window, which is the evidence for the
    # forward trend above rather than a second copy of it.
    #
    # Plotting only the forward part meant plotting only the extrapolation: over
    # seven days a piecewise-linear trend has nowhere to go, so the chart was a
    # flat line whatever the business was doing, and "is demand growing?" could
    # not be answered from it. Prophet already computes this -- `fc` covers the
    # training rows too -- so it costs one slice, not a second fit.
    #
    # One point per training day is ~90 numbers, the same order as the 30
    # already stored here, so it stays in params_json rather than earning a table.
    hist = fc.loc[fc["ds"] <= train_end, ["ds", "trend"]]
    trend_history = [
        {"date": r.ds.strftime("%Y-%m-%d"), "value": round(float(r.trend), 4)}
        for r in hist.itertuples()
    ]
    # Prophet's OWN daily order forecast, kept so the page can plot the model's
    # actual output. Without this the pipeline stores only per-dish servings, and
    # the "orders per day" chart had to recover the order count by dividing
    # servings by the units-per-order figure -- a faithful derivation, but a
    # round trip rather than the number the model produced.
    daily_orders = [
        {
            "date": r.ds.strftime("%Y-%m-%d"),
            "yhat": round(float(r.yhat), 3),
            "lower": round(float(r.yhat_lower), 3),
            "upper": round(float(r.yhat_upper), 3),
        }
        for r in out.itertuples()
    ]

    weekly_profile = {}
    if "weekly" in out.columns and not out.empty:
        # One factor per weekday. Prophet repeats it exactly, so the mean over
        # the horizon IS the factor; averaging just collapses the repeats.
        by_dow = out.assign(dow=out["ds"].dt.day_name()).groupby("dow")["weekly"].mean()
        weekly_profile = {d: round(float(v), 4) for d, v in by_dow.items()}

    meta = {
        "engine_version": f"prophet={prophet.__version__}",
        "components": {
            "mode": "multiplicative",
            "trend": trend_series,
            "trend_history": trend_history,
            "daily_orders": daily_orders,
            "weekly_profile": weekly_profile,
        },
        "horizon_days": horizon,
        "trained_rows": int(len(train)),
        "training_end": train["ds"].max().strftime("%Y-%m-%d"),
        # How far past the training data these predictions reach. Zero when
        # recording is current; large when it has lapsed, and worth saying so.
        "extrapolated_days": int(gap_days),
        "forecast_start": out["ds"].min().strftime("%Y-%m-%d") if not out.empty else None,
        "forecast_end": out["ds"].max().strftime("%Y-%m-%d") if not out.empty else None,
        "prophet_config": {
            "yearly_seasonality": False,
            "weekly_seasonality": True,
            "daily_seasonality": False,
            "seasonality_mode": "multiplicative",
            "seasonality_prior_scale": 5.0,
            "changepoint_prior_scale": 0.05,
            "interval_width": 0.80,
            "holidays_fitted": False,
        },
    }
    return out.reset_index(drop=True), meta
