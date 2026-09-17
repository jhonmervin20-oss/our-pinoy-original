<?php
/**
 * orders/orders.php
 *
 * Order History — a read-only, server-side-filtered ledger of every order
 * taken through the cashier's POS terminal (cashier/pos.php), for Owner/
 * Manager oversight. Modeled closely on
 * inventory/inventory_transactions.php (closest existing precedent: a
 * filtered history table, not a dashboard -- no summary/KPI cards here,
 * so this doesn't overlap with the Owner's separate Insights section).
 *
 * "Orders" used to be a dead nav link in both the Owner and Manager
 * sidebars (pointing at a pos.php that never existed in either panel);
 * this page is what that link now points to.
 *
 * TWO TABS, one page: "Order history" (this ledger) and "Void requests" (the
 * approval queue). They started as separate pages with their own sidebar
 * entries, but a void request is only ever about an order -- an approver
 * looks at the order, then decides -- so splitting them put two nav items on
 * the same subject and made the reviewer navigate between them. Tabs are real
 * ?tab= links, matching owner/analytics.php: each tab runs only its own SQL,
 * and each keeps its own independent filters and pagination in the URL.
 *
 * ?print=1 renders a self-contained, professionally-formatted print
 * document (own letterhead, no dashboard chrome) -- same convention as
 * owner/report.php's print view, see renderOrdersPrintDocumentHtml()
 * (orders/includes/order_functions.php). ?download=1 streams the same
 * document as a real PDF via Dompdf.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/void_functions.php';
require_once __DIR__ . '/includes/order_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'orders';
$pageTitle  = 'Orders';
$isManager  = Session::hasRole(['manager']);

$TAB_LABELS = ['orders' => 'Order history', 'void_requests' => 'Void requests'];
$activeTab  = isset($TAB_LABELS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'orders';

// Printing is the order ledger's feature only -- the void queue is a work
// queue, not a document anyone hands out -- so ?print=1 is ignored on that tab
// rather than silently printing the wrong thing.
$isPrint = ($_GET['print'] ?? '') === '1' && $activeTab === 'orders';

$itemIdRaw = trim((string)($_GET['item_id'] ?? ''));

$filters = [
    'date_from'      => trim((string)($_GET['date_from'] ?? '')),
    'date_to'        => trim((string)($_GET['date_to'] ?? '')),
    'order_type'     => trim((string)($_GET['order_type'] ?? '')),
    'order_source'   => trim((string)($_GET['order_source'] ?? '')),
    // No payment_status here: the filter was removed from the bar, so a stale
    // ?payment_status=... link is ignored rather than silently narrowing the
    // list with no dropdown on screen to show it is doing so. The Status column
    // still marks voided orders, and buildOrdersQuery() keeps the clause as an
    // optional one for any caller that does pass it.
    'cashier_id'     => trim((string)($_GET['cashier_id'] ?? '')),
    'search'         => trim((string)($_GET['search'] ?? '')),
    'item_id'        => ($itemIdRaw !== '' && ctype_digit($itemIdRaw)) ? (int)$itemIdRaw : '',
];

$pageSize    = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalOrders = 0;
$totalPages  = 1;

$orders   = [];
$cashiers = [];
$filterItemName = null;
$pendingVoidCount = 0;
$dbError  = null;

// --- Void requests tab state (only populated when that tab is active) ------
$voidFilters = [
    'status'       => trim((string)($_GET['status'] ?? '')),
    'reason_code'  => trim((string)($_GET['reason_code'] ?? '')),
    'requested_by' => trim((string)($_GET['requested_by'] ?? '')),
    'date_from'    => trim((string)($_GET['date_from'] ?? '')),
    'date_to'      => trim((string)($_GET['date_to'] ?? '')),
    'search'       => trim((string)($_GET['search'] ?? '')),
];
// An unrecognised ?status=foo is dropped rather than passed through as a filter
// that matches nothing and silently shows an empty queue.
if (!isset(VOID_STATUS_LABELS[$voidFilters['status']]))     { $voidFilters['status'] = ''; }
if (!isset(VOID_REASON_LABELS[$voidFilters['reason_code']])) { $voidFilters['reason_code'] = ''; }
if (!ctype_digit($voidFilters['requested_by']))              { $voidFilters['requested_by'] = ''; }

$voidRequests   = [];
$voidRequesters = [];
$voidTotal      = 0;
$voidPageSize   = 20;
$voidPages      = 1;

try {
    $pdo = Database::getInstance()->getConnection();

    // Always loaded: it drives the tab strip's pending badge, which has to be
    // visible from the orders tab too or nobody would know to switch.
    $pendingVoidCount = pendingVoidRequestCount($pdo);

    if ($activeTab === 'orders') {
        // Cashier filter dropdown intentionally includes anyone who has ever
        // rung up an order, even if their account is since deactivated -- a
        // past order must stay filterable by who actually took it.
        $cashiers = $pdo->query(
            "SELECT DISTINCT u.user_id, u.first_name, u.last_name
             FROM orders o JOIN users u ON u.user_id = o.cashier_id
             ORDER BY u.first_name ASC, u.last_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($filters['item_id'] !== '') {
            $itemNameStmt = $pdo->prepare("SELECT item_name FROM menu_items WHERE item_id = ?");
            $itemNameStmt->execute([$filters['item_id']]);
            $filterItemName = $itemNameStmt->fetchColumn() ?: null;
        }

        [$sql, $params] = buildOrdersQuery($filters);

        if ($isPrint) {
            // The printed report is the full filtered list, not just one page.
            $stmt = $pdo->prepare($sql . ' LIMIT 5000');
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$sql}) AS counted");
            $countStmt->execute($params);
            $totalOrders = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalOrders / $pageSize));
            if ($currentPage > $totalPages) {
                $currentPage = $totalPages;
            }
            $offset = ($currentPage - 1) * $pageSize;

            $stmt = $pdo->prepare($sql . " LIMIT {$pageSize} OFFSET {$offset}");
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Anyone who has ever raised a request, active or not -- a past request
        // must stay filterable by who actually raised it.
        $voidRequesters = $pdo->query(
            "SELECT DISTINCT u.user_id, u.first_name, u.last_name
             FROM order_void_requests v JOIN users u ON u.user_id = v.requested_by
             ORDER BY u.first_name ASC, u.last_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        [$vSql, $vParams] = buildVoidRequestsQuery($voidFilters);

        $vCount = $pdo->prepare("SELECT COUNT(*) FROM ({$vSql}) AS counted");
        $vCount->execute($vParams);
        $voidTotal = (int)$vCount->fetchColumn();
        $voidPages = max(1, (int)ceil($voidTotal / $voidPageSize));
        if ($currentPage > $voidPages) {
            $currentPage = $voidPages;
        }
        $vOffset = ($currentPage - 1) * $voidPageSize;

        $vStmt = $pdo->prepare($vSql . " LIMIT {$voidPageSize} OFFSET {$vOffset}");
        $vStmt->execute($vParams);
        $voidRequests = $vStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('orders/orders.php failed: ' . $e->getMessage());
    $dbError = "Couldn't load this page's data. Please refresh.";
}

$queryString  = http_build_query(array_filter($filters));
$displayCount = $isPrint ? count($orders) : $totalOrders;
// Carries the reviewer back to the same filtered queue page they acted from.
$voidReturnQs = http_build_query(array_filter(array_merge($voidFilters, ['tab' => 'void_requests', 'page' => $currentPage])));

/** Shared table renderer for the on-screen (non-print) view only. */
function renderOrdersTable(array $orders): string
{
    ob_start();
    ?>
    <div class="owner-table-wrap">
        <table class="owner-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date &amp; time</th>
                    <th>Type</th>
                    <th>Visit type</th>
                    <th>Cashier</th>
                    <th>Payment method</th>
                    <th>Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="8" class="owner-table-empty">No orders match your filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o):
                        $isVoided = ($o['order_status'] ?? '') === 'voided';
                    ?>
                        <tr<?= $isVoided ? ' style="opacity:.62;"' : '' ?>>
                            <td>
                                <strong><?= htmlspecialchars($o['order_number']) ?></strong>
                                <?php if ($isVoided): ?>
                                    <div style="margin-top:3px;"><span class="owner-status-pill is-danger">Voided</span></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($o['created_at']))) ?></td>
                            <td><?= htmlspecialchars(orderTypeLabel($o['order_type'])) ?></td>
                            <td>
                                <?php if ($o['reservation_id'] !== null): ?>
                                    <span class="owner-status-pill is-info">Reservation</span>
                                    <?php if ($o['reservation_number']): ?><div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;"><?= htmlspecialchars($o['reservation_number']) ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--op-ink-faint);">Walk-in</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $o['cashier_first_name'] !== null ? htmlspecialchars($o['cashier_first_name'] . ' ' . $o['cashier_last_name']) : '&mdash;' ?></td>
                            <td><?= htmlspecialchars(orderPaymentMethodLabel($o['payment_method'])) ?></td>
                            <td>&#8369;<?= number_format((float)$o['total_amount'], 2) ?></td>
                            <td><button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-view-order" data-order-id="<?= (int)$o['order_id'] ?>" aria-label="View order"><i class="ph ph-eye" aria-hidden="true"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------------------
