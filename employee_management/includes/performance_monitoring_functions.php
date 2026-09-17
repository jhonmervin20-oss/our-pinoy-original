<?php
/**
 * employee_management/includes/performance_monitoring_functions.php
 *
 * Employee Performance Monitoring -- Attendance Trends (employee_management/performance_monitoring.php).
 * Manager-only, read-only: nothing here ever writes to attendance_records or
 * employee_schedules. Requires payroll_functions.php for getPositions()/
 * attendanceStatusBadgeClass()/attendanceStatusLabel() -- reused directly
 * rather than re-declared here.
 *
 * "Scheduled days" denominator (attendance rate, absenteeism rate, no-show
 * rate) = employee_schedules rows in range where is_rest_day=0, the date
 * isn't an active holiday, and status NOT IN ('cancelled','on_leave') --
 * deliberately narrower than the literal spec ("excluding rest_day/holiday"
 * only), since an approved leave day or a cancelled shift isn't a real
 * attendance failure and would otherwise unfairly drag the rate down.
 *
 * Period bucketing (resolveAttendanceReportRange()/attendanceDenseDailyMap()/
 * attendanceRollUpWeekly()) is a small local copy of the same approach
 * owner/includes/demand_forecast_functions.php already established
 * (PHP DateTime bucketing, not SQL DATE_FORMAT/YEARWEEK) -- copied rather
 * than cross-required from owner/includes/, matching this app's per-module
 * convention to keep the collision blast radius small.
 */

require_once __DIR__ . '/payroll_functions.php';

const ATTENDANCE_GOOD_THRESHOLD_PCT  = 95.0;
const ATTENDANCE_WATCH_THRESHOLD_PCT = 85.0;
const ATTENDANCE_MIN_SAMPLE_SCHEDULED_DAYS = 5; // sweepAttendanceThresholdAlerts()'s gate against flagging a brand-new hire off one bad day

// ---------------------------------------------------------------------
// Period resolution (local copy, see module docblock)
// ---------------------------------------------------------------------

function resolveAttendanceReportRange(string $period, ?string $dateFrom, ?string $dateTo): array
{
    $today = new DateTime('today');

    switch ($period) {
        case 'yesterday':
            $start = (clone $today)->modify('-1 day');
            $end   = clone $start;
            break;
        case 'today':
            $start = clone $today;
            $end   = clone $today;
            break;
        case 'last7':
            $start = (clone $today)->modify('-6 days');
            $end   = clone $today;
            break;
        case 'last90':
            $start = (clone $today)->modify('-89 days');
            $end   = clone $today;
            break;
        case 'custom':
            $parsedFrom = $dateFrom ? DateTime::createFromFormat('Y-m-d', $dateFrom) : false;
            $parsedTo   = $dateTo ? DateTime::createFromFormat('Y-m-d', $dateTo) : false;
            $start = $parsedFrom ?: (clone $today)->modify('-29 days');
            $end   = $parsedTo ?: clone $today;
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }
            break;
        case 'last30':
        default:
            $start = (clone $today)->modify('-29 days');
            $end   = clone $today;
            break;
    }

    $start->setTime(0, 0, 0);
    $end->setTime(0, 0, 0);

    return [$start, $end];
}

/** The equal-length window immediately preceding $start..$end, for KPI vs-previous-period deltas. */
function attendancePreviousRange(DateTime $start, DateTime $end): array
{
    $lengthDays = (int)$start->diff($end)->days + 1;
    $prevEnd    = (clone $start)->modify('-1 day');
    $prevStart  = (clone $prevEnd)->modify('-' . ($lengthDays - 1) . ' days');

    return [$prevStart, $prevEnd];
}

function attendanceReportPeriodLabel(string $period, DateTime $start, DateTime $end): string
{
    $labels = ['today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'last90' => 'Last 90 days'];
    $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
    $range   = $start->format('M j, Y') . ($sameDay ? '' : ' – ' . $end->format('M j, Y'));

    return ($labels[$period] ?? 'Custom range') . ' (' . $range . ')';
}

/** Sparse day=>value map -> every day in range filled with 0 where missing. */
function attendanceDenseDailyMap(array $sparseMap, DateTime $start, DateTime $end): array
{
    $dense  = [];
    $cursor = clone $start;
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $dense[$key] = $sparseMap[$key] ?? 0.0;
        $cursor->modify('+1 day');
    }
    return $dense;
}

/** Rolls a dense day=>value map up into ISO-week buckets. */
function attendanceRollUpWeekly(array $denseDailyMap): array
{
    $buckets = [];
    foreach ($denseDailyMap as $dateStr => $value) {
        $bucketKey = (new DateTime($dateStr))->format('o-\WW');
        $buckets[$bucketKey] = ($buckets[$bucketKey] ?? 0.0) + $value;
    }
    ksort($buckets);
    return $buckets;
}

