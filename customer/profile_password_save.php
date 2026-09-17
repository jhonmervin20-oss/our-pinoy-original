<?php
/**
 * customer/profile_password_save.php
 *
 * Self-service password change -- always operates on Session::getUserId().
 * Requires the current password to verify (password_verify() against the
 * real hash) before accepting a new one, standard practice for a password
 * change specifically (unlike the other profile fields, which don't need
 * this extra check). Only reachable for accounts that actually have a
 * password_hash to begin with.
 *
 * It also handles the opposite case: an account with NO password adding one
 * for the first time. That path skips the current-password check -- there is
 * nothing to verify against -- and is gated on the stored hash being NULL, so
 * it can never be used to bypass verification on an account that already has
 * a password. Which form customer/profile.php renders follows the same
 * condition, but this endpoint re-derives it from the database rather than
 * trusting the markup.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['customer'])) {
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


require_once __DIR__ . '/../config/profile_password.php';

try {
    $result = changeOwnPassword(
        Database::getInstance()->getConnection(),
        Session::getUserId(),
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? ''),
        (string)($_POST['confirm_password'] ?? ''),
        // Customers may set a password for the first time if the account
        // doesn't have one yet. Staff endpoints pass false.
        true
    );
    flash_set($result['ok'] ? 'success' : 'error', $result['message']);
} catch (PDOException $e) {
    error_log('customer/profile_password_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating your password. Please try again.');
}

header('Location: profile.php');
exit;
