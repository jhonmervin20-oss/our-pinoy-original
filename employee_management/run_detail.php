<?php
/**
 * employee_management/run_detail.php
 *
 * Per-run breakdown: header actions, a structured "Payroll Information"
 * panel, a workflow timeline with a date/time/user per stage, and every
 * employee's payslip on this run -- each row's "View payslip" opens the
 * unified payslip template in a large in-page modal (fetched as an HTML
 * fragment from payslip_fragment.php) rather than navigating away; Print/
 * Download still open the standalone payslip.php in a new tab, since an
 * actual print/PDF needs its own document cFontext. Lifecycle actions
 * (generate payslips/approve/complete/cancel) are gated server-side by
 * the run's current status in their own action files -- the buttons shown
 * here are a convenience, not the real gate.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/back_link.php';   // back_link()
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

$managerBase = '../manager/';
$activePage  = 'payroll_runs';

$runId = (int)($_GET['id'] ?? 0);
$run = null;
$payslips = [];
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $runStmt = $pdo->prepare(
        "SELECT r.*,
                gu.first_name AS generated_first_name, gu.last_name AS generated_last_name,
                pu.first_name AS processed_first_name, pu.last_name AS processed_last_name,
                au.first_name AS approved_first_name, au.last_name AS approved_last_name,
                cu.first_name AS released_first_name, cu.last_name AS released_last_name
         FROM payroll_runs r
         LEFT JOIN users gu ON gu.user_id = r.generated_by
         LEFT JOIN users pu ON pu.user_id = r.processed_by
         LEFT JOIN users au ON au.user_id = r.approved_by
         LEFT JOIN users cu ON cu.user_id = r.released_by
         WHERE r.payroll_run_id = ?"
    );
    $runStmt->execute([$runId]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC);

    if ($run) {
        $psStmt = $pdo->prepare(
            "SELECT p.payslip_id, p.employee_id, p.days_worked, p.hours_worked, p.overtime_pay, p.gross_pay, p.total_deductions, p.net_pay, p.status,
                    e.employee_number, e.first_name, e.last_name, pos.position_title,
                    COALESCE((SELECT SUM(amount) FROM payslip_earnings WHERE payslip_id = p.payslip_id), 0) AS allowances
             FROM payslips p
             JOIN employees e ON e.employee_id = p.employee_id
             LEFT JOIN positions pos ON pos.position_id = e.position_id
             WHERE p.payroll_run_id = ?
             ORDER BY e.first_name ASC, e.last_name ASC"
        );
        $psStmt->execute([$runId]);
        $payslips = $psStmt->fetchAll(PDO::FETCH_ASSOC);

        // No summary aggregation here any more: the report moved out to
        // run_summary_print.php, which calls getPayrollRunSummary() itself.
        // Running those five aggregate queries on every page load to render
        // a panel that no longer exists was pure waste.
    }
} catch (PDOException $e) {
    $dbError = "Couldn't load this payroll run. Please refresh this page.";
}

if (!$run && !$dbError) {
    flash_set('error', 'Payroll run not found.');
    header('Location: runs.php');
    exit;
}

$pageTitle = $run ? $run['run_number'] : 'Payroll Run';

$stageOrder = ['draft', 'pending_approval', 'approved', 'released'];
$currentIndex = $run ? array_search($run['status'], $stageOrder, true) : false;
$isCancelled = $run && $run['status'] === 'cancelled';

function personName(?string $first, ?string $last): ?string
{
    $name = trim(($first ?? '') . ' ' . ($last ?? ''));
    return $name !== '' ? $name : null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> | Payroll | Manager Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<style>
    .owner-run-stepper{ display:flex; align-items:flex-start; padding:6px 4px; }
    .owner-run-step{ display:flex; flex-direction:column; align-items:center; text-align:center; min-width:110px; }
    .owner-run-step-dot{ width:24px; height:24px; border-radius:50%; border:2px solid var(--op-border); background:#fff; display:flex; align-items:center; justify-content:center; font-size:0.75rem; color:var(--op-ink-faint); flex-shrink:0; }
    .owner-run-step.is-done .owner-run-step-dot{ background:var(--op-gold); border-color:var(--op-gold); color:#fff; }
    .owner-run-step.is-current .owner-run-step-dot{ border-color:var(--op-gold); background:var(--op-gold-soft); }
    .owner-run-step.is-failed .owner-run-step-dot{ background:var(--op-danger); border-color:var(--op-danger); color:#fff; }
    .owner-run-step-label{ font-size:0.82rem; font-weight:600; margin-top:8px; color:var(--op-ink); }
    .owner-run-step.is-pending .owner-run-step-label{ color:var(--op-ink-faint); font-weight:500; }
    .owner-run-step-time{ font-size:0.7rem; color:var(--op-ink-faint); margin-top:2px; }
    .owner-run-step-connector{ flex:1; height:2px; background:var(--op-border); margin-top:12px; }
    .owner-run-step-connector.is-done{ background:var(--op-gold); }
    .owner-run-info-row{ display:flex; justify-content:space-between; align-items:baseline; gap:12px; padding:5px 0; }
    .owner-run-info-label{ font-size:0.78rem; color:var(--op-ink-faint); white-space:nowrap; }
    .owner-run-info-value{ font-size:0.86rem; color:var(--op-ink); font-weight:600; text-align:right; }

    /* Four steps at a fixed 110px, plus the connectors between them, need about
       440px. A phone viewport is 393px, so "Released" hung off the right edge
       and dragged the whole page's horizontal scroll with it -- the page read
       as broken even though every other block on it fitted.

       Wrapped into two rows instead of scrolled, so the whole workflow stays
       visible at a glance. The connectors are hidden at this size: they are
       decorative, and a connector that wraps onto the next line points at
       nothing. */
    @media (max-width: 640px){
        .owner-run-stepper{ flex-wrap: wrap; row-gap: 18px; padding: 6px 0; }
        .owner-run-step{ min-width: 0; flex: 1 1 44%; }
        .owner-run-step-connector{ display: none; }
    }

    /* The payroll figures are the point of this page, so the info rows stack
       label-over-value rather than squeezing both onto one line, where a long
       value like "Pending approval" wrapped into a ragged column. */
    @media (max-width: 480px){
        .owner-run-info-row{ flex-direction: column; align-items: flex-start; gap: 1px; padding: 7px 0; }
        .owner-run-info-value{ text-align: left; }
    }
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/../manager/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/../manager/includes/header.php'; ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php elseif ($run): ?>

                <!-- Header -->
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <a href="<?= htmlspecialchars(back_link('runs.php')) ?>" class="owner-btn owner-btn-secondary owner-btn-sm">
                            <i class="ph ph-arrow-left" aria-hidden="true"></i> Back
                        </a>
                        <h1 style="font-family:'Lexend',sans-serif;font-size:1.15rem;font-weight:700;margin:0;color:var(--op-ink);"><?= htmlspecialchars($run['run_number']) ?></h1>
                        <span class="owner-status-pill <?= payrollRunStatusBadgeClass($run['status']) ?>"><?= htmlspecialchars(payrollRunStatusLabel($run['status'])) ?></span>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <?php if ($run['status'] === 'draft'): ?>
                            <form method="POST" action="run_process.php" data-confirm="Generate payslips for this payroll run? This creates a payslip for every active employee on this pay frequency." data-confirm-danger="false">
                                <?= csrf_field() ?>
                                <input type="hidden" name="payroll_run_id" value="<?= (int)$run['payroll_run_id'] ?>">
                                <button type="submit" class="owner-btn owner-btn-primary">
                                    <i class="ph ph-file-text" aria-hidden="true"></i> Generate Payslips
                                </button>
                            </form>
                        <?php elseif ($run['status'] === 'pending_approval'): ?>
                            <form method="POST" action="run_approve.php" data-confirm="Approve this payroll run?" data-confirm-danger="false">
                                <?= csrf_field() ?>
                                <input type="hidden" name="payroll_run_id" value="<?= (int)$run['payroll_run_id'] ?>">
                                <button type="submit" class="owner-btn owner-btn-primary">
                                    Approve Payroll
                                </button>
                            </form>
                        <?php elseif ($run['status'] === 'approved'): ?>
                            <button type="button" class="owner-btn owner-btn-secondary" id="btnPrintPayslips" data-run-id="<?= (int)$run['payroll_run_id'] ?>">
                                <i class="ph ph-printer" aria-hidden="true"></i> Print Payslips
                            </button>
                            <form method="POST" action="run_release.php" data-confirm="Mark this payroll run as released? This finalizes every payslip on the run." data-confirm-danger="false">
                                <?= csrf_field() ?>
                                <input type="hidden" name="payroll_run_id" value="<?= (int)$run['payroll_run_id'] ?>">
                                <button type="submit" class="owner-btn owner-btn-primary">
                                    Release Payroll
                                </button>
                            </form>
                        <?php elseif ($run['status'] === 'released'): ?>
                            <button type="button" class="owner-btn owner-btn-secondary" id="btnPrintPayslips" data-run-id="<?= (int)$run['payroll_run_id'] ?>">
                                <i class="ph ph-printer" aria-hidden="true"></i> Print Payslips
                            </button>
                        <?php endif; ?>
                        <?php if (in_array($run['status'], ['draft', 'pending_approval'], true)): ?>
                            <form method="POST" action="run_cancel.php" data-confirm="Cancel this payroll run? Any generated payslips will be reversed and consumed adjustments/attendance released.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="payroll_run_id" value="<?= (int)$run['payroll_run_id'] ?>">
                                <button type="submit" class="owner-btn owner-btn-danger">
                                    Cancel Payroll
                                </button>
                            </form>
                        <?php elseif ($run['status'] === 'cancelled'): ?>
                            <form method="POST" action="run_delete.php" data-confirm="Permanently delete this payroll run? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="payroll_run_id" value="<?= (int)$run['payroll_run_id'] ?>">
                                <button type="submit" class="owner-btn owner-btn-danger">
                                    <i class="ph ph-trash" aria-hidden="true"></i> Delete Payroll Run
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isCancelled): ?>
                    <div class="owner-alert owner-alert-error" style="margin-bottom:20px;">
                        <i class="ph ph-x-circle" aria-hidden="true"></i>
                        <span>This payroll run was cancelled. Any payslips it had generated were reversed and its adjustments/attendance were released.</span>
                    </div>
                <?php endif; ?>
               

                <div class="owner-card">

                <!-- Employee payroll table -->
                <div id="run-tab-payslips">
                    <div class="owner-card-head">
                        <div>
                            <h2 class="owner-card-title">Employee Payroll</h2>
                            <span class="owner-card-subtitle"><?= count($payslips) ?> employee(s)</span>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <div class="owner-inv-filter-search" style="width:240px;">
                                <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                                <input type="text" id="employeeSearchInput" placeholder="Search employee&hellip;" autocomplete="off">
                            </div>
                            <?php /* The summary used to be a second tab on this page, which meant
                                     the printable document and the on-screen one were two separate
                                     renderings that could drift. It is now generated from the same
                                     run_summary_print.php markup that the PDF uses, opened in its
                                     own tab where it can be previewed, printed or downloaded. */ ?>
                            <?php if (!empty($payslips)): ?>
                            <a href="run_summary_print.php?id=<?= (int)$run['payroll_run_id'] ?>" target="_blank" rel="noopener"
                               class="owner-btn owner-btn-primary">Generate summary report</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php $showPrintSelect = in_array($run['status'], ['approved', 'released'], true) && !empty($payslips); ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table">
                            <thead>
                                <tr>
                                    <?php if ($showPrintSelect): ?><th style="width:36px;"><input type="checkbox" id="selectAllPayslips" checked aria-label="Select all"></th><?php endif; ?>
                                    <th>Employee</th>
                                    <th>Position</th>
                                    <th>Days worked</th>
                                    <th>Hours worked</th>
                                    <th>Overtime pay</th>
                                    <th>Allowances</th>
                                    <th>Gross pay</th>
                                    <th>Deductions</th>
                                    <th>Net pay</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payslips)): ?>
                                    <tr><td colspan="<?= $showPrintSelect ? 12 : 11 ?>" class="owner-table-empty">This run hasn't been processed yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payslips as $p):
                                        $fullName = trim($p['first_name'] . ' ' . $p['last_name']);
                                    ?>
                                    <tr data-name="<?= htmlspecialchars(strtolower($fullName . ' ' . $p['employee_number'])) ?>">
                                        <?php if ($showPrintSelect): ?><td><input type="checkbox" class="payslip-select-checkbox" value="<?= (int)$p['payslip_id'] ?>" checked aria-label="Select <?= htmlspecialchars($fullName) ?>"></td><?php endif; ?>
                                        <td>
                                            <div class="owner-cell-stack">
                                                <strong><?= htmlspecialchars($fullName) ?></strong>
                                                <small><?= htmlspecialchars($p['employee_number']) ?></small>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($p['position_title'] ?? '—') ?></td>
                                        <td><?= number_format((float)$p['days_worked'], 2) ?></td>
                                        <td><?= number_format((float)$p['hours_worked'], 2) ?></td>
                                        <td>₱<?= number_format((float)$p['overtime_pay'], 2) ?></td>
                                        <td>₱<?= number_format((float)$p['allowances'], 2) ?></td>
                                        <td>₱<?= number_format((float)$p['gross_pay'], 2) ?></td>
                                        <td>₱<?= number_format((float)$p['total_deductions'], 2) ?></td>
                                        <td><strong>₱<?= number_format((float)$p['net_pay'], 2) ?></strong></td>
                                        <td><span class="owner-status-pill <?= payslipStatusBadgeClass($p['status']) ?>"><?= htmlspecialchars(payslipStatusLabel($p['status'])) ?></span></td>
                                        <td>
                                            <div class="owner-table-actions">
                                                <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-view-payslip"
                                                    aria-label="View payslip for <?= htmlspecialchars($fullName) ?>" data-payslip-id="<?= (int)$p['payslip_id'] ?>">Slip</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr id="employeeNoMatchRow" class="owner-table-empty-row" hidden>
                                        <td colspan="<?= $showPrintSelect ? 12 : 11 ?>" class="owner-table-empty">No employees match your search.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                </div>

            <?php endif; ?>

        </main>

    </div>

