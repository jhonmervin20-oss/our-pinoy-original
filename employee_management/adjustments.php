<?php


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
$activePage  = 'payroll_adjustments';
$pageTitle   = 'Adjustments';

const ADJUSTMENT_STATUS_FILTERS = ['pending', 'applied', 'all'];

/** Fixed earning types -- deductions already have a real lookup table (payroll_deduction_types); earnings never did, so this is the equivalent fixed list, kept here rather than a new table since there's no employer-share/computation-method distinction to carry per type the way deductions have. */
const ADJUSTMENT_EARNING_TYPES = ['Bonus', 'Incentive'];

$statusFilter = trim((string)($_GET['status'] ?? 'pending'));
if (!in_array($statusFilter, ADJUSTMENT_STATUS_FILTERS, true)) {
    $statusFilter = 'pending';
}

$employees = [];
$deductionTypes = [];
$adjustments = [];
$payrollSettings = [];
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $employees = getActiveEmployeesForPicker($pdo);
    $deductionTypes = getManualDeductionTypes($pdo);
    // Drives the Add Adjustment modal's payroll-period picker -- same
    // settings, same month+half date math runs.php's own New Payroll Run
    // modal already uses, so "September, 2nd half" always means the exact
    // same two dates in both places.
    $payrollSettings = getPayrollSettings($pdo);

         $sql = "SELECT a.adjustment_id, a.employee_id, a.adjustment_category, a.adjustment_type, a.description,
             a.amount, a.is_taxable, a.target_period_start, a.target_period_end, a.status, a.created_at, a.payroll_run_id,
                   e.employee_number, e.first_name, e.last_name, e.pay_frequency, e.is_active AS employee_is_active,
                   r.run_number AS applied_run_number, r.cutoff_period_start AS applied_cutoff_start, r.cutoff_period_end AS applied_cutoff_end
            FROM payroll_adjustments a
            JOIN employees e ON e.employee_id = a.employee_id
            LEFT JOIN payroll_runs r ON r.payroll_run_id = a.payroll_run_id";
    $params = [];
    if ($statusFilter !== 'all') {
        $sql .= " WHERE a.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY a.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* For a still-pending adjustment: what runPayrollForEmployee() will
     * actually do with it, now that the match is by EXACT target period
     * rather than "whichever draft run happens to process first".
     *
     * Keyed by "start|end" so a lookup for one adjustment's own target
     * period is a single array access, not a scan. A cancelled run is
     * excluded on purpose -- it is inert, so a pending adjustment aimed at
     * a now-cancelled run's period should read exactly like no run exists
     * for that period at all, not like something already claimed it.
     */
    $runsByExactPeriod = [];
    foreach ($pdo->query(
        "SELECT status, run_number, cutoff_period_start, cutoff_period_end
         FROM payroll_runs WHERE status != 'cancelled' ORDER BY cutoff_period_start ASC"
    )->fetchAll(PDO::FETCH_ASSOC) as $run) {
        $runsByExactPeriod[$run['cutoff_period_start'] . '|' . $run['cutoff_period_end']] = $run;
    }

    $pendingSummary = $pdo->query(
        "SELECT adjustment_category, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
         FROM payroll_adjustments WHERE status = 'pending' GROUP BY adjustment_category"
    )->fetchAll(PDO::FETCH_ASSOC);
    $pendingEarningsTotal = 0.0;
    $pendingDeductionsTotal = 0.0;
    $pendingCount = 0;
    foreach ($pendingSummary as $row) {
        $pendingCount += (int)$row['cnt'];
        if ($row['adjustment_category'] === 'earning') {
            $pendingEarningsTotal = (float)$row['total'];
        } else {
            $pendingDeductionsTotal = (float)$row['total'];
        }
    }
    $appliedCount = (int)$pdo->query("SELECT COUNT(*) FROM payroll_adjustments WHERE status = 'applied'")->fetchColumn();
} catch (PDOException $e) {
    $dbError = "Couldn't load adjustments. Please refresh this page.";
    $pendingEarningsTotal = 0.0;
    $pendingDeductionsTotal = 0.0;
    $pendingCount = 0;
    $appliedCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Adjustments | Payroll | Manager Panel | OPO! Our Pinoy Original</title>
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

           

            <?php /* Filters + actions in their own card, then a bare table card --
                     the list-page split used everywhere else. No title or count
                     above a list table: the sidebar already says which page this
                     is, and the rows are their own count. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <form method="GET" class="owner-card-tools" style="margin-left:auto;">
                        <select name="status" class="owner-select" style="width:auto;" onchange="this.form.submit()">
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="applied" <?= $statusFilter === 'applied' ? 'selected' : '' ?>>Applied</option>
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                        </select>
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddAdjustment">
                            <i class="ph ph-plus-circle" aria-hidden="true"></i> Add adjustment
                        </button>
                    </form>
                </div>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Will apply to</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($adjustments)): ?>
                                <tr><td colspan="8" class="owner-table-empty">No <?= $statusFilter === 'all' ? '' : htmlspecialchars($statusFilter) . ' ' ?>adjustments yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($adjustments as $a):
                                    $isEditable = $a['status'] !== 'applied';
                                    $aData = $a;
                                ?>
                                <tr>
                                    <td>
                                        <div class="owner-cell-stack">
                                            <strong><?= htmlspecialchars(trim($a['first_name'] . ' ' . $a['last_name'])) ?></strong>
                                            <small><?= htmlspecialchars($a['employee_number']) ?></small>
                                        </div>
                                    </td>
                                    <td><span class="owner-status-pill <?= adjustmentCategoryBadgeClass($a['adjustment_category']) ?>"><?= htmlspecialchars(ucfirst($a['adjustment_category'])) ?></span></td>
                                    <td><?= htmlspecialchars($a['adjustment_type']) ?></td>
                                    <td><?= htmlspecialchars($a['description'] ?? '—') ?></td>
                                    <td>₱<?= number_format((float)$a['amount'], 2) ?></td>
                                    <td>
                                        <?php if ($a['status'] === 'applied' && $a['applied_run_number']): ?>
                                            <?= htmlspecialchars($a['applied_run_number']) ?>
                                            <small style="display:block;color:var(--op-ink-faint);"><?= date('M j', strtotime($a['applied_cutoff_start'])) ?>&ndash;<?= date('M j, Y', strtotime($a['applied_cutoff_end'])) ?></small>
                                        <?php elseif ($a['status'] === 'pending'):
                                            $hasPeriod  = $a['target_period_start'] !== null && $a['target_period_end'] !== null;
                                            $periodLbl  = $hasPeriod
                                                ? date('M j', strtotime($a['target_period_start'])) . '&ndash;' . date('M j, Y', strtotime($a['target_period_end']))
                                                : null;
                                            // Exact match, not "the next draft run" -- see the query building
                                            // $runsByExactPeriod above for why this replaced the old guess.
                                            $matchedRun = $hasPeriod ? ($runsByExactPeriod[$a['target_period_start'] . '|' . $a['target_period_end']] ?? null) : null;
                                        ?>
                                            <?php if ((int)$a['employee_is_active'] !== 1): ?>
                                                <span style="color:var(--op-ink-faint);">Employee inactive &mdash; won't be picked up</span>
                                            <?php elseif (!$hasPeriod): ?>
                                                <?php // Only reachable by a legacy row from before this field existed. ?>
                                                <span style="color:var(--op-ink-faint);">No payroll period set</span>
                                            <?php elseif ($matchedRun && $matchedRun['status'] === 'draft'): ?>
                                                <small style="display:block;"><?= $periodLbl ?></small>
                                                <?= htmlspecialchars($matchedRun['run_number']) ?>
                                                <small style="display:block;color:var(--op-ink-faint);">will be included when generated</small>
                                            <?php elseif ($matchedRun): ?>
                                                <?php // The run for this exact period already moved past draft --
                                                      // run_process.php has already run and will never look at this
                                                      // adjustment again. Surfacing this is the whole point: the old
                                                      // "earliest draft run" guess could never tell a manager their
                                                      // adjustment had quietly missed its payroll. ?>
                                                <small style="display:block;"><?= $periodLbl ?></small>
                                                <span style="color:var(--op-danger);"><?= htmlspecialchars($matchedRun['run_number']) ?> already generated &mdash; missed</span>
                                            <?php else: ?>
                                                <small style="display:block;"><?= $periodLbl ?></small>
                                                <span style="color:var(--op-ink-faint);">No run created yet for this period</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="owner-status-pill <?= adjustmentStatusBadgeClass($a['status']) ?>"><?= htmlspecialchars(ucfirst($a['status'])) ?></span></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <?php if ($isEditable): ?>
                                                <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-adjustment"
                                                    aria-label="Edit adjustment" data-adjustment='<?= htmlspecialchars(json_encode($aData), ENT_QUOTES, 'UTF-8') ?>'>
                                                    <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" style="opacity:0.4;cursor:not-allowed;" aria-label="Applied by a payroll run" title="Applied by a payroll run">
                                                    <i class="ph ph-lock" aria-hidden="true"></i>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($a['status'] === 'pending'): ?>
                                                <form method="post" action="adjustment_cancel.php" style="display:inline;" data-confirm="Delete this adjustment? This can't be undone.">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="adjustment_id" value="<?= (int)$a['adjustment_id'] ?>">
                                                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete adjustment" title="Delete adjustment">
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

<!-- Add / edit adjustment modal -->
<div class="owner-modal-backdrop" id="adjFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="adjFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="adjFormTitle">Add adjustment</h2>
            <button type="button" class="owner-modal-close" id="btnCloseAdjForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="adjustment_save.php" id="adjForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="adjustment_id" id="adjIdHidden" value="">
                <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                <input type="hidden" id="adjStatus" name="status" value="pending">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="adjEmployee">Employee <span class="owner-required" aria-hidden="true">*</span></label>
                        <select data-searchable data-search-placeholder="Search employee&hellip;" id="adjEmployee" name="employee_id" class="owner-select" required>
                            <option value="">Select an employee</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['employee_id'] ?>"><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_number'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="adjCategory">Category <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="adjCategory" name="adjustment_category" class="owner-select" required>
                            <option value="earning" selected>Earning</option>
                            <option value="deduction">Deduction</option>
                        </select>
                    </div>
                    <?php /* Which payroll cutoff this adjustment is meant for -- set once
                             here and never changed afterward (an edit's Cancel-and-recreate
                             is how you retarget one), the same immutability payroll_runs'
                             own cutoff dates already have. Before this existed, a pending
                             adjustment had no period of its own at all: whichever draft run
                             for the employee's pay frequency got processed FIRST swept it
                             in, so two adjustments meant for two different future cutoffs
                             could both land on the same next run.

                             Month + half, computed into the same start/end dates via the
                             SAME payroll_settings formula runs.php's own New Payroll Run
                             modal uses -- mirrored deliberately so "September, 2nd half"
                             means identical dates in both places. The half selector is
                             shown or hidden per the SELECTED EMPLOYEE's actual pay
                             frequency (see EMP_FREQ below), so a monthly employee is never
                             offered a half that could not apply to them. */ ?>
                    <div class="owner-form-group owner-form-group-full" id="adjPeriodGroup">
                        <label for="adjPeriodMonth">Payroll period <span class="owner-required" aria-hidden="true">*</span></label>
                        <div class="owner-form-grid" style="margin-top:0;">
                            <div class="owner-form-group">
                                <input type="month" id="adjPeriodMonth" class="owner-input" required>
                            </div>
                            <div class="owner-form-group" id="adjPeriodHalfGroup">
                                <select id="adjPeriodHalf" class="owner-select">
                                    <option value="1">1st half (cutoff 1)</option>
                                    <option value="2">2nd half (cutoff 2)</option>
                                </select>
                            </div>
                        </div>
                        <small class="owner-form-hint" id="adjPeriodPreview" style="display:block;margin-top:8px;">&mdash;</small>
                        <input type="hidden" name="target_period_start" id="adjPeriodStartHidden">
                        <input type="hidden" name="target_period_end" id="adjPeriodEndHidden">
                    </div>
                    <?php // Edit mode's read-only counterpart to the group above -- the
                          // period is immutable once saved, so editing shows the already-
                          // fixed dates as plain text instead of a live picker. ?>
                    <div class="owner-form-group owner-form-group-full" id="adjPeriodReadonlyGroup" style="display:none;">
                        <label>Payroll period</label>
                        <div class="owner-input" id="adjPeriodReadonlyText" style="background:var(--op-canvas);color:var(--op-ink-soft);">&mdash;</div>
                        <small class="owner-form-hint">Set when this adjustment was created and cannot be changed here &mdash; cancel it and add a new one to target a different period.</small>
                    </div>
                    <div class="owner-form-group" id="adjTypeEarningGroup">
                        <label for="adjTypeEarning">Type <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="adjTypeEarning" name="adjustment_type_earning" class="owner-select">
                            <option value="">Select a type</option>
                            <?php foreach (ADJUSTMENT_EARNING_TYPES as $earningType): ?>
                                <option value="<?= htmlspecialchars($earningType) ?>"><?= htmlspecialchars($earningType) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="adjAmount">Amount <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="number" id="adjAmount" name="amount" class="owner-input" step="0.01" min="0.01" required>
                    </div>
                    <div class="owner-form-group owner-form-group-full" id="adjTaxableGroup">
                        <label class="owner-checkbox-row" for="adjIsTaxable">
                            <input type="checkbox" id="adjIsTaxable" name="is_taxable" value="1" class="owner-checkbox-input" checked>
                            <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                            <span class="owner-checkbox-text">
                                <span class="owner-checkbox-label">Taxable earning</span>
                                <span class="owner-checkbox-desc">Include this earning in taxable income. Uncheck only for an approved non-taxable benefit.</span>
                            </span>
                        </label>
                    </div>
                    <div class="owner-form-group" id="adjTypeDeductionGroup" style="display:none;">
                        <label for="adjTypeDeduction">Type <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="adjTypeDeduction" name="adjustment_type_deduction" class="owner-select">
                            <option value="">Select a deduction type</option>
                            <?php foreach ($deductionTypes as $dt): ?>
                                <option value="<?= htmlspecialchars($dt['code']) ?>"><?= htmlspecialchars($dt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                           </div>
                    <div class="owner-form-group owner-form-group-full">
                        <label for="adjDescription">Description <span class="owner-form-optional">(optional)</span></label>
                        <input type="text" id="adjDescription" name="description" class="owner-input">
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelAdjForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save adjustment</button>
            </div>
        </form>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const backdrop = document.getElementById('adjFormBackdrop');
    const form = document.getElementById('adjForm');
    const title = document.getElementById('adjFormTitle');
    const idField = document.getElementById('adjIdHidden');
    const employeeField = document.getElementById('adjEmployee');
    const categoryField = document.getElementById('adjCategory');
    const typeEarningGroup = document.getElementById('adjTypeEarningGroup');
    const typeEarningInput = document.getElementById('adjTypeEarning');
    const typeDeductionGroup = document.getElementById('adjTypeDeductionGroup');
    const typeDeductionInput = document.getElementById('adjTypeDeduction');
    const taxableGroup = document.getElementById('adjTaxableGroup');
    const taxableField = document.getElementById('adjIsTaxable');
    const amountField = document.getElementById('adjAmount');
    const statusField = document.getElementById('adjStatus');
    const descriptionField = document.getElementById('adjDescription');

    // -- Payroll period picker -----------------------------------------------
    // Same month+half date math as runs.php's own New Payroll Run modal (see
    // that file for the reference implementation this mirrors), so a period
    // named the same way always resolves to identical dates in both places.
    const SETTINGS = <?= json_encode($payrollSettings) ?>;
    // employee_id -> 'monthly' | 'semi_monthly', so the half selector is only
    // ever offered to an employee it could actually apply to.
    const EMP_FREQ = <?= json_encode(array_column($employees, 'pay_frequency', 'employee_id')) ?>;

    const periodGroup = document.getElementById('adjPeriodGroup');
    const periodReadonlyGroup = document.getElementById('adjPeriodReadonlyGroup');
    const periodReadonlyText = document.getElementById('adjPeriodReadonlyText');
    const periodMonth = document.getElementById('adjPeriodMonth');
    const periodHalfGroup = document.getElementById('adjPeriodHalfGroup');
    const periodHalf = document.getElementById('adjPeriodHalf');
    const periodPreview = document.getElementById('adjPeriodPreview');
    const periodStartHidden = document.getElementById('adjPeriodStartHidden');
    const periodEndHidden = document.getElementById('adjPeriodEndHidden');

    function lastDayOfMonth(year, month) { return new Date(year, month, 0).getDate(); }
    function resolveDay(year, month, day) { return day === 0 ? lastDayOfMonth(year, month) : day; }
    function dateStr(year, month, day) { return year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0'); }
    function displayDate(iso) {
        const [y, m, d] = iso.split('-').map(Number);
        return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    // null (no employee picked yet) is a real third state here, distinct from
    // 'monthly'/'semi_monthly' -- before that, there is nothing correct to
    // show: the half selector cannot be offered or hidden based on a
    // frequency nobody has told us yet, and computing a period against a
    // guessed frequency would let a manager submit a period that quietly
    // does not match the employee actually selected.
    function employeeFrequency() {
        return EMP_FREQ[employeeField.value] || null;
    }

    // Hidden until an employee is actually picked, then shown only for a
    // semi-monthly one -- a monthly employee's own payroll never runs on a
    // half-month schedule, so a period picked from it could never match a
    // real run for them.
    function syncPeriodHalfVisibility() {
        periodHalfGroup.style.display = (employeeFrequency() === 'semi_monthly') ? '' : 'none';
    }
    employeeField.addEventListener('change', () => { syncPeriodHalfVisibility(); recomputePeriod(); });

    function recomputePeriod() {
        const freq = employeeFrequency();
        if (!freq || !periodMonth.value) {
            periodPreview.textContent = freq ? '—' : 'Choose an employee first';
            periodStartHidden.value = periodEndHidden.value = '';
            return;
        }
        const [year, month] = periodMonth.value.split('-').map(Number);
        let start, end;

        if (freq === 'monthly') {
            start = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.monthly_cutoff_start_day)));
            end = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.monthly_cutoff_end_day)));
        } else if (periodHalf.value === '1') {
            start = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff1_start_day)));
            end = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff1_end_day)));
        } else {
            start = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff2_start_day)));
            end = dateStr(year, month, resolveDay(year, month, Number(SETTINGS.semi_monthly_cutoff2_end_day)));
        }

        periodStartHidden.value = start;
        periodEndHidden.value = end;
        periodPreview.textContent = displayDate(start) + ' – ' + displayDate(end);
    }
    periodMonth.addEventListener('change', recomputePeriod);
    periodHalf.addEventListener('change', recomputePeriod);

    // Add mode: the live picker, defaulted to the current month. Edit mode:
    // the period is immutable once saved (see adjustment_save.php's UPDATE,
    // which never touches these two columns), so the picker is replaced by a
    // plain read-only display of whatever was actually stored.
    function showPeriodPicker() {
        periodGroup.style.display = '';
        periodReadonlyGroup.style.display = 'none';
        periodMonth.required = true;
        periodMonth.disabled = false;
        const now = new Date();
        periodMonth.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        syncPeriodHalfVisibility();
        recomputePeriod();
    }
    function showPeriodReadonly(targetStart, targetEnd) {
        periodGroup.style.display = 'none';
        periodReadonlyGroup.style.display = '';
        periodMonth.required = false;
        periodMonth.disabled = true;
        periodReadonlyText.textContent = (targetStart && targetEnd)
            ? (displayDate(targetStart) + ' – ' + displayDate(targetEnd))
            : 'Not set (created before this field existed)';
    }

    function syncCategoryUi() {
        const isDeduction = categoryField.value === 'deduction';
        typeEarningGroup.style.display = isDeduction ? 'none' : '';
        typeDeductionGroup.style.display = isDeduction ? '' : 'none';
        typeEarningInput.disabled = isDeduction;
        typeDeductionInput.disabled = !isDeduction;
        typeEarningInput.required = !isDeduction;
        typeDeductionInput.required = isDeduction;
        taxableGroup.style.display = isDeduction ? 'none' : '';
        taxableField.disabled = isDeduction;
    }
    categoryField.addEventListener('change', syncCategoryUi);

    function open() { backdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function close() { backdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }

    document.getElementById('btnOpenAddAdjustment').addEventListener('click', () => {
        form.reset();
        idField.value = '';
        title.textContent = 'Add adjustment';
        syncCategoryUi();
        showPeriodPicker();
        open();
    });
    document.getElementById('btnCloseAdjForm').addEventListener('click', close);
    document.getElementById('btnCancelAdjForm').addEventListener('click', close);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) close(); });

    document.querySelectorAll('.owner-btn-edit-adjustment').forEach((btn) => {
        btn.addEventListener('click', () => {
            let a;
            try { a = JSON.parse(btn.getAttribute('data-adjustment')); } catch (e) { return; }

            idField.value = a.adjustment_id;
            employeeField.value = a.employee_id;
            categoryField.value = a.adjustment_category;
            syncCategoryUi();
            if (a.adjustment_category === 'deduction') {
                typeDeductionInput.value = a.adjustment_type;
            } else {
                typeEarningInput.value = a.adjustment_type;
            }
            amountField.value = a.amount;
            statusField.value = a.status === 'applied' ? 'pending' : a.status;
            descriptionField.value = a.description || '';
            taxableField.checked = a.is_taxable === undefined || !!parseInt(a.is_taxable, 10);
            showPeriodReadonly(a.target_period_start, a.target_period_end);

            title.textContent = 'Edit adjustment';
            open();
        });
    });

    <?php if (!empty($_SESSION['_reopen_adjustment_modal'])): unset($_SESSION['_reopen_adjustment_modal']); ?>
    open();
    <?php endif; ?>
})();
</script>

<script src="../owner/assets/js/searchable-select.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/searchable-select.js') ?>"></script>
</body>
</html>
