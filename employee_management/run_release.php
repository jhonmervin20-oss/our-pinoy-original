<?php
/**
 * employee_management/run_release.php
 *
 * approved -> released. This payroll process intentionally ends once
 * payslips are generated/approved and printed -- this system never
 * disburses funds, generates bank files, or tracks salary release (see
 * project scope notes), so "released" just marks that the manager
 * considers this run's payroll released to employees, not that this system
 * itself moved money. Also finalizes every payslip on the run to
 * status='released', and stamps released_by/released_at for the
 * run-detail timeline. Replaces the old run_complete.php (renamed along
 * with payroll_runs.status's 'completed' value becoming 'released' -- see
 * database/restaurant_management_system_db.sql), which itself replaced
 * run_mark_paid.php when 'paid' became 'completed'.
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
$redirect = 'run_detail.php?id=' . $runId;

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
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
    if ($run['status'] !== 'approved') {
        flash_set('error', 'Only approved runs can be released.');
        header('Location: ' . $redirect);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE payroll_runs SET status = 'released', released_by = ?, released_at = NOW() WHERE payroll_run_id = ?")
        ->execute([Session::getUserId(), $runId]);
    $pdo->prepare("UPDATE payslips SET status = 'released' WHERE payroll_run_id = ?")->execute([$runId]);
    $pdo->commit();

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Payroll Runs', 'Release payroll run', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Released {$run['run_number']}",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    flash_set('success', "Payroll run {$run['run_number']} released.");
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('run_release.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong releasing this payroll run.');
}

header('Location: ' . $redirect);
exit;
