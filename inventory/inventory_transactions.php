<?php
/**
 * inventory/inventory_transactions.php
 *
 * Read-only Stock Transaction History — the ledger every inventory change
 * writes to. Server-side filtered via GET params (not the client-side
 * DOM-hide pattern used elsewhere in this app), because "Remaining
 * Balance" is a running total computed with a window function that has to
 * see the filters applied in SQL, not hidden after the fact in the DOM —
 * see buildInventoryTransactionQuery() in includes/inventory_functions.php.
 *
 * ?print=1 renders a stripped-down, chrome-free layout for printing.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
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

$activePage   = 'inventory';
$activeInvTab = 'transactions';
$pageTitle    = 'Stock Transactions';
$isManager    = Session::hasRole(['manager']);
$isPrint      = ($_GET['print'] ?? '') === '1';

$filters = [
    'date_from'     => trim((string)($_GET['date_from'] ?? '')),
    'date_to'       => trim((string)($_GET['date_to'] ?? '')),
    'item_id'       => trim((string)($_GET['item_id'] ?? '')),
    'movement_type' => trim((string)($_GET['movement_type'] ?? '')),
];

// Pagination is server-side (not the client-side DOM-hide pattern used
// elsewhere) for the same reason filtering is: LIMIT/OFFSET is only ever
// applied to the outermost query, after buildInventoryTransactionQuery()'s
// window-function subquery has already computed remaining_balance across
// every matching row for that item — paginating the DOM instead would still
// be correct here since the balance is precomputed in SQL either way, but
// server-side keeps this page consistent with how its filters already work.
$pageSize          = 50;
$currentPage       = max(1, (int)($_GET['page'] ?? 1));
$totalTransactions = 0;
$totalPages        = 1;

$transactions = [];
$items        = [];
$dbError      = null;

try {
    $pdo = Database::getInstance()->getConnection();

    // Filter dropdown intentionally includes inactive/deactivated items —
    // a transaction against a since-deactivated item must stay filterable
    // in its own history.
    $items      = $pdo->query('SELECT item_id, item_name FROM inventory_items ORDER BY item_name')->fetchAll(PDO::FETCH_ASSOC);

    [$sql, $params] = buildInventoryTransactionQuery($filters, $pdo);

    if ($isPrint) {
        // The printed report is meant to be the full filtered ledger, not
        // just one page of it — a generous cap instead of true pagination.
        $stmt = $pdo->prepare($sql . ' LIMIT 5000');
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$sql}) AS counted");
        $countStmt->execute($params);
        $totalTransactions = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalTransactions / $pageSize));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }
        $offset = ($currentPage - 1) * $pageSize;

        $stmt = $pdo->prepare($sql . " LIMIT {$pageSize} OFFSET {$offset}");
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $dbError = "Couldn't load live transaction data. Run the schema migration, then refresh this page.";
}

// Print/Export always operate on the full filtered set, so their links
// never carry the current page number.
$queryString = http_build_query(array_filter($filters));
$displayCount = $isPrint ? count($transactions) : $totalTransactions;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock Transactions | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
</head>
<body<?= $isPrint ? ' onload="window.print()"' : '' ?>>

<?php if ($isPrint): ?>

    <main class="owner-content" style="max-width:100%;">
        <h1 style="font-family:'Lexend',sans-serif;">Stock Transaction History</h1>
        <p style="color:var(--op-ink-soft);font-size:0.85rem;">Printed <?= date('M j, Y g:i A') ?> &mdash; <?= count($transactions) ?> entries</p>
        <?= renderTransactionsTable($transactions) ?>
    </main>

<?php else: ?>

<div class="owner-shell">

    <?php
    if ($isManager) {
        $managerBase = '../manager/';
        require_once __DIR__ . '/../manager/includes/sidebar.php';
    } else {
        $ownerBase = '../owner/';
        require_once __DIR__ . '/../owner/includes/sidebar.php';
    }
    ?>

    <div class="owner-main">

        <?php
        if ($isManager) {
            require_once __DIR__ . '/../manager/includes/header.php';
        } else {
            require_once __DIR__ . '/../owner/includes/header.php';
        }
        ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <?php require __DIR__ . '/includes/inventory_nav.php'; ?>

            <?php /* Page actions + filters sit in their own card above the
                     table -- same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <form method="get" action="inventory_transactions.php" class="owner-inv-filters" style="margin:0;" data-autosubmit="transactions">
                    <input type="date" name="date_from" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_from']) ?>" title="From date">
                    <input type="date" name="date_to" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_to']) ?>" title="To date">
                    <select name="item_id" class="owner-select">
                        <option value="">All items</option>
                        <?php foreach ($items as $it): ?>
                        <option value="<?= (int)$it['item_id'] ?>" <?= $filters['item_id'] == $it['item_id'] ? 'selected' : '' ?>><?= htmlspecialchars($it['item_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="movement_type" class="owner-select">
                        <option value="">All movement types</option>
                        <?php foreach (MOVEMENT_TYPE_OPTIONS as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $filters['movement_type'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="inventory_transactions.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                    <div style="margin-left:auto;display:flex;gap:10px;">
                        <a href="inventory_transactions.php?print=1&<?= htmlspecialchars($queryString) ?>" target="_blank" class="owner-btn owner-btn-secondary">
                            <i class="ph ph-printer" aria-hidden="true"></i> Print
                        </a>
                        <a href="inventory_transactions_export.php?<?= htmlspecialchars($queryString) ?>" class="owner-btn owner-btn-secondary">
                            <i class="ph ph-download-simple" aria-hidden="true"></i> Export CSV
                        </a>
                    </div>
                </form>
            </div>

            <div class="owner-card">
                <?= renderTransactionsTable($transactions) ?>

                <?php if (!$isPrint && $totalPages > 1):
                    $prevQs = http_build_query(array_filter(array_merge($filters, ['page' => $currentPage - 1])));
                    $nextQs = http_build_query(array_filter(array_merge($filters, ['page' => $currentPage + 1])));
                ?>
                <div class="owner-pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="inventory_transactions.php?<?= htmlspecialchars($prevQs) ?>" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</a>
                    <?php else: ?>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" disabled><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <?php endif; ?>
                    <span class="owner-pagination-info">Page <?= $currentPage ?> of <?= $totalPages ?></span>
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="inventory_transactions.php?<?= htmlspecialchars($nextQs) ?>" class="owner-btn owner-btn-secondary owner-btn-sm">Next <i class="ph ph-caret-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" disabled>Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

        </main>

    </div>

</div>

<?php endif; ?>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
<script src="../owner/assets/js/filter-autosubmit.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-autosubmit.js') ?>"></script>
</body>
</html>
<?php
/** Shared table renderer — used by both the normal view and the print view. */
function renderTransactionsTable(array $transactions): string
{
    ob_start();
    ?>
    <div class="owner-table-wrap">
        <table class="owner-table">
            <thead>
                <tr>
                    <th>Reference #</th>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Batch</th>
                    <th>Type</th>
                    <th>Qty In</th>
                    <th>Qty Out</th>
                    <th>Total Stocks</th>
                    <th>Unit</th>
                    <th>Performed By</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="11" class="owner-table-empty">No transactions match your filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><?= $t['reference_number'] !== null ? '<strong>' . htmlspecialchars($t['reference_number']) . '</strong>' : '&mdash;' ?></td>
                        <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($t['created_at']))) ?></td>
                        <td><?= htmlspecialchars($t['item_name']) ?></td>
                        <td><?= $t['batch_number'] !== null ? htmlspecialchars($t['batch_number']) : '&mdash;' ?></td>
                        <td><?= htmlspecialchars(transactionTypeLabel($t['transaction_type'], $t['reference_type'])) ?></td>
                        <td><?= $t['quantity_in'] !== null ? fmtQty($t['quantity_in']) : '&mdash;' ?></td>
                        <td><?= $t['quantity_out'] !== null ? fmtQty($t['quantity_out']) : '&mdash;' ?></td>
                        <td><?php if ((int)$t['balance_reliable'] === 1): ?>
                                <?= fmtQty($t['remaining_balance']) ?>
                            <?php else: ?>
                                <span class="owner-ink-faint" title="Predates per-transaction consumption logging -- balance can't be confirmed against real stock">n/a</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($t['unit_code'] ?? '') ?></td>
                        <td><?= $t['performed_first_name'] !== null ? htmlspecialchars($t['performed_first_name'] . ' ' . $t['performed_last_name']) : 'System' ?></td>
                        <td><?= $t['remarks'] !== null ? htmlspecialchars($t['remarks']) : '&mdash;' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
?>
