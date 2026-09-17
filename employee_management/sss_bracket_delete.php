<?php
/**
 * employee_management/sss_bracket_delete.php
 *
 * Deletes an SSS contribution bracket. Nothing references sss_bracket_id
 * by foreign key -- payroll runs store the computed deduction amount, not
 * which bracket produced it -- so no in-use guard is needed.
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

$bracketId = trim((string)($_POST['sss_bracket_id'] ?? ''));

if ($bracketId === '' || !ctype_digit($bracketId)) {
    flash_set('error', 'Invalid bracket.');
    header('Location: settings.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare('SELECT sss_bracket_id FROM sss_contribution_table WHERE sss_bracket_id = ?');
    $stmt->execute([$bracketId]);

    if (!$stmt->fetch()) {
        flash_set('error', 'That bracket no longer exists.');
    } else {
        $pdo->prepare('DELETE FROM sss_contribution_table WHERE sss_bracket_id = ?')->execute([$bracketId]);
        flash_set('success', 'SSS bracket deleted.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Payroll Settings', 'Delete SSS bracket', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Deleted SSS bracket #{$bracketId}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('sss_bracket_delete.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this bracket. Please try again.');
}

header('Location: settings.php');
exit;
