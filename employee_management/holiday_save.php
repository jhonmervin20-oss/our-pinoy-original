<?php
/**
 * employee_management/holiday_save.php
 *
 * Create or update a holiday. holidays has a UNIQUE(holiday_date)
 * constraint (one holiday per calendar date), checked explicitly here for
 * a friendly error.
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

const HOLIDAY_TYPES = ['regular', 'special'];

$holidayId = trim((string)($_POST['holiday_id'] ?? ''));
$isEdit    = $holidayId !== '' && ctype_digit($holidayId);

$date = trim($_POST['holiday_date'] ?? '');
$type = trim($_POST['holiday_type'] ?? '');
$name = trim($_POST['holiday_name'] ?? '');

$errors = [];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = 'Please enter a valid date.';
}
if (!in_array($type, HOLIDAY_TYPES, true)) {
    $errors[] = 'Please choose a valid holiday type.';
}
if ($name === '' || mb_strlen($name) > 100) {
    $errors[] = 'Please enter a holiday name (up to 100 characters).';
}

// The redirect year should follow whichever year the saved holiday actually falls in.
if (!$errors) {
    $redirectParams['year'] = substr($date, 0, 4);
    $redirect = 'holidays.php?' . http_build_query($redirectParams);
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $dupCheck = $isEdit
            ? $pdo->prepare("SELECT holiday_id FROM holidays WHERE holiday_date = ? AND holiday_id != ?")
            : $pdo->prepare("SELECT holiday_id FROM holidays WHERE holiday_date = ?");
        $isEdit ? $dupCheck->execute([$date, $holidayId]) : $dupCheck->execute([$date]);

        if ($dupCheck->fetch()) {
            $errors[] = 'A holiday is already set for that date. Edit the existing one instead.';
        } else {
            if ($isEdit) {
                $stmt = $pdo->prepare("UPDATE holidays SET holiday_date = ?, holiday_name = ?, holiday_type = ? WHERE holiday_id = ?");
                $stmt->execute([$date, $name, $type, $holidayId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO holidays (holiday_date, holiday_name, holiday_type) VALUES (?, ?, ?)");
                $stmt->execute([$date, $name, $type]);
            }

            flash_set('success', $isEdit ? 'Holiday updated.' : 'Holiday added.');

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Holidays', ?, ?, ?)"
                )->execute([
                    Session::getUserId(),
                    $isEdit ? 'Update holiday' : 'Add holiday',
                    ($isEdit ? 'Updated' : 'Added') . " holiday: {$name} ({$date})",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }
        }
    } catch (PDOException $e) {
        error_log('holiday_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this holiday. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_holiday_modal'] = true;
}

header('Location: ' . $redirect);
exit;
