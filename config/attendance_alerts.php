<?php
/**
 * config/attendance_alerts.php
 *
 * Two kinds of sweep live here, both manager-only (this app's Performance
 * Monitoring, attendance import, and settings pages all gate on
 * hasRole(['manager']), not owner):
 *   - sweepAttendanceThresholdAlerts() -- opportunistic, "no cron, check on
 *     a real page load" (manager/includes/header.php), same pattern as
 *     config/inventory_alerts.php / config/payroll_alerts.php.
 *   - sweepAutoDetectAbsences() / notifyPendingAttendanceImports() --
 *     registered in cron/run_sweeps.php instead, since their correctness
 *     (auto-marking a real no-show, and reminding before that happens)
 *     can't depend on a manager happening to open a page that day.
 *   - reconcileAbsencesAfterImport() -- called directly from
 *     employee_management/attendance_biometric_import_process.php right after a batch
 *     commits, not cron or opportunistic: a completed import IS the
 *     "finalized" signal for the dates it covers, so anyone still missing
 *     gets marked absent immediately instead of waiting out the cutoff.
 *
 * Requires config/database.php's Database class and a live PDO connection
 * to already be available (every caller already does); requires
 * config/notifications.php (notifyUsersByRole()) and employee_management/includes/
 * performance_monitoring_functions.php (which itself requires payroll_functions.php)
 * -- confirmed collision-free against every other module's own
 * *_functions.php via this session's established "require everything
 * together" smoke test.
 */

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../employee_management/includes/performance_monitoring_functions.php';

/**
 * Notifies every manager once per employee who crosses the Critical
 * attendance-rate threshold over the trailing 30 days, gated by a minimum
 * sample size (ATTENDANCE_MIN_SAMPLE_SCHEDULED_DAYS) so a brand-new hire
 * isn't flagged off one bad day. Re-alerts once/week while still
 * unresolved (same recurring-condition convention as the inventory stock
 * alerts) rather than dedup'ing forever, since an ongoing attendance
 * problem is exactly the kind of thing that should keep surfacing until
 * it's actually addressed.
 */
function sweepAttendanceThresholdAlerts(PDO $db): void
{
    [$start, $end] = resolveAttendanceReportRange('last30', null, null);
    $rows = buildAttendanceEmployeeRows($db, $start, $end, null, null, null);

    foreach ($rows as $row) {
        if ($row['status_flag'] !== 'critical' || $row['scheduled_days'] < ATTENDANCE_MIN_SAMPLE_SCHEDULED_DAYS) {
            continue;
        }

        notifyUsersByRole(
            $db,
            ['manager'],
            'attendance',
            'Attendance critical: ' . $row['full_name'],
            sprintf(
                "%s's attendance rate over the last 30 days is %s%% (%d of %d scheduled days present or late), below the %s%% critical threshold.",
                $row['full_name'],
                number_format($row['attendance_rate'], 1),
                $row['present_or_late_days'],
                $row['scheduled_days'],
                number_format(ATTENDANCE_WATCH_THRESHOLD_PCT, 0)
            ),
            'attendance_critical_threshold',
            $row['employee_id'],
            '7 DAY'
        );
    }
}

/**
 * Shared candidate set for sweepAutoDetectAbsences(),
 * notifyPendingAttendanceImports(), and reconcileAbsencesAfterImport()
 * below: every scheduled (non-rest-day) workday in [$dateFrom, $dateTo]
 * that still has no attendance_records row and isn't covered by an
 * approved leave. status NOT IN ('cancelled', 'on_leave') mirrors
 * buildAttendanceEmployeeRows()'s own scheduled_days denominator
 * (performance_monitoring_functions.php) -- a schedule a manager
 * explicitly cancelled, or already flagged on_leave at the schedule
 * level, was never really "expected to show up" either. Active holiday
 * dates are excluded the same way -- a holiday, worked or not, was never
 * a "nobody showed up so mark it absent" gap, same reasoning as
 * performance_monitoring_functions.php's own scheduled_days denominator.
 *
 * scheduled_time_in/out come straight off employee_schedules (NOT NULL
 * columns, always populated for a real workday) rather than joining
 * shift_templates -- self-contained, and shift_id can be NULL on a
 * manually-edited schedule row even when is_rest_day=0.
 */
