<?php
/**
 * owner/demand_forecast_backtest_export.php
 *
 * CSV export of real daily series for forecast_service/backtest_real.py
 * (project plan Phase 6) -- one row per entity per calendar day, dense
 * (zero-filled, never skipped, matching this app's standing "a quiet day
 * counts as 0" convention). Two domains, selected via ?domain=:
 *
 *   - menu_item_sales (default): dense daily quantity sold, per active
 *     menu item with any sales history -- same source
 *     (getDailyDenseHistoryForItem(), business-day attributed) Section 4's
 *     trend chart and the accuracy pipeline already use.
 *   - ingredient_demand: dense daily BOM-exploded consumption, per active
 *     ingredient -- same source (getDailyDenseIngredientConsumption())
 *     the real forecast training and (per Fix #10) accuracy reconciliation
 *     both use, so a backtest run against this export is validated on
 *     the identical basis as the live system, not an approximation of it.
 *
 * Date range: the full real history available (earliest paid order
 * through yesterday) -- this app has ~91 real days as of this writing,
 * enough for a legitimate (if not deep) rolling-origin backtest.
 *
 * CSV columns match backtest_real.py's expected format exactly:
 * entity_id,entity_name,date,quantity
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/demand_forecast_functions.php';
require_once __DIR__ . '/includes/inventory_policy_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$domain = in_array($_GET['domain'] ?? '', ['menu_item_sales', 'ingredient_demand'], true) ? $_GET['domain'] : 'menu_item_sales';

$pdo = Database::getInstance()->getConnection();

$earliestTs = $pdo->query(
    "SELECT MIN(COALESCE(op.paid_at, op.created_at)) FROM order_payments op WHERE op.payment_status = 'paid'"
)->fetchColumn();

if (!$earliestTs) {
    header('Location: demand_forecast.php');
    exit;
}

$rangeStart = new DateTime((new DateTime($earliestTs))->format('Y-m-d'));
$rangeEnd   = forecastTrainingEnd($pdo, new DateTime('yesterday'));
// Clamp to the last day that actually has sales, exactly as the live
// forecast does. Exporting through yesterday regardless pads the series
// with however many trailing zero-days separate the last real
// transaction from today, and a backtest run on that measures the
// padding rather than the model.

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="backtest_export_' . $domain . '_' . date('Y-m-d_His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputcsv($out, ['entity_id', 'entity_name', 'date', 'quantity']);

if ($domain === 'menu_item_sales') {
    $menuItems = $pdo->query(
        "SELECT DISTINCT mi.item_id, mi.item_name
         FROM menu_items mi
         JOIN order_items oi ON oi.menu_item_id = mi.item_id
         JOIN orders o ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         WHERE mi.is_active = 1 AND op.payment_status = 'paid' AND oi.status != 'cancelled'
         ORDER BY mi.item_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($menuItems as $item) {
        $itemId = (int)$item['item_id'];
        $dailyMap = getDailyDenseHistoryForItem($pdo, $itemId, $rangeStart, $rangeEnd);
        foreach ($dailyMap as $date => $qty) {
            fputcsv($out, [$itemId, $item['item_name'], $date, $qty]);
        }
    }
} else {
    $ingredients = getActiveIngredientItems($pdo);
    foreach ($ingredients as $item) {
        $itemId = (int)$item['item_id'];
        $missing = [];
        $dailyMap = getDailyDenseIngredientConsumption($pdo, $itemId, (int)$item['base_unit_id'], $rangeStart, $rangeEnd, $missing);
        foreach ($dailyMap as $date => $qty) {
            fputcsv($out, [$itemId, $item['item_name'], $date, $qty]);
        }
    }
}

fclose($out);
exit;
