<?php
/**
 * employee_management/overtime_delete.php
 *
 * Permanently deletes an already-cancelled OT authorization -- mirrors
 * leave_record_delete.php exactly. Only ever allowed on status='cancelled'
 * rows; an approved one must be cancelled first (overtime_cancel.php),
 * never deleted outright, so there's always an explicit cancel step
 * before anything is actually removed.
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
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$returnSearch = trim((string)($_POST['return_search'] ?? ''));
$redirect     = 'overtime.php' . ($returnSearch !== '' ? '?q=' . urlencode($returnSearch) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$authId = trim((string)($_POST['overtime_authorization_id'] ?? ''));

if ($authId === '' || !ctype_digit($authId)) {
    flash_set('error', 'Invalid overtime authorization.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT status FROM overtime_authorizations WHERE overtime_authorization_id = ?");
    $stmt->execute([$authId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        flash_set('error', 'That overtime authorization no longer exists.');
    } elseif ($status !== 'cancelled') {
        flash_set('error', 'Only a cancelled overtime authorization can be deleted -- cancel it first.');
    } else {
        $pdo->prepare("DELETE FROM overtime_authorizations WHERE overtime_authorization_id = ?")->execute([$authId]);
        flash_set('success', 'Overtime authorization deleted.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Overtime', 'Delete overtime authorization', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Deleted overtime authorization #{$authId}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('overtime_delete.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this authorization. Please try again.');
}

header('Location: ' . $redirect);
exit;
