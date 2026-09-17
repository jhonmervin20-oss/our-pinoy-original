"""
Stage 6 -- the buying arithmetic and the five decision branches.

WHEN to reorder and HOW MUCH to buy are two independent numbers now:
reorder_level is read straight off the item (typed by the owner, never
computed), walked day by day against the forecast and any dated open-PO
receipts; the purchase quantity is a separate 7-day-demand-plus-safety-stock
target, net of stock and whatever incoming PO quantity actually lands
inside the horizon. The tests below are organised around that split.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pandas as pd
import pytest

from stages.s6_reorder import decide, round_up, window_sum


# ------------------------------------------------------------------- rounding

@pytest.mark.parametrize(
    "qty,unit_type,expected",
    [
        # Countable things round UP -- you cannot order a fraction of an egg.
        (6.40, "count", 7.0),
        (6.00, "count", 6.0),
        (0.10, "count", 1.0),
        # Weight and volume round UP to 10 g / 10 ml -- the finest amount a
        # supplier can actually measure out. Three decimals asked for 2.724 kg
        # of beef and 0.034 l of concentrate, which nobody weighs.
        (2.87,   "weight", 2.87),
        (2.053,  "weight", 2.06),
        (2.724,  "weight", 2.73),
        (0.296,  "volume", 0.30),
        (0.034,  "volume", 0.04),
        # Already exact: rounding up must not add a step to a clean number.
        (0.25,   "weight", 0.25),
    ],
)
def test_orders_round_up_to_something_orderable(qty, unit_type, expected):
    assert round_up(qty, unit_type) == pytest.approx(expected)


def test_rounding_up_never_inflates_more_than_one_step():
    """
    The guard against the old 0.25-step behaviour, which turned a 0.296 l order
    into 0.500 l. Rounding may add at most 10 g / 10 ml, never more.
    """
    for qty in (0.001, 0.034, 0.233, 0.296, 2.053, 2.724, 19.999):
        up = round_up(qty, "weight")
        assert up >= qty
        assert up - qty < 0.01


# --------------------------------------------------------------- window sums

def _demand(item_id, quantities, start="2026-09-04"):
    return pd.DataFrame({
        "item_id": [item_id] * len(quantities),
        "ds": pd.date_range(start, periods=len(quantities), freq="D"),
        "overlaid_qty": quantities,
        "predicted_qty": quantities,
    })


def test_window_sum_is_the_trace_sum():
    """Fri 1.316 + Sat 0.727 + Sun 0.718 + Mon 0.427 = 3.188 over 4 days."""
    d = _demand(10, [1.316, 0.727, 0.718, 0.427, 0.5])
    assert window_sum(d, 10, 4) == pytest.approx(3.188)


def test_window_sum_of_a_missing_item_is_zero():
    assert window_sum(_demand(10, [1.0]), 99, 7) == 0.0


# ------------------------------------------------------------ fixtures

def _policy(**over):
    base = {
        "item_id": 10, "item_name": "Tanigue", "lead_time_days": 1,
        "preferred_supplier_id": 1,
        # reorder_level is the owner's own typed number -- deliberately NOT
        # derived from lead time or safety stock, and deliberately not equal
        # to either so a test can't pass by accident.
        "reorder_level": 2.0,
        "safety_stock_qty": 1.316,
        "last_purchase_cost": 380.0, "category_name": "Seafood",
        "unit_code": "kg", "unit_type": "weight", "supplier_name": "JM STORE",
        "on_hand": 0.90, "on_order": 0.0, "dish_count": 1,
    }
    base.update(over)
    return pd.DataFrame([base])


def _receipts(item_id, entries):
    """entries: list of (date_str, qty) -- one open PO line each."""
    if not entries:
        return pd.DataFrame(columns=["item_id", "expected_delivery_date", "qty"])
    return pd.DataFrame({
        "item_id": [item_id] * len(entries),
        "expected_delivery_date": [pd.Timestamp(d).date() for d, _ in entries],
        "qty": [q for _, q in entries],
    })


NO_RECEIPTS = _receipts(10, [])

# Seven days starting 2026-09-04, the same trace every test reasons from.
DEMAND = _demand(10, [1.316, 0.727, 0.718, 0.427, 0.5, 0.5, 0.5])
HORIZON_DEMAND = 1.316 + 0.727 + 0.718 + 0.427 + 0.5 + 0.5 + 0.5  # 4.688
SAFETY = 1.316
TARGET = HORIZON_DEMAND + SAFETY  # 6.004 -- 7-day demand + safety stock
REORDER_LEVEL = 2.0


def _decide(**policy_over):
    receipts_over = policy_over.pop("_receipts", NO_RECEIPTS)
    gaps = policy_over.pop("_gaps", set())
    return decide(_policy(**policy_over), DEMAND, receipts_over, gaps).iloc[0]


# ------------------------------------------------------------ structural branches

def test_no_supplier_is_flagged_not_silently_skipped():
    row = _decide(preferred_supplier_id=None)
    assert row["decision"] == "flagged_no_supplier"
    assert row["gated_reason"] is not None


def test_a_conversion_gap_is_its_own_outcome():
    row = _decide(_gaps={10})
    assert row["decision"] == "skipped_conversion_gap"


def test_every_item_gets_a_row_whatever_happens():
    """A buyer must always be able to answer 'why did nothing happen today?'"""
    for kw in [{}, {"preferred_supplier_id": None}, {"_gaps": {10}}, {"on_hand": 999.0}]:
        assert len(decide(_policy(**{k: v for k, v in kw.items() if k != "_gaps"}),
                           DEMAND, NO_RECEIPTS, kw.get("_gaps", set()))) == 1


# ------------------------------------------------------------ WHEN: reorder_level

def test_reorder_level_is_read_from_the_item_never_computed():
    """
    The core of the redesign. reorder_level must come straight off the row,
    completely unaffected by lead time or safety stock -- the opposite of
    the old rule, where it was lead-time demand + safety stock and could
    never be typed at all.
    """
    row = _decide(reorder_level=8.0, lead_time_days=5, safety_stock_qty=99.0)
    assert row["reorder_level"] == pytest.approx(8.0)


def test_stock_that_never_reaches_the_reorder_level_is_sufficient():
    """Comfortably above reorder_level even after a full week of demand with
    no incoming PO at all -- the walk never trips, so nothing is suggested."""
    row = _decide(on_hand=REORDER_LEVEL + HORIZON_DEMAND + 0.001)
    assert row["decision"] == "no_action"
    assert row["gated_reason"] is None
    assert row["final_purchase_qty"] == 0


def test_stock_at_the_reorder_level_right_now_still_needs_replenishing():
    """
    Sitting exactly AT the reorder level today is not "nothing to do" under a
    forward-looking walk -- tomorrow's demand pushes it under before any PO
    can help, so it is due today. This is the deliberate behaviour change
    from a single snapshot check to a real day-by-day projection.
    """
    row = _decide(on_hand=REORDER_LEVEL)
    assert row["decision"] == "drafted"
    assert row["urgency"] == "critical"


def test_urgency_is_critical_only_when_already_short_today():
    """Breaching the reorder level on day 1 is critical; breaching it later
    in the week (because the shelf empties gradually) is merely normal."""
    already_short = _decide(on_hand=0.0)
    assert already_short["urgency"] == "critical"

    # Enough to clear today and tomorrow, but the week's demand still wears
    # it down to the reorder level by day 3.
    day1, day2 = 1.316, 0.727
    later = _decide(on_hand=REORDER_LEVEL + day1 + day2 + 0.001)
    assert later["decision"] == "drafted"
    assert later["urgency"] == "normal"


# ------------------------------------------------------------ HOW MUCH: target stock

def test_target_stock_is_seven_day_demand_plus_safety_stock():
    """The other half of the split -- sizing has nothing to do with
    reorder_level, only the horizon's own forecast plus the safety buffer."""
    row = _decide(on_hand=0.0)
    assert row["restock_target"] == pytest.approx(TARGET)
    assert row["raw_suggested_qty"] == pytest.approx(TARGET)