// Printable view -- a self-contained professional document (own
// letterhead, bordered tables, no dashboard chrome), NOT the on-screen
// dashboard styling -- same reasoning and convention as owner/report.php's
// $isPrint branch (see renderOrdersPrintDocumentHtml()'s docblock for why
// this deliberately never loads owner-panel.css). ?download=1 streams the
// same document as a real PDF via Dompdf.
// ---------------------------------------------------------------------
if ($isPrint) {
    $isDownload = ($_GET['download'] ?? '') === '1';

    $restaurantName = 'OPO! Our Pinoy Original';
    if (isset($pdo)) {
        $restaurantName = $pdo->query(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
        )->fetchColumn() ?: $restaurantName;
    }

    $filterSummary = ($filters['date_from'] !== '' || $filters['date_to'] !== '')
        ? trim(($filters['date_from'] !== '' ? date('M j, Y', strtotime($filters['date_from'])) : 'Start') . ' &ndash; ' . ($filters['date_to'] !== '' ? date('M j, Y', strtotime($filters['date_to'])) : 'Now'))
        : 'All time';

    $printUrl = 'orders.php?print=1&' . $queryString;

    if ($isDownload) {
        require_once __DIR__ . '/../vendor/autoload.php';

        $html = renderOrdersPrintDocumentHtml($orders, $restaurantName, $filterSummary, false);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="order_history_' . date('Y-m-d') . '.pdf"');
        echo $dompdf->output();
        exit;
    }

    echo renderOrdersPrintDocumentHtml(
        $orders,
        $restaurantName,
        $filterSummary,
        true,
        $printUrl,
        $printUrl . '&download=1',
        'orders.php?' . $queryString
    );
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Orders | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
</head>
<body>

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

            <?php // Real ?tab= links, not JS panel toggling: each tab carries its
                  // own filters and page number in the URL, so a reviewer can
                  // bookmark or share "pending void requests, page 2". ?>
            <div class="owner-tabs">
                <?php foreach ($TAB_LABELS as $key => $label): ?>
                    <a href="orders.php?tab=<?= htmlspecialchars($key) ?>" class="owner-tabs-link<?= $activeTab === $key ? ' is-active' : '' ?>">
                        <?= htmlspecialchars($label) ?><?php
                            // The badge is shown on BOTH tabs, and only when there is
                            // something to act on -- a permanent "(0)" trains people to
                            // ignore it, and hiding the count while on the orders tab
                            // would mean nobody knew to switch.
                            if ($key === 'void_requests' && $pendingVoidCount > 0) {
                                echo ' <strong>(' . $pendingVoidCount . ')</strong>';
                            }
                        ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($activeTab === 'orders'): ?>

            <?php if ($filters['item_id'] !== '' && $filterItemName !== null): ?>
                <div class="owner-alert" style="background:var(--op-canvas);color:var(--op-ink-soft);">
                    <i class="ph ph-funnel" aria-hidden="true"></i>
                    <span>Showing orders containing &ldquo;<?= htmlspecialchars($filterItemName) ?>&rdquo;.</span>
                    <a href="orders.php" class="owner-btn owner-btn-secondary owner-btn-sm" style="margin-left:auto;">Show all</a>
                </div>
            <?php endif; ?>

            <?php /* Page actions + filters sit in their own card above the
                     table -- same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <?php /* Filters as you change them -- see filter-autosubmit.js. No
                         Filter button: the bar submits on change, and the script
                         leaves empty fields out of the URL. Clear resets them all
                         at once, which emptying six controls by hand does not. */ ?>
                <form method="get" action="orders.php" class="owner-inv-filters" style="margin:0;" data-autosubmit="orders">
                    <input type="date" name="date_from" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_from']) ?>" title="From date">
                    <input type="date" name="date_to" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_to']) ?>" title="To date">
                    <select name="order_type" class="owner-select">
                        <option value="">All types</option>
                        <?php foreach (ORDER_TYPE_LABELS as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $filters['order_type'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="order_source" class="owner-select">
                        <option value="">All visit types</option>
                        <?php foreach (ORDER_SOURCE_OPTIONS as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $filters['order_source'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="cashier_id" class="owner-select">
                        <option value="">All cashiers</option>
                        <?php foreach ($cashiers as $c): ?>
                        <option value="<?= (int)$c['user_id'] ?>" <?= $filters['cashier_id'] == $c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="search" class="owner-input" style="width:auto;" placeholder="Order #" value="<?= htmlspecialchars($filters['search']) ?>">
                    <a href="orders.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                    <div style="margin-left:auto;display:flex;gap:10px;">
                        <a href="orders.php?print=1&<?= htmlspecialchars($queryString) ?>" target="_blank" class="owner-btn owner-btn-secondary">
                            <i class="ph ph-printer" aria-hidden="true"></i> Print
                        </a>
                    </div>
                </form>
            </div>

            <div class="owner-card">
                <?= renderOrdersTable($orders) ?>

                <?php if ($totalPages > 1):
                    $prevQs = http_build_query(array_filter(array_merge($filters, ['page' => $currentPage - 1])));
                    $nextQs = http_build_query(array_filter(array_merge($filters, ['page' => $currentPage + 1])));
                ?>
                <div class="owner-pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="orders.php?<?= htmlspecialchars($prevQs) ?>" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</a>
                    <?php else: ?>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" disabled><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <?php endif; ?>
                    <span class="owner-pagination-info">Page <?= $currentPage ?> of <?= $totalPages ?></span>
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="orders.php?<?= htmlspecialchars($nextQs) ?>" class="owner-btn owner-btn-secondary owner-btn-sm">Next <i class="ph ph-caret-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" disabled>Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php else: /* ---------------- Void requests tab ---------------- */ ?>

            <?php /* Page actions + filters sit in their own card above the
                     table -- same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <?php /* Same auto-submitting bar as the Order history tab. The hidden
                         tab input is not a filter, so it is never blanked out and the
                         auto-submit stays on this tab. */ ?>
                <form method="get" action="orders.php" class="owner-inv-filters" style="margin:0;" data-autosubmit="void">
                    <input type="hidden" name="tab" value="void_requests">
                    <select name="status" class="owner-select">
                        <option value="">All statuses</option>
                        <?php foreach (VOID_STATUS_LABELS as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $voidFilters['status'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="reason_code" class="owner-select">
                        <option value="">All reasons</option>
                        <?php foreach (VOID_REASON_LABELS as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $voidFilters['reason_code'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="requested_by" class="owner-select">
                        <option value="">All cashiers</option>
                        <?php foreach ($voidRequesters as $c): ?>
                        <option value="<?= (int)$c['user_id'] ?>" <?= $voidFilters['requested_by'] == $c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($voidFilters['date_from']) ?>" title="From date">
                    <input type="date" name="date_to" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($voidFilters['date_to']) ?>" title="To date">
                    <input type="text" name="search" class="owner-input" style="width:auto;" placeholder="Void # or Order #" value="<?= htmlspecialchars($voidFilters['search']) ?>">
                    <a href="orders.php?tab=void_requests" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                </form>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Void #</th><th>Order #</th><th>Requested by</th><th>Reason</th>
                                <th>Amount</th><th>Status</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($voidRequests)): ?>
                                <tr><td colspan="7" class="owner-table-empty">No void requests match your filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ($voidRequests as $r):
                                    $isPending = $r['status'] === 'pending';
                                    $requester = $r['requester_first_name'] !== null
                                        ? $r['requester_first_name'] . ' ' . $r['requester_last_name'] : 'Unknown';
                                    $reviewer = $r['reviewer_first_name'] !== null
                                        ? $r['reviewer_first_name'] . ' ' . $r['reviewer_last_name'] : null;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($r['void_number']) ?></strong>
                                        <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['created_at']))) ?></div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($r['order_number']) ?>
                                        <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;"><?= htmlspecialchars(date('M j, Y', strtotime($r['order_created_at']))) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($requester) ?></td>
                                    <td>
                                        <?= htmlspecialchars(voidReasonLabel($r['reason_code'])) ?>
                                        <?php if (!empty($r['reason_notes'])): ?>
                                            <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;max-width:260px;">&ldquo;<?= htmlspecialchars($r['reason_notes']) ?>&rdquo;</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>&#8369;<?= number_format((float)$r['total_amount'], 2) ?></td>
                                    <td>
                                        <span class="owner-status-pill <?= voidStatusBadgeClass($r['status']) ?>"><?= htmlspecialchars(voidStatusLabel($r['status'])) ?></span>
                                        <?php if (!$isPending && $reviewer !== null): ?>
                                            <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;">
                                                by <?= htmlspecialchars($reviewer) ?><?= $r['reviewed_at'] ? ', ' . htmlspecialchars(date('M j', strtotime($r['reviewed_at']))) : '' ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['review_notes'])): ?>
                                            <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;max-width:260px;">&ldquo;<?= htmlspecialchars($r['review_notes']) ?>&rdquo;</div>
                                        <?php endif; ?>
                                        <?php if ($r['status'] === 'approved' && (int)$r['shift_was_closed'] === 1): ?>
                                            <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;max-width:260px;">
                                                <i class="ph ph-warning" aria-hidden="true"></i> Shift already closed &mdash; remitted cash left as counted.
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;justify-content:flex-end;">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-view-order"
                                                    data-order-id="<?= (int)$r['order_id'] ?>" aria-label="View order <?= htmlspecialchars($r['order_number']) ?>">
                                                <i class="ph ph-eye" aria-hidden="true"></i>
                                            </button>
                                            <?php if ($isPending): ?>
                                            <button type="button" class="owner-btn owner-btn-primary owner-btn-sm owner-btn-review"
                                                    data-action="approve"
                                                    data-void-id="<?= (int)$r['void_request_id'] ?>"
                                                    data-void-number="<?= htmlspecialchars($r['void_number']) ?>"
                                                    data-order-number="<?= htmlspecialchars($r['order_number']) ?>"
                                                    data-amount="&#8369;<?= number_format((float)$r['total_amount'], 2) ?>">Approve</button>
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-review"
                                                    data-action="reject"
                                                    data-void-id="<?= (int)$r['void_request_id'] ?>"
                                                    data-void-number="<?= htmlspecialchars($r['void_number']) ?>"
                                                    data-order-number="<?= htmlspecialchars($r['order_number']) ?>"
                                                    data-amount="&#8369;<?= number_format((float)$r['total_amount'], 2) ?>">Reject</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($voidPages > 1):
                    $vPrev = http_build_query(array_filter(array_merge($voidFilters, ['tab' => 'void_requests', 'page' => $currentPage - 1])));
                    $vNext = http_build_query(array_filter(array_merge($voidFilters, ['tab' => 'void_requests', 'page' => $currentPage + 1])));
                ?>
                <div class="owner-pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="orders.php?<?= htmlspecialchars($vPrev) ?>" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</a>
                    <?php else: ?>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" disabled><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <?php endif; ?>
                    <span class="owner-pagination-info">Page <?= $currentPage ?> of <?= $voidPages ?></span>
                    <?php if ($currentPage < $voidPages): ?>
                        <a href="orders.php?<?= htmlspecialchars($vNext) ?>" class="owner-btn owner-btn-secondary owner-btn-sm">Next <i class="ph ph-caret-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" disabled>Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>

        </main>

    </div>

</div>

<!-- Order detail modal. Shared by both tabs: the order list opens it from its
     eye button, and the void queue opens the SAME fragment so an approver sees
     the exact line items they are about to reverse without leaving the page. -->
<div class="owner-modal-backdrop" id="orderModalBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="orderModalTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="orderModalTitle">Order details</h2>
            <button type="button" class="owner-modal-close" id="btnCloseOrderModal" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body" id="orderModalBody" style="background:var(--op-canvas);">
            <div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>
        </div>
        <div class="owner-modal-footer">
            <button type="button" class="owner-btn owner-btn-secondary" id="btnCloseOrderModalFooter">Close</button>
        </div>
    </div>
</div>

<?php if ($activeTab === 'void_requests'): ?>
<!-- Approve / Reject decision modal. One modal for both verbs: they collect
     exactly the same thing (a note), and only the consequence text changes. -->
<div class="owner-modal-backdrop" id="reviewModalBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle">
        <form method="post" action="void_request_action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="void_request_id" id="reviewVoidId">
            <input type="hidden" name="action" id="reviewAction">
            <input type="hidden" name="return_qs" value="<?= htmlspecialchars($voidReturnQs) ?>">

            <div class="owner-modal-header">
                <h2 class="owner-modal-title" id="reviewModalTitle">Review void request</h2>
                <button type="button" class="owner-modal-close" id="btnCloseReviewModal" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
            </div>

            <div class="owner-modal-body">
                <p id="reviewModalIntro" style="margin:0 0 14px;color:var(--op-ink-soft);"></p>
                <div class="owner-form-group">
                    <label for="reviewNotes">Note <span id="reviewNotesRequired" style="color:var(--op-danger);">*</span></label>
                    <textarea name="review_notes" id="reviewNotes" class="owner-input" rows="3" maxlength="500"
                              placeholder="Why this decision? The cashier sees this on their order history."></textarea>
                </div>
            </div>

            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelReview">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary" id="btnConfirmReview">Confirm</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    function opener(backdrop) {
        return {
            open() { backdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); },
            close() { backdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); },
        };
    }

    // ---- Order detail (both tabs) -----------------------------------------
    const orderBackdrop = document.getElementById('orderModalBackdrop');
    const orderModal = opener(orderBackdrop);
    const body = document.getElementById('orderModalBody');

    document.querySelectorAll('.owner-btn-view-order').forEach((btn) => {
        btn.addEventListener('click', () => {
            body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>';
            orderModal.open();

            fetch('order_fragment.php?id=' + btn.getAttribute('data-order-id'))
                .then((res) => res.text())
                .then((html) => { body.innerHTML = html; })
                .catch(() => { body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-danger);">Couldn\'t load this order.</div>'; });
        });
    });

    document.getElementById('btnCloseOrderModal').addEventListener('click', orderModal.close);
    document.getElementById('btnCloseOrderModalFooter').addEventListener('click', orderModal.close);
    orderBackdrop.addEventListener('click', (e) => { if (e.target === orderBackdrop) orderModal.close(); });

    // ---- Approve / reject (void requests tab only) -------------------------
    // The markup only exists on that tab, so everything below is guarded --
    // otherwise the orders tab would throw on the first getElementById and
    // kill the order-detail handlers wired up above it.
    const reviewBackdrop = document.getElementById('reviewModalBackdrop');
    if (reviewBackdrop) {
        const reviewModal = opener(reviewBackdrop);
        const notes = document.getElementById('reviewNotes');
        const notesRequired = document.getElementById('reviewNotesRequired');
        const confirmBtn = document.getElementById('btnConfirmReview');

        document.querySelectorAll('.owner-btn-review').forEach((btn) => {
            btn.addEventListener('click', () => {
                const action = btn.getAttribute('data-action');
                const voidNumber = btn.getAttribute('data-void-number');
                const orderNumber = btn.getAttribute('data-order-number');
                const amount = btn.getAttribute('data-amount');

                document.getElementById('reviewVoidId').value = btn.getAttribute('data-void-id');
                document.getElementById('reviewAction').value = action;
                notes.value = '';

                if (action === 'approve') {
                    document.getElementById('reviewModalTitle').textContent = 'Approve void ' + voidNumber;
                    document.getElementById('reviewModalIntro').textContent =
                        'This reverses order ' + orderNumber + ' (' + amount + '). Its stock returns to the batches it came from, '
                        + 'and it drops out of sales reports and shift totals. This cannot be undone.';
                    // A note is useful on an approval but not required -- the
                    // approval itself is the message. A rejection is the opposite:
                    // the cashier is left with an order they still believe is wrong.
                    notes.removeAttribute('required');
                    notesRequired.hidden = true;
                    confirmBtn.textContent = 'Approve and void';
                } else {
                    document.getElementById('reviewModalTitle').textContent = 'Reject void ' + voidNumber;
                    document.getElementById('reviewModalIntro').textContent =
                        'Order ' + orderNumber + ' (' + amount + ') stays exactly as it is. Tell the cashier why.';
                    notes.setAttribute('required', 'required');
                    notesRequired.hidden = false;
                    confirmBtn.textContent = 'Reject request';
                }

                reviewModal.open();
            });
        });

        document.getElementById('btnCloseReviewModal').addEventListener('click', reviewModal.close);
        document.getElementById('btnCancelReview').addEventListener('click', reviewModal.close);
        reviewBackdrop.addEventListener('click', (e) => { if (e.target === reviewBackdrop) reviewModal.close(); });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.owner-modal-backdrop.is-open').forEach((bd) => {
            bd.classList.remove('is-open');
            document.body.classList.remove('owner-modal-open');
        });
    });
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
<script src="../owner/assets/js/filter-autosubmit.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-autosubmit.js') ?>"></script>
</body>
</html>
