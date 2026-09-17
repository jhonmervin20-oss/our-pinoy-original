<?php
/**
 * employee_management/performance_monitoring.php
 *
 * Employee Performance Monitoring -- Attendance Trends. Manager-only,
 * read-only reporting: never writes to attendance_records or
 * employee_schedules (those are owned by attendance.php's import flows and
 * the work-schedule generator, syncEmployeeSchedule(), respectively). Fills a real gap -- Manager had no
 * attendance analytics anywhere before this, only the single-date raw
 * table on attendance.php.
 *
 * Real starting-state disclosure: attendance_records has 0 rows and
 * employee_schedules has 1 row in this environment today (attendance_records
 * can only ever be populated by an import -- there's no manual-entry path).
 * Every section below has an honest empty state for that reason; this is
 * the expected day-one condition, not a bug, and fills in as imports run.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/performance_monitoring_functions.php';

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
$activePage  = 'performance_monitoring';
$pageTitle   = 'Performance Monitoring';

$validPeriods = ['last7', 'last30', 'last90', 'custom'];

$period      = in_array($_GET['period'] ?? '', $validPeriods, true) ? $_GET['period'] : 'last30';
$dateFrom    = trim((string)($_GET['date_from'] ?? ''));
$dateTo      = trim((string)($_GET['date_to'] ?? ''));
$positionRaw   = trim((string)($_GET['position_id'] ?? ''));
$search        = trim((string)($_GET['q'] ?? ''));
$autoOpenRaw   = trim((string)($_GET['employee_id'] ?? ''));

$positionId   = ($positionRaw !== '' && ctype_digit($positionRaw)) ? (int)$positionRaw : null;
$autoOpenEmployeeId = ($autoOpenRaw !== '' && ctype_digit($autoOpenRaw)) ? (int)$autoOpenRaw : null;

[$rangeStart, $rangeEnd] = resolveAttendanceReportRange($period, $dateFrom ?: null, $dateTo ?: null);

$currentQueryString = http_build_query(array_filter([
    'period' => $period, 'date_from' => $dateFrom, 'date_to' => $dateTo,
    'position_id' => $positionRaw, 'q' => $search,
], fn($v) => $v !== ''));

$dbError = null;
$positions   = [];
$employeeRows = [];
$weeklyTrend = [];

try {
    $pdo = Database::getInstance()->getConnection();

    $positions   = getPositions($pdo, true);

    $employeeRows = buildAttendanceEmployeeRows($pdo, $rangeStart, $rangeEnd, $positionId, $search ?: null);

    $weeklyTrend = buildAttendanceWeeklyTrend($pdo, $rangeStart, $rangeEnd, $positionId, $search ?: null);
} catch (PDOException $e) {
    $dbError = "Couldn't load performance monitoring data. Please refresh this page.";
    error_log('performance_monitoring.php failed: ' . $e->getMessage());
}

$weeklyTrendHasData = !empty($weeklyTrend) && array_sum(array_map(fn($row) => $row['present'] + $row['late'] + $row['absent'] + $row['leave'], $weeklyTrend)) > 0;

// Plain "Attendance Trend" -- the chart buckets by ISO week regardless of
// the period picked, and a bucket at either edge of the range is often only
// partly real (its label is clipped to $rangeStart/$rangeEnd below), so
// calling the whole thing "Weekly" claimed a regularity the chart doesn't
// actually have.
$trendCardTitle = 'Attendance Trend';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Performance Monitoring | Payroll | Manager Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
    .df-kpi-meta{ font-size:0.72rem; color:var(--op-ink-faint); margin-top:4px; }
    .df-custom-dates{ display:flex; gap:8px; align-items:center; }
    .df-custom-dates[hidden]{ display:none; }
    .atr-chart-wrap{ position:relative; height:280px; margin-top:12px; }

    .atr-sortable{ cursor:pointer; user-select:none; }
    .atr-sortable:hover{ color:var(--op-gold); }
    .atr-row-clickable{ cursor:pointer; }
    @media (max-width: 900px){ .atr-chart-wrap{ height:220px; } }
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/../manager/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/../manager/includes/header.php'; ?>

        <main class="owner-content">

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <!-- ================= Filter bar ================= -->
            <div class="owner-card">
                <form method="get" class="owner-inv-filters" style="margin:0;" id="atrFilterForm" data-autosubmit="performance">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search employee&hellip;" autocomplete="off">
                    </div>

                    <select name="period" class="owner-select" id="atrPeriodSelect" onchange="this.form.submit()">
                        <option value="last7" <?= $period === 'last7' ? 'selected' : '' ?>>Last 7 days</option>
                        <option value="last30" <?= $period === 'last30' ? 'selected' : '' ?>>Last 30 days</option>
                        <option value="last90" <?= $period === 'last90' ? 'selected' : '' ?>>Last 90 days</option>
                        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom range</option>
                    </select>

                    <div class="df-custom-dates" id="atrCustomDates" <?= $period === 'custom' ? '' : 'hidden' ?>>
                        <input type="date" name="date_from" class="owner-input" value="<?= htmlspecialchars($dateFrom ?: $rangeStart->format('Y-m-d')) ?>">
                        <span>&ndash;</span>
                        <input type="date" name="date_to" class="owner-input" value="<?= htmlspecialchars($dateTo ?: $rangeEnd->format('Y-m-d')) ?>">
                    </div>

                    <select name="position_id" class="owner-select" onchange="this.form.submit()">
                        <option value="">All positions</option>
                        <?php foreach ($positions as $pos): ?>
                            <option value="<?= (int)$pos['position_id'] ?>" <?= $positionId === (int)$pos['position_id'] ? 'selected' : '' ?>><?= htmlspecialchars($pos['position_title']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <a href="performance_monitoring.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>

                    <?php /* "Last 30 days" is relative and tells you nothing about which
                             actual dates that resolved to -- shown here so it never has to
                             be worked out by hand. Same start/end DateTimes the query itself
                             ran against, so this can't drift from what's actually on screen.
                             scheduleWeekLabel() assumes a real multi-day span (it was built
                             for a 7-day block) and renders a single-day one as "Sep 15–15,
                             2026" -- guarded here since Today/Yesterday collapse start to end. */ ?>
                    <span class="owner-form-hint" style="margin-left:auto;white-space:nowrap;">
                        <?= htmlspecialchars(
                            $rangeStart->format('Y-m-d') === $rangeEnd->format('Y-m-d')
                                ? $rangeStart->format('M j, Y')
                                : scheduleWeekLabel($rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'))
                        ) ?>
                    </span>
                </form>
            </div>

            <!-- ================= Weekly trend chart ================= -->
            <div class="owner-card">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title"><?= htmlspecialchars($trendCardTitle) ?></h2>
                    </div>
                </div>
                <?php if (!$weeklyTrendHasData): ?>
                    <?= attendanceEmptyState('ph-chart-bar', 'No attendance records yet for this period.', 'This chart fills in once attendance is imported (biometric or CSV) for the selected range.') ?>
                <?php else: ?>
                    <div class="atr-chart-wrap"><canvas id="atrWeeklyTrendChart"></canvas></div>
                <?php endif; ?>
            </div>

            <!-- ================= Employee table ================= -->
            <div class="owner-card">
                <div class="owner-card-head">
                    
                </div>
                <?php if (empty($employeeRows)): ?>
                    <?= attendanceEmptyState('ph-users-three', 'No active employees match these filters.', 'Adjust the position or search filter above.') ?>
                <?php endif; ?>
                <?php if (!empty($employeeRows)): ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table" id="atrEmployeeTable">
                            <thead>
                                <tr>
                                    <th class="atr-sortable" data-sort="name">Name</th>
                                    <th class="num atr-sortable" data-sort="present">Present days</th>
                                    <th class="num atr-sortable" data-sort="late">Late days</th>
                                    <th class="num atr-sortable" data-sort="absent">Absent days</th>
                                    <th class="num atr-sortable" data-sort="leave">Leave days</th>
                                    <th class="num atr-sortable" data-sort="rate">Attendance rate</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($employeeRows as $row): ?>
                                <tr class="atr-row-clickable" data-employee-id="<?= (int)$row['employee_id'] ?>" data-employee-name="<?= htmlspecialchars($row['full_name']) ?>"
                                    data-name="<?= htmlspecialchars(strtolower($row['full_name'])) ?>"
                                    data-present="<?= (int)$row['present_days'] ?>" data-late="<?= (int)$row['late_days'] ?>"
                                    data-absent="<?= (int)$row['absent_days'] ?>" data-leave="<?= (int)$row['leave_days'] ?>"
                                    data-rate="<?= $row['attendance_rate'] ?? -1 ?>">
                                    <td><strong><?= htmlspecialchars($row['full_name']) ?></strong><div style="font-size:0.72rem;color:var(--op-ink-faint);"><?= htmlspecialchars($row['position_title'] ?? '') ?></div></td>
                                    <td class="num"><?= attendanceDaysLabel($row['present_days']) ?></td>
                                    <td class="num"><?= attendanceDaysLabel($row['late_days']) ?></td>
                                    <td class="num"><?= attendanceDaysLabel($row['absent_days']) ?></td>
                                    <td class="num"><?= attendanceDaysLabel($row['leave_days']) ?></td>
                                    <td class="num"><?= $row['attendance_rate'] !== null ? number_format($row['attendance_rate'], 1) . '%' : '&mdash;' ?></td>
                                    <td><span class="owner-status-pill <?= attendanceStatusFlagBadgeClass($row['status_flag']) ?>"><?= htmlspecialchars(attendanceStatusFlagLabel($row['status_flag'])) ?></span></td>
                                    <?php // The row itself already opens this, but an unlabelled
                                          // clickable row is only discoverable by accident. The
                                          // button names the thing it opens. ?>
                                    <td class="atr-dtr-cell">
                                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm atr-dtr-btn">
                                            DTR
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<!-- Employee daily attendance drill-down modal -->
<div class="owner-modal-backdrop" id="atrDetailModalBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="atrDetailModalTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="atrDetailModalTitle">Daily Time Record</h2>
            <button type="button" class="owner-modal-close" id="atrDetailModalClose" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body" id="atrDetailModalBody" style="background:var(--op-canvas);">
            <div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>
        </div>
        <div class="owner-modal-footer">
            <?php // owner-panel.css's @media print already hides everything except an
                  // open modal, and hides the modal's own header close button and footer
                  // with it -- so this prints the record and nothing else, no separate
                  // print view needed. ?>
            <button type="button" class="owner-btn owner-btn-secondary" id="atrDetailModalPrint">
                <i class="ph ph-printer" aria-hidden="true"></i> Print
            </button>
            <button type="button" class="owner-btn owner-btn-secondary" id="atrDetailModalCloseFooter">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    var periodSelect = document.getElementById('atrPeriodSelect');
    var customDates  = document.getElementById('atrCustomDates');
    if (periodSelect && customDates) {
        periodSelect.addEventListener('change', function () {
            customDates.hidden = periodSelect.value !== 'custom';
        });
    }

    <?php if ($weeklyTrendHasData): ?>
    (function () {
        var ctx = document.getElementById('atrWeeklyTrendChart');
        if (!ctx || !window.Chart) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($k) => attendanceWeekBucketLabel($k, $rangeStart, $rangeEnd), array_keys($weeklyTrend))) ?>,
                datasets: [
                    { label: 'Present', data: <?= json_encode(array_column($weeklyTrend, 'present')) ?>, backgroundColor: '#4caf7d' },
                    { label: 'Late', data: <?= json_encode(array_column($weeklyTrend, 'late')) ?>, backgroundColor: '#d1a13a' },
                    { label: 'Absent', data: <?= json_encode(array_column($weeklyTrend, 'absent')) ?>, backgroundColor: '#c0564a' },
                    { label: 'Leave', data: <?= json_encode(array_column($weeklyTrend, 'leave')) ?>, backgroundColor: '#7e93c9' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var value = context.parsed.y;
                                return context.dataset.label + ': ' + value + (value === 1 ? ' day' : ' days');
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Days' } }
                }
            }
        });
    })();
    <?php endif; ?>

    // -- Employee table: client-side column sort ------------------------------
    var table = document.getElementById('atrEmployeeTable');
    if (table) {
        var tbody = table.querySelector('tbody');
        table.querySelectorAll('th.atr-sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-sort');
                var rows = Array.from(tbody.querySelectorAll('tr'));
                var asc = th.getAttribute('data-sort-dir') !== 'asc';
                table.querySelectorAll('th.atr-sortable').forEach(function (t) { t.removeAttribute('data-sort-dir'); });
                th.setAttribute('data-sort-dir', asc ? 'asc' : 'desc');

                var attr = { name: 'data-name', present: 'data-present', late: 'data-late', absent: 'data-absent', leave: 'data-leave', rate: 'data-rate' }[key];
                rows.sort(function (a, b) {
                    var av = a.getAttribute(attr), bv = b.getAttribute(attr);
                    if (key === 'name') {
                        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
                    }
                    return asc ? (parseFloat(av) - parseFloat(bv)) : (parseFloat(bv) - parseFloat(av));
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
            });
        });
    }

    // -- Row click -> daily drill-down modal (AJAX fragment, same mechanism as employee_management/run_detail.php's payslip modal) --
    var backdrop = document.getElementById('atrDetailModalBackdrop');
    var body = document.getElementById('atrDetailModalBody');
    var title = document.getElementById('atrDetailModalTitle');

    function openModal() { backdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function closeModal() { backdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }

    // The record covers whatever range the page is filtered to, and states that
    // range in its own header -- no second set of date inputs inside the modal.
    function openEmployeeDetail(employeeId, employeeName) {
        title.textContent = employeeName ? (employeeName + ' — Daily Time Record') : 'Daily Time Record';
        body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>';
        printBtn.disabled = true;
        printEmployeeId = employeeId;
        openModal();

        var qs = new URLSearchParams(<?= json_encode($currentQueryString) ?>);
        qs.set('employee_id', employeeId);
        fetch('performance_monitoring_detail_fragment.php?' + qs.toString())
            .then(function (res) { return res.text(); })
            .then(function (html) { body.innerHTML = html; printBtn.disabled = false; })
            .catch(function () { body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-danger);">Couldn\'t load this employee\'s Daily Time Record.</div>'; });
    }

    if (tbody) {
        tbody.querySelectorAll('tr[data-employee-id]').forEach(function (row) {
            row.addEventListener('click', function () {
                openEmployeeDetail(row.getAttribute('data-employee-id'), row.getAttribute('data-employee-name'));
            });
        });
    }

    // Print opens the standalone DTR document (dtr_print.php) rather than
    // printing this modal. window.print() here put the panel's screen styling
    // on paper -- coloured status pills, tinted card -- and a DTR is a signed
    // payroll document, so it gets its own plain black-and-white sheet, the
    // same way payslips and the payroll summary already do.
    // Still disabled until the fragment loads: the button is meaningless until
    // an employee is actually open.
    var printBtn = document.getElementById('atrDetailModalPrint');
    var printEmployeeId = null;
    printBtn.addEventListener('click', function () {
        if (printEmployeeId === null) { return; }
        var pq = new URLSearchParams(<?= json_encode($currentQueryString) ?>);
        pq.set('employee_id', printEmployeeId);
        window.open('dtr_print.php?' + pq.toString(), '_blank', 'noopener');
    });

    document.getElementById('atrDetailModalClose').addEventListener('click', closeModal);
    document.getElementById('atrDetailModalCloseFooter').addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal(); });

    <?php if ($autoOpenEmployeeId !== null): ?>
    // Deep link from the attendance-critical notification (config/attendance_alerts.php's
    // sweepAttendanceThresholdAlerts() -> manager/includes/notification_functions.php).
    openEmployeeDetail(<?= json_encode($autoOpenEmployeeId) ?>, null);
    <?php endif; ?>
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
<script src="../owner/assets/js/filter-autosubmit.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-autosubmit.js') ?>"></script>
</body>
</html>
