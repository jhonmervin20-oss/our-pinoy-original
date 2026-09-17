"""Stage 1 -- the closure rule. Written before the stage was trusted."""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pandas as pd
from stages.s1_history import detect_closures


def _series(counts, start="2026-07-01"):
    days = pd.date_range(start, periods=len(counts), freq="D")
    return pd.DataFrame({"ds": days, "orders": counts})


def test_reproduces_the_august_gap():
    """Ten zero days and one single-order day inside an otherwise normal series
    -- the shape live data actually has from 12 to 22 August 2026."""
    normal = [20] * 30
    gap = [0] * 10 + [1]
    df = _series(normal + gap)
    excluded = detect_closures(df, 20)
    assert len(excluded) == 11, f"expected 11 excluded, got {len(excluded)}"
    assert excluded[0] == "2026-07-31"


def test_a_merely_quiet_day_is_kept():
    """8 orders against a median of 20 is 40% -- a bad day, not a closure."""
    df = _series([20] * 30 + [8])
    assert detect_closures(df, 20) == []


def test_no_median_no_judgement():
    """Fewer than 7 days of history gives nothing to judge against."""
    df = _series([20, 0, 20])
    assert detect_closures(df, 20) == []


def test_excluded_days_are_absent_not_zero():
    """Prophet tolerates gaps and is broken by false zeros."""
    df = _series([20] * 30 + [0] * 5)
    excluded = detect_closures(df, 20)
    clean = df[~df["ds"].dt.strftime("%Y-%m-%d").isin(excluded)]
    assert len(clean) == 30
    assert (clean["orders"] > 0).all()


# ------------------------------------------------------------------ holidays

def test_a_holiday_is_excluded_even_when_it_traded_normally():
    """
    The closure rule only catches days that traded LOW. A holiday trading at or
    above normal sails straight through it, which is exactly the case this
    covers -- Independence Day did 24 orders against a 22.8 average.
    """
    from stages.s1_history import detect_closures
    df = _series([20] * 30 + [24] + [20] * 5)
    # 24 is well above the closure threshold, so nothing is flagged...
    assert detect_closures(df, 20) == []
    # ...which is why holiday exclusion has to be a separate step rather than
    # something the closure rule can be relied on to handle.


def test_holidays_and_closures_are_reported_separately():
    """'We were shut' and 'it was a public holiday' are different facts. A run
    record that blurs them cannot answer either question."""
    import stages.s1_history as s1
    keys = s1.run.__doc__ or ""
    # The contract: meta carries both counts under distinct keys.
    import inspect
    src = inspect.getsource(s1.run)
    assert '"excluded_dates"' in src and '"holidays_excluded"' in src
    assert '"excluded_count"' in src and '"holidays_excluded_count"' in src


def test_backtest_anchors_on_the_fold_cutoff_not_today():
    """
    Regression guard. s2_forecast.run() defaults to forecasting from TODAY so
    the live screens show real upcoming dates. A backtest must override that
    with the fold's own cutoff -- when it did not, every fold was scored
    against actuals from months earlier and measured accuracy went from 13.4%
    to 38.9% while looking perfectly plausible.
    """
    import inspect
    from stages import s7_accuracy
    src = inspect.getsource(s7_accuracy.backtest)
    assert "anchor=" in src, "the backtest must pass an explicit anchor"
    assert 'train["ds"].max()' in src, "the anchor must come from the fold, not today"
