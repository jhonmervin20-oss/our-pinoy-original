<?php
/**
 * employee_management/employee_toggle_status.php
 *
 * Flip an employee's is_active flag. Soft toggle only, never a DELETE —
 * matches the is_active convention used everywhere else in this app
 * (inventory, suppliers, users). Archiving an employee excludes them from
 * active payroll but keeps their attendance/payslip history intact.
 *
 * Their upcoming schedule follows in the same transaction
 * (syncEmployeeSchedule()): archiving clears the future days nobody has
 * worked yet, so the absence sweep never marks an archived employee absent;
 * restoring regenerates them from the employee's shift and work days.
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
// Creating/editing/archiving an employee is Admin-only now -- the Manager's
// access to Employees is read-only. See includes/em_access.php.
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: employees.php');
    exit;
}

$employeeId = trim((string)($_POST['employee_id'] ?? ''));

if ($employeeId === '' || !ctype_digit($employeeId)) {
    flash_set('error', 'Invalid employee.');
    header('Location: employees.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT first_name, last_name, is_active FROM employees WHERE employee_id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        flash_set('error', 'That employee no longer exists.');
    } else {
        $newStatus = (int)$employee['is_active'] === 1 ? 0 : 1;

        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE employees SET is_active = ? WHERE employee_id = ?");
        $upd->execute([$newStatus, $employeeId]);
        $sync = syncEmployeeSchedule($pdo, (int)$employeeId, Session::getUserId());
        $pdo->commit();

        try {
            $log = $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Employees', ?, ?, ?)"
            );
            $log->execute([
                Session::getUserId(),
                $newStatus === 1 ? 'Restore employee' : 'Archive employee',
                ($newStatus === 1 ? 'Restored ' : 'Archived ') . "{$employee['first_name']} {$employee['last_name']}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual toggle on it.
        }

        if ($newStatus === 1) {
            $message = 'Employee restored.' . ($sync['created'] > 0
                ? ' Their weekly schedule is back on.'
                : '');
        } else {
            $message = 'Employee archived.' . ($sync['removed'] > 0
                ? " {$sync['removed']} upcoming schedule " . ($sync['removed'] === 1 ? 'day' : 'days') . ' removed.'
                : '');
        }
        flash_set('success', $message);
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('employee_toggle_status.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating this employee. Please try again.');
}

header('Location: employees.php');
exit;
