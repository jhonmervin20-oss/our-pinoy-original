<?php
/**
 * employee_management/includes/payroll_functions.php
 *
 * Shared data-access and display helpers for the Payroll module's HR
 * foundation (employees, departments, positions). The Philippine payroll
 * computation engine (attendance aggregation, government contribution
 * lookups, gross/net pay) is a separate concern and lives in
 * payroll_engine.php once attendance/settings pages exist to feed it.
 */

/** All departments, optionally limited to active ones, alphabetical. */
function getDepartments(PDO $pdo, bool $activeOnly = false): array
{
    $sql = "SELECT department_id, department_name, is_active FROM departments";
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY department_name ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** All positions with their department name, optionally limited to active ones. */
function getPositions(PDO $pdo, bool $activeOnly = false): array
{
    $sql = "SELECT p.position_id, p.position_title, p.is_active,
                   p.department_id, d.department_name, p.salary_type, p.pay_frequency
            FROM positions p
            LEFT JOIN departments d ON d.department_id = p.department_id";
    if ($activeOnly) {
        $sql .= " WHERE p.is_active = 1";
    }
    $sql .= " ORDER BY p.position_title ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Next sequential employee_number, formatted EMP-0001. Reads the highest
 * existing numeric suffix rather than COUNT(*) so gaps left by no employee
 * ever having been deleted (soft-delete only, per this app's convention)
 * don't matter and the value stays correct even if seed data is imported
 * out of order.
 */
function generateEmployeeNumber(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT employee_number FROM employees ORDER BY employee_id DESC LIMIT 1");
    $last = $stmt->fetchColumn();

    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }

    return 'EMP-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function employmentStatusBadgeClass(string $status): string
{
    return match ($status) {
        'active'     => 'is-active',
        'suspended'  => 'is-danger',
        'resigned'   => 'is-neutral',
        'terminated' => 'is-critical',
        default      => 'is-inactive',
    };
}

function employmentStatusLabel(string $status): string
{
    return match ($status) {
        'active'     => 'Active',
        'suspended'  => 'Suspended',
        'resigned'   => 'Resigned',
        'terminated' => 'Terminated',
        default      => ucfirst($status),
    };
}

function employmentTypeLabel(string $type): string
{
    return match ($type) {
        'regular'       => 'Regular',
        'probationary'  => 'Probationary',
        default         => ucfirst($type),
    };
}

function salaryTypeLabel(string $type): string
{
    return $type === 'daily' ? 'Daily' : 'Monthly';
}

function payFrequencyLabel(string $freq): string
{
    return $freq === 'monthly' ? 'Monthly' : 'Semi-monthly';
}

/** Formats basic_rate with the unit that matches salary_type, e.g. "₱760.00 / day" vs "₱30,000.00 / mo". */
function formatBasicRate(float $rate, string $salaryType): string
{
    $formatted = '₱' . number_format($rate, 2);
    return $salaryType === 'daily' ? $formatted . ' / day' : $formatted . ' / mo';
}

function formatPeso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

/** "₱0.00 – ₱5,249.99" for a bounded bracket, "₱20,833.01 and up" when $to is null (the top/open-ended bracket). */
function formatMoneyRange(float $from, ?float $to): string
{
    return $to === null
        ? formatPeso($from) . ' and up'
        : formatPeso($from) . ' – ' . formatPeso($to);
}

/** Trims trailing zeros off a percent value, e.g. 5.000 -> "5%", 2.500 -> "2.5%". */
function formatPercent(float $value): string
{
    $trimmed = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    return ($trimmed === '' ? '0' : $trimmed) . '%';
}

function payFrequencyBadgeLabel(string $freq): string
{
    return match ($freq) {
        'daily'        => 'Daily',
        'weekly'       => 'Weekly',
        'semi_monthly' => 'Semi-monthly',
        'monthly'      => 'Monthly',
        default        => ucfirst($freq),
    };
}

/** All active employees, name + basic pay info, for schedule/attendance employee pickers. */
function getActiveEmployeesForPicker(PDO $pdo): array
{
    // pay_frequency is additive -- attendance.php and leave.php, the other two
    // callers, only read employee_id/employee_number/first_name/last_name, so
    // this extra key changes nothing for them. adjustments.php uses it to
    // drive the Add Adjustment modal's payroll-period picker (a monthly
    // employee never sees the semi-monthly half selector).
    return $pdo->query(
        "SELECT employee_id, employee_number, first_name, last_name, pay_frequency
         FROM employees
         WHERE is_active = 1
         ORDER BY first_name ASC, last_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** All shift templates. */
function getShiftTemplates(PDO $pdo): array
{
    $sql = "SELECT shift_id, start_time, end_time, is_night_shift FROM shift_templates ORDER BY start_time ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** "8:00 AM" from a "08:00:00" TIME column value. */
function formatTimeOfDay(?string $time): string
{
    if (!$time) {
        return '—';
    }
    return date('g:i A', strtotime($time));
}

/**
 * "7h 59m" from a decimal hours value like 7.98 -- for a single day's worked
 * hours or night differential, where a reader has to do the ×60 in their
 * head to know 7.98h means 59 minutes, not 98. Late/OT are already
 * stored and shown in plain minutes elsewhere on these same pages; this just
 * brings total_hours_worked and night_differential_hours in line with that.
 * Not used for period/week AGGREGATE totals (e.g. schedules.php's weekly
 * Total Hours, a payroll run's period hours) -- those are genuinely sums
 * across many days, where a decimal is the more normal way to read them.
 */
function formatHoursMinutes(float $hours): string
{
    $totalMinutes = (int)round($hours * 60);
    $h = intdiv($totalMinutes, 60);
    $m = $totalMinutes % 60;
    return "{$h}h {$m}m";
}

// ---------------------------------------------------------------------
// Schedule grid (employee_management/schedules.php) date math -- also used by
// the work-schedule generator below (scheduleAddDays(), scheduleStartOfWeek()).
// ---------------------------------------------------------------------

/** Monday on/before $ymd (ISO-8601 week numbering: 1=Mon..7=Sun). */
function scheduleStartOfWeek(string $ymd): string
{
    $dt = new DateTime($ymd);
    $dow = (int)$dt->format('N');
    $dt->modify('-' . ($dow - 1) . ' days');
    return $dt->format('Y-m-d');
}

/** $ymd shifted by $n days (negative rolls back). */
function scheduleAddDays(string $ymd, int $n): string
{
    return (new DateTime($ymd))->modify("{$n} days")->format('Y-m-d');
}

/** One Monday-Sunday block: its 7 dates plus a display label. */
/**
 * A week as one readable range.
 *
 *   same month   Sep 1–7, 2026
 *   same year    Aug 31 – Sep 6, 2026
 *   new year     Dec 29, 2025 – Jan 4, 2026
 */
function scheduleWeekLabel(string $start, string $end): string
{
    $a = strtotime($start);
    $b = strtotime($end);

    if (date('Y', $a) !== date('Y', $b)) {
        return date('M j, Y', $a) . ' – ' . date('M j, Y', $b);
    }
    if (date('n', $a) !== date('n', $b)) {
        return date('M j', $a) . ' – ' . date('M j, Y', $b);
    }
    // En dash with no spaces: it reads as a single range, not two dates.
    return date('M j', $a) . '–' . date('j, Y', $b);
}

function scheduleWeekBlock(string $start): array
{
    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $dates[] = scheduleAddDays($start, $i);
    }
    $end = end($dates);
    return [
        'start' => $start,
        'end'   => $end,
        'dates' => $dates,
        // "Sep 1–7, 2026" when the week sits inside one month, rather than
        // repeating a month name nobody needed twice. A week that straddles a
        // boundary keeps both parts -- "Aug 31 – Sep 6, 2026" -- and one that
        // straddles new year keeps both years too, because dropping either
        // would be genuinely ambiguous.
        'label' => scheduleWeekLabel($start, $end),
    ];
}

/**
 * Minutes between two "H:i:s" times minus a break, in hours. An end time
 * at/before the start time is treated as crossing into the next calendar
 * day -- same rule shift_template_save.php's is_night_shift derivation
 * already uses, so a scheduled night shift's hours come out positive.
 */
function scheduleShiftHours(string $timeIn, string $timeOut, int $breakMinutes): float
{
    $t1 = DateTime::createFromFormat('H:i:s', $timeIn);
    $t2 = DateTime::createFromFormat('H:i:s', $timeOut);
    if (!$t1 || !$t2) {
        return 0.0;
    }
    if ($t2 <= $t1) {
        $t2->modify('+1 day');
    }
    $minutes = ($t2->getTimestamp() - $t1->getTimestamp()) / 60 - $breakMinutes;
    return round(max(0.0, $minutes) / 60, 2);
}


function scheduleStatusBadgeClass(string $status): string
{
    return match ($status) {
        'scheduled' => 'is-info',
        'completed' => 'is-success',
        'cancelled' => 'is-neutral',
        'on_leave'  => 'is-warning',
        default     => 'is-inactive',
    };
}


// ---------------------------------------------------------------------
// Employee work schedules -- automatic employee_schedules generation.
//
// An employee's shift (employees.shift_id) and work days (employees.work_days,
// a SET of SCHEDULE_WEEKDAYS keys -- every other day is a rest day) are set on
// the Admin's Add/Edit employee form and are the ONLY thing that writes
// employee_schedules. schedules.php is a read-only view of the result; its old
// per-cell edit, Bulk Assign, Copy previous week and Weekly templates were
// removed on 2026-09-15.
//
// Rows are still materialised, one per employee per date, because payroll
// (rest-day and holiday premiums), the biometric import (late/OT against the
// scheduled times), the absence sweep and Performance Monitoring all read
// employee_schedules by date. Generating real rows keeps every one of them
// working unchanged.
// ---------------------------------------------------------------------

/* Normal hours in a working week: Labor Code Art. 83 sets 8 hours a day, so a
   six-day week is 48. A shift + work days pattern past this commits to
   overtime before anyone has clocked in, which is worth seeing on the roster.
   Advisory only -- schedules.php flags it, never blocks it. */
const SCHEDULE_WEEKLY_HOURS_LIMIT = 48;

const SCHEDULE_WEEKDAYS = [
    ['key' => 'monday',    'label' => 'Mon', 'full' => 'Monday'],
    ['key' => 'tuesday',   'label' => 'Tue', 'full' => 'Tuesday'],
    ['key' => 'wednesday', 'label' => 'Wed', 'full' => 'Wednesday'],
    ['key' => 'thursday',  'label' => 'Thu', 'full' => 'Thursday'],
    ['key' => 'friday',    'label' => 'Fri', 'full' => 'Friday'],
    ['key' => 'saturday',  'label' => 'Sat', 'full' => 'Saturday'],
    ['key' => 'sunday',    'label' => 'Sun', 'full' => 'Sunday'],
];

/* A schedule has no end date: an employee's shift + work days repeat every
   week until someone changes them. Real employee_schedules rows are stored
   through the Sunday that closes the 8th week after this one -- payroll, the
   biometric import and the absence sweep need actual rows, and only ever look
   at the near term. The window rolls forward on its own (cron/run_sweeps.php
   re-syncs every 5 minutes), and schedules.php fills in any week past it from
   the same rule (workScheduleRowFor()), so browsing ahead never hits an edge. */
const SCHEDULE_GENERATE_WEEKS_AHEAD = 8;

/** Last date employee_schedules rows are generated through. */
function scheduleGenerationHorizon(?string $today = null): string
{
    return scheduleAddDays(scheduleStartOfWeek($today ?? date('Y-m-d')), SCHEDULE_GENERATE_WEEKS_AHEAD * 7 + 6);
}

/** employees.work_days (a MySQL SET, comma-joined) as SCHEDULE_WEEKDAYS keys, Monday first. */
function parseEmployeeWorkDays(?string $raw): array
{
    $picked = ($raw === null || $raw === '') ? [] : explode(',', $raw);
    return array_values(array_intersect(array_column(SCHEDULE_WEEKDAYS, 'key'), $picked));
}

/** "Every day", a run like "Mon–Sat", or a list like "Mon, Wed, Fri" -- '' for none. */
function describeWeekdays(array $keys): string
{
    $allKeys = array_column(SCHEDULE_WEEKDAYS, 'key');
    $labels  = array_column(SCHEDULE_WEEKDAYS, 'label');
    $idx = array_keys(array_intersect($allKeys, $keys));   // 0 = Monday .. 6 = Sunday, ascending
    $n = count($idx);
    if ($n === 0) {
        return '';
    }
    if ($n === 7) {
        return 'Every day';
    }
    if ($n >= 3 && $idx[$n - 1] - $idx[0] === $n - 1) {
        return $labels[$idx[0]] . '–' . $labels[$idx[$n - 1]];
    }
    return implode(', ', array_map(fn($i) => $labels[$i], $idx));
}

/**
 * Whether an employee gets scheduled at all: active (is_active = 1 AND
 * employment_status = 'active') with a shift and at least one work day.
 * $emp needs is_active, employment_status and the shift's start_time.
 */
function employeeHasActiveSchedule(array $emp, array $workDays): bool
{
    return (int)$emp['is_active'] === 1
        && $emp['employment_status'] === 'active'
        && ($emp['start_time'] ?? null) !== null
        && $workDays !== [];
}

/**
 * One day of an employee's repeating week: their shift on a work day, a rest
 * day otherwise. The single rule behind both the rows syncEmployeeSchedule()
 * stores and the days schedules.php fills in past the stored window, so what
 * the grid shows for a far-off week is exactly what will be generated there.
 * $emp needs shift_id, start_time and end_time.
 *
 * @return array{shift_id:?int, in:string, out:string, rest:int}
 */
function workScheduleRowFor(array $emp, array $workDays, string $date): array
{
    $key = SCHEDULE_WEEKDAYS[(int)date('N', strtotime($date)) - 1]['key'];
    return in_array($key, $workDays, true)
        ? ['shift_id' => (int)$emp['shift_id'], 'in' => $emp['start_time'], 'out' => $emp['end_time'], 'rest' => 0]
        // The shape rest days have always been stored in: no shift, 00:00-00:00.
        : ['shift_id' => null, 'in' => '00:00:00', 'out' => '00:00:00', 'rest' => 1];
}

/**
 * Brings one employee's employee_schedules rows in line with their shift and
 * work days, from today onward. Idempotent -- nothing changed, nothing written
 * -- so every caller just calls it after anything that could matter: the
 * employee save/archive/restore, a shift's times changing, the Schedules page
 * load, and the 5-minute cron.
 *
 *   - Never touches a date before today, or any date that already has an
 *     attendance record. That day's late/OT/rest-day pay were computed against
 *     the schedule it had; changing it afterwards would rewrite history.
 *   - Rows start at max(today, date_hired), never earlier: the absence sweep
 *     looks back 45 days, so a generated past day with no punches would be
 *     marked Absent on its next run. They stop at date_separated.
 *   - Only an active (is_active = 1 AND employment_status = 'active') employee
 *     with a shift and at least one work day is scheduled. Anyone else has
 *     their upcoming unworked rows removed, so an archived, suspended or
 *     separated employee is never auto-marked absent for days they were never
 *     going to work.
 *   - Existing rows are UPDATED in place, never deleted and re-inserted:
 *     attendance_records.schedule_id points at them (ON DELETE SET NULL), and
 *     deletion only ever hits a row with no attendance, so nothing is unlinked.
 *
 * Opens its own transaction unless the caller already has one (employee_save.php
 * does, so the employee and their schedule commit or fail together).
 *
 * @return array{created:int, updated:int, removed:int}
 */
function syncEmployeeSchedule(PDO $db, int $employeeId, ?int $actingUserId = null, ?string $today = null): array
{
    $counts = ['created' => 0, 'updated' => 0, 'removed' => 0];
    $today  = $today ?? date('Y-m-d');

    $stmt = $db->prepare(
        "SELECT e.is_active, e.employment_status, e.date_hired, e.date_separated, e.work_days,
                e.shift_id, st.start_time, st.end_time
         FROM employees e
         LEFT JOIN shift_templates st ON st.shift_id = e.shift_id
         WHERE e.employee_id = ?"
    );
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        return $counts;
    }

    $workDays  = parseEmployeeWorkDays($emp['work_days']);
    $scheduled = employeeHasActiveSchedule($emp, $workDays);

    $from   = max($today, $emp['date_hired']);
    $keepTo = $emp['date_separated'] ?: '9999-12-31';               // existing rows past the horizon still follow the pattern
    $fillTo = min(scheduleGenerationHorizon($today), $keepTo);      // new rows only up to the horizon

    $want = fn(string $date): array => workScheduleRowFor($emp, $workDays, $date);

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        $existing = $db->prepare(
            "SELECT es.schedule_id, es.schedule_date, es.shift_id, es.scheduled_time_in, es.scheduled_time_out, es.is_rest_day,
                    EXISTS (SELECT 1 FROM attendance_records ar
                            WHERE ar.schedule_id = es.schedule_id
                               OR (ar.employee_id = es.employee_id AND ar.attendance_date = es.schedule_date)) AS has_attendance
             FROM employee_schedules es
             WHERE es.employee_id = ? AND es.schedule_date >= ?"
        );
        $existing->execute([$employeeId, $today]);
        $have = [];
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $have[$row['schedule_date']] = $row;
        }

        $upd = $db->prepare(
            "UPDATE employee_schedules SET shift_id = ?, scheduled_time_in = ?, scheduled_time_out = ?, is_rest_day = ?
             WHERE schedule_id = ?"
        );
        $del = $db->prepare("DELETE FROM employee_schedules WHERE schedule_id = ?");

        foreach ($have as $date => $row) {
            if ((int)$row['has_attendance'] === 1) {
                continue;
            }
            if (!$scheduled || $date < $from || $date > $keepTo) {
                $del->execute([$row['schedule_id']]);
                $counts['removed']++;
                continue;
            }
            $w = $want($date);
            if ((int)$row['shift_id'] !== (int)$w['shift_id']
                || $row['scheduled_time_in'] !== $w['in']
                || $row['scheduled_time_out'] !== $w['out']
                || (int)$row['is_rest_day'] !== $w['rest']) {
                $upd->execute([$w['shift_id'], $w['in'], $w['out'], $w['rest'], $row['schedule_id']]);
                $counts['updated']++;
            }
        }

        if ($scheduled) {
            // The cron and a save can race for the same (employee_id,
            // schedule_date). uq_employee_schedule_date turns the loser into a
            // no-op instead of an exception that would roll back the save.
            $ins = $db->prepare(
                "INSERT INTO employee_schedules
                    (employee_id, shift_id, schedule_date, scheduled_time_in, scheduled_time_out, is_rest_day, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?)
                 ON DUPLICATE KEY UPDATE schedule_id = schedule_id"
            );
            for ($d = $from; $d <= $fillTo; $d = scheduleAddDays($d, 1)) {
                if (isset($have[$d])) {
                    continue;
                }
                $w = $want($d);
                $ins->execute([$employeeId, $w['shift_id'], $d, $w['in'], $w['out'], $w['rest'], $actingUserId]);
                if ($ins->rowCount() === 1) {
                    $counts['created']++;
                }
            }
        }

        if ($ownTx) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $counts;
}

/**
 * syncEmployeeSchedule() for every employee -- archived ones included, since
 * that is what clears an archived employee's leftover upcoming rows. One
 * employee failing is logged and skipped rather than stopping the rest.
 *
 * @return array{created:int, updated:int, removed:int, failed:int}
 */
function syncAllEmployeeSchedules(PDO $db, ?string $today = null): array
{
    $totals = ['created' => 0, 'updated' => 0, 'removed' => 0, 'failed' => 0];
    foreach ($db->query("SELECT employee_id FROM employees")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            foreach (syncEmployeeSchedule($db, (int)$id, null, $today) as $k => $n) {
                $totals[$k] += $n;
            }
        } catch (Throwable $e) {
            $totals['failed']++;
            error_log("syncAllEmployeeSchedules: employee {$id} failed: " . $e->getMessage());
        }
    }
    return $totals;
}

function attendanceStatusBadgeClass(string $status): string
{
    return match ($status) {
        'present'   => 'is-active',
        'late'      => 'is-warning',
        'absent'    => 'is-danger',
        'on_leave'  => 'is-info',
        'holiday'   => 'is-success',
        'rest_day'  => 'is-neutral',
        default     => 'is-inactive',
    };
}

function attendanceStatusLabel(string $status): string
{
    return match ($status) {
        'present'   => 'Present',
        'late'      => 'Late',
        'absent'    => 'Absent',
        'on_leave'  => 'On leave',
        'holiday'   => 'Holiday',
        'rest_day'  => 'Rest day',
        default     => ucfirst($status),
    };
}

/**
 * Break display for one attendance row (attendance.php's Break column).
 *
 * The break is always a fixed company-wide duration (see
 * computeAttendanceMetrics()) with no real clock window attached to it, so
 * there's nothing to show but how much was deducted -- reconstructed
 * arithmetically from data we do store (the gap between the clocked span
 * and total_hours_worked IS the break that was applied) rather than just
 * assuming the current default, since a record's break could have been
 * computed under a since-changed setting.
 *
 * Returns ['label' => string, 'note' => string]; note is '' when there is
 * nothing worth qualifying.
 */
function attendanceBreakDisplay(array $r): array
{
    $timeIn  = $r['time_in'] ?? null;
    $timeOut = $r['time_out'] ?? null;
    if (!$timeIn || !$timeOut) {
        return ['label' => '—', 'note' => ''];
    }

    $grossMinutes    = (strtotime($timeOut) - strtotime($timeIn)) / 60;
    $workedMinutes   = (float)($r['total_hours_worked'] ?? 0) * 60;
    $deductedMinutes = max(0, (int)round($grossMinutes - $workedMinutes));

    return $deductedMinutes > 0
        ? ['label' => formatHoursMinutes($deductedMinutes / 60), 'note' => 'auto-deducted']
        : ['label' => 'None', 'note' => 'no break taken'];
}

function attendanceSourceLabel(string $source): string
{
    return match ($source) {
        'manual'              => 'Manual',
        'file_import'         => 'File import',
        'biometric_import'    => 'Biometric import',
        'auto_absence_sweep'  => 'Auto-detected',
        default               => ucfirst(str_replace('_', ' ', $source)),
    };
}

/** All leave types. */
function getLeaveTypes(PDO $pdo): array
{
    return $pdo->query(
        "SELECT leave_type_id, leave_name, is_paid, default_days_per_year FROM leave_types ORDER BY leave_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function leaveRecordStatusBadgeClass(string $status): string
{
    return $status === 'approved' ? 'is-active' : 'is-inactive';
}

/**
 * Recomputes employee_leave_balances.days_used for one employee/leave
 * type/year by summing that combination's approved employee_leave_records
 * (bucketed by start_date's calendar year — a leave spanning a year
 * boundary counts toward the year it started in, not split across both).
 * Upserts the balance row: creates one (days_entitled defaulted from
 * leave_types.default_days_per_year) if none exists yet, since a leave
 * record can be added before any balance row was manually set up.
 *
 * Call this after any insert/update/status-change to employee_leave_records
 * for the affected employee/leave_type/year combination(s) -- including
 * the *old* combination too when an edit changes employee/leave_type/date.
 */
function recalculateLeaveBalance(PDO $pdo, int $employeeId, int $leaveTypeId, int $year): void
{
    $sumStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_days), 0) FROM employee_leave_records
         WHERE employee_id = ? AND leave_type_id = ? AND YEAR(start_date) = ? AND status = 'approved'"
    );
    $sumStmt->execute([$employeeId, $leaveTypeId, $year]);
    $daysUsed = (float)$sumStmt->fetchColumn();

    $existing = $pdo->prepare(
        "SELECT leave_balance_id FROM employee_leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?"
    );
    $existing->execute([$employeeId, $leaveTypeId, $year]);
    $balanceId = $existing->fetchColumn();

    if ($balanceId) {
        $upd = $pdo->prepare("UPDATE employee_leave_balances SET days_used = ? WHERE leave_balance_id = ?");
        $upd->execute([$daysUsed, $balanceId]);
    } else {
        $defaultDays = $pdo->prepare("SELECT default_days_per_year FROM leave_types WHERE leave_type_id = ?");
        $defaultDays->execute([$leaveTypeId]);
        $entitled = (float)($defaultDays->fetchColumn() ?: 0);

        $ins = $pdo->prepare(
            "INSERT INTO employee_leave_balances (employee_id, leave_type_id, year, days_entitled, days_used) VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([$employeeId, $leaveTypeId, $year, $entitled, $daysUsed]);
    }
}

/** Combines a Y-m-d date with an H:i time into a DateTime, rolling to the next day if it falls at/before $notBefore (handles shifts that cross midnight). */
function combineDateTime(string $date, string $timeHHMM, ?DateTime $notBefore = null): ?DateTime
{
    if ($timeHHMM === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i', "$date $timeHHMM");
    if (!$dt) {
        return null;
    }
    if ($notBefore && $dt <= $notBefore) {
        $dt->modify('+1 day');
    }
    return $dt;
}

/**
 * Overlap (in hours) between the worked interval [timeIn, timeOut] and
 * payroll_settings' configured night-differential window -- purely
 * mechanical clock math, same category as late, not a judgment call like
 * overtime approval: Labor Code Art. 86 entitles an employee to the
 * night-shift premium for any hours actually worked within the configured
 * window, regardless of reason. $nightStartRaw/$nightEndRaw are
 * 'HH:MM[:SS]' TIME strings; the window may cross midnight (the seeded
 * default is 22:00-06:00), so every candidate window instance from the day
 * before timeIn through the day after is checked -- a shift starting right
 * before/after either boundary still gets the correct overlap.
 *
 * Does not exclude the break: the break is a fixed company-wide duration
 * with no real clock window attached to it (see computeAttendanceMetrics()),
 * so there is nothing to exclude by. In practice this restaurant's break
 * always falls well before the 22:00-06:00 window anyway.
 */
function computeNightDifferentialHours(?DateTime $timeIn, ?DateTime $timeOut, string $nightStartRaw, string $nightEndRaw): float
{
    if (!$timeIn || !$timeOut) {
        return 0.0;
    }

    $nightStartTime = substr($nightStartRaw, 0, 5);
    $nightEndTime = substr($nightEndRaw, 0, 5);

    $totalOverlapSeconds = 0;
    for ($dayOffset = -1; $dayOffset <= 1; $dayOffset++) {
        $anchorDate = (clone $timeIn)->modify("$dayOffset day")->format('Y-m-d');
        $windowStart = DateTime::createFromFormat('Y-m-d H:i', "$anchorDate $nightStartTime");
        $windowEnd = DateTime::createFromFormat('Y-m-d H:i', "$anchorDate $nightEndTime");
        if (!$windowStart || !$windowEnd) {
            continue;
        }
        if ($windowEnd <= $windowStart) {
            $windowEnd->modify('+1 day'); // the window itself crosses midnight (e.g. 22:00-06:00)
        }

        $overlapStart = max($timeIn, $windowStart);
        $overlapEnd = min($timeOut, $windowEnd);
        if ($overlapEnd <= $overlapStart) {
            continue;
        }

        $totalOverlapSeconds += $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
    }

    return round($totalOverlapSeconds / 3600, 2);
}

/**
 * Computes an attendance record's derived fields -- hours worked, late
 * minutes, night differential hours, and the schedule it was measured
 * against -- used by the biometric punch import
 * (attendance_biometric_import_process.php), the only path that writes
 * attendance_records rows now that manual entry was removed 2026-09-14.
 *
 * $timeInRaw/$timeOutRaw are H:i strings (or '' for none). The break is
 * always payroll_settings.default_break_minutes -- one company-wide policy,
 * not a per-employee, per-shift, or per-punch one (this restaurant's client
 * runs a fixed break; real break clock times were never tracked in
 * practice, and a stray biometric break punch once silently defeated the
 * auto-deduction for a day it existed) -- auto-deducted from hours worked
 * unless $noBreak is true, which means "no break happened," skipping the
 * deduction entirely.
 *
 * Returns DB-ready values: schedule_id (?int), time_in/time_out
 * ('Y-m-d H:i:s' or null), total_hours_worked (float), late_minutes (int),
 * undertime_minutes (int), night_differential_hours (float),
 * auto_break_minutes (int, 0 unless the auto-deduction actually applied --
 * for UI display only).
 */
function computeAttendanceMetrics(
    PDO $pdo,
    string $employeeId,
    string $attendanceDate,
    string $timeInRaw,
    string $timeOutRaw,
    bool $noBreak = false
): array {
    $timeIn   = combineDateTime($attendanceDate, $timeInRaw);
    $timeOut  = combineDateTime($attendanceDate, $timeOutRaw, $timeIn);

    $settings = $pdo->query(
        "SELECT late_grace_period_minutes, undertime_grace_period_minutes, night_differential_start, night_differential_end, default_break_minutes
         FROM payroll_settings LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $lateGrace = (int)($settings['late_grace_period_minutes'] ?? 0);
    $undertimeGrace = (int)($settings['undertime_grace_period_minutes'] ?? 0);

    $lateMinutes = 0;
    $undertimeMinutes = 0;

    $schedStmt = $pdo->prepare(
        "SELECT schedule_id, scheduled_time_in, scheduled_time_out, is_rest_day
         FROM employee_schedules
         WHERE employee_id = ? AND schedule_date = ?"
    );
    $schedStmt->execute([$employeeId, $attendanceDate]);
    $schedule = $schedStmt->fetch(PDO::FETCH_ASSOC);
    $scheduleId = $schedule['schedule_id'] ?? null;

    $autoBreakMinutes = 0;
    if (!$noBreak && $timeIn && $timeOut) {
        $autoBreakMinutes = (int)($settings['default_break_minutes'] ?? 60);
    }

    $totalHoursWorked = 0.0;
    if ($timeIn && $timeOut) {
        $workedSeconds = $timeOut->getTimestamp() - $timeIn->getTimestamp();
        $breakSeconds = $autoBreakMinutes * 60;
        $totalHoursWorked = round(max(0, $workedSeconds - $breakSeconds) / 3600, 2);
    }

    $nightDifferentialHours = computeNightDifferentialHours(
        $timeIn,
        $timeOut,
        $settings['night_differential_start'] ?? '22:00:00',
        $settings['night_differential_end'] ?? '06:00:00'
    );

    if ($schedule && (int)$schedule['is_rest_day'] === 0) {
        $scheduledIn = combineDateTime($attendanceDate, substr($schedule['scheduled_time_in'], 0, 5));
        if ($timeIn && $scheduledIn && $timeIn > $scheduledIn) {
            $diffMinutes = ($timeIn->getTimestamp() - $scheduledIn->getTimestamp()) / 60;
            $lateMinutes = max(0, (int)round($diffMinutes) - $lateGrace);
        }

        // Mirrors late, on the departure side: clocking out before the
        // scheduled time-out, past the grace period, is undertime -- this is
        // also what now covers a "half day" (leaving hours early shows up as
        // a large undertime figure) instead of a separate manual status.
        $scheduledOut = combineDateTime($attendanceDate, substr($schedule['scheduled_time_out'], 0, 5), $scheduledIn);
        if ($timeOut && $scheduledOut && $timeOut < $scheduledOut) {
            $diffMinutesOut = ($scheduledOut->getTimestamp() - $timeOut->getTimestamp()) / 60;
            $undertimeMinutes = max(0, (int)round($diffMinutesOut) - $undertimeGrace);
        }
    }

    return [
        'schedule_id' => $scheduleId,
        'time_in' => $timeIn ? $timeIn->format('Y-m-d H:i:s') : null,
        'time_out' => $timeOut ? $timeOut->format('Y-m-d H:i:s') : null,
        'total_hours_worked' => $totalHoursWorked,
        'late_minutes' => $lateMinutes,
        'undertime_minutes' => $undertimeMinutes,
        'night_differential_hours' => $nightDifferentialHours,
        'auto_break_minutes' => $autoBreakMinutes,
    ];
}

function holidayTypeBadgeClass(string $type): string
{
    return $type === 'regular' ? 'is-active' : 'is-info';
}

function holidayTypeLabel(string $type): string
{
    return $type === 'regular' ? 'Regular' : 'Special';
}

/** Deduction types a manager can manually apply as an adjustment — excludes government_mandatory ones, which the computation engine derives on its own from the government contribution tables, never entered by hand. */
function getManualDeductionTypes(PDO $pdo): array
{
    return $pdo->query(
        "SELECT deduction_type_id, code, name, category FROM payroll_deduction_types
         WHERE is_active = 1 AND category IN ('loan', 'other_deduction')
         ORDER BY display_order ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function adjustmentCategoryBadgeClass(string $category): string
{
    return $category === 'earning' ? 'is-success' : 'is-danger';
}

function adjustmentStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending'   => 'is-warning',
        'applied'   => 'is-active',
        'cancelled' => 'is-inactive',
        default     => 'is-neutral',
    };
}

/** The single payroll_settings row every payroll computation reads from. */
function getPayrollSettings(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM payroll_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
}

function payrollRunStatusBadgeClass(string $status): string
{
    return match ($status) {
        'draft'             => 'is-neutral',
        'processing'        => 'is-info',
        'pending_approval'  => 'is-warning',
        'approved'          => 'is-success',
        'released'          => 'is-active',
        'cancelled'         => 'is-critical',
        default             => 'is-inactive',
    };
}

function payrollRunStatusLabel(string $status): string
{
    return match ($status) {
        'draft'             => 'Draft',
        'processing'        => 'Processing',
        'pending_approval'  => 'Pending approval',
        'approved'          => 'Approved',
        'released'          => 'Released',
        'cancelled'         => 'Cancelled',
        default             => ucfirst($status),
    };
}

function payslipStatusBadgeClass(string $status): string
{
    return match ($status) {
        'draft'     => 'is-neutral',
        'finalized' => 'is-warning',
        'released'  => 'is-active',
        default     => 'is-inactive',
    };
}

function payslipStatusLabel(string $status): string
{
    return match ($status) {
        'draft'     => 'Draft',
        'finalized' => 'Finalized',
        'released'  => 'Released',
        default     => ucfirst($status),
    };
}

/**
 * Builds the filtered payroll_runs query behind runs.php's list + filter bar.
 * $filters: status, pay_frequency, date_from, date_to (against cutoff_period_start),
 * search (matched against run_number). Returns [sql, params].
 */
function buildPayrollRunsQuery(array $filters): array
{
    $sql = "SELECT r.*, u.first_name AS generated_first_name, u.last_name AS generated_last_name
            FROM payroll_runs r
            LEFT JOIN users u ON u.user_id = r.generated_by
            WHERE 1=1";
    $params = [];

    if (!empty($filters['status'])) {
        $sql .= " AND r.status = ?";
        $params[] = $filters['status'];
    }
    if (!empty($filters['pay_frequency'])) {
        $sql .= " AND r.pay_frequency = ?";
        $params[] = $filters['pay_frequency'];
    }
    if (!empty($filters['date_from'])) {
        $sql .= " AND r.cutoff_period_start >= ?";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $sql .= " AND r.cutoff_period_end <= ?";
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $sql .= " AND r.run_number LIKE ?";
        $params[] = '%' . $filters['search'] . '%';
    }

    // Sorted by when the run was actually CREATED, matching the "Created"
    // column shown in the table -- not by cutoff_period_start, which drifts
    // out of creation order the moment a run for an earlier pay period gets
    // generated after a later one already exists (e.g. a monthly run made
    // after its overlapping semi-monthly runs).
    $sql .= " ORDER BY r.created_at DESC, r.payroll_run_id DESC";

    return [$sql, $params];
}

/**
 * One payslip's full data -- the row itself (joined to employee/position/
 * department/run context) plus its earnings and deductions line items.
 * Shared by payslip.php, payslip_fragment.php, and run_payslips_print.php
 * so all three read exactly the same data the exact same way.
 */
function getPayslipWithDetails(PDO $pdo, int $payslipId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.*, e.employee_number, e.first_name, e.last_name, e.salary_type, e.pay_frequency, e.basic_rate,
                pos.position_title, d.department_name,
                r.run_number, r.cutoff_period_start, r.cutoff_period_end, r.payout_date
         FROM payslips p
         JOIN employees e ON e.employee_id = p.employee_id
         LEFT JOIN positions pos ON pos.position_id = e.position_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         JOIN payroll_runs r ON r.payroll_run_id = p.payroll_run_id
         WHERE p.payslip_id = ?"
    );
    $stmt->execute([$payslipId]);
    $payslip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payslip) {
        return null;
    }

    $earningsStmt = $pdo->prepare("SELECT earning_type, description, amount FROM payslip_earnings WHERE payslip_id = ? ORDER BY payslip_earning_id ASC");
    $earningsStmt->execute([$payslipId]);
    $extraEarnings = $earningsStmt->fetchAll(PDO::FETCH_ASSOC);

    // employee_amount > 0 filter: when the net-pay floor trims a contribution
    // to nothing (see runPayrollForEmployee()), the row is still WRITTEN so the
    // employer's own share stays on record as a remittance obligation -- but a
    // "-₱0.00" line on the employee's payslip reads as an error rather than as
    // information, so it is not printed. The stored employee amounts still sum
    // to payslips.total_deductions either way, since the trimmed ones are zero.
    $deductionsStmt = $pdo->prepare(
        "SELECT pd.description, pd.employee_amount, dt.name AS deduction_name, dt.code
         FROM payslip_deductions pd
         JOIN payroll_deduction_types dt ON dt.deduction_type_id = pd.deduction_type_id
         WHERE pd.payslip_id = ? AND pd.employee_amount > 0
         ORDER BY dt.display_order ASC"
    );
    $deductionsStmt->execute([$payslipId]);
    $deductions = $deductionsStmt->fetchAll(PDO::FETCH_ASSOC);

    return ['payslip' => $payslip, 'earnings' => $extraEarnings, 'deductions' => $deductions];
}

/**
 * Run-wide payroll summary: every figure is a straight SUM()/GROUP BY over
 * data runPayrollForEmployee() already computed and this run's payslips
 * already stored -- no new payroll math, just aggregation, so it can never
 * disagree with what each individual payslip says. Shared by run_detail.php
 * (in-page "Summary Report" tab) and run_summary_print.php (the standalone
 * print/PDF version) so the two can never drift apart from computing this
 * differently. This is the shape a PH employer actually needs to prepare
 * statutory remittances: SSS Contribution Collection List (R-3), PhilHealth
 * Remittance (RF-1), Pag-IBIG Monthly Remittance (M1-1), and BIR Form
 * 1601-C (withholding tax) all need the EMPLOYEE and EMPLOYER shares by
 * contribution type, summed across every employee on the run -- not just
 * each payslip's own lump total.
 */
function getPayrollRunSummary(PDO $pdo, int $runId): ?array
{
    $runStmt = $pdo->prepare("SELECT * FROM payroll_runs WHERE payroll_run_id = ?");
    $runStmt->execute([$runId]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        return null;
    }

    $earningsStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(p.basic_pay), 0) AS basic_pay,
            COALESCE(SUM(p.overtime_pay), 0) AS overtime_pay,
            COALESCE(SUM(p.holiday_pay), 0) AS holiday_pay,
            COALESCE(SUM(p.rest_day_pay), 0) AS rest_day_pay,
            COALESCE(SUM(p.night_differential_pay), 0) AS night_differential_pay,
            COALESCE(SUM(p.leave_pay), 0) AS leave_pay,
            COALESCE((SELECT SUM(pe.amount) FROM payslip_earnings pe JOIN payslips p2 ON p2.payslip_id = pe.payslip_id WHERE p2.payroll_run_id = p.payroll_run_id), 0) AS other_earnings,
            COALESCE(SUM(p.gross_pay), 0) AS gross_pay
         FROM payslips p WHERE p.payroll_run_id = ?"
    );
    $earningsStmt->execute([$runId]);
    $earnings = $earningsStmt->fetch(PDO::FETCH_ASSOC);

    $govStmt = $pdo->prepare(
        "SELECT pdt.code, pdt.name, pdt.category, pdt.has_employer_share,
                COALESCE(SUM(pd.employee_amount), 0) AS employee_total,
                COALESCE(SUM(pd.employer_amount), 0) AS employer_total
         FROM payslip_deductions pd
         JOIN payslips p ON p.payslip_id = pd.payslip_id
         JOIN payroll_deduction_types pdt ON pdt.deduction_type_id = pd.deduction_type_id
         WHERE p.payroll_run_id = ? AND pdt.category = 'government_mandatory'
         GROUP BY pdt.deduction_type_id
         ORDER BY pdt.display_order ASC"
    );
    $govStmt->execute([$runId]);
    $govDeductions = $govStmt->fetchAll(PDO::FETCH_ASSOC);

    $otherStmt = $pdo->prepare(
        "SELECT pdt.code, pdt.name, pdt.category,
                COALESCE(SUM(pd.employee_amount), 0) AS employee_total
         FROM payslip_deductions pd
         JOIN payslips p ON p.payslip_id = pd.payslip_id
         JOIN payroll_deduction_types pdt ON pdt.deduction_type_id = pd.deduction_type_id
         WHERE p.payroll_run_id = ? AND pdt.category != 'government_mandatory'
         GROUP BY pdt.deduction_type_id
         ORDER BY pdt.display_order ASC"
    );
    $otherStmt->execute([$runId]);
    $otherDeductions = $otherStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalsStmt = $pdo->prepare(
        "SELECT COUNT(*) AS employee_count,
                COALESCE(SUM(total_deductions), 0) AS total_deductions,
                COALESCE(SUM(net_pay), 0) AS total_net_pay,
                COALESCE(SUM(late_deduction), 0) AS late_deduction,
                COALESCE(SUM(undertime_deduction), 0) AS undertime_deduction,
                COALESCE(SUM(absence_deduction), 0) AS absence_deduction
         FROM payslips WHERE payroll_run_id = ?"
    );
    $totalsStmt->execute([$runId]);
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

    return [
        'run' => $run,
        'earnings' => $earnings,
        'gov_deductions' => $govDeductions,
        'other_deductions' => $otherDeductions,
        'totals' => $totals,
    ];
}

