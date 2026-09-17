<?php
/**
 * employee_management/employee_save.php
 *
 * Create or update an employee record. Edit mode is triggered by a
 * non-empty `employee_id` field, matching admin/users_save.php's
 * create/edit convention. employee_number is never accepted from the
 * client — it's assigned server-side on create and immutable after.
 *
 * Also upserts this employee's biometric device link (see
 * upsertEmployeeBiometricId(), biometric_functions.php) in the same
 * transaction, since the Employee form is where "Biometric ID" is now
 * set — a duplicate-ID conflict there rolls back the whole save rather
 * than leaving the employee record saved without its link.
 *
 * The employee's shift and work days are saved here too, and their upcoming
 * employee_schedules rows are generated from them in the same transaction
 * (syncEmployeeSchedule(), payroll_functions.php) -- this form is the only
 * place a schedule is set, so an employee and their schedule commit or fail
 * together.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/payroll_functions.php';
require_once __DIR__ . '/includes/biometric_functions.php';

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

const GENDERS           = ['male', 'female', 'other'];
const CIVIL_STATUSES     = ['single', 'married', 'widowed', 'separated', 'other'];
const EMPLOYMENT_TYPES   = ['regular', 'probationary'];
const EMPLOYMENT_STATUSES = ['active', 'suspended', 'resigned', 'terminated'];
const SALARY_TYPES       = ['daily', 'monthly'];
const PAY_FREQUENCIES    = ['semi_monthly', 'monthly'];

function nullableInt($value): ?int
{
    $value = trim((string)($value ?? ''));
    return ($value !== '' && ctype_digit($value)) ? (int)$value : null;
}

function nullableStr($value): ?string
{
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : null;
}

$employeeId = trim((string)($_POST['employee_id'] ?? ''));
$isEdit     = $employeeId !== '' && ctype_digit($employeeId);

$firstName  = trim($_POST['first_name'] ?? '');
$middleName = nullableStr($_POST['middle_name'] ?? '');
$lastName   = trim($_POST['last_name'] ?? '');
$suffix     = nullableStr($_POST['suffix'] ?? '');
$birthDate  = nullableStr($_POST['birth_date'] ?? '');
$gender     = nullableStr($_POST['gender'] ?? '');
$civilStatus = nullableStr($_POST['civil_status'] ?? '');
$contactNumber = nullableStr($_POST['contact_number'] ?? '');
$email      = nullableStr($_POST['email'] ?? '');
$address    = nullableStr($_POST['address'] ?? '');

$departmentId = nullableInt($_POST['department_id'] ?? '');
$positionId   = nullableInt($_POST['position_id'] ?? '');
$employmentType   = trim($_POST['employment_type'] ?? '');
$employmentStatus = trim($_POST['employment_status'] ?? '');
$dateHired    = trim($_POST['date_hired'] ?? '');
$dateRegularized = nullableStr($_POST['date_regularized'] ?? '');

$salaryType   = trim($_POST['salary_type'] ?? '');
$basicRate    = trim($_POST['basic_rate'] ?? '');
$payFrequency = trim($_POST['pay_frequency'] ?? '');

$sssNumber       = nullableStr($_POST['sss_number'] ?? '');
$philhealthNumber = nullableStr($_POST['philhealth_number'] ?? '');
$pagibigNumber   = nullableStr($_POST['pagibig_number'] ?? '');
$tinNumber       = nullableStr($_POST['tin_number'] ?? '');
$biometricUserId = nullableStr($_POST['biometric_user_id'] ?? '');

$shiftId  = nullableInt($_POST['shift_id'] ?? '');
// Checkbox group: absent from the POST entirely when nothing is ticked.
// Anything that isn't one of the seven SCHEDULE_WEEKDAYS keys is dropped.
$workDays = array_values(array_intersect(
    array_column(SCHEDULE_WEEKDAYS, 'key'),
    array_map('strval', (array)($_POST['work_days'] ?? []))
));

// Separation fields only make sense once the employee is actually
// resigned/terminated — discard them silently otherwise rather than
// erroring, since the client hides these fields for other statuses.
$separationApplicable = in_array($employmentStatus, ['resigned', 'terminated'], true);
$dateSeparated    = $separationApplicable ? nullableStr($_POST['date_separated'] ?? '') : null;
$separationReason = $separationApplicable ? nullableStr($_POST['separation_reason'] ?? '') : null;

$errors = [];

if ($firstName === '' || mb_strlen($firstName) > 100) {
    $errors[] = 'Please enter a first name.';
}
if ($lastName === '' || mb_strlen($lastName) > 100) {
    $errors[] = 'Please enter a last name.';
}
if ($email !== null && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
    $errors[] = 'Please enter a valid email address, or leave it blank.';
}
if ($contactNumber !== null && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contactNumber)) {
    $errors[] = 'Please enter a valid contact number, or leave it blank.';
}
if ($gender !== null && !in_array($gender, GENDERS, true)) {
    $errors[] = 'Please choose a valid gender.';
}
if ($civilStatus !== null && !in_array($civilStatus, CIVIL_STATUSES, true)) {
    $errors[] = 'Please choose a valid civil status.';
}
if (!in_array($employmentType, EMPLOYMENT_TYPES, true)) {
    $errors[] = 'Please choose a valid employment type.';
}
if (!in_array($employmentStatus, EMPLOYMENT_STATUSES, true)) {
    $errors[] = 'Please choose a valid employment status.';
}
if ($dateHired === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateHired)) {
    $errors[] = 'Please enter a valid date hired.';
}
if (!in_array($salaryType, SALARY_TYPES, true)) {
    $errors[] = 'Please choose a valid salary type.';
}
if (!in_array($payFrequency, PAY_FREQUENCIES, true)) {
    $errors[] = 'Please choose a valid pay frequency.';
}
if ($basicRate === '' || !is_numeric($basicRate) || (float)$basicRate < 0) {
    $errors[] = 'Please enter a valid, non-negative pay rate.';
}
if ($shiftId === null) {
    $errors[] = 'Please choose a shift.';
}
if (!$workDays) {
    $errors[] = 'Please tick at least one work day.';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($departmentId !== null) {
            $chk = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ?");
            $chk->execute([$departmentId]);
            if (!$chk->fetch()) {
                $errors[] = 'That department no longer exists.';
            }
        }
        $chk = $pdo->prepare("SELECT start_time, end_time FROM shift_templates WHERE shift_id = ?");
        $chk->execute([$shiftId]);
        $shiftRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$shiftRow) {
            $errors[] = 'That shift no longer exists.';
        }
        // A position with rates defined is the source of truth for pay --
        // salary_type/pay_frequency/basic_rate below get overwritten from
        // this row (picking whichever rate matches this employee's own
        // employment_type) rather than trusted from the client, so a
        // disabled/locked field in the UI can't be bypassed by posting a
        // different value directly. No position assigned -> those three
        // fields stay exactly as submitted (free-form), same as before.
        $positionRow = null;
        if (!$errors && $positionId !== null) {
            $chk = $pdo->prepare(
                "SELECT salary_type, pay_frequency, regular_rate, probationary_rate
                 FROM positions WHERE position_id = ?"
            );
            $chk->execute([$positionId]);
            $positionRow = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$positionRow) {
                $errors[] = 'That position no longer exists.';
            }
        }
        if (!$errors && $positionRow !== null) {
            $rateColumn = [
                'regular' => 'regular_rate',
                'probationary' => 'probationary_rate',
            ][$employmentType];
            $salaryType = $positionRow['salary_type'];
            $payFrequency = $positionRow['pay_frequency'];
            $basicRate = $positionRow[$rateColumn];
        }
    } catch (PDOException $e) {
        error_log('employee_save.php lookup failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this employee. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();

        $fields = [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'suffix' => $suffix,
            'birth_date' => $birthDate,
            'gender' => $gender,
            'civil_status' => $civilStatus,
            'contact_number' => $contactNumber,
            'email' => $email,
            'address' => $address,
            'position_id' => $positionId,
            'department_id' => $departmentId,
            'employment_type' => $employmentType,
            'date_hired' => $dateHired,
            'date_regularized' => $dateRegularized,
            'employment_status' => $employmentStatus,
            'date_separated' => $dateSeparated,
            'separation_reason' => $separationReason,
            'salary_type' => $salaryType,
            'basic_rate' => (float)$basicRate,
            'pay_frequency' => $payFrequency,
            // Checkbox: absent from the POST entirely when unticked.
            'is_minimum_wage_earner' => isset($_POST['is_minimum_wage_earner']) ? 1 : 0,
            'sss_number' => $sssNumber,
            'philhealth_number' => $philhealthNumber,
            'pagibig_number' => $pagibigNumber,
            'tin_number' => $tinNumber,
            'shift_id' => $shiftId,
            'work_days' => implode(',', $workDays),
        ];

        // What the schedule was before this save, so the log can say when it
        // actually changed rather than on every edit.
        $previousSchedule = null;
        if ($isEdit) {
            $prev = $pdo->prepare("SELECT shift_id, work_days FROM employees WHERE employee_id = ?");
            $prev->execute([$employeeId]);
            $previousSchedule = $prev->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($isEdit) {
            $setSql = implode(', ', array_map(fn($col) => "$col = ?", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE employees SET $setSql WHERE employee_id = ?");
            $stmt->execute([...array_values($fields), $employeeId]);
            $logAction = 'Update employee';
        } else {
            $fields['employee_number'] = generateEmployeeNumber($pdo);
            $columns = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = $pdo->prepare("INSERT INTO employees ($columns) VALUES ($placeholders)");
            $stmt->execute(array_values($fields));
            $employeeId = $pdo->lastInsertId();
            $logAction = 'Create employee';
        }

        $biometricError = upsertEmployeeBiometricId($pdo, (int)$employeeId, $biometricUserId);

        if ($biometricError !== null) {
            $pdo->rollBack();
            $errors[] = $biometricError;
        } else {
            // Inside the transaction on purpose: if generating the schedule
            // fails, the employee save rolls back with it.
            $sync = syncEmployeeSchedule($pdo, (int)$employeeId, Session::getUserId());

            $scheduleSummary = formatTimeOfDay($shiftRow['start_time']) . '–' . formatTimeOfDay($shiftRow['end_time']) . ', ' . describeWeekdays($workDays);
            $scheduleChanged = !$isEdit
                || $previousSchedule === null
                || (int)$previousSchedule['shift_id'] !== $shiftId
                || parseEmployeeWorkDays($previousSchedule['work_days']) !== $workDays;

            try {
                $log = $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Employees', ?, ?, ?)"
                );
                $log->execute([
                    Session::getUserId(),
                    $logAction,
                    "{$logAction}: {$firstName} {$lastName}" . ($scheduleChanged ? " (schedule: {$scheduleSummary})" : ''),
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }

            $pdo->commit();

            $message = $isEdit ? 'Employee updated.' : 'Employee created.';
            if (!$isEdit && $sync['created'] > 0) {
                $message .= ' Their schedule repeats every week until you change it.';
            } elseif ($sync['created'] + $sync['updated'] + $sync['removed'] > 0) {
                // e.g. "Upcoming schedule: 26 days changed." / "61 days removed."
                $parts = [];
                foreach (['created' => 'added', 'updated' => 'changed', 'removed' => 'removed'] as $key => $verb) {
                    if ($sync[$key] > 0) {
                        $parts[] = $sync[$key] . ' ' . ($sync[$key] === 1 ? 'day' : 'days') . ' ' . $verb;
                    }
                }
                $message .= ' Upcoming schedule: ' . implode(', ', $parts) . '.';
            }
            flash_set('success', $message);
        }
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('employee_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this employee. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_employee_modal'] = true;
}

header('Location: employees.php');
exit;
