<?php
/**
 * employee_management/runs.php
 *
 * Payroll Runs dashboard: a filterable/searchable/exportable list.
 *
 * There was a workflow-status card row above it (Draft/Pending Approval/
 * Approved/Completed, counting runs at each stage). It was removed on
 * 2026-09-01 -- with one run in the system it was four cards to say "1", and
 * the Status column in the table below already says the same thing per row.
 * The query that fed it went with it; nothing else read $statusCounts. A run starts as 'draft'
 * (run_save.php); run_process.php
 * assembles every active employee on the run's pay_frequency into real
 * payslips (attendance + leave + adjustments + holidays, via
 * runPayrollForEmployee()) and moves the run to 'pending_approval'.
 * Approve/complete/cancel live in their own action files, gated by the
 * run's current status. Manager-only, same gate as the rest of this module.
 *
 * This payroll process intentionally ends once payslips are generated and
 * printed -- there is no "mark as paid"/disbursement step. 'released' is
 * the terminal status; it marks that the manager considers this run's
 * payroll released to employees, not that this system itself generated
 * bank files or tracked the actual disbursement (see the module's own
 * scope notes elsewhere).
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

$managerBase = '../manager/';
$activePage  = 'payroll_runs';
$pageTitle   = 'Payroll Runs';

$filters = [
    'status'        => trim((string)($_GET['status'] ?? '')),
    'pay_frequency' => trim((string)($_GET['pay_frequency'] ?? '')),
    'date_from'     => trim((string)($_GET['date_from'] ?? '')),
    'date_to'       => trim((string)($_GET['date_to'] ?? '')),
    'search'        => trim((string)($_GET['search'] ?? '')),
];

$runs = [];
$settings = [];
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $settings = getPayrollSettings($pdo);

    [$sql, $params] = buildPayrollRunsQuery($filters);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbError = "Couldn't load payroll runs. Please refresh this page.";
}

$hasActiveFilters = array_filter($filters) !== [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll Runs | Payroll | Manager Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
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
            <?php endif; ?>

            <!-- Recent payroll runs -->
            <?php /* Page actions + filters sit in their own card above the
                     table -- same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <?php /* Seven controls plus a Filter button and a Clear link overflowed
                         this row, pushing "New payroll run" onto a line of its own. The
                         bar filters as you change it now (filter-autosubmit.js), so the
                         Filter button is gone and everything fits on one line. Clear
                         resets every control at once -- and the empty state below
                         still offers a "Clear filters" link when a filter hides every
                         run, which is the case where it actually helps. */ ?>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <form method="GET" class="owner-inv-filters" style="margin:0;flex:1 1 auto;" data-autosubmit="runs">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" name="search" placeholder="Search run #&hellip;" value="<?= htmlspecialchars($filters['search']) ?>">
                    </div>
                    <select name="status" class="owner-select">
                        <option value="">All statuses</option>
                        <?php foreach (['draft', 'processing', 'pending_approval', 'approved', 'released', 'cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars(payrollRunStatusLabel($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="pay_frequency" class="owner-select">
                        <option value="">All frequencies</option>
                        <option value="semi_monthly" <?= $filters['pay_frequency'] === 'semi_monthly' ? 'selected' : '' ?>>Semi-monthly</option>
                        <option value="monthly" <?= $filters['pay_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    </select>
                    <input type="date" name="date_from" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_from']) ?>" title="Cutoff from">
                    <input type="date" name="date_to" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_to']) ?>" title="Cutoff to">
                    <a href="runs.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                </form>
                <?php /* Outside the form on purpose: it is a modal trigger, not a
                         filter, and the auto-submit script blanks empty form controls
                         before submitting. */ ?>
                <button type="button" class="owner-btn owner-btn-primary" id="btnOpenNewRun">
                    <i class="ph ph-plus-circle" aria-hidden="true"></i> New payroll run
                </button>
                </div>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Run #</th>
                                <th>Period</th>
                                <th>Frequency</th>
                                <th>Employees</th>
                                <th>Gross pay</th>
                                <th>Net pay</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($runs)): ?>
                                <tr>
                                    <td colspan="9">
                                        <?php /* Same shape as every other empty state: message,
                                                 optional hint, no icon. Spacing comes from
                                                 .owner-table-empty, not inline styles. */ ?>
                                        <div class="owner-table-empty">
                                            <?php if ($hasActiveFilters): ?>
                                                <p>No payroll runs match these filters.</p>
                                                <a href="runs.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear filters</a>
                                            <?php else: ?>
                                                <p>No payroll runs yet.</p>
                                                <p class="owner-table-empty-hint">Create your first payroll run to get started.</p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($runs as $r): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['run_number']) ?></strong></td>
                                    <td><?= date('M j', strtotime($r['cutoff_period_start'])) ?> – <?= date('M j, Y', strtotime($r['cutoff_period_end'])) ?></td>
                                    <td><?= htmlspecialchars(payFrequencyLabel($r['pay_frequency'])) ?></td>
                                    <td><?= (int)$r['total_employees'] ?></td>
                                    <td>₱<?= number_format((float)$r['total_gross_pay'], 2) ?></td>
                                    <td>₱<?= number_format((float)$r['total_net_pay'], 2) ?></td>
                                    <td><span class="owner-status-pill <?= payrollRunStatusBadgeClass($r['status']) ?>"><?= htmlspecialchars(payrollRunStatusLabel($r['status'])) ?></span></td>
                                    <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <a href="run_detail.php?id=<?= (int)$r['payroll_run_id'] ?>" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="View run" title="View">
                                                <i class="ph ph-eye" aria-hidden="true"></i>
                                            </a>
                                            <?php if ($r['status'] === 'draft'): ?>
                                                <form method="POST" action="run_process.php" data-confirm="Generate payslips for this payroll run?" data-confirm-danger="false">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="payroll_run_id" value="<?= (int)$r['payroll_run_id'] ?>">
                                                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm" aria-label="Generate payslips" title="Generate payslips">
                                                        Generate Payslip
                                                    </button>
                                                </form>
                                            <?php elseif ($r['status'] === 'pending_approval'): ?>
                                                <form method="POST" action="run_approve.php" data-confirm="Approve this payroll run?" data-confirm-danger="false">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="payroll_run_id" value="<?= (int)$r['payroll_run_id'] ?>">
                                                    <input type="hidden" name="return_to" value="runs">
                                                    <button type="submit" class="owner-btn owner-btn-success owner-btn-sm" aria-label="Approve" title="Approve">
                                                        Approve
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($r['status'], ['approved', 'released'], true)): ?>
                                                <a href="run_payslips_print.php?id=<?= (int)$r['payroll_run_id'] ?>" target="_blank" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Print payslips" title="Print payslips">
                                                    <i class="ph ph-printer" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (in_array($r['status'], ['draft', 'pending_approval'], true)): ?>
                                                <form method="POST" action="run_cancel.php" data-confirm="Cancel this payroll run?">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="payroll_run_id" value="<?= (int)$r['payroll_run_id'] ?>">
                                                    <input type="hidden" name="return_to" value="runs">
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm" aria-label="Cancel run" title="Cancel run">
                                                        Cancel
                                                    </button>
                                                </form>
                                            <?php elseif ($r['status'] === 'cancelled'): ?>
                                                <form method="POST" action="run_delete.php" data-confirm="Permanently delete <?= htmlspecialchars($r['run_number']) ?>? This cannot be undone.">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="payroll_run_id" value="<?= (int)$r['payroll_run_id'] ?>">
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete run" title="Delete run">
                                                        <i class="ph ph-trash" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- New payroll run modal -->
