<?php
/**
 * employee_management/run_payslips_print.php?id=&payslip_ids=
 *
 * "Print Payslips" from run_detail.php's header -- every payslip on one
 * run (or only the ones checked via run_detail.php's per-row/select-all
 * checkboxes, when `payslip_ids` -- a comma-separated list -- is present),
 * rendered back-to-back through the same renderPayslipCardHtml() the
 * single-payslip view and the run-detail modal use, one page break between
 * each card (.ps-page-break) so it prints as one payslip per sheet. Own
 * standalone <html>, opens in a new tab, auto-fires window.print() the
 * same way inventory_transactions.php's ?print=1 mode does. Manager-only.
 *
 * `payslip_ids` is always additionally scoped to `payroll_run_id = ?`
 * (never trusted alone) so a manager can't print a payslip belonging to a
 * different run by guessing/editing an ID in the query string.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
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

$runId = (int)($_GET['id'] ?? 0);

$pdo = Database::getInstance()->getConnection();

$runStmt = $pdo->prepare("SELECT run_number FROM payroll_runs WHERE payroll_run_id = ?");
$runStmt->execute([$runId]);
$run = $runStmt->fetch(PDO::FETCH_ASSOC);

if (!$run) {
    http_response_code(404);
    echo 'Payroll run not found.';
    exit;
}

$requestedIds = array_filter(array_map('intval', explode(',', (string)($_GET['payslip_ids'] ?? ''))));

if (!empty($requestedIds)) {
    $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));
    $payslipIdsStmt = $pdo->prepare(
        "SELECT p.payslip_id FROM payslips p JOIN employees e ON e.employee_id = p.employee_id
         WHERE p.payroll_run_id = ? AND p.payslip_id IN ($placeholders) ORDER BY e.first_name ASC, e.last_name ASC"
    );
    $payslipIdsStmt->execute([$runId, ...$requestedIds]);
} else {
    $payslipIdsStmt = $pdo->prepare(
        "SELECT p.payslip_id FROM payslips p JOIN employees e ON e.employee_id = p.employee_id
         WHERE p.payroll_run_id = ? ORDER BY e.first_name ASC, e.last_name ASC"
    );
    $payslipIdsStmt->execute([$runId]);
}
$payslipIds = $payslipIdsStmt->fetchAll(PDO::FETCH_COLUMN);

$identity = getRestaurantIdentity($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payslips — <?= htmlspecialchars($run['run_number']) ?></title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<?= payslipDocumentStyles() ?>
</head>
<body onload="window.print()">

<div class="ps-toolbar">
    <button type="button" class="ps-btn ps-btn-primary" onclick="window.print()"><i class="ph ph-printer"></i> Print</button>
</div>

<?php if (empty($payslipIds)): ?>
    <p style="text-align:center;color:var(--ps-ink-soft);">This run has no payslips yet.</p>
<?php else: ?>
    <?php foreach ($payslipIds as $i => $payslipId):
        $details = getPayslipWithDetails($pdo, (int)$payslipId);
        if (!$details) {
            continue;
        }
        $isLast = $i === array_key_last($payslipIds);
    ?>
        <div<?= $isLast ? '' : ' class="ps-page-break"' ?>>
            <?= renderPayslipCardHtml($details['payslip'], $details['earnings'], $details['deductions'], $identity['name'], $identity['address']) ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
