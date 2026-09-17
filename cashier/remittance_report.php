<?php
/**
 * cashier/remittance_report.php
 *
 * The Cash Balance module -- single source of truth for shift info, cash
 * reconciliation, and history. The page itself renders ONLY the shift
 * history table; per-shift detail (summary/orders/payment breakdown/notes)
 * is never rendered inline -- it always lives in the "View" modal, fetched
 * from this same page's own ?fragment=1 mode (see renderShiftDetailFragmentHtml()
 * below). Cashier-only -- every cashier only ever sees their OWN shifts in
 * that table. Owner/Manager never land here: the one thing that used to
 * send them here (the shift discrepancy notification) now redirects into
 * owner/report.php's Cashier Remittance tab instead, which they already
 * have real access to -- see owner/includes/notification_functions.php's
 * ownerNotifLink().
 *
 * $shift/$totals (resolved once, near the top: the viewer's own open shift,
 * else their own most recent closed one, or whatever ?shift_id=N points at)
 * exist purely to drive: (a) which row in the history table gets the inline
 * "Close" action (see $canClose below), and (b) the print/download/fragment
 * response modes -- they are NOT used to render anything on the page itself.
 *
 * Sales totals are always computed fresh from orders/order_payments via
 * computeShiftTotals() (see pos_functions.php) for the payment-method
 * breakdown and the orders list -- but once a shift is CLOSED, the
 * top-level KPIs (total sales / expected cash / cash on hand / variance)
 * are read from cash_balances' own snapshot columns, not recomputed live,
 * since a closed shift is meant to be an immutable, finalized record (no
 * new order can ever attach to a closed shift_id -- see create_order.php).
 *
 * Four response modes, all off the same $shift/$totals computed once above:
 *   ?print=1            chrome-free version for browser printing (mirrors
 *                        inventory/inventory_transactions.php's convention)
 *   ?download=1          streams a real .pdf via Dompdf (mirrors
 *                        purchase_orders/purchase_order_pdf.php's convention)
 *   ?fragment=1           the Shift History "View" modal's content -- just
 *                        the tabbed detail markup, no page chrome, fetch()ed
 *                        into the modal so viewing any shift (open or
 *                        closed) never navigates away from this page. Same
 *                        "one more read-only output branch of an existing
 *                        page" shape as the two modes above, not a new
 *                        endpoint.
 *   (none)               the Cash Balance page itself: just the shift
 *                        history table.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/pos_functions.php';

// Cashier-only -- Owner and Manager have no access to any cashier module
// page (pos.php's own pos_guard.php separately allows Owner in for
// emergency POS coverage, but that's that file's own long-standing
// decision, not this one). This page used to also allow Owner/Manager in
// for the shift discrepancy notification's sake; that notification now
// redirects to owner/report.php's Cashier Remittance tab instead, so
// there's no remaining reason for either role to land here.
Session::start();
if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['cashier'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$viewerId = Session::getUserId();
$activePage = 'remittance';

$restaurantName = $db->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
)->fetchColumn() ?: 'OPO! Our Pinoy Original';

// -- Resolve which shift to show -------------------------------------------
$requestedShiftId = isset($_GET['shift_id']) && ctype_digit((string)$_GET['shift_id']) ? (int)$_GET['shift_id'] : null;

$shift = null;
$dbError = null;

try {
    if ($requestedShiftId) {
        $stmt = $db->prepare(
            "SELECT cs.*, u.first_name, u.last_name
             FROM cash_balances cs JOIN users u ON u.user_id = cs.cashier_id
             WHERE cs.shift_id = ?"
        );
        $stmt->execute([$requestedShiftId]);
        $shift = $stmt->fetch() ?: null;
    } else {
        // Default: the viewer's own open shift, else their own most recent
        // closed one -- (status='open') sorts true (1) before false (0).
        $stmt = $db->prepare(
            "SELECT cs.*, u.first_name, u.last_name
             FROM cash_balances cs JOIN users u ON u.user_id = cs.cashier_id
             WHERE cs.cashier_id = ?
             ORDER BY (cs.status = 'open') DESC, cs.shift_id DESC
             LIMIT 1"
        );
        $stmt->execute([$viewerId]);
        $shift = $stmt->fetch() ?: null;
    }

    if ($shift && (int)$shift['cashier_id'] !== $viewerId) {
        $shift = null; // not yours
    }
} catch (PDOException $e) {
    $dbError = "Couldn't load this shift. Please refresh this page.";
}

$totals = null;
if ($shift) {
    try {
        $totals = computeShiftTotals($db, (int)$shift['shift_id'], (float)$shift['opening_cash']);
    } catch (PDOException $e) {
        $dbError = "Couldn't load this shift's totals. Please refresh this page.";
        $totals = null;
    }
}

// $shift is whichever shift is being closed via the modal below: the
// viewer's own open shift by default, or whatever ?shift_id= they
// navigated to (always their own -- see the visibility check above).
$canClose = $shift && $shift['status'] === 'open';

// -- Print / PDF / fragment modes -------------------------------------------
$isPrint = ($_GET['print'] ?? '') === '1';
$isDownload = ($_GET['download'] ?? '') === '1';
$isFragment = ($_GET['fragment'] ?? '') === '1';

if ($shift && $totals && ($isPrint || $isDownload)) {
    if ($isDownload) {
        require_once __DIR__ . '/../vendor/autoload.php';

        $html = renderRemittanceDocumentHtml($shift, $totals, $restaurantName, false);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Shift-' . $shift['shift_id'] . '-Remittance.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $dompdf->output();
        exit;
    }

    echo renderRemittanceDocumentHtml($shift, $totals, $restaurantName, true);
    exit;
}

/**
 * The Shift History "View" modal's content -- 4 tabs (Summary / Orders /
 * Payment Breakdown / Notes) built from the exact same $shift/$totals
 * shape the main page already renders from, just laid out for a modal
 * instead of a full page. No page chrome, no <html>. Defined here (not
 * pos_functions.php) since it's purely presentational, not shared by any
 * other page.
 */
