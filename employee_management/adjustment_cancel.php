<?php
/**
 * employee_management/adjustment_cancel.php
 *
 * Deletes a still-pending adjustment outright -- at the client's request,
 * this merges the old two-step "cancel now, delete later" idea into one
 * "Delete" action (the trash-icon button on adjustments.php), same change
 * already made to Leave Records and Overtime this session. Kept as its own
 * single-purpose action instead of going through the edit form, now that
 * adjustments.php's Add/Edit modal no longer exposes a Status control at
 * all -- 'pending' is the only sensible value at creation time, and
 * 'applied' is set exclusively by a payroll run consuming the adjustment,
 * never by hand.
 *
 * Only ever allowed on status='pending' rows: an 'applied' adjustment has
 * already been consumed by a payroll run (payslip_deductions/payslip_earnings
 * reference it, ON DELETE SET NULL) and can no longer be touched here.
 * 'cancelled' is no longer a real status at all -- the 4 historical rows
 * that had it were deleted and the column's ENUM narrowed to
 * ('pending','applied') on 2026-09-14, so a deleted adjustment is genuinely
 * gone rather than left in a cancelled-but-still-there state.
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

const ADJUSTMENT_CANCEL_STATUSES = ['pending', 'applied', 'all'];

$returnStatus = trim((string)($_POST['return_status'] ?? ''));
$redirect     = 'adjustments.php' . (in_array($returnStatus, ADJUSTMENT_CANCEL_STATUSES, true) ? '?status=' . $returnStatus : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}
if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$adjustmentId = trim((string)($_POST['adjustment_id'] ?? ''));
if ($adjustmentId === '' || !ctype_digit($adjustmentId)) {
    flash_set('error', 'Invalid adjustment.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare('SELECT status FROM payroll_adjustments WHERE adjustment_id = ?');
    $stmt->execute([$adjustmentId]);
    $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adjustment) {
        flash_set('error', 'That adjustment no longer exists.');
    } elseif ($adjustment['status'] === 'applied') {
        flash_set('error', 'This adjustment has already been applied by a payroll run and can no longer be deleted.');
    } else {
        $pdo->prepare('DELETE FROM payroll_adjustments WHERE adjustment_id = ?')->execute([$adjustmentId]);
        flash_set('success', 'Adjustment deleted.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Adjustments', 'Delete adjustment', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Deleted adjustment #{$adjustmentId}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    }
} catch (PDOException $e) {
    error_log('adjustment_cancel.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this adjustment. Please try again.');
}

header('Location: ' . $redirect);
exit;
