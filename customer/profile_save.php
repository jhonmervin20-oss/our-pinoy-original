<?php
/**
 * customer/profile_save.php
 *
 * Self-service update for the logged-in customer's OWN first name, last
 * name, email, and phone -- always operates on
 * Session::getUserId(), never a posted user_id. Unlike the staff panels'
 * profile_save.php (owner/manager/cashier), email IS editable here since
 * customers have no admin to change it for them -- validated the same way
 * auth/sign_up.php validates a new signup's email (format + uniqueness).
 * Password is a separate form/endpoint (profile_password_save.php).
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

$customerId = Session::getUserId();
$firstName  = trim($_POST['first_name'] ?? '');
$lastName   = trim($_POST['last_name'] ?? '');
$email      = trim($_POST['email'] ?? '');
$phone      = trim($_POST['phone'] ?? '');

$errors = [];

if ($firstName === '' || mb_strlen($firstName) > 100) {
    $errors[] = 'Please enter a first name.';
}
if ($lastName === '' || mb_strlen($lastName) > 100) {
    $errors[] = 'Please enter a last name.';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    $errors[] = 'Please enter a valid email address.';
}
if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
    $errors[] = 'Please enter a valid phone number, or leave it blank.';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $dupCheck = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1');
        $dupCheck->execute([$email, $customerId]);
        if ($dupCheck->fetch()) {
            $errors[] = 'Another account already uses this email address.';
        }
    } catch (PDOException $e) {
        error_log('customer/profile_save.php dup check failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving your profile. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    header('Location: profile.php');
    exit;
}

try {
    $pdo->prepare(
        "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE user_id = ?"
    )->execute([
        $firstName, $lastName, $email,
        $phone !== '' ? $phone : null,
        $customerId,
    ]);

    // Session's own name is only refreshed on next login otherwise -- update
    // it now so the navbar avatar/name reflects the change immediately.
    $_SESSION['first_name'] = $firstName;
    $_SESSION['last_name']  = $lastName;
    $_SESSION['email']      = $email;

    flash_set('success', 'Profile updated.');
} catch (PDOException $e) {
    // Race-condition duplicate email (two saves at the same instant) --
    // same code auth/sign_up.php already treats as a friendly message.
    if ((int)$e->getCode() === 23000) {
        flash_set('error', 'Another account already uses this email address.');
    } else {
        error_log('customer/profile_save.php failed: ' . $e->getMessage());
        flash_set('error', 'Something went wrong saving your profile. Please try again.');
    }
}

header('Location: profile.php');
exit;
