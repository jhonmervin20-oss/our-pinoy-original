<?php
/**
 * customer/api/reservation_resume_payment.php
 *
 * AJAX POST — "Continue Payment" from the reservation details page. The
 * original PayMongo Checkout Session may already be expired/stale (they
 * time out well before the reservation's own hold window in some cases,
 * or the customer simply closed the tab), so this creates a FRESH session
 * for the same still-pending reservation/payment row rather than trying to
 * reuse the old checkout_url — mirrors reservation_checkout.php's session
 * creation exactly via the shared createReservationCheckoutSession()
 * helper so both flows build identical PayMongo requests.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/paymongo.php';
require_once __DIR__ . '/../includes/reservation_functions.php';

Session::start();

header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['customer'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in to continue.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

if (!verify_csrf_token()) {
    http_response_code(419);
    echo json_encode(['error' => 'Your session expired. Please refresh the page and try again.']);
    exit;
}

$customerId = Session::getUserId();
$reservationNumber = trim((string)($_POST['reservation_number'] ?? ''));

if ($reservationNumber === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Missing reservation.']);
    exit;
}

$pdo = Database::getInstance()->getConnection();

$settings = getReservationSettings($pdo);
sweepExpiredReservationHolds($pdo, $settings['reservation_hold_minutes']);

$stmt = $pdo->prepare(
    "SELECT r.reservation_id, r.reservation_date, r.slot_id, r.number_of_guests,
            ts.slot_label,
            rp.payment_id, rp.payment_purpose, rp.deposit_percentage, rp.amount_due
     FROM reservations r
     JOIN time_slots ts ON ts.slot_id = r.slot_id
     JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
     WHERE r.reservation_number = ? AND r.customer_id = ? AND r.status = 'pending' AND rp.payment_status = 'pending'
     ORDER BY rp.payment_id DESC LIMIT 1"
);
$stmt->execute([$reservationNumber, $customerId]);
$hold = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hold) {
    http_response_code(410);
    echo json_encode(['error' => 'This reservation hold has expired or was already resolved. Please make a new reservation.']);
    exit;
}

$paymongo = getPaymongoClient($pdo);
if ($paymongo === null) {
    http_response_code(503);
    echo json_encode(['error' => "Online payment isn't set up yet. Please contact the restaurant to complete your reservation."]);
    exit;
}

$userStmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ?');
$userStmt->execute([$customerId]);
$customerEmail = $userStmt->fetchColumn() ?: null;

$advanceOrderTotal = 0.0;
if ($hold['payment_purpose'] === 'advance_order_deposit') {
    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(subtotal), 0) FROM reservation_advance_orders WHERE reservation_id = ?');
    $sumStmt->execute([$hold['reservation_id']]);
    $advanceOrderTotal = (float)$sumStmt->fetchColumn();
}

try {
    $session = createReservationCheckoutSession(
        $paymongo,
        $reservationNumber,
        (int)$hold['reservation_id'],
        (int)$hold['payment_id'],
        $hold['payment_purpose'],
        $hold['deposit_percentage'],
        (float)$hold['amount_due'],
        (int)$hold['number_of_guests'],
        $hold['reservation_date'],
        $hold['slot_label'],
        $advanceOrderTotal,
        $customerEmail
    );

    if (empty($session['checkout_url'])) {
        throw new RuntimeException('PayMongo did not return a checkout URL.');
    }

    $pdo->prepare('UPDATE reservation_payments SET paymongo_reference_number = ? WHERE payment_id = ?')
        ->execute([$session['id'], $hold['payment_id']]);

    echo json_encode(['checkout_url' => $session['checkout_url']]);
} catch (Throwable $e) {
    error_log('reservation_resume_payment.php PayMongo call failed: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => "Couldn't reach the payment provider. Please try again."]);
}
