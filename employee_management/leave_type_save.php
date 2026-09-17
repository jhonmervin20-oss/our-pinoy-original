<?php
/**
 * employee_management/leave_type_save.php
 *
 * Create or update a leave type. Managed entirely through the "Manage
 * leave types" modal on leave.php (no standalone page).
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
    header('Location: leave.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: leave.php');
    exit;
}

$leaveTypeId = trim((string)($_POST['leave_type_id'] ?? ''));
$isEdit = $leaveTypeId !== '' && ctype_digit($leaveTypeId);

$name = trim($_POST['leave_name'] ?? '');
$defaultDays = trim($_POST['default_days_per_year'] ?? '');
$isPaid = isset($_POST['is_paid']) ? 1 : 0;

$errors = [];

if ($name === '' || mb_strlen($name) > 50) {
    $errors[] = 'Please enter a leave name (up to 50 characters).';
}
if ($defaultDays === '' || !is_numeric($defaultDays) || (float)$defaultDays < 0) {
    $errors[] = 'Please enter a valid default number of days per year.';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $dupCheck = $isEdit
            ? $pdo->prepare("SELECT leave_type_id FROM leave_types WHERE leave_name = ? AND leave_type_id != ?")
            : $pdo->prepare("SELECT leave_type_id FROM leave_types WHERE leave_name = ?");
        $isEdit ? $dupCheck->execute([$name, $leaveTypeId]) : $dupCheck->execute([$name]);

        if ($dupCheck->fetch()) {
            $errors[] = 'A leave type with that name already exists.';
        } else {
            if ($isEdit) {
                $stmt = $pdo->prepare("UPDATE leave_types SET leave_name = ?, is_paid = ?, default_days_per_year = ? WHERE leave_type_id = ?");
                $stmt->execute([$name, $isPaid, (float)$defaultDays, $leaveTypeId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO leave_types (leave_name, is_paid, default_days_per_year) VALUES (?, ?, ?)");
                $stmt->execute([$name, $isPaid, (float)$defaultDays]);
            }

            flash_set('success', $isEdit ? 'Leave type updated.' : 'Leave type created.');

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Leave', ?, ?, ?)"
                )->execute([
                    Session::getUserId(),
                    $isEdit ? 'Update leave type' : 'Create leave type',
                    ($isEdit ? 'Updated' : 'Created') . " leave type: {$name}",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }
        }
    } catch (PDOException $e) {
        error_log('leave_type_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this leave type. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_leave_type_modal'] = true;
}

header('Location: leave.php');
exit;
