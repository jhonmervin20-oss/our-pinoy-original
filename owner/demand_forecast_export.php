<?php
/**
 * owner/demand_forecast_export.php
 *
 * Streams a Demand Forecasting section as CSV (opens fine in Excel -- see
 * the module's Excel-export note: PhpSpreadsheet isn't installed in this
 * environment and a prior attempt to add it was abandoned, so this is a
 * real CSV, not a fabricated .xlsx). ?section= picks the column layout;
 * every other filter matches demand_forecast.php's own $_GET vocabulary
 * so the export always matches what's on screen. Mirrors the
 * download-header pattern in inventory/inventory_transactions_export.php.
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

$section = (string)($_GET['section'] ?? '');
$validSections = ['fast_moving', 'slow_moving', 'ingredient_forecast', 'purchase_recommendations'];
if (!in_array($section, $validSections, true)) {
    header('Location: demand_forecast.php');
    exit;
}

$validGranularities = ['daily', 'weekly', 'monthly', 'yearly'];
$period      = in_array($_GET['period'] ?? '', ['today', 'yesterday', 'last7', 'last30', 'last90', 'custom'], true) ? $_GET['period'] : 'last30';
$dateFrom    = trim((string)($_GET['date_from'] ?? ''));
$dateTo      = trim((string)($_GET['date_to'] ?? ''));
$granularity = in_array($_GET['granularity'] ?? '', $validGranularities, true) ? $_GET['granularity'] : 'daily';
$categoryRaw = trim((string)($_GET['category_id'] ?? ''));
$supplierRaw = trim((string)($_GET['supplier_id'] ?? ''));
$search      = trim((string)($_GET['q'] ?? ''));
$horizonOverrideRaw = in_array($_GET['horizon'] ?? '', ['7', '14'], true) ? $_GET['horizon'] : '';

$categoryId = ($categoryRaw !== '' && ctype_digit($categoryRaw)) ? (int)$categoryRaw : null;
$supplierId = ($supplierRaw !== '' && ctype_digit($supplierRaw)) ? (int)$supplierRaw : null;

[$rangeStart, $rangeEnd] = resolvePeriodRange($period, $dateFrom ?: null, $dateTo ?: null);

$filters = [
    'date_from'   => $rangeStart->format('Y-m-d 00:00:00'),
    'date_to'     => $rangeEnd->format('Y-m-d 23:59:59'),
    'category_id' => $categoryId,
    'search'      => $search !== '' ? $search : null,
];

$pdo = Database::getInstance()->getConnection();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="demand_forecast_' . $section . '_' . date('Y-m-d_His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders the peso sign / accented names correctly

switch ($section) {
    case 'ingredient_forecast':
        $policySettings = $pdo->query(
            "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('forecast_horizon_days', 'auto_po_forecast_window_days')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        // Reads the last completed sweep, same as the Demand Forecast page --
        // exporting used to re-run one Prophet call per ingredient (~30s for a CSV).
        $rows = getPersistedInventoryPolicyReport($pdo, $supplierId, $search !== '' ? $search : null);

        fputcsv($out, ['Item', 'Mover', 'Model', 'Available Stock', 'Reorder Level', 'Restock Target', 'Safety Stock', 'Unit', 'Status']);
        foreach ($rows as $ing) {
            $status = $ing['gated_reason'] !== null ? 'Unknown' : ($ing['triggered'] ? 'At/under reorder level' : 'Sufficient');
            fputcsv($out, [
                $ing['item_name'], demandPatternLabel($ing['demand_pattern'], $ing['slow_reason'] ?? null), modelUsedLabel($ing['model_used']),
                number_format($ing['available_stock'], 2, '.', ''),
                $ing['policy_reorder_level'] !== null ? number_format($ing['policy_reorder_level'], 2, '.', '') : '',
                $ing['policy_restock_target'] !== null ? number_format($ing['policy_restock_target'], 2, '.', '') : '',
                $ing['safety_stock_qty'] !== null ? number_format($ing['safety_stock_qty'], 2, '.', '') : '',
                $ing['unit_code'], $status,
            ]);
        }
        break;

    case 'purchase_recommendations':
        $policySettings = $pdo->query(
            "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('forecast_horizon_days', 'auto_po_forecast_window_days')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = array_values(array_filter(
            getPersistedInventoryPolicyReport($pdo, $supplierId, $search !== '' ? $search : null),
            fn($r) => $r['triggered']
        ));

        fputcsv($out, ['Item', 'Category', 'Available Stock', 'Reorder Level', 'Recommended Qty', 'Unit', 'Supplier', 'Mover']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['item_name'], $row['category_name'] ?? '',
                number_format($row['available_stock'], 2, '.', ''),
                number_format($row['policy_reorder_level'], 2, '.', ''),
                $row['suggested_qty'] !== null ? (string)$row['suggested_qty'] : '0',
                $row['unit_code'], $row['supplier_name'] ?? '', demandPatternLabel($row['demand_pattern'], $row['slow_reason'] ?? null),
            ]);
        }
        break;
}

fclose($out);
exit;
