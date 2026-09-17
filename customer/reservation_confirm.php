<?php
/**
 * customer/reservation_confirm.php
 *
 * PayMongo's success_url target. Since this dev environment runs on
 * localhost, PayMongo cannot deliver a webhook here — this page
 * synchronously calls Retrieve Checkout Session when the customer's
 * browser lands back on it, which is PayMongo's own documented fallback
 * for missed webhooks, not a workaround. Looked up by reservation_number
 * + session ownership (never a bare numeric id) — closes the "can a
 * customer view someone else's confirmation" concern using the same
 * session-ownership model every other page in this app already relies on.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/paymongo.php';
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

// Same reasoning as make_reservation.php -- this is the tail end of the
// booking wizard, not the "My Reservations" list page, so it shouldn't
// highlight the Reservations tab either.
$activePage = 'make_reservation';
$customerId = Session::getUserId();

$reservationNumber = trim((string)($_GET['reservation_number'] ?? ''));
if ($reservationNumber === '') {
    header('Location: reservations.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare(
    "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.slot_id, r.number_of_guests,
            r.status AS reservation_status,
            ts.slot_label, ts.start_time, ts.end_time,
            rp.payment_id, rp.payment_purpose, rp.deposit_percentage, rp.amount_due, rp.amount_paid,
            rp.payment_status, rp.paymongo_reference_number
     FROM reservations r
     JOIN time_slots ts ON ts.slot_id = r.slot_id
     JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
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
        <p><a href="reservations.php">Back to Reservations</a></p>
    </body></html>
    <?php
    exit;
}

$needsManualReview = false;

// If not already paid, verify synchronously against PayMongo.
if ($reservation['payment_status'] === 'pending') {
    $paymongo = getPaymongoClient($pdo);

    if ($paymongo !== null && $reservation['paymongo_reference_number']) {
        try {
            $session = $paymongo->retrieveCheckoutSession($reservation['paymongo_reference_number']);
            $isPaid = ($session['payment_intent_status'] ?? null) === 'succeeded' || ($session['has_paid_payment'] ?? false);

            if ($isPaid) {
                // Reservation checkout only ever offers GCash (see
                // createReservationCheckoutSession()), so any successful
                // payment on this session is a GCash payment regardless of
                // the source type PayMongo reports back.
                $paymongoMethod = 'paymongo_gcash';

                $result = finalizeReservationPayment($pdo, (int)$reservation['payment_id'], $session['payment_intent_id'], $paymongoMethod);

                if (($result['reservation_status'] ?? null) === 'cancelled') {
                    // Reclaimed-but-actually-paid edge case: the hold expired
                    // right as payment posted. Flag for manual follow-up
                    // rather than silently showing a normal success page.
                    $needsManualReview = true;
                    try {
                        $pdo->prepare(
                            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                             VALUES (?, 'Reservations', 'payment_needs_manual_review', ?, ?)"
                        )->execute([
                            $customerId,
                            "{$reservationNumber}: payment succeeded after hold expired — needs manual reseat/refund review",
                            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                        ]);
                    } catch (PDOException $e) {
                        // best-effort
                    }
                } else {
                    $reservation['reservation_status'] = $result['reservation_status'] ?? $reservation['reservation_status'];
                    $reservation['payment_status']     = $result['payment_status'] ?? $reservation['payment_status'];
                    $reservation['amount_paid']         = $result['amount_paid'] ?? $reservation['amount_paid'];
                }
            } else {
                // Payment wasn't completed — release the hold.
                releaseReservationHold(
                    $pdo,
                    (int)$reservation['reservation_id'],
                    (int)$reservation['payment_id'],
                    $reservation['reservation_date'],
                    (string)$reservation['slot_id'],
                    (int)$reservation['number_of_guests']
                );

                $reservation['reservation_status'] = 'cancelled';
                $reservation['payment_status']      = 'failed';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('reservation_confirm.php PayMongo verify failed: ' . $e->getMessage());
        }
    }
}

$paid      = $reservation['payment_status'] === 'paid';
$failed    = in_array($reservation['payment_status'], ['failed', 'unpaid'], true) && !$paid;
$pageTitle = $paid ? 'Reservation Confirmed' : 'Payment Status';

$advanceItems = $paid ? getReservationAdvanceOrderItems($pdo, (int)$reservation['reservation_id']) : [];
// The advance order's own total, not just the deposit -- amount_paid is
// only the deposit_percentage slice of it (see computeReservationPaymentDue()
// in reservation_functions.php), so what's actually still owed is the rest
// of that order total, same subtraction make-reservation.js's own
// computePaymentDue() already previews before checkout.
$advanceOrderTotal = array_sum(array_column($advanceItems, 'subtotal'));
$remainingBalance   = round($advanceOrderTotal - (float)$reservation['amount_paid'], 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">
</head>
<body class="ca-body ca-standalone">

<!-- No navbar.php here -- same reasoning as make_reservation.php: this is
     a focused receipt/status screen, not a place for the full nav (pill
     links, CTA, notifications) to compete for attention. -->
<header class="ca-topbar">
    <div class="ca-topbar-inner">
        <a href="dashboard.php" class="ca-icon-btn" aria-label="Back to Home">
            <i class="ph ph-arrow-left" aria-hidden="true"></i>
        </a>
        <div class="ca-wizard-topbar-info">
            <span class="ca-wizard-topbar-title"><?= htmlspecialchars($pageTitle) ?></span>
            <span class="ca-wizard-topbar-sub"><?= htmlspecialchars($reservation['reservation_number']) ?></span>
        </div>
        <a href="dashboard.php" class="ca-logo" aria-label="Our Pinoy Original — Home">
            <img src="../assets/images/logo.jpg" alt="OPO! Our Pinoy Original">
        </a>
    </div>
</header>

<main class="ca-content">
    <div class="ca-wizard" style="max-width:560px;">

        <?php if ($paid): ?>
            <div class="ca-success-hero">
                <div class="ca-success-icon"><i class="ph ph-check" aria-hidden="true"></i></div>
                <h1 class="ca-success-title">Reservation Confirmed!</h1>
                <p class="ca-success-sub">A table is waiting for you.</p>
            </div>

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

            <div class="ca-receipt" id="printableReceipt">
                <?php if (!empty($advanceItems)): ?>
                <div class="ca-receipt-section">
                    <div class="ca-receipt-section-title">Advance Order</div>
                    <?php foreach ($advanceItems as $it): ?>
                        <div class="ca-receipt-row"><span><?= htmlspecialchars($it['item_name']) ?> &times; <?= (int)$it['quantity'] ?></span><span>&#8369;<?= number_format((float)$it['subtotal'], 2) ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="ca-receipt-section">
                    <div class="ca-receipt-section-title">Payment</div>
                    <div class="ca-receipt-row"><span><?= $reservation['payment_purpose'] === 'reservation_fee' ? 'Reservation fee' : "Deposit ({$reservation['deposit_percentage']}%)" ?></span><span>&#8369;<?= number_format((float)$reservation['amount_paid'], 2) ?></span></div>
                    <div class="ca-receipt-row"><span>Payment status</span><span>Paid</span></div>
                    <?php if ($reservation['payment_purpose'] !== 'reservation_fee' && $remainingBalance > 0): ?>
                        <div class="ca-receipt-row"><span>Remaining balance</span><span>&#8369;<?= number_format($remainingBalance, 2) ?> at restaurant</span></div>
                    <?php endif; ?>
                    <?php if ($reservation['paymongo_reference_number']): ?>
                        <div class="ca-receipt-row"><span>Transaction ID</span><span class="ca-receipt-breakable"><?= htmlspecialchars($reservation['paymongo_reference_number']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ca-success-actions">
                <button type="button" class="ca-btn ca-btn-secondary" data-download-ticket data-filename="<?= htmlspecialchars($reservation['reservation_number']) ?>-ticket.png">
                    <i class="ph ph-download" aria-hidden="true"></i> Download Ticket
                </button>
                <a href="reservations.php" class="ca-btn ca-btn-secondary"><i class="ph ph-calendar-check" aria-hidden="true"></i> View Reservation</a>
                <a href="dashboard.php" class="ca-btn ca-btn-primary"><i class="ph ph-house" aria-hidden="true"></i> Return Home</a>
            </div>

        <?php elseif ($needsManualReview): ?>
            <div class="ca-success-hero">
                <div class="ca-success-icon" style="background:var(--ca-warning-soft);color:var(--ca-warning);"><i class="ph ph-clock-countdown" aria-hidden="true"></i></div>
                <h1 class="ca-success-title">We received your payment</h1>
                <p class="ca-success-sub">Your table hold expired right as your payment came through. Our team has been notified and will confirm your reservation shortly — no need to pay again.</p>
                <div class="ca-success-number"><?= htmlspecialchars($reservation['reservation_number']) ?></div>
            </div>
            <div class="ca-success-actions">
                <a href="dashboard.php" class="ca-btn ca-btn-primary">Return Home</a>
            </div>

        <?php else: ?>
            <div class="ca-success-hero">
                <div class="ca-success-icon" style="background:var(--ca-danger-soft);color:var(--ca-danger);"><i class="ph ph-x-circle" aria-hidden="true"></i></div>
                <h1 class="ca-success-title">Payment Failed</h1>
                <p class="ca-success-sub">Please try again.</p>
            </div>
            <div class="ca-success-actions">
                <a href="make_reservation.php" class="ca-btn ca-btn-primary">Try Again</a>
            </div>
        <?php endif; ?>

    </div>
</main>

<?php if ($paid): ?>
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
