<?php
/**
 * employee_management/run_save.php
 *
 * Creates a 'draft' payroll_runs row. Nothing is computed here -- that's
 * run_process.php's job once the manager reviews the cutoff dates. Blocks
 * creating a run whose cutoff period overlaps an existing non-cancelled
 * run of the same pay_frequency, since two runs covering the same days
 * for the same frequency would double-pay whoever falls in both.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
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

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: runs.php');
    exit;
}

const RUN_PAY_FREQUENCIES = ['semi_monthly', 'monthly'];

$payFrequency = trim((string)($_POST['pay_frequency'] ?? ''));
$cutoffStart  = trim((string)($_POST['cutoff_period_start'] ?? ''));
$cutoffEnd    = trim((string)($_POST['cutoff_period_end'] ?? ''));
$payoutDate   = trim((string)($_POST['payout_date'] ?? ''));

$errors = [];

if (!in_array($payFrequency, RUN_PAY_FREQUENCIES, true)) {
    $errors[] = 'Please choose a valid pay frequency.';
}
foreach (['cutoff_period_start' => $cutoffStart, 'cutoff_period_end' => $cutoffEnd, 'payout_date' => $payoutDate] as $label => $value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $errors[] = 'Please enter valid dates for the cutoff period and payout date.';
        break;
    }
}
if (!$errors && $cutoffEnd < $cutoffStart) {
    $errors[] = 'Cutoff end date must be on or after the cutoff start date.';
}
if (!$errors && $payoutDate < $cutoffStart) {
    $errors[] = 'Payout date must be on or after the cutoff start date.';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $overlapStmt = $pdo->prepare(
            "SELECT run_number FROM payroll_runs
             WHERE pay_frequency = ? AND status != 'cancelled'
               AND cutoff_period_start <= ? AND cutoff_period_end >= ?
             LIMIT 1"
        );
        $overlapStmt->execute([$payFrequency, $cutoffEnd, $cutoffStart]);
        $overlap = $overlapStmt->fetchColumn();

        if ($overlap) {
            $errors[] = "Run {$overlap} already covers part of this period for this pay frequency.";
        } else {
            $runNumber = generatePayrollRunNumber($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO payroll_runs (run_number, pay_frequency, cutoff_period_start, cutoff_period_end, payout_date, status, generated_by)
                 VALUES (?, ?, ?, ?, ?, 'draft', ?)"
            );
            $stmt->execute([$runNumber, $payFrequency, $cutoffStart, $cutoffEnd, $payoutDate, Session::getUserId()]);
            $newRunId = (int)$pdo->lastInsertId();

            flash_set('success', "Draft payroll run {$runNumber} created.");

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Payroll Runs', 'Create payroll run', ?, ?)"
                )->execute([
                    Session::getUserId(),
                    "Created draft run {$runNumber} ({$cutoffStart} to {$cutoffEnd})",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }

            header('Location: run_detail.php?id=' . $newRunId);
            exit;
        }
    } catch (PDOException $e) {
        error_log('run_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong creating this payroll run. Please try again.';
    }
}

flash_set('error', implode(' ', $errors));
$_SESSION['_reopen_run_modal'] = true;
header('Location: runs.php');
exit;
