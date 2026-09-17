<?php
/**
 * employee_management/leave_record_delete.php
 *
 * Permanently deletes an already-cancelled leave record. Only ever allowed
 * on status='cancelled' rows -- an approved record must be cancelled first
 * (leave_record_cancel.php), never deleted outright, so there's always an
 * explicit cancel step before anything is actually removed. No balance
 * recalculation needed: recalculateLeaveBalance() only ever sums
 * status='approved' rows, so a cancelled record already contributes 0 to
 * days_used and deleting it changes nothing there. Nothing else in the
 * schema has a foreign key to employee_leave_records, so this is a plain
 * delete, no cascade concerns.
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

if ($recordId === '' || !ctype_digit($recordId)) {
    flash_set('error', 'Invalid leave record.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT status FROM employee_leave_records WHERE leave_record_id = ?");
    $stmt->execute([$recordId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        flash_set('error', 'That leave record no longer exists.');
    } elseif ($status !== 'cancelled') {
        flash_set('error', 'Only a cancelled leave record can be deleted -- cancel it first.');
    } else {
        $pdo->prepare("DELETE FROM employee_leave_records WHERE leave_record_id = ?")->execute([$recordId]);
        flash_set('success', 'Leave record deleted.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Leave', 'Delete leave record', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Deleted leave record #{$recordId}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('leave_record_delete.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this record. Please try again.');
}

header('Location: ' . $redirect);
exit;
