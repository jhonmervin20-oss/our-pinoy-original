<?php
/**
 * reservation/print_reservation.php
 *
 * ?id=RS-2026-001 — a formal, print-optimized reservation confirmation
 * document (A4), distinct from the interactive detail modal in
 * reservations.php. Staff-only (Owner/Manager); not scoped to a customer
 * session since staff may need to print any reservation on file.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/display_helpers.php';
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

$pdo = Database::getInstance()->getConnection();

$reservationNumber = trim((string)($_GET['id'] ?? ''));

$restaurantName = $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
)->fetchColumn() ?: 'OPO! Our Pinoy Original';

$reservation = null;
if ($reservationNumber !== '') {
    $stmt = $pdo->prepare(
        "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.number_of_guests,
                r.status, r.payment_type, r.created_at,
                ts.slot_label, ts.start_time, ts.end_time,
                u.first_name, u.last_name, u.phone, u.email
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         JOIN users u ON u.user_id = r.customer_id
         WHERE r.reservation_number = ?"
    );
    $stmt->execute([$reservationNumber]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$reservation) {
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservation Not Found | <?= htmlspecialchars($restaurantName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/print-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/print-reservation.css') ?>">
</head>
<body>
<div class="pr-page" style="text-align:center;">
    <h1 class="pr-title">Reservation Not Found</h1>
    <p style="color:var(--pr-ink-soft);">
        <?php if ($reservationNumber === ''): ?>
            No reservation number was provided.
        <?php else: ?>
            No reservation matches &ldquo;<?= htmlspecialchars($reservationNumber) ?>&rdquo;.
        <?php endif; ?>
    </p>
    <p style="margin-top:16px;"><a href="reservations.php" class="pr-btn pr-btn-primary" style="display:inline-flex;">Back to Reservations</a></p>
</div>
</body>
</html>
    <?php
    exit;
}

$rid = (int)$reservation['reservation_id'];

$paymentStmt = $pdo->prepare(
    "SELECT payment_purpose, deposit_percentage, amount_due, amount_paid, payment_method, payment_status,
            paid_at, paymongo_reference_number
     FROM reservation_payments
     WHERE reservation_id = ?
     ORDER BY payment_id DESC LIMIT 1"
);
$paymentStmt->execute([$rid]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$itemsStmt = $pdo->prepare(
    "SELECT rao.quantity, rao.unit_price, rao.subtotal, mi.item_name
     FROM reservation_advance_orders rao
     JOIN menu_items mi ON mi.item_id = rao.menu_item_id
     WHERE rao.reservation_id = ?
     ORDER BY rao.advance_order_id"
);
$itemsStmt->execute([$rid]);
$advanceItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$advanceTotal = 0.0;
foreach ($advanceItems as $it) {
    $advanceTotal += (float)$it['subtotal'];
}

$remainingBalance = null;
if ($payment && $payment['payment_purpose'] === 'advance_order_deposit' && $advanceTotal > 0) {
    $remainingBalance = max(0, $advanceTotal - (float)$payment['amount_paid']);
}

$paymentMeta = $payment ? reservationPaymentStatusMeta($payment['payment_status']) : null;
$paymentMethodLabel = ($payment && $payment['payment_status'] === 'paid')
    ? reservationPaymentMethodLabel($payment['payment_method'])
    : null;

$meta = reservationStatusMeta($reservation['status']);
$reservationType = $reservation['payment_type'] === 'advance_order_deposit' ? 'Advance Order Reservation' : 'Standard Reservation';

$dbNow = getDbNow($pdo);
$settings = getReservationSettings($pdo);

// Policy prose generated from real, live settings values (system_settings,
// via getReservationSettings()) -- the numbers are never hardcoded, only
// the sentence templates are authored, since no stored policy text exists
// anywhere in this app. No cancellation/refund line: there's no setting or
// enforced business rule to source one from honestly (reservation_payments
// has no 'refunded' status and no refund workflow exists in this system).
$holdMins   = $settings['reservation_hold_minutes'];
$noShowHrs  = $settings['reservation_no_show_hours'];
$leadHrs    = $settings['reservation_min_lead_hours'];
$maxDays    = $settings['reservation_max_advance_days'];
$policyLines = [
    "Unpaid reservations are held for {$holdMins} minute" . ($holdMins == 1 ? '' : 's') . " before the table is released back to availability.",
    "A reservation may be marked as a no-show if the guest has not arrived within {$noShowHrs} hour" . ($noShowHrs == 1 ? '' : 's') . " of the reserved time.",
    "Online reservations require at least {$leadHrs} hour" . ($leadHrs == 1 ? '' : 's') . " of lead time and may be made up to {$maxDays} days in advance.",
];
if ($reservation['payment_type'] === 'advance_order_deposit') {
    $policyLines[] = "Advance food orders require a " . rtrim(rtrim(number_format($settings['advance_order_deposit_percentage'], 2), '0'), '.') . "% deposit at the time of booking; the remaining balance is payable at the restaurant.";
} else {
    $policyLines[] = "A flat reservation fee of \u{20b1}" . number_format($settings['reservation_fee_amount'], 2) . " applies when no advance food order is placed.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirmation <?= htmlspecialchars($reservation['reservation_number']) ?> | <?= htmlspecialchars($restaurantName) ?></title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600;700;900&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/print-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/print-reservation.css') ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body>

<div class="pr-page" id="prPage">

    <div class="pr-header-top">
        <img src="../assets/images/nav_logo.jpg" alt="" class="pr-logo">
        <div>
            <p class="pr-brand-name"><?= htmlspecialchars($restaurantName) ?></p>
            <div class="pr-brand-contact">
                <span><i class="ph ph-map-pin" aria-hidden="true"></i> 19 San Juan Rd, Calamba, 4027 Laguna, Philippines</span>
                <span><i class="ph ph-phone" aria-hidden="true"></i> +63 947 314 1042</span>
                <span><i class="ph ph-facebook-logo" aria-hidden="true"></i> facebook.com/ourpinoyoriginal</span>
            </div>
        </div>
    </div>

    <hr class="pr-divider">

    <h1 class="pr-title">Reservation Confirmation</h1>
    <div class="pr-meta-row">
        <span class="pr-res-number"><?= htmlspecialchars($reservation['reservation_number']) ?></span>
        <span class="pr-status-badge <?= $meta['class'] ?>"><?= htmlspecialchars($meta['label']) ?></span>
        <span class="pr-meta-faint"><?= htmlspecialchars(date('l, F j, Y', strtotime($reservation['reservation_date']))) ?></span>
        <span class="pr-meta-faint">Generated <?= htmlspecialchars(date('M j, Y g:i A', strtotime($dbNow['now']))) ?></span>
    </div>

    <div class="pr-section">
        <div class="pr-section-title">Customer Information</div>
        <div class="pr-card">
            <div class="pr-row"><span>Name</span><span><?= htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']) ?></span></div>
            <div class="pr-row"><span>Phone</span><span><?= htmlspecialchars(phoneOrNa($reservation['phone'])) ?></span></div>
            <div class="pr-row"><span>Email</span><span><?= htmlspecialchars($reservation['email']) ?></span></div>
        </div>
    </div>

    <div class="pr-section">
        <div class="pr-section-title">Reservation Details</div>
        <div class="pr-card">
            <div class="pr-row"><span>Date</span><span><?= htmlspecialchars(date('l, F j, Y', strtotime($reservation['reservation_date']))) ?></span></div>
            <div class="pr-row"><span>Time</span><span><?= htmlspecialchars($reservation['slot_label']) ?></span></div>
            <div class="pr-row"><span>Duration</span><span><?= htmlspecialchars(formatSlotDuration($reservation['start_time'], $reservation['end_time'])) ?></span></div>
            <div class="pr-row"><span>Guests</span><span><?= (int)$reservation['number_of_guests'] ?></span></div>
            <div class="pr-row"><span>Reservation Type</span><span><?= htmlspecialchars($reservationType) ?></span></div>
        </div>
    </div>

    <div class="pr-section">
        <div class="pr-section-title">Payment Details</div>
        <?php if ($payment): ?>
        <table class="pr-table pr-table-kv">
            <tbody>
                <tr>
                    <td><?= $payment['payment_purpose'] === 'reservation_fee' ? 'Reservation Fee' : "Deposit ({$payment['deposit_percentage']}%)" ?></td>
                    <td>&#8369;<?= number_format((float)$payment['amount_due'], 2) ?></td>
                </tr>
                <?php if ($advanceTotal > 0): ?>
                <tr><td>Advance Order Total</td><td>&#8369;<?= number_format($advanceTotal, 2) ?></td></tr>
                <?php endif; ?>
                <tr><td>Amount Paid</td><td>&#8369;<?= number_format((float)$payment['amount_paid'], 2) ?></td></tr>
                <?php if ($remainingBalance !== null): ?>
                <tr><td>Remaining Balance</td><td>&#8369;<?= number_format($remainingBalance, 2) ?></td></tr>
                <?php endif; ?>
                <tr><td>Payment Status</td><td><?= htmlspecialchars($paymentMeta['label']) ?></td></tr>
                <?php if ($paymentMethodLabel): ?>
                <tr><td>Payment Method</td><td><?= htmlspecialchars($paymentMethodLabel) ?></td></tr>
                <?php endif; ?>
                <?php if ($payment['paymongo_reference_number']): ?>
                <tr><td>Transaction ID</td><td><?= htmlspecialchars($payment['paymongo_reference_number']) ?></td></tr>
                <?php endif; ?>
                <?php if ($payment['paid_at']): ?>
                <tr><td>Payment Date</td><td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($payment['paid_at']))) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="pr-empty">No payment on record.</p>
        <?php endif; ?>
    </div>

    <div class="pr-section">
        <div class="pr-section-title">Advance Order</div>
        <?php if (!empty($advanceItems)): ?>
        <table class="pr-table">
            <thead>
                <tr><th>Item</th><th>Qty &times; Unit Price</th><th>Subtotal</th></tr>
            </thead>
            <tbody>
                <?php foreach ($advanceItems as $it): ?>
                <tr>
                    <td><?= htmlspecialchars($it['item_name']) ?></td>
                    <td><?= (int)$it['quantity'] ?> &times; &#8369;<?= number_format((float)$it['unit_price'], 2) ?></td>
                    <td>&#8369;<?= number_format((float)$it['subtotal'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="pr-total-row"><td colspan="2">Total</td><td>&#8369;<?= number_format($advanceTotal, 2) ?></td></tr>
                <?php if ($payment): ?>
                <tr><td colspan="2">Deposit Paid</td><td>&#8369;<?= number_format((float)$payment['amount_paid'], 2) ?></td></tr>
                <?php if ($remainingBalance !== null): ?>
                <tr><td colspan="2">Remaining Balance</td><td>&#8369;<?= number_format($remainingBalance, 2) ?></td></tr>
                <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="pr-empty">No advance order was placed.</p>
        <?php endif; ?>
    </div>

    <div class="pr-section">
        <div class="pr-section-title">Reservation Policy</div>
        <ul class="pr-policy-list">
            <?php foreach ($policyLines as $line): ?>
                <li><?= htmlspecialchars($line) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="pr-qr-section">
        <canvas id="reservationQr"></canvas>
        <p class="pr-qr-caption">Please present this QR code upon arrival.</p>
    </div>

    <div class="pr-footer">
        <p class="pr-footer-thanks">Thank you for choosing <?= htmlspecialchars($restaurantName) ?></p>
        <div class="pr-footer-contact">
            19 San Juan Rd, Calamba, 4027 Laguna, Philippines &middot;
            +63 947 314 1042 &middot;
            facebook.com/ourpinoyoriginal
        </div>
        <div class="pr-copyright">&copy; <?= date('Y') ?> OPO &ndash; Our Pinoy Original. All rights reserved.</div>
    </div>

</div>

<div class="pr-actions">
    <button type="button" class="pr-btn pr-btn-primary" id="prPrintBtn"><i class="ph ph-printer" aria-hidden="true"></i> Print Reservation</button>
    <button type="button" class="pr-btn" id="prPdfBtn"><i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF</button>
    <a href="reservations.php" class="pr-btn"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back to Reservations</a>
</div>

<script>
(function () {
    new QRious({
        element: document.getElementById('reservationQr'),
        value: <?= json_encode($reservation['reservation_number']) ?>,
        size: 160,
        foreground: '#241f1a',
        background: '#ffffff',
    });

    document.getElementById('prPrintBtn').addEventListener('click', () => window.print());

    document.getElementById('prPdfBtn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="ph ph-spinner" aria-hidden="true"></i> Preparing&hellip;';

        try {
            const page = document.getElementById('prPage');
            // scale:1.5 + JPEG (not PNG) keeps the file a few hundred KB
            // instead of tens of MB -- PNG's lossless compression does very
            // poorly on the re-rasterized photo logo, and this is a document
            // meant to be emailed/filed, not a pixel-perfect archival image.
            const canvas = await html2canvas(page, { backgroundColor: '#ffffff', scale: 1.5 });

            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');
            const pageWidth = pdf.internal.pageSize.getWidth();
            const pageHeight = pdf.internal.pageSize.getHeight();
            const imgWidth = pageWidth;
            const imgHeight = (canvas.height * imgWidth) / canvas.width;

            let heightLeft = imgHeight;
            let position = 0;
            const imgData = canvas.toDataURL('image/jpeg', 0.85);

            pdf.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
            heightLeft -= pageHeight;

            while (heightLeft > 0) {
                position = heightLeft - imgHeight;
                pdf.addPage();
                pdf.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
            }

            pdf.save(<?= json_encode($reservation['reservation_number']) ?> + '-confirmation.pdf');
        } catch (err) {
            alert('Could not generate the PDF. Please try again.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    });
})();
</script>

</body>
</html>
