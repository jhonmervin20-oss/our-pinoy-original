<?php
/**
 * employee_management/leave_balance_save.php
 *
 * Create or update a leave balance's days_entitled for one employee /
 * leave type / year. days_used is never accepted from the client -- it's
 * always recalculated from that combination's approved
 * employee_leave_records right after saving, so it can never drift from
 * the actual records. employee_leave_balances has a
 * UNIQUE(employee_id, leave_type_id, year) constraint, checked explicitly
 * here for a friendly error.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/payroll_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$returnYear = trim((string)($_POST['return_year'] ?? ''));
$redirect   = 'leave.php?tab=balances' . (preg_match('/^\d{4}$/', $returnYear) ? '&year=' . $returnYear : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$balanceId = trim((string)($_POST['leave_balance_id'] ?? ''));
$isEdit    = $balanceId !== '' && ctype_digit($balanceId);

$employeeId   = trim((string)($_POST['employee_id'] ?? ''));
$leaveTypeId  = trim((string)($_POST['leave_type_id'] ?? ''));
$year         = trim((string)($_POST['year'] ?? ''));
$daysEntitled = trim($_POST['days_entitled'] ?? '');

$errors = [];

if ($employeeId === '' || !ctype_digit($employeeId)) {
    $errors[] = 'Please choose an employee.';
}
if ($leaveTypeId === '' || !ctype_digit($leaveTypeId)) {
    $errors[] = 'Please choose a leave type.';
}
if (!preg_match('/^\d{4}$/', $year)) {
    $errors[] = 'Please enter a valid year.';
}
if ($daysEntitled === '' || !is_numeric($daysEntitled) || (float)$daysEntitled < 0) {
    $errors[] = 'Please enter a valid, non-negative number of entitled days.';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $dupCheck = $isEdit
            ? $pdo->prepare("SELECT leave_balance_id FROM employee_leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ? AND leave_balance_id != ?")
            : $pdo->prepare("SELECT leave_balance_id FROM employee_leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?");
        $isEdit
            ? $dupCheck->execute([$employeeId, $leaveTypeId, $year, $balanceId])
            : $dupCheck->execute([$employeeId, $leaveTypeId, $year]);

        if ($dupCheck->fetch()) {
            $errors[] = 'A balance for this employee, leave type, and year already exists. Edit the existing one instead.';
        }
    } catch (PDOException $e) {
        error_log('leave_balance_save.php lookup failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this balance. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE employee_leave_balances SET employee_id = ?, leave_type_id = ?, year = ?, days_entitled = ? WHERE leave_balance_id = ?"
            );
            $stmt->execute([$employeeId, $leaveTypeId, $year, (float)$daysEntitled, $balanceId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO employee_leave_balances (employee_id, leave_type_id, year, days_entitled, days_used) VALUES (?, ?, ?, ?, 0)"
            );
            $stmt->execute([$employeeId, $leaveTypeId, $year, (float)$daysEntitled]);
        }

        // Refresh days_used from actual records so it's never stale, even
        // for a brand-new balance row created before any leave was taken.
        recalculateLeaveBalance($pdo, (int)$employeeId, (int)$leaveTypeId, (int)$year);

        flash_set('success', $isEdit ? 'Leave balance updated.' : 'Leave balance set.');

        try {
            $empName = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
            $empName->execute([$employeeId]);
            $emp = $empName->fetch(PDO::FETCH_ASSOC);
            $empLabel = $emp ? trim($emp['first_name'] . ' ' . $emp['last_name']) : "employee #{$employeeId}";

            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Leave', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update leave balance' : 'Set leave balance',
                ($isEdit ? 'Updated' : 'Set') . " leave balance for {$empLabel} ({$year}): {$daysEntitled} days entitled",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('leave_balance_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this balance. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_balance_modal'] = true;
}

header('Location: ' . $redirect);
exit;
