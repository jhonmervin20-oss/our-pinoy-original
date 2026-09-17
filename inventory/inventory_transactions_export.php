<?php
/**
 * inventory/inventory_transactions_export.php
 *
 * Streams the Stock Transaction History as CSV (opens fine in Excel, no
 * new dependency needed — true .xlsx/PDF generation is deferred to the
 * later Reports phase). Reruns the exact same buildInventoryTransactionQuery()
 * with the same GET params as inventory_transactions.php, so the export
 * always matches what's on screen. Mirrors the download-header pattern
 * already used elsewhere in this app for file downloads.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/inventory_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$filters = [
    'date_from'     => trim((string)($_GET['date_from'] ?? '')),
    'date_to'       => trim((string)($_GET['date_to'] ?? '')),
    'item_id'       => trim((string)($_GET['item_id'] ?? '')),
    'movement_type' => trim((string)($_GET['movement_type'] ?? '')),
];

$pdo = Database::getInstance()->getConnection();
[$sql, $params] = buildInventoryTransactionQuery($filters, $pdo);
$stmt = $pdo->prepare($sql . ' LIMIT 5000');
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="stock_transactions_' . date('Y-m-d_His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders the peso sign / accented names correctly

fputcsv($out, ['Reference #', 'Date', 'Item', 'Batch', 'Type', 'Qty In', 'Qty Out', 'Total Stocks', 'Unit', 'Performed By', 'Remarks']);

foreach ($transactions as $t) {
    fputcsv($out, [
        $t['reference_number'] ?? '',
        date('Y-m-d H:i:s', strtotime($t['created_at'])),
        $t['item_name'],
        $t['batch_number'] ?? '',
        transactionTypeLabel($t['transaction_type'], $t['reference_type']),
        $t['quantity_in'] !== null ? fmtQty($t['quantity_in']) : '',
        $t['quantity_out'] !== null ? fmtQty($t['quantity_out']) : '',
        (int)$t['balance_reliable'] === 1 ? fmtQty($t['remaining_balance']) : 'n/a',
        $t['unit_code'] ?? '',
        $t['performed_first_name'] !== null ? ($t['performed_first_name'] . ' ' . $t['performed_last_name']) : 'System',
        $t['remarks'] ?? '',
    ]);
}

fclose($out);
exit;