def test_suggested_quantity_nets_off_current_stock():
    row = _decide(on_hand=1.0)
    assert row["decision"] == "drafted"
    assert row["raw_suggested_qty"] == pytest.approx(TARGET - 1.0)


def test_a_reorder_level_set_above_target_does_not_falsely_claim_a_po_covered_it():
    """
    Found against live data, not invented: reorder_level and target_stock are
    fully independent now, so an owner can set reorder_level HIGHER than what
    7-day-demand-plus-safety would ask for (e.g. wanting an early, cautious
    trigger on a slow-to-source item). That crosses reorder_level and
    triggers -- but if on-hand stock alone already exceeds target_stock, there
    is genuinely nothing to buy and NO purchase order is involved at all. That
    must read as "Sufficient Stock", never "Covered by an existing purchase
    order" -- there isn't one.
    """
    row = _decide(reorder_level=8.0, on_hand=7.0)  # crosses 8.0 on day 1, but 7.0 > TARGET (6.004)
    assert row["decision"] == "no_action"
    assert row["final_purchase_qty"] == 0
    assert row["gated_reason"] is None
    assert row["suppressed_by_open_commitment"] == 0


def test_the_order_is_sized_to_the_full_week_not_just_back_to_the_line():
    """
    The regression this design was chosen over: an order that only topped up
    to the reorder point bought exactly enough to survive the next delivery
    and re-triggered immediately once it landed. Sizing to the horizon is
    what makes an order actually last a week.
    """
    row = _decide(on_hand=0.0)
    assert row["raw_suggested_qty"] > REORDER_LEVEL
    assert row["raw_suggested_qty"] == pytest.approx(TARGET)