/**
 * Converts an ISO year-week bucket key from attendanceRollUpWeekly() (e.g.
 * "2026-W33") into a date range for chart axis/tooltip labels -- "Aug 10 -
 * 16" within one month, "Aug 31 - Sep 6" spanning two. Raw "2026-W33" keys
 * read as a system code, not a week a manager recognises.
 *
 * $rangeStart/$rangeEnd clip the label to the dates actually queried: the
 * first and last ISO weeks a report range touches are almost never
 * themselves Monday-to-Sunday (a "Last 7 days" ending today, say, starts
 * mid-week), and the bucket's VALUE already only counts real in-range days
 * (attendanceDenseDailyMap() zero-fills the rest) -- labelling it with the
 * full calendar week regardless used to claim days outside the selection
 * entirely, once printing a future date range that had not happened yet.
 */
function attendanceWeekBucketLabel(string $isoWeekKey, ?DateTime $rangeStart = null, ?DateTime $rangeEnd = null): string
{
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $isoWeekKey, $m)) {
        return $isoWeekKey;
    }
    $monday = new DateTime();
    $monday->setISODate((int)$m[1], (int)$m[2]);
    $sunday = (clone $monday)->modify('+6 days');

    $labelStart = ($rangeStart !== null && $rangeStart > $monday) ? $rangeStart : $monday;
    $labelEnd   = ($rangeEnd !== null && $rangeEnd < $sunday) ? $rangeEnd : $sunday;

    return $labelStart->format('M j') . ' - ' . ($labelStart->format('M') === $labelEnd->format('M') ? $labelEnd->format('j') : $labelEnd->format('M j'));
}

/** Merges several bucket=>value series (same bucket keys) into one bucket=>[series_name=>value] grid. */
function attendanceMergeSeriesIntoGrid(array $namedSeries): array
{
    if (empty($namedSeries)) {
        return [];
    }
    $buckets = array_keys(reset($namedSeries));
    $grid = [];
    foreach ($buckets as $bucket) {
        foreach ($namedSeries as $name => $series) {
            $grid[$bucket][$name] = $series[$bucket] ?? 0;
        }
    }
    return $grid;
}

// ---------------------------------------------------------------------
// Status flag (Good / Needs attention / Critical / No data)
// ---------------------------------------------------------------------

/** "0 days" / "1 day" / "2 days" -- the unit spelled out on each count cell, not just the column header. */
function attendanceDaysLabel(int $days): string
{
    return number_format($days) . ' ' . ($days === 1 ? 'day' : 'days');
}

function attendanceStatusFlag(?float $attendanceRate): string
{
    if ($attendanceRate === null) {
        return 'no_data';
    }
    if ($attendanceRate >= ATTENDANCE_GOOD_THRESHOLD_PCT) {
        return 'good';
    }
    if ($attendanceRate >= ATTENDANCE_WATCH_THRESHOLD_PCT) {
        return 'watch';
    }
    return 'critical';
}

function attendanceStatusFlagBadgeClass(string $flag): string
{
    return match ($flag) {
        'good'     => 'is-success',
        'watch'    => 'is-warning',
        'critical' => 'is-critical',
        default    => 'is-neutral',
    };
}

function attendanceStatusFlagLabel(string $flag): string
{
    return match ($flag) {
        'good'     => 'Good',
        'watch'    => 'Needs attention',
        'critical' => 'Critical',
        default    => 'No data',
    };
}

// ---------------------------------------------------------------------
// Core per-employee aggregation
// ---------------------------------------------------------------------

/**
 * One row per active employee: scheduled/present/late/absent counts, rates,
 * avg late minutes, overtime frequency/approval, and a Good/Needs attention/
 * Critical status flag.
 */
/**
 * Raw attendance_records rows (one per employee-day: status, late_minutes,
 * overtime_minutes, and whether that date is an active holiday) for
 * employees matching the position/search filters, within range -- the
 * SINGLE shared dataset both buildAttendanceEmployeeRows() (aggregates by
 * employee, for the table) and buildAttendanceWeeklyTrend() (aggregates by
 * week, for the chart) build from. Deriving both views from one query,
 * rather than each running its own independent SQL, is what actually
 * guarantees they can never quietly drift apart -- two separately-written
 * queries can agree today and diverge the next time either one is edited;
 * one shared raw fetch can't.
 */
