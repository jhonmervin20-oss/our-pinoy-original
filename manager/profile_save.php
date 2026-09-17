<?php
/**
 * manager/profile_save.php
 *
 * Self-service update for the logged-in manager's OWN first name, last
 * name and phone only -- always operates on Session::getUserId(),
 * never a posted user_id, so there's no way to edit anyone else's account
 * from here. Email, password, and role are deliberately not accepted by
 * this form at all (see manager/profile.php) -- those stay admin-only
 * changes via admin/users_save.php. Mirrors owner/profile_save.php.
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
if (!Session::hasRole(['manager'])) {
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

$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name'] ?? '');

$errors = [];

if ($firstName === '' || mb_strlen($firstName) > 100) {
    $errors[] = 'Please enter a first name.';
}
if ($lastName === '' || mb_strlen($lastName) > 100) {
    $errors[] = 'Please enter a last name.';
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    header('Location: profile.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->prepare(
        // phone is deliberately not named here: this form no longer
        // collects them, so writing them would blank whatever is already stored
        // (they remain editable by the account holder nowhere else -- the
        // columns simply keep their current value).
        "UPDATE users SET first_name = ?, last_name = ? WHERE user_id = ?"
    )->execute([
        $firstName, $lastName,
        Session::getUserId(),
    ]);

    // Session's own name fields are only refreshed on next login otherwise --
    // update them now so the sidebar/header avatar+name reflect the change
    // immediately instead of showing stale text until the manager logs out.
    $_SESSION['first_name'] = $firstName;
    $_SESSION['last_name']  = $lastName;

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Profile', 'Update own profile', ?, ?)"
        )->execute([
            Session::getUserId(),
            'Updated own name.',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort — never block the actual save on it.
    }

    flash_set('success', 'Profile updated.');
} catch (PDOException $e) {
    error_log('manager/profile_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong saving your profile. Please try again.');
}

header('Location: profile.php');
exit;
