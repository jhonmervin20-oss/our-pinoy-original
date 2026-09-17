<?php
/**
 * employee_management/department_save.php
 *
 * Create or update a department. Edit mode is triggered by a non-empty
 * `department_id` field. Departments are managed on their own page,
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: departments_positions.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: departments_positions.php');
    exit;
}

$departmentId = trim((string)($_POST['department_id'] ?? ''));
$isEdit = $departmentId !== '' && ctype_digit($departmentId);

$name = trim($_POST['department_name'] ?? '');
$isActive = isset($_POST['is_active']) ? 1 : 0;

$errors = [];

if ($name === '' || mb_strlen($name) > 100) {
    $errors[] = 'Please enter a department name.';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $dupCheck = $isEdit
            ? $pdo->prepare("SELECT department_id FROM departments WHERE department_name = ? AND department_id != ?")
            : $pdo->prepare("SELECT department_id FROM departments WHERE department_name = ?");
        $isEdit ? $dupCheck->execute([$name, $departmentId]) : $dupCheck->execute([$name]);

        if ($dupCheck->fetch()) {
            $errors[] = 'A department with that name already exists.';
        } else {
            if ($isEdit) {
                $stmt = $pdo->prepare("UPDATE departments SET department_name = ?, is_active = ? WHERE department_id = ?");
                $stmt->execute([$name, $isActive, $departmentId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO departments (department_name, is_active) VALUES (?, ?)");
                $stmt->execute([$name, $isActive]);
            }

            flash_set('success', $isEdit ? 'Department updated.' : 'Department created.');

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Departments & Positions', ?, ?, ?)"
                )->execute([
                    Session::getUserId(),
                    $isEdit ? 'Update department' : 'Create department',
                    ($isEdit ? 'Updated' : 'Created') . " department: {$name}",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }
        }
    } catch (PDOException $e) {
        error_log('department_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this department. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
}

header('Location: departments_positions.php');
exit;
