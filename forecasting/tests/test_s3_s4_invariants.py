"""
Stages 3 and 4 are pure arithmetic with known answers, so the invariants are
tested directly rather than eyeballed.

Two must always hold:
  s3: the split sums to the predicted total item count
  s4: contribution rows for an ingredient sum exactly to its forecast row
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pandas as pd
import pytest

from stages.s3_menu_split import renormalise, split
from stages.s4_explode import explode, summarise


@pytest.fixture
def forecast():
    """Three days: a Friday, a Saturday, a Monday."""
    return pd.DataFrame(
        {
            "ds": pd.to_datetime(["2026-09-04", "2026-09-05", "2026-09-07"]),
            "yhat": [29.3, 29.5, 17.3],
            "yhat_lower": [21.0, 21.5, 12.0],
            "yhat_upper": [37.6, 37.5, 22.0],
        }
    )


@pytest.fixture
def shares():
    """Two dishes. Grilled Tanigue is a Friday favourite; Sisig is steady."""
    return pd.DataFrame(
        {
            "menu_item_id": [1, 1, 1, 2, 2, 2],
            "weekday": [4, 5, 0, 4, 5, 0],           # Fri, Sat, Mon
            "share": [0.1233, 0.0800, 0.0500, 0.2400, 0.2400, 0.2400],
        }
    )


# --------------------------------------------------------------------- stage 3

def test_the_grilled_tanigue_trace_reproduces(forecast, shares):
    """Section 3: 29.3 orders x 0.1233 share = 3.61 servings. To the gram."""
    out = split(forecast, shares)
    fri = out[(out["menu_item_id"] == 1) & (out["ds"] == pd.Timestamp("2026-09-04"))]
    assert round(float(fri["servings"].iloc[0]), 2) == 3.61


def test_split_sums_to_the_predicted_total(forecast, shares):
    """The invariant: the split cannot invent or lose demand."""
    raw = split(forecast, shares)
    units_per_order = 2.5
    final = renormalise(raw, forecast, units_per_order)

    per_day = final.groupby("ds")["servings"].sum()
    for _, row in forecast.iterrows():
        expected = row["yhat"] * units_per_order
        assert abs(per_day[row["ds"]] - expected) < 0.01, (
            f"{row['ds'].date()}: split sums to {per_day[row['ds']]:.4f}, expected {expected:.4f}"
        )


def test_rounding_up_only_ever_adds_and_never_by_a_whole_serving_per_dish(forecast, shares):
    """
    Whole-serving rounding replaces the exact-sum invariant with a bounded one.

    The kitchen cooks whole portions, so the split is ceilinged per dish-day
    before it reaches stage 4. That deliberately breaks "the split cannot invent
    demand" -- it can only invent, never lose -- so the guarantee that remains is
    a bound: each dish-day gains strictly less than one serving, therefore a day
    gains strictly less than one serving per dish with a row that day.

    Anything outside that bound is a bug, not a rounding effect.
    """
    from stages.s3_menu_split import round_servings_up

    final = renormalise(split(forecast, shares), forecast, 2.5)
    rounded = round_servings_up(final)

    assert (rounded["servings"] >= final["servings"] - 1e-9).all(), "rounding lost demand"
    assert (rounded["servings"] < final["servings"] + 1.0 + 1e-9).all(), \
        "a dish-day gained a whole serving or more"
    assert (rounded["servings"] % 1 == 0).all(), "servings are not whole numbers"

    per_day_before = final.groupby("ds")["servings"].sum()
    per_day_after = rounded.groupby("ds")["servings"].sum()
    dishes_per_day = final.groupby("ds")["menu_item_id"].nunique()
    for day in per_day_before.index:
        gained = per_day_after[day] - per_day_before[day]
        assert 0 <= gained < dishes_per_day[day] + 1e-9, (
            f"{day.date()}: gained {gained:.4f} servings across "
            f"{dishes_per_day[day]} dishes"
        )


def test_renormalising_preserves_the_measured_mix(forecast, shares):
    """Scaling changes the totals, never the ratio between dishes -- the ratio
    is the part that was actually measured."""
    raw = split(forecast, shares)
    final = renormalise(raw, forecast, 2.5)
    day = pd.Timestamp("2026-09-04")

    def ratio(df):
        a = float(df[(df["menu_item_id"] == 1) & (df["ds"] == day)]["servings"].iloc[0])
        b = float(df[(df["menu_item_id"] == 2) & (df["ds"] == day)]["servings"].iloc[0])
        return a / b

    assert abs(ratio(raw) - ratio(final)) < 1e-9


# --------------------------------------------------------------------- stage 4

@pytest.fixture
def factors():
    """Grilled Tanigue -> 200 g tanigue, converted to 0.2 kg base units."""
    return pd.DataFrame(
        {
            "menu_item_id": [1, 1, 2],
            "item_id": [10, 11, 12],
            "source_type": ["recipe", "recipe", "recipe"],
            "per_unit_required": [0.2, 2.0, 0.15],   # kg tanigue, pcs calamansi, kg pork
            "item_name": ["Tanigue", "Calamansi", "Pork"],
            "unit_code": ["kg", "pcs", "kg"],
            "unit_type": ["weight", "count", "weight"],
            "convertible": [True, True, True],
        }
    )


def test_tanigue_explodes_to_the_documented_kilograms(forecast, shares, factors):
    """
    The plan's section 3 says 3.61 servings x 200 g = 0.722 kg. The pipeline
    gets 0.723, and the pipeline is right.

    The difference is rounding ORDER, not arithmetic. The document rounds
    servings to 3.61 first and then multiplies (3.61 x 0.2 = 0.722). The code
    carries full precision to the last step, per CLAUDE.md: 29.3 x 0.1233 =
    3.61269 servings, x 0.2 = 0.72254 -> 0.723.

    One gram. Worth knowing before a panel recomputes the slide by hand and
    finds a number that does not match the screen.
    """
    out = split(forecast, shares)
    contrib = explode(out, factors)
    fri = contrib[
        (contrib["item_id"] == 10) & (contrib["ds"] == pd.Timestamp("2026-09-04"))
    ]
    qty = float(fri["contributed_qty"].iloc[0])
    assert round(qty, 3) == 0.723
    # And the document's hand-computed figure is within a gram of it.
    assert abs(qty - 0.722) < 0.0015


def test_contributions_sum_exactly_to_the_forecast_row(forecast, shares, factors):
    """The invariant: a manager clicking an ingredient must see numbers that
    add up to the figure they clicked on."""
    out = split(forecast, shares)
    contrib = explode(out, factors)
    totals = summarise(contrib)

    for r in totals.itertuples():
        parts = contrib[(contrib["item_id"] == r.item_id) & (contrib["ds"] == r.ds)]
        assert abs(parts["contributed_qty"].sum() - r.predicted_qty) < 1e-9


def test_an_unconvertible_ingredient_is_dropped_not_guessed(forecast, shares, factors):
    """A missing unit conversion must not silently become a wrong quantity."""
    broken = factors.copy()
    broken.loc[broken["item_id"] == 10, "convertible"] = False
    contrib = explode(split(forecast, shares), broken)
    assert 10 not in set(contrib["item_id"])