function fetchAttendanceStatusRows(PDO $db, DateTime $start, DateTime $end, ?int $positionId, ?string $search, ?int $employeeId = null): array
{
    $where = ["e.is_active = 1", "e.employment_status = 'active'", "ar.attendance_date BETWEEN ? AND ?"];
    $params = [$start->format('Y-m-d'), $end->format('Y-m-d')];

    if ($employeeId !== null) {
        $where[] = "e.employee_id = ?";
        $params[] = $employeeId;
    }
    if ($positionId !== null) {
        $where[] = "e.position_id = ?";
        $params[] = $positionId;
    }
    if ($search !== null && $search !== '') {
        $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $stmt = $db->prepare(
        "SELECT ar.employee_id, ar.attendance_date, ar.status, ar.late_minutes, ar.overtime_minutes,
                (ar.attendance_date IN (SELECT holiday_date FROM holidays WHERE is_active = 1)) AS is_holiday
         FROM attendance_records ar
         JOIN employees e ON e.employee_id = ar.employee_id
         WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildAttendanceEmployeeRows(PDO $db, DateTime $start, DateTime $end, ?int $positionId, ?string $search, ?int $employeeId = null): array
{
    $where = ["e.is_active = 1", "e.employment_status = 'active'"];
    $params = [];

    if ($employeeId !== null) {
        $where[] = "e.employee_id = ?";
        $params[] = $employeeId;
    }
    if ($positionId !== null) {
        $where[] = "e.position_id = ?";
        $params[] = $positionId;
    }
    if ($search !== null && $search !== '') {
        $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $employeeStmt = $db->prepare(
        "SELECT e.employee_id, e.employee_number, e.first_name, e.last_name,
                e.position_id, p.position_title
         FROM employees e
         LEFT JOIN positions p ON p.position_id = e.position_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY e.first_name ASC, e.last_name ASC"
    );
    $employeeStmt->execute($params);
    $employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($employees)) {
        return [];
    }

    $rangeParams = [$start->format('Y-m-d'), $end->format('Y-m-d')];

    /* A scheduled day only counts once attendance for it has actually been
       recorded. Without the EXISTS below, today and any day whose punches have
       not been imported yet sit in the denominator with nothing to match in the
       numerator, so every employee's rate decays as the day goes on: six staff
       here read 79.2% purely because Sep 7-8 had no records yet, which dropped
       all of them under the 85% line and flagged the whole roster "Critical"
       when the real figure was 86.4% ("Watch").

       Days that were genuinely missed are not let off: the absence sweep writes
       an 'absent' record for them, which is a record, so they re-enter the
       denominator and count against the employee as soon as it runs. */
    $scheduleStmt = $db->prepare(
        "SELECT es.employee_id,
                COUNT(*) AS scheduled_days
         FROM employee_schedules es
         WHERE es.schedule_date BETWEEN ? AND ?
           AND es.is_rest_day = 0
           AND es.status NOT IN ('cancelled', 'on_leave')
           AND es.schedule_date NOT IN (SELECT holiday_date FROM holidays WHERE is_active = 1)
           AND EXISTS (SELECT 1 FROM attendance_records ar
                       WHERE ar.employee_id = es.employee_id
                         AND ar.attendance_date = es.schedule_date)
         GROUP BY es.employee_id"
    );
    $scheduleStmt->execute($rangeParams);
    $scheduleByEmployee = [];
    foreach ($scheduleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scheduleByEmployee[(int)$row['employee_id']] = $row;
    }

    // Same raw rows buildAttendanceWeeklyTrend() aggregates by week instead
    // of by employee -- see fetchAttendanceStatusRows()'s own docblock for
    // why sharing this one query (rather than each view running its own)
    // is what keeps the table and chart from ever quietly drifting apart.
    $statusRows = fetchAttendanceStatusRows($db, $start, $end, $positionId, $search, $employeeId);
    $attendanceByEmployee = [];
    foreach ($statusRows as $row) {
        $empId = (int)$row['employee_id'];
        if (!isset($attendanceByEmployee[$empId])) {
            $attendanceByEmployee[$empId] = [
                'present_or_late_days' => 0, 'present_days' => 0, 'late_days' => 0, 'on_time_days' => 0,
                'absent_days' => 0, 'late_minutes_sum' => 0, 'late_minutes_count' => 0, 'overtime_count' => 0,
            ];
        }
        $bucket = &$attendanceByEmployee[$empId];
        $status = $row['status'];
        $lateMinutes = (int)$row['late_minutes'];

        if ($status === 'present' || $status === 'late') {
            $bucket['present_or_late_days']++;
        }
        if ($status === 'present') {
            $bucket['present_days']++;
            if ($lateMinutes === 0) {
                $bucket['on_time_days']++;
            }
        }
        if ($status === 'late') {
            $bucket['late_days']++;
        }
        if ($status === 'absent' && !$row['is_holiday']) {
            $bucket['absent_days']++;
        }
        if ($lateMinutes > 0) {
            $bucket['late_minutes_sum'] += $lateMinutes;
            $bucket['late_minutes_count']++;
        }
        if ((int)$row['overtime_minutes'] > 0) {
            $bucket['overtime_count']++;
        }
        unset($bucket);
    }

    // Leave days -- sourced from employee_leave_records (the real,
    // authoritative leave system), never from employee_schedules.status or
    // attendance_records.status = 'on_leave' (manually-set flags that can
    // go out of sync with the real leave records). Mirrors
    // payroll_run_functions.php's own leave-day clipping exactly: a leave
    // record can span beyond this report's own date range, so only the
    // OVERLAP counts, and a rest day within that overlap is excluded --
    // it was never a scheduled day this report counts anywhere else either
    // (see $scheduleStmt above), so leave filed on it shouldn't inflate a
    // "days on leave" figure meant to read alongside Days present/Late/
    // Absences, all of which are already scoped to real workdays only.
    $employeeIds = array_column($employees, 'employee_id');
    $inPlaceholders = implode(',', array_fill(0, count($employeeIds), '?'));

    $restDayStmt = $db->prepare(
        "SELECT employee_id, schedule_date FROM employee_schedules
         WHERE employee_id IN ($inPlaceholders) AND schedule_date BETWEEN ? AND ? AND is_rest_day = 1"
    );
    $restDayStmt->execute(array_merge($employeeIds, $rangeParams));
    $restDayDatesByEmployee = [];
    foreach ($restDayStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $restDayDatesByEmployee[(int)$row['employee_id']][$row['schedule_date']] = true;
    }

    $leaveStmt = $db->prepare(
        "SELECT employee_id, start_date, end_date FROM employee_leave_records
         WHERE employee_id IN ($inPlaceholders) AND status = 'approved'
           AND start_date <= ? AND end_date >= ?"
    );
    $leaveStmt->execute(array_merge($employeeIds, [$end->format('Y-m-d'), $start->format('Y-m-d')]));
    $leaveDaysByEmployee = [];
    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
        $empId = (int)$leave['employee_id'];
        $overlapStart = max($leave['start_date'], $start->format('Y-m-d'));
        $overlapEnd = min($leave['end_date'], $end->format('Y-m-d'));
        if ($overlapEnd < $overlapStart) {
            continue;
        }
        $cursor = new DateTime($overlapStart);
        $leaveEnd = new DateTime($overlapEnd);
        while ($cursor <= $leaveEnd) {
            $dateKey = $cursor->format('Y-m-d');
            if (!isset($restDayDatesByEmployee[$empId][$dateKey])) {
                $leaveDaysByEmployee[$empId] = ($leaveDaysByEmployee[$empId] ?? 0) + 1;
            }
            $cursor->modify('+1 day');
        }
    }

    $rows = [];
    foreach ($employees as $emp) {
        $empId = (int)$emp['employee_id'];
        $sched = $scheduleByEmployee[$empId] ?? ['scheduled_days' => 0];
        $att   = $attendanceByEmployee[$empId] ?? [
            'present_or_late_days' => 0, 'present_days' => 0, 'late_days' => 0, 'on_time_days' => 0,
            'absent_days' => 0, 'late_minutes_sum' => 0, 'late_minutes_count' => 0,
            'overtime_count' => 0,
        ];

        $scheduledDays = (int)$sched['scheduled_days'];
        $presentOrLate = (int)$att['present_or_late_days'];
        $presentDays   = (int)$att['present_days'];
        $lateDays      = (int)$att['late_days'];
        $absentDays    = (int)$att['absent_days'];
        $lateMinCount  = (int)$att['late_minutes_count'];
        $otCount       = (int)$att['overtime_count'];
        $leaveDays     = $leaveDaysByEmployee[$empId] ?? 0;

        $attendanceRate  = $scheduledDays > 0 ? ($presentOrLate / $scheduledDays) * 100 : null;
        $onTimeRate      = $presentOrLate > 0 ? ((int)$att['on_time_days'] / $presentOrLate) * 100 : null;
        $absenteeismRate = $scheduledDays > 0 ? ($absentDays / $scheduledDays) * 100 : null;
        $avgLateMinutes  = $lateMinCount > 0 ? (float)$att['late_minutes_sum'] / $lateMinCount : null;

        $rows[] = [
            'employee_id'          => $empId,
            'employee_number'      => $emp['employee_number'],
            'full_name'            => trim($emp['first_name'] . ' ' . $emp['last_name']),
            'position_title'       => $emp['position_title'],
            'scheduled_days'       => $scheduledDays,
            'present_days'         => $presentDays,
            'late_days'            => $lateDays,
            'present_or_late_days' => $presentOrLate,
            'absent_days'          => $absentDays,
            'leave_days'           => $leaveDays,
            'attendance_rate'      => $attendanceRate,
            'on_time_rate'         => $onTimeRate,
            'absenteeism_rate'     => $absenteeismRate,
            'avg_late_minutes'     => $avgLateMinutes,
            'overtime_count'       => $otCount,
            'status_flag'          => attendanceStatusFlag($attendanceRate),
        ];
    }

    return $rows;
}

/** Aggregate KPI totals across a set of buildAttendanceEmployeeRows() rows (weighted by each employee's own scheduled-days, not a plain average of rates). */
function computeAttendanceKpiSummary(array $employeeRows): array
{
    $totalScheduled = array_sum(array_column($employeeRows, 'scheduled_days'));
    $totalPresentOrLate = array_sum(array_column($employeeRows, 'present_or_late_days'));
    $totalOnTime = 0;
    foreach ($employeeRows as $row) {
        if ($row['on_time_rate'] !== null) {
            $totalOnTime += (int)round($row['on_time_rate'] / 100 * $row['present_or_late_days']);
        }
    }
    $totalAbsences = array_sum(array_column($employeeRows, 'absent_days'));
    $totalLate = array_sum(array_column($employeeRows, 'late_days'));
    $totalOvertime = array_sum(array_column($employeeRows, 'overtime_count'));

    return [
        'attendance_rate'   => $totalScheduled > 0 ? ($totalPresentOrLate / $totalScheduled) * 100 : null,
        'on_time_rate'      => $totalPresentOrLate > 0 ? ($totalOnTime / $totalPresentOrLate) * 100 : null,
        'total_absences'    => $totalAbsences,
        'total_late'        => $totalLate,
        'absenteeism_rate'  => $totalScheduled > 0 ? ($totalAbsences / $totalScheduled) * 100 : null,
        'overtime_count'    => $totalOvertime,
    ];
}

// ---------------------------------------------------------------------
// Weekly trend chart data
// ---------------------------------------------------------------------

/**
 * Per-day count of one status, from the SAME raw $statusRows
 * fetchAttendanceStatusRows() returns for buildAttendanceEmployeeRows() --
 * see that function's docblock for why sharing the raw fetch, rather than
 * running an independent query per status, is what keeps the table and
 * chart from ever quietly drifting apart.
 */
function bucketAttendanceStatusRowsByDay(array $statusRows, string $status, DateTime $start, DateTime $end): array
{
    $sparse = [];
    foreach ($statusRows as $row) {
        if ($row['status'] !== $status) {
            continue;
        }
        // Only the 'absent' bucket needs the holiday carve-out -- present/late
        // attendance actually logged on a worked holiday should still show in
        // the trend, same asymmetry fix as buildAttendanceEmployeeRows() above.
        if ($status === 'absent' && $row['is_holiday']) {
            continue;
        }
        $d = $row['attendance_date'];
        $sparse[$d] = ($sparse[$d] ?? 0) + 1;
    }
    return attendanceDenseDailyMap($sparse, $start, $end);
}

/**
 * Per-day count of employees on approved leave, filtered/scoped the same
 * way as bucketAttendanceStatusRowsByDay() so it can sit in the same stacked
 * chart. Sourced from employee_leave_records (the real, authoritative leave
 * system), never attendance_records.status = 'on_leave' or
 * employee_schedules.status -- same reasoning as buildAttendanceEmployeeRows()'s
 * own leave-days figure, which this mirrors exactly except rolled up by day
 * instead of by employee: a leave record can span beyond this range (only
 * the overlap counts) and a rest day within that overlap is excluded, since
 * it was never a countable day for Present/Late/Absent here either.
 *
 * Present/Late/Absent/Leave are mutually exclusive outcomes for a given
 * employee-day, so stacking all four is safe and the stack height is a real
 * "how many people were accounted for" total. Undertime is deliberately NOT
 * a series here -- it's a sub-condition of an already-counted Present/Late
 * day (an employee is either Present or Undertime-Present, never both as
 * separate days), so adding it would double-count against the same day.
 */
function getLeaveDailyCounts(PDO $db, DateTime $start, DateTime $end, ?int $positionId, ?string $search): array
{
    $where = ["e.is_active = 1", "e.employment_status = 'active'"];
    $params = [];
    if ($positionId !== null) {
        $where[] = "e.position_id = ?";
        $params[] = $positionId;
    }
    if ($search !== null && $search !== '') {
        $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $employeeStmt = $db->prepare("SELECT e.employee_id FROM employees e WHERE " . implode(' AND ', $where));
    $employeeStmt->execute($params);
    $employeeIds = array_column($employeeStmt->fetchAll(PDO::FETCH_ASSOC), 'employee_id');

    if (empty($employeeIds)) {
        return attendanceDenseDailyMap([], $start, $end);
    }

    $inPlaceholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $rangeParams = [$start->format('Y-m-d'), $end->format('Y-m-d')];

    $restDayStmt = $db->prepare(
        "SELECT employee_id, schedule_date FROM employee_schedules
         WHERE employee_id IN ($inPlaceholders) AND schedule_date BETWEEN ? AND ? AND is_rest_day = 1"
    );
    $restDayStmt->execute(array_merge($employeeIds, $rangeParams));
    $restDayDatesByEmployee = [];
    foreach ($restDayStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $restDayDatesByEmployee[(int)$row['employee_id']][$row['schedule_date']] = true;
    }

    $leaveStmt = $db->prepare(
        "SELECT employee_id, start_date, end_date FROM employee_leave_records
         WHERE employee_id IN ($inPlaceholders) AND status = 'approved'
           AND start_date <= ? AND end_date >= ?"
    );
    $leaveStmt->execute(array_merge($employeeIds, [$end->format('Y-m-d'), $start->format('Y-m-d')]));

    $sparse = [];
    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
        $empId = (int)$leave['employee_id'];
        $overlapStart = max($leave['start_date'], $start->format('Y-m-d'));
        $overlapEnd = min($leave['end_date'], $end->format('Y-m-d'));
        if ($overlapEnd < $overlapStart) {
            continue;
        }
        $cursor = new DateTime($overlapStart);
        $leaveEnd = new DateTime($overlapEnd);
        while ($cursor <= $leaveEnd) {
            $dateKey = $cursor->format('Y-m-d');
            if (!isset($restDayDatesByEmployee[$empId][$dateKey])) {
                $sparse[$dateKey] = ($sparse[$dateKey] ?? 0) + 1;
            }
            $cursor->modify('+1 day');
        }
    }
    return attendanceDenseDailyMap($sparse, $start, $end);
}

function buildAttendanceWeeklyTrend(PDO $db, DateTime $start, DateTime $end, ?int $positionId, ?string $search): array
{
    // The exact same raw rows buildAttendanceEmployeeRows() (the table) also
    // fetches and aggregates -- one shared query underneath both views.
    $statusRows = fetchAttendanceStatusRows($db, $start, $end, $positionId, $search);

    $series = [
        'present' => attendanceRollUpWeekly(bucketAttendanceStatusRowsByDay($statusRows, 'present', $start, $end)),
        'late'    => attendanceRollUpWeekly(bucketAttendanceStatusRowsByDay($statusRows, 'late', $start, $end)),
        'absent'  => attendanceRollUpWeekly(bucketAttendanceStatusRowsByDay($statusRows, 'absent', $start, $end)),
        'leave'   => attendanceRollUpWeekly(getLeaveDailyCounts($db, $start, $end, $positionId, $search)),
    ];
    return attendanceMergeSeriesIntoGrid($series);
}

// ---------------------------------------------------------------------
// Per-employee daily drill-down
// ---------------------------------------------------------------------

/**
 * One row per calendar day in range for a single employee, merging
 * employee_schedules and attendance_records by date (a LEFT JOIN alone
 * would miss an attendance_records row recorded on a day with no matching
 * schedule -- schedule_id is nullable -- so both tables are queried
 * separately and merged here instead).
 */
function buildEmployeeDailyAttendanceDetail(PDO $db, int $employeeId, DateTime $start, DateTime $end): array
{
    $rangeParams = [$employeeId, $start->format('Y-m-d'), $end->format('Y-m-d')];

    $scheduleStmt = $db->prepare(
        "SELECT schedule_date, scheduled_time_in, scheduled_time_out, is_rest_day, status AS schedule_status
         FROM employee_schedules WHERE employee_id = ? AND schedule_date BETWEEN ? AND ?"
    );
    $scheduleStmt->execute($rangeParams);
    $byDate = [];
    foreach ($scheduleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDate[$row['schedule_date']] = [
            'date'                => $row['schedule_date'],
            'scheduled_time_in'   => $row['scheduled_time_in'],
            'scheduled_time_out'  => $row['scheduled_time_out'],
            'is_rest_day'         => (bool)$row['is_rest_day'],
            'schedule_status'     => $row['schedule_status'],
            'time_in'             => null,
            'time_out'            => null,
            'total_hours_worked'  => null,
            'late_minutes'        => null,
            'undertime_minutes'   => null,
            'overtime_minutes'    => null,
            'night_differential_hours' => null,
            'attendance_status'   => null,
            'source'              => null,
        ];
    }

    $attendanceStmt = $db->prepare(
        "SELECT attendance_date, time_in, time_out, total_hours_worked, late_minutes, undertime_minutes,
                overtime_minutes, night_differential_hours, status AS attendance_status, source
         FROM attendance_records WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
    );
    $attendanceStmt->execute($rangeParams);
    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = $row['attendance_date'];
        if (!isset($byDate[$d])) {
            $byDate[$d] = [
                'date' => $d, 'scheduled_time_in' => null, 'scheduled_time_out' => null,
                'is_rest_day' => false, 'schedule_status' => null,
            ];
        }
        $byDate[$d]['time_in']            = $row['time_in'];
        $byDate[$d]['time_out']           = $row['time_out'];
        $byDate[$d]['total_hours_worked'] = $row['total_hours_worked'];
        $byDate[$d]['late_minutes']       = $row['late_minutes'];
        $byDate[$d]['undertime_minutes']  = $row['undertime_minutes'];
        $byDate[$d]['overtime_minutes']   = $row['overtime_minutes'];
        $byDate[$d]['night_differential_hours'] = $row['night_differential_hours'];
        $byDate[$d]['attendance_status']  = $row['attendance_status'];
        $byDate[$d]['source']             = $row['source'];
    }

    // Public holidays in the range, so a day with no attendance row can say WHY.
    //
    // sweepAutoDetectAbsences() deliberately skips holidays -- not working on a
    // holiday is not absence -- so those days end up with no attendance_records
    // row at all. The DTR then fell through to the schedule status and printed
    // "Scheduled", which looks identical to a day nobody has processed yet. On a
    // document someone signs, that is the difference between "this was a public
    // holiday" and "we have not checked this day".
    //
    // Display only. Payroll derives unworked-holiday pay from `holidays`, the
    // schedule and the PRECEDING day (employeeQualifiesForUnworkedHolidayPay),
    // never from an attendance row on the holiday itself -- so nothing here
    // changes what anyone is paid.
    $holidayStmt = $db->prepare(
        "SELECT holiday_date, holiday_name, holiday_type
           FROM holidays
          WHERE is_active = 1 AND holiday_date BETWEEN ? AND ?"
    );
    $holidayStmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    foreach ($holidayStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $d = $h['holiday_date'];
        if (!isset($byDate[$d])) {
            // A holiday with neither a schedule nor an attendance row still
            // belongs on the record -- otherwise the date silently vanishes.
            $byDate[$d] = [
                'date' => $d, 'scheduled_time_in' => null, 'scheduled_time_out' => null,
                'is_rest_day' => false, 'schedule_status' => null,
                'time_in' => null, 'time_out' => null, 'total_hours_worked' => null,
                'late_minutes' => null, 'undertime_minutes' => null,
                'overtime_minutes' => null, 'night_differential_hours' => null,
                'attendance_status' => null, 'source' => null,
            ];
        }
        $byDate[$d]['holiday_name'] = $h['holiday_name'];
        $byDate[$d]['holiday_type'] = $h['holiday_type'];
    }

    ksort($byDate);
    return array_values($byDate);
}

/**
 * The Daily Time Record as one paper-styled card -- reuses the exact same
 * .payslip/.ps-* classes (payslipDocumentStyles() in payroll_functions.php)
 * as the payslip, so a DTR and a payslip read as two documents from the
 * same office, not two different UI styles. Shared between the drill-down
 * modal fragment (performance_monitoring_detail_fragment.php) and the
 * standalone print document (dtr_print.php), same convention as
 * renderPayslipCardHtml().
 */
function renderDtrDocumentHtml(array $employee, array $days, DateTime $rangeStart, DateTime $rangeEnd, string $restaurantName, string $restaurantAddress, string $logoSrc = '../assets/images/logo.jpg'): string
{
    $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);

    // Totals footed at the bottom of the record, the way a DTR is signed off.
    $totalHours = 0.0;
    $totalLate = 0;
    $totalUndertime = 0;
    $totalOt = 0;
    $totalNightDiff = 0.0;
    $daysPresent = 0;
    foreach ($days as $d) {
        $totalHours += (float)($d['total_hours_worked'] ?? 0);
        $totalLate  += (int)($d['late_minutes'] ?? 0);
        $totalUndertime += (int)($d['undertime_minutes'] ?? 0);
        $totalOt    += (int)($d['overtime_minutes'] ?? 0);
        $totalNightDiff += (float)($d['night_differential_hours'] ?? 0);
        if (in_array($d['attendance_status'] ?? '', ['present', 'late'], true)) {
            $daysPresent++;
        }
    }

    ob_start();
    ?>
<div class="payslip">
    <div class="ps-header">
        <div class="ps-header-brand">
            <img src="<?= htmlspecialchars($logoSrc) ?>" alt="" class="ps-logo">
            <div>
                <h1><?= htmlspecialchars($restaurantName) ?></h1>
                <?php if ($restaurantAddress): ?><div class="ps-addr"><?= htmlspecialchars($restaurantAddress) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="ps-title">
            <h2>Daily Time Record</h2>
            <div><?= htmlspecialchars($rangeStart->format('M j')) ?> &ndash; <?= htmlspecialchars($rangeEnd->format('M j, Y')) ?></div>
        </div>
    </div>

    <div class="ps-meta">
        <div class="ps-meta-block">
            <h3>Employee</h3>
            <div class="ps-meta-row"><span>Name</span><span><?= htmlspecialchars($fullName) ?></span></div>
            <?php if (!empty($employee['employee_number'])): ?>
                <div class="ps-meta-row"><span>Employee #</span><span><?= htmlspecialchars($employee['employee_number']) ?></span></div>
            <?php endif; ?>
            <div class="ps-meta-row"><span>Position</span><span><?= htmlspecialchars($employee['position_title'] ?: '—') ?></span></div>
            <div class="ps-meta-row"><span>Department</span><span><?= htmlspecialchars($employee['department_name'] ?: '—') ?></span></div>
        </div>
        <div class="ps-meta-block">
            <h3>Record</h3>
            <div class="ps-meta-row"><span>Period covered</span><span><?= htmlspecialchars($rangeStart->format('M j, Y')) ?> &ndash; <?= htmlspecialchars($rangeEnd->format('M j, Y')) ?></span></div>
            <div class="ps-meta-row"><span>Days present</span><span><?= (int)$daysPresent ?></span></div>
            <div class="ps-meta-row"><span>Total hours</span><span><?= htmlspecialchars(formatHoursMinutes($totalHours)) ?></span></div>
        </div>
    </div>

    <?php if (empty($days)): ?>
        <div class="ps-section">
            <p style="color:var(--ps-ink-soft);font-style:italic;">No scheduled or recorded attendance for this employee in this period.</p>
        </div>
    <?php else: ?>
    <div class="ps-section">
        <h3>Attendance</h3>
        <table class="ps-grid">
            <thead>
                <tr>
                    <th>Date</th><th>Day</th><th>Time in</th><th>Time out</th>
                    <th class="num">Hours</th><th class="num">Late</th><th class="num">Undertime</th><th class="num">OT</th><th class="num">Night diff</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($days as $d): $ts = strtotime($d['date']); ?>
                <tr>
                    <td><?= htmlspecialchars(date('M j, Y', $ts)) ?></td>
                    <td><?= htmlspecialchars(date('D', $ts)) ?></td>
                    <td><?= !empty($d['time_in']) ? htmlspecialchars(formatTimeOfDay($d['time_in'])) : '&mdash;' ?></td>
                    <td><?= !empty($d['time_out']) ? htmlspecialchars(formatTimeOfDay($d['time_out'])) : '&mdash;' ?></td>
                    <td class="num"><?= $d['total_hours_worked'] !== null ? htmlspecialchars(formatHoursMinutes((float)$d['total_hours_worked'])) : '&mdash;' ?></td>
                    <td class="num"><?= $d['attendance_status'] !== null ? htmlspecialchars(formatHoursMinutes((float)$d['late_minutes'] / 60)) : '&mdash;' ?></td>
                    <td class="num"><?= $d['attendance_status'] !== null ? htmlspecialchars(formatHoursMinutes((float)($d['undertime_minutes'] ?? 0) / 60)) : '&mdash;' ?></td>
                    <td class="num"><?= $d['attendance_status'] !== null ? htmlspecialchars(formatHoursMinutes((float)$d['overtime_minutes'] / 60)) : '&mdash;' ?></td>
                    <td class="num"><?= $d['attendance_status'] !== null ? htmlspecialchars(formatHoursMinutes((float)($d['night_differential_hours'] ?? 0))) : '&mdash;' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Total</td>
                    <td class="num"><?= htmlspecialchars(formatHoursMinutes($totalHours)) ?></td>
                    <td class="num"><?= htmlspecialchars(formatHoursMinutes($totalLate / 60)) ?></td>
                    <td class="num"><?= htmlspecialchars(formatHoursMinutes($totalUndertime / 60)) ?></td>
                    <td class="num"><?= htmlspecialchars(formatHoursMinutes($totalOt / 60)) ?></td>
                    <td class="num"><?= htmlspecialchars(formatHoursMinutes($totalNightDiff)) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <div class="ps-footer">Generated <?= date('M j, Y g:i A') ?> &middot; System-generated Daily Time Record.</div>
</div>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------------------
// UI helpers
// ---------------------------------------------------------------------

function attendanceKpiCard(string $label, string $value, string $meta = '', string $icon = 'ph-chart-bar'): string
{
    // .pm-kpi (owner-panel.css) pins the icon to the top and gives every card
    // the same height. Without it the row read as broken: .owner-summary-card
    // centres its children, so the one card whose meta wrapped to two lines sat
    // at a different height and floated its icon halfway down.
    return '<div class="owner-summary-card pm-kpi"><div class="pm-kpi-text">'
        . '<div class="owner-summary-card-label">' . htmlspecialchars($label) . '</div>'
        . '<div class="owner-summary-card-value">' . $value . '</div>'
        . ($meta !== '' ? '<div class="df-kpi-meta">' . $meta . '</div>' : '')
        . '</div><i class="ph ' . htmlspecialchars($icon) . ' pm-kpi-icon" aria-hidden="true"></i></div>';
}

/** "+2.3 pts vs previous period" / "-1.1 pts vs previous period", or a neutral note when the previous period has no data to compare against. */
function attendanceDeltaMeta(?float $current, ?float $previous): string
{
    if ($current === null || $previous === null) {
        return 'No prior period to compare';
    }
    $delta = $current - $previous;
    $tone  = $delta >= 0 ? 'var(--op-success)' : 'var(--op-danger)';
    $icon  = $delta >= 0 ? 'ph-trend-up' : 'ph-trend-down';
    return '<span style="color:' . $tone . ';"><i class="ph ' . $icon . '" aria-hidden="true"></i> '
        . ($delta >= 0 ? '+' : '') . number_format($delta, 1) . ' pts</span> vs previous period';
}

function attendanceEmptyState(string $icon, string $title, string $message): string
{
    return '<div class="owner-table-empty">'
        . '<i class="ph ' . htmlspecialchars($icon) . '" style="font-size:2rem;color:var(--op-ink-faint);display:block;margin-bottom:10px;" aria-hidden="true"></i>'
        . '<strong style="display:block;margin-bottom:4px;">' . htmlspecialchars($title) . '</strong>'
        . '<span style="color:var(--op-ink-faint);font-size:0.85rem;">' . htmlspecialchars($message) . '</span>'
        . '</div>';
}

// ---------------------------------------------------------------------
// Export logging -- first real writer of data_export_logs (confirmed
// unused scaffolding elsewhere in this app), alongside a direct
// activity_logs insert (a one-off export action, not a dedup'd recurring
// alert, so this bypasses logActivityOnce()'s dedup semantics on purpose).
// ---------------------------------------------------------------------

function logAttendanceExport(PDO $db, int $userId, array $filters, int $rowCount): void
{
    try {
        $db->prepare(
            "INSERT INTO data_export_logs (module, export_format, filters_applied, row_count, exported_by, status)
             VALUES ('Performance Monitoring', 'csv', ?, ?, ?, 'completed')"
        )->execute([json_encode($filters), $rowCount, $userId]);
    } catch (Throwable $e) {
        error_log('logAttendanceExport (data_export_logs) failed: ' . $e->getMessage());
    }

    try {
        $db->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Performance Monitoring', 'Export attendance trends CSV', ?, ?)"
        )->execute([
            $userId,
            // describeExportFilters() lives in owner/includes/analytics_functions.php,
            // which this page does not load -- so the same formatting is applied
            // inline rather than pulling in that whole file for one helper.
            "Exported {$rowCount} employee row(s)" . (function (array $f): string {
                $labels = ['period' => 'period', 'date_from' => 'from', 'date_to' => 'to',
                           'position_id' => 'position', 'search' => 'search'];
                $parts = [];
                foreach ($f as $k => $v) {
                    if ($v === null || $v === '' || $v === []) continue;
                    $parts[] = ($labels[$k] ?? $k) . ' ' . (is_array($v) ? implode(', ', $v) : $v);
                }
                return $parts ? ' (' . implode(', ', $parts) . ')' : '';
            })($filters),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('logAttendanceExport (activity_logs) failed: ' . $e->getMessage());
    }
}
