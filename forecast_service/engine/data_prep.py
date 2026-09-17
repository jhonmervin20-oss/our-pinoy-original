"""
forecast_service/engine/data_prep.py

In-memory BOM (recipe) explosion: translates menu-item sales into
ingredient-level daily consumption. This module is NOT on the live
request path -- the /forecast_policy endpoint receives an already-built
daily ingredient series straight from PHP (PHP owns the DB and does this
join directly against the live order_items / menu_item_ingredients /
recipes tables). This module exists so the offline deliverables
(run_example.py, backtest.py) can demonstrate/validate the identical BOM
math against synthetic data without needing a database connection here.

Architecture note: this Python service has no DB access by deliberate
design -- it's a stateless calculator called over HTTP by PHP, which owns
all reads/writes.
"""

from __future__ import annotations

import pandas as pd

# Minimum distinct days of history required before Prophet is even
# attempted. Mirrors MIN_PROPHET_HISTORY_DAYS in
# owner/includes/demand_forecast_functions.php -- kept as the same value
# here rather than inventing a second number.
MIN_HISTORY_DAYS = 14


def build_ingredient_daily_series(
    order_items_df: pd.DataFrame,
    menu_item_ingredients_df: pd.DataFrame,
    ingredient_item_id,
    start_date,
    end_date,
) -> pd.Series:
    """
    Build a dense daily consumption series for one ingredient by exploding
    menu-item sales through the bill-of-materials (recipe).

    Expected input shapes (already aggregated to daily grain by the
    caller -- this function does not do its own date bucketing beyond the
    final dense reindex):

        order_items_df: columns [date, menu_item_id, quantity]
            One row per (date, menu_item_id) with that day's total units
            of that menu item sold. `date` may be a string ("YYYY-MM-DD")
            or a datetime-like value; it is coerced with pd.to_datetime.

        menu_item_ingredients_df: columns
            [menu_item_id, ingredient_item_id, quantity_required]
            One row per (menu_item_id, ingredient_item_id) recipe line.
            `quantity_required` is how much of the ingredient one unit of
            the menu item consumes, and is assumed > 0.

    BOM math, for each menu item that uses this ingredient:
        ingredient_qty_that_day = sum over menu items(
            order_items.quantity_sold_that_day * per_unit_required
        )

    The result is dense-filled with 0 for every calendar day in
    [start_date, end_date] inclusive, even days with zero consumption or
    no matching rows at all -- quiet days must not be skipped, since the
    nonzero_day_ratio classification downstream depends on seeing the
    true count of zero days in the window.

    Returns:
        pd.Series indexed by a daily DatetimeIndex covering
        [start_date, end_date], named "quantity".
    """
    full_index = pd.date_range(start=start_date, end=end_date, freq="D")

    # Recipe lines that actually consume this ingredient.
    recipe_lines = menu_item_ingredients_df[
        menu_item_ingredients_df["ingredient_item_id"] == ingredient_item_id
    ].copy()

    if recipe_lines.empty or order_items_df.empty:
        return pd.Series(0.0, index=full_index, name="quantity")

    recipe_lines["per_unit_required"] = recipe_lines["quantity_required"]

    items = order_items_df.copy()
    items["date"] = pd.to_datetime(items["date"])

    # Keep only sales of menu items that use this ingredient, and attach
    # each one's per_unit_required conversion factor.
    merged = items.merge(
        recipe_lines[["menu_item_id", "per_unit_required"]],
        on="menu_item_id",
        how="inner",
    )

    if merged.empty:
        return pd.Series(0.0, index=full_index, name="quantity")

    merged["ingredient_qty"] = merged["quantity"] * merged["per_unit_required"]

    daily = merged.groupby("date")["ingredient_qty"].sum()

    dense = daily.reindex(full_index, fill_value=0.0)
    dense.name = "quantity"
    dense.index.name = "date"
    return dense
