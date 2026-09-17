<?php
/**
 * inventory/inventory_live.php
 *
 * JSON endpoint inventory.php polls (see its own <script> block) so stock
 * counts, statuses, and batch quantities reflect concurrent activity -- a
 * POS sale, another manager's adjustment/wastage entry, a purchase order
 * receipt -- without a manual page reload. Same "no WebSocket/SSE
 * infrastructure, short-interval polling instead" convention as
 * owner/notifications_poll.php.
 *
 * Built from the exact same snapshot functions inventory.php's own initial
 * render uses (getInventoryItemsSnapshot()/getInventoryBatchesSnapshot() in
 * includes/inventory_functions.php), so the two can never disagree about
 * what "current" means. Only keeps rows ALREADY on the page fresh -- a new
 * item/batch that appeared after the page loaded still needs a real reload
 * to show up; the client-side script explains this same boundary.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/inventory_functions.php';

Session::start();

header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['owner', 'manager'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $items   = getInventoryItemsSnapshot($pdo);
    $batches = getInventoryBatchesSnapshot($pdo);

    // -- Same derivation as inventory.php's own summary block -------------
    $lowStockItems = array_filter($items, fn($i) => (float)$i['current_stock'] <= (float)$i['reorder_level']);
    $criticalStockItems = array_filter($items, function ($i) {
        if ($i['critical_level'] === null || $i['critical_level'] === '') return false;
        return (float)$i['current_stock'] <= (float)$i['critical_level'];
    });
    $totalValue = 0.0;
    foreach ($batches as $b) {
        $totalValue += (float)$b['quantity_remaining'] * (float)$b['unit_cost'];
    }

    $today = new DateTime('today');
    $expiringSoon = array_filter($batches, function ($b) use ($today) {
        if (!$b['expiry_date']) return false;
        $diff = $today->diff(new DateTime($b['expiry_date']))->days;
        return (new DateTime($b['expiry_date'])) >= $today && $diff <= 7;
    });
    $expiredBatches = array_filter($batches, function ($b) use ($today) {
        return $b['expiry_date'] && (new DateTime($b['expiry_date'])) < $today;
    });

    // -- Items: just what a row needs to refresh itself in place -----------
    $itemsOut = array_map(function ($it) {
        $stock      = (float)$it['current_stock'];
        $reorder    = (float)$it['reorder_level'];
        $barCeiling = max($reorder * 2, 0.0001);

        return [
            'item_id'       => (int)$it['item_id'],
            'stock_display' => fmtQty($stock) . ' ' . ($it['unit_code'] ?? ''),
            'bar_pct'       => (int)min(100, ($stock / $barCeiling) * 100),
            // Already folds in is_active (returns 'inactive'), matching
            // inventory.php's own use of this same VIEW column.
            'stock_status'  => $it['stock_status'],
            'row_data'      => inventoryItemRowData($it),
        ];
    }, array_values($items));

    // -- Batches: same expiry-status derivation as inventory.php's loop ----
    $batchesOut = array_map(function ($b) use ($today) {
        $expiry = $b['expiry_date'];
        $expiryStatusKey = 'none';
        $statusLabel = 'No expiry set';
        $statusClass = 'is-active';

        if ($expiry) {
            $days = $today->diff(new DateTime($expiry))->days;
            $isPast = (new DateTime($expiry)) < $today;
            if ($isPast) {
                $statusLabel = 'Expired'; $statusClass = 'is-danger'; $expiryStatusKey = 'expired';
            } elseif ($days <= 7) {
                $statusLabel = "Expires in {$days}d"; $statusClass = 'is-warning'; $expiryStatusKey = 'expiring';
            } else {
                $statusLabel = 'Fresh'; $statusClass = 'is-success'; $expiryStatusKey = 'fresh';
            }
        }

        return [
            'batch_id'          => (int)$b['batch_id'],
            'remaining_display' => fmtQty($b['quantity_remaining']) . ' ' . ($b['unit_code'] ?? ''),
            'remaining_raw'     => (float)$b['quantity_remaining'],
            'unit_cost_display' => number_format((float)$b['unit_cost'], 2),
            'status_label'      => $statusLabel,
            'status_class'      => $statusClass,
            'expiry_status'     => $expiryStatusKey,
        ];
    }, array_values($batches));

    echo json_encode([
        'items'   => $itemsOut,
        'batches' => $batchesOut,
        'summary' => [
            'low_stock'      => count($lowStockItems),
            'critical_stock' => count($criticalStockItems),
            'expiring_soon'  => count($expiringSoon),
            'expired'        => count($expiredBatches),
            'total_value'    => number_format($totalValue, 2),
        ],
    ]);
} catch (PDOException $e) {
    error_log('inventory_live.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load live inventory data']);
}
