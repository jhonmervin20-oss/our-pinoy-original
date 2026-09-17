<?php
/**
 * employee_management/overtime_save.php
 *
 * Creates OR edits an OT authorization -- always status='approved', same
 * "manager's save action IS the approval" convention as
 * leave_record_save.php. A new record is add-only (no separate approval
 * step); editing an existing one updates that same row in place, mirroring
 * leave_record_save.php's own create/update dual mode.
 *
 * UNLIKE leave records, a given employee/date is deliberately allowed to
 * have MORE THAN ONE approved authorization row: the normal flow is a
 * manager pre-authorizes an amount (possibly before the shift even
 * happens), and if the employee ends up clocking MORE than that, the
 * manager can come back and add a second row to cover the excess -- an
 * "excess approval" that's really just another authorization, born
 * approved the same way. payroll_run_functions.php SUMS all approved rows
 * for a date rather than expecting exactly one, so stacking is exactly how
 * that gets paid. Blocking duplicates here would make the excess-approval
 * flow impossible.
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

$returnSearch = trim((string)($_POST['return_search'] ?? ''));
$redirect     = 'overtime.php' . ($returnSearch !== '' ? '?q=' . urlencode($returnSearch) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$authId     = trim((string)($_POST['overtime_authorization_id'] ?? ''));
$isEdit     = $authId !== '' && ctype_digit($authId);

$employeeId = trim((string)($_POST['employee_id'] ?? ''));
$workDate   = trim($_POST['work_date'] ?? '');
$hoursRaw   = trim((string)($_POST['hours'] ?? ''));
$minutesRaw = trim((string)($_POST['minutes'] ?? ''));

$errors = [];

if ($employeeId === '' || !ctype_digit($employeeId)) {
    $errors[] = 'Please choose an employee.';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    $errors[] = 'Please enter a valid date.';
}
if ($hoursRaw === '' || !ctype_digit($hoursRaw) || $minutesRaw === '' || !ctype_digit($minutesRaw) || (int)$minutesRaw > 59) {
    $errors[] = 'Please enter a valid overtime duration.';
}

// Duration is entered as separate Hours/Minutes fields (matching the form),
// combined into one total for storage -- authorized_minutes is still the
// single source of truth everywhere else in the system.
$minutes = ($hoursRaw !== '' && $minutesRaw !== '' && ctype_digit($hoursRaw) && ctype_digit($minutesRaw))
    ? (string)((int)$hoursRaw * 60 + (int)$minutesRaw)
    : '0';
if (!$errors && (int)$minutes <= 0) {
    $errors[] = 'Please enter a duration greater than zero.';
}

if (!$errors && $isEdit) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT status FROM overtime_authorizations WHERE overtime_authorization_id = ?");
        $stmt->execute([$authId]);
        $existingStatus = $stmt->fetchColumn();
        if ($existingStatus === false) {
            $errors[] = 'That overtime record no longer exists.';
        } elseif ($existingStatus !== 'approved') {
            $errors[] = 'That overtime record was already deleted.';
        }
    } catch (PDOException $e) {
        error_log('overtime_save.php lookup failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this record. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE overtime_authorizations SET employee_id = ?, work_date = ?, authorized_minutes = ?
                 WHERE overtime_authorization_id = ?"
            );
            $stmt->execute([$employeeId, $workDate, (int)$minutes, $authId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO overtime_authorizations (employee_id, work_date, authorized_minutes, status, recorded_by)
                 VALUES (?, ?, ?, 'approved', ?)"
            );
            $stmt->execute([$employeeId, $workDate, (int)$minutes, Session::getUserId()]);
        }

        flash_set('success', $isEdit ? 'Overtime record updated.' : 'Overtime recorded.');

        try {
            $empName = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
            $empName->execute([$employeeId]);
            $emp = $empName->fetch(PDO::FETCH_ASSOC);
            $empLabel = $emp ? trim($emp['first_name'] . ' ' . $emp['last_name']) : "employee #{$employeeId}";

            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Overtime', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update overtime record' : 'Record overtime',
                ($isEdit ? 'Updated' : 'Recorded') . " {$hoursRaw}h {$minutesRaw}m ({$minutes} minute(s)) of overtime for {$empLabel} on {$workDate}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('overtime_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this record. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_ot_modal'] = true;
}

header('Location: ' . $redirect);
exit;
