<?php
/**
 * employee_management/leave_type_delete.php
 *
 * Permanently deletes a leave type -- no archive step, matching
 * shift_template_delete.php's pattern. Blocked if still in use:
 * employee_leave_records.leave_type_id has no ON DELETE clause (defaults
 * to RESTRICT) so the DB would already refuse it, but employee_leave_balances
 * has ON DELETE CASCADE, which would silently wipe balance history instead
 * of erroring. Both are checked explicitly first so this is always a
 * friendly message, never a raw SQL error or a silent data loss.
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

if ($leaveTypeId === '' || !ctype_digit($leaveTypeId)) {
    flash_set('error', 'Invalid leave type.');
    header('Location: leave.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT leave_name FROM leave_types WHERE leave_type_id = ?");
    $stmt->execute([$leaveTypeId]);
    $leaveType = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$leaveType) {
        flash_set('error', 'That leave type no longer exists.');
    } else {
        $inUse = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM employee_leave_records WHERE leave_type_id = ?) AS record_count,
                (SELECT COUNT(*) FROM employee_leave_balances WHERE leave_type_id = ?) AS balance_count"
        );
        $inUse->execute([$leaveTypeId, $leaveTypeId]);
        $counts = $inUse->fetch(PDO::FETCH_ASSOC);

        if ((int)$counts['record_count'] > 0 || (int)$counts['balance_count'] > 0) {
            flash_set('error', "Can't delete \"{$leaveType['leave_name']}\" — it still has leave records or balances tied to it.");
        } else {
            $pdo->prepare("DELETE FROM leave_types WHERE leave_type_id = ?")->execute([$leaveTypeId]);
            flash_set('success', "Leave type \"{$leaveType['leave_name']}\" deleted.");

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Leave', 'Delete leave type', ?, ?)"
                )->execute([
                    Session::getUserId(),
                    "Deleted leave type: {$leaveType['leave_name']}",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }
        }
    }
} catch (PDOException $e) {
    error_log('leave_type_delete.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this leave type. Please try again.');
}

header('Location: leave.php');
exit;