function renderShiftDetailFragmentHtml(array $shift, array $totals): string
{
    $isClosed = $shift['status'] === 'closed';
    $totalSales   = $isClosed ? (float)$shift['total_sales'] : $totals['total_sales'];
    $expectedCash = $isClosed ? (float)$shift['expected_cash'] : $totals['expected_cash'];
    $countedCash  = $isClosed ? (float)$shift['counted_cash'] : null;
    $variance     = $isClosed ? (float)$shift['variance'] : null;
    $cashierName  = trim($shift['first_name'] . ' ' . $shift['last_name']);
    // Broken out explicitly so Expected Cash doesn't read as though it
    // should equal Total Sales -- GCash never touches the physical
    // drawer, only Cash sales do. Same reasoning as the print document's
    // renderRemittanceDocumentHtml().
    $cashSales  = $totals['by_method']['cash']['total'] ?? 0.0;
    $gcashSales = $totals['by_method']['paymongo_gcash']['total'] ?? 0.0;

    $varianceTone = $variance === null ? null : ($variance == 0 ? 'is-success' : ($variance > 0 ? 'is-info' : 'is-danger'));
    $varianceIcon = $variance === null ? null : ($variance == 0 ? 'ph-check-circle' : ($variance > 0 ? 'ph-info' : 'ph-warning'));
    $varianceTitle = $variance === null ? null : ($variance == 0
        ? 'Drawer balanced'
        : ($variance > 0
            ? '₱' . number_format($variance, 2) . ' over expected'
            : '₱' . number_format(abs($variance), 2) . ' short of expected'));

    ob_start();
    ?>
    <div class="pos-tabs" id="shiftDetailTabs">
        <button type="button" class="pos-tabs-link is-active" data-shift-tab="summary">Summary</button>
        <button type="button" class="pos-tabs-link" data-shift-tab="orders">Orders</button>
        <button type="button" class="pos-tabs-link" data-shift-tab="payments">Payment Breakdown</button>
        <button type="button" class="pos-tabs-link" data-shift-tab="notes">Notes</button>
    </div>

    <div class="pos-shift-tab-panel" data-shift-panel="summary">
        <?php if ($variance !== null): ?>
            <div class="pos-variance-banner <?= $varianceTone ?>">
                <i class="ph <?= $varianceIcon ?>" aria-hidden="true"></i>
                <div>
                    <div class="pos-variance-banner-title"><?= htmlspecialchars($varianceTitle) ?></div>
                    <div class="pos-variance-banner-sub">Cash on hand &#8369;<?= number_format($countedCash, 2) ?> against expected &#8369;<?= number_format($expectedCash, 2) ?></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="pos-summary-section-label">Sales this shift</div>
        <div class="pos-summary-card-group">
            <div>
                <div class="pos-summary-card-label">Petty cash fund</div>
                <div class="pos-summary-card-value">&#8369;<?= number_format((float)$shift['opening_cash'], 2) ?></div>
            </div>
            <div>
                <div class="pos-summary-card-label">Cash sales</div>
                <div class="pos-summary-card-value">&#8369;<?= number_format($cashSales, 2) ?></div>
            </div>
            <div>
                <div class="pos-summary-card-label">GCash sales</div>
                <div class="pos-summary-card-value">&#8369;<?= number_format($gcashSales, 2) ?></div>
            </div>
            <div>
                <div class="pos-summary-card-label">Total sales (all methods)</div>
                <div class="pos-summary-card-value">&#8369;<?= number_format($totalSales, 2) ?></div>
                <div class="pos-summary-card-meta"><?= $totals['total_orders'] ?> order<?= $totals['total_orders'] === 1 ? '' : 's' ?></div>
            </div>
        </div>

        <div class="pos-summary-section-label" style="margin-top:16px;">Cash drawer reconciliation</div>
        <?php /* The two formulas spelled out on the screen the cashier counts
                 against, in the same words the owner's report uses. */ ?>
        <p class="pos-modal-hint" style="margin:-4px 0 10px;text-align:left;">Expected cash = petty cash fund + cash sales &nbsp;&middot;&nbsp; Variance = cash on hand &minus; expected cash &nbsp;&middot;&nbsp; GCash never enters the drawer</p>
        <div class="pos-summary-card-group">
            <div>
                <div class="pos-summary-card-label">Expected cash</div>
                <div class="pos-summary-card-value">&#8369;<?= number_format($expectedCash, 2) ?></div>
                <div class="pos-summary-card-meta">Petty cash fund + cash sales</div>
            </div>
            <div>
                <div class="pos-summary-card-label">Cash on hand</div>
                <div class="pos-summary-card-value"><?= $countedCash === null ? '&mdash;' : '&#8369;' . number_format($countedCash, 2) ?></div>
            </div>
            <div>
                <div class="pos-summary-card-label">Variance</div>
                <div class="pos-summary-card-value">
                    <?php if ($variance === null): ?>
                        &mdash;
                    <?php elseif ($variance == 0): ?>
                        Balanced
                    <?php else: ?>
                        <?= ($variance > 0 ? '+' : '&minus;') ?>&#8369;<?= number_format(abs($variance), 2) ?>
                    <?php endif; ?>
                </div>
                <div class="pos-summary-card-meta"><?= $variance === null ? 'Not yet counted' : ($variance == 0 ? 'Exact match' : ($variance > 0 ? 'Over expected' : 'Short of expected')) ?></div>
            </div>
        </div>

        <div class="pos-report-card" style="margin-top:16px;">
            <div class="pos-report-card-head">
                <h2 class="pos-report-card-title">Details</h2>
            </div>
            <dl class="pos-detail-list">
                <div><dt>Shift number</dt><dd>#<?= (int)$shift['shift_id'] ?></dd></div>
                <div><dt>Cashier</dt><dd><?= htmlspecialchars($cashierName) ?></dd></div>
                <div><dt>Opened</dt><dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($shift['opened_at']))) ?></dd></div>
                <div><dt>Closed</dt><dd><?= $shift['closed_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($shift['closed_at']))) : '&mdash;' ?></dd></div>
                <div><dt>Duration</dt><dd><?= htmlspecialchars($shift['closed_at'] ? shiftDurationLabel($shift['opened_at'], $shift['closed_at']) : shiftDurationLabel($shift['opened_at']) . ' so far') ?></dd></div>
                <div><dt>Orders processed</dt><dd><?= $totals['total_orders'] ?></dd></div>
            </dl>
        </div>
    </div>

    <div class="pos-shift-tab-panel" data-shift-panel="orders" style="display:none;">
        <div class="pos-report-table-wrap">
            <table class="pos-report-table">
                <thead>
                    <tr><th>Order #</th><th>Time</th><th>Type</th><th>Method</th><th class="num">Amount</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($totals['orders'])): ?>
                        <tr><td colspan="6" class="pos-report-empty-row">No orders have been recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($totals['orders'] as $o): ?>
                            <tr>
                                <td><?= htmlspecialchars($o['order_number']) ?></td>
                                <td><?= htmlspecialchars(date('g:i A', strtotime($o['created_at']))) ?></td>
                                <td>
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $o['order_type']))) ?>
                                    <?php if ($o['reservation_id'] !== null): ?><span class="pos-status-pill is-info" style="margin-left:4px;">Reservation</span><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(paymentMethodLabel($o['payment_method'])) ?></td>
                                <td class="num">&#8369;<?= number_format((float)$o['amount'], 2) ?></td>
                                <td><button type="button" class="pos-btn-secondary pos-view-receipt-btn" style="width:auto;padding:5px 10px;font-size:0.78rem;" data-order-id="<?= (int)$o['order_id'] ?>">Receipt</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="pos-shift-tab-panel" data-shift-panel="payments" style="display:none;">
        <div class="pos-report-table-wrap">
            <table class="pos-report-table">
                <thead>
                    <tr><th>Method</th><th class="num">Orders</th><th class="num">Amount</th><th class="num">%</th></tr>
                </thead>
                <tbody>
                    <?php if ($totals['total_orders'] === 0): ?>
                        <tr><td colspan="4" class="pos-report-empty-row">No transactions recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($totals['by_method'] as $method => $data): ?>
                            <?php $pct = $totalSales > 0 ? round(($data['total'] / $totalSales) * 100, 1) : 0.0; ?>
                            <tr>
                                <td>
                                    <span class="pos-method-cell">
                                        <?= htmlspecialchars(paymentMethodLabel($method)) ?>
                                    </span>
                                </td>
                                <td class="num"><?= (int)$data['count'] ?></td>
                                <td class="num">&#8369;<?= number_format($data['total'], 2) ?></td>
                                <td class="num"><?= number_format($pct, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="pos-shift-tab-panel" data-shift-panel="notes" style="display:none;">
        <div class="pos-report-card">
            <div class="pos-report-card-head">
                <h2 class="pos-report-card-title">Closing notes</h2>
            </div>
            <?php if (!empty($shift['closing_notes'])): ?>
                <p style="margin:0;color:var(--op-ink-soft);font-size:0.88rem;"><?= nl2br(htmlspecialchars($shift['closing_notes'])) ?></p>
            <?php else: ?>
                <p style="margin:0;color:var(--op-ink-faint);font-size:0.85rem;">No notes recorded.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

if ($shift && $totals && $isFragment) {
    header('Content-Type: text/html; charset=UTF-8');
    echo renderShiftDetailFragmentHtml($shift, $totals);
    exit;
}

// -- History list -------------------------------------------------------------
// A cashier only ever sees their own shifts. Always newest-first
// (buildCashierShiftHistoryQuery()'s ORDER BY is unconditional -- there's
// no column-sort UI here, so nothing can ever reorder it away from that).
$historyFilters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to'   => trim((string)($_GET['date_to'] ?? '')),
    'status'    => trim((string)($_GET['status'] ?? '')),
    'search'    => trim((string)($_GET['search'] ?? '')),
];

