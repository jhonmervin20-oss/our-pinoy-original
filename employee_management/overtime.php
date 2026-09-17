<?php
/**
 * employee_management/overtime.php
 *
 * Overtime authorization log. A manager directly authorizes a specific
 * number of OT minutes for an employee/date -- modeled on leave.php's own
 * pattern: employee_leave_records has no "pending" state, a manager's save
 * action IS the approval, and there's no self-service request/approval
 * workflow to build. Same here: overtime_authorizations rows are born
 * status='approved' on insert (overtime_save.php), can be edited in place
 * (overtime_save.php's edit mode) while still approved, and deleted
 * outright (overtime_cancel.php -- the "Delete" trash-icon button both
 * cancels AND permanently removes the record in one step, same merged
 * cancel+delete change made to leave.php's Leave Records). Editing and
 * deleting are both scoped to a single row -- for a date with a stacked
 * "excess approval" top-up, only the specific row acted on is affected.
 *
 * Payroll (payroll_run_functions.php) pays the LESSER of what was actually
 * clocked (attendance_records.overtime_minutes, the clock-derived
 * candidate) and the SUM of approved authorized_minutes for that
 * employee/date -- no authorization on file means zero OT paid, full stop.
 *
 * UNLIKE leave, a given employee/date can carry MORE THAN ONE approved
 * authorization row on purpose: the normal flow is a manager pre-authorizes
 * an amount (often before the shift even happens), and if the employee
 * ends up clocking more than that, the manager adds a SECOND row to cover
 * the excess -- an "excess approval," which is really just another
 * authorization, approved the same way as the first. The "Clocked" column
 * below flags when the running authorized total for a date falls short of
 * what was actually clocked, so a manager knows there's a gap still
 * waiting on a decision.
 *
 * Manager-only, same gate as the rest of this module.
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
$activePage  = 'payroll_overtime';
$pageTitle   = 'Overtime';

$search = trim((string)($_GET['q'] ?? ''));

$employees = [];
$records = [];
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $employees = getActiveEmployeesForPicker($pdo);

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $recStmt = $pdo->prepare(
        "SELECT o.overtime_authorization_id, o.employee_id, o.work_date, o.authorized_minutes,
                o.status, o.created_at,
                e.employee_number, e.first_name, e.last_name,
                a.overtime_minutes AS clocked_minutes
         FROM overtime_authorizations o
         JOIN employees e ON e.employee_id = o.employee_id
         LEFT JOIN attendance_records a ON a.employee_id = o.employee_id AND a.attendance_date = o.work_date
         $whereSql
         ORDER BY o.work_date DESC, o.overtime_authorization_id DESC"
    );
    $recStmt->execute($params);
    $records = $recStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load overtime data. Please refresh this page.";
}

// Running total of APPROVED authorized minutes per employee/date, so each
// row can show whether the date's total still falls short of what was
// actually clocked (the signal that an "excess approval" top-up is
// waiting on a decision).
$totalAuthorizedByKey = [];
foreach ($records as $r) {
    if ($r['status'] === 'approved') {
        $key = $r['employee_id'] . '|' . $r['work_date'];
        $totalAuthorizedByKey[$key] = ($totalAuthorizedByKey[$key] ?? 0) + (int)$r['authorized_minutes'];
    }
}

$approvedCount = count(array_filter($records, fn($r) => $r['status'] === 'approved'));
$cancelledCount = count(array_filter($records, fn($r) => $r['status'] === 'cancelled'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Overtime | Payroll | Manager Panel | OPO! Our Pinoy Original</title>
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

            <?php /* Search + actions in their own card, then a bare table card --
                     the same split every other list page uses. No title or record
                     count: the table itself is the count. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <form method="GET" class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search employee&hellip;" autocomplete="off">
                    </div>
                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm">Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="overtime.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                    <?php endif; ?>
                    <div style="margin-left:auto;">
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddOt">
                            <i class="ph ph-plus-circle" aria-hidden="true"></i> Record Overtime
                        </button>
                    </div>
                </form>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Date</th>
                                <th>Overtime Duration</th>
                                <th>Actual Overtime</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="5" class="owner-table-empty"><?= $search !== '' ? 'No overtime records match your search.' : 'No overtime recorded yet.' ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($records as $r):
                                    $clocked = $r['clocked_minutes'] !== null ? (int)$r['clocked_minutes'] : null;
                                    $key = $r['employee_id'] . '|' . $r['work_date'];
                                    $totalAuthorizedThisDate = $totalAuthorizedByKey[$key] ?? 0;
                                    $gapMinutes = ($clocked !== null) ? max(0, $clocked - $totalAuthorizedThisDate) : 0;
                                    // Mismatch between THIS row's own authorized duration and what was
                                    // actually clocked that day -- either direction (clocked more, or
                                    // less, than authorized). Drives the "Match Clocked" quick-fix button.
                                    $mismatch = $clocked !== null && $clocked !== (int)$r['authorized_minutes'];
                                ?>
                                <tr>
                                    <td>
                                        <div class="owner-cell-stack">
                                            <strong><?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?></strong>
                                            <small><?= htmlspecialchars($r['employee_number']) ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="owner-cell-stack">
                                            <span><?= htmlspecialchars(date('M j, Y', strtotime($r['work_date']))) ?></span>
                                            <small>Recorded <?= htmlspecialchars(date('g:i A', strtotime($r['created_at']))) ?></small>
                                        </div>
                                    </td>
                                    <td><?= formatHoursMinutes((int)$r['authorized_minutes'] / 60) ?></td>
                                    <td>
                                        <?php if ($clocked === null): ?>
                                            <span style="color:var(--op-ink-faint);">Not yet clocked</span>
                                        <?php else: ?>
                                            <div class="owner-cell-stack">
                                                <span><?= formatHoursMinutes($clocked / 60) ?></span>
                                                <?php if ($r['status'] === 'approved' && $gapMinutes > 0): ?>
                                                    <small style="color:var(--op-danger);">
                                                        <?= formatHoursMinutes($gapMinutes / 60) ?> still unauthorized
                                                    </small>
                                                <?php elseif ($r['status'] === 'approved' && $clocked < (int)$r['authorized_minutes']): ?>
                                                    <small style="color:var(--op-ink-faint);">
                                                        <?= formatHoursMinutes(((int)$r['authorized_minutes'] - $clocked) / 60) ?> less than authorized
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <?php if ($r['status'] === 'approved'): ?>
                                            <?php if ($mismatch): ?>
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-match-clocked"
                                                title="Match clocked time (<?= formatHoursMinutes($clocked / 60) ?>)"
                                                data-ot='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' data-clocked="<?= (int)$clocked ?>">
                                                <i class="ph ph-arrows-clockwise" aria-hidden="true"></i> Match Clocked
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-ot"
                                                aria-label="Edit overtime record" data-ot='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="post" action="overtime_cancel.php" data-confirm="Delete this overtime record? This can't be undone." style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="overtime_authorization_id" value="<?= (int)$r['overtime_authorization_id'] ?>">
                                                <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete overtime record" title="Delete overtime record">
                                                    <i class="ph ph-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <form method="post" action="overtime_delete.php" style="display:inline;" data-confirm="Permanently delete this cancelled overtime authorization? This can't be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="overtime_authorization_id" value="<?= (int)$r['overtime_authorization_id'] ?>">
                                                <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete authorization">
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

<!-- Record overtime modal -- deliberately independent of any clocked time,
     same as leave.php's Record leave modal: a manager states the employee,
     date, and duration directly. No live "clocked minutes" lookup here --
     this is often filled in AHEAD of the shift (a true pre-authorization),
     so there's frequently nothing clocked yet to look up anyway. The
     "Clocked" column on the list below still shows the real clocked figure
     once attendance exists, for after-the-fact comparison; payroll itself
     still pays the lesser of what's authorized here and what actually gets
     clocked -- this form just doesn't make filling it in depend on that. -->
<div class="owner-modal-backdrop" id="otFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="otFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="otFormTitle">Record Overtime</h2>
            <button type="button" class="owner-modal-close" id="btnCloseOtForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="overtime_save.php" id="otForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="overtime_authorization_id" id="otIdHidden" value="">
                <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="otEmployee">Employee <span class="owner-required" aria-hidden="true">*</span></label>
                        <select data-searchable data-search-placeholder="Search employee&hellip;" id="otEmployee" name="employee_id" class="owner-select" required>
                            <option value="">Select an employee</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['employee_id'] ?>"><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_number'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="otDate">OT Date <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="date" id="otDate" name="work_date" class="owner-input" required>
                    </div>
                    <div class="owner-form-group owner-form-group-full">
                        <label>Overtime Duration <span class="owner-required" aria-hidden="true">*</span></label>
                        <div style="display:flex;gap:12px;">
                            <div style="flex:1;">
                                <input type="number" id="otHours" name="hours" class="owner-input" min="0" step="1" value="0" required placeholder="0">
                                <span class="owner-form-hint">Hours</span>
                            </div>
                            <div style="flex:1;">
                                <input type="number" id="otMinutes" name="minutes" class="owner-input" min="0" max="59" step="1" value="0" required placeholder="0">
                                <span class="owner-form-hint">Minutes</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelOtForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="otSubmitLabel">Record Overtime</span></button>
            </div>
        </form>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const backdrop = document.getElementById('otFormBackdrop');
    const form = document.getElementById('otForm');
    const title = document.getElementById('otFormTitle');
    const submitLabel = document.getElementById('otSubmitLabel');
    const idField = document.getElementById('otIdHidden');
    const employeeSelect = document.getElementById('otEmployee');
    const dateInput = document.getElementById('otDate');
    const hoursInput = document.getElementById('otHours');
    const minutesInput = document.getElementById('otMinutes');

    function openModal() { backdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function closeModal() { backdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }

    function openAdd() {
        form.reset();
        idField.value = '';
        title.textContent = 'Record Overtime';
        submitLabel.textContent = 'Record Overtime';
        openModal();
    }

    // $overrideMinutes lets the "Match Clocked" button reuse this same edit
    // flow but pre-fill the CLOCKED duration instead of the currently
    // authorized one -- the manager still reviews it in the modal and clicks
    // Save Changes themselves, rather than this silently rewriting the row.
    function openEdit(ot, overrideMinutes) {
        form.reset();
        idField.value = ot.overtime_authorization_id;
        employeeSelect.value = ot.employee_id;
        dateInput.value = ot.work_date;
        const totalMinutes = overrideMinutes !== undefined ? overrideMinutes : (parseInt(ot.authorized_minutes, 10) || 0);
        hoursInput.value = Math.floor(totalMinutes / 60);
        minutesInput.value = totalMinutes % 60;
        title.textContent = 'Edit Overtime';
        submitLabel.textContent = 'Save Changes';
        openModal();
    }

    document.getElementById('btnOpenAddOt').addEventListener('click', openAdd);
    document.getElementById('btnCloseOtForm').addEventListener('click', closeModal);
    document.getElementById('btnCancelOtForm').addEventListener('click', closeModal);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal(); });

    document.querySelectorAll('.owner-btn-edit-ot').forEach((btn) => {
        btn.addEventListener('click', () => {
            let ot;
            try { ot = JSON.parse(btn.getAttribute('data-ot')); } catch (e) { return; }
            openEdit(ot);
        });
    });

    document.querySelectorAll('.owner-btn-match-clocked').forEach((btn) => {
        btn.addEventListener('click', () => {
            let ot;
            try { ot = JSON.parse(btn.getAttribute('data-ot')); } catch (e) { return; }
            const clocked = parseInt(btn.getAttribute('data-clocked'), 10) || 0;
            openEdit(ot, clocked);
        });
    });

    <?php if (!empty($_SESSION['_reopen_ot_modal'])): unset($_SESSION['_reopen_ot_modal']); ?>
    openAdd();
    <?php endif; ?>
})();
</script>

<script src="../owner/assets/js/searchable-select.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/searchable-select.js') ?>"></script>
</body>
</html>
