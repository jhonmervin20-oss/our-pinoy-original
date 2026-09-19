<?php
/**
 * customer/reservation_details.php
 *
 * Full detail view for a single reservation, reached by tapping a card on
 * reservations.php. Deliberately does NOT include includes/navbar.php —
 * just a minimal back-button header, per this page's own spec. Offers
 * Cancel / Continue Payment actions only while the reservation is still an
 * unpaid hold ('pending' status + 'pending' payment_status); once
 * confirmed/paid there's nothing left for the customer to act on here.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/reservation_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['customer'])) {
    header('Location: ../auth/login.php');
    exit;
}

$customerId = Session::getUserId();
$reservationNumber = trim((string)($_GET['reservation_number'] ?? ''));

if ($reservationNumber === '') {
    header('Location: reservations.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

$noShowHours = getReservationSettings($pdo)['reservation_no_show_hours'];
sweepNoShowReservations($pdo, $noShowHours);

$stmt = $pdo->prepare(
    "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.number_of_guests,
            r.status,
            ts.slot_label, ts.start_time, ts.end_time,
            rp.payment_id, rp.payment_purpose, rp.deposit_percentage, rp.amount_due, rp.amount_paid,
            rp.payment_status, rp.paid_at, rp.paymongo_reference_number
     FROM reservations r
     JOIN time_slots ts ON ts.slot_id = r.slot_id
     LEFT JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
     WHERE r.reservation_number = ? AND r.customer_id = ?
     ORDER BY rp.payment_id DESC LIMIT 1"
);
$stmt->execute([$reservationNumber, $customerId]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    http_response_code(404);
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not Found</title></head>
    <body style="font-family:sans-serif;text-align:center;padding:60px;">
        <h1>Reservation not found</h1>
        <p><a href="reservations.php">Back to My Reservations</a></p>
    </body></html>
    <?php
    exit;
}

$advanceItems = getReservationAdvanceOrderItems($pdo, (int)$reservation['reservation_id']);
$meta = reservationStatusMeta($reservation['status']);
$isPendingPayment = $reservation['status'] === 'pending' && $reservation['payment_status'] === 'pending';

// "Back" returns to wherever the customer actually came from -- this page
// is now reached from several places (reservations.php, dashboard.php's
// hero/recent-activity/calendar, a notification click-through), so a
// hardcoded reservations.php link would be wrong most of the time. Same
// same-app-only referer check api/notifications_mark_all_read.php already
// uses; excludes this page's own URL so a reload can't turn "back" into a
// no-op loop.
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$backUrl = 'reservations.php';
if (strpos($referer, '/customer/') !== false && strpos($referer, 'reservation_details.php') === false) {
    $backUrl = $referer;
}

// A QR/downloadable ticket only makes sense once the table is actually
// held for the customer — not for a still-unpaid hold, or one that's
// cancelled/no-show.
$showTicket = in_array($reservation['status'], ['confirmed', 'seated', 'completed'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($reservation['reservation_number']) ?> | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">
<link rel="stylesheet" href="assets/css/reservations.css?v=<?= filemtime(__DIR__ . '/assets/css/reservations.css') ?>">
<link rel="stylesheet" href="assets/css/reservation-details.css?v=<?= filemtime(__DIR__ . '/assets/css/reservation-details.css') ?>">
</head>
<body class="ca-body ca-plain">

<header class="ca-subpage-header">
    <div class="ca-subpage-header-inner">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="ca-subpage-back" aria-label="Go back">
            <i class="ph ph-arrow-left" aria-hidden="true"></i>
        </a>
        <div class="ca-subpage-heading">
            <h1 class="ca-subpage-title">Reservation Details</h1>
            <span class="ca-subpage-sub"><?= htmlspecialchars($reservation['reservation_number']) ?></span>
        </div>
    </div>
</header>

<main class="ca-detail-content">
    <?= ca_flash_render() ?>

    <span class="ca-res-badge <?= $meta['class'] ?>"><?= htmlspecialchars($meta['label']) ?></span>
    <div class="ca-detail-number"><?= htmlspecialchars($reservation['reservation_number']) ?></div>

    <?php if ($showTicket): ?>
        <div class="ca-eticket" id="eTicketCard" style="margin-bottom:20px;">
            <div class="ca-eticket-qr-section">
                <canvas id="reservationQr"></canvas>
                <p class="ca-eticket-caption">Please show or scan this QR code when you arrive at the restaurant.</p>
            </div>
            <div class="ca-eticket-perforation"></div>
            <div class="ca-eticket-details">
                <div>
                    <div class="ca-eticket-field-label">Name</div>
                    <div class="ca-eticket-field-value"><?= htmlspecialchars(Session::getFullName() ?? '') ?></div>
                </div>
                <div>
                    <div class="ca-eticket-field-label">Reservation No.</div>
                    <div class="ca-eticket-field-value"><?= htmlspecialchars($reservation['reservation_number']) ?></div>
                </div>
                <div>
                    <div class="ca-eticket-field-label">Date</div>
                    <div class="ca-eticket-field-value"><?= htmlspecialchars(date('M j, Y', strtotime($reservation['reservation_date']))) ?></div>
                </div>
                <div>
                    <div class="ca-eticket-field-label">Time</div>
                    <div class="ca-eticket-field-value"><?= htmlspecialchars(date('g:i A', strtotime($reservation['start_time']))) ?></div>
                </div>
                <div>
                    <div class="ca-eticket-field-label">Guests</div>
                    <div class="ca-eticket-field-value"><?= (int)$reservation['number_of_guests'] ?></div>
                </div>
                <div>
                    <div class="ca-eticket-field-label">Duration</div>
                    <div class="ca-eticket-field-value"><?= htmlspecialchars(formatSlotDuration($reservation['start_time'], $reservation['end_time'])) ?></div>
                </div>
            </div>
        </div>

        <div class="ca-success-actions" style="margin-bottom:20px;">
            <button type="button" class="ca-btn ca-btn-secondary" data-download-ticket data-filename="<?= htmlspecialchars($reservation['reservation_number']) ?>-ticket.png">
                <i class="ph ph-download" aria-hidden="true"></i> Download Ticket
            </button>
        </div>
    <?php else: ?>
        <div class="ca-receipt" style="margin-bottom:20px;">
            <div class="ca-receipt-section">
                <div class="ca-receipt-section-title">Reservation Details</div>
                <div class="ca-receipt-row"><span>Date</span><span><?= htmlspecialchars(date('l, F j, Y', strtotime($reservation['reservation_date']))) ?></span></div>
                <div class="ca-receipt-row"><span>Time</span><span><?= htmlspecialchars($reservation['slot_label']) ?></span></div>
                <div class="ca-receipt-row"><span>Guests</span><span><?= (int)$reservation['number_of_guests'] ?></span></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="ca-receipt">
        <?php if (!empty($advanceItems)): ?>
        <div class="ca-receipt-section">
            <div class="ca-receipt-section-title">Advance Order</div>
            <?php foreach ($advanceItems as $it): ?>
                <div class="ca-receipt-row"><span><?= htmlspecialchars($it['item_name']) ?> &times; <?= (int)$it['quantity'] ?></span><span>&#8369;<?= number_format((float)$it['subtotal'], 2) ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($reservation['payment_id']): ?>
        <div class="ca-receipt-section">
            <div class="ca-receipt-section-title">Payment</div>
            <div class="ca-receipt-row">
                <span><?= $reservation['payment_purpose'] === 'reservation_fee' ? 'Reservation fee' : "Deposit ({$reservation['deposit_percentage']}%)" ?></span>
                <span>&#8369;<?= number_format((float)$reservation['amount_due'], 2) ?></span>
            </div>
            <div class="ca-receipt-row"><span>Amount paid</span><span>&#8369;<?= number_format((float)$reservation['amount_paid'], 2) ?></span></div>
            <div class="ca-receipt-row"><span>Payment status</span><span><?= htmlspecialchars(ucfirst($reservation['payment_status'])) ?></span></div>
            <?php if ($reservation['paid_at']): ?>
                <div class="ca-receipt-row"><span>Paid on</span><span><?= htmlspecialchars(date('M j, Y g:i A', strtotime($reservation['paid_at']))) ?></span></div>
            <?php endif; ?>
            <?php if ($reservation['paymongo_reference_number']): ?>
                <div class="ca-receipt-row"><span>Transaction ID</span><span class="ca-receipt-breakable"><?= htmlspecialchars($reservation['paymongo_reference_number']) ?></span></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isPendingPayment): ?>
        <div class="ca-detail-actions">
            <form method="POST" action="api/reservation_cancel.php" data-confirm="Cancel this reservation? Your table hold will be released.">
                <?= csrf_field() ?>
                <input type="hidden" name="reservation_number" value="<?= htmlspecialchars($reservation['reservation_number']) ?>">
                <button type="submit" class="ca-btn ca-btn-secondary ca-btn-block">
                    <i class="ph ph-x-circle" aria-hidden="true"></i> Cancel Reservation
                </button>
            </form>
            <button type="button" class="ca-btn ca-btn-primary ca-btn-block" id="continuePaymentBtn">
                <i class="ph ph-credit-card" aria-hidden="true"></i> Continue Payment
            </button>
        </div>
    <?php endif; ?>

    <?php if ($reservation['status'] === 'completed'): ?>
        <?php // Links to the general feedback form, same as every other entry
              // point into it -- feedback stays a testimonial, not a per-visit
              // review, so this is a prompt to leave one, not a pre-filled
              // report on this specific reservation (feedback has no
              // reservation_id column to attribute it with). ?>
        <div class="ca-detail-actions">
            <a href="feedback.php" class="ca-btn ca-btn-primary ca-btn-block">
                <i class="ph ph-chat-circle-text" aria-hidden="true"></i> Leave Feedback
            </a>
        </div>
    <?php endif; ?>
</main>

<div class="ca-loading-overlay" id="loadingOverlay">
    <div class="ca-spinner"></div>
    <div class="ca-loading-text">Redirecting to secure payment&hellip;</div>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
window.RESERVATION_NUMBER = <?= json_encode($reservation['reservation_number']) ?>;
</script>
<script src="assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/confirm-modal.js') ?>"></script>
<script src="assets/js/reservation-details.js?v=<?= filemtime(__DIR__ . '/assets/js/reservation-details.js') ?>"></script>

<?php if ($showTicket): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
new QRious({
    element: document.getElementById('reservationQr'),
    value: <?= json_encode($reservation['reservation_number']) ?>,
    size: 200,
    foreground: '#241f1a',
    background: '#ffffff',
});
</script>
<script src="assets/js/eticket-download.js?v=<?= filemtime(__DIR__ . '/assets/js/eticket-download.js') ?>"></script>
<?php endif; ?>

</body>
</html>
