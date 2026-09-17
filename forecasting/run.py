"""
forecasting/run.py

Opens a forecast_runs row, calls the seven stages in order, closes it.

A stage that fails marks the run `partial` or `failed` and says why. The one
outcome this pipeline is not allowed to produce is a half-written forecast with
no record of what happened.

Usage:
    python run.py                 # full run, writes to the database
    python run.py --dry-run       # compute everything, write nothing
    python run.py --skip-accuracy # skip stage 7 (it refits Prophet many times)
"""

from __future__ import annotations

import json
import sys
import traceback
from datetime import date, datetime

import config
from db import execute, scalar
from stages import (
    s1_history,
    s2_forecast,
    s3_menu_split,
    s4_explode,
    s5_reconcile,
    s6_reorder,
    s7_accuracy,
)

RUN_TYPE = "ingredient_policy_sweep"


def open_run() -> int:
    key = f"{RUN_TYPE}:{date.today():%Y-%m-%d}:{datetime.now():%H%M%S}"
    execute(
        """
        INSERT INTO forecast_runs (run_type, status, idempotency_key, started_at)
        VALUES (:t, 'running', :k, NOW())
        """,
        {"t": RUN_TYPE, "k": key},
    )
    return int(scalar("SELECT run_id FROM forecast_runs WHERE idempotency_key = :k", {"k": key}))


def close_run(run_id: int, status: str, params: dict, engine_version: str | None = None,
              error: str | None = None) -> None:
    execute(
        """
        UPDATE forecast_runs
           SET status = :s, params_json = :p, engine_version = :e,
               error_message = :err, finished_at = NOW()
         WHERE run_id = :id
        """,
        {
            "s": status,
            "p": json.dumps(params, default=str),
            "e": engine_version,
            "err": error,
            "id": run_id,
        },
    )


def main(dry_run: bool = False, skip_accuracy: bool = False) -> int:
    run_id = open_run()
    params: dict = {"dry_run": dry_run}
    engine_version = None
    print(f"run {run_id} started{' (dry run)' if dry_run else ''}")

    try:
        # -- 1 ---------------------------------------------------------------
        series, m1 = s1_history.run(run_id)
        params["s1_history"] = m1
        print(f"  s1  {m1['history_days_used']} trading days "
              f"{m1['training_start']} -> {m1['training_end']}, "
              f"{m1['excluded_count']} excluded")

        # -- 2 ---------------------------------------------------------------
        # Forecast the LONGEST horizon the Demand Forecasting page can be
        # switched to, not the planning horizon.
        #
        # These are two different jobs. The page is a viewer: an owner switches
        # between 7 and 14 days to see what each would cost before committing to
        # one. Purchasing is a decision: s6 sizes every order against the
        # planning horizon in Settings, and switching the page must never change
        # what gets ordered.
        #
        # Storing only the planning horizon's days made the viewer a lie -- with
        # Settings on 7, selecting 14 still showed 7 days of demand because days
        # 8-14 had never been computed. Forecasting the maximum means the viewer
        # always has the days it offers; s6 still slices only the ones the
        # setting entitles it to, so the order is unaffected.
        forecast, m2 = s2_forecast.run(series, horizon_days=config.get_int("forecast_view_max_days"))
        engine_version = m2["engine_version"]
        params["s2_forecast"] = m2
        print(f"  s2  {m2['engine_version']}, {len(forecast)} days "
              f"{m2['forecast_start']} -> {m2['forecast_end']}")

        # -- 3 ---------------------------------------------------------------
        split, m3 = s3_menu_split.run(run_id, forecast, m1["training_end"])
        params["s3_menu_split"] = m3
        print(f"  s3  {m3['dishes_with_history']} dishes split, "
              f"{m3['units_per_order']} units/order")

        # -- 4 ---------------------------------------------------------------
        contributions, totals, m4 = s4_explode.run(run_id, split)
        params["s4_explode"] = {k: v for k, v in m4.items() if k != "conversion_gaps"}
        params["s4_explode"]["conversion_gap_items"] = sorted(
            {int(g["item_id"]) for g in m4["conversion_gaps"]}
        )
        print(f"  s4  {m4['ingredients_forecast']} ingredients from "
              f"{m4['contribution_rows']} contributions, "
              f"{m4['conversion_gap_count']} conversion gaps")

        # -- 5 ---------------------------------------------------------------
        factors = s4_explode.recipe_factors()
        reconciled, m5 = s5_reconcile.run(run_id, totals, factors)
        params["s5_reconcile"] = m5
        print(f"  s5  {m5['floored_rows']} rows floored by bookings, "
              f"{m5['adjusted_rows']} adjusted")

        # -- 6 ---------------------------------------------------------------
        gap_items = {int(g["item_id"]) for g in m4["conversion_gaps"]}
        suggestions, m6 = s6_reorder.run(run_id, reconciled, gap_items, m1["training_end"])
        params["s6_reorder"] = m6
        print(f"  s6  {m6['items_evaluated']} items: {m6['decisions']}, "
              f"driven by {m6['driven_by']}")

        # -- writes ----------------------------------------------------------
        if not dry_run:
            n3 = s3_menu_split.persist(run_id, split)
            n4a, n4b = s4_explode.persist(run_id, contributions, totals)
            n5 = s5_reconcile.persist(run_id, reconciled)
            n6 = s6_reorder.persist(run_id, suggestions)
            print(f"  db  menu={n3} contrib={n4a} ingredient={n4b} "
                  f"reconciled={n5} suggestions={n6}")

        # -- 7 ---------------------------------------------------------------
        if not skip_accuracy:
            acc, m7 = s7_accuracy.run(series)
            params["s7_accuracy"] = m7
            for r in acc:
                print(f"  s7  {r['horizon_days']:>2}d  WAPE {r['wape_pct']:.1f}%  "
                      f"baseline {r['baseline_wape_pct']:.1f}%  "
                      f"{'beats' if r['beats_baseline'] else 'LOSES to'} baseline  "
                      f"({r['fold_count']} folds)")
            if not dry_run:
                s7_accuracy.persist(run_id, acc)
        else:
            print("  s7  skipped")

        params["settings_defaulted"] = config.missing_keys()
        close_run(run_id, "completed", params, engine_version)
        print(f"run {run_id} completed")
        return 0

    except Exception as exc:  # noqa: BLE001 -- the run record must always close
        close_run(run_id, "failed", params, engine_version, f"{type(exc).__name__}: {exc}")
        print(f"run {run_id} FAILED: {exc}", file=sys.stderr)
        traceback.print_exc()
        return 1


if __name__ == "__main__":
    sys.exit(main(
        dry_run="--dry-run" in sys.argv,
        skip_accuracy="--skip-accuracy" in sys.argv,
    ))