<div class="owner-modal-backdrop" id="runFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="runFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="runFormTitle">New payroll run</h2>
            <button type="button" class="owner-modal-close" id="btnCloseRunForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="run_save.php" id="runForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="cutoff_period_start" id="runStartHidden">
                <input type="hidden" name="cutoff_period_end" id="runEndHidden">
                <input type="hidden" name="payout_date" id="runPayoutHidden">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="runFrequency">Pay frequency <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="runFrequency" name="pay_frequency" class="owner-select" required>
                            <option value="semi_monthly">Semi-monthly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="runPeriod">Payroll period <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="month" id="runPeriod" class="owner-input" required>
                    </div>
                    <div class="owner-form-group" id="runHalfGroup">
                        <label for="runHalf">Cutoff</label>
                        <select id="runHalf" class="owner-select">
                            <option value="1">1st half (cutoff 1)</option>
                            <option value="2">2nd half (cutoff 2)</option>
                        </select>
                    </div>
                </div>

                <div class="owner-form-hint" style="margin:14px 0 6px;font-weight:600;color:var(--op-ink);">Computed from Payroll Settings</div>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label>Cutoff start</label>
                        <div class="owner-input" id="runStartPreview" style="background:var(--op-canvas);color:var(--op-ink-soft);">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Cutoff end</label>
                        <div class="owner-input" id="runEndPreview" style="background:var(--op-canvas);color:var(--op-ink-soft);">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Payout date <span class="owner-form-optional">(reference only)</span></label>
                        <div class="owner-input" id="runPayoutPreview" style="background:var(--op-canvas);color:var(--op-ink-soft);">&mdash;</div>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelRunForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary" id="btnGenerateRun" disabled><i class="ph ph-check" aria-hidden="true"></i> Generate Payroll</button>
            </div>
        </form>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const SETTINGS = <?= json_encode($settings) ?>;

    const backdrop = document.getElementById('runFormBackdrop');
    const form = document.getElementById('runForm');
    const freqField = document.getElementById('runFrequency');
    const periodField = document.getElementById('runPeriod');
    const halfGroup = document.getElementById('runHalfGroup');
    const halfField = document.getElementById('runHalf');
    const startHidden = document.getElementById('runStartHidden');
    const endHidden = document.getElementById('runEndHidden');
    const payoutHidden = document.getElementById('runPayoutHidden');
    const startPreview = document.getElementById('runStartPreview');
    const endPreview = document.getElementById('runEndPreview');
    const payoutPreview = document.getElementById('runPayoutPreview');
    const generateBtn = document.getElementById('btnGenerateRun');

    function open() { backdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function close() { backdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }

    document.getElementById('btnOpenNewRun').addEventListener('click', () => {
        form.reset();
        const now = new Date();
        periodField.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        syncFrequencyUi();
        recomputeDates();
        open();
    });
    document.getElementById('btnCloseRunForm').addEventListener('click', close);
    document.getElementById('btnCancelRunForm').addEventListener('click', close);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) close(); });

    function syncFrequencyUi() {
        halfGroup.style.display = freqField.value === 'monthly' ? 'none' : '';
    }
    freqField.addEventListener('change', () => { syncFrequencyUi(); recomputeDates(); });
    periodField.addEventListener('change', recomputeDates);
    halfField.addEventListener('change', recomputeDates);

    function lastDayOfMonth(year, month) { return new Date(year, month, 0).getDate(); }
    function resolveDay(year, month, day) { return day === 0 ? lastDayOfMonth(year, month) : day; }
    function dateStr(year, month, day) { return year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0'); }
    function displayDate(iso) {
        const [y, m, d] = iso.split('-').map(Number);
        return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function addMonths(year, month, delta) {
        let m = month + delta, y = year;
        while (m > 12) { m -= 12; y += 1; }
        while (m < 1) { m += 12; y -= 1; }
        return { year: y, month: m };
    }

    function recomputeDates() {
        if (!periodField.value) {
            startPreview.textContent = endPreview.textContent = payoutPreview.textContent = '—';
            startHidden.value = endHidden.value = payoutHidden.value = '';
            generateBtn.disabled = true;
            return;
        }
        const [year, month] = periodField.value.split('-').map(Number);
        let start, end, payout;

        if (freqField.value === 'monthly') {
            start = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.monthly_cutoff_start_day)));
            end = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.monthly_cutoff_end_day)));
            const pm = Number(SETTINGS.monthly_payout_next_month) ? addMonths(year, month, 1) : { year, month };
            payout = dateStr(pm.year, pm.month, resolveDay(pm.year, pm.month, Number(SETTINGS.monthly_payout_day)));
        } else if (halfField.value === '1') {
            start = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff1_start_day)));
            end = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff1_end_day)));
            payout = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff1_payout_day)));
        } else {
            start = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff2_start_day)));
            end = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff2_end_day)));
            const pm = Number(SETTINGS.semi_monthly_cutoff2_payout_next_month) ? addMonths(year, month, 1) : { year, month };
            payout = dateStr(pm.year, pm.month, resolveDay(pm.year, pm.month, Number(SETTINGS.semi_monthly_cutoff2_payout_day)));
        }

        startHidden.value = start;
        endHidden.value = end;
        payoutHidden.value = payout;
        startPreview.textContent = displayDate(start);
        endPreview.textContent = displayDate(end);
        payoutPreview.textContent = displayDate(payout);
        generateBtn.disabled = false;
    }

    <?php if (!empty($_SESSION['_reopen_run_modal'])): unset($_SESSION['_reopen_run_modal']); ?>
    open();
    <?php endif; ?>

    <?php
    // Prefilled open from a "payroll cutoff needs to run" notification
    // (manager/notification_go.php reconstructs these exact dates from
    // payroll_settings + the notification's stored end date -- see
    // reconstructPayrollCutoffByEnd() in employee_management/includes/payroll_run_functions.php).
    // Values are already server-computed and correct, so this sets the
    // hidden fields + preview text directly and skips recomputeDates()
    // entirely, rather than trying to reverse-derive which period/half
    // dropdown selection would reproduce them.
    $prefillFrequency = trim((string)($_GET['prefill_frequency'] ?? ''));
    $prefillStart = trim((string)($_GET['prefill_start'] ?? ''));
    $prefillEnd = trim((string)($_GET['prefill_end'] ?? ''));
    $prefillPayout = trim((string)($_GET['prefill_payout'] ?? ''));
    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
    $hasValidPrefill = in_array($prefillFrequency, ['monthly', 'semi_monthly'], true)
        && preg_match($datePattern, $prefillStart)
        && preg_match($datePattern, $prefillEnd)
        && preg_match($datePattern, $prefillPayout);
    ?>
    <?php if ($hasValidPrefill): ?>
    (function () {
        const p = <?= json_encode(['frequency' => $prefillFrequency, 'start' => $prefillStart, 'end' => $prefillEnd, 'payout' => $prefillPayout]) ?>;
        freqField.value = p.frequency;
        syncFrequencyUi();
        periodField.value = p.start.slice(0, 7);
        if (p.frequency === 'semi_monthly') {
            halfField.value = Number(p.start.slice(8, 10)) <= 15 ? '1' : '2';
        }
        startHidden.value = p.start;
        endHidden.value = p.end;
        payoutHidden.value = p.payout;
        startPreview.textContent = displayDate(p.start);
        endPreview.textContent = displayDate(p.end);
        payoutPreview.textContent = displayDate(p.payout);
        generateBtn.disabled = false;
        open();
    })();
    <?php endif; ?>
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
<script src="../owner/assets/js/filter-autosubmit.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-autosubmit.js') ?>"></script>
</body>
</html>
