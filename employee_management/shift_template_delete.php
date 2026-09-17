<?php
/**
 * employee_management/shift_template_delete.php
 *
 * Permanently deletes a shift -- no archive step, this table has no
 * is_active column (removed on purpose: shifts are a small, hands-on-managed
 * lookup list, not something worth soft-deleting). Posted from the "Manage
 * shifts" modal on employees.php.
 *
 * Refused while any employee (archived ones included) still has this as
 * their shift: their schedule is generated from it, and
 * employees.fk_employee_shift would reject the DELETE anyway -- this just
 * says so readably. Once nobody holds it, past employee_schedules rows
 * survive the delete (their scheduled_time_in/out are their own stored
 * columns; employee_schedules.shift_id is ON DELETE SET NULL) and only lose
 * the shift name/night-shift badge in the grid.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/payroll_functions.php';   // formatTimeOfDay() for the activity log

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
// Shifts and employee work schedules are Admin-only -- the Manager's access
// to Employees and Schedules is read-only. See includes/em_access.php.
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

$shiftId = trim((string)($_POST['shift_id'] ?? ''));

if ($shiftId === '' || !ctype_digit($shiftId)) {
    flash_set('error', 'Invalid shift.');
    header('Location: employees.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT start_time, end_time FROM shift_templates WHERE shift_id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        flash_set('error', 'That shift no longer exists.');
    } else {
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE shift_id = ?");
        $inUse->execute([$shiftId]);
        $holders = (int)$inUse->fetchColumn();

        if ($holders > 0) {
            flash_set('error', "This shift is still assigned to {$holders} " . ($holders === 1 ? 'employee' : 'employees') . ' (archived ones included). Give them a different shift first.');
        } else {
            $pdo->prepare("DELETE FROM shift_templates WHERE shift_id = ?")->execute([$shiftId]);
            flash_set('success', 'Shift deleted.');

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Schedules', 'Delete shift', ?, ?)"
                )->execute([
                    Session::getUserId(),
                    'Deleted shift: ' . formatTimeOfDay($shift['start_time']) . ' – ' . formatTimeOfDay($shift['end_time']),
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }
        }
    }
} catch (PDOException $e) {
    error_log('shift_template_delete.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this shift. Please try again.');
}

header('Location: employees.php');
exit;
