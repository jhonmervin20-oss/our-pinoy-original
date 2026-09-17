<?php
/**
 * employee_management/settings_save.php
 *
 * Updates the single payroll_settings row. There is always exactly one
 * row (seeded, never created/deleted from the UI) so this is always an
 * UPDATE, never an INSERT.
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
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: settings.php');
    exit;
}

function intField($value): ?int
{
    $value = trim((string)($value ?? ''));
    return ($value !== '' && ctype_digit($value)) ? (int)$value : null;
}

function decimalField($value): ?float
{
    $value = trim((string)($value ?? ''));
    return ($value !== '' && is_numeric($value)) ? (float)$value : null;
}

$dayFields = [
    'semi_monthly_cutoff1_start_day', 'semi_monthly_cutoff1_end_day', 'semi_monthly_cutoff1_payout_day',
    'semi_monthly_cutoff2_start_day', 'semi_monthly_cutoff2_payout_day',
    'monthly_cutoff_start_day', 'monthly_payout_day',
];
$values = [];
foreach ($dayFields as $f) {
    $values[$f] = intField($_POST[$f] ?? '');
}
$values['semi_monthly_cutoff2_end_day'] = intField($_POST['semi_monthly_cutoff2_end_day'] ?? '');
$values['monthly_cutoff_end_day']       = intField($_POST['monthly_cutoff_end_day'] ?? '');
$values['semi_monthly_cutoff2_payout_next_month'] = isset($_POST['semi_monthly_cutoff2_payout_next_month']) ? 1 : 0;
$values['monthly_payout_next_month']              = isset($_POST['monthly_payout_next_month']) ? 1 : 0;

$values['late_grace_period_minutes']      = intField($_POST['late_grace_period_minutes'] ?? '');
$values['undertime_grace_period_minutes'] = intField($_POST['undertime_grace_period_minutes'] ?? '');
$values['attendance_import_cutoff_hours'] = intField($_POST['attendance_import_cutoff_hours'] ?? '');
$values['standard_work_hours_per_day']    = decimalField($_POST['standard_work_hours_per_day'] ?? '');
$values['default_break_minutes']          = intField($_POST['default_break_minutes'] ?? '');
$values['annual_working_days_divisor']    = decimalField($_POST['annual_working_days_divisor'] ?? '');

$multiplierFields = [
    'overtime_multiplier', 'rest_day_multiplier', 'rest_day_ot_multiplier',
    'regular_holiday_multiplier', 'regular_holiday_ot_multiplier',
    'regular_holiday_restday_multiplier', 'regular_holiday_restday_ot_multiplier',
    'special_holiday_multiplier', 'special_holiday_ot_multiplier',
    'special_holiday_restday_multiplier', 'special_holiday_restday_ot_multiplier',
];
foreach ($multiplierFields as $f) {
    $values[$f] = decimalField($_POST[$f] ?? '');
}

$nightDiffPercent = decimalField($_POST['night_differential_rate_percent'] ?? '');
$values['night_differential_rate']  = $nightDiffPercent !== null ? $nightDiffPercent / 100 : null;
$values['night_differential_start'] = trim($_POST['night_differential_start'] ?? '');
$values['night_differential_end']   = trim($_POST['night_differential_end'] ?? '');

$values['enable_government_contributions'] = isset($_POST['enable_government_contributions']) ? 1 : 0;

$errors = [];

foreach ($dayFields as $f) {
    if ($values[$f] === null || $values[$f] < 1 || $values[$f] > 31) {
        $errors[] = 'Please enter valid day-of-month values (1–31) for all cutoff/payout day fields.';
        break;
    }
}
if ($values['semi_monthly_cutoff2_end_day'] === null || $values['semi_monthly_cutoff2_end_day'] < 0 || $values['semi_monthly_cutoff2_end_day'] > 31) {
    $errors[] = 'Please enter a valid 2nd cutoff end day (0–31, 0 = last day of month).';
}
if ($values['monthly_cutoff_end_day'] === null || $values['monthly_cutoff_end_day'] < 0 || $values['monthly_cutoff_end_day'] > 31) {
    $errors[] = 'Please enter a valid monthly cutoff end day (0–31, 0 = last day of month).';
}

// These raw day-of-month settings become the literal BETWEEN start/end
// dates runPayrollForEmployee() pulls attendance from
// (buildPayrollCutoffCandidatesForMonth() in payroll_run_functions.php) --
// an inverted cutoff (start > end) makes that BETWEEN empty, silently
// producing a payroll run with zero attendance for everyone; an
// overlapping cutoff1/cutoff2 pulls the same days into both semi-monthly
// runs, double-paying them. 0 always means "last day of month" here,
// which is always >= any 1-31 start day, so those checks are skipped
// when the end day is 0 rather than compared as a literal 0.
if (!$errors) {
    if ($values['semi_monthly_cutoff1_start_day'] > $values['semi_monthly_cutoff1_end_day']) {
        $errors[] = 'The 1st semi-monthly cutoff\'s start day must be on or before its end day.';
    }
    if ($values['semi_monthly_cutoff2_end_day'] !== 0
        && $values['semi_monthly_cutoff2_start_day'] > $values['semi_monthly_cutoff2_end_day']) {
        $errors[] = 'The 2nd semi-monthly cutoff\'s start day must be on or before its end day (or 0 for "last day of month").';
    }
    if ($values['semi_monthly_cutoff1_end_day'] >= $values['semi_monthly_cutoff2_start_day']) {
        $errors[] = 'The 2nd semi-monthly cutoff must start after the 1st cutoff ends -- overlapping cutoffs would pay the same attendance days twice.';
    }
    if ($values['monthly_cutoff_end_day'] !== 0
        && $values['monthly_cutoff_start_day'] > $values['monthly_cutoff_end_day']) {
        $errors[] = 'The monthly cutoff\'s start day must be on or before its end day (or 0 for "last day of month").';
    }
}

if ($values['late_grace_period_minutes'] === null || $values['late_grace_period_minutes'] < 0) {
    $errors[] = 'Please enter a valid late grace period.';
}
if ($values['undertime_grace_period_minutes'] === null || $values['undertime_grace_period_minutes'] < 0) {
    $errors[] = 'Please enter a valid undertime grace period.';
}
if ($values['attendance_import_cutoff_hours'] === null || $values['attendance_import_cutoff_hours'] < 0) {
    $errors[] = 'Please enter a valid attendance import cutoff.';
}
if ($values['standard_work_hours_per_day'] === null || $values['standard_work_hours_per_day'] <= 0) {
    $errors[] = 'Please enter valid standard work hours per day.';
}
if ($values['default_break_minutes'] === null || $values['default_break_minutes'] < 0) {
    $errors[] = 'Please enter a valid default break duration.';
}
if ($values['annual_working_days_divisor'] === null || $values['annual_working_days_divisor'] <= 0) {
    $errors[] = 'Please enter a valid annual working days divisor.';
}
foreach ($multiplierFields as $f) {
    if ($values[$f] === null || $values[$f] < 1) {
        $errors[] = 'Please enter valid multipliers (1.00 or higher) for overtime/holiday/rest-day rules.';
        break;
    }
}
if ($nightDiffPercent === null || $nightDiffPercent < 0 || $nightDiffPercent > 100) {
    $errors[] = 'Please enter a valid night differential rate (0–100%).';
}
if (!preg_match('/^\d{2}:\d{2}$/', $values['night_differential_start']) || !preg_match('/^\d{2}:\d{2}$/', $values['night_differential_end'])) {
    $errors[] = 'Please enter valid night differential start/end times.';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $row = $pdo->query("SELECT payroll_setting_id FROM payroll_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $errors[] = 'No payroll settings row exists to update.';
        } else {
            $setSql = implode(', ', array_map(fn($col) => "$col = ?", array_keys($values)));
            $stmt = $pdo->prepare("UPDATE payroll_settings SET $setSql, updated_by = ? WHERE payroll_setting_id = ?");
            $stmt->execute([...array_values($values), Session::getUserId(), $row['payroll_setting_id']]);

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Payroll Settings', 'Update payroll settings', 'Updated payroll settings configuration', ?)"
                )->execute([
                    Session::getUserId(),
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort.
            }

            flash_set('success', 'Payroll settings updated.');
        }
    } catch (PDOException $e) {
        error_log('settings_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving payroll settings. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
}

header('Location: settings.php');
exit;
