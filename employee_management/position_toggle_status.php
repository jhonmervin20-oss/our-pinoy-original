<?php
/**
 * employee_management/position_toggle_status.php
 *
 * Flip a position's is_active flag. Soft toggle only — deactivating a
 * position just removes it from the "active positions" dropdown used
 * when adding/editing employees; existing links are kept. Managed from
 * employee_management/departments_positions.php.
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

if ($positionId === '' || !ctype_digit($positionId)) {
    flash_set('error', 'Invalid position.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT position_title, is_active FROM positions WHERE position_id = ?");
    $stmt->execute([$positionId]);
    $position = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$position) {
        flash_set('error', 'That position no longer exists.');
    } else {
        $newStatus = (int)$position['is_active'] === 1 ? 0 : 1;
        $upd = $pdo->prepare("UPDATE positions SET is_active = ? WHERE position_id = ?");
        $upd->execute([$newStatus, $positionId]);

        flash_set('success', $newStatus === 1 ? 'Position activated.' : 'Position deactivated.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Departments & Positions', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $newStatus === 1 ? 'Activate position' : 'Deactivate position',
                ($newStatus === 1 ? 'Activated' : 'Deactivated') . " position: {$position['position_title']}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('position_toggle_status.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating this position. Please try again.');
}

header('Location: ' . $redirect);
exit;
