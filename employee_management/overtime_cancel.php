<?php
/**
 * employee_management/overtime_cancel.php
 *
 * Deletes an approved OT authorization: at the client's request, this is a
 * single action (the "Delete" trash-icon button on overtime.php) that both
 * cancels AND permanently removes the record in one step, mirroring the
 * same change made to leave.php's Leave Records ("Delete" merges cancel +
 * delete). Once gone, it no longer counts toward payroll's
 * SUM(approved authorized_minutes) cap for that employee/date at all --
 * deleting one row of a multi-row date (e.g. the excess-approval top-up)
 * leaves the other row(s) untouched.
 *
 * Kept as its own endpoint (route name unchanged) rather than folded into
 * overtime_save.php, same reasoning as before: overtime.php's Add/Edit
 * modal never exposes a Status control, so this stays the one place a
 * record's fate actually changes.
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
        flash_set('error', 'That overtime record no longer exists.');
    } elseif ($status !== 'approved') {
        flash_set('error', 'That overtime record was already deleted.');
    } else {
        $pdo->prepare("DELETE FROM overtime_authorizations WHERE overtime_authorization_id = ?")->execute([$authId]);

        flash_set('success', 'Overtime record deleted.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Overtime', 'Delete overtime record', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Deleted overtime record #{$authId}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('overtime_cancel.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this record. Please try again.');
}

header('Location: ' . $redirect);
exit;
