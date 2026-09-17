<?php
/**
 * employee_management/leave.php
 *
 * Leave management: leave types (a "Manage leave types" modal, same
 * in-page-modal pattern as Departments/Positions), per-employee/year
 * leave balances, and the actual leave record log. employee_leave_records
 * has no "pending" status (schema only supports approved/cancelled) --
 * leave here is recorded as an already-decided fact, not a self-service
 * request/approval workflow. days_used on a balance is never edited
 * directly; it's always recomputed from approved records for that
 * employee/leave type/year via recalculateLeaveBalance() whenever a
 * record changes. Manager-only, same gate as the rest of this module.
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
$activePage  = 'payroll_leave';
$pageTitle   = 'Leave';

$currentYear = (int)date('Y');
$yearFilter = trim((string)($_GET['year'] ?? $currentYear));
if (!preg_match('/^\d{4}$/', $yearFilter)) {
    $yearFilter = (string)$currentYear;
}

$employees = [];
$leaveTypes = [];
$balances = [];
$records = [];
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $employees = getActiveEmployeesForPicker($pdo);
    $leaveTypes = $pdo->query(
        "SELECT lt.leave_type_id, lt.leave_name, lt.is_paid, lt.default_days_per_year,
                (SELECT COUNT(*) FROM employee_leave_balances b WHERE b.leave_type_id = lt.leave_type_id) AS balance_count
         FROM leave_types lt
         ORDER BY lt.leave_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $balStmt = $pdo->prepare(
        "SELECT b.leave_balance_id, b.employee_id, b.leave_type_id, b.year, b.days_entitled, b.days_used,
                e.employee_number, e.first_name, e.last_name, lt.leave_name
         FROM employee_leave_balances b
         JOIN employees e ON e.employee_id = b.employee_id
         JOIN leave_types lt ON lt.leave_type_id = b.leave_type_id
         WHERE b.year = ?
         ORDER BY e.first_name ASC, e.last_name ASC, lt.leave_name ASC"
    );
    $balStmt->execute([$yearFilter]);
    $balances = $balStmt->fetchAll(PDO::FETCH_ASSOC);

    $recStmt = $pdo->prepare(
        "SELECT r.leave_record_id, r.employee_id, r.leave_type_id, r.start_date, r.end_date, r.total_days,
                r.reason, r.cancelled_reason, r.status,
                e.employee_number, e.first_name, e.last_name, lt.leave_name
         FROM employee_leave_records r
         JOIN employees e ON e.employee_id = r.employee_id
         JOIN leave_types lt ON lt.leave_type_id = r.leave_type_id
         WHERE YEAR(r.start_date) = ?
         ORDER BY r.start_date DESC"
    );
    $recStmt->execute([$yearFilter]);
    $records = $recStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load leave data. Please refresh this page.";
}

$totalDaysUsed = array_sum(array_column($balances, 'days_used'));
$approvedCount = count(array_filter($records, fn($r) => $r['status'] === 'approved'));
$cancelledCount = count(array_filter($records, fn($r) => $r['status'] === 'cancelled'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave | Payroll | Manager Panel | OPO! Our Pinoy Original</title>
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

           

            <!-- Tabs -->
            <div class="owner-tabs">
                <a href="#" class="owner-tabs-link is-active" data-leave-tab="balances">Leave Balances</a>
                <a href="#" class="owner-tabs-link" data-leave-tab="records">Leave Records</a>
            </div>

            <!-- Leave Balances panel -->
            <div class="owner-card" id="leave-panel-balances">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Leave Balances</h2>
                        <span class="owner-card-subtitle"><?= count($balances) ?> for <?= htmlspecialchars($yearFilter) ?></span>
                    </div>
                    <form method="GET" class="owner-card-tools">
                        <input type="hidden" name="tab" value="balances">
                        <select name="year" class="owner-select" style="width:auto;" onchange="this.form.submit()">
                            <?php for ($y = $currentYear + 1; $y >= $currentYear - 3; $y--): ?>
                                <option value="<?= $y ?>" <?= (string)$y === $yearFilter ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="button" class="owner-btn owner-btn-secondary" id="btnOpenManageLeaveTypes">
                            <i class="ph ph-tag" aria-hidden="true"></i> Manage leave types
                        </button>
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddBalance">
                            <i class="ph ph-plus-circle" aria-hidden="true"></i> Set balance
                        </button>
                    </form>
                </div>

                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave type</th>
                                <th>Entitled</th>
                                <th>Used</th>
                                <th>Remaining</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($balances)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No leave balances set for <?= htmlspecialchars($yearFilter) ?> yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($balances as $b):
                                    $remaining = (float)$b['days_entitled'] - (float)$b['days_used'];
                                    $bData = $b;
                                ?>
                                <tr>
                                    <td>
                                        <div class="owner-cell-stack">
                                            <strong><?= htmlspecialchars(trim($b['first_name'] . ' ' . $b['last_name'])) ?></strong>
                                            <small><?= htmlspecialchars($b['employee_number']) ?></small>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($b['leave_name']) ?></td>
                                    <td><?= number_format((float)$b['days_entitled'], 1) ?></td>
                                    <td><?= number_format((float)$b['days_used'], 1) ?></td>
                                    <td><span class="owner-status-pill <?= $remaining < 0 ? 'is-danger' : 'is-active' ?>"><?= number_format($remaining, 1) ?></span></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-balance"
                                                aria-label="Edit balance" data-balance='<?= htmlspecialchars(json_encode($bData), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Leave Records panel -->
            <div class="owner-card" id="leave-panel-records" style="display:none;">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Leave Records</h2>
                        <span class="owner-card-subtitle"><?= count($records) ?> for <?= htmlspecialchars($yearFilter) ?></span>
                    </div>
                    <form method="GET" class="owner-card-tools">
                        <input type="hidden" name="tab" value="records">
                        <select name="year" class="owner-select" style="width:auto;" onchange="this.form.submit()">
                            <?php for ($y = $currentYear + 1; $y >= $currentYear - 3; $y--): ?>
                                <option value="<?= $y ?>" <?= (string)$y === $yearFilter ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddRecord">
                            <i class="ph ph-calendar-plus" aria-hidden="true"></i> Record leave
                        </button>
                    </form>
                </div>

                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave type</th>
                                <th>Dates</th>
                                <th>Days</th>
                                <th>Reason</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No leave recorded for <?= htmlspecialchars($yearFilter) ?> yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($records as $r): $rData = $r; ?>
                                <tr>
                                    <td>
                                        <div class="owner-cell-stack">
                                            <strong><?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?></strong>
                                            <small><?= htmlspecialchars($r['employee_number']) ?></small>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($r['leave_name']) ?></td>
                                    <td><?= htmlspecialchars(date('M j', strtotime($r['start_date']))) ?> – <?= htmlspecialchars(date('M j, Y', strtotime($r['end_date']))) ?></td>
                                    <td><?= number_format((float)$r['total_days'], 1) ?></td>
                                    <td><?= htmlspecialchars($r['reason'] ?? '—') ?></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-record"
                                                aria-label="Edit leave record" data-record='<?= htmlspecialchars(json_encode($rData), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <?php if ($r['status'] === 'approved'): ?>
                                            <form method="post" action="leave_record_cancel.php" class="leave-cancel-form" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="leave_record_id" value="<?= (int)$r['leave_record_id'] ?>">
                                                <input type="hidden" name="return_year" value="<?= htmlspecialchars($yearFilter) ?>">
                                                <input type="hidden" name="cancelled_reason" class="leave-cancel-reason-input" value="">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete leave record" title="Delete leave record">
                                                    <i class="ph ph-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <form method="post" action="leave_record_delete.php" style="display:inline;" data-confirm="Permanently delete this cancelled leave record? This can't be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="leave_record_id" value="<?= (int)$r['leave_record_id'] ?>">
                                                <input type="hidden" name="return_year" value="<?= htmlspecialchars($yearFilter) ?>">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete leave record">
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

<!-- Add / edit leave balance modal -->
<div class="owner-modal-backdrop" id="balFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="balFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="balFormTitle">Set leave balance</h2>
            <button type="button" class="owner-modal-close" id="btnCloseBalForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="leave_balance_save.php" id="balForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="leave_balance_id" id="balIdHidden" value="">
                <input type="hidden" name="return_year" value="<?= htmlspecialchars($yearFilter) ?>">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="balEmployee">Employee <span class="owner-required" aria-hidden="true">*</span></label>
                        <select data-searchable data-search-placeholder="Search employee&hellip;" id="balEmployee" name="employee_id" class="owner-select" required>
                            <option value="">Select an employee</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['employee_id'] ?>"><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_number'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="balLeaveType">Leave type <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="balLeaveType" name="leave_type_id" class="owner-select" required>
                            <option value="">Select a leave type</option>
                            <?php foreach ($leaveTypes as $lt): ?>
                                <option value="<?= (int)$lt['leave_type_id'] ?>" data-default-days="<?= htmlspecialchars($lt['default_days_per_year']) ?>"><?= htmlspecialchars($lt['leave_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="balYear">Year <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="number" id="balYear" name="year" class="owner-input" min="2020" max="2100" value="<?= htmlspecialchars($yearFilter) ?>" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="balDaysEntitled">Days entitled <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="number" id="balDaysEntitled" name="days_entitled" class="owner-input" step="0.5" min="0" required>
                        <span class="owner-form-hint">           </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelBalForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save balance</button>
            </div>
        </form>
    </div>
</div>

<!-- Add / edit leave record modal -->
<div class="owner-modal-backdrop" id="recFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="recFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="recFormTitle">Record leave</h2>
            <button type="button" class="owner-modal-close" id="btnCloseRecForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="leave_record_save.php" id="recForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="leave_record_id" id="recIdHidden" value="">
                <input type="hidden" name="return_year" value="<?= htmlspecialchars($yearFilter) ?>">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="recEmployee">Employee <span class="owner-required" aria-hidden="true">*</span></label>
                        <select data-searchable data-search-placeholder="Search employee&hellip;" id="recEmployee" name="employee_id" class="owner-select" required>
                            <option value="">Select an employee</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['employee_id'] ?>"><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_number'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="recLeaveType">Leave type <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="recLeaveType" name="leave_type_id" class="owner-select" required>
                            <option value="">Select a leave type</option>
                            <?php foreach ($leaveTypes as $lt): ?>
                                <option value="<?= (int)$lt['leave_type_id'] ?>"><?= htmlspecialchars($lt['leave_name']) ?><?= (int)$lt['is_paid'] === 0 ? ' (unpaid)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="recStartDate">Start date <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="date" id="recStartDate" name="start_date" class="owner-input" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="recEndDate">End date <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="date" id="recEndDate" name="end_date" class="owner-input" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="recTotalDays">Total days <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="number" id="recTotalDays" name="total_days" class="owner-input" step="0.5" min="0.5" required>
                        <span class="owner-form-hint">Auto-filled from the date range (inclusive) — adjust for weekends/holidays or half-days if needed.</span>
                    </div>
                    <div class="owner-form-group owner-form-group-full">
                        <label for="recReason">Reason <span class="owner-form-optional">(optional)</span></label>
                        <input type="text" id="recReason" name="reason" class="owner-input">
                    </div>
                    <input type="hidden" id="recStatus" name="status" value="approved">
                    <input type="hidden" id="recCancelledReason" name="cancelled_reason" value="">
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelRecForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save record</button>
            </div>
        </form>
    </div>
</div>

<!-- Manage leave types modal -->
<div class="owner-modal-backdrop" id="manageLeaveTypesBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="manageLeaveTypesTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="manageLeaveTypesTitle">Manage leave types</h2>
            <button type="button" class="owner-modal-close" id="btnCloseManageLeaveTypes" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body">

            <div class="owner-table-wrap" style="margin-bottom:20px;">
                <table class="owner-table">
                    <thead>
                        <tr>
                            <th>Leave type</th>
                            <th>Paid</th>
                            <th>Default days / year</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leaveTypes)): ?>
                            <tr><td colspan="4" class="owner-table-empty">No leave types yet. Add your first one below.</td></tr>
                        <?php else: ?>
                            <?php foreach ($leaveTypes as $lt): $ltData = $lt; ?>
                            <tr>
                                <td><?= htmlspecialchars($lt['leave_name']) ?></td>
                                <td><?= ((int)$lt['is_paid'] === 1) ? '<i class="ph ph-check" aria-hidden="true"></i>' : '—' ?></td>
                                <td><?= number_format((float)$lt['default_days_per_year'], 1) ?></td>
                                <td>
                                    <div class="owner-table-actions">
                                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-leave-type"
                                            aria-label="Edit leave type" data-leave-type='<?= htmlspecialchars(json_encode($ltData), ENT_QUOTES, 'UTF-8') ?>'>
                                            <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                        </button>
                                        <form method="post" action="leave_type_delete.php" style="display:inline;" data-confirm="Permanently delete this leave type? This can't be undone.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="leave_type_id" value="<?= (int)$lt['leave_type_id'] ?>">
                                            <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete leave type">
                                                <i class="ph ph-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="leave_type_save.php" id="addLeaveTypeForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="leave_type_id" id="leaveTypeFormId" value="">
                <h3 class="owner-form-section-title" id="leaveTypeFormTitle">Add leave type</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="new_leave_name">Leave name <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="text" id="new_leave_name" name="leave_name" class="owner-input" placeholder="e.g. Bereavement Leave" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="new_leave_default_days">Default days / year</label>
                        <input type="number" id="new_leave_default_days" name="default_days_per_year" class="owner-input" step="0.5" min="0" value="0" required>
                    </div>
                </div>
                <label class="owner-checkbox-row" for="new_leave_is_paid" style="margin-top:14px;">
                    <input type="checkbox" id="new_leave_is_paid" name="is_paid" value="1" class="owner-checkbox-input" checked>
                    <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                    <span class="owner-checkbox-text">
                        <span class="owner-checkbox-label">Paid leave</span>
                    </span>
                </label>
                <div style="margin-top:16px;display:flex;gap:8px;">
                    <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="leaveTypeFormSubmitLabel">Add leave type</span></button>
                    <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelEditLeaveType" hidden>Cancel edit</button>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    // -- Balances / Records tabs -----------------------------------------------
    const leaveTabs = document.querySelectorAll('[data-leave-tab]');
    const leavePanels = {
        balances: document.getElementById('leave-panel-balances'),
        records: document.getElementById('leave-panel-records'),
    };

    function showTab(key) {
        leaveTabs.forEach(t => t.classList.toggle('is-active', t.getAttribute('data-leave-tab') === key));
        Object.entries(leavePanels).forEach(([k, el]) => {
            if (el) el.style.display = (k === key) ? '' : 'none';
        });
    }

    leaveTabs.forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            showTab(tab.getAttribute('data-leave-tab'));
        });
    });

    const requestedLeaveTab = new URLSearchParams(window.location.search).get('tab');
    if (requestedLeaveTab === 'records') showTab('records');

    // -- Add / edit leave balance modal --------------------------------------
    const balBackdrop = document.getElementById('balFormBackdrop');
    const balForm = document.getElementById('balForm');
    const balTitle = document.getElementById('balFormTitle');
    const balIdField = document.getElementById('balIdHidden');
    const balEmployee = document.getElementById('balEmployee');
    const balLeaveType = document.getElementById('balLeaveType');
    const balYear = document.getElementById('balYear');
    const balDaysEntitled = document.getElementById('balDaysEntitled');

    function openBalModal() { balBackdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function closeBalModal() { balBackdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }

    document.getElementById('btnOpenAddBalance').addEventListener('click', () => {
        balForm.reset();
        balIdField.value = '';
        balYear.value = '<?= htmlspecialchars($yearFilter) ?>';
        balTitle.textContent = 'Set leave balance';
        openBalModal();
    });
    document.getElementById('btnCloseBalForm').addEventListener('click', closeBalModal);
    document.getElementById('btnCancelBalForm').addEventListener('click', closeBalModal);
    balBackdrop.addEventListener('click', (e) => { if (e.target === balBackdrop) closeBalModal(); });

    balLeaveType.addEventListener('change', () => {
        if (balIdField.value) return; // don't clobber an existing balance's entitled days when just viewing
        const opt = balLeaveType.selectedOptions[0];
        if (opt && opt.getAttribute('data-default-days')) {
            balDaysEntitled.value = opt.getAttribute('data-default-days');
        }
    });

    document.querySelectorAll('.owner-btn-edit-balance').forEach((btn) => {
        btn.addEventListener('click', () => {
            let b;
            try { b = JSON.parse(btn.getAttribute('data-balance')); } catch (e) { return; }

            balIdField.value = b.leave_balance_id;
            balEmployee.value = b.employee_id;
            balLeaveType.value = b.leave_type_id;
            balYear.value = b.year;
            balDaysEntitled.value = b.days_entitled;

            balTitle.textContent = 'Edit leave balance';
            openBalModal();
        });
    });

    <?php if (!empty($_SESSION['_reopen_balance_modal'])): unset($_SESSION['_reopen_balance_modal']); ?>
    showTab('balances');
    openBalModal();
    <?php endif; ?>

    // -- Add / edit leave record modal ---------------------------------------
    const recBackdrop = document.getElementById('recFormBackdrop');
    const recForm = document.getElementById('recForm');
    const recTitle = document.getElementById('recFormTitle');
    const recIdField = document.getElementById('recIdHidden');
    const recEmployee = document.getElementById('recEmployee');
    const recLeaveType = document.getElementById('recLeaveType');
    const recStartDate = document.getElementById('recStartDate');
    const recEndDate = document.getElementById('recEndDate');
    const recTotalDays = document.getElementById('recTotalDays');
    const recStatus = document.getElementById('recStatus');
    const recReason = document.getElementById('recReason');
    const recCancelledReason = document.getElementById('recCancelledReason');

    function openRecModal() { recBackdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function closeRecModal() { recBackdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }

    function autoFillTotalDays() {
        if (!recStartDate.value || !recEndDate.value) return;
        const start = new Date(recStartDate.value);
        const end = new Date(recEndDate.value);
        if (end < start) return;
        const days = Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1;
        recTotalDays.value = days;
    }
    recStartDate.addEventListener('change', autoFillTotalDays);
    recEndDate.addEventListener('change', autoFillTotalDays);

    document.getElementById('btnOpenAddRecord').addEventListener('click', () => {
        recForm.reset();
        recIdField.value = '';
        recStatus.value = 'approved';
        recCancelledReason.value = '';
        recTitle.textContent = 'Record leave';
        openRecModal();
    });
    document.getElementById('btnCloseRecForm').addEventListener('click', closeRecModal);
    document.getElementById('btnCancelRecForm').addEventListener('click', closeRecModal);
    recBackdrop.addEventListener('click', (e) => { if (e.target === recBackdrop) closeRecModal(); });

    document.querySelectorAll('.owner-btn-edit-record').forEach((btn) => {
        btn.addEventListener('click', () => {
            let r;
            try { r = JSON.parse(btn.getAttribute('data-record')); } catch (e) { return; }

            recIdField.value = r.leave_record_id;
            recEmployee.value = r.employee_id;
            recLeaveType.value = r.leave_type_id;
            recStartDate.value = r.start_date;
            recEndDate.value = r.end_date;
            recTotalDays.value = r.total_days;
            recStatus.value = r.status;
            recReason.value = r.reason || '';
            recCancelledReason.value = r.cancelled_reason || '';

            recTitle.textContent = 'Edit leave record';
            openRecModal();
        });
    });

    <?php if (!empty($_SESSION['_reopen_record_modal'])): unset($_SESSION['_reopen_record_modal']); ?>
    showTab('records');
    openRecModal();
    <?php endif; ?>

    document.querySelectorAll('.leave-cancel-form').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const ok = await confirmAction("Delete this leave record? This can't be undone.");
            if (!ok) return;
            const reason = prompt('Reason for deleting (optional):') || '';
            form.querySelector('.leave-cancel-reason-input').value = reason;
            form.submit();
        });
    });

    // -- Manage leave types modal ---------------------------------------------
    const ltBackdrop = document.getElementById('manageLeaveTypesBackdrop');
    const ltOpenBtn = document.getElementById('btnOpenManageLeaveTypes');
    const ltCloseBtn = document.getElementById('btnCloseManageLeaveTypes');
    const ltForm = document.getElementById('addLeaveTypeForm');
    const ltFormTitle = document.getElementById('leaveTypeFormTitle');
    const ltSubmitLabel = document.getElementById('leaveTypeFormSubmitLabel');
    const ltIdField = document.getElementById('leaveTypeFormId');
    const ltCancelEditBtn = document.getElementById('btnCancelEditLeaveType');

    function openLtModal() { ltBackdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function closeLtModal() { ltBackdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }
    function resetLtFormToAddMode() {
        ltForm.reset();
        ltIdField.value = '';
        document.getElementById('new_leave_is_paid').checked = true;
        ltFormTitle.textContent = 'Add leave type';
        ltSubmitLabel.textContent = 'Add leave type';
        ltCancelEditBtn.hidden = true;
    }

    ltOpenBtn.addEventListener('click', () => { openLtModal(); });
    ltCloseBtn.addEventListener('click', closeLtModal);
    ltCancelEditBtn.addEventListener('click', resetLtFormToAddMode);
    ltBackdrop.addEventListener('click', (e) => { if (e.target === ltBackdrop) closeLtModal(); });

    document.querySelectorAll('.owner-btn-edit-leave-type').forEach((btn) => {
        btn.addEventListener('click', () => {
            let lt;
            try { lt = JSON.parse(btn.getAttribute('data-leave-type')); } catch (e) { return; }

            ltIdField.value = lt.leave_type_id;
            document.getElementById('new_leave_name').value = lt.leave_name || '';
            document.getElementById('new_leave_default_days').value = lt.default_days_per_year;
            document.getElementById('new_leave_is_paid').checked = !!parseInt(lt.is_paid, 10);

            ltFormTitle.textContent = 'Edit leave type';
            ltSubmitLabel.textContent = 'Save changes';
            ltCancelEditBtn.hidden = false;
            openLtModal();
        });
    });

    <?php if (!empty($_SESSION['_reopen_leave_type_modal'])): unset($_SESSION['_reopen_leave_type_modal']); ?>
    openLtModal();
    <?php endif; ?>
})();
</script>

<script src="../owner/assets/js/searchable-select.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/searchable-select.js') ?>"></script>
</body>
</html>