/** The <style> block every payslip card render shares -- included once per document, never per-card, so a bulk print of N payslips doesn't repeat it N times. */
/**
 * The same payslip design, rewritten in the subset of CSS Dompdf actually
 * implements. It exists because the PDF download did NOT look like the printed
 * page, for four separate reasons, all of them Dompdf limitations:
 *
 *   1. CSS custom properties. payslipDocumentStyles() defines its palette as
 *      :root{--ps-ink:...} and uses var() 24 times. Dompdf does not implement
 *      custom properties, so every one of those colours fell back to the
 *      initial value -- the document lost its entire palette.
 *
 *   2. The peso sign. Dompdf's default is the Adobe base-14 set (Helvetica,
 *      Times), which is Latin-1 and has no U+20B1, so every amount printed as
 *      "?30,000.00". DejaVu Sans ships with Dompdf and does contain it. This is
 *      the reason a payslip could not be handed to an employee as-is.
 *
 *   3. flexbox and gap. The label/value rows are flex with justify-content:
 *      space-between; Dompdf's flex support does not honour that, so labels and
 *      values collided -- "NameJhon Mervin Tablang". display:table with two
 *      cells produces the identical result in both engines.
 *
 *   4. display:grid, used for the two-column meta block, is not implemented at
 *      all and collapsed to a single stacked column.
 *
 * Kept as a separate stylesheet rather than merged into the screen one so the
 * on-screen and window.print() output -- which a real browser renders
 * correctly -- is not degraded to Dompdf's capabilities.
 */
