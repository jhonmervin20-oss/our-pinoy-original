<?php
/**
 * customer/api/notifications_mark_all_read.php
 *
 * GET — plain link target (the "Mark all as read" line in navbar.php's
 * bell dropdown), not a fetch()-based endpoint — same reasoning as
 * notification_open.php. Redirects back to wherever the customer actually
 * was, via an explicit ?return=... (that page's own REQUEST_URI) rather
 * than HTTP_REFERER -- that header is dropped or truncated to origin-only
 * by plenty of real mobile browsers/privacy settings, which silently sent
 * everyone back to the dashboard regardless of which page they'd actually
 * been on. ?return= is attacker-controllable, so it's validated as a
 * same-site, non-protocol-relative path under customer/ before use.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['customer'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();
$pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0")
    ->execute([Session::getUserId()]);

$returnParam = $_GET['return'] ?? '';
// Only ever redirect back into this same app's customer/ area -- never
// follow an arbitrary external or protocol-relative value.
$isSafeReturn = $returnParam !== ''
    && $returnParam[0] === '/'
    && strpos($returnParam, '//', 1) === false
    && strpos($returnParam, '://') === false
    && strpos($returnParam, '/customer/') !== false;
$redirect = $isSafeReturn ? $returnParam : '../dashboard.php';

header('Location: ' . $redirect);
exit;
