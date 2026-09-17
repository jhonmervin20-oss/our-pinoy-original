<?php
/**
 * employee_management/shift_template_save.php
 *
 * Create or update a shift -- just a start and end time; shifts have no
 * name (shift_templates.shift_name was dropped on 2026-09-15), so two shifts
 * with the same times would be indistinguishable and are refused. Managed
 * entirely through the "Manage shifts" modal on employees.php (no standalone
 * page) -- a shift is what an employee's work schedule is built from, so it
 * lives beside that form.
 *
 * Editing a shift's times re-syncs every employee's upcoming schedule
 * (syncAllEmployeeSchedules()), so days not worked yet move to the new
 * times. Days that already have attendance keep the times they were worked
 * against.
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
$isEdit  = $shiftId !== '' && ctype_digit($shiftId);

$startTime    = trim($_POST['start_time'] ?? '');
$endTime      = trim($_POST['end_time'] ?? '');

$errors = [];

if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) {
    $errors[] = 'Please enter a valid start time.';
}
if (!preg_match('/^\d{2}:\d{2}$/', $endTime)) {
    $errors[] = 'Please enter a valid end time.';
}
if (!$errors && $startTime === $endTime) {
    $errors[] = 'The end time must be different from the start time.';
}

// Derived, never accepted from the client -- a shift crosses midnight
// exactly when its end time is earlier than its (zero-padded) start
// time, the same rule combineDateTime() uses elsewhere for attendance
// clock times. Removes a checkbox that used to require the manager to
// separately remember to flag this instead of it just being a fact
// about the two times already entered.
$isNightShift = (!$errors && $endTime < $startTime) ? 1 : 0;

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $label = formatTimeOfDay($startTime . ':00') . ' – ' . formatTimeOfDay($endTime . ':00');
        $dup = $pdo->prepare("SELECT COUNT(*) FROM shift_templates WHERE start_time = ? AND end_time = ? AND shift_id <> ?");
        $dup->execute([$startTime, $endTime, $isEdit ? (int)$shiftId : 0]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new DomainException("There is already a shift for {$label}.");
        }

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE shift_templates SET start_time = ?, end_time = ?, is_night_shift = ? WHERE shift_id = ?"
            );
            $stmt->execute([$startTime, $endTime, $isNightShift, $shiftId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO shift_templates (start_time, end_time, is_night_shift) VALUES (?, ?, ?)"
            );
            $stmt->execute([$startTime, $endTime, $isNightShift]);
        }

        $message = $isEdit ? 'Shift updated.' : 'Shift created.';
        if ($isEdit) {
            $sync = syncAllEmployeeSchedules($pdo);
            if ($sync['updated'] > 0) {
                $message .= " {$sync['updated']} upcoming schedule " . ($sync['updated'] === 1 ? 'day' : 'days') . ' moved to the new times.';
            }
        }
        flash_set('success', $message);

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Schedules', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update shift' : 'Create shift',
                ($isEdit ? 'Updated' : 'Created') . " shift: {$label}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (DomainException $e) {
        $errors[] = $e->getMessage();
    } catch (PDOException $e) {
        error_log('shift_template_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this shift. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_shift_modal'] = true;
}

header('Location: employees.php');
exit;
