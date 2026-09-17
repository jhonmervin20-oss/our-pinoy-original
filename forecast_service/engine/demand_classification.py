"""
forecast_service/engine/demand_classification.py

Classifies an ingredient's daily demand series into fast/medium/slow
velocity buckets, which in turn determines which forecasting model
(forecasting.py) is used and which interval width Prophet is fit with.

The three thresholds/widths below are plain Python module constants by
design (a capstone architecture decision): business-facing settings like
the forecast horizon or lead times live in the app's database and can be
tuned by an owner/manager without a code change, but these are
model-tuning constants -- changing them invalidates any prior backtest
(backtest.py), so they're versioned with the code, not configurable at
runtime.
"""

from __future__ import annotations

import pandas as pd

FAST_RATIO_THRESHOLD = 0.70
MEDIUM_RATIO_THRESHOLD = 0.30

INTERVAL_WIDTH_FAST = 0.80
INTERVAL_WIDTH_MEDIUM = 0.95

# Below this many days of history, Prophet's weekly-seasonality fit isn't
# trustworthy yet, regardless of how dense the series otherwise is. Confirmed
# by backtest_real.py against this app's real order history (see
# ASSUMPTIONS.md): the fold trained on the bare MIN_HISTORY_DAYS (14) scored
# Prophet at 110.2% WAPE vs. a 94.8% seasonal-naive baseline (worse); folds
# trained on 21+ days scored Prophet at 85.4% vs. 86.1% (better). 28 days
# (4 weeks) sits inside the confirmed-good range and gives a clean,
# explainable floor for a capstone defense ("at least a month of history")
# rather than the bare minimum needed just to not divide by zero.
#
# Below this floor, classification still reports the item's *true*
# nonzero_day_ratio pattern (fast/medium/slow is a statement about
# consumption regularity, independent of history length) but forces routing
# through the trailing-average branch anyway, via slow_reason -- reusing the
# existing conservative, low-data-requirement model rather than inventing a
# second one, since the underlying problem (no real statistical basis for a
# seasonal decomposition yet) is the same one the "slow mover" branch already
# exists to handle.
PROPHET_MIN_TRAINING_DAYS = 28


def classify_demand(daily_quantities) -> dict:
    """
    Classify a daily demand series by how often it has nonzero sales, and by
    whether there's enough history yet to trust Prophet's seasonal fit.

        nonzero_day_ratio = (days with y > 0) / (total days in the window)

        fast   : ratio >= 0.70              -> Prophet, interval_width = 0.80
        medium : 0.30 <= ratio < 0.70       -> Prophet, interval_width = 0.95
        slow   : ratio < 0.30               -> trailing average, no Prophet

        Regardless of the above: total_days < PROPHET_MIN_TRAINING_DAYS ->
        demand_pattern is reported as "slow" (trailing average routing),
        with slow_reason="insufficient_history" so callers/UI can
        distinguish this from a genuinely sparse-selling item.

    Args:
        daily_quantities: list[float] or pd.Series of daily quantities
            (may include zeros; must be dense -- i.e. every calendar day
            in the window is represented, quiet days included as 0, not
            omitted).

    Returns:
        dict with keys:
            demand_pattern    : "fast" | "medium" | "slow"
            nonzero_day_ratio : float
            interval_width    : 0.80 | 0.95 | None (None for "slow", since
                                 the trailing-average branch has no Prophet
                                 confidence interval to configure)
            slow_reason       : "insufficient_history" | "sparse_demand" | None
                                 (None unless demand_pattern == "slow")
    """
    series = pd.Series(daily_quantities)
    total_days = len(series)

    if total_days == 0:
        # Degenerate empty input -- treat as slow/no-signal rather than
        # dividing by zero. Callers are expected to enforce MIN_HISTORY_DAYS
        # upstream (data_prep.py / the /forecast_policy endpoint), so this
        # is a defensive fallback, not the normal path.
        return {"demand_pattern": "slow", "nonzero_day_ratio": 0.0, "interval_width": None, "slow_reason": "insufficient_history"}

    nonzero_days = int((series > 0).sum())
    ratio = nonzero_days / total_days

    if total_days < PROPHET_MIN_TRAINING_DAYS:
        return {"demand_pattern": "slow", "nonzero_day_ratio": ratio, "interval_width": None, "slow_reason": "insufficient_history"}

    if ratio >= FAST_RATIO_THRESHOLD:
        return {"demand_pattern": "fast", "nonzero_day_ratio": ratio, "interval_width": INTERVAL_WIDTH_FAST, "slow_reason": None}
    if ratio >= MEDIUM_RATIO_THRESHOLD:
        return {"demand_pattern": "medium", "nonzero_day_ratio": ratio, "interval_width": INTERVAL_WIDTH_MEDIUM, "slow_reason": None}
    return {"demand_pattern": "slow", "nonzero_day_ratio": ratio, "interval_width": None, "slow_reason": "sparse_demand"}
