<?php
/**
 * employee_management/holiday_toggle_status.php
 *
 * Flip a holiday's is_active flag. Soft toggle only — archiving a holiday
 * excludes it from future payroll holiday-pay lookups but keeps the
 * historical record.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/em_access.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
// Holidays moved to the Admin on 2026-09-15 -- see includes/em_access.php.
if (!emCanManageHolidays()) {
    header('Location: ../auth/login.php');
    exit;
}

$returnYear = trim((string)($_POST['return_year'] ?? ''));
$returnStatus = trim((string)($_POST['return_status'] ?? ''));
$redirectParams = [];
if (preg_match('/^\d{4}$/', $returnYear)) {
    $redirectParams['year'] = $returnYear;
}
if (in_array($returnStatus, ['active', 'archived', 'all'], true)) {
    $redirectParams['status'] = $returnStatus;
}
$redirect = 'holidays.php' . ($redirectParams ? '?' . http_build_query($redirectParams) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$holidayId = trim((string)($_POST['holiday_id'] ?? ''));

if ($holidayId === '' || !ctype_digit($holidayId)) {
    flash_set('error', 'Invalid holiday.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT holiday_name, is_active FROM holidays WHERE holiday_id = ?");
    $stmt->execute([$holidayId]);
    $holiday = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$holiday) {
        flash_set('error', 'That holiday no longer exists.');
    } else {
        $newStatus = (int)$holiday['is_active'] === 1 ? 0 : 1;
        $upd = $pdo->prepare("UPDATE holidays SET is_active = ? WHERE holiday_id = ?");
        $upd->execute([$newStatus, $holidayId]);

        flash_set('success', $newStatus === 1 ? 'Holiday activated.' : 'Holiday deactivated.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Holidays', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $newStatus === 1 ? 'Activate holiday' : 'Deactivate holiday',
                ($newStatus === 1 ? 'Activated' : 'Deactivated') . " holiday: {$holiday['holiday_name']}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('holiday_toggle_status.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating this holiday. Please try again.');
}

header('Location: ' . $redirect);
exit;
