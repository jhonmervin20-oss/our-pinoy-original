<?php
/**
 * employee_management/department_toggle_status.php
 *
 * Flip a department's is_active flag. Soft toggle only — deactivating a
 * department just removes it from the "active departments" dropdown used
 * when adding/editing employees and positions; existing links are kept.
 * Managed from employee_management/departments_positions.php.
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

if ($departmentId === '' || !ctype_digit($departmentId)) {
    flash_set('error', 'Invalid department.');
    header('Location: departments_positions.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT department_name, is_active FROM departments WHERE department_id = ?");
    $stmt->execute([$departmentId]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$department) {
        flash_set('error', 'That department no longer exists.');
    } else {
        $newStatus = (int)$department['is_active'] === 1 ? 0 : 1;
        $upd = $pdo->prepare("UPDATE departments SET is_active = ? WHERE department_id = ?");
        $upd->execute([$newStatus, $departmentId]);

        flash_set('success', $newStatus === 1 ? 'Department activated.' : 'Department deactivated.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Departments & Positions', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $newStatus === 1 ? 'Activate department' : 'Deactivate department',
                ($newStatus === 1 ? 'Activated' : 'Deactivated') . " department: {$department['department_name']}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('department_toggle_status.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating this department. Please try again.');
}

header('Location: departments_positions.php');
exit;
