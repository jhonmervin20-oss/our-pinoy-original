<?php
/**
 * cashier/orders.php
 *
 * Order History for the cashier's own POS terminal -- a read-only,
 * filtered, paginated list of every order THIS cashier has rung up.
 * Self-scoped the same way remittance_report.php scopes shift history to
 * "your own shifts only" (WHERE o.cashier_id = the viewer, see
 * buildCashierOrdersQuery() in includes/pos_functions.php) -- a cashier
 * has never been able to see another cashier's shifts, and this page
 * keeps that same boundary for orders.
 *
 * Distinct from orders/orders.php (Owner/Manager's cross-cashier oversight
 * view, styled with owner-panel.css) -- this one lives in the pos-shell
 * design system (pos.css) and reuses components remittance_report.php
 * already established there: .pos-report-table, .pos-status-pill, the
 * "Receipt" button + #receiptViewModalBackdrop pattern (fetches the same
 * api/receipt_fragment.php?order_id=N fragment pos.php's own post-checkout
 * receipt modal uses, so a cashier can look up and reprint a past
 * receipt without leaving this page).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/void_functions.php';
require_once __DIR__ . '/includes/pos_functions.php';

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
$activePage = 'orders';

$restaurantName = $db->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
)->fetchColumn() ?: 'OPO! Our Pinoy Original';

$filters = [
    'date_from'      => trim((string)($_GET['date_from'] ?? '')),
    'date_to'        => trim((string)($_GET['date_to'] ?? '')),
    'order_type'     => trim((string)($_GET['order_type'] ?? '')),
    'order_source'   => trim((string)($_GET['order_source'] ?? '')),
    'search'         => trim((string)($_GET['search'] ?? '')),
];

$pageSize    = 20;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalOrders = 0;
$totalPages  = 1;
$orders      = [];
$voidStates  = [];
$dbError     = null;

try {
    [$sql, $params] = buildCashierOrdersQuery($viewerId, $filters);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM ({$sql}) AS counted");
    $countStmt->execute($params);
    $totalOrders = (int)$countStmt->fetchColumn();
    $totalPages  = max(1, (int)ceil($totalOrders / $pageSize));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $pageSize;

    $stmt = $db->prepare($sql . " LIMIT {$pageSize} OFFSET {$offset}");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    // One query for the whole page rather than one per row -- this is the only
    // place the cashier ever learns what happened to a void they asked for,
    // since the POS shell has no notification bell to send a decision to.
    $voidStates = orderVoidStatesByOrderIds($db, array_column($orders, 'order_id'));
} catch (PDOException $e) {
    error_log('cashier/orders.php failed: ' . $e->getMessage());
    $dbError = "Couldn't load order history. Please refresh this page.";
    $voidStates = [];
}

$queryString = http_build_query(array_filter($filters));
$returnQs    = http_build_query(array_filter(array_merge($filters, ['page' => $currentPage])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<title>Orders | <?= htmlspecialchars($restaurantName) ?></title>
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
                <form method="get" action="orders.php" class="pos-report-filter-form" style="margin:0;">
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>" title="From date">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>" title="To date">
                    <select name="order_type">
                        <option value="">All types</option>
                        <option value="dine_in" <?= $filters['order_type'] === 'dine_in' ? 'selected' : '' ?>>Dine In</option>
                        <option value="takeout" <?= $filters['order_type'] === 'takeout' ? 'selected' : '' ?>>Takeout</option>
                    </select>
                    <select name="order_source">
                        <option value="">All visit types</option>
                        <option value="walk_in" <?= $filters['order_source'] === 'walk_in' ? 'selected' : '' ?>>Walk-in</option>
                        <option value="reservation" <?= $filters['order_source'] === 'reservation' ? 'selected' : '' ?>>Reservation</option>
                    </select>
                    <input type="text" name="search" placeholder="Order #" value="<?= htmlspecialchars($filters['search']) ?>">
                    <button type="submit" class="pos-btn-primary">Filter</button>
                    <a href="orders.php" class="pos-btn-secondary" style="width:auto;padding:8px 16px;">Clear</a>
                </form>
            </div>

            <?php /* Filters sit in their own card above the table -- same split used
                     across every list page. .pos-report-card already carries its own
                     margin-bottom, so the two cards space themselves. */ ?>
            <div class="pos-report-card">
                <div class="pos-report-table-wrap">
                    <table class="pos-report-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date &amp; time</th>
                                <th>Type</th>
                                <th>Visit type</th>
                                <th>Payment method</th>
                                <th class="num">Total</th>
                                <th>Void</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr><td colspan="8" class="pos-report-empty-row">No orders match your filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ($orders as $o):
                                    $void = $voidStates[(int)$o['order_id']] ?? null;
                                    // Same helper the submit handler enforces with, so the
                                    // button and the POST can never disagree about who may
                                    // ask for what.
                                    [$canRequest, ] = orderVoidEligibility($db, $o, $viewerId, $void);
                                ?>
                                    <tr<?= $o['order_status'] === 'voided' ? ' style="opacity:.62;"' : '' ?>>
                                        <td><?= htmlspecialchars($o['order_number']) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($o['created_at']))) ?></td>
                                        <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $o['order_type']))) ?></td>
                                        <td>
                                            <?php if ($o['reservation_id'] !== null): ?>
                                                <span class="pos-status-pill is-info">Reservation</span>
                                            <?php else: ?>
                                                <span style="color:var(--op-ink-faint);">Walk-in</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $o['payment_method'] ? htmlspecialchars(paymentMethodLabel($o['payment_method'])) : '&mdash;' ?></td>
                                        <td class="num">&#8369;<?= number_format((float)$o['total_amount'], 2) ?></td>
                                        <td>
                                            <?php if ($void === null): ?>
                                                <span style="color:var(--op-ink-faint);">&mdash;</span>
                                            <?php else: ?>
                                                <span class="pos-status-pill <?= voidStatusBadgeClass($void['status']) ?>"><?= htmlspecialchars(voidStatusLabel($void['status'])) ?></span>
                                                <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:3px;"><?= htmlspecialchars($void['void_number']) ?></div>
                                                <?php if (!empty($void['review_notes'])): ?>
                                                    <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;max-width:200px;">&ldquo;<?= htmlspecialchars($void['review_notes']) ?>&rdquo;</div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
                                                <button type="button" class="pos-btn-secondary pos-view-receipt-btn" style="width:auto;padding:5px 10px;font-size:0.78rem;" data-order-id="<?= (int)$o['order_id'] ?>">Receipt</button>
                                                <?php if ($canRequest): ?>
                                                    <button type="button" class="pos-btn-secondary pos-btn-danger pos-void-request-btn" style="width:auto;padding:5px 10px;font-size:0.78rem;"
                                                            data-order-id="<?= (int)$o['order_id'] ?>"
                                                            data-order-number="<?= htmlspecialchars($o['order_number']) ?>"
                                                            data-amount="&#8369;<?= number_format((float)$o['total_amount'], 2) ?>">Request void</button>
                                                <?php elseif ($void !== null && $void['status'] === 'pending' && (int)$void['requested_by'] === $viewerId): ?>
                                                    <form method="post" action="void_request_action.php" style="display:inline;"
                                                          onsubmit="return confirm('Withdraw void request <?= htmlspecialchars($void['void_number'], ENT_QUOTES) ?>?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="withdraw">
                                                        <input type="hidden" name="void_request_id" value="<?= (int)$void['void_request_id'] ?>">
                                                        <input type="hidden" name="return_qs" value="<?= htmlspecialchars($returnQs) ?>">
                                                        <button type="submit" class="pos-btn-secondary" style="width:auto;padding:5px 10px;font-size:0.78rem;">Withdraw</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1):
                    $prevQs = http_build_query(array_filter(array_merge($filters, ['page' => $currentPage - 1])));
                    $nextQs = http_build_query(array_filter(array_merge($filters, ['page' => $currentPage + 1])));
                ?>
                <div class="pos-pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="orders.php?<?= htmlspecialchars($prevQs) ?>" class="pos-btn-secondary" style="width:auto;padding:6px 14px;"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</a>
                    <?php else: ?>
                        <button type="button" class="pos-btn-secondary" style="width:auto;padding:6px 14px;" disabled><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <?php endif; ?>
                    <span class="pos-pagination-info">Page <?= $currentPage ?> of <?= $totalPages ?></span>
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="orders.php?<?= htmlspecialchars($nextQs) ?>" class="pos-btn-secondary" style="width:auto;padding:6px 14px;">Next <i class="ph ph-caret-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <button type="button" class="pos-btn-secondary" style="width:auto;padding:6px 14px;" disabled>Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

        </main>

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

