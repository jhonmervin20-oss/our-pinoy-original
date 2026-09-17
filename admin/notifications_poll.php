<?php
/**
 * admin/notifications_poll.php
 *
 * JSON endpoint the bell dropdown polls on an interval (see the <script>
 * block in admin/includes/header.php) so new notifications appear without a
 * full page reload. Returns exactly the same data shape header.php renders
 * on a real page load (same query, same adminNotifListHtml() used for both),
 * just as JSON instead of inline HTML. Reads the system-wide `activity_logs`
 * audit trail and computes unread from the session watermark -- DOES NOT mark
 * anything read itself: polling should reveal new activity, not silently mark
 * it read; that still only happens via "Mark all as read" or clicking through
 * a notification (notification_go.php).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/notification_functions.php';

Session::start();

header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

// Only ever produced by this app's own JS echoing its own $adminBase back
// (never raw user input in practice), but validated anyway since it's used
// to build hrefs -- restrict to the only shapes $adminBase ever actually
// takes ('' or a handful of '../' segments), not an open string.
$callerBase = (string)($_GET['base'] ?? '');
if (!preg_match('#^(\.\./[a-z_]+/)*$#', $callerBase)) {
    $callerBase = '';
}

$notifications = [];
$unreadCount = 0;

try {
    $pdo = Database::getInstance()->getConnection();

    // Seed the watermark on first poll too, so an admin who lands on a page
    // that skips header.php's seeding still gets a stable baseline.
    if (!isset($_SESSION['admin_notif_read_at'])) {
        $_SESSION['admin_notif_read_at'] = time();
    }

    $notifStmt = $pdo->prepare(
        "SELECT l.log_id, l.module, l.action, l.description, l.created_at,
                u.first_name, u.last_name
         FROM activity_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         ORDER BY l.created_at DESC
         LIMIT 8"
    );
    $notifStmt->execute();
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM activity_logs WHERE created_at > FROM_UNIXTIME(?)'
    );
    $unreadStmt->execute([(int)$_SESSION['admin_notif_read_at']]);
    $unreadCount = (int)$unreadStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('admin/notifications_poll.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load notifications']);
    exit;
}

echo json_encode([
    'unread_count' => $unreadCount,
    'list_html'    => adminNotifListHtml($notifications, $callerBase, adminNotifWatermark()),
]);
