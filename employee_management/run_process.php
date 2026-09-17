<?php


require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/payroll_functions.php';
require_once __DIR__ . '/includes/payroll_run_functions.php';

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
    if ($run['status'] !== 'draft') {
        flash_set('error', 'Only draft runs can be processed.');
        header('Location: ' . $redirect);
        exit;
    }

    $employees = $pdo->prepare("SELECT * FROM employees WHERE is_active = 1 AND pay_frequency = ?");
    $employees->execute([$run['pay_frequency']]);
    $employeeRows = $employees->fetchAll(PDO::FETCH_ASSOC);

    if (empty($employeeRows)) {
        flash_set('error', 'No active employees are on this pay frequency — nothing to process.');
        header('Location: ' . $redirect);
        exit;
    }

    $settings = getPayrollSettings($pdo);
    $govTables = loadGovernmentTables($pdo);
    $deductionTypesByCode = loadDeductionTypesByCode($pdo);

    $pdo->beginTransaction();

    $totalGross = 0.0;
    $totalDeductions = 0.0;
    $totalNet = 0.0;
    $allConsumedAdjustmentIds = [];
    $processedEmployeeIds = [];

    $payslipInsert = $pdo->prepare(
        "INSERT INTO payslips (payroll_run_id, employee_id, days_worked, hours_worked, basic_pay, overtime_pay,
                                holiday_pay, rest_day_pay, night_differential_pay, leave_pay, paid_leave_days, late_deduction,
                                undertime_deduction, absence_deduction, gross_pay, total_deductions, net_pay, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')"
    );
    $earningInsert = $pdo->prepare(
        "INSERT INTO payslip_earnings (payslip_id, earning_type, description, amount, adjustment_id) VALUES (?, ?, ?, ?, ?)"
    );
    $deductionInsert = $pdo->prepare(
        "INSERT INTO payslip_deductions (payslip_id, deduction_type_id, description, employee_amount, employer_amount, adjustment_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    foreach ($employeeRows as $employee) {
        $result = runPayrollForEmployee(
            $pdo,
            $employee,
            $run['cutoff_period_start'],
            $run['cutoff_period_end'],
            $settings,
            $govTables,
            $deductionTypesByCode
        );

        $payslipInsert->execute([
            $run['payroll_run_id'],
            $employee['employee_id'],
            $result['days_worked'],
            $result['hours_worked'],
            $result['basic_pay'],
            $result['overtime_pay'],
            $result['holiday_pay'],
            $result['rest_day_pay'],
            $result['night_differential_pay'],
            $result['leave_pay'],
            $result['paid_leave_days'],
            $result['late_deduction'],
            $result['undertime_deduction'],
            $result['absence_deduction'],
            $result['gross_pay'],
            $result['total_deductions'],
            $result['net_pay'],
        ]);
        $payslipId = (int)$pdo->lastInsertId();

        foreach ($result['earning_lines'] as $line) {
            $earningInsert->execute([$payslipId, $line['earning_type'], $line['description'], $line['amount'], $line['adjustment_id']]);
        }
        foreach ($result['deduction_lines'] as $line) {
            if (empty($line['deduction_type_id'])) {
                error_log("run_process.php: skipped a deduction line with no deduction_type_id for employee {$employee['employee_id']}, run {$runId}.");
                continue;
            }
            $deductionInsert->execute([
                $payslipId,
                $line['deduction_type_id'],
                $line['description'],
                $line['employee_amount'],
                $line['employer_amount'],
                $line['adjustment_id'],
            ]);
        }

        $totalGross += $result['gross_pay'];
        $totalDeductions += $result['total_deductions'];
        $totalNet += $result['net_pay'];
        $allConsumedAdjustmentIds = array_merge($allConsumedAdjustmentIds, $result['consumed_adjustment_ids']);
        $processedEmployeeIds[] = $employee['employee_id'];
    }

    if (!empty($allConsumedAdjustmentIds)) {
        $placeholders = implode(',', array_fill(0, count($allConsumedAdjustmentIds), '?'));
        $pdo->prepare("UPDATE payroll_adjustments SET payroll_run_id = ?, status = 'applied' WHERE adjustment_id IN ($placeholders)")
            ->execute([$run['payroll_run_id'], ...$allConsumedAdjustmentIds]);
    }

    $lockPlaceholders = implode(',', array_fill(0, count($processedEmployeeIds), '?'));
    $pdo->prepare(
        "UPDATE attendance_records SET is_payroll_locked = 1
         WHERE employee_id IN ($lockPlaceholders) AND attendance_date BETWEEN ? AND ?"
    )->execute([...$processedEmployeeIds, $run['cutoff_period_start'], $run['cutoff_period_end']]);

    $pdo->prepare(
        "UPDATE payroll_runs
         SET total_employees = ?, total_gross_pay = ?, total_deductions = ?, total_net_pay = ?,
             status = 'pending_approval', processed_by = ?, processed_at = NOW()
         WHERE payroll_run_id = ?"
    )->execute([count($processedEmployeeIds), $totalGross, $totalDeductions, $totalNet, Session::getUserId(), $run['payroll_run_id']]);

    $pdo->commit();

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Payroll Runs', 'Process payroll run', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Processed {$run['run_number']}: " . count($processedEmployeeIds) . " employees, net pay ₱" . number_format($totalNet, 2),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    flash_set('success', 'Payroll run processed: ' . count($processedEmployeeIds) . ' payslip(s) generated, now awaiting approval.');
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('run_process.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong processing this payroll run. No payslips were saved.');
}

header('Location: ' . $redirect);
exit;
