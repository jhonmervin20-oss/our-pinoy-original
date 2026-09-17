<?php
/**
 * employee_management/leave_record_cancel.php
 *
 * Deletes an approved leave record: at the client's request, this is now a
 * single action (the "Delete" trash-icon button on leave.php's Leave Records
 * table) that both cancels AND permanently removes the record in one step,
 * rather than the old two-step "cancel now, delete the cancelled record
 * later" flow. There is no more lingering status='cancelled' row to show a
 * Status column for -- every record left in the table is implicitly
 * approved, which is also why leave.php no longer shows a Status column at
 * all. recalculateLeaveBalance() only ever sums status='approved' rows, so
 * running it AFTER the delete correctly excludes this record from
 * days_used with no intermediate state needed.
 *
 * Kept as its own endpoint (route name unchanged) rather than folded into
 * leave_record_save.php, same reasoning as before: leave.php's Add/Edit
 * modal never exposes a Status control, so this stays the one place a
 * record's fate actually changes.
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

$recordId = trim((string)($_POST['leave_record_id'] ?? ''));
$deleteReason = trim((string)($_POST['cancelled_reason'] ?? ''));

if ($recordId === '' || !ctype_digit($recordId)) {
    flash_set('error', 'Invalid leave record.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT employee_id, leave_type_id, start_date, status FROM employee_leave_records WHERE leave_record_id = ?");
    $stmt->execute([$recordId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        flash_set('error', 'That leave record no longer exists.');
    } elseif ($record['status'] !== 'approved') {
        flash_set('error', 'That leave record was already deleted.');
    } else {
        $pdo->prepare("DELETE FROM employee_leave_records WHERE leave_record_id = ?")->execute([$recordId]);

        recalculateLeaveBalance($pdo, (int)$record['employee_id'], (int)$record['leave_type_id'], (int)substr($record['start_date'], 0, 4));

        flash_set('success', 'Leave record deleted.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Leave', 'Delete leave record', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Deleted leave record #{$recordId}" . ($deleteReason !== '' ? " ({$deleteReason})" : ''),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('leave_record_cancel.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this record. Please try again.');
}

header('Location: ' . $redirect);
exit;