<!-- Request-void modal. Submits to void_request_action.php, which re-checks
     every rule in config/void_functions.php rather than trusting this form --
     a cashier can only ASK here; nothing on this page can void anything. -->
<div class="pos-modal-backdrop" id="voidRequestModalBackdrop">
    <div class="pos-modal">
        <form method="post" action="void_request_action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <input type="hidden" name="order_id" id="voidOrderId">
            <input type="hidden" name="return_qs" value="<?= htmlspecialchars($returnQs) ?>">

            <div class="pos-modal-header">
                <h2 class="pos-modal-title">Request void</h2>
                <button type="button" class="pos-modal-close" id="voidRequestModalClose" aria-label="Close">
                    <i class="ph ph-x" aria-hidden="true"></i>
                </button>
            </div>

            <div class="pos-modal-body">
                <p id="voidRequestIntro" style="margin:0 0 16px;color:var(--op-ink-soft);font-size:0.88rem;"></p>

                <div class="pos-field">
                    <label class="pos-field-label" for="voidReasonCode">Reason</label>
                    <select name="reason_code" id="voidReasonCode" class="pos-input" required>
                        <option value="">Choose a reason&hellip;</option>
                        <?php foreach (VOID_REASON_LABELS as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pos-field">
                    <label class="pos-field-label" for="voidReasonNotes">
                        Details <span id="voidNotesRequiredMark" hidden style="color:var(--op-danger);">*</span>
                    </label>
                    <textarea name="reason_notes" id="voidReasonNotes" class="pos-input" rows="3" maxlength="500"
                              placeholder="What happened? Your manager or the owner sees this when reviewing."></textarea>
                </div>
            </div>

            <?php // pos-checkout-btn, not "pos-btn-primary" -- that class does not
                  // exist in pos.css, so the submit button was rendering as a raw
                  // browser default. .pos-modal-footer already sizes both of these
                  // (flex:1 with footer padding), so no inline styles either. ?>
            <div class="pos-modal-footer">
                <button type="button" class="pos-btn-secondary" id="voidRequestCancelBtn">Cancel</button>
                <button type="submit" class="pos-checkout-btn">Submit request</button>
            </div>
        </form>
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
    function reclampOpenModals() {
        document.querySelectorAll('.pos-modal-backdrop.is-open').forEach(clampModalHeight);
    }
    window.addEventListener('resize', reclampOpenModals);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', reclampOpenModals);
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.pos-modal-backdrop.is-open').forEach(closeModal);
        }
    });

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

    document.querySelectorAll('.pos-view-receipt-btn').forEach((btn) => {
        btn.addEventListener('click', () => showReceipt(btn.getAttribute('data-order-id')));
    });

    const receiptCloseBtn = document.getElementById('receiptViewModalClose');
    if (receiptCloseBtn) receiptCloseBtn.addEventListener('click', () => closeModal(receiptBackdrop));
    const receiptPrintBtn = document.getElementById('receiptViewModalPrintBtn');
    if (receiptPrintBtn) receiptPrintBtn.addEventListener('click', () => window.print());

    // ---- Request void ----------------------------------------------------
    // Backdrop-click and Escape are already handled generically above for every
    // .pos-modal-backdrop, so this only has to wire the opener and the fields.
    const voidBackdrop = document.getElementById('voidRequestModalBackdrop');
    const voidReason = document.getElementById('voidReasonCode');
    const voidNotes = document.getElementById('voidReasonNotes');
    const voidNotesMark = document.getElementById('voidNotesRequiredMark');

    // "Other" carries no meaning on its own, so it's the one reason that makes
    // the free-text box mandatory. The server enforces this too -- this only
    // saves the cashier a round trip.
    function syncNotesRequirement() {
        const isOther = voidReason.value === 'other';
        voidNotes.required = isOther;
        voidNotesMark.hidden = !isOther;
    }
    voidReason.addEventListener('change', syncNotesRequirement);

    document.querySelectorAll('.pos-void-request-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.getElementById('voidOrderId').value = btn.getAttribute('data-order-id');
            document.getElementById('voidRequestIntro').textContent =
                'Ask for order ' + btn.getAttribute('data-order-number') + ' (' + btn.getAttribute('data-amount')
                + ') to be reversed. It stays exactly as it is until an owner or manager approves.';
            voidReason.value = '';
            voidNotes.value = '';
            syncNotesRequirement();
            openModal(voidBackdrop);
        });
    });

    const voidCloseBtn = document.getElementById('voidRequestModalClose');
    if (voidCloseBtn) voidCloseBtn.addEventListener('click', () => closeModal(voidBackdrop));
    const voidCancelBtn = document.getElementById('voidRequestCancelBtn');
    if (voidCancelBtn) voidCancelBtn.addEventListener('click', () => closeModal(voidBackdrop));
})();
</script>

</body>
</html>
