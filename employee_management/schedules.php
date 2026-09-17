<?php
/**
 * employee_management/schedules.php
 *
 * Read-only weekly roster -- rows are employees, columns are Mon–Sun, so a
 * week's coverage (and gaps) reads at a glance. Always exactly one week block
 * (scheduleWeekBlock(), payroll_functions.php), anchored on whatever date was
 * navigated to via Prev/Next or the week picker.
 *
 * Nothing on this page writes a schedule. Every employee_schedules row is
 * generated from the employee's own shift and work days, set on the Admin's
 * Add/Edit employee form (employees.php) -- see syncEmployeeSchedule(). That
 * pattern repeats every week with no end date; weeks past the stored window
 * (scheduleGenerationHorizon()) are filled in here from the same rule, so any
 * week can be browsed. The
 * per-cell Add/Edit modal, Bulk Assign, Copy previous week and Weekly
 * templates that used to live here were removed on 2026-09-15, and shifts
 * themselves are managed from employees.php's "Manage shifts" modal now.
 *
 * Open to Manager and Admin alike, both read-only here.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/payroll_functions.php';
require_once __DIR__ . '/includes/em_access.php';   // role split + panel chrome

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!emCanViewSchedules()) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage  = 'payroll_schedules';
$pageTitle   = 'Schedules';

$today = date('Y-m-d');
$dateFilter = trim((string)($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = $today;
}

$weekBlocks = [scheduleWeekBlock(scheduleStartOfWeek($dateFilter))];
$rangeStart = $weekBlocks[0]['start'];
$rangeEnd   = $weekBlocks[0]['end'];
$prevAnchor = scheduleAddDays($weekBlocks[0]['start'], -7);
$nextAnchor = scheduleAddDays($weekBlocks[0]['start'], 7);

$employees = [];
$scheduleMatrix = [];
$leaveMatrix = [];
$blockStats = [];
$defaultBreakMinutes = 60;
$dbError = null;
$horizon = scheduleGenerationHorizon();

try {
    $pdo = Database::getInstance()->getConnection();

    // Bring upcoming rows up to date before reading them. The 5-minute cron
    // does the same; doing it here too means this grid is never behind on a
    // machine where that scheduled task isn't registered. Idempotent -- with
    // nothing changed it writes nothing -- and a failure only costs freshness,
    // never the page.
    try {
        syncAllEmployeeSchedules($pdo);
    } catch (Throwable $e) {
        error_log('schedules.php schedule sync failed: ' . $e->getMessage());
    }

    $employees = $pdo->query(
        "SELECT e.employee_id, e.employee_number, e.first_name, e.last_name,
                e.employment_status, e.work_days, e.shift_id, e.is_active, e.date_hired, e.date_separated,
                st.start_time, st.end_time, st.is_night_shift
         FROM employees e
         LEFT JOIN shift_templates st ON st.shift_id = e.shift_id
         WHERE e.is_active = 1
         ORDER BY e.first_name ASC, e.last_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $schedStmt = $pdo->prepare(
        "SELECT s.schedule_id, s.employee_id, s.shift_id, s.schedule_date, s.scheduled_time_in, s.scheduled_time_out,
                s.is_rest_day, s.status, s.notes, st.is_night_shift
         FROM employee_schedules s
         LEFT JOIN shift_templates st ON st.shift_id = s.shift_id
         WHERE s.schedule_date BETWEEN ? AND ?"
    );
    $schedStmt->execute([$rangeStart, $rangeEnd]);
    foreach ($schedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scheduleMatrix[(int)$row['employee_id']][$row['schedule_date']] = $row;
    }

    // Past the stored window nothing is in employee_schedules yet, but the
    // schedule doesn't end there -- it repeats every week until someone changes
    // it. Fill those days in from workScheduleRowFor(), the same rule the
    // generator will store when the window reaches them, so a week in March
    // reads the same as next week. Everything below (hours, warnings, status)
    // then treats them like any other row.
    foreach ($employees as $emp) {
        $workDays = parseEmployeeWorkDays($emp['work_days']);
        if (!employeeHasActiveSchedule($emp, $workDays)) {
            continue;
        }
        $empId = (int)$emp['employee_id'];
        foreach ($weekBlocks[0]['dates'] as $d) {
            if ($d <= $horizon || isset($scheduleMatrix[$empId][$d])
                || $d < $emp['date_hired'] || ($emp['date_separated'] && $d > $emp['date_separated'])) {
                continue;
            }
            $w = workScheduleRowFor($emp, $workDays, $d);
            $scheduleMatrix[$empId][$d] = [
                'employee_id' => $empId, 'schedule_date' => $d, 'shift_id' => $w['shift_id'],
                'scheduled_time_in' => $w['in'], 'scheduled_time_out' => $w['out'], 'is_rest_day' => $w['rest'],
                'status' => 'scheduled', 'notes' => null, 'is_night_shift' => $w['rest'] ? 0 : (int)$emp['is_night_shift'],
            ];
        }
    }

    // Approved leave overlapping the visible range -- expanded into a
    // per-day dict in PHP rather than SQL-JOINed onto the schedule query,
    // so overlapping leave records for the same employee can never
    // multiply schedule rows (a dict has exactly one slot per key).
    $leaveStmt = $pdo->prepare(
        "SELECT elr.employee_id, elr.start_date, elr.end_date, lt.leave_name
         FROM employee_leave_records elr
         JOIN leave_types lt ON lt.leave_type_id = elr.leave_type_id
         WHERE elr.status = 'approved' AND elr.start_date <= ? AND elr.end_date >= ?"
    );
    $leaveStmt->execute([$rangeEnd, $rangeStart]);
    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
        $cursor = max(new DateTime($lr['start_date']), new DateTime($rangeStart));
        $stop   = min(new DateTime($lr['end_date']), new DateTime($rangeEnd));
        while ($cursor <= $stop) {
            $leaveMatrix[(int)$lr['employee_id']][$cursor->format('Y-m-d')] = $lr['leave_name'];
            $cursor->modify('+1 day');
        }
    }

    $payrollSettings = getPayrollSettings($pdo);
    $defaultBreakMinutes = (int)($payrollSettings['default_break_minutes'] ?? 60);

    // Total Hours + workload flags, computed here (not per-cell in the
    // template) so the render loop below is pure display logic.
    foreach ($weekBlocks as $bi => $block) {
        foreach ($employees as $emp) {
            $empId = (int)$emp['employee_id'];
            $total = 0.0;
            foreach ($block['dates'] as $d) {
                $row = $scheduleMatrix[$empId][$d] ?? null;
                if (!$row) {
                    continue;
                }
                if ((int)$row['is_rest_day'] === 1 || $row['scheduled_time_in'] === null || $row['scheduled_time_out'] === null) {
                    continue;
                }
                $total += scheduleShiftHours($row['scheduled_time_in'], $row['scheduled_time_out'], $defaultBreakMinutes);
            }
            /* Workload flags, raised at SCHEDULING time rather than discovered
               in payroll. Two separate rules, because they fail separately:

                 hours    -- Labor Code Art. 83 puts normal hours at 8/day, so a
                             6-day week is 48. Past that the roster is committing
                             to overtime before anyone has clocked in.
                 rest day -- Art. 91 entitles every employee to 24 consecutive
                             hours of rest after 6 consecutive working days. A
                             week with no rest day scheduled is a rule breach,
                             not merely a heavy week, so it is flagged on its own
                             even when the hours look fine.

               Advisory only: nothing blocks saving an employee's work days.
               Genuine 7-day cover exists, and the Admin -- not the roster --
               decides. The point is that it is visible here, and fixable on
               that employee's form. */
            $restDays  = 0;
            $workDays  = 0;
            foreach ($block['dates'] as $d) {
                $r = $scheduleMatrix[$empId][$d] ?? null;
                if (!$r) { continue; }
                if ((int)$r['is_rest_day'] === 1) { $restDays++; } else { $workDays++; }
            }
            $warnings = [];
            if ($total > SCHEDULE_WEEKLY_HOURS_LIMIT) {
                $warnings[] = number_format($total - SCHEDULE_WEEKLY_HOURS_LIMIT, 2)
                            . 'h over the ' . SCHEDULE_WEEKLY_HOURS_LIMIT . 'h week';
            }
            if ($workDays > 0 && $restDays === 0) {
                $warnings[] = 'no rest day scheduled';
            }

            $blockStats[$bi][$empId] = [
                'total_hours' => round($total, 2),
                'rest_days'   => $restDays,
                'work_days'   => $workDays,
                'warnings'    => $warnings,
            ];
        }
    }
} catch (PDOException $e) {
    $dbError = "Couldn't load schedule data. Please refresh this page.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Schedules | Payroll | <?= emPanelLabel() ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<style>
    /* Workload flags. Muted red text rather than a filled badge -- these are
       advisory, and a loud pill on several rows would read as breakage. */
    .sched-over{ color:var(--op-danger); }
    .sched-warn{ font-size:0.7rem; color:var(--op-danger); line-height:1.3; margin-top:2px; max-width:150px; }

    .sched-cell{ display:flex; align-items:center; justify-content:center; width:100%; min-height:38px; padding:4px; }
    .sched-cell-empty{ color:var(--op-ink-faint); }
    .sched-emp-note{ color:var(--op-danger); }
    .sched-col-date{ font-weight:400; font-size:0.72rem; color:var(--op-ink-faint); }
    .sched-time-pill{ flex-direction:column; gap:0; line-height:1.3; padding:4px 10px; text-align:center; border-radius:6px; }
    /* Prev/Next sit beside the week label, each its own separate button. */
    .sched-week-nav{ display:inline-flex; align-items:stretch; gap:4px; }
    .owner-table thead th.sched-day-col{ text-align:center; }
    th.sched-today-col{ background:var(--op-gold-soft); border-radius:6px 6px 0 0; }
    /* Wider than the shared 210px -- a name is the one thing searched here.
       .owner-inv-filter-search caps every search box at 280px, which also kept
       the phone layout (.sched-search = 100% at <=560px) from going full width,
       so the cap is lifted for this page. */
    .sched-search{ max-width:none; }
    @media (min-width: 561px){ .sched-search{ width: 340px; } }
</style>
</head>
<body>

<div class="owner-shell">

    <?php emRenderSidebar(); ?>

    <div class="owner-main">

        <?php emRenderHeader(); ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <div class="owner-card" style="margin-bottom:20px;">
                <?php // Controls sit on their own row under the heading rather than
                      // pushed to the far right of it. There are six of them, and on a
                      // laptop the right-hand cluster wrapped into a ragged block
                      // opposite the title. A full-width row below reads left to
                      // right, wraps predictably, and needs no reflow on mobile. ?>
                <?php // No heading on this card -- it holds nothing but controls, and
                      // the week picker below already names the week shown. Without a
                      // title the column layout has one child, so .sched-head is dropped
                      // too and the controls row becomes the card head itself. ?>
                <div class="owner-card-head sched-controls">
                    <div class="sched-browse-controls">
                        <div class="owner-inv-filter-search sched-search">
                            <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                            <input type="text" id="schedEmployeeSearch" placeholder="Search employee&hellip;" autocomplete="off">
                        </div>
                        <?php // Jump straight to a week. Prev/Next step one week at a time,
                              // which is fine for next week and painful for one in March.
                              // A plain date input rather than <input type="week"> because
                              // that submits an ISO "2026-W36" string this page would have to
                              // parse, and every other date control in the panel is a date
                              // input -- the page already normalises whatever it is given to
                              // the Monday of that week. ?>
                        <?php // A week list, not a day picker. Choosing a week from a calendar
                              // of days meant picking any day and trusting the page to snap to
                              // its Monday; this shows the weeks themselves. It is also plain
                              // markup and script -- the previous version depended on the
                              // browser's showPicker(), which is exactly what failed. ?>
                        <?php // Prev/Next flank the week label directly as one control --
                              // "Today" was dropped since jumping to the current week is just
                              // as fast through the week-picker popover below. ?>
                        <div class="sched-week-nav">
                            <a href="?date=<?= urlencode($prevAnchor) ?>" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon sched-week-nav-arrow" aria-label="Previous week"><i class="ph ph-caret-left" aria-hidden="true"></i></a>
                            <div class="sched-weekpick" id="schedWeekPick"
                                 data-current="<?= htmlspecialchars($weekBlocks[0]['start']) ?>">
                                <button type="button" class="sched-weekpick-face" id="schedWeekBtn"
                                        aria-haspopup="dialog" aria-expanded="false">
                                    <i class="ph ph-calendar-blank" aria-hidden="true"></i>
                                    <span><?= htmlspecialchars($weekBlocks[0]['label']) ?></span>
                                </button>

                                <div class="sched-weekpop" id="schedWeekPop" role="dialog"
                                     aria-label="Select week" hidden>
                                    <div class="sched-weekpop-head">Select week</div>
                                    <div class="sched-weekpop-nav">
                                        <button type="button" class="sched-weekpop-arrow" data-mv="-1" aria-label="Previous month">
                                            <i class="ph ph-caret-left" aria-hidden="true"></i>
                                        </button>
                                        <span class="sched-weekpop-month" id="schedWeekPopMonth"></span>
                                        <button type="button" class="sched-weekpop-arrow" data-mv="1" aria-label="Next month">
                                            <i class="ph ph-caret-right" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="sched-weekpop-list" id="schedWeekPopList"></div>
                                </div>
                            </div>
                            <a href="?date=<?= urlencode($nextAnchor) ?>" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon sched-week-nav-arrow" aria-label="Next week"><i class="ph ph-caret-right" aria-hidden="true"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <?php foreach ($weekBlocks as $bi => $block): ?>
            <?php // A bare table card, no "Week of ..." title: the week picker in the
                  // card above already shows which week this is. ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <?php foreach ($block['dates'] as $d): ?>
                                    <th class="sched-day-col<?= $d === $today ? ' sched-today-col' : '' ?>"><?= htmlspecialchars(date('D', strtotime($d))) ?><br><span class="sched-col-date"><?= htmlspecialchars(date('M j', strtotime($d))) ?></span></th>
                                <?php endforeach; ?>
                                <th>Total Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employees)): ?>
                                <tr><td colspan="9" class="owner-table-empty">No active employees.</td></tr>
                            <?php else: ?>
                                <?php foreach ($employees as $emp):
                                    $empId = (int)$emp['employee_id'];
                                    $fullName = trim($emp['first_name'] . ' ' . $emp['last_name']);
                                    $initials = mb_strtoupper(mb_substr($emp['first_name'], 0, 1) . mb_substr($emp['last_name'], 0, 1));
                                    $stat = $blockStats[$bi][$empId] ?? ['total_hours' => 0.0];
                                ?>
                                <tr data-employee-row data-name="<?= htmlspecialchars(strtolower($fullName . ' ' . $emp['employee_number'])) ?>">
                                    <td>
                                        <div class="owner-cell-stack" style="flex-direction:row;align-items:center;gap:10px;">
                                        <span class="owner-avatar owner-avatar-sm"><?= htmlspecialchars($initials) ?></span>
                                        <div class="owner-cell-stack">
                                            <strong><?= htmlspecialchars($fullName) ?></strong>
                                            <small><?= htmlspecialchars($emp['employee_number']) ?></small>
                                            <?php // Why a current or upcoming row is blank: nothing to generate
                                                  // from, or not an active employee. Past weeks show what was
                                                  // actually scheduled, so today's status says nothing about them. ?>
                                            <?php if ($block['end'] < $today): ?>
                                            <?php elseif ($emp['shift_id'] === null || parseEmployeeWorkDays($emp['work_days']) === []): ?>
                                                <small class="sched-emp-note">No shift or work days set</small>
                                            <?php elseif ($emp['employment_status'] !== 'active'): ?>
                                                <small class="sched-emp-note">Not scheduled · <?= htmlspecialchars(employmentStatusLabel($emp['employment_status'])) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        </div>
                                    </td>
                                    <?php foreach ($block['dates'] as $d):
                                        $row = $scheduleMatrix[$empId][$d] ?? null;
                                        $leaveName = $leaveMatrix[$empId][$d] ?? null;
                                        $isRest = $row && (int)$row['is_rest_day'] === 1;
                                    ?>
                                    <td>
                                        <div class="sched-cell">
                                            <?php if ($leaveName !== null): ?>
                                                <span class="owner-status-pill is-warning" title="<?= htmlspecialchars($leaveName) ?>">On leave</span>
                                            <?php elseif ($isRest): ?>
                                                <span class="owner-status-pill is-neutral">Rest day</span>
                                            <?php elseif ($row): ?>
                                                <span class="owner-status-pill sched-time-pill <?= scheduleStatusBadgeClass($row['status']) ?>"><?php if ((int)($row['is_night_shift'] ?? 0) === 1): ?><i class="ph ph-moon-stars" aria-hidden="true"></i><?php endif; ?><span><?= htmlspecialchars(formatTimeOfDay($row['scheduled_time_in'])) ?></span>
                                                    <span><?= htmlspecialchars(formatTimeOfDay($row['scheduled_time_out'])) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="sched-cell-empty">&mdash;</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php endforeach; ?>
                                    <?php /* Hours carry the warning rather than a separate column:
                                             the number and the reason it is a problem belong
                                             together, and an extra column would be empty for
                                             most rows most weeks. */ ?>
                                    <td>
                                        <strong<?= !empty($stat['warnings']) ? ' class="sched-over"' : '' ?>><?= number_format($stat['total_hours'], 2) ?></strong>
                                        <?php if (!empty($stat['warnings'])): ?>
                                            <div class="sched-warn" title="<?= htmlspecialchars(implode(' · ', $stat['warnings'])) ?>">
                                                <?= htmlspecialchars(implode(' · ', $stat['warnings'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="owner-table-empty-row sched-no-match-row" hidden>
                                    <td colspan="9" class="owner-table-empty">No employees match your filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-pagination" id="schedPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="schedPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="schedPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="schedPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>
            <?php endforeach; ?>

        </main>

    </div>

</div>

<script>
/* Week picker.
   Lists the weeks of one month and navigates to the chosen one. Weeks run
   Monday-Sunday, matching the grid below, so a week that straddles a month
   boundary is shown whole ("Aug 31 - Sep 6") rather than clipped: the grid
   would render those seven days anyway, and trimming the label would describe
   a week the page does not actually show. */
(function () {
    var root = document.getElementById('schedWeekPick');
    if (!root) return;
    var btn   = document.getElementById('schedWeekBtn');
    var pop   = document.getElementById('schedWeekPop');
    var mlbl  = document.getElementById('schedWeekPopMonth');
    var list  = document.getElementById('schedWeekPopList');
    var MON   = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var MONTH = ['January','February','March','April','May','June','July',
                 'August','September','October','November','December'];

    function iso(d) {
        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }
    function startOfWeek(d) {          // Monday
        var x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
        return x;
    }
    function addDays(d, n) {
        var x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        x.setDate(x.getDate() + n);
        return x;
    }
    function range(a, b) {
        return MON[a.getMonth()] + ' ' + a.getDate() + ' \u2013 ' +
               MON[b.getMonth()] + ' ' + b.getDate();
    }

    var current = root.getAttribute('data-current') || iso(new Date());
    var parts   = current.split('-');
    var cursor  = new Date(+parts[0], +parts[1] - 1, 1);   // month on show

    function render() {
        mlbl.textContent = MONTH[cursor.getMonth()] + ' ' + cursor.getFullYear();
        list.innerHTML = '';

        var last = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);
        var w = startOfWeek(new Date(cursor.getFullYear(), cursor.getMonth(), 1));
        var n = 1;
        // Every Monday-start week that overlaps this month.
        while (w <= last) {
            var end = addDays(w, 6);
            var key = iso(w);
            var a = document.createElement('a');
            a.className = 'sched-weekpop-item' + (key === current ? ' is-current' : '');
            a.href = '?date=' + key;
            a.innerHTML = '<span class="sched-weekpop-wk">Week ' + n + '</span>' +
                          '<span class="sched-weekpop-rg">' + range(w, end) + '</span>';
            list.appendChild(a);
            w = addDays(w, 7);
            n++;
        }
    }

    function open()  {
        render();
        pop.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
    }
    function close() {
        pop.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        pop.hidden ? open() : close();
    });

    list.addEventListener('click', function (e) { e.stopPropagation(); });

    pop.addEventListener('click', function (e) {
        var arrow = e.target.closest('.sched-weekpop-arrow');
        if (!arrow) return;
        e.stopPropagation();
        cursor = new Date(cursor.getFullYear(), cursor.getMonth() + (+arrow.dataset.mv), 1);
        render();
    });

    document.addEventListener('click', function (e) {
        if (!pop.hidden && !root.contains(e.target)) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !pop.hidden) { close(); btn.focus(); }
    });
})();
</script>
<script>
(function () {
    // -- Employee search + pagination --------------------------------------------
    // Client-side over the rows already on the page -- same pattern and page size
    // as Suppliers / Menu Items / Activity logs: the search narrows the set, the
    // pager pages through what's left, and the pager hides when it all fits.
    const PAGE_SIZE = 10;
    const schedSearch = document.getElementById('schedEmployeeSearch');
    const employeeRows = Array.from(document.querySelectorAll('[data-employee-row]'));
    const noMatchRows = Array.from(document.querySelectorAll('.sched-no-match-row'));
    const pagination = document.getElementById('schedPagination');
    const pagePrev = document.getElementById('schedPagePrev');
    const pageNext = document.getElementById('schedPageNext');
    const pageInfo = document.getElementById('schedPageInfo');
    let currentPage = 1;

    function applySchedFilters(resetPage) {
        if (resetPage) currentPage = 1;

        const q = schedSearch.value.trim().toLowerCase();
        const matched = employeeRows.filter((row) => !q || row.getAttribute('data-name').includes(q));

        const totalPages = Math.max(1, Math.ceil(matched.length / PAGE_SIZE));
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * PAGE_SIZE;
        const onPage = new Set(matched.slice(start, start + PAGE_SIZE));

        employeeRows.forEach((row) => { row.style.display = onPage.has(row) ? '' : 'none'; });
        noMatchRows.forEach((row) => { row.hidden = employeeRows.length === 0 || matched.length > 0; });

        if (pagination) pagination.hidden = matched.length === 0 || totalPages <= 1;
        if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        if (pagePrev) pagePrev.disabled = currentPage <= 1;
        if (pageNext) pageNext.disabled = currentPage >= totalPages;
    }

    schedSearch.addEventListener('input', () => applySchedFilters(true));
    if (pagePrev) pagePrev.addEventListener('click', () => { currentPage--; applySchedFilters(false); });
    if (pageNext) pageNext.addEventListener('click', () => { currentPage++; applySchedFilters(false); });
    applySchedFilters(true);
})();
</script>

</body>
</html>
