"""
forecasting/config.py

Every threshold this pipeline uses comes from `system_settings`. Nothing is
hardcoded here -- that is a build rule, and it is what lets the Purchasing
Settings screen honestly claim it controls the system.

Defaults exist only so a missing row cannot crash a nightly run; they mirror
the seeded values and a missing key is reported rather than silently absorbed.
"""

from __future__ import annotations

from decimal import Decimal

from db import read_sql

# Fallbacks, used only when a key is absent from system_settings.
DEFAULTS: dict[str, str] = {
    "forecast_history_days": "90",
    "forecast_horizon_days": "7",
    # The longest horizon the Demand Forecasting page offers. Runs compute this
    # many days so the page can show any horizon it lets you pick; purchasing
    # still sizes against forecast_horizon_days above. Keep in step with
    # FORECAST_HORIZONS in owner/includes/forecast_view_functions.php.
    "forecast_view_max_days": "14",
    "forecast_default_horizon": "7",
    "closure_detect_pct": "20",
    "mix_window_weeks": "8",
    "mix_direct_fit_min_qty": "3.0",
    "review_period_days": "1",
    "safety_stock_days": "1",
    "business_day_cutoff_time": "04:00:00",
    "auto_po_enabled": "1",
    "forecast_round_servings_up": "1",
}

_CACHE: dict[str, str] | None = None
_MISSING: list[str] = []


def load(refresh: bool = False) -> dict[str, str]:
    global _CACHE
    if _CACHE is None or refresh:
        df = read_sql("SELECT setting_key, setting_value FROM system_settings")
        _CACHE = dict(zip(df["setting_key"], df["setting_value"]))
    return _CACHE


def raw(key: str) -> str:
    settings = load()
    if key in settings and settings[key] is not None:
        return str(settings[key])
    if key not in _MISSING:
        _MISSING.append(key)
    return DEFAULTS[key]


def get_int(key: str) -> int:
    return int(float(raw(key)))


def get_dec(key: str) -> Decimal:
    return Decimal(str(raw(key)))


def get_bool(key: str) -> bool:
    return str(raw(key)).strip() not in ("0", "", "false", "False")


def missing_keys() -> list[str]:
    """Keys that fell back to a default. Written into the run record so a
    settings row that never got seeded is visible rather than invisible."""
    return list(_MISSING)


