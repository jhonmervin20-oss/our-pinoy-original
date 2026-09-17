<?php
/**
 * customer/reservation_cancel_return.php
 *
 * PayMongo's cancel_url target — the customer backed out of checkout.
 * Releases the hold the same way reservation_confirm.php does for a
 * failed payment, then sends them back into the wizard.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
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

if ($reservationNumber !== '') {
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

    if ($hold) {
        releaseReservationHold(
            $pdo,
            (int)$hold['reservation_id'],
            (int)$hold['payment_id'],
            $hold['reservation_date'],
            (string)$hold['slot_id'],
            (int)$hold['number_of_guests']
        );
    }
}

flash_set('error', 'Reservation was not completed. Your hold has been released — please try again.');
header('Location: make_reservation.php');
exit;
