<?php
/**
 * employee_management/pagibig_bracket_delete.php
 *
 * Deletes a Pag-IBIG contribution bracket. Nothing references
 * pagibig_bracket_id by foreign key -- payroll runs store the computed
 * deduction amount, not which bracket produced it -- so no in-use guard is
 * needed.
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

$bracketId = trim((string)($_POST['pagibig_bracket_id'] ?? ''));

if ($bracketId === '' || !ctype_digit($bracketId)) {
    flash_set('error', 'Invalid bracket.');
    header('Location: settings.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare('SELECT pagibig_bracket_id FROM pagibig_contribution_table WHERE pagibig_bracket_id = ?');
    $stmt->execute([$bracketId]);

    if (!$stmt->fetch()) {
        flash_set('error', 'That bracket no longer exists.');
    } else {
        $pdo->prepare('DELETE FROM pagibig_contribution_table WHERE pagibig_bracket_id = ?')->execute([$bracketId]);
        flash_set('success', 'Pag-IBIG bracket deleted.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Payroll Settings', 'Delete Pag-IBIG bracket', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Deleted Pag-IBIG bracket #{$bracketId}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('pagibig_bracket_delete.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this bracket. Please try again.');
}

header('Location: settings.php');
exit;
