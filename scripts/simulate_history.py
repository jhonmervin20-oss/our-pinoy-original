"""
scripts/simulate_history.py

Generates SIMULATED trading history for 2024-01-01 .. 2026-05-06 -- the period
immediately before real recording began on 2026-05-07.

WHY THIS EXISTS, AND WHAT IT IS NOT
-----------------------------------
The forecasting pipeline can only learn a holiday effect if it has seen that
holiday several times. With four months of real data it has seen each holiday
once, which is why the production configuration excludes holidays from training
rather than fitting them. This script produces enough history to demonstrate the
holiday-aware configuration actually working.

Every row it writes is INVENTED. It is a demonstration of mechanism, not
evidence about this restaurant. Specifically:

  * The holiday multipliers below are ASSUMPTIONS chosen to be plausible for a
    Filipino restaurant. They were not measured. If the model later "discovers"
    that All Saints' Day runs +35%, it has recovered the number on line 78 of
    this file -- that is circular, and it is not a finding.
  * Any accuracy figure computed against a training set containing these rows
    measures how well Prophet recovers this generator. Real accuracy figures
    must be measured on 2026-05-07 onward only.

Every generated order is numbered `SIM-YYYY-NNNNNN`, so the whole dataset is
identifiable and removable in one statement:

    DELETE FROM orders WHERE order_number LIKE 'SIM-%';

order_items and order_payments both cascade on that delete.

NOTE: these are ordinary paid orders as far as the rest of the system is
concerned. They will appear in Sales reports, Analytics and dashboard revenue
for any date range covering 2024-2025. That is the cost of them being realistic
enough to train on; the DELETE above reverses it completely.

Usage:
    python simulate_history.py --dry-run     # report volumes, write nothing
    python simulate_history.py               # generate and insert
    python simulate_history.py --purge       # remove every SIM- order
"""

from __future__ import annotations

import argparse
import sys
from datetime import date, datetime, timedelta
from decimal import Decimal, ROUND_HALF_UP

import numpy as np

sys.path.insert(0, r"C:\xampp\htdocs\Our Pinoy Original\forecasting")
from db import engine, execute, read_sql  # noqa: E402
from sqlalchemy import text  # noqa: E402

START = date(2024, 1, 1)
END = date(2026, 5, 6)          # real recording starts the next day
CASHIER_ID = 7
VAT_RATE = Decimal("0.12")
SEED = 20260903                  # fixed: the dataset is reproducible

# Level at each end of the window. The end value is chosen to meet the real
# 2026 data (~22.7 orders/day) so the seam between simulated and real history
# is not a visible step the model would read as a changepoint.
BASE_START, BASE_END = 15.5, 22.3

# Weekday multipliers, taken from the REAL measured pattern so the simulated
# years share the restaurant's actual weekly rhythm rather than inventing one.
# Mon..Sun means 17.6 18.8 18.4 17.5 28.6 28.6 29.2 against an overall 22.7.
WEEKDAY = {0: 0.775, 1: 0.828, 2: 0.810, 3: 0.771, 4: 1.260, 5: 1.260, 6: 1.286}

# Month multipliers -- the yearly shape four months of real data cannot show.
# Kept deliberately mild; a restaurant has a season, not a cliff.
MONTH = {1: 0.92, 2: 0.97, 3: 1.02, 4: 1.03, 5: 1.01, 6: 0.99,
         7: 0.98, 8: 0.98, 9: 0.99, 10: 1.03, 11: 1.06, 12: 1.15}

# ---------------------------------------------------------------------------
# ASSUMED holiday effects. NOT MEASURED. See the module docstring.
# Rationale is recorded so a reader can judge each one rather than take it.
# ---------------------------------------------------------------------------
HOLIDAY = {
    "New Year's Day":            0.70,  # families at home
    "Chinese New Year":          1.20,
    "Eid'l Fitr":                1.00,  # little local effect assumed
    "Eid'l Adha":                1.00,
    "Maundy Thursday":           0.75,  # Holy Week travel/observance
    "Good Friday":               0.70,
    "Black Saturday":            0.85,
    "Araw ng Kagitingan":        1.12,
    "Labor Day":                 1.15,
    "Independence Day":          1.15,
    "Ninoy Aquino Day":          1.10,
    "National Heroes Day":       1.10,
    "All Saints' Day":           1.35,  # cemetery visits -> families eat out
    "All Souls' Day":            1.30,
    "Bonifacio Day":             1.12,
    "Feast of the Immaculate Conception of Mary": 1.10,
    "Christmas Eve":             1.30,  # busy right up to the evening
    "Christmas Day":             0.60,  # most families eat at home
    "Rizal Day":                 1.12,
    "Last Day of the Year":      1.25,
}


def holiday_factor(d: date, hol_by_date: dict) -> float:
    name = hol_by_date.get(d.isoformat())
    if not name:
        return 1.0
    for key, mult in HOLIDAY.items():
        if key.lower() in name.lower() or name.lower() in key.lower():
            return mult
    return 1.10  # an unrecognised public holiday still reads as a day off


