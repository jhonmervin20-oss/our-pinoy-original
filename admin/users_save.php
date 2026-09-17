<?php
/**
 * admin/users_save.php
 *
 * Create or update a staff account (admin, owner, manager, cashier).
 * Customer accounts are self-service (sign_up.php) and are never created
 * or edited from here — this endpoint only accepts the four staff role
 * names.
 *
 * Edit mode is triggered by a non-empty `user_id` field. Password is
 * required on create, optional on edit (blank = keep the existing hash).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/notifications.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

const STAFF_ROLES = ['admin', 'owner', 'manager', 'cashier'];

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: users.php');
    exit;
}

$userId    = trim((string)($_POST['user_id'] ?? ''));
$isEdit    = $userId !== '' && ctype_digit($userId);
$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$role      = trim($_POST['role'] ?? '');
$password  = (string)($_POST['password'] ?? '');
$password2 = (string)($_POST['password_confirm'] ?? '');

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
if (!in_array($role, STAFF_ROLES, true)) {
    $errors[] = 'Please choose a valid role.';
}

if (!$isEdit || $password !== '') {
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    }
}

// Editing your own account away from 'admin' would lock you out of this
// panel with no other admin necessarily available to undo it.
if ($isEdit && (int)$userId === Session::getUserId() && $role !== 'admin') {
    $errors[] = "You can't change your own role away from admin.";
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $dupCheck = $isEdit
            ? $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1")
            : $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $isEdit ? $dupCheck->execute([$email, $userId]) : $dupCheck->execute([$email]);

        if ($dupCheck->fetch()) {
            $errors[] = 'Another account already uses this email address.';
        } else {
            $roleRow = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ?");
            $roleRow->execute([$role]);
            $roleId = $roleRow->fetchColumn();

            if (!$roleId) {
                $errors[] = 'That role no longer exists.';
            } else {
                // `phone` is deliberately absent from both statements. This form
                // no longer collects it, and naming the column here would mean an
                // edit silently blanked whatever the staff member had set for
                // themselves under My profile. Left out entirely, the column keeps
                // its value on update and its NULL default on insert.
                if ($isEdit) {
                    if ($password !== '') {
                        $stmt = $pdo->prepare(
                            "UPDATE users SET first_name = ?, last_name = ?, email = ?, role_id = ?, password_hash = ?
                             WHERE user_id = ?"
                        );
                        $stmt->execute([$firstName, $lastName, $email, $roleId,
                            password_hash($password, PASSWORD_DEFAULT), $userId]);
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE users SET first_name = ?, last_name = ?, email = ?, role_id = ?
                             WHERE user_id = ?"
                        );
                        $stmt->execute([$firstName, $lastName, $email, $roleId, $userId]);
                    }
                    $logAction = 'Update staff account';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (role_id, first_name, last_name, email, password_hash, is_active)
                         VALUES (?, ?, ?, ?, ?, 1)"
                    );
                    $stmt->execute([$roleId, $firstName, $lastName, $email,
                        password_hash($password, PASSWORD_DEFAULT)]);
                    $userId    = $pdo->lastInsertId();
                    $logAction = 'Create staff account';
                }

                try {
                    $log = $pdo->prepare(
                        "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                         VALUES (?, 'Users', ?, ?, ?)"
                    );
                    $log->execute([
                        Session::getUserId(),
                        $logAction,
                        "{$logAction}: {$firstName} {$lastName} ({$email}), role: {$role}",
                        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                } catch (PDOException $e) {
                    // Activity logging is best-effort — never block the actual save on it.
                }

                if (!$isEdit) {
                    notifyUsersByRole(
                        $pdo, ['admin'], 'users', 'Staff account created',
                        "{$firstName} {$lastName} ({$email}), role: {$role}.",
                        'user_account', (int)$userId
                    );
                }

                flash_set('success', $isEdit ? 'Staff account updated.' : 'Staff account created.');
            }
        }
    } catch (PDOException $e) {
        error_log('users_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this account. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_staff_modal'] = true;
}

header('Location: users.php');
exit;
