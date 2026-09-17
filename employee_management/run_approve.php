<?php
/**
 * employee_management/run_approve.php
 *
 * pending_approval -> approved. Also finalizes every payslip on the run
 * (draft -> finalized), matching the run's own status meaning: once a
 * manager approves, the individual payslips are no longer subject to
 * silent recomputation.
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
$redirect = ($_POST['return_to'] ?? '') === 'runs' ? 'runs.php' : 'run_detail.php?id=' . $runId;

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
    if ($run['status'] !== 'pending_approval') {
        flash_set('error', 'Only runs pending approval can be approved.');
        header('Location: ' . $redirect);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE payroll_runs SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE payroll_run_id = ?")
        ->execute([Session::getUserId(), $runId]);
    $pdo->prepare("UPDATE payslips SET status = 'finalized' WHERE payroll_run_id = ?")->execute([$runId]);
    $pdo->commit();

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Payroll Runs', 'Approve payroll run', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Approved {$run['run_number']}",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    flash_set('success', "Payroll run {$run['run_number']} approved.");
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('run_approve.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong approving this payroll run.');
}

header('Location: ' . $redirect);
exit;