$historyPageSize = 20;
$historyPage     = max(1, (int)($_GET['page'] ?? 1));
$historyTotal    = 0;
$historyPages    = 1;
$history         = [];

try {
    [$historySql, $historyParams] = buildCashierShiftHistoryQuery($viewerId, $historyFilters);

    $historyCountStmt = $db->prepare("SELECT COUNT(*) FROM ({$historySql}) AS counted");
    $historyCountStmt->execute($historyParams);
    $historyTotal = (int)$historyCountStmt->fetchColumn();
    $historyPages = max(1, (int)ceil($historyTotal / $historyPageSize));
    if ($historyPage > $historyPages) {
        $historyPage = $historyPages;
    }
    $historyOffset = ($historyPage - 1) * $historyPageSize;

    $historyStmt = $db->prepare($historySql . " LIMIT {$historyPageSize} OFFSET {$historyOffset}");
    $historyStmt->execute($historyParams);
    $history = $historyStmt->fetchAll();
} catch (PDOException $e) {
    $dbError = $dbError ?: "Couldn't load shift history. Please refresh this page.";
}

$pageTitle = 'Cash Balance | ' . htmlspecialchars($restaurantName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<!-- Without this, iOS Safari's Data Detectors auto-linkify anything that
     looks like a date/number in the receipt (Date, Time, item amounts)
     into blue tap-to-act text -- the receipt is a static record, not a
     set of tappable phone/calendar links. -->
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<title>Cash Balance | <?= htmlspecialchars($restaurantName) ?></title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/pos.css?v=<?= filemtime(__DIR__ . '/assets/css/pos.css') ?>">
</head>
<body>

<div class="pos-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="pos-content">

        <button type="button" class="pos-icon-btn pos-menu-toggle" id="posMenuToggle" aria-label="Open menu">
            <i class="ph ph-list" aria-hidden="true"></i>
        </button>

        <?= flash_render() ?>

        <?php if ($dbError): ?>
            <div class="pos-report-alert">
                <i class="ph ph-warning-circle" aria-hidden="true"></i>
                <span><?= htmlspecialchars($dbError) ?></span>
            </div>
        <?php endif; ?>

        <main class="pos-report-main">

            <div class="pos-report-card">
                <form method="get" action="remittance_report.php" class="pos-report-filter-form" style="margin:0;">
                    <input type="date" name="date_from" value="<?= htmlspecialchars($historyFilters['date_from']) ?>" title="From date">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($historyFilters['date_to']) ?>" title="To date">
                    <select name="status">
                        <option value="">All statuses</option>
                        <option value="open" <?= $historyFilters['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="closed" <?= $historyFilters['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                    <input type="text" name="search" placeholder="Search shift #" value="<?= htmlspecialchars($historyFilters['search']) ?>">
                    <button type="submit" class="pos-btn-primary">Filter</button>
                    <a href="remittance_report.php" class="pos-btn-secondary" style="width:auto;padding:8px 16px;">Clear</a>
                </form>
            </div>

            <?php /* Filters sit in their own card above the table -- same split used
                     across every list page. */ ?>
            <div class="pos-report-card">
                <div class="pos-report-table-wrap">
                    <table class="pos-report-table" id="shiftHistoryTable">
                        <thead>
                            <tr>
                                <?php /* Same column names, in the same order, as the owner's
                                         Cashier Remittance report -- a cashier and an owner
                                         comparing screens should be reading one vocabulary. */ ?>
                                <th>Shift</th>
                                <th>Opened</th>
                                <th class="num">Petty Cash Fund</th>
                                <th class="num">Total Sales</th>
                                <th class="num">Cash on Hand</th>
                                <th class="num">Variance</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                                <tr><td colspan="8" class="pos-report-empty-row">No shifts match your filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $h): ?>
                                    <?php
                                    $hVariance = $h['variance'] === null ? null : (float)$h['variance'];
                                    $hVarianceTone = $hVariance === null ? 'is-neutral' : ($hVariance == 0 ? 'is-success' : ($hVariance > 0 ? 'is-info' : 'is-danger'));
                                    $hVarianceLabel = $hVariance === null
                                        ? '&mdash;'
                                        : ($hVariance == 0 ? 'Balanced' : ($hVariance > 0 ? '+₱' . number_format($hVariance, 2) . ' over' : '−₱' . number_format(abs($hVariance), 2) . ' short'));
                                    ?>
                                    <tr>
                                        <td>#<?= (int)$h['shift_id'] ?></td>
                                        <td><?= htmlspecialchars(date('M j, g:i A', strtotime($h['opened_at']))) ?></td>
                                        <td class="num">&#8369;<?= number_format((float)$h['opening_cash'], 2) ?></td>
                                        <td class="num"><?= $h['total_sales'] === null ? '&mdash;' : '&#8369;' . number_format((float)$h['total_sales'], 2) ?></td>
                                        <td class="num"><?= $h['counted_cash'] === null ? '&mdash;' : '&#8369;' . number_format((float)$h['counted_cash'], 2) ?></td>
                                        <td class="num">
                                            <?php if ($hVariance === null): ?>
                                                &mdash;
                                            <?php else: ?>
                                                <span class="pos-status-pill <?= $hVarianceTone ?>"><?= $hVarianceLabel ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="pos-status-pill <?= shiftStatusBadgeClass($h['status']) ?>"><?= htmlspecialchars(shiftStatusLabel($h['status'])) ?></span></td>
                                        <td>
                                            <div class="pos-table-actions">
                                                <?php /* View comes first on every row, open or closed, so the eye
                                                         stays in the same column down the whole table -- with
                                                         Close Shift ahead of it the open row's eye sat out of
                                                         line with every other row's. */ ?>
                                                <button type="button" class="pos-icon-link pos-view-shift-btn" aria-label="View shift #<?= (int)$h['shift_id'] ?>" title="View" data-shift-id="<?= (int)$h['shift_id'] ?>"><i class="ph ph-eye" aria-hidden="true"></i></button>
                                                <?php if ($shift && $canClose && (int)$h['shift_id'] === (int)$shift['shift_id']): ?>
                                                    <button type="button" class="pos-checkout-btn" style="width:auto;padding:6px 12px;font-size:0.8rem;" id="btnOpenCloseShift">
                                                       Close Shift
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($h['status'] === 'closed'): ?>
                                                    <a href="?shift_id=<?= (int)$h['shift_id'] ?>&download=1" class="pos-btn-secondary pos-btn-inline" aria-label="Download shift #<?= (int)$h['shift_id'] ?> as PDF">
                                                        <i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($historyPages > 1):
                    $historyPrevQs = http_build_query(array_filter(array_merge($historyFilters, ['page' => $historyPage - 1])));
                    $historyNextQs = http_build_query(array_filter(array_merge($historyFilters, ['page' => $historyPage + 1])));
                ?>
                <div class="pos-pagination">
                    <?php if ($historyPage > 1): ?>
                        <a href="remittance_report.php?<?= htmlspecialchars($historyPrevQs) ?>" class="pos-btn-secondary" style="width:auto;padding:6px 14px;"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</a>
                    <?php else: ?>
                        <button type="button" class="pos-btn-secondary" style="width:auto;padding:6px 14px;" disabled><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <?php endif; ?>
                    <span class="pos-pagination-info">Page <?= $historyPage ?> of <?= $historyPages ?></span>
                    <?php if ($historyPage < $historyPages): ?>
                        <a href="remittance_report.php?<?= htmlspecialchars($historyNextQs) ?>" class="pos-btn-secondary" style="width:auto;padding:6px 14px;">Next <i class="ph ph-caret-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <button type="button" class="pos-btn-secondary" style="width:auto;padding:6px 14px;" disabled>Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

        </main>

    </div>

</div>

<?php if ($shift && $canClose): ?>
<!-- Close Shift modal -- two steps inside ONE modal (no second stacked
     backdrop, so nothing to get a z-index wrong): step "entry" takes the
     counted cash, step "confirm" plays it back ("Are you sure you input the
     right counted cash?") before anything is posted. Both step panes live
     inside the same <form>, so the fields still submit exactly as before --
     same shift_close.php action, same counted_cash / closing_notes names --
     and with JS off the entry step's submit button posts straight through,
     which is the pre-confirmation behaviour rather than a broken one. -->
<div class="pos-modal-backdrop" id="closeShiftModalBackdrop">
    <div class="pos-modal">
        <div class="pos-modal-header">
            <h2 class="pos-modal-title" id="closeShiftModalTitle">Close Shift</h2>
            <button type="button" class="pos-modal-close" id="closeShiftModalClose" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="POST" action="shift_close.php" id="closeShiftForm">
            <?= csrf_field() ?>

            <!-- Step 1: enter the counted cash -->
            <div class="pos-modal-body" data-close-step="entry">
                <div class="pos-totals-row is-balance" style="margin-bottom:14px;">
                    <span>Expected cash</span>
                    <span>&#8369;<?= number_format($totals['expected_cash'], 2) ?></span>
                </div>
                <div class="pos-shift-form-group">
                    <label for="countedCashInput">Cash on hand <span style="color:var(--op-danger);">*</span></label>
                    <input type="number" id="countedCashInput" name="counted_cash" step="0.01" min="0" placeholder="0.00" inputmode="decimal" required data-expected="<?= htmlspecialchars((string)$totals['expected_cash']) ?>">
                    <div id="closeDiffPreview" class="pos-close-diff-preview"></div>
                </div>
                <div class="pos-shift-form-group" style="margin-top:12px;">
                    <label for="closingNotesInput">Notes <span style="color:var(--op-ink-faint);">(optional)</span></label>
                    <input type="text" id="closingNotesInput" name="closing_notes" maxlength="255" placeholder="e.g. Short due to a misprinted receipt refund">
                </div>
            </div>

            <!-- Step 2: read back what was typed before committing -->
            <div class="pos-modal-body" data-close-step="confirm" hidden>
                <div class="pos-confirm-count">
                    <p class="pos-confirm-count-q">Are you sure this is the right cash on hand?</p>
                    <div class="pos-confirm-count-amount" id="confirmCountedAmount">&#8369;0.00</div>
                    <p class="pos-confirm-count-label">Cash on hand you entered</p>
                </div>
                <div class="pos-totals-row">
                    <span>Expected cash</span>
                    <span>&#8369;<?= number_format($totals['expected_cash'], 2) ?></span>
                </div>
                <div class="pos-totals-row" id="confirmVarianceRow">
                    <span>Difference</span>
                    <span id="confirmVarianceValue">&mdash;</span>
                </div>
                <div class="pos-totals-row" id="confirmNotesRow" hidden>
                    <span>Notes</span>
                    <span id="confirmNotesValue" style="max-width:60%;text-align:right;"></span>
                </div>
                <p class="pos-modal-hint">Closing a shift is final &mdash; it can&rsquo;t be reopened or edited afterwards.</p>
            </div>

            <div class="pos-modal-footer" data-close-step="entry">
                <button type="button" class="pos-btn-secondary" id="closeShiftModalCancel">Cancel</button>
                <button type="submit" class="pos-checkout-btn" id="closeShiftReviewBtn">
                    <i class="ph ph-arrow-right" aria-hidden="true"></i> Review
                </button>
            </div>
            <div class="pos-modal-footer" data-close-step="confirm" hidden>
                <button type="button" class="pos-btn-secondary" id="closeShiftBackBtn">
                    Back &amp; edit
                </button>
                <button type="button" class="pos-checkout-btn" id="closeShiftConfirmBtn">
                    Yes, close shift
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Shift detail modal -- Shift History "View" opens this instead of
     navigating away; content is fetch()ed once from this same page's
     own ?fragment=1 mode. -->
<div class="pos-modal-backdrop pos-modal-backdrop-lg" id="shiftDetailModalBackdrop">
    <div class="pos-modal pos-modal-lg">
        <div class="pos-modal-header">
            <h2 class="pos-modal-title" id="shiftDetailModalTitle">Shift</h2>
            <button type="button" class="pos-modal-close" id="shiftDetailModalClose" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="pos-modal-body" id="shiftDetailModalBody">
            <p style="text-align:center;color:var(--op-ink-faint);padding:30px 0;">Loading&hellip;</p>
        </div>
        <div class="pos-modal-footer">
            <a href="#" class="pos-btn-secondary" id="shiftDetailDownloadLink">
                <i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF
            </a>
            <button type="button" class="pos-btn-secondary" id="shiftDetailModalCloseFooter">Close</button>
        </div>
    </div>
</div>

<!-- Receipt view modal -- reuses the same api/receipt_fragment.php?order_id=N
     endpoint pos.php's own post-checkout receipt modal already calls. -->
<div class="pos-modal-backdrop" id="receiptViewModalBackdrop">
    <div class="pos-modal">
        <div class="pos-modal-header">
            <h2 class="pos-modal-title">Receipt</h2>
            <button type="button" class="pos-modal-close" id="receiptViewModalClose" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="pos-modal-body" id="receiptViewModalBody"></div>
        <div class="pos-modal-footer">
            <button type="button" class="pos-btn-secondary" id="receiptViewModalPrintBtn">Print</button>
        </div>
    </div>
</div>

<script>
(function () {
    const menuToggle = document.getElementById('posMenuToggle');
    const sidebar = document.getElementById('posSidebar');
    const backdrop = document.getElementById('posSidebarBackdrop');
    if (menuToggle && sidebar && backdrop) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('is-open');
            backdrop.classList.toggle('is-visible');
        });
        backdrop.addEventListener('click', () => {
            sidebar.classList.remove('is-open');
            backdrop.classList.remove('is-visible');
        });
    }

    // -- Generic modal open/close helpers (mirrors pos.js's own) ------------
    // clampModalHeight: pos.css's max-height: calc(100dvh - 40px) covers
    // most browsers, but some mobile/tablet Safari versions still misreport
    // dvh (or don't shrink it) inside Stage Manager / windowed Safari,
    // letting the modal grow taller than what's actually visible and
    // pushing its footer buttons below the fold. window.innerHeight is
    // what the browser itself says is visible right now, no unit
    // ambiguity -- setting an inline max-height from it overrides the CSS
    // as a guaranteed floor.
    function clampModalHeight(backdrop) {
        const box = backdrop.querySelector('.pos-modal');
        if (!box) return;
        const viewportHeight = (window.visualViewport && window.visualViewport.height) || window.innerHeight;
        box.style.maxHeight = Math.max(200, viewportHeight - 40) + 'px';
    }
    function openModal(el) {
        if (!el) return;
        el.classList.add('is-open');
        document.body.classList.add('pos-modal-open');
        clampModalHeight(el);
    }
    function closeModal(el) {
        if (!el) return;
        el.classList.remove('is-open');
        document.body.classList.remove('pos-modal-open');
    }
    document.querySelectorAll('.pos-modal-backdrop').forEach((bd) => {
        bd.addEventListener('click', (e) => { if (e.target === bd) closeModal(bd); });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.pos-modal-backdrop.is-open').forEach(closeModal);
        }
    });
    function reclampOpenModals() {
        document.querySelectorAll('.pos-modal-backdrop.is-open').forEach(clampModalHeight);
    }
    window.addEventListener('resize', reclampOpenModals);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', reclampOpenModals);
    }

    // -- Close Shift modal: live variance preview + "are you sure" step -----
    const closeShiftBackdrop = document.getElementById('closeShiftModalBackdrop');
    const btnOpenCloseShift = document.getElementById('btnOpenCloseShift');
    const closeShiftForm = document.getElementById('closeShiftForm');
    const closeShiftTitle = document.getElementById('closeShiftModalTitle');
    const countedInput = document.getElementById('countedCashInput');
    const notesInput = document.getElementById('closingNotesInput');
    const diffPreview = document.getElementById('closeDiffPreview');

    const peso = (n) => '₱' + Math.abs(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // One source of truth for the expected-vs-counted math, shared by the
    // live preview under the input and the confirmation read-back, so the
    // two can never disagree. Returns null when there's nothing to compare.
    function readCounted() {
        if (!countedInput) return null;
        const raw = countedInput.value.trim();
        if (raw === '') return null;
        const counted = parseFloat(raw);
        if (isNaN(counted)) return null;
        const expected = parseFloat(countedInput.dataset.expected) || 0;
        return { counted: counted, expected: expected, diff: Math.round((counted - expected) * 100) / 100 };
    }

    // Swap which step of the Close Shift modal is showing. Both the body and
    // the footer of each step carry data-close-step, so one call moves the
    // pane and its buttons together.
    function setCloseShiftStep(step) {
        if (!closeShiftForm) return;
        closeShiftForm.querySelectorAll('[data-close-step]').forEach((el) => {
            el.hidden = el.getAttribute('data-close-step') !== step;
        });
        if (closeShiftTitle) {
            closeShiftTitle.textContent = step === 'confirm' ? 'Confirm Cash on Hand' : 'Close Shift';
        }
        if (closeShiftBackdrop && closeShiftBackdrop.classList.contains('is-open')) {
            clampModalHeight(closeShiftBackdrop);
        }
    }

    if (btnOpenCloseShift && closeShiftBackdrop) {
        btnOpenCloseShift.addEventListener('click', () => {
            // Always reopen on step 1 -- the modal can be dismissed from the
            // confirm step (Esc / backdrop / X), and coming back straight
            // into a stale "are you sure" would be a trap. The typed amount
            // is deliberately kept so nothing has to be re-entered.
            setCloseShiftStep('entry');
            openModal(closeShiftBackdrop);
        });
    }
    const closeShiftCloseBtn = document.getElementById('closeShiftModalClose');
    const closeShiftCancelBtn = document.getElementById('closeShiftModalCancel');
    if (closeShiftCloseBtn) closeShiftCloseBtn.addEventListener('click', () => closeModal(closeShiftBackdrop));
    if (closeShiftCancelBtn) closeShiftCancelBtn.addEventListener('click', () => closeModal(closeShiftBackdrop));

    if (countedInput && diffPreview) {
        countedInput.addEventListener('input', () => {
            const state = readCounted();
            if (!state) {
                diffPreview.className = 'pos-close-diff-preview';
                diffPreview.innerHTML = '';
                return;
            }
            let tone, icon, text;
            if (state.diff === 0) {
                tone = 'is-success'; icon = 'ph-check-circle'; text = 'Matches expected cash exactly';
            } else if (state.diff > 0) {
                tone = 'is-info'; icon = 'ph-info'; text = peso(state.diff) + ' over expected';
            } else {
                tone = 'is-danger'; icon = 'ph-warning'; text = peso(state.diff) + ' short of expected';
            }
            diffPreview.className = 'pos-close-diff-preview is-visible ' + tone;
            diffPreview.innerHTML = '<i class="ph ' + icon + '" aria-hidden="true"></i><span>' + text + '</span>';
        });
    }

    const confirmAmountEl  = document.getElementById('confirmCountedAmount');
    const confirmVarRow    = document.getElementById('confirmVarianceRow');
    const confirmVarValue  = document.getElementById('confirmVarianceValue');
    const confirmNotesRow  = document.getElementById('confirmNotesRow');
    const confirmNotesVal  = document.getElementById('confirmNotesValue');
    const closeShiftBackBtn    = document.getElementById('closeShiftBackBtn');
    const closeShiftConfirmBtn = document.getElementById('closeShiftConfirmBtn');
    let closeShiftSubmitting = false;

    if (closeShiftForm) {
        // The entry step's button is a real type="submit", so the browser runs
        // its own validation (required / min / step) first and this handler
        // only ever fires on a valid amount. We then stop the post and show
        // the read-back instead. With JS unavailable the same button just
        // posts, exactly as it did before this step existed.
        closeShiftForm.addEventListener('submit', (e) => {
            if (closeShiftSubmitting) return;   // the real post, let it through
            e.preventDefault();
            const state = readCounted();
            if (!state) {
                setCloseShiftStep('entry');
                if (countedInput) countedInput.focus();
                return;
            }
            if (confirmAmountEl) confirmAmountEl.textContent = peso(state.counted);
            if (confirmVarRow && confirmVarValue) {
                confirmVarRow.className = 'pos-totals-row';
                if (state.diff === 0) {
                    confirmVarRow.classList.add('is-exact');
                    confirmVarValue.textContent = 'Balanced — matches exactly';
                } else if (state.diff > 0) {
                    confirmVarRow.classList.add('is-over');
                    confirmVarValue.textContent = '+' + peso(state.diff) + ' over';
                } else {
                    confirmVarRow.classList.add('is-short');
                    confirmVarValue.textContent = '−' + peso(state.diff) + ' short';
                }
            }
            if (confirmNotesRow && confirmNotesVal) {
                const notes = notesInput ? notesInput.value.trim() : '';
                confirmNotesVal.textContent = notes;      // textContent, never innerHTML
                confirmNotesRow.hidden = notes === '';
            }
            setCloseShiftStep('confirm');
            if (closeShiftConfirmBtn) closeShiftConfirmBtn.focus();
        });
    }

    if (closeShiftBackBtn) {
        closeShiftBackBtn.addEventListener('click', () => {
            setCloseShiftStep('entry');
            if (countedInput) {
                countedInput.focus();
                countedInput.select();
            }
        });
    }

    if (closeShiftConfirmBtn && closeShiftForm) {
        closeShiftConfirmBtn.addEventListener('click', () => {
            if (closeShiftSubmitting) return;
            // Re-check before committing. If something is somehow invalid we
            // go BACK to the entry step first, then report it -- a browser
            // can't show a validation bubble on a control inside a hidden
            // pane, and would otherwise just refuse to submit with no
            // message at all.
            if (!closeShiftForm.checkValidity()) {
                setCloseShiftStep('entry');
                closeShiftForm.reportValidity();
                return;
            }
            closeShiftSubmitting = true;
            closeShiftConfirmBtn.disabled = true;
            if (closeShiftBackBtn) closeShiftBackBtn.disabled = true;
            closeShiftConfirmBtn.innerHTML = '<i class="ph ph-spinner" aria-hidden="true"></i> Closing…';
            // form.submit() (not requestSubmit) on purpose: it posts without
            // re-running validation and without re-firing the submit event,
            // so there is no way for the confirmation handler to intercept
            // its own confirmed submit.
            closeShiftForm.submit();
        });
    }

    // -- Shift detail modal (Shift History "View") ---------------------------
    const shiftDetailBackdrop = document.getElementById('shiftDetailModalBackdrop');
    const shiftDetailBody = document.getElementById('shiftDetailModalBody');
    const shiftDetailTitle = document.getElementById('shiftDetailModalTitle');
    const shiftDetailDownloadLink = document.getElementById('shiftDetailDownloadLink');

    function wireShiftTabs() {
        const tabs = shiftDetailBody.querySelectorAll('[data-shift-tab]');
        const panels = shiftDetailBody.querySelectorAll('[data-shift-panel]');
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const key = tab.getAttribute('data-shift-tab');
                tabs.forEach((t) => t.classList.toggle('is-active', t === tab));
                panels.forEach((p) => { p.style.display = (p.getAttribute('data-shift-panel') === key) ? '' : 'none'; });
            });
        });
    }

    document.querySelectorAll('.pos-view-shift-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const shiftId = btn.getAttribute('data-shift-id');
            shiftDetailTitle.textContent = 'Shift #' + shiftId;
            shiftDetailBody.innerHTML = '<p style="text-align:center;color:var(--op-ink-faint);padding:30px 0;">Loading&hellip;</p>';
            shiftDetailDownloadLink.href = '?shift_id=' + shiftId + '&download=1';
            openModal(shiftDetailBackdrop);

            try {
                const response = await fetch('?shift_id=' + shiftId + '&fragment=1');
                if (!response.ok) throw new Error('Could not load this shift.');
                shiftDetailBody.innerHTML = await response.text();
                wireShiftTabs();
                wireReceiptButtons(shiftDetailBody);
            } catch (err) {
                shiftDetailBody.innerHTML = '<p style="text-align:center;color:var(--op-danger);padding:30px 0;">' + err.message + '</p>';
            }
        });
    });

    const shiftDetailCloseBtn = document.getElementById('shiftDetailModalClose');
    const shiftDetailCloseFooterBtn = document.getElementById('shiftDetailModalCloseFooter');
    if (shiftDetailCloseBtn) shiftDetailCloseBtn.addEventListener('click', () => closeModal(shiftDetailBackdrop));
    if (shiftDetailCloseFooterBtn) shiftDetailCloseFooterBtn.addEventListener('click', () => closeModal(shiftDetailBackdrop));

    // -- Receipt view modal ---------------------------------------------------
    const receiptBackdrop = document.getElementById('receiptViewModalBackdrop');
    const receiptBody = document.getElementById('receiptViewModalBody');

    async function showReceipt(orderId) {
        receiptBody.innerHTML = '<p style="text-align:center;color:var(--op-ink-faint);padding:30px 0;">Loading receipt&hellip;</p>';
        openModal(receiptBackdrop);
        try {
            const response = await fetch('api/receipt_fragment.php?order_id=' + orderId);
            const html = await response.text();
            if (!response.ok) throw new Error('Could not load the receipt.');
            receiptBody.innerHTML = html;
        } catch (err) {
            receiptBody.innerHTML = '<p style="text-align:center;color:var(--op-danger);padding:30px 0;">' + err.message + '</p>';
        }
    }

    function wireReceiptButtons(scope) {
        scope.querySelectorAll('.pos-view-receipt-btn').forEach((btn) => {
            btn.addEventListener('click', () => showReceipt(btn.getAttribute('data-order-id')));
        });
    }
    wireReceiptButtons(document);

    const receiptCloseBtn = document.getElementById('receiptViewModalClose');
    if (receiptCloseBtn) receiptCloseBtn.addEventListener('click', () => closeModal(receiptBackdrop));
    const receiptPrintBtn = document.getElementById('receiptViewModalPrintBtn');
    if (receiptPrintBtn) receiptPrintBtn.addEventListener('click', () => window.print());
})();
</script>

</body>
</html>