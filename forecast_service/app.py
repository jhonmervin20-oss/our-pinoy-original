"""
forecast_service/app.py

Standalone AI forecasting microservice for the Demand Forecasting module
(owner/demand_forecast.php). Wraps Facebook Prophet behind one endpoint.

This is NOT part of the PHP app's request lifecycle -- it's a separate
local process the PHP side calls over HTTP (see owner/includes/
prophet_client.php). Runs on http://127.0.0.1:5000 by default. Start it
with start_service.bat before using the "AI Forecast (Prophet)" features
of the dashboard. There is deliberately no statistical fallback baked
into this service or the PHP side -- if this process isn't running, the
PHP side shows an honest "AI forecast unavailable" note (or, for the
ingredient/auto-PO engine specifically, falls back to a PHP-side
seasonal-naive estimate that's clearly labeled as such, never silently
blended with a real Prophet result). See owner/includes/
demand_forecast_functions.php's module docblock for the full rationale.

Setup:
    pip install -r requirements.txt
    uvicorn app:app --host 127.0.0.1 --port 5000
(or just double-click start_service.bat on Windows)
"""

import importlib.metadata
import os

import pandas as pd
from fastapi import FastAPI, Header, HTTPException
from prophet import Prophet
from pydantic import BaseModel

from engine import data_prep, forecasting, inventory_policy

# Minimum distinct days of history required before Prophet is even
# attempted. Below this, seasonality/trend decomposition has no real
# statistical basis -- Prophet would still "run" and return a number, but
# that number wouldn't mean anything. The PHP side enforces the same floor
# before it even calls this service (see MIN_PROPHET_HISTORY_DAYS in
# demand_forecast_functions.php); this check exists here too in case this
# service is ever called from somewhere else.
MIN_HISTORY_POINTS = 14

# Maps this module's granularity vocabulary to pandas frequency codes for
# Prophet's make_future_dataframe().
FREQ_MAP = {"daily": "D", "weekly": "W", "monthly": "MS", "yearly": "YS"}

# Optional shared-secret check -- only enforced if FORECAST_SERVICE_API_KEY
# is set in the environment. Off by default since this normally only
# listens on 127.0.0.1 for a single local PHP app.
API_KEY = os.environ.get("FORECAST_SERVICE_API_KEY", "")

# Uncertainty band width for the menu-item/sales forecast (/forecast).
# The Owner panel prints this band next to the headline number and labels it
# "80% prediction interval", so the value is pinned here rather than left to
# Prophet's default. (The ingredient side picks its width per demand class in
# engine/demand_classification.py -- 0.80 fast, 0.95 medium -- which is a
# separate, deliberate choice documented there.)
SALES_FORECAST_INTERVAL_WIDTH = 0.80

app = FastAPI(title="Demand Forecast AI Service", version="1.0.0")


class HistoryPoint(BaseModel):
    date: str      # "YYYY-MM-DD"
    quantity: float


class HolidayPoint(BaseModel):
    date: str            # "YYYY-MM-DD"
    holiday_type: str    # "regular" | "special" -- see engine.forecasting.build_holidays_df()
                          # for why this is pooled by type, not by individual holiday name


class DailyForecastPoint(BaseModel):
    day: int
    date: str
    yhat: float
    yhat_lower: float
    yhat_upper: float


def engine_version() -> str:
    """
    Real, installed package versions for this process -- reported on every
    response so `forecast_runs.engine_version` reflects what actually ran,
    not a hardcoded label that drifts from reality after an upgrade.
    """
    versions = []
    for pkg in ("prophet", "pandas"):
        try:
            versions.append(f"{pkg}={importlib.metadata.version(pkg)}")
        except importlib.metadata.PackageNotFoundError:
            versions.append(f"{pkg}=unknown")
    return " ".join(versions)


class ForecastRequest(BaseModel):
    history: list[HistoryPoint]
    granularity: str = "daily"   # daily | weekly | monthly | yearly
    horizon: int = 1             # number of future buckets to predict
    holidays: list[HolidayPoint] = []


class ForecastResponse(BaseModel):
    predicted_quantity: float
    yhat_lower: float
    yhat_upper: float
    predicted_quantity_cumulative: float  # sum(yhat) across the full requested horizon, for "next N days" aggregates
    daily_forecast: list[DailyForecastPoint]
    model: str = "prophet"
    engine_version: str