def money(x) -> Decimal:
    return Decimal(str(x)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def main(dry_run: bool, purge: bool) -> int:
    if purge:
        n = execute("DELETE FROM orders WHERE order_number LIKE 'SIM-%'")
        print(f"Purged {n} simulated orders (items and payments cascaded).")
        return 0

    rng = np.random.default_rng(SEED)

    existing = read_sql("SELECT COUNT(*) c FROM orders WHERE order_number LIKE 'SIM-%'")
    if int(existing["c"].iloc[0]) and not dry_run:
        print("Simulated orders already present. Run with --purge first.")
        return 1

    items = read_sql(
        "SELECT item_id, selling_price, is_vat_exempt FROM menu_items WHERE is_active = 1"
    )
    if items.empty:
        print("No active menu items.")
        return 1

    # Sample dishes with the REAL observed popularity, so stage 3's measured mix
    # stays close to the live one instead of drifting to a flat distribution.
    mix = read_sql(
        """
        SELECT oi.menu_item_id AS item_id, SUM(oi.quantity) AS units
        FROM order_items oi
        JOIN orders o          ON o.order_id = oi.order_id
        JOIN order_payments op ON op.order_id = o.order_id
        WHERE op.payment_status = 'paid' AND oi.status <> 'cancelled'
        GROUP BY oi.menu_item_id
        """
    )
    items = items.merge(mix, on="item_id", how="left")
    items["units"] = items["units"].fillna(1.0).astype(float)
    weights = (items["units"] / items["units"].sum()).to_numpy()
    ids = items["item_id"].to_numpy()
    prices = {int(r.item_id): Decimal(str(r.selling_price)) for r in items.itertuples()}
    exempt = {int(r.item_id): bool(r.is_vat_exempt) for r in items.itertuples()}

    hols = read_sql("SELECT holiday_date, holiday_name FROM holidays WHERE is_active = 1")
    hol_by_date = {str(r.holiday_date): r.holiday_name for r in hols.itertuples()}
    covered = sum(1 for d in hol_by_date if START.isoformat() <= d <= END.isoformat())
    print(f"Holiday rows covering the simulated window: {covered}")
    if covered == 0:
        print("  WARNING: no holidays in range -- seed 2024/2025 holidays first,")
        print("  or the generated years carry no holiday signal to learn.")

    orders, order_items, payments = [], [], []
    seq = {}
    total_days = (END - START).days + 1

    for i in range(total_days):
        d = START + timedelta(days=i)
        base = BASE_START + (BASE_END - BASE_START) * (i / max(1, total_days - 1))
        lam = base * WEEKDAY[d.weekday()] * MONTH[d.month] * holiday_factor(d, hol_by_date)
        n_orders = int(rng.poisson(max(0.5, lam)))
        if n_orders <= 0:
            continue

        for _ in range(n_orders):
            yr = d.year
            seq[yr] = seq.get(yr, 0) + 1
            onum = f"SIM-{yr}-{seq[yr]:06d}"

            n_lines = int(rng.integers(1, 5))
            chosen = rng.choice(ids, size=n_lines, replace=False, p=weights)
            lines, total = [], Decimal("0.00")
            for iid in chosen:
                iid = int(iid)
                qty = int(rng.integers(1, 4))
                unit = prices[iid]
                sub = money(unit * qty)
                lines.append((iid, qty, unit, sub))
                total += sub

            vat = money(sum(
                (l[3] / (1 + VAT_RATE) * VAT_RATE) for l in lines if not exempt[l[0]]
            ))
            hour = int(rng.integers(11, 21))
            ts = datetime(d.year, d.month, d.day, hour,
                          int(rng.integers(0, 60)), int(rng.integers(0, 60)))

            orders.append({
                "n": onum,
                "t": "dine_in" if rng.random() < 0.72 else "takeout",
                "c": CASHIER_ID,
                "sub": money(total - vat),
                "vat": vat,
                "tot": total,
                "ts": ts,
            })
            payments.append({
                "n": onum,
                "m": "cash" if rng.random() < 0.99 else "paymongo_gcash",
                "a": total,
                "ts": ts,
            })
            for iid, qty, unit, sub in lines:
                order_items.append({"n": onum, "i": iid, "q": qty, "u": unit, "s": sub})

    print(f"Generated {len(orders):,} orders / {len(order_items):,} items "
          f"over {total_days:,} days ({len(orders)/total_days:.1f}/day average)")

    if dry_run:
        print("Dry run -- nothing written.")
        return 0

    with engine().begin() as conn:
        for k in range(0, len(orders), 500):
            conn.execute(text(
                "INSERT INTO orders (order_number, order_type, cashier_id, order_status,"
                " subtotal, vat_amount, total_amount, created_at, updated_at)"
                " VALUES (:n, :t, :c, 'completed', :sub, :vat, :tot, :ts, :ts)"
            ), orders[k:k + 500])
        print("  orders inserted")

        idmap = read_sql("SELECT order_id, order_number FROM orders WHERE order_number LIKE 'SIM-%'")
        lookup = dict(zip(idmap["order_number"], idmap["order_id"]))

        for r in order_items:
            r["oid"] = lookup[r["n"]]
        for r in payments:
            r["oid"] = lookup[r["n"]]

        for k in range(0, len(order_items), 1000):
            conn.execute(text(
                "INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price,"
                " subtotal, status) VALUES (:oid, :i, :q, :u, :s, 'served')"
            ), order_items[k:k + 1000])
        print("  order_items inserted")

        for k in range(0, len(payments), 500):
            conn.execute(text(
                "INSERT INTO order_payments (order_id, payment_method, amount,"
                " amount_tendered, payment_status, paid_at, created_at)"
                " VALUES (:oid, :m, :a, :a, 'paid', :ts, :ts)"
            ), payments[k:k + 500])
        print("  order_payments inserted")

    print("Done. Remove with: python simulate_history.py --purge")
    return 0


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--purge", action="store_true")
    a = ap.parse_args()
    sys.exit(main(a.dry_run, a.purge))
