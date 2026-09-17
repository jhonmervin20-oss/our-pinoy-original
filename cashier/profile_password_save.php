<?php
/**
 * cashier/profile_password_save.php
 *
 * Self-service password change for the logged-in cashier. Always operates on
 * Session::getUserId() -- there is no user_id in the request, so this endpoint
 * cannot be pointed at anyone else's account.
 *
 * The rules themselves (verify current, min length, must match, must differ)
 * live in config/profile_password.php, shared with every other role's copy of
 * this endpoint. $allowSetWhenMissing stays false here: a staff account with
 * no password is an admin-provisioning matter, not something to be claimed by
 * whoever reaches this page.
 *
 * Replaces the previous arrangement where only an admin could change a staff
 * password via admin/users_save.php -- which meant the admin chose, and
 * therefore knew, every staff member's password.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/profile_password.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['cashier'])) {
    header('Location: ../auth/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit;
}
if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: profile.php');
    exit;
}

try {
    $result = changeOwnPassword(
        Database::getInstance()->getConnection(),
        Session::getUserId(),
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? ''),
        (string)($_POST['confirm_password'] ?? ''),
        false
    );
    flash_set($result['ok'] ? 'success' : 'error', $result['message']);
} catch (PDOException $e) {
    error_log('cashier/profile_password_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating your password. Please try again.');
}

header('Location: profile.php');
exit;
