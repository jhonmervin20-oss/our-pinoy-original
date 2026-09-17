<?php
/**
 * admin/profile_save.php
 *
 * Self-service update for the logged-in admin's own profile fields.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['admin'])) {
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
$errors    = [];

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
        // collects them, so writing them would blank whatever is already stored.
        "UPDATE users SET first_name = ?, last_name = ? WHERE user_id = ?"
    )->execute([
        $firstName,
        $lastName,
        Session::getUserId(),
    ]);

    $_SESSION['first_name'] = $firstName;
    $_SESSION['last_name']  = $lastName;
    flash_set('success', 'Profile updated.');
} catch (PDOException $e) {
    error_log('admin/profile_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong saving your profile. Please try again.');
}

header('Location: profile.php');
exit;