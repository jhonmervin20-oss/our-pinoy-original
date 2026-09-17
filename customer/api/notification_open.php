<?php
/**
 * customer/api/notification_open.php
 *
 * GET ?id=N — plain link target for one notification in navbar.php's bell
 * dropdown (not a fetch()-based endpoint like this app's other api/*.php
 * files; a notification is just a link, so a real navigation + redirect is
 * the right shape here, matching e.g. reservation_cancel_return.php's own
 * "do a thing, then redirect" pattern). Marks it read, then sends the
 * customer to whatever page that notification is actually about — the
 * whole point of "the right redirection" being wired up, not just showing
 * text in a dropdown.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['customer'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$customerId = Session::getUserId();
$notificationId = (int)($_GET['id'] ?? 0);

$fallback = '../dashboard.php';

if ($notificationId <= 0) {
    header('Location: ' . $fallback);
    exit;
}

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare(
    'SELECT notification_id, reference_type, reference_id
     FROM notifications
     WHERE notification_id = ? AND user_id = ?'
);
$stmt->execute([$notificationId, $customerId]);
$notification = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$notification) {
    header('Location: ' . $fallback);
    exit;
}

$pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND is_read = 0")
    ->execute([$notificationId]);

// 'reservation_confirmed' is the only type that lands on the ticket/success
// page (reservation_confirm.php already renders that view correctly even
// long after checkout, since it only re-verifies with PayMongo while
// payment_status is still 'pending' — see that file's own doc comment).
// Every other reservation-related type is "go look at this reservation",
// which is reservation_details.php.
$redirect = $fallback;

if ($notification['reference_id'] !== null) {
    $reservationId = (int)$notification['reference_id'];
    $numberStmt = $pdo->prepare('SELECT reservation_number FROM reservations WHERE reservation_id = ? AND customer_id = ?');
    $numberStmt->execute([$reservationId, $customerId]);
    $reservationNumber = $numberStmt->fetchColumn();

    if ($reservationNumber) {
        $redirect = $notification['reference_type'] === 'reservation_confirmed'
            ? '../reservation_confirm.php?reservation_number=' . urlencode($reservationNumber)
            : '../reservation_details.php?reservation_number=' . urlencode($reservationNumber);
    }
}

header('Location: ' . $redirect);
exit;
