<?php
/**
 * admin/notifications_mark_all_read.php
 *
 * GET — plain link target (the "Mark all as read" line in includes/header.php's
 * bell dropdown and the button on notifications.php). The admin feed reads the
 * system-wide `activity_logs` audit trail, so "mark all read" simply advances
 * the session watermark (admin_notif_read_at) to "now" -- everything logged
 * before this moment counts as read. Works for the current browser session
 * only, exactly like the watermark that powers the bell.
 *
 * Where to redirect back to is passed explicitly via ?return=... (the calling
 * page's own REQUEST_URI) rather than relying on the HTTP Referer header --
 * that header is dropped or truncated to origin-only by plenty of real
 * browsers/privacy settings, which silently sent everyone back to
 * dashboard.php regardless of which admin page they'd actually been on.
 * ?return= is attacker-controllable (it's a query param), so it's validated
 * as a same-site, non-protocol-relative path landing in one of the modules
 * that share this bell dropdown -- not just /admin/ itself, but also
 * employee_management/ (employees.php, departments_positions.php,
 * schedules.php all render the admin chrome for an Admin viewer -- see
 * em_access.php's emRenderHeader()), the same cross-module sharing pattern
 * owner/manager's own mark-all-read handlers already account for.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$_SESSION['admin_notif_read_at'] = time();

$returnParam = $_GET['return'] ?? '';
$validSegments = ['/admin/', '/employee_management/'];
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
