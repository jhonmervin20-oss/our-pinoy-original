<?php
/**
 * employee_management/run_cancel.php
 *
 * Cancels a run that hasn't been approved yet. A plain 'draft' run has
 * nothing to undo -- it's just marked cancelled. A 'pending_approval' run
 * has already been processed, so cancelling it must actually reverse that:
 * delete its payslips (and their earnings/deductions children), release
 * the adjustments it consumed back to 'pending' (payroll_run_id = NULL),
 * and unlock the attendance records it locked. Approved/completed runs
 * can't be cancelled here -- unwinding a run the manager already signed
 * off on is a bigger decision than this button is meant for.
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

    $runStmt = $pdo->prepare("SELECT * FROM payroll_runs WHERE payroll_run_id = ?");
    $runStmt->execute([$runId]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC);

    if (!$run) {
        flash_set('error', 'Payroll run not found.');
        header('Location: runs.php');
        exit;
    }
    if (!in_array($run['status'], ['draft', 'pending_approval'], true)) {
        flash_set('error', 'Only draft or pending-approval runs can be cancelled.');
        header('Location: ' . $redirect);
        exit;
    }

    $pdo->beginTransaction();

    if ($run['status'] === 'pending_approval') {
        $employeeIdsStmt = $pdo->prepare("SELECT employee_id FROM payslips WHERE payroll_run_id = ?");
        $employeeIdsStmt->execute([$runId]);
        $employeeIds = $employeeIdsStmt->fetchAll(PDO::FETCH_COLUMN);

        $payslipIdsStmt = $pdo->prepare("SELECT payslip_id FROM payslips WHERE payroll_run_id = ?");
        $payslipIdsStmt->execute([$runId]);
        $payslipIds = $payslipIdsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($payslipIds)) {
            $placeholders = implode(',', array_fill(0, count($payslipIds), '?'));
            $pdo->prepare("DELETE FROM payslip_earnings WHERE payslip_id IN ($placeholders)")->execute($payslipIds);
            $pdo->prepare("DELETE FROM payslip_deductions WHERE payslip_id IN ($placeholders)")->execute($payslipIds);
        }
        $pdo->prepare("DELETE FROM payslips WHERE payroll_run_id = ?")->execute([$runId]);

        $pdo->prepare("UPDATE payroll_adjustments SET payroll_run_id = NULL, status = 'pending' WHERE payroll_run_id = ?")
            ->execute([$runId]);

        if (!empty($employeeIds)) {
            $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
            $pdo->prepare(
                "UPDATE attendance_records SET is_payroll_locked = 0
                 WHERE employee_id IN ($placeholders) AND attendance_date BETWEEN ? AND ?"
            )->execute([...$employeeIds, $run['cutoff_period_start'], $run['cutoff_period_end']]);
        }
    }

    $pdo->prepare(
        "UPDATE payroll_runs SET status = 'cancelled', total_employees = 0, total_gross_pay = 0, total_deductions = 0, total_net_pay = 0
         WHERE payroll_run_id = ?"
    )->execute([$runId]);

    $pdo->commit();

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Payroll Runs', 'Cancel payroll run', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Cancelled {$run['run_number']}",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    flash_set('success', "Payroll run {$run['run_number']} cancelled.");
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('run_cancel.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong cancelling this payroll run.');
}

header('Location: ' . $redirect);
exit;
