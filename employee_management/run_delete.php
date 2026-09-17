<?php
/**
 * employee_management/run_delete.php
 *
 * Permanently deletes a payroll run -- only ever allowed once it's
 * 'cancelled'. A cancelled run already has zero payslips (run_cancel.php
 * deletes them, releases adjustments back to pending, and unlocks
 * attendance as part of cancelling) and zeroed totals, so there is
 * nothing left to unwind here -- this just removes the now-empty shell
 * row. `payroll_adjustments.payroll_run_id` (ON DELETE SET NULL) and
 * `payslips.payroll_run_id` (ON DELETE CASCADE, always already empty for
 * a cancelled run) make the delete itself safe at the DB level; the
 * `status = 'cancelled'` check in both the app logic and the DELETE's own
 * WHERE clause is what actually protects any run with real data still
 * attached.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: runs.php');
    exit;
}

$runId = (int)($_POST['payroll_run_id'] ?? 0);

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: runs.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $runStmt = $pdo->prepare("SELECT run_number, status FROM payroll_runs WHERE payroll_run_id = ?");
    $runStmt->execute([$runId]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC);

    if (!$run) {
        flash_set('error', 'Payroll run not found.');
        header('Location: runs.php');
        exit;
    }
    if ($run['status'] !== 'cancelled') {
        flash_set('error', 'Only a cancelled payroll run can be deleted.');
        header('Location: run_detail.php?id=' . $runId);
        exit;
    }

    $pdo->prepare("DELETE FROM payroll_runs WHERE payroll_run_id = ? AND status = 'cancelled'")->execute([$runId]);

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Payroll Runs', 'Delete payroll run', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Deleted cancelled run {$run['run_number']}",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    flash_set('success', "Payroll run {$run['run_number']} deleted.");
} catch (PDOException $e) {
    error_log('run_delete.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this payroll run.');
    header('Location: run_detail.php?id=' . $runId);
    exit;
}

header('Location: runs.php');
exit;
