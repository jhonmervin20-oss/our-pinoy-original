<?php
/**
 * employee_management/leave_record_save.php
 *
 * Create or update a leave record. employee_leave_records.status only
 * supports approved/cancelled (no "pending") -- leave here is recorded as
 * an already-decided fact. After saving, recalculateLeaveBalance() is
 * called for the affected employee/leave type/year so the balance's
 * days_used never drifts from the actual records. If editing changed the
 * employee, leave type, or the year the leave falls in, the *old*
 * combination is recalculated too, so its balance doesn't stay
 * incorrectly inflated.
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
$redirect   = 'leave.php?tab=records' . (preg_match('/^\d{4}$/', $returnYear) ? '&year=' . $returnYear : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

const LEAVE_RECORD_STATUSES = ['approved', 'cancelled'];

$recordId = trim((string)($_POST['leave_record_id'] ?? ''));
$isEdit   = $recordId !== '' && ctype_digit($recordId);

$employeeId  = trim((string)($_POST['employee_id'] ?? ''));
$leaveTypeId = trim((string)($_POST['leave_type_id'] ?? ''));
$startDate   = trim($_POST['start_date'] ?? '');
$endDate     = trim($_POST['end_date'] ?? '');
$totalDays   = trim($_POST['total_days'] ?? '');
$status      = trim($_POST['status'] ?? '');
$reason      = trim($_POST['reason'] ?? '');
$cancelledReason = trim($_POST['cancelled_reason'] ?? '');

$errors = [];

if ($employeeId === '' || !ctype_digit($employeeId)) {
    $errors[] = 'Please choose an employee.';
}
if ($leaveTypeId === '' || !ctype_digit($leaveTypeId)) {
    $errors[] = 'Please choose a leave type.';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $errors[] = 'Please enter a valid start date.';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $errors[] = 'Please enter a valid end date.';
}
if (!$errors && $endDate < $startDate) {
    $errors[] = 'End date must be on or after the start date.';
}
if ($totalDays === '' || !is_numeric($totalDays) || (float)$totalDays <= 0) {
    $errors[] = 'Please enter a valid, positive number of total days.';
}
if (!in_array($status, LEAVE_RECORD_STATUSES, true)) {
    $errors[] = 'Please choose a valid status.';
}
if ($status !== 'cancelled') {
    $cancelledReason = '';
}

$oldRecord = null;
if (!$errors && $isEdit) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT employee_id, leave_type_id, start_date FROM employee_leave_records WHERE leave_record_id = ?");
        $stmt->execute([$recordId]);
        $oldRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldRecord) {
            $errors[] = 'That leave record no longer exists.';
        }
    } catch (PDOException $e) {
        error_log('leave_record_save.php lookup failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this record. Please try again.';
    }
}

// An approved record's dates are what payroll actually pays leave_pay and
// tallies leave_balances.days_used against (runPayrollForEmployee(),
// recalculateLeaveBalance() below) -- two overlapping approved records for
// the same employee would double-count both, since neither dedupes by
// date. A cancelled record doesn't block anything: it isn't "using" those
// days. Only relevant when saving AS approved -- a record being cancelled
// here can't overlap-conflict with anything.
if (!$errors && $status === 'approved') {
    try {
        $pdo = Database::getInstance()->getConnection();
        $overlapStmt = $isEdit
            ? $pdo->prepare(
                "SELECT leave_record_id FROM employee_leave_records
                 WHERE employee_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ? AND leave_record_id != ?"
            )
            : $pdo->prepare(
                "SELECT leave_record_id FROM employee_leave_records
                 WHERE employee_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?"
            );
        $isEdit
            ? $overlapStmt->execute([$employeeId, $endDate, $startDate, $recordId])
            : $overlapStmt->execute([$employeeId, $endDate, $startDate]);
        if ($overlapStmt->fetch()) {
            $errors[] = 'This employee already has an approved leave record overlapping these dates.';
        }
    } catch (PDOException $e) {
        error_log('leave_record_save.php overlap check failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this record. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE employee_leave_records
                 SET employee_id = ?, leave_type_id = ?, start_date = ?, end_date = ?, total_days = ?,
                     reason = ?, cancelled_reason = ?, status = ?
                 WHERE leave_record_id = ?"
            );
            $stmt->execute([
                $employeeId, $leaveTypeId, $startDate, $endDate, (float)$totalDays,
                $reason !== '' ? $reason : null, $cancelledReason !== '' ? $cancelledReason : null, $status,
                $recordId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO employee_leave_records
                    (employee_id, leave_type_id, start_date, end_date, total_days, reason, cancelled_reason, status, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $employeeId, $leaveTypeId, $startDate, $endDate, (float)$totalDays,
                $reason !== '' ? $reason : null, $cancelledReason !== '' ? $cancelledReason : null, $status,
                Session::getUserId(),
            ]);
        }

        $newYear = (int)substr($startDate, 0, 4);
        recalculateLeaveBalance($pdo, (int)$employeeId, (int)$leaveTypeId, $newYear);

        if ($oldRecord) {
            $oldYear = (int)substr($oldRecord['start_date'], 0, 4);
            $sameCombo = (int)$oldRecord['employee_id'] === (int)$employeeId
                && (int)$oldRecord['leave_type_id'] === (int)$leaveTypeId
                && $oldYear === $newYear;
            if (!$sameCombo) {
                recalculateLeaveBalance($pdo, (int)$oldRecord['employee_id'], (int)$oldRecord['leave_type_id'], $oldYear);
            }
        }

        flash_set('success', $isEdit ? 'Leave record updated.' : 'Leave recorded.');

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
                $isEdit ? 'Update leave record' : 'Record leave',
                ($isEdit ? 'Updated' : 'Recorded') . " leave for {$empLabel}: {$startDate} to {$endDate}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('leave_record_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this record. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_record_modal'] = true;
}

header('Location: ' . $redirect);
exit;
