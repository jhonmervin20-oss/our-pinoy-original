<?php
/**
 * employee_management/adjustment_save.php
 *
 * Create or update a payroll adjustment. Edit mode is triggered by a
 * non-empty `adjustment_id` field. Only 'pending'/'cancelled' are
 * accepted for status here -- 'applied' is set exclusively by a future
 * payroll run consuming the adjustment, never by hand -- and an
 * adjustment that's already 'applied' can't be edited at all (checked
 * server-side, not just hidden in the UI).
 *
 * The form sends adjustment_type via one of two fields depending on
 * category (adjustment_type_earning is one of ADJUSTMENT_EARNING_TYPES
 * below, adjustment_type_deduction is a payroll_deduction_types code)
 * since earnings have no lookup table in the schema -- this picks
 * whichever one matches the submitted category.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/payroll_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$returnStatus = trim((string)($_POST['return_status'] ?? ''));
$redirect     = 'adjustments.php' . (in_array($returnStatus, ['pending', 'applied', 'cancelled', 'all'], true) ? '?status=' . $returnStatus : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

const ADJUSTMENT_CATEGORIES = ['earning', 'deduction'];
const ADJUSTMENT_EDITABLE_STATUSES = ['pending', 'cancelled'];
/** Kept in sync with adjustments.php's own copy -- see that file's comment for why this isn't a lookup table. */
const ADJUSTMENT_EARNING_TYPES = ['Bonus', 'Incentive'];

$adjustmentId = trim((string)($_POST['adjustment_id'] ?? ''));
$isEdit       = $adjustmentId !== '' && ctype_digit($adjustmentId);

$employeeId = trim((string)($_POST['employee_id'] ?? ''));
$category   = trim($_POST['adjustment_category'] ?? '');
$type       = $category === 'deduction'
    ? trim($_POST['adjustment_type_deduction'] ?? '')
    : trim($_POST['adjustment_type_earning'] ?? '');
$amount      = trim($_POST['amount'] ?? '');
$status      = trim($_POST['status'] ?? '');
$description = trim($_POST['description'] ?? '');
$isTaxable   = $category === 'earning' && isset($_POST['is_taxable']) ? 1 : 0;
// Which payroll cutoff this adjustment is meant for -- required on create,
// and immutable afterward (see the UPDATE statement below, which never
// touches these two columns). The modal computes them from the same
// month+half formula payroll_settings drives everywhere else in this module.
$targetPeriodStart = trim((string)($_POST['target_period_start'] ?? ''));
$targetPeriodEnd   = trim((string)($_POST['target_period_end'] ?? ''));

$errors = [];

if ($employeeId === '' || !ctype_digit($employeeId)) {
    $errors[] = 'Please choose an employee.';
}
if (!in_array($category, ADJUSTMENT_CATEGORIES, true)) {
    $errors[] = 'Please choose a valid category.';
}
if ($type === '' || mb_strlen($type) > 50) {
    $errors[] = 'Please choose or enter a type (up to 50 characters).';
}
if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
    $errors[] = 'Please enter a valid, positive amount.';
}
if (!in_array($status, ADJUSTMENT_EDITABLE_STATUSES, true)) {
    $errors[] = 'Please choose a valid status.';
}
// Only on create: the period is immutable once set (see the UPDATE below),
// so an edit's posted values -- whatever the read-only preview happened to
// render -- are never validated or written.
if (!$isEdit) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetPeriodStart)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetPeriodEnd)
    ) {
        $errors[] = 'Please choose the payroll period this adjustment applies to.';
    } elseif ($targetPeriodEnd < $targetPeriodStart) {
        $errors[] = 'The payroll period end date cannot be before its start date.';
    }
}
// Same re-check as the deduction one below, for the same reason: the
// <select> already restricts this in the UI, but a raw POST could submit
// anything without this.
if ($category === 'earning' && $type !== '' && !in_array($type, ADJUSTMENT_EARNING_TYPES, true)) {
    $errors[] = 'Please choose a valid earning type.';
}
// A deduction's type must be one of the actual manual deduction codes
// (loan/other_deduction) -- adjustments.php's own <select> is already
// restricted to these via getManualDeductionTypes(), but that's only a
// UI restriction. Without this re-check here, a raw POST could submit a
// government-mandated code (e.g. "SSS") and runPayrollForEmployee()
// would resolve it to that SAME deduction_type_id the auto-computed
// government contribution already uses -- doubling that line on the
// payslip, indistinguishable from the real one.
if (!$errors && $category === 'deduction' && $type !== '') {
    try {
        $pdo = Database::getInstance()->getConnection();
        if (!in_array($type, array_column(getManualDeductionTypes($pdo), 'code'), true)) {
            $errors[] = 'Please choose a valid deduction type.';
        }
    } catch (PDOException $e) {
        error_log('adjustment_save.php deduction-type validation failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this adjustment. Please try again.';
    }
}

$existing = null;
if (!$errors && $isEdit) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT status FROM payroll_adjustments WHERE adjustment_id = ?");
        $stmt->execute([$adjustmentId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $errors[] = 'That adjustment no longer exists.';
        } elseif ($existing['status'] === 'applied') {
            $errors[] = 'This adjustment has already been applied by a payroll run and can no longer be edited.';
        }
    } catch (PDOException $e) {
        error_log('adjustment_save.php lookup failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this adjustment. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($isEdit) {
            // Deliberately does not touch target_period_start/end -- the
            // payroll period an adjustment targets is fixed at creation, the
            // same way a payroll run's own cutoff dates never change once set.
            $stmt = $pdo->prepare(
                "UPDATE payroll_adjustments
                  SET employee_id = ?, adjustment_category = ?, adjustment_type = ?, description = ?, amount = ?, is_taxable = ?, status = ?
                 WHERE adjustment_id = ?"
            );
              $stmt->execute([$employeeId, $category, $type, $description !== '' ? $description : null, (float)$amount, $isTaxable, $status, $adjustmentId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO payroll_adjustments
                          (employee_id, adjustment_category, adjustment_type, description, amount, is_taxable, target_period_start, target_period_end, status, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
                $stmt->execute([$employeeId, $category, $type, $description !== '' ? $description : null, (float)$amount, $isTaxable, $targetPeriodStart, $targetPeriodEnd, $status, Session::getUserId()]);
        }

        flash_set('success', $isEdit ? 'Adjustment updated.' : 'Adjustment added.');

        try {
            $empName = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
            $empName->execute([$employeeId]);
            $emp = $empName->fetch(PDO::FETCH_ASSOC);
            $empLabel = $emp ? trim($emp['first_name'] . ' ' . $emp['last_name']) : "employee #{$employeeId}";

            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Adjustments', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update adjustment' : 'Add adjustment',
                ($isEdit ? 'Updated' : 'Added') . " {$category} adjustment ({$type}) for {$empLabel}: ₱" . number_format((float)$amount, 2),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('adjustment_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this adjustment. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_adjustment_modal'] = true;
}

header('Location: ' . $redirect);
exit;