@app.get("/health")
def health():
    """Lets the PHP side (or a human) confirm the service is up before relying on it."""
    return {"status": "ok"}


@app.post("/forecast", response_model=ForecastResponse)
def forecast(req: ForecastRequest, x_api_key: str | None = Header(default=None)):
    if API_KEY and x_api_key != API_KEY:
        raise HTTPException(status_code=401, detail={"error": "unauthorized"})

    if len(req.history) < MIN_HISTORY_POINTS:
        raise HTTPException(
            status_code=422,
            detail={
                "error": "insufficient_data",
                "message": f"Need at least {MIN_HISTORY_POINTS} historical points, got {len(req.history)}.",
            },
        )

    freq = FREQ_MAP.get(req.granularity, "D")
    horizon = max(1, req.horizon)

    df = pd.DataFrame([{"ds": p.date, "y": p.quantity} for p in req.history])
    df["ds"] = pd.to_datetime(df["ds"])

    holidays_df = forecasting.build_holidays_df([h.model_dump() for h in req.holidays])

    # One definition, imported -- the sales model and the ingredient model must
    # gate yearly seasonality identically or they are two different models of
    # the same business.
    yearly_seasonality = 4 if len(df) >= forecasting.YEARLY_SEASONALITY_MIN_DAYS else False

    model = Prophet(
        daily_seasonality=(req.granularity == "daily"),
        # Seasonalities are ADDITIVE in Prophet, not exclusive -- weekly and
        # yearly are fitted together, each as its own component.
        #
        # weekly: the strongest real signal in this dataset (Fri/Sat/Sun run
        #   ~410 orders against ~250 Mon-Thu).
        # yearly: fitted at a REDUCED Fourier order (4 rather than Prophet's
        #   default 10, so the annual shape stays smooth), but only once a
        #   year of history exists to fit it to -- see
        #   YEARLY_SEASONALITY_MIN_DAYS for the measurement behind that gate.
        weekly_seasonality=True,
        yearly_seasonality=yearly_seasonality,
        holidays=holidays_df,
        # Stated explicitly rather than inherited from Prophet's default.
        # It happens to BE Prophet's current default (0.80), but the UI
        # labels this band "80% prediction interval" to the owner, so the
        # number backing that claim must live here in the open -- not
        # silently track whatever a future Prophet release decides to
        # default to.
        interval_width=SALES_FORECAST_INTERVAL_WIDTH,
    )
    model.fit(df)

    future = model.make_future_dataframe(periods=horizon, freq=freq)
    predicted = model.predict(future)
    future_rows = predicted.tail(horizon).reset_index(drop=True)

    daily_forecast = [
        DailyForecastPoint(
            day=i + 1,
            date=row["ds"].strftime("%Y-%m-%d"),
            yhat=max(0.0, float(row["yhat"])),
            yhat_lower=max(0.0, float(row["yhat_lower"])),
            yhat_upper=max(0.0, float(row["yhat_upper"])),
        )
        for i, row in future_rows.iterrows()
    ]
    last_row = future_rows.iloc[-1]

    return ForecastResponse(
        predicted_quantity=max(0.0, float(last_row["yhat"])),
        yhat_lower=max(0.0, float(last_row["yhat_lower"])),
        yhat_upper=max(0.0, float(last_row["yhat_upper"])),
        predicted_quantity_cumulative=float(sum(p.yhat for p in daily_forecast)),
        daily_forecast=daily_forecast,
        model="prophet",
        engine_version=engine_version(),
    )


# ---------------------------------------------------------------------------
# /forecast_policy -- demand-classification + inventory-policy engine.
#
# This is a SEPARATE feature from /forecast above (ingredient-level
# purchasing decisions for the Hybrid Auto-PO feature, vs. /forecast's
# menu-item sales point-forecast). It shares only the HistoryPoint request
# shape and the optional API-key/insufficient-data conventions. Everything
# else -- routing between Prophet and a trailing average, computing an
# order-up-to policy -- lives in engine/ and is unit-testable independent
# of this HTTP layer. See engine/__init__.py for the module map.
# ---------------------------------------------------------------------------


class PolicyRequest(BaseModel):
    history: list[HistoryPoint]
    lead_time_days: int
    forecast_horizon_days: int = 7
    holidays: list[HolidayPoint] = []
    # Day-aligned with the forecast horizon (index 0 = first forecast day).
    # committed_floor_daily is a genuine floor (e.g. BOM-exploded advance
    # orders); adjustment_delta_daily is signed and additive (a manager
    # override). Both optional -- see engine.inventory_policy.compute_policy()
    # for exactly how they combine.
    committed_floor_daily: list[float] | None = None
    adjustment_delta_daily: list[float] | None = None


