<?php
/**
 * employee_management/position_save.php
 *
 * Create or update a position. Edit mode is triggered by a non-empty
 * `position_id` field. Positions are managed on their own page,
 * employee_management/departments_positions.php (a Departments/Positions tab each) --
 * pulled out of what used to be a modal on employees.php so org-structure
 * management has real room instead of competing with the Add/Edit
 * Employee form.
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
// Departments & Positions moved to the Admin panel -- the Manager has no
// access to this area at all any more. See includes/em_access.php.
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$redirect = 'departments_positions.php?tab=positions';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$positionId = trim((string)($_POST['position_id'] ?? ''));
$isEdit = $positionId !== '' && ctype_digit($positionId);

$title = trim($_POST['position_title'] ?? '');
$departmentId = trim((string)($_POST['department_id'] ?? ''));
$departmentId = ($departmentId !== '' && ctype_digit($departmentId)) ? (int)$departmentId : null;
$salaryType = trim($_POST['salary_type'] ?? '');
$payFrequency = trim($_POST['pay_frequency'] ?? '');
$isActive = isset($_POST['is_active']) ? 1 : 0;

// One rate per employment type -- a position no longer has a single rate
// that applies to every employee in it. Employees assigned to this
// position get whichever of these two matches their own employment_type
// (see employee_save.php), with no per-employee override once a position
// is chosen.
$RATE_FIELDS = ['regular_rate', 'probationary_rate'];
$rates = [];
foreach ($RATE_FIELDS as $field) {
    $rates[$field] = trim($_POST[$field] ?? '');
}

$errors = [];

if ($title === '' || mb_strlen($title) > 100) {
    $errors[] = 'Please enter a position title.';
}
if (!in_array($salaryType, ['daily', 'monthly'], true)) {
    $errors[] = 'Please choose a valid salary type.';
}
if (!in_array($payFrequency, ['semi_monthly', 'monthly'], true)) {
    $errors[] = 'Please choose a valid pay frequency.';
}
foreach ($rates as $field => $value) {
    if ($value === '' || !is_numeric($value) || (float)$value < 0) {
        $errors[] = 'Please enter a valid, non-negative rate for every employment type.';
        break;
    }
}

if (!$errors && $departmentId !== null) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $chk = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ?");
        $chk->execute([$departmentId]);
        if (!$chk->fetch()) {
            $errors[] = 'That department no longer exists.';
        }
    } catch (PDOException $e) {
        error_log('position_save.php department lookup failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this position. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE positions SET position_title = ?, department_id = ?, salary_type = ?, pay_frequency = ?,
                                       regular_rate = ?, probationary_rate = ?, is_active = ?
                 WHERE position_id = ?"
            );
            $stmt->execute([
                $title, $departmentId, $salaryType, $payFrequency,
                (float)$rates['regular_rate'], (float)$rates['probationary_rate'],
                $isActive, $positionId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO positions (position_title, department_id, salary_type, pay_frequency, regular_rate, probationary_rate, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $title, $departmentId, $salaryType, $payFrequency,
                (float)$rates['regular_rate'], (float)$rates['probationary_rate'],
                $isActive,
            ]);
        }

        flash_set('success', $isEdit ? 'Position updated.' : 'Position created.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Departments & Positions', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update position' : 'Create position',
                ($isEdit ? 'Updated' : 'Created') . " position: {$title}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('position_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this position. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
}

header('Location: ' . $redirect);
exit;
