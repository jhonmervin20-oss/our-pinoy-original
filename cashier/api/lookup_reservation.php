<?php
/**
 * cashier/api/lookup_reservation.php
 *
 * POST reservation_number + csrf_token -> JSON.
 * A "not found" reservation number is a normal, expected outcome (cashiers
 * mistype constantly) — it's reported as {found:false}, not an HTTP error.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../includes/pos_functions.php';
require_once __DIR__ . '/../../customer/includes/reservation_functions.php';

header('Content-Type: application/json');

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['cashier', 'owner'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Your session has expired. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token()) {
    http_response_code(400);
    echo json_encode(['error' => 'Your session expired. Please refresh the page and try again.']);
    exit;
}

$reservationNumber = trim((string)($_POST['reservation_number'] ?? ''));

if ($reservationNumber === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a reservation number.']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Sweep no-shows before this lookup — a reservation that's sat unclaimed
// past the grace period should read as No-show here, not stale Confirmed.
sweepNoShowReservations($db, getReservationSettings($db)['reservation_no_show_hours']);

$reservation = findReservationByNumber($db, $reservationNumber);

if (!$reservation) {
    echo json_encode(['found' => false]);
    exit;
}

// Reservation exists but its table/credit is no longer usable -- same
// no_show/cancelled cases create_order.php's own server-side guard rejects.
// Reported here too (found:true, usable:false) rather than as a plain
// not-found, so the cashier sees WHY instead of assuming a typo and
// retyping the same number.
if (in_array($reservation['status'], ['no_show', 'cancelled'], true)) {
    echo json_encode([
        'found'  => true,
        'usable' => false,
        'status' => $reservation['status'],
        'reservation_number' => $reservation['reservation_number'],
    ]);
    exit;
}

$advanceOrders = getReservationAdvanceOrders($db, (int)$reservation['reservation_id']);
$credit        = getReservationCredit($db, (int)$reservation['reservation_id']);

echo json_encode([
    'found'          => true,
    'reservation'    => $reservation,
    'advance_orders' => $advanceOrders,
    'credit'         => $credit,
]);