function payslipPdfStyles(): string
{
    return <<<CSS
<style>
    @page { margin: 14mm 12mm; }
    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 0; background: #ffffff; color: #1f2430;
        font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.45;
    }
    .ps-toolbar { display: none; }

    .payslip { width: 100%; background: #ffffff; padding: 0; margin: 0 0 6px; }

    /* Header: brand on the left, document title on the right. */
    .ps-header { display: table; width: 100%; border-bottom: 2px solid #1f2430; padding-bottom: 10px; margin-bottom: 14px; }
    .ps-header-brand { display: table-cell; width: 62%; vertical-align: top; }
    .ps-header .ps-title { display: table-cell; width: 38%; vertical-align: top; text-align: right; }
    .ps-logo { height: 34px; width: auto; }
    .ps-header h1 { font-family: 'DejaVu Sans', sans-serif; font-size: 15px; font-weight: bold; margin: 4px 0 2px; color: #1f2430; }
    .ps-header .ps-addr { font-size: 9px; color: #6b7280; }
    .ps-header .ps-title h2 { font-family: 'DejaVu Sans', sans-serif; font-size: 13px; font-weight: bold; margin: 0 0 3px; }
    .ps-header .ps-title div { font-size: 9px; color: #6b7280; }
    .ps-status-pill {
        display: inline-block; padding: 2px 9px; font-size: 8px; font-weight: bold;
        text-transform: uppercase; background: #f4f5f7; color: #6b7280; border: 1px solid #dfe3ea;
    }
    .ps-status-pill.is-completed { background: #eafaf0; color: #1a7f4b; border-color: #bfe9d1; }
    .ps-status-pill.is-finalized { background: #fff6e5; color: #a5680a; border-color: #f3ddab; }

    /* Two meta columns -- display:grid is unsupported, a table is not. */
    .ps-meta { display: table; width: 100%; margin-bottom: 16px; }
    .ps-meta-block { display: table-cell; width: 50%; vertical-align: top; padding-right: 16px; }
    .ps-meta-block h3 { font-size: 8.5px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin: 0 0 5px; }

    /* Label left, value right -- the flex row that Dompdf could not space. */
    .ps-meta-row { display: table; width: 100%; font-size: 9.5px; margin-bottom: 2px; }
    .ps-meta-row span:first-child { display: table-cell; color: #6b7280; text-align: left; }
    .ps-meta-row span:last-child { display: table-cell; font-weight: bold; text-align: right; }

    .ps-section { margin-bottom: 12px; }
    .ps-section h3 { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin: 0 0 5px; border-bottom: 1px solid #dfe3ea; padding-bottom: 4px; }
    table.ps-table { width: 100%; border-collapse: collapse; font-size: 10px; }
    table.ps-table td { padding: 3.5px 0; vertical-align: top; }
    table.ps-table td:last-child { text-align: right; font-weight: bold; }
    table.ps-table td small { display: block; color: #6b7280; font-size: 8.5px; font-weight: normal; }
    .ps-subtotal td { border-top: 1px solid #dfe3ea; font-weight: bold; padding-top: 6px; }

    .ps-totals { margin-top: 14px; border-top: 2px solid #1f2430; padding-top: 10px; }
    .ps-totals-row { display: table; width: 100%; font-size: 10.5px; margin-bottom: 4px; }
    .ps-totals-row span:first-child { display: table-cell; text-align: left; }
    .ps-totals-row span:last-child { display: table-cell; text-align: right; font-weight: bold; }
    .ps-totals-row.ps-net { font-size: 14px; font-weight: bold; color: #1f2430; margin-top: 6px; }

    .ps-footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #dfe3ea; font-size: 8.5px; color: #6b7280; text-align: center; }

    /* Multi-column table (the statutory remittance breakdown). The
       single-column rule above right-aligns only the LAST cell, which would
       leave the middle money columns ragged, so .num carries it explicitly. */
    table.ps-table-3col th { font-size: 8.5px; text-transform: uppercase; color: #6b7280;
        text-align: left; padding: 0 4px 4px; border-bottom: 1px solid #dfe3ea; font-weight: bold; }
    table.ps-table-3col th.num, table.ps-table-3col td.num { text-align: right; }
    table.ps-table-3col td { padding: 3.5px 4px; font-weight: normal; }
    table.ps-table-3col td:last-child { font-weight: bold; }


    .ps-page-break { page-break-after: always; }
</style>
CSS;
}

function payslipDocumentStyles(bool $forFragment = false): string
{
    // $forFragment=true is payslip_fragment.php's case: this stylesheet gets
    // fetch()ed and innerHTML'd into run_detail.php's already-open payslip
    // modal rather than opened as its own <html> document. A bare `body{...}`
    // rule still targets the *real* <body> wherever the <style> tag ends up
    // in the DOM, so it was overriding owner-panel.css's own body rule
    // (equal specificity, later source order wins) and repainting the whole
    // run-detail page -- gray canvas, 24px inset, shrunk base font -- every
    // time a payslip was viewed. The page-chrome part of that rule only
    // makes sense for the standalone documents (payslip.php,
    // run_payslips_print.php, run_summary_print.php) that own their <body>;
    // the typography lives on .payslip instead so it works in both cases.
    $pageChrome = $forFragment ? '' : <<<CSS

    body{ margin:0; padding:24px; background:var(--ps-bg); }
CSS;

    return <<<CSS
<style>
    :root{
        --ps-ink:#1f2430; --ps-ink-soft:#6b7280; --ps-line:#dfe3ea; --ps-accent:#1f2430; --ps-bg:#f4f5f7;
    }
    *{ box-sizing:border-box; }
    {$pageChrome}
    .ps-toolbar{ max-width:800px; margin:0 auto 14px; display:flex; justify-content:flex-end; gap:8px; }
    .ps-btn{
        border:1px solid var(--ps-line); background:#fff; color:var(--ps-ink); border-radius:8px;
        padding:8px 16px; font-family:'Poppins', sans-serif; font-size:0.85rem; font-weight:500; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px;
    }
    .ps-btn-primary{ background:var(--ps-accent); border-color:var(--ps-accent); color:#fff; }

    .payslip{
        max-width:800px; margin:0 auto 24px; background:#fff; border:1px solid var(--ps-line); border-radius:12px;
        padding:36px 40px; box-shadow:0 1px 6px rgba(0,0,0,0.06);
        color:var(--ps-ink); font-family:'Poppins', sans-serif; font-size:13px; line-height:1.5;
    }
    .ps-header{ display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid var(--ps-accent); padding-bottom:16px; margin-bottom:20px; }
    .ps-header-brand{ display:flex; align-items:center; gap:12px; }
    /* Grayscale -- same reasoning as the cashier receipt's logo treatment
       (cashier/includes/receipt_body.php): a full-color logo photo looks
       out of place next to an otherwise plain black-and-white document. */
    .ps-logo{ height:46px; width:auto; object-fit:contain; filter:grayscale(100%); flex-shrink:0; }
    .ps-header h1{ font-family:'Lexend', sans-serif; font-size:1.3rem; margin:0 0 4px; color:var(--ps-accent); }
    .ps-header .ps-addr{ font-size:0.82rem; color:var(--ps-ink-soft); }
    .ps-header .ps-title{ text-align:right; }
    .ps-header .ps-title h2{ font-family:'Lexend', sans-serif; font-size:1.1rem; margin:0 0 4px; }
    .ps-header .ps-title div{ font-size:0.82rem; color:var(--ps-ink-soft); }
    .ps-status-pill{
        display:inline-block; padding:3px 12px; border-radius:999px; font-size:0.74rem; font-weight:600;
        text-transform:uppercase; letter-spacing:0.03em; background:var(--ps-bg); color:var(--ps-ink-soft); border:1px solid var(--ps-line);
    }
    .ps-status-pill.is-completed{ background:#eafaf0; color:#1a7f4b; border-color:#bfe9d1; }
    .ps-status-pill.is-finalized{ background:#fff6e5; color:#a5680a; border-color:#f3ddab; }

    .ps-meta{ display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:24px; }
    .ps-meta-block h3{ font-family:'Lexend', sans-serif; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--ps-ink-soft); margin:0 0 8px; }
    .ps-meta-row{ display:flex; justify-content:space-between; gap:8px; font-size:0.85rem; margin-bottom:4px; }
    .ps-meta-row span:first-child{ color:var(--ps-ink-soft); }
    .ps-meta-row span:last-child{ font-weight:500; text-align:right; }

    .ps-section{ margin-bottom:18px; }
    .ps-section h3{ font-family:'Lexend', sans-serif; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--ps-ink-soft); margin:0 0 8px; border-bottom:1px solid var(--ps-line); padding-bottom:6px; }
    table.ps-table{ width:100%; border-collapse:collapse; font-size:0.88rem; }
    table.ps-table td{ padding:6px 0; }
    table.ps-table td:last-child{ text-align:right; font-weight:500; }
    table.ps-table td small{ display:block; color:var(--ps-ink-soft); font-size:0.78rem; }
    .ps-subtotal td{ border-top:1px solid var(--ps-line); font-weight:700; padding-top:8px; }

    .ps-totals{ margin-top:20px; border-top:2px solid var(--ps-accent); padding-top:14px; }
    .ps-totals-row{ display:flex; justify-content:space-between; font-size:0.92rem; margin-bottom:6px; }
    .ps-totals-row.ps-net{ font-family:'Lexend', sans-serif; font-size:1.2rem; font-weight:700; color:var(--ps-accent); margin-top:8px; }

    .ps-footer{ margin-top:28px; padding-top:14px; border-top:1px dashed var(--ps-line); font-size:0.76rem; color:var(--ps-ink-soft); text-align:center; }

    /* Multi-column table (the statutory remittance breakdown). The
       single-column rule above right-aligns only the LAST cell, so the middle
       money columns need .num to line up on the decimal. */
    table.ps-table-3col th{ font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em;
        color:var(--ps-ink-soft); text-align:left; padding:0 8px 6px; border-bottom:1px solid var(--ps-line); }
    table.ps-table-3col th.num, table.ps-table-3col td.num{ text-align:right; }
    table.ps-table-3col td{ padding:6px 8px; font-weight:400; }
    table.ps-table-3col td:last-child{ font-weight:600; }
    table.ps-table-3col tbody tr + tr td{ border-top:1px solid var(--ps-line); }

    /* A wide bordered grid (the DTR's day-by-day rows) -- unlike ps-table's
       label/value pairs or ps-table-3col's fixed 3 columns, this one needs an
       arbitrary number of columns, so every cell carries its own border
       rather than relying on row dividers alone. */
    table.ps-grid{ width:100%; border-collapse:collapse; font-size:0.78rem; }
    table.ps-grid th, table.ps-grid td{ border:1px solid var(--ps-line); padding:6px 8px; text-align:left; white-space:nowrap; }
    table.ps-grid th{ font-size:0.68rem; text-transform:uppercase; letter-spacing:0.03em; color:var(--ps-ink-soft); background:var(--ps-bg); }
    table.ps-grid th.num, table.ps-grid td.num{ text-align:right; }
    table.ps-grid tfoot td{ font-weight:700; background:var(--ps-bg); }

    .ps-page-break{ page-break-after: always; }

    @media print{
        body{ background:#fff; padding:0; }
        .ps-toolbar{ display:none; }
        .payslip{ border:none; box-shadow:none; border-radius:0; max-width:none; padding:0; margin-bottom:0; }
        .ps-page-break{ page-break-after: always; }
        table.ps-grid{ page-break-inside:auto; }
        table.ps-grid tr{ page-break-inside:avoid; }
        thead{ display:table-header-group; }
    }
</style>
CSS;
}

/**
 * The `.payslip` card itself -- one HTML/CSS structure for every employee
 * regardless of salary_type/pay_frequency, branching only on salary_type
 * to swap labels/rows (the original spec's explicit requirement). Callers
 * provide payslipDocumentStyles() separately (once per document) so this
 * function can be looped for a bulk print without repeating the <style>
 * block per card.
 */
function renderPayslipCardHtml(array $payslip, array $extraEarnings, array $deductions, string $restaurantName, string $restaurantAddress, string $logoSrc = '../assets/images/logo.jpg'): string
{
    $isDaily = $payslip['salary_type'] === 'daily';
    $basicPayLabel = 'Basic Pay';
    $rateLabel = $isDaily ? 'Daily Rate' : 'Monthly Salary';
    $rateValue = $isDaily ? formatPeso((float)$payslip['basic_rate']) . ' / day' : formatPeso((float)$payslip['basic_rate']) . ' / mo';
    $employeeName = trim($payslip['first_name'] . ' ' . $payslip['last_name']);

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
            <h2>Payslip</h2>
            <div><?= htmlspecialchars($payslip['run_number']) ?></div>
        </div>
    </div>

    <div class="ps-meta">
        <div class="ps-meta-block">
            <h3>Employee</h3>
            <div class="ps-meta-row"><span>Name</span><span><?= htmlspecialchars($employeeName) ?></span></div>
            <div class="ps-meta-row"><span>Employee #</span><span><?= htmlspecialchars($payslip['employee_number']) ?></span></div>
            <div class="ps-meta-row"><span>Position</span><span><?= htmlspecialchars($payslip['position_title'] ?? '—') ?></span></div>
            <div class="ps-meta-row"><span>Department</span><span><?= htmlspecialchars($payslip['department_name'] ?? '—') ?></span></div>
            <div class="ps-meta-row"><span><?= htmlspecialchars($rateLabel) ?></span><span><?= htmlspecialchars($rateValue) ?></span></div>
        </div>
        <div class="ps-meta-block">
            <h3>Pay period</h3>
            <div class="ps-meta-row"><span>Frequency</span><span><?= htmlspecialchars(payFrequencyLabel($payslip['pay_frequency'])) ?></span></div>
            <div class="ps-meta-row"><span>Cutoff</span><span><?= date('M j', strtotime($payslip['cutoff_period_start'])) ?> – <?= date('M j, Y', strtotime($payslip['cutoff_period_end'])) ?></span></div>
            <div class="ps-meta-row"><span>Payout date</span><span><?= date('M j, Y', strtotime($payslip['payout_date'])) ?></span></div>
        </div>
    </div>

    <div class="ps-section">
        <h3>Earnings</h3>
        <table class="ps-table">
            <tr><td><?= htmlspecialchars($basicPayLabel) ?></td><td><?= formatPeso((float)$payslip['basic_pay']) ?></td></tr>
            <?php if ((float)$payslip['overtime_pay'] > 0): ?><tr><td>Overtime Pay</td><td><?= formatPeso((float)$payslip['overtime_pay']) ?></td></tr><?php endif; ?>
            <?php if ((float)$payslip['holiday_pay'] > 0): ?><tr><td>Holiday Pay</td><td><?= formatPeso((float)$payslip['holiday_pay']) ?></td></tr><?php endif; ?>
            <?php if ((float)$payslip['rest_day_pay'] > 0): ?><tr><td>Rest Day Pay</td><td><?= formatPeso((float)$payslip['rest_day_pay']) ?></td></tr><?php endif; ?>
            <?php if ((float)$payslip['night_differential_pay'] > 0): ?><tr><td>Night Differential Pay</td><td><?= formatPeso((float)$payslip['night_differential_pay']) ?></td></tr><?php endif; ?>
            <?php /* Monthly employees never get a separate leave_pay figure --
                     their flat salary already covers every calendar day,
                     leave included (see payroll_run_functions.php) -- so this
                     row would only ever show a bare em-dash for them. Shown
                     for daily-paid employees only, where it's real money. */ ?>
            <?php if ($isDaily && (float)($payslip['paid_leave_days'] ?? 0) > 0): ?>
                <tr>
                    <td>Paid Leave<small><?= number_format((float)$payslip['paid_leave_days'], 2) ?> day(s)</small></td>
                    <td><?= formatPeso((float)$payslip['leave_pay']) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($extraEarnings as $e): ?>
                <tr><td><?= htmlspecialchars($e['earning_type']) ?><?php if ($e['description']): ?><small><?= htmlspecialchars($e['description']) ?></small><?php endif; ?></td><td><?= formatPeso((float)$e['amount']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="ps-subtotal"><td>Gross Pay</td><td><?= formatPeso((float)$payslip['gross_pay']) ?></td></tr>
        </table>
    </div>

    <div class="ps-section">
        <h3>Deductions</h3>
        <table class="ps-table">
            <?php if ((float)$payslip['late_deduction'] > 0): ?><tr><td>Late Deduction</td><td>-<?= formatPeso((float)$payslip['late_deduction']) ?></td></tr><?php endif; ?>
            <?php if ((float)($payslip['undertime_deduction'] ?? 0) > 0): ?><tr><td>Undertime Deduction</td><td>-<?= formatPeso((float)$payslip['undertime_deduction']) ?></td></tr><?php endif; ?>
            <?php if (!$isDaily && (float)$payslip['absence_deduction'] > 0): ?><tr><td>Absences/Unpaid Leave</td><td>-<?= formatPeso((float)$payslip['absence_deduction']) ?></td></tr><?php endif; ?>
            <?php foreach ($deductions as $d): ?>
                <tr><td><?= htmlspecialchars($d['deduction_name']) ?><?php if ($d['description']): ?><small><?= htmlspecialchars($d['description']) ?></small><?php endif; ?></td><td>-<?= formatPeso((float)$d['employee_amount']) ?></td></tr>
            <?php endforeach; ?>
            <?php if ((float)$payslip['late_deduction'] <= 0 && (float)($payslip['undertime_deduction'] ?? 0) <= 0 && ($isDaily || (float)$payslip['absence_deduction'] <= 0) && empty($deductions)): ?>
                <tr><td colspan="2" style="color:var(--ps-ink-soft);">No deductions this period.</td></tr>
            <?php endif; ?>
            <tr class="ps-subtotal"><td>Total Deductions</td><td>-<?= formatPeso((float)$payslip['total_deductions']) ?></td></tr>
        </table>
    </div>

    <div class="ps-totals">
        <div class="ps-totals-row"><span>Gross Pay</span><span><?= formatPeso((float)$payslip['gross_pay']) ?></span></div>
        <div class="ps-totals-row"><span>Total Deductions</span><span>-<?= formatPeso((float)$payslip['total_deductions']) ?></span></div>
        <div class="ps-totals-row ps-net"><span>Net Pay</span><span><?= formatPeso((float)$payslip['net_pay']) ?></span></div>
    </div>

    <div class="ps-footer">Generated <?= date('M j, Y g:i A', strtotime($payslip['generated_at'])) ?> · This is a system-generated payslip.</div>
</div>
    <?php
    return ob_get_clean();
}

/**
 * The run-wide Summary Report card -- reuses the exact same .ps-* classes
 * (and therefore payslipDocumentStyles()/payslipPdfStyles()) as the payslip
 * card above, rather than a second stylesheet, so the two documents look
 * like they belong to the same system instead of two different ones.
 * $summary is getPayrollRunSummary()'s return array.
 */
function renderPayrollSummaryHtml(array $summary, string $restaurantName, string $restaurantAddress, string $logoSrc = '../assets/images/logo.jpg'): string
{
    $run = $summary['run'];
    $earnings = $summary['earnings'];
    $totals = $summary['totals'];
    $otherTotal = (float)$totals['late_deduction'] + (float)($totals['undertime_deduction'] ?? 0) + (float)$totals['absence_deduction'];
    foreach ($summary['other_deductions'] as $o) {
        $otherTotal += (float)$o['employee_total'];
    }
    $govEmpTotal = 0.0;
    $govErTotal = 0.0;
    foreach ($summary['gov_deductions'] as $g) {
        $govEmpTotal += (float)$g['employee_total'];
        $govErTotal += (float)$g['employer_total'];
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
            <h2>Payroll Summary Report</h2>
            <div><?= htmlspecialchars($run['run_number']) ?></div>
            <div><span class="ps-status-pill is-<?= htmlspecialchars($run['status']) ?>"><?= htmlspecialchars(payrollRunStatusLabel($run['status'])) ?></span></div>
        </div>
    </div>

    <div class="ps-meta">
        <div class="ps-meta-block">
            <h3>Pay period</h3>
            <div class="ps-meta-row"><span>Frequency</span><span><?= htmlspecialchars(payFrequencyLabel($run['pay_frequency'])) ?></span></div>
            <div class="ps-meta-row"><span>Cutoff</span><span><?= date('M j', strtotime($run['cutoff_period_start'])) ?> – <?= date('M j, Y', strtotime($run['cutoff_period_end'])) ?></span></div>
            <div class="ps-meta-row"><span>Payout date</span><span><?= date('M j, Y', strtotime($run['payout_date'])) ?></span></div>
        </div>
        <div class="ps-meta-block">
            <h3>Run</h3>
            <div class="ps-meta-row"><span>Employees Paid</span><span><?= (int)$totals['employee_count'] ?></span></div>
            <div class="ps-meta-row"><span>Status</span><span><?= htmlspecialchars(payrollRunStatusLabel($run['status'])) ?></span></div>
        </div>
    </div>

    <div class="ps-section">
        <h3>Earnings</h3>
        <table class="ps-table">
            <tr><td>Basic Pay</td><td><?= formatPeso((float)$earnings['basic_pay']) ?></td></tr>
            <?php if ((float)$earnings['overtime_pay'] > 0): ?><tr><td>Overtime Pay</td><td><?= formatPeso((float)$earnings['overtime_pay']) ?></td></tr><?php endif; ?>
            <?php if ((float)$earnings['holiday_pay'] > 0): ?><tr><td>Holiday Pay</td><td><?= formatPeso((float)$earnings['holiday_pay']) ?></td></tr><?php endif; ?>
            <?php if ((float)$earnings['rest_day_pay'] > 0): ?><tr><td>Rest Day Pay</td><td><?= formatPeso((float)$earnings['rest_day_pay']) ?></td></tr><?php endif; ?>
            <?php if ((float)$earnings['night_differential_pay'] > 0): ?><tr><td>Night Differential Pay</td><td><?= formatPeso((float)$earnings['night_differential_pay']) ?></td></tr><?php endif; ?>
            <?php if ((float)$earnings['leave_pay'] > 0): ?><tr><td>Leave Pay</td><td><?= formatPeso((float)$earnings['leave_pay']) ?></td></tr><?php endif; ?>
            <?php if ((float)$earnings['other_earnings'] > 0): ?><tr><td>Other Earnings (bonuses/allowances)</td><td><?= formatPeso((float)$earnings['other_earnings']) ?></td></tr><?php endif; ?>
            <tr class="ps-subtotal"><td>Total Gross Pay</td><td><?= formatPeso((float)$earnings['gross_pay']) ?></td></tr>
        </table>
    </div>

    <?php /* Employee / Employer / Total as three real columns. Cramming the
             split into a <small> under the name made the one number the
             remitter actually files against impossible to read off, and every
             PH payroll summary presents these side by side because SSS,
             PhilHealth and Pag-IBIG are each remitted as employee share PLUS
             employer share. */ ?>
    <div class="ps-section">
        <h3>Statutory Contributions &amp; Remittances</h3>
        <table class="ps-table ps-table-3col">
            <thead>
                <tr>
                    <th>Contribution</th>
                    <th class="num">Employee Share</th>
                    <th class="num">Employer Share</th>
                    <th class="num">Total Remittance</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($summary['gov_deductions'])): ?>
                <tr><td colspan="4" style="color:var(--ps-ink-soft);">No government contributions on this run.</td></tr>
            <?php else: ?>
                <?php foreach ($summary['gov_deductions'] as $g): ?>
                    <tr>
                        <td><?= htmlspecialchars($g['name']) ?></td>
                        <td class="num"><?= formatPeso((float)$g['employee_total']) ?></td>
                        <td class="num"><?= (int)$g['has_employer_share'] === 1 ? formatPeso((float)$g['employer_total']) : '&mdash;' ?></td>
                        <td class="num"><?= formatPeso((float)$g['employee_total'] + (float)$g['employer_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="ps-subtotal">
                    <td>Total</td>
                    <td class="num"><?= formatPeso($govEmpTotal) ?></td>
                    <td class="num"><?= formatPeso($govErTotal) ?></td>
                    <td class="num"><?= formatPeso($govEmpTotal + $govErTotal) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="ps-section">
        <h3>Other Deductions</h3>
        <table class="ps-table">
            <?php if ((float)$totals['late_deduction'] > 0): ?><tr><td>Late Deduction</td><td>-<?= formatPeso((float)$totals['late_deduction']) ?></td></tr><?php endif; ?>
            <?php if ((float)($totals['undertime_deduction'] ?? 0) > 0): ?><tr><td>Undertime Deduction</td><td>-<?= formatPeso((float)$totals['undertime_deduction']) ?></td></tr><?php endif; ?>
            <?php if ((float)$totals['absence_deduction'] > 0): ?><tr><td>Absences / Unpaid Leave</td><td>-<?= formatPeso((float)$totals['absence_deduction']) ?></td></tr><?php endif; ?>
            <?php foreach ($summary['other_deductions'] as $o): ?>
                <tr><td><?= htmlspecialchars($o['name']) ?></td><td>-<?= formatPeso((float)$o['employee_total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($otherTotal <= 0): ?>
                <tr><td colspan="2" style="color:var(--ps-ink-soft);">No late, undertime, absence, or manual deductions on this run.</td></tr>
            <?php else: ?>
                <tr class="ps-subtotal"><td>Total</td><td>-<?= formatPeso($otherTotal) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <?php /* Two figures, deliberately separated. Net pay is what the employees
             receive; cost to company is gross plus the employer's own statutory
             share -- the number the business actually funds, and the one a
             company-level summary is usually read for. Showing only net
             understates the payroll by the employer share every time. */ ?>
    <div class="ps-totals">
        <div class="ps-totals-row"><span>Total Gross Pay</span><span><?= formatPeso((float)$earnings['gross_pay']) ?></span></div>
        <div class="ps-totals-row"><span>Total Deductions</span><span>-<?= formatPeso((float)$totals['total_deductions']) ?></span></div>
        <div class="ps-totals-row ps-net"><span>Total Net Pay (payable to employees)</span><span><?= formatPeso((float)$totals['total_net_pay']) ?></span></div>
        <div class="ps-totals-row"><span>Add: Employer Statutory Share</span><span><?= formatPeso($govErTotal) ?></span></div>
        <div class="ps-totals-row ps-net"><span>Total Cost to Company</span><span><?= formatPeso((float)$earnings['gross_pay'] + $govErTotal) ?></span></div>
    </div>

    <?php /* No per-employee register here: this document is the company-level
             summary, and the line-by-line listing already lives on the run
             detail page and in the individual payslips. */ ?>

    <div class="ps-footer">Generated <?= date('M j, Y g:i A') ?> · This is a system-generated payroll summary report.</div>
</div>
    <?php
    return ob_get_clean();
}

/** The restaurant_name/address pair every payslip render needs -- one lookup, reused by payslip.php/payslip_fragment.php/run_payslips_print.php. */
function getRestaurantIdentity(PDO $pdo): array
{
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('restaurant_name', 'restaurant_address')");
    $siteSettings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'name' => $siteSettings['restaurant_name'] ?: 'OPO! Our Pinoy Original',
        'address' => $siteSettings['restaurant_address'] ?? '',
    ];
}