class PolicyResponse(BaseModel):
    demand_pattern: str
    slow_reason: str | None
    model_used: str
    nonzero_day_ratio: float
    interval_width: float | None
    daily_forecast: list[DailyForecastPoint]
    lead_time_demand_qty: float
    reorder_level: float
    review_period_demand_qty: float
    restock_target: float
    safety_stock_qty: float
    coverage_days: int
    engine_version: str


@app.post("/forecast_policy", response_model=PolicyResponse)
def forecast_policy(req: PolicyRequest, x_api_key: str | None = Header(default=None)):
    if API_KEY and x_api_key != API_KEY:
        raise HTTPException(status_code=401, detail={"error": "unauthorized"})

    if len(req.history) < data_prep.MIN_HISTORY_DAYS:
        raise HTTPException(
            status_code=422,
            detail={
                "error": "insufficient_data",
                "message": f"Need at least {data_prep.MIN_HISTORY_DAYS} historical points, got {len(req.history)}.",
            },
        )

    if req.lead_time_days < 0:
        raise HTTPException(
            status_code=422,
            detail={"error": "invalid_input", "message": "lead_time_days must be >= 0."},
        )

    if req.forecast_horizon_days < 1:
        raise HTTPException(
            status_code=422,
            detail={"error": "invalid_input", "message": "forecast_horizon_days must be >= 1."},
        )

    for field_name, daily_list in (
        ("committed_floor_daily", req.committed_floor_daily),
        ("adjustment_delta_daily", req.adjustment_delta_daily),
    ):
        if daily_list is not None and len(daily_list) != req.forecast_horizon_days:
            raise HTTPException(
                status_code=422,
                detail={
                    "error": "invalid_input",
                    "message": f"{field_name} must have exactly forecast_horizon_days ({req.forecast_horizon_days}) entries, got {len(daily_list)}.",
                },
            )

    # Build a dense daily series indexed by date, same convention as
    # data_prep.build_ingredient_daily_series()'s output.
    hist_df = pd.DataFrame([{"date": p.date, "quantity": p.quantity} for p in req.history])
    hist_df["date"] = pd.to_datetime(hist_df["date"])
    hist_df = hist_df.sort_values("date")
    daily_quantities = pd.Series(
        hist_df["quantity"].values, index=pd.DatetimeIndex(hist_df["date"].values), name="quantity"
    )

    holidays_df = forecasting.build_holidays_df([h.model_dump() for h in req.holidays])

    classification, forecast_df = forecasting.forecast_demand(
        daily_quantities, req.forecast_horizon_days, holidays_df
    )

    if classification["demand_pattern"] == "slow":
        model_used = "trailing_average"
        trailing_weekly_avg = float(daily_quantities.mean()) * 7.0
        policy = inventory_policy.compute_policy(
            forecast_df,
            classification["demand_pattern"],
            req.lead_time_days,
            req.forecast_horizon_days,
            trailing_weekly_avg=trailing_weekly_avg,
            committed_floor_daily=req.committed_floor_daily,
            adjustment_delta_daily=req.adjustment_delta_daily,
        )
    else:
        model_used = "prophet"
        policy = inventory_policy.compute_policy(
            forecast_df,
            classification["demand_pattern"],
            req.lead_time_days,
            req.forecast_horizon_days,
            committed_floor_daily=req.committed_floor_daily,
            adjustment_delta_daily=req.adjustment_delta_daily,
        )

    daily_forecast = [
        DailyForecastPoint(
            day=int(row["day"]),
            date=row["date"],
            yhat=float(row["yhat"]),
            yhat_lower=float(row["yhat_lower"]),
            yhat_upper=float(row["yhat_upper"]),
        )
        for _, row in forecast_df.iterrows()
    ]

    return PolicyResponse(
        demand_pattern=classification["demand_pattern"],
        slow_reason=classification.get("slow_reason"),
        model_used=model_used,
        nonzero_day_ratio=classification["nonzero_day_ratio"],
        interval_width=classification["interval_width"],
        daily_forecast=daily_forecast,
        engine_version=engine_version(),
        **policy,
    )
