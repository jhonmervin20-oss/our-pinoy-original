<?php
/**
 * reservation/reservations.php
 *
 * Staff reservation management — Owner + Manager, shared the same way
 * inventory/ and purchase_orders/ already are: one physical page, gated by
 * role, conditionally rendering the owner or manager sidebar/header.
 * Card-style list (not a table), filterable by date + status tab + a
 * client-side search, each card opening a read-only detail modal.
 *
 * Read-only by design: every status this page tracks (confirmed, completed,
 * no_show) already transitions on its own -- completed fires the moment a
 * cashier links an order to the reservation (cashier/api/create_order.php),
 * no_show fires from sweepNoShowReservations() below once the grace period
 * lapses with no order linked. Pending and cancelled are the customer's
 * side of the flow (an unpaid hold in progress, or that hold lapsing/being
 * backed out of before payment) and are deliberately not fetched here at
 * all -- staff never needed to act on either, and now don't track them.
 * There used to be a staff write path (reservation_action.php: manual Mark
 * Completed / Mark No-show / Cancel Reservation) -- removed as redundant
 * with the automatic transitions above.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/display_helpers.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../customer/includes/reservation_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'reservations';
$pageTitle  = 'Reservations';
$isManager  = Session::hasRole(['manager']);

$pdo = Database::getInstance()->getConnection();

$dbNow = getDbNow($pdo);
$today = $dbNow['today'];

$settings = getReservationSettings($pdo);
sweepExpiredReservationHolds($pdo, $settings['reservation_hold_minutes']);
sweepNoShowReservations($pdo, $settings['reservation_no_show_hours']);

$showAllDates = ($_GET['all_dates'] ?? '') === '1';
// Pre-fills the search box so another page can deep-link to one reservation
// (the dashboard's "Today's Reservations" rows pass ?q=<reservation number>).
// No JS change needed: applyFilters() already runs on load and reads this
// input's value, so a server-rendered value filters the grid immediately.
$prefillSearch = trim((string)($_GET['q'] ?? ''));
$dateFilter   = trim((string)($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = $today;
}

// Pending and cancelled are the customer's side of the flow -- a pending
// hold is just an unpaid checkout in progress (never confirmed a table),
// and cancellations are either that hold lapsing or a customer backing out
// before paying. Staff only need to track reservations that actually
// became a real, paid booking: confirmed (coming), completed (showed up),
// or no-show (didn't). The sweeps below still run regardless -- they keep
// the underlying data (and slot availability) correct even though this
// page no longer displays their pending/cancelled output.
$sql = "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.number_of_guests,
               r.status, r.created_at, r.updated_at,
               ts.slot_label, ts.start_time, ts.end_time,
               u.first_name, u.last_name, u.phone, u.email
        FROM reservations r
        JOIN time_slots ts ON ts.slot_id = r.slot_id
        JOIN users u ON u.user_id = r.customer_id
        WHERE r.status IN ('confirmed', 'completed', 'no_show')";
$params = [];
if (!$showAllDates) {
    $sql .= " AND r.reservation_date = ?";
    $params[] = $dateFilter;
}
$sql .= " ORDER BY r.reservation_date DESC, ts.start_time ASC, r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reservationIds = array_column($reservations, 'reservation_id');

$payments = [];
$advanceItemsByReservation = [];

if ($reservationIds) {
    $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));

    $pStmt = $pdo->prepare(
        "SELECT payment_id, reservation_id, payment_purpose, deposit_percentage, amount_due, amount_paid,
                payment_method, payment_status, paid_at, paymongo_reference_number
         FROM reservation_payments
         WHERE reservation_id IN ({$placeholders})
         ORDER BY payment_id DESC"
    );
    $pStmt->execute($reservationIds);
    foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int)$row['reservation_id'];
        if (!isset($payments[$rid])) {
            $payments[$rid] = $row;
        }
    }

    $aStmt = $pdo->prepare(
        "SELECT rao.reservation_id, rao.quantity, rao.unit_price, rao.subtotal, mi.item_name
         FROM reservation_advance_orders rao
         JOIN menu_items mi ON mi.item_id = rao.menu_item_id
         WHERE rao.reservation_id IN ({$placeholders})
         ORDER BY rao.advance_order_id"
    );
    $aStmt->execute($reservationIds);
    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $advanceItemsByReservation[(int)$row['reservation_id']][] = $row;
    }
}

$rows = [];
// Unlike the customer-facing list, this doesn't reuse reservationBucket()'s
// combined "upcoming" grouping -- bucket here is just the raw status,
// scoped by the query above to the three staff actually track.
$bucketCounts = ['confirmed' => 0, 'completed' => 0, 'no_show' => 0];
foreach ($reservations as $r) {
    $rid = (int)$r['reservation_id'];
    $bucket = $r['status'];
    $bucketCounts[$bucket] = ($bucketCounts[$bucket] ?? 0) + 1;
    $rows[] = $r + [
        'reservation_id' => $rid,
        'bucket'         => $bucket,
        'payment'        => $payments[$rid] ?? null,
        'advance_items'  => $advanceItemsByReservation[$rid] ?? [],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservations | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
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

            <?php /* Three separate blocks: the filters card, then the status tabs,
                     then the reservation cards themselves sitting directly on the
                     canvas. The cards ARE cards -- wrapping them in an outer
                     .owner-card just boxed cards inside a card. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="resSearch" placeholder="Search reservation # or customer name&hellip;" value="<?= htmlspecialchars($prefillSearch) ?>" autocomplete="off">
                    </div>
                    <?php
                        // Which scope is live used to be spelled out in the card title
                        // ("All dates" / "Sep 8, 2026"). With the title gone, the active
                        // button carries it instead -- otherwise nothing on the page
                        // says the date filter is off, since the date input still shows
                        // a date either way.
                        $scopeIsAll   = $showAllDates;
                        $scopeIsToday = !$showAllDates && $dateFilter === date('Y-m-d');
                        $btn = fn(bool $on) => 'owner-btn owner-btn-sm ' . ($on ? 'owner-btn-primary' : 'owner-btn-secondary');
                    ?>
                    <form method="GET" class="owner-res-date-filter" style="margin-left:auto;">
                        <input type="date" name="date" class="owner-input" value="<?= htmlspecialchars($dateFilter) ?>" style="width:auto;">
                        <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm">Go</button>
                        <a href="reservations.php" class="<?= $btn($scopeIsToday) ?>">Today</a>
                        <a href="reservations.php?all_dates=1" class="<?= $btn($scopeIsAll) ?>">All dates</a>
                    </form>
                </div>
            </div>

            <nav class="owner-tabs" aria-label="Status">
                <button type="button" class="owner-tabs-link is-active" data-res-tab="all">All <span>(<?= count($rows) ?>)</span></button>
                <button type="button" class="owner-tabs-link" data-res-tab="confirmed">Confirmed <span>(<?= $bucketCounts['confirmed'] ?>)</span></button>
                <button type="button" class="owner-tabs-link" data-res-tab="completed">Completed <span>(<?= $bucketCounts['completed'] ?>)</span></button>
                <button type="button" class="owner-tabs-link" data-res-tab="no_show">No-show <span>(<?= $bucketCounts['no_show'] ?>)</span></button>
            </nav>

            <?php if (empty($rows)): ?>
                <div class="owner-table-empty">No reservations <?= $showAllDates ? 'recorded yet' : 'for this date' ?>.</div>
            <?php else: ?>
                <div class="owner-res-grid" id="resGrid">
                    <?php foreach ($rows as $r): $meta = reservationStatusMeta($r['status']); ?>
                        <button type="button" class="owner-res-card" data-res-bucket="<?= htmlspecialchars($r['bucket']) ?>"
                                data-res-search="<?= htmlspecialchars(mb_strtolower($r['reservation_number'] . ' ' . $r['first_name'] . ' ' . $r['last_name'])) ?>"
                                data-open-modal="resModal-<?= $r['reservation_id'] ?>">
                            <div class="owner-res-card-date">
                                <span class="day"><?= date('d', strtotime($r['reservation_date'])) ?></span>
                                <span class="mon"><?= date('M', strtotime($r['reservation_date'])) ?></span>
                            </div>
                            <div class="owner-res-card-main">
                                <div class="owner-res-card-top">
                                    <div>
                                        <div class="owner-res-card-number"><?= htmlspecialchars($r['reservation_number']) ?></div>
                                        <div class="owner-res-card-customer"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                    </div>
                                    <span class="owner-status-pill <?= $meta['class'] ?>"><?= htmlspecialchars($meta['label']) ?></span>
                                </div>
                                <div class="owner-res-card-meta">
                                    <span><i class="ph ph-clock" aria-hidden="true"></i> <?= htmlspecialchars($r['slot_label']) ?></span>
                                    <span><i class="ph ph-users" aria-hidden="true"></i> <?= (int)$r['number_of_guests'] ?></span>
                                    <?php if ($r['payment']): ?>
                                        <span><i class="ph ph-credit-card" aria-hidden="true"></i> <?= htmlspecialchars(ucfirst($r['payment']['payment_status'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="owner-table-empty" id="resEmptyFiltered" hidden>No reservations match this filter.</div>
            <?php endif; ?>

        </main>

    </div>

</div>

<?php foreach ($rows as $r):
    $meta = reservationStatusMeta($r['status']);
    $payment = $r['payment'];

    $advanceTotal = 0.0;
    foreach ($r['advance_items'] as $it) { $advanceTotal += (float)$it['subtotal']; }

    $remainingBalance = null;
    if ($payment && $payment['payment_purpose'] === 'advance_order_deposit' && $advanceTotal > 0) {
        $remainingBalance = max(0, $advanceTotal - (float)$payment['amount_paid']);
    }

    $paymentMeta = $payment ? reservationPaymentStatusMeta($payment['payment_status']) : null;
    $paymentMethodLabel = ($payment && $payment['payment_status'] === 'paid')
        ? reservationPaymentMethodLabel($payment['payment_method'])
        : null;

?>
<div class="owner-modal-backdrop owner-res-modal" id="resModal-<?= $r['reservation_id'] ?>">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="resModalTitle-<?= $r['reservation_id'] ?>">
        <div class="owner-modal-header">
            <div>
                <h2 class="owner-modal-title" id="resModalTitle-<?= $r['reservation_id'] ?>"><?= htmlspecialchars($r['reservation_number']) ?></h2>
                <span class="owner-status-pill <?= $meta['class'] ?>"><?= htmlspecialchars($meta['label']) ?></span>
                <span style="font-size:0.78rem; color:var(--op-ink-faint); margin-left:8px;"><?= htmlspecialchars(date('l, M j, Y', strtotime($r['reservation_date']))) ?></span>
            </div>
            <button type="button" class="owner-modal-close" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="owner-modal-body">

            <?php /* No summary strip: every figure it carried is already on this
                     screen -- guests and time in Reservation Details, payment state
                     in the Payment card, and the status pill in the modal header. */ ?>
            <div class="owner-res-card-grid">

                <?php /* Named label per row rather than an icon: an icon alone left the
                         reader to infer what a value meant, and several of these (date vs
                         duration vs guests) do not have an unambiguous glyph. Same
                         label/value row the Payment card below already uses. */ ?>
                <div class="owner-res-info-card">
                    <div class="owner-res-info-card-title">Customer</div>
                    <div class="owner-res-detail-row"><span>Name</span><span><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></span></div>
                    <div class="owner-res-detail-row"><span>Phone</span><span><?= htmlspecialchars(phoneOrNa($r['phone'])) ?></span></div>
                    <div class="owner-res-detail-row"><span>Email</span><span><?= htmlspecialchars($r['email']) ?></span></div>
                </div>

                <div class="owner-res-info-card">
                    <div class="owner-res-info-card-title">Reservation Details</div>
                    <div class="owner-res-detail-row"><span>Date</span><span><?= htmlspecialchars(date('l, F j, Y', strtotime($r['reservation_date']))) ?></span></div>
                    <div class="owner-res-detail-row"><span>Time</span><span><?= htmlspecialchars($r['slot_label']) ?></span></div>
                    <div class="owner-res-detail-row"><span>Duration</span><span><?= htmlspecialchars(formatSlotDuration($r['start_time'], $r['end_time'])) ?></span></div>
                    <div class="owner-res-detail-row"><span>Guests</span><span><?= (int)$r['number_of_guests'] ?> guest<?= (int)$r['number_of_guests'] === 1 ? '' : 's' ?></span></div>
                </div>

                <div class="owner-res-info-card">
                    <div class="owner-res-info-card-title">Payment</div>
                    <?php if ($payment): ?>
                        <div class="owner-res-detail-row">
                            <span><?= $payment['payment_purpose'] === 'reservation_fee' ? 'Reservation fee' : "Deposit ({$payment['deposit_percentage']}%)" ?></span>
                            <span>&#8369;<?= number_format((float)$payment['amount_due'], 2) ?></span>
                        </div>
                        <?php if ($advanceTotal > 0): ?>
                        <div class="owner-res-detail-row"><span>Advance order total</span><span>&#8369;<?= number_format($advanceTotal, 2) ?></span></div>
                        <?php endif; ?>
                        <div class="owner-res-detail-row"><span>Amount paid</span><span>&#8369;<?= number_format((float)$payment['amount_paid'], 2) ?></span></div>
                        <?php if ($remainingBalance !== null): ?>
                        <div class="owner-res-detail-row"><span>Remaining balance</span><span>&#8369;<?= number_format($remainingBalance, 2) ?></span></div>
                        <?php endif; ?>
                        <div class="owner-res-detail-row"><span>Payment status</span><span><span class="owner-status-pill <?= $paymentMeta['class'] ?>"><?= htmlspecialchars($paymentMeta['label']) ?></span></span></div>
                        <?php if ($paymentMethodLabel): ?>
                        <div class="owner-res-detail-row"><span>Payment method</span><span><?= htmlspecialchars($paymentMethodLabel) ?></span></div>
                        <?php endif; ?>
                        <?php if ($payment['paid_at']): ?>
                        <div class="owner-res-detail-row"><span>Paid on</span><span><?= htmlspecialchars(date('M j, Y g:i A', strtotime($payment['paid_at']))) ?></span></div>
                        <?php endif; ?>
                        <?php if ($payment['paymongo_reference_number']): ?>
                        <div class="owner-res-detail-row"><span>Transaction ID</span><span><?= htmlspecialchars($payment['paymongo_reference_number']) ?></span></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="owner-res-info-empty">No payment on record.</div>
                    <?php endif; ?>
                </div>

                <div class="owner-res-info-card">
                    <div class="owner-res-info-card-title">Advance Order</div>
                    <?php if (!empty($r['advance_items'])): ?>
                        <?php foreach ($r['advance_items'] as $it): ?>
                            <div class="owner-res-detail-row"><span><?= htmlspecialchars($it['item_name']) ?> &times; <?= (int)$it['quantity'] ?></span><span>&#8369;<?= number_format((float)$it['subtotal'], 2) ?></span></div>
                        <?php endforeach; ?>
                        <div class="owner-res-detail-row" style="border-top:1px dashed var(--op-border); margin-top:6px; padding-top:9px; font-weight:600;"><span>Subtotal</span><span>&#8369;<?= number_format($advanceTotal, 2) ?></span></div>
                    <?php else: ?>
                        <div class="owner-res-info-empty">No advance order was placed.</div>
                    <?php endif; ?>
                </div>

            </div>

        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    // -- Status tabs (client-side show/hide) --------------------------------
    const tabs  = document.querySelectorAll('[data-res-tab]');
    const cards = Array.from(document.querySelectorAll('.owner-res-card[data-res-bucket]'));
    const emptyFiltered = document.getElementById('resEmptyFiltered');
    const search = document.getElementById('resSearch');

    // No pagination: the tab + search filters already cut the list down, and
    // the grid scrolls, so every match renders at once. Cards hide via the
    // `hidden` attribute, which .owner-res-card[hidden]{display:none} has to
    // back up -- the element's own display:flex would otherwise beat it.
    function applyFilters() {
        const activeTab = document.querySelector('[data-res-tab].is-active')?.getAttribute('data-res-tab') || 'all';
        const q = (search?.value || '').trim().toLowerCase();

        let matched = 0;
        cards.forEach((card) => {
            const bucketMatch = activeTab === 'all' || card.getAttribute('data-res-bucket') === activeTab;
            const searchMatch = !q || card.getAttribute('data-res-search').includes(q);
            const show = bucketMatch && searchMatch;
            card.hidden = !show;
            if (show) matched++;
        });

        if (emptyFiltered) emptyFiltered.hidden = matched !== 0;
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            tabs.forEach((t) => t.classList.remove('is-active'));
            tab.classList.add('is-active');
            applyFilters();
        });
    });
    if (search) search.addEventListener('input', () => applyFilters());

    // -- Deep link from Analytics' status drill-down (e.g. "Cancelled
    // Reservations" -> reservations.php?status=cancelled) -- pre-activates
    // the matching status tab on load, same "URL param wins" convention as
    // report.php's ?shift_id= deep link.
    const requestedStatus = new URLSearchParams(window.location.search).get('status');
    if (requestedStatus) {
        const match = Array.from(tabs).find((t) => t.getAttribute('data-res-tab') === requestedStatus);
        if (match) {
            tabs.forEach((t) => t.classList.remove('is-active'));
            match.classList.add('is-active');
        }
    }

    applyFilters();

    // -- Detail modals --------------------------------------------------------
    function openResModal(modal) {
        modal.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closeResModal(modal) {
        modal.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    document.querySelectorAll('[data-open-modal]').forEach((card) => {
        card.addEventListener('click', () => {
            const modal = document.getElementById(card.getAttribute('data-open-modal'));
            if (modal) openResModal(modal);
        });
    });

    document.querySelectorAll('.owner-res-modal').forEach((modal) => {
        const closeBtn = modal.querySelector('.owner-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', () => closeResModal(modal));
        modal.addEventListener('click', (e) => { if (e.target === modal) closeResModal(modal); });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.owner-res-modal.is-open').forEach(closeResModal);
    });

})();
</script>

</body>
</html>
