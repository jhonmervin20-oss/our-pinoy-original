<?php
/**
 * customer/api/reservation_cancel.php
 *
 * Customer-initiated cancellation from the reservation details page — only
 * ever offered for a reservation still in its unpaid hold window ('pending'
 * status + 'pending' payment_status). A customer cannot self-cancel a
 * confirmed/paid reservation from here; that's a staff action elsewhere.
 * Standard POST-redirect-GET, not AJAX — this is a plain form submit, no
 * client-side round trip needed before navigating.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/flash.php';
require_once __DIR__ . '/../includes/reservation_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit;
}
if (!Session::hasRole(['customer'])) {
    header('Location: ../../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../reservations.php');
    exit;
}

$customerId = Session::getUserId();
$reservationNumber = trim((string)($_POST['reservation_number'] ?? ''));

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ../reservation_details.php?reservation_number=' . urlencode($reservationNumber));
    exit;
}

if ($reservationNumber === '') {
    header('Location: ../reservations.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare(
    "SELECT r.reservation_id, r.reservation_date, r.slot_id, r.number_of_guests, rp.payment_id
     FROM reservations r
     JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
     WHERE r.reservation_number = ? AND r.customer_id = ? AND r.status = 'pending' AND rp.payment_status = 'pending'
     ORDER BY rp.payment_id DESC LIMIT 1"
);
$stmt->execute([$reservationNumber, $customerId]);
$hold = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hold) {
    flash_set('error', "That reservation can't be cancelled — it may already be confirmed, cancelled, or expired.");
    header('Location: ../reservation_details.php?reservation_number=' . urlencode($reservationNumber));
    exit;
}

releaseReservationHold(
    $pdo,
    (int)$hold['reservation_id'],
    (int)$hold['payment_id'],
    $hold['reservation_date'],
    (string)$hold['slot_id'],
    (int)$hold['number_of_guests']
);

try {
    $pdo->prepare(
        "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
         VALUES (?, 'Reservations', 'Cancel reservation', ?, ?)"
    )->execute([
        $customerId,
        "{$reservationNumber}: cancelled by customer before payment",
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (PDOException $e) {
    // Activity logging is best-effort.
}

flash_set('success', 'Reservation cancelled.');
header('Location: ../reservation_details.php?reservation_number=' . urlencode($reservationNumber));
exit;