</div>

<!-- Payslip preview modal -->
<div class="owner-modal-backdrop" id="payslipModalBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="payslipModalTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="payslipModalTitle">Payslip</h2>
            <button type="button" class="owner-modal-close" id="btnClosePayslipModal" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body" id="payslipModalBody" style="background:var(--op-canvas);">
            <div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>
        </div>
        <div class="owner-modal-footer">
            <button type="button" class="owner-btn owner-btn-secondary" id="btnClosePayslipModalFooter">Close</button>
            <a href="#" target="_blank" class="owner-btn owner-btn-secondary" id="btnDownloadPayslip"><i class="ph ph-download-simple" aria-hidden="true"></i> Download PDF</a>
            <a href="#" target="_blank" class="owner-btn owner-btn-primary" id="btnPrintPayslip"><i class="ph ph-printer" aria-hidden="true"></i> Print</a>
        </div>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    // -- Employee search (client-side row filter, same pattern as employee_management/employees.php) --
    const searchInput = document.getElementById('employeeSearchInput');
    const noMatchRow = document.getElementById('employeeNoMatchRow');
    if (searchInput) {
        const rows = Array.from(document.querySelectorAll('.owner-table tbody tr[data-name]'));
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim().toLowerCase();
            let visible = 0;
            rows.forEach((row) => {
                const show = !q || row.getAttribute('data-name').includes(q);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (noMatchRow) noMatchRow.hidden = (rows.length === 0 || visible > 0);
        });
    }

    // -- Payslip preview modal ------------------------------------------------
    const backdrop = document.getElementById('payslipModalBackdrop');
    const body = document.getElementById('payslipModalBody');
    const downloadBtn = document.getElementById('btnDownloadPayslip');
    const printBtn = document.getElementById('btnPrintPayslip');

    function openModal() { backdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function closeModal() { backdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }

    document.querySelectorAll('.owner-btn-view-payslip').forEach((btn) => {
        btn.addEventListener('click', () => {
            const payslipId = btn.getAttribute('data-payslip-id');
            body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>';
            downloadBtn.href = 'payslip.php?id=' + payslipId + '&download=1';
            printBtn.href = 'payslip.php?id=' + payslipId;
            openModal();

            fetch('payslip_fragment.php?id=' + payslipId)
                .then((res) => res.text())
                .then((html) => { body.innerHTML = html; })
                .catch(() => { body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-danger);">Couldn\'t load this payslip.</div>'; });
        });
    });

    document.getElementById('btnClosePayslipModal').addEventListener('click', closeModal);
    document.getElementById('btnClosePayslipModalFooter').addEventListener('click', closeModal);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal(); });

    // -- Batch print: "select all" checkbox + per-row checkboxes, then only
    // print whichever payslips are checked (run_payslips_print.php?payslip_ids=...) --
    const selectAllPayslips = document.getElementById('selectAllPayslips');
    const rowCheckboxes = Array.from(document.querySelectorAll('.payslip-select-checkbox'));
    if (selectAllPayslips) {
        selectAllPayslips.addEventListener('change', () => {
            rowCheckboxes.forEach((cb) => { cb.checked = selectAllPayslips.checked; });
        });
        rowCheckboxes.forEach((cb) => {
            cb.addEventListener('change', () => {
                const checkedCount = rowCheckboxes.filter((c) => c.checked).length;
                selectAllPayslips.checked = checkedCount === rowCheckboxes.length;
                selectAllPayslips.indeterminate = checkedCount > 0 && checkedCount < rowCheckboxes.length;
            });
        });
    }

    const btnPrintPayslips = document.getElementById('btnPrintPayslips');
    if (btnPrintPayslips) {
        btnPrintPayslips.addEventListener('click', () => {
            const checkedIds = rowCheckboxes.filter((cb) => cb.checked).map((cb) => cb.value);
            if (checkedIds.length === 0) {
                alert('Select at least one employee to print.');
                return;
            }
            const runId = btnPrintPayslips.getAttribute('data-run-id');
            let url = 'run_payslips_print.php?id=' + runId;
            if (checkedIds.length < rowCheckboxes.length) {
                url += '&payslip_ids=' + checkedIds.join(',');
            }
            window.open(url, '_blank');
        });
    }
})();
</script>

</body>
</html>
