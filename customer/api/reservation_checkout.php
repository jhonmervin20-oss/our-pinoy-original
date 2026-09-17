<?php
/**
 * customer/api/reservation_checkout.php
 *
 * AJAX POST — the transactional core of the booking wizard. Re-validates
 * everything server-side (never trusts client-computed availability or
 * advance-order pricing), locks capacity via slot_availability row
 * locking, creates the 'pending' hold (reservations + reservation_payments
 * + reservation_advance_orders, all in one transaction), then — outside
 * that transaction, since it's a network call — creates the PayMongo
 * Checkout Session and returns its checkout_url for the browser to
 * redirect to. Sent as application/x-www-form-urlencoded (not JSON) so
 * config/csrf.php's $_POST-based verify_csrf_token() works unchanged.
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
    echo json_encode(['error' => 'Please log in to make a reservation.']);
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

$reservationDate = trim((string)($_POST['reservation_date'] ?? ''));
$slotId          = trim((string)($_POST['slot_id'] ?? ''));
$numberOfGuests  = trim((string)($_POST['number_of_guests'] ?? ''));
$advanceItemsRaw = (string)($_POST['advance_order_items'] ?? '[]');

$errors = [];

if ($reservationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservationDate)) {
    $errors[] = 'Please select a valid reservation date.';
}
if ($slotId === '' || !ctype_digit($slotId)) {
    $errors[] = 'Please select a valid time slot.';
}
if ($numberOfGuests === '' || !ctype_digit($numberOfGuests) || (int)$numberOfGuests < 1) {
    $errors[] = 'Please enter a valid guest count.';
}
$advanceItems = json_decode($advanceItemsRaw, true);
if (!is_array($advanceItems)) {
    $advanceItems = [];
}
$cleanItems = [];
foreach ($advanceItems as $line) {
    $itemId   = trim((string)($line['item_id'] ?? ''));
    $quantity = trim((string)($line['quantity'] ?? ''));
    if ($itemId === '' || !ctype_digit($itemId)) continue;
    if ($quantity === '' || !ctype_digit($quantity) || (int)$quantity < 1) continue;
    $cleanItems[] = ['item_id' => (int)$itemId, 'quantity' => (int)$quantity];
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors)]);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$guests = (int)$numberOfGuests;

// Re-price every advance-order line from the DB — never trust client-sent
// prices. Items that are inactive/unavailable are rejected outright, not
// silently dropped.
$cartLines = [];
if (!empty($cleanItems)) {
    $itemIds = array_column($cleanItems, 'item_id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $itemStmt = $pdo->prepare("SELECT item_id, item_name, selling_price FROM menu_items WHERE item_id IN ({$placeholders}) AND is_active = 1 AND is_available = 1");
    $itemStmt->execute($itemIds);
    $validItems = [];
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $validItems[(int)$row['item_id']] = $row;
    }
    foreach ($cleanItems as $line) {
        if (!isset($validItems[$line['item_id']])) {
            http_response_code(422);
            echo json_encode(['error' => 'One of the items in your order is no longer available. Please review your order.']);
            exit;
        }
        $item = $validItems[$line['item_id']];
        $cartLines[] = [
            'item_id'    => $line['item_id'],
            'item_name'  => $item['item_name'],
            'quantity'   => $line['quantity'],
            'unit_price' => (float)$item['selling_price'],
            'subtotal'   => round((float)$item['selling_price'] * $line['quantity'], 2),
        ];
    }
}

try {
    $hold = createReservationHoldCore($pdo, $customerId, $reservationDate, (int)$slotId, $guests, $cartLines);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('reservation_checkout.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong creating your reservation. Please try again.']);
    exit;
}

if (isset($hold['error'])) {
    http_response_code(422);
    echo json_encode(['error' => $hold['error']]);
    exit;
}

$reservationId = $hold['reservation_id'];
$reservationNumber = $hold['reservation_number'];
$paymentId = $hold['payment_id'];
$paymentDue = $hold['payment_due'];
$advanceOrderTotal = $hold['advance_order_total'];

// -- Everything below is outside the DB transaction (network call) --------

$paymongo = getPaymongoClient($pdo);

if ($paymongo === null) {
    // Leave the reservation 'pending' rather than cancelling it -- matches
    // reservation_resume_payment.php's own handling of this exact case.
    // The hold's own expiry sweep (sweepExpiredReservationHolds()) is what
    // eventually cancels it if payment is never completed, not a one-shot
    // failure here.
    http_response_code(503);
    echo json_encode(['error' => 'Online payment isn\'t set up yet. Please contact the restaurant to complete your reservation.']);
    exit;
}

$userStmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ?');
$userStmt->execute([$customerId]);
$customerEmail = $userStmt->fetchColumn() ?: null;

try {
    $session = createReservationCheckoutSession(
        $paymongo,
        $reservationNumber,
        $reservationId,
        $paymentId,
        $paymentDue['purpose'],
        $paymentDue['deposit_percentage'],
        $paymentDue['amount_due'],
        $guests,
        $reservationDate,
        $hold['slot_label'],
        $advanceOrderTotal,
        $customerEmail
    );

    if (empty($session['checkout_url'])) {
        throw new RuntimeException('PayMongo did not return a checkout URL.');
    }

    $pdo->prepare('UPDATE reservation_payments SET paymongo_reference_number = ? WHERE payment_id = ?')
        ->execute([$session['id'], $paymentId]);

    echo json_encode(['checkout_url' => $session['checkout_url']]);
} catch (Throwable $e) {
    error_log('reservation_checkout.php PayMongo call failed: ' . $e->getMessage());
    // Leave the reservation 'pending' rather than cancelling it on the
    // first hiccup -- a transient PayMongo/network failure here shouldn't
    // destroy a reservation the customer just made. Matches
    // reservation_resume_payment.php's identical catch block, which never
    // released the hold either; the customer can retry via "Continue
    // Payment" on My Reservations, and the hold's own expiry sweep is what
    // eventually cancels it if payment never completes.
    http_response_code(502);
    echo json_encode(['error' => "Couldn't reach the payment provider. Your reservation is saved -- go to My Reservations and tap \"Continue Payment\" to try again."]);
}
