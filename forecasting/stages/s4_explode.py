"""
forecasting/stages/s4_explode.py

Stage 4 -- explode dishes into ingredients.

Multiply each dish's predicted servings by every line of its active recipe,
convert into the ingredient's base unit, and sum across dishes. Packaging
(packaging_rule_items) is deliberately excluded: it tracks takeout order
COUNT rather than dish composition, and forecasting it alongside real
ingredients put a container on the same footing as food in every forecast,
policy and purchase recommendation. Packaging is reordered from its own
stock levels, not from this demand model (per user direction 2026-09-10).

One contribution row is written per dish-ingredient-day BEFORE summing. That is
what lets a manager click a kilogram of garlic and see the dishes that asked for
it -- and it is what makes the total checkable rather than asserted.
"""

from __future__ import annotations

import pandas as pd

from db import execute, read_sql


def recipe_factors() -> pd.DataFrame:
    """
    menu_item_id, item_id, source_type, per_unit_required (in the ingredient's
    base unit), plus item_name/unit for reporting.

    The conversion is resolved in SQL against unit_conversions so a missing
    conversion path shows up as an absent row rather than a silently wrong
    number -- stage 6 reports those as skipped_conversion_gap.
    """
    recipe_sql = """
        SELECT
            mii.menu_item_id,
            mii.inventory_item_id                        AS item_id,
            'recipe'                                     AS source_type,
            mii.quantity_required * COALESCE(uc.conversion_factor, 1.0) AS per_unit_required,
            i.item_name,
            u.unit_code,
            u.unit_type,
            (mii.recipe_unit_id = i.base_unit_id OR uc.conversion_factor IS NOT NULL) AS convertible
        FROM menu_item_ingredients mii
        JOIN recipes r        ON r.menu_item_id = mii.menu_item_id AND r.is_active = 1
        JOIN inventory_items i ON i.item_id     = mii.inventory_item_id AND i.is_active = 1
        JOIN unit_of_measures u ON u.unit_id    = i.base_unit_id
        LEFT JOIN unit_conversions uc
               ON uc.from_unit_id = mii.recipe_unit_id
              AND uc.to_unit_id   = i.base_unit_id
    """
    df = read_sql(recipe_sql)
    df["per_unit_required"] = df["per_unit_required"].astype(float)
    df["convertible"] = df["convertible"].astype(bool)
    return df


def explode(split_df: pd.DataFrame, factors: pd.DataFrame) -> pd.DataFrame:
    """
    Returns contributions: item_id, ds, menu_item_id, source_type,
    servings, per_unit_required, contributed_qty.
    """
    if split_df.empty or factors.empty:
        return pd.DataFrame(
            columns=["item_id", "ds", "menu_item_id", "source_type",
                     "servings", "per_unit_required", "contributed_qty"]
        )

    usable = factors[factors["convertible"]]
    out = split_df.merge(usable, on="menu_item_id", how="inner")
    out["contributed_qty"] = out["servings"].astype(float) * out["per_unit_required"]
    return out[["item_id", "ds", "menu_item_id", "source_type",
                "servings", "per_unit_required", "contributed_qty",
                "item_name", "unit_code", "unit_type"]]


def summarise(contributions: pd.DataFrame) -> pd.DataFrame:
    """Sum contributions into one predicted quantity per ingredient per day."""
    if contributions.empty:
        return pd.DataFrame(columns=["item_id", "ds", "predicted_qty"])
    return (
        contributions.groupby(["item_id", "ds"], as_index=False)["contributed_qty"]
        .sum()
        .rename(columns={"contributed_qty": "predicted_qty"})
    )


def run(run_id: int, split_df: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame, dict]:
    factors = recipe_factors()
    contributions = explode(split_df, factors)
    totals = summarise(contributions)

    gaps = factors[~factors["convertible"]][["menu_item_id", "item_id", "source_type"]]
    meta = {
        "ingredients_forecast": int(totals["item_id"].nunique()) if not totals.empty else 0,
        "contribution_rows": int(len(contributions)),
        "conversion_gaps": gaps.to_dict("records"),
        "conversion_gap_count": int(len(gaps)),
    }
    return contributions, totals, meta


def persist(run_id: int, contributions: pd.DataFrame, totals: pd.DataFrame) -> tuple[int, int]:
    if contributions.empty:
        return 0, 0

    contrib_rows = [
        {
            "run_id": run_id,
            "item_id": int(r.item_id),
            "consumption_date": r.ds.strftime("%Y-%m-%d"),
            "menu_item_id": int(r.menu_item_id),
            "source_type": r.source_type,
            "quantity_sold": round(float(r.servings), 3),
            "per_unit_required": round(float(r.per_unit_required), 6),
            "contributed_qty": round(float(r.contributed_qty), 3),
        }
        for r in contributions.itertuples()
    ]
    execute(
        """
        INSERT INTO ingredient_demand_contributions
            (run_id, item_id, consumption_date, menu_item_id, source_type,
             quantity_sold, per_unit_required, contributed_qty)
        VALUES
            (:run_id, :item_id, :consumption_date, :menu_item_id, :source_type,
             :quantity_sold, :per_unit_required, :contributed_qty)
        ON DUPLICATE KEY UPDATE
            quantity_sold   = VALUES(quantity_sold),
            per_unit_required = VALUES(per_unit_required),
            contributed_qty = VALUES(contributed_qty)
        """,
        contrib_rows,
    )

    # predicted_qty only at this stage. committed_floor/adjustment/overlaid are
    # stage 5's job -- seeded so the NOT NULL columns are satisfied and so a
    # half-run is visibly missing its reconciliation rather than silently equal.
    total_rows = [
        {
            "run_id": run_id,
            "item_id": int(r.item_id),
            "forecast_date": r.ds.strftime("%Y-%m-%d"),
            "predicted_qty": round(float(r.predicted_qty), 3),
        }
        for r in totals.itertuples()
    ]
    execute(
        """
        INSERT INTO ingredient_demand_forecast
            (run_id, item_id, forecast_date, predicted_qty,
             committed_floor_qty, adjustment_delta_qty, overlaid_qty,
             model_used, demand_pattern)
        VALUES
            (:run_id, :item_id, :forecast_date, :predicted_qty,
             0, 0, :predicted_qty, 'prophet', 'medium')
        ON DUPLICATE KEY UPDATE
            predicted_qty = VALUES(predicted_qty),
            overlaid_qty  = VALUES(predicted_qty)
        """,
        total_rows,
    )
    return len(contrib_rows), len(total_rows)
