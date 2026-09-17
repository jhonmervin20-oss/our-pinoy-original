<?php
/**
 * manager/notifications_mark_all_read.php
 *
 * GET — plain link target (the "Mark all as read" line in includes/header.php's
 * bell dropdown and the button on notifications.php). Notifications are real
 * per-row notifications table entries now (config/notifications.php) -- this
 * just flips every unread row this manager owns to is_read = 1.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();
$pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0')
    ->execute([Session::getUserId()]);

// Where to redirect back to is passed explicitly via ?return=... (the
// calling page's own REQUEST_URI) rather than relying on the HTTP Referer
// header -- that header is dropped or truncated to origin-only by plenty of
// real browsers/privacy settings, which silently sent everyone back to
// dashboard.php regardless of which page they'd actually been on. ?return=
// is attacker-controllable (it's a query param), so it's validated the same
// way the old referer check was: it must be a same-site, non-protocol-
// relative path landing in one of the modules that share this bell dropdown
// (inventory/, purchase_orders/, reservation/, orders/, cashier/,
// employee_management/ -- not just manager/ itself).
$returnParam = $_GET['return'] ?? '';
$validSegments = ['/manager/', '/inventory/', '/purchase_orders/', '/reservation/', '/orders/', '/cashier/', '/employee_management/'];
$isSafeReturn = $returnParam !== ''
    && $returnParam[0] === '/'
    && strpos($returnParam, '//', 1) === false
    && strpos($returnParam, '://') === false;
if ($isSafeReturn) {
    $isSafeReturn = false;
    foreach ($validSegments as $segment) {
        if (strpos($returnParam, $segment) !== false) {
            $isSafeReturn = true;
            break;
        }
    }
}
$redirect = $isSafeReturn ? $returnParam : 'dashboard.php';

header('Location: ' . $redirect);
exit;
