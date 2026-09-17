<?php
/**
 * customer/api/notifications_poll.php
 *
 * JSON endpoint the bell dropdown polls on an interval (see the <script>
 * block in customer/includes/navbar.php) so new notifications appear
 * without a full page reload -- this app has no WebSocket/SSE
 * infrastructure, so short-interval polling is the mechanism. Returns
 * exactly the same data shape navbar.php renders on a real page load (same
 * sweeps, same query, same customerNotifListHtml() used for both), just as
 * JSON instead of inline HTML.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../includes/reservation_functions.php';
require_once __DIR__ . '/../includes/notification_functions.php';

Session::start();

header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['customer'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

$customerId = Session::getUserId();
$unreadCount = 0;
$notifications = [];

try {
    $pdo = Database::getInstance()->getConnection();
    $resSettings = getReservationSettings($pdo);

    // Same opportunistic sweeps navbar.php runs on a real page load, so a
    // reservation hitting its hold-expiry/today/no-show window gets caught
    // while the customer is just sitting on a page polling, not only when
    // they next navigate.
    notifyUpcomingHoldExpiry($pdo, $customerId, $resSettings['reservation_hold_minutes']);
    notifyTodayReservations($pdo, $customerId);
    notifyUpcomingNoShow($pdo, $customerId, $resSettings['reservation_no_show_hours']);

    $notifStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $notifStmt->execute([$customerId]);
    $unreadCount = (int)$notifStmt->fetchColumn();

    $recentStmt = $pdo->prepare(
        'SELECT notification_id, title, message, reference_type, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10'
    );
    $recentStmt->execute([$customerId]);
    $notifications = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('customer/api/notifications_poll.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load notifications']);
    exit;
}

echo json_encode([
    'unread_count' => $unreadCount,
    'list_html'    => customerNotifListHtml($notifications),
]);