# ------------------------------------------------------- incoming PO qualification

def test_a_receipt_inside_the_horizon_reduces_the_suggested_quantity():
    row = _decide(on_hand=0.0, _receipts=_receipts(10, [("2026-09-06", 1.0)]))  # day 3
    assert row["decision"] == "drafted"
    assert row["raw_suggested_qty"] == pytest.approx(TARGET - 1.0)


def test_a_receipt_covering_the_whole_target_suppresses_a_new_po():
    """
    Clause: existing incoming PO quantities enough to cover the projected
    requirement mean no new PO, and the row says why -- even though the
    reorder level was genuinely breached earlier in the week (on_hand=0
    triggers on day 1), a large-enough delivery later in the SAME horizon
    still means nothing new needs to be drafted.
    """
    row = _decide(on_hand=0.0, _receipts=_receipts(10, [("2026-09-05", TARGET)]))  # day 2
    assert row["decision"] == "no_action"
    assert row["suppressed_by_open_commitment"] == 1
    assert "existing purchase order" in row["gated_reason"]
    assert row["final_purchase_qty"] == 0


def test_a_receipt_outside_the_horizon_does_not_qualify():
    """
    The rule the whole redesign turns on: an expected delivery date makes a
    PO count only when it falls inside the planning window being walked.
    Ten days out, on a 7-day horizon, must not reduce the suggestion at all
    -- regardless of how large the quantity is.
    """
    row = _decide(on_hand=0.0, _receipts=_receipts(10, [("2026-09-14", 999.0)]))  # day 11
    assert row["decision"] == "drafted"
    assert row["raw_suggested_qty"] == pytest.approx(TARGET)


def test_healthy_stock_is_not_labelled_as_rescued_by_a_po():
    """
    An open PO exists, but stock was never projected to breach the reorder
    level at all -- calling that "covered by an existing PO" would misreport
    healthy stock as a near miss.
    """
    row = _decide(
        on_hand=REORDER_LEVEL + HORIZON_DEMAND + 0.001,
        _receipts=_receipts(10, [("2026-09-05", 5.0)]),
    )
    assert row["decision"] == "no_action"
    assert row["suppressed_by_open_commitment"] == 0
    assert row["gated_reason"] is None


def test_a_receipt_on_a_specific_day_only_credits_that_day():
    """
    Projected Stock(day) = Projected Stock(day-1) + receipts that day -
    forecast demand that day. A receipt dated day 5 must not appear in the
    running balance any earlier than day 5.
    """
    no_po = decide(_policy(on_hand=0.0), DEMAND, NO_RECEIPTS, set()).iloc[0]
    with_late_po = decide(
        _policy(on_hand=0.0), DEMAND,
        _receipts(10, [("2026-09-08", 3.0)]),  # day 5
        set(),
    ).iloc[0]
    # Both still breach the reorder level on day 1 -- the day-5 receipt
    # cannot retroactively change that.
    assert no_po["urgency"] == with_late_po["urgency"] == "critical"
    # But the day-5 receipt DOES qualify (it's inside the 7-day horizon), so
    # it still reduces how much is suggested.
    assert with_late_po["raw_suggested_qty"] == pytest.approx(no_po["raw_suggested_qty"] - 3.0)


# ------------------------------------------------------- lead time is reference only

def test_lead_time_no_longer_feeds_the_trigger_or_the_target():
    """
    Lead time is retained purely as reference data now (still surfaced as
    lead_time_demand_qty). Changing it must not move reorder_level,
    restock_target, or the decision at all.
    """
    short_lead = _decide(lead_time_days=1, on_hand=1.0)
    long_lead = _decide(lead_time_days=6, on_hand=1.0)
    assert short_lead["reorder_level"] == long_lead["reorder_level"]
    assert short_lead["restock_target"] == pytest.approx(long_lead["restock_target"])
    assert short_lead["decision"] == long_lead["decision"]
    assert short_lead["raw_suggested_qty"] == pytest.approx(long_lead["raw_suggested_qty"])


def test_lead_time_demand_is_still_reported_for_reference():
    row = _decide(lead_time_days=4)
    assert row["lead_time_demand_qty"] == pytest.approx(window_sum(DEMAND, 10, 4))