function fetchPendingAttendanceScheduleRows(PDO $db, string $dateFrom, string $dateTo): array
{
    $stmt = $db->prepare(
        "SELECT es.schedule_id, es.employee_id, es.schedule_date, es.scheduled_time_in, es.scheduled_time_out,
                e.first_name, e.last_name
         FROM employee_schedules es
         JOIN employees e ON e.employee_id = es.employee_id AND e.is_active = 1
         LEFT JOIN attendance_records ar ON ar.employee_id = es.employee_id AND ar.attendance_date = es.schedule_date
         LEFT JOIN employee_leave_records elr ON elr.employee_id = es.employee_id
             AND elr.status = 'approved'
             AND es.schedule_date BETWEEN elr.start_date AND elr.end_date
         WHERE es.is_rest_day = 0
           AND es.status NOT IN ('cancelled', 'on_leave')
           AND es.schedule_date BETWEEN ? AND ?
           AND ar.attendance_id IS NULL
           AND elr.leave_record_id IS NULL
           AND es.schedule_date NOT IN (SELECT holiday_date FROM holidays WHERE is_active = 1)"
    );
    $stmt->execute([$dateFrom, $dateTo]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Inserts the actual Absent row + notifies -- shared by the cron sweep and
 * the import-triggered reconciliation below, so both write the exact same
 * shape of row and only differ in *when* they decide to call this.
 * Race-safe: a duplicate-key hit means something else (a concurrent import,
 * a manual entry) already resolved this employee/date between the
 * candidate query and this insert, so it's silently skipped, not retried.
 */
function markScheduleRowAbsent(PDO $db, array $row, string $reasonForNotification): bool
{
    try {
        $db->prepare(
            "INSERT INTO attendance_records
                (employee_id, schedule_id, attendance_date, status, source, remarks,
                 total_hours_worked, late_minutes, overtime_minutes, night_differential_hours)
             VALUES (?, ?, ?, 'absent', 'auto_absence_sweep', 'Auto-detected: no attendance log within the import cutoff window', 0, 0, 0, 0)"
        )->execute([$row['employee_id'], $row['schedule_id'], $row['schedule_date']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }

    $fullName = trim($row['first_name'] . ' ' . $row['last_name']);
    notifyUsersByRole(
        $db,
        ['manager'],
        'attendance',
        'Marked absent: ' . $fullName,
        "{$fullName} had no attendance record for {$row['schedule_date']} ({$reasonForNotification}), so they were automatically marked Absent. Import the real log for that date to correct this if it's wrong.",
        'attendance_auto_absent',
        $row['schedule_id']
    );
    return true;
}

/**
 * A scheduled shift's real end datetime, handling the same midnight
 * rollover payroll_functions.php/biometric_functions.php already account
 * for elsewhere in this app -- if scheduled_time_out <= scheduled_time_in,
 * the shift crosses midnight, so the end datetime lands on the next
 * calendar day.
 */
function resolveScheduledShiftEnd(string $scheduleDate, string $timeIn, string $timeOut): DateTime
{
    $end = new DateTime("{$scheduleDate} {$timeOut}");
    if ($timeOut <= $timeIn) {
        $end->modify('+1 day');
    }
    return $end;
}

/**
 * Auto-marks Absent: has a schedule, no attendance log at all (source of
 * that gap doesn't matter -- biometric import never ran, or nobody
 * manually recorded it), and the configured import-cutoff window has fully
 * elapsed since the shift ended. Physicalizes the same missing-row rule
 * payroll_run_functions.php's runPayrollForEmployee() already computes
 * in-memory for pay purposes (excluding rest days + approved leave), so
 * Performance Monitoring's attendance_records-driven KPIs/charts finally
 * agree with what payroll already deducts, without a code change on
 * either side.
 *
 * Self-correcting, not a permanent guess: if a manager later imports the
 * real biometric log for this employee/date, attendance_biometric_
 * import_process.php's existing upsert (lines ~108-153) fully overwrites
 * this placeholder row -- status, source, times, everything -- since it
 * only ever skips a row that's already is_payroll_locked, which a
 * same-source fresh row never is.
 */
function sweepAutoDetectAbsences(PDO $db, float $importCutoffHours, int $daysBack = 45): void
{
    $cutoffMinutes = (int)round($importCutoffHours * 60);
    $now = new DateTime();
    $dateTo = $now->format('Y-m-d');
    $dateFrom = (clone $now)->modify("-{$daysBack} days")->format('Y-m-d');

    foreach (fetchPendingAttendanceScheduleRows($db, $dateFrom, $dateTo) as $row) {
        $shiftEnd = resolveScheduledShiftEnd($row['schedule_date'], $row['scheduled_time_in'], $row['scheduled_time_out']);
        $cutoffAt = (clone $shiftEnd)->modify("+{$cutoffMinutes} minutes");
        if ($now < $cutoffAt) {
            continue;
        }

        markScheduleRowAbsent($db, $row, 'the attendance import cutoff passed');
    }
}

/**
 * Import-triggered counterpart to the time-based cutoff above -- fires
 * immediately after a manager commits a biometric import batch (see
 * employee_management/attendance_biometric_import_process.php), scoped to exactly the
 * date range that batch covered. A completed import IS the "attendance
 * period finalized" signal for those dates -- no reason to make a manager
 * who just imported a full day's punches wait out the cutoff timer too;
 * anyone scheduled that day who still has no attendance row after the
 * import committed genuinely didn't show up in it. Same exclusions as
 * everywhere else (rest day, cancelled/on_leave, approved leave) via the
 * shared candidate query -- only the "when" differs from
 * sweepAutoDetectAbsences(), not the "who".
 *
 * Still requires the shift to have actually ENDED (just not the full
 * cutoff window past that) -- an import batch can legitimately cover a
 * date range that includes today, and today's shift may still be in
 * progress. Skipping this check would have marked someone Absent while
 * they were still clocked in for a night shift that started earlier today
 * and hasn't ended yet -- caught via live testing, not by inspection.
 */
function reconcileAbsencesAfterImport(PDO $db, string $dateFrom, string $dateTo): void
{
    $now = new DateTime();

    foreach (fetchPendingAttendanceScheduleRows($db, $dateFrom, $dateTo) as $row) {
        $shiftEnd = resolveScheduledShiftEnd($row['schedule_date'], $row['scheduled_time_in'], $row['scheduled_time_out']);
        if ($now < $shiftEnd) {
            continue;
        }

        markScheduleRowAbsent($db, $row, "not found in the attendance import you just ran");
    }
}

/**
 * Reminder, not a verdict -- fires once a scheduled day is past the
 * halfway point of the import-cutoff window but hasn't hit it yet, same
 * "warn before the deadline, then act at the deadline" shape as
 * customer/includes/reservation_functions.php's notifyUpcomingNoShow() +
 * sweepNoShowReservations() pair. Re-alerts every 4 hours while still
 * unresolved rather than once-ever, since a still-missing import is exactly
 * the kind of thing that should keep surfacing until someone actually
 * imports or records it.
 *
 * ONE notification per DATE, not per employee. This used to emit a separate
 * row per pending schedule, so a day where nobody's log had been imported
 * yet -- the normal case, since an import covers the whole shift at once --
 * filled the bell with one near-identical message per employee, each
 * repeating every 4 hours. The manager's actual next action is the same
 * single action for all of them (run the import for that date), so the
 * notification now names the date and the count and leaves the per-employee
 * breakdown to the page it links to.
 *
 * reference_id is the date as YYYYMMDD, the same int-encoded-date convention
 * payroll_cutoff_due_* already uses; managerNotifLink() decodes it back into
 * attendance.php's ?date= filter, whose "Missing attendance records" panel is
 * built from this very same fetchPendingAttendanceScheduleRows() candidate
 * set -- so the count in the message and the list on the page cannot drift.
 *
 * Employees on the same date can sit under different shifts and therefore
 * different cutoffs, so the deadline quoted is the EARLIEST among the
 * employees counted: it is the first moment any of them would be auto-marked
 * Absent, which is the deadline a manager actually has to beat.
 */
function notifyPendingAttendanceImports(PDO $db, float $importCutoffHours, float $warnWithinFraction = 0.5, int $daysBack = 45): void
{
    $cutoffMinutes = (int)round($importCutoffHours * 60);
    $warnMinutes = (int)round($cutoffMinutes * $warnWithinFraction);
    $now = new DateTime();
    $dateTo = $now->format('Y-m-d');
    $dateFrom = (clone $now)->modify("-{$daysBack} days")->format('Y-m-d');

    // Collect first, notify after: the count belongs in the message, so no
    // row can be sent until every row for that date has been examined.
    $pendingByDate = [];

    foreach (fetchPendingAttendanceScheduleRows($db, $dateFrom, $dateTo) as $row) {
        $shiftEnd = resolveScheduledShiftEnd($row['schedule_date'], $row['scheduled_time_in'], $row['scheduled_time_out']);
        $warnAt = (clone $shiftEnd)->modify("+{$warnMinutes} minutes");
        $cutoffAt = (clone $shiftEnd)->modify("+{$cutoffMinutes} minutes");
        if ($now < $warnAt || $now >= $cutoffAt) {
            continue;
        }

        $date = $row['schedule_date'];
        if (!isset($pendingByDate[$date])) {
            $pendingByDate[$date] = ['count' => 0, 'cutoff' => $cutoffAt];
        }
        $pendingByDate[$date]['count']++;
        if ($cutoffAt < $pendingByDate[$date]['cutoff']) {
            $pendingByDate[$date]['cutoff'] = $cutoffAt;
        }
    }

    foreach ($pendingByDate as $date => $info) {
        $count = $info['count'];
        notifyUsersByRole(
            $db,
            ['manager'],
            'attendance',
            'Attendance records missing',
            sprintf(
                '%d employee%s no attendance record for %s. Import biometric logs or record attendance manually before %s, or they will be automatically marked Absent.',
                $count,
                $count === 1 ? ' has' : 's have',
                date('F j', strtotime($date)),
                $info['cutoff']->format('F j, g:i A')
            ),
            'attendance_import_pending',
            (int)date('Ymd', strtotime($date)),
            '4 HOUR'
        );
    }
}
