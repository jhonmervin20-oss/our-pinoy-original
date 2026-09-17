"""
forecast_service/engine

Demand-classification + inventory-policy engine for ingredient-level
purchasing decisions (Hybrid Auto-PO / inventory forecasting feature).

This package is intentionally separate from app.py's existing /forecast
endpoint, which serves a completely different feature (menu-item sales
forecasting called from owner/includes/prophet_client.php). Nothing here
is imported by that code path, and nothing in this package touches a
database -- it is a pure, stateless calculator. All persistence is owned
by PHP; PHP builds the daily series and passes it in over HTTP.

Modules:
    data_prep             -- in-memory BOM (recipe) explosion helpers, used
                              only by the offline example/backtest scripts.
    demand_classification -- fast/medium/slow velocity classification.
    forecasting           -- Prophet and trailing-average forecasters.
    inventory_policy      -- order-up-to (min-max) policy derived from the
                              forecast.
    po_generation         -- trigger check, order sizing, supplier grouping,
                              and shortage-day projection.
"""
