<?php
/**
 * manager/notifications_poll.php
 *
 * JSON endpoint the bell dropdown polls on an interval (see the <script>
 * block in manager/includes/header.php) so new notifications appear
 * without a full page reload -- this app has no WebSocket/SSE
 * infrastructure, so short-interval polling is the mechanism. Returns
 * exactly the same data shape header.php renders on a real page load (same
 * query, same managerNotifListHtml() used for both), just as JSON instead
 * of inline HTML.
 *
 * header.php is shared across several module folders (inventory/,
 * purchase_orders/, reservation/, orders/, cashier/, employee_management/ -- not just
 * manager/ itself), each setting its own $managerBase so its links resolve
 * correctly relative to wherever the page actually lives. The poll
 * response needs that same base to build correct links, so the caller
 * echoes its own $managerBase back here as ?base=.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/notification_functions.php';
require_once __DIR__ . '/../config/inventory_alerts.php';
require_once __DIR__ . '/../config/payroll_alerts.php';

Session::start();

header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['manager'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

$managerId = Session::getUserId();
$unreadCount = 0;
$notifications = [];

// Only ever produced by this app's own JS echoing its own $managerBase back
// (never raw user input in practice), but validated anyway since it's used
// to build hrefs -- restrict to the only shapes $managerBase ever actually
// takes ('' or a handful of '../' segments), not an open string.
$callerBase = (string)($_GET['base'] ?? '');
if (!preg_match('#^(\.\./[a-z_]+/)*$#', $callerBase)) {
    $callerBase = '';
}

try {
    $pdo = Database::getInstance()->getConnection();

    // Same opportunistic sweeps header.php runs on a real page load --
    // sweepInventoryStockAlerts() is cheap pure SQL and sweepPayrollCutoffAlerts()
    // self-throttles internally, so calling them every poll is safe, just a
    // cheap no-op skip on a repeat. Without this here, stock/batch/payroll
    // conditions would only ever get checked on navigation, not while sitting
    // on a page polling. Auto purchase orders are no longer swept from page
    // loads at all -- cron/run_sweeps.php owns that now
    // (config/forecast_auto_po.php).
    try {
        sweepInventoryStockAlerts($pdo);
        sweepPayrollCutoffAlerts($pdo);
    } catch (Throwable $e) {
        error_log('manager/notifications_poll.php sweep failed: ' . $e->getMessage());
    }

    $notifStmt = $pdo->prepare(
        "SELECT notification_id, type, title, message, reference_type, reference_id, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 8"
    );
    $notifStmt->execute([$managerId]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $unreadStmt->execute([$managerId]);
    $unreadCount = (int)$unreadStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('manager/notifications_poll.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load notifications']);
    exit;
}

echo json_encode([
    'unread_count' => $unreadCount,
    'list_html'    => managerNotifListHtml($notifications, $callerBase),
]);
