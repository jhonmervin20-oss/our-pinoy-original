<?php
/**
 * employee_management/includes/payroll_run_functions.php
 *
 * The DB-backed wrapper the plan always called out as separate from the
 * pure functions in payroll_engine.php: this is where real attendance,
 * leave, adjustment, and holiday rows get assembled into the inputs those
 * pure functions need, for one employee, one cutoff period.
 *
 * Documented scope decisions (things the spec didn't fully pin down):
 *   - A run only ever includes ACTIVE employees whose own pay_frequency
 *     matches the run's pay_frequency -- a run represents one real payout
 *     event, and employees on a different cutoff schedule aren't part of it.
 *   - Holiday and rest-day pay are computed only for hours actually worked
 *     on a date marked status='holiday'/'rest_day' in attendance_records
 *     (computeHolidayPay()/computeRestDayPay(), looked up from the
 *     `holidays` table by date for the holiday type). Those hours are
 *     deliberately excluded from the "regular" hours total fed into
 *     computeBasicOrRegularPay() -- otherwise a daily-paid employee's worked
 *     holiday would be paid once via basic pay (at 1x) and again via
 *     holiday pay (at the full multiplier), a real double-count bug this
 *     file used to have. computeHolidayPay()/computeRestDayPay() apply the
 *     full multiplier for daily-paid employees (their basic pay only covers
 *     for hours actually clocked, so the full multiplier IS that day's
 *     entire pay) but only the *incremental* amount above 1.0x for monthly
 *     employees (their flat salary already includes the base 100% for any
 *     day of the month, worked or not). Unworked-regular-holiday pay for
 *     daily-paid employees (a real DOLE entitlement) is still NOT auto-computed
 *     -- there's no attendance row to derive hours from for a day nobody
 *     clocked into, and a monthly employee doesn't need this modeled
 *     separately since their flat basic pay already covers unworked days
 *     by construction.
 *   - Overtime is only paid up to whatever a manager separately
 *     pre-authorized for that employee/date in `overtime_authorizations`
 *     (employee_management/overtime.php) -- no authorization on file means
 *     zero OT paid, full stop, regardless of what got clocked. A date can
 *     have MULTIPLE approved authorization rows (an initial pre-authorized
 *     amount, plus a later separate top-up if actual attendance exceeds it
 *     -- "excess approval", same manager's-save-action-IS-the-approval
 *     convention as employee_leave_records, just allowed to stack instead
 *     of being edited in place); they are SUMMED per date. The paid amount
 *     is the LESSER of that sum and attendance_records.overtime_minutes
 *     (the clock-derived candidate figure computed by
 *     computeBiometricOvertimeMinutes()), so an employee is never paid for
 *     more OT than they actually worked, and never for more than a manager
 *     actually signed off on. Overtime is bucketed by the day-type it was worked on -- OT on a plain day uses
 *     overtime_multiplier, OT on a rest day uses rest_day_ot_multiplier, OT
 *     on a holiday uses regular_holiday_ot_multiplier/special_holiday_ot_multiplier
 *     by the date's type in `holidays`.
 *   - Leave pay is daily-paid-only, same reasoning as the holiday/rest-day
 *     baseline subtraction above: a monthly employee's flat basic pay
 *     already covers every calendar day of the period including approved
 *     leave, so adding leave pay on top would double-pay those days. Only
 *     daily-paid employees (whose basic pay excludes unworked days entirely)
 *     get a real leave-pay line, counted from approved leave_records whose
 *     leave_type is paid (is_paid=1), clamped to the cutoff period, at
 *     one Daily Rate per leave day. A leave date that lands on a
 *     scheduled rest day (employee_schedules, is_rest_day=1) is excluded
 *     from that count entirely -- no work, no pay applies to rest days
 *     regardless of leave, so it neither earns leave pay nor an absence
 *     deduction if the leave type happens to be unpaid.
 *   - A calendar day inside the cutoff with NO attendance record at all
 *     counts as an absence too, not just a day explicitly marked 'absent'
 *     -- unless it's an actual scheduled rest day (employee_schedules,
 *     is_rest_day=1) or covered by approved leave (paid or unpaid -- both
 *     count as *documented*, so neither trips the "nobody logged anything"
 *     gap this walk is looking for). An employee with zero attendance for
 *     the whole period used to get their full pay with a ₱0 absence
 *     deduction, since there was no row to even mark 'absent' -- a real
 *     bug. A day with no schedule at all is NOT automatically excused, so
 *     schedules (rest days included) need to actually be set up for this
 *     to work as intended. Precisely because of that, an employee with NO
 *     schedule at all can have every calendar day of the period counted
 *     absent (nothing to exclude rest days) -- computeAbsenceDeduction()
 *     caps the resulting deduction at Basic Pay so this can never exceed a
 *     full period's pay (Daily Rate is calibrated off
 *     annual_working_days_divisor, a *working*-days figure, so multiplying
 *     it by a *calendar*-days count can otherwise overshoot).
 *   - Unpaid-leave days are folded into $daysAbsent (after the walk above,
 *     not inside it -- they're still "documented", just not paid) so they
 *     get priced through the same computeAbsenceDeduction() formula and
 *     surface as the combined "Absences/Unpaid Leave" payslip line. Fixed
 *     2026-08-04: this used to treat approved unpaid leave exactly like
 *     approved PAID leave (fully excused, zero deduction) for a monthly
 *     employee, which paid them in full for leave that was supposed to be
 *     unpaid. No-ops for daily-paid employees either way -- their basic pay
 *     already excludes unclocked hours by construction.
 *   - Government contributions need a "monthly compensation" figure for
 *     their bracket lookups even though a run might be semi-monthly --
 *     monthly employees use basic_rate directly; daily-paid employees use
 *     this period's regular pay x (2 for semi-monthly, 1 for monthly) as
 *     an approximation of their monthly earning rate.
 *   - Withholding tax's taxable income = gross pay minus the *employee*
 *     share of SSS/PhilHealth/Pag-IBIG (mandatory contributions are
 *     non-taxable under PH law), floored at 0.
 *   - Government contributions/withholding tax are skipped entirely (not
 *     pro-rated) for a period where the absence deduction fully absorbs
 *     gross pay (gross pay - absence deduction <= 0) -- a fully-unpaid
 *     period means zero actual compensable earnings to base a contribution
 *     on. This only fires for that specific edge case; a partial-absence
 *     period still computes contributions against the nominal salary
 *     exactly as before.
 */

require_once __DIR__ . '/payroll_engine.php';

function generatePayrollRunNumber(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT run_number FROM payroll_runs ORDER BY payroll_run_id DESC LIMIT 1");
    $last = $stmt->fetchColumn();

    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }

    return 'PR-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Settings day 0 = last day of the month -- same convention employee_management/runs.php's client-side JS already resolves. */
function resolvePayrollCutoffDay(int $year, int $month, int $day): int
{
    return $day === 0 ? (int)date('t', mktime(0, 0, 0, $month, 1, $year)) : $day;
}

/** @return array{0:int,1:int} [year, month] shifted by $delta months (negative rolls back). */
function addPayrollMonths(int $year, int $month, int $delta): array
{
    $month += $delta;
    while ($month > 12) { $month -= 12; $year++; }
    while ($month < 1) { $month += 12; $year--; }
    return [$year, $month];
}

/**
 * Builds the 3 candidate cutoff periods (monthly, semi-monthly 1st half,
 * semi-monthly 2nd half) for one specific calendar month, from
 * payroll_settings' day-of-month configuration. Pure date math, no DB
 * access -- shared by findDuePayrollCutoffs() (which scans several months)
 * and reconstructPayrollCutoffByEnd() (which needs just one month's worth
 * to rebuild a period from its end date alone).
 *
 * @return array<int, array{pay_frequency:string, start:string, end:string, payout:string}>
 */
function buildPayrollCutoffCandidatesForMonth(array $settings, int $year, int $month): array
{
    $day = fn(int $d): int => resolvePayrollCutoffDay($year, $month, $d);
    $dateStr = fn(int $d): string => sprintf('%04d-%02d-%02d', $year, $month, $d);

    [$mpY, $mpM] = ((int)$settings['monthly_payout_next_month']) ? addPayrollMonths($year, $month, 1) : [$year, $month];
    [$c2pY, $c2pM] = ((int)$settings['semi_monthly_cutoff2_payout_next_month']) ? addPayrollMonths($year, $month, 1) : [$year, $month];

    return [
        [
            'pay_frequency' => 'monthly',
            'start'  => $dateStr($day((int)$settings['monthly_cutoff_start_day'])),
            'end'    => $dateStr($day((int)$settings['monthly_cutoff_end_day'])),
            'payout' => sprintf('%04d-%02d-%02d', $mpY, $mpM, resolvePayrollCutoffDay($mpY, $mpM, (int)$settings['monthly_payout_day'])),
        ],
        [
            'pay_frequency' => 'semi_monthly',
            'start'  => $dateStr($day((int)$settings['semi_monthly_cutoff1_start_day'])),
            'end'    => $dateStr($day((int)$settings['semi_monthly_cutoff1_end_day'])),
            'payout' => $dateStr($day((int)$settings['semi_monthly_cutoff1_payout_day'])),
        ],
        [
            'pay_frequency' => 'semi_monthly',
            'start'  => $dateStr($day((int)$settings['semi_monthly_cutoff2_start_day'])),
            'end'    => $dateStr($day((int)$settings['semi_monthly_cutoff2_end_day'])),
            'payout' => sprintf('%04d-%02d-%02d', $c2pY, $c2pM, resolvePayrollCutoffDay($c2pY, $c2pM, (int)$settings['semi_monthly_cutoff2_payout_day'])),
        ],
    ];
}

/**
 * Finds cutoff periods that have fully closed (end date <= today) but have
 * no matching, non-cancelled payroll_runs row yet -- "a payroll cutoff needs
 * to run". Checks the current AND previous calendar month for each
 * frequency, so a period that closed since the last time this ran isn't
 * missed.
 *
 * Returns a list of ['pay_frequency','start','end','payout'] for every due
 * period (usually 0 or 1 -- more only if payroll hasn't been run in a while).
 */
function findDuePayrollCutoffs(PDO $pdo, array $settings): array
{
    $today = date('Y-m-d');
    $thisYear = (int)date('Y');
    $thisMonth = (int)date('n');
    [$prevYear, $prevMonth] = addPayrollMonths($thisYear, $thisMonth, -1);

    $candidates = array_merge(
        buildPayrollCutoffCandidatesForMonth($settings, $thisYear, $thisMonth),
        buildPayrollCutoffCandidatesForMonth($settings, $prevYear, $prevMonth)
    );

    $seen = [];
    $closed = [];
    foreach ($candidates as $c) {
        $key = $c['pay_frequency'] . '|' . $c['start'] . '|' . $c['end'];
        if (isset($seen[$key]) || $c['end'] > $today) {
            continue;
        }
        $seen[$key] = true;
        $closed[] = $c;
    }

    $due = [];
    $existsStmt = $pdo->prepare(
        "SELECT run_number FROM payroll_runs
         WHERE pay_frequency = ? AND cutoff_period_start = ? AND cutoff_period_end = ? AND status != 'cancelled'
         LIMIT 1"
    );
    foreach ($closed as $c) {
        $existsStmt->execute([$c['pay_frequency'], $c['start'], $c['end']]);
        if (!$existsStmt->fetch()) {
            $due[] = $c;
        }
    }

    return $due;
}

/**
 * Reconstructs a cutoff period's [start,end,payout] purely from its
 * frequency + end date -- used by manager/notification_go.php to rebuild the
 * exact prefill values for a payroll_cutoff_due_* notification's redirect,
 * since only the end date (as reference_id) was stored, not the whole period.
 * $endDate's own year/month always contains that period's start too (a
 * cutoff period never spans a calendar-month boundary in this app's model).
 */
function reconstructPayrollCutoffByEnd(array $settings, string $payFrequency, string $endDate): ?array
{
    $ts = strtotime($endDate);
    if ($ts === false) {
        return null;
    }
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);

    foreach (buildPayrollCutoffCandidatesForMonth($settings, $year, $month) as $c) {
        if ($c['pay_frequency'] === $payFrequency && $c['end'] === $endDate) {
            return $c;
        }
    }
    return null;
}

/** All 4 government tables -- fetch once per run, not per employee. */
function loadGovernmentTables(PDO $pdo): array
{
    return [
        'sss' => $pdo->query("SELECT * FROM sss_contribution_table")->fetchAll(PDO::FETCH_ASSOC),
        'philhealth' => $pdo->query("SELECT * FROM philhealth_contribution_table")->fetchAll(PDO::FETCH_ASSOC),
        'pagibig' => $pdo->query("SELECT * FROM pagibig_contribution_table")->fetchAll(PDO::FETCH_ASSOC),
        'wtax' => $pdo->query("SELECT * FROM bir_withholding_tax_table")->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** payroll_deduction_types indexed by code, for looking up deduction_type_id when writing payslip_deductions rows. */
function loadDeductionTypesByCode(PDO $pdo): array
{
    $rows = $pdo->query("SELECT deduction_type_id, code, category, has_employer_share FROM payroll_deduction_types")->fetchAll(PDO::FETCH_ASSOC);
    $byCode = [];
    foreach ($rows as $row) {
        $byCode[$row['code']] = $row;
    }
    return $byCode;
}

/**
 * DOLE unworked-regular-holiday-pay eligibility: present/late/holiday
 * or approved PAID leave on the workday immediately preceding the holiday.
 * Fails open (qualifies) when the prior day wasn't a real scheduled workday
 * (rest day, itself a holiday, or no schedule row at all) -- entitlement is
 * forfeited only by a demonstrated unexcused absence on an actual expected
 * workday, not by the absence of schedule data. Single-calendar-day lookback
 * only, not a walk back through multiple consecutive rest days/holidays.
 */
function employeeQualifiesForUnworkedHolidayPay(PDO $pdo, int $employeeId, string $holidayDate): bool
{
    $priorDate = (new DateTime($holidayDate))->modify('-1 day')->format('Y-m-d');

    $schedStmt = $pdo->prepare("SELECT is_rest_day FROM employee_schedules WHERE employee_id = ? AND schedule_date = ?");
    $schedStmt->execute([$employeeId, $priorDate]);
    $priorIsRestDay = $schedStmt->fetchColumn();
    if ($priorIsRestDay === false || (int)$priorIsRestDay === 1) {
        return true;
    }

    $holStmt = $pdo->prepare("SELECT 1 FROM holidays WHERE holiday_date = ? AND is_active = 1");
    $holStmt->execute([$priorDate]);
    if ($holStmt->fetchColumn()) {
        return true;
    }

    $attStmt = $pdo->prepare("SELECT status FROM attendance_records WHERE employee_id = ? AND attendance_date = ?");
    $attStmt->execute([$employeeId, $priorDate]);
    if (in_array($attStmt->fetchColumn(), ['present', 'late', 'holiday'], true)) {
        return true;
    }

    $leaveStmt = $pdo->prepare(
        "SELECT 1 FROM employee_leave_records r
         JOIN leave_types lt ON lt.leave_type_id = r.leave_type_id
         WHERE r.employee_id = ? AND r.status = 'approved' AND lt.is_paid = 1
           AND ? BETWEEN r.start_date AND r.end_date"
    );
    $leaveStmt->execute([$employeeId, $priorDate]);
    return (bool)$leaveStmt->fetchColumn();
}

/**
 * Computes one employee's full payslip breakdown for a cutoff period.
 * Returns everything needed to insert one payslips row plus its
 * payslip_earnings/payslip_deductions children -- nothing is written to
 * the database here, this function only computes.
 */
function runPayrollForEmployee(
    PDO $pdo,
    array $employee,
    string $cutoffStart,
    string $cutoffEnd,
    array $settings,
    array $govTables,
    array $deductionTypesByCode
): array {
    $employeeId = (int)$employee['employee_id'];
    $salaryType = $employee['salary_type'];
    $payFrequency = $employee['pay_frequency'];
    $basicRate = (float)$employee['basic_rate'];

    // -- Attendance aggregation over the cutoff period --------------------
    $attStmt = $pdo->prepare(
        "SELECT attendance_date, status, total_hours_worked, late_minutes, undertime_minutes,
                overtime_minutes, night_differential_hours
         FROM attendance_records WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
    );
    $attStmt->execute([$employeeId, $cutoffStart, $cutoffEnd]);
    $attendance = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    // -- Manager-authorized OT minutes per date, this cutoff -- the cap on
    // what actually gets paid, regardless of what was clocked. A date can
    // have more than one approved row (an initial pre-authorization plus a
    // later "excess approval" top-up), so these are SUMMED per date rather
    // than expecting exactly one. See employee_management/overtime.php.
    $otAuthStmt = $pdo->prepare(
        "SELECT work_date, SUM(authorized_minutes) AS total_minutes FROM overtime_authorizations
         WHERE employee_id = ? AND status = 'approved' AND work_date BETWEEN ? AND ?
         GROUP BY work_date"
    );
    $otAuthStmt->execute([$employeeId, $cutoffStart, $cutoffEnd]);
    $authorizedMinutesByDate = [];
    foreach ($otAuthStmt->fetchAll(PDO::FETCH_ASSOC) as $auth) {
        $authorizedMinutesByDate[$auth['work_date']] = (int)$auth['total_minutes'];
    }

    $totalHoursWorked = 0.0;
    $regularHoursWorked = 0.0;
    // Ordinary-day count that feeds a DAILY-paid employee's basic pay.
    // Deliberately separate from $daysWorked below: that one is the
    // payslip's informational "days worked" stat and includes holiday- and
    // rest-day-worked days, which are paid through their own full-multiplier
    // buckets. Counting those here too would pay the same day twice.
    $regularDaysWorked = 0.0;
    $daysWorked = 0;
    $daysAbsent = 0;
    $lateMinutesTotal = 0;
    $undertimeMinutesTotal = 0;
    $regularOtHoursTotal = 0.0;
    $restDayOtHoursTotal = 0.0;
    $holidayOtHoursByDate = [];
    $nightDiffHoursTotal = 0.0;
    $holidayHoursByDate = [];
    $restDayHoursByDate = [];

    foreach ($attendance as $a) {
        $hoursThisDay = (float)$a['total_hours_worked'];
        $totalHoursWorked += $hoursThisDay;
        $lateMinutesTotal += (int)$a['late_minutes'];
        $undertimeMinutesTotal += (int)$a['undertime_minutes'];
        $nightDiffHoursTotal += (float)$a['night_differential_hours'];

        // Overtime is bucketed by the day-type it was actually worked on --
        // OT on a rest day or holiday earns the higher combined premium
        // (payroll_settings.rest_day_ot_multiplier / *_holiday_ot_multiplier),
        // not the flat overtime_multiplier every OT hour used to get
        // regardless of the date. $otHoursThisRow is capped at the LESSER of
        // what was actually clocked and what a manager authorized for this
        // date -- no authorization on file means 0 here, same effect as the
        // old "unapproved" case: the hours still get paid at straight time
        // via the base bucket below, just without a premium.
        $authorizedMinutes = $authorizedMinutesByDate[$a['attendance_date']] ?? 0;
        $otHoursThisRow = min((int)$a['overtime_minutes'], $authorizedMinutes) / 60;

        if (in_array($a['status'], ['present', 'late', 'holiday'], true)) {
            $daysWorked++;
        }
        if ($a['status'] === 'absent') {
            $daysAbsent++;
        }

        // Holiday- and rest-day-worked hours are excluded from the "regular"
        // hours total that feeds computeBasicOrRegularPay() -- they get paid
        // through computeHolidayPay()/computeRestDayPay() instead (at the
        // full multiplier for daily-paid employees), so including them here too
        // would pay the same hours twice. $totalHoursWorked above still
        // includes them, since that figure is only ever used for the
        // payslip's informational "Hours Worked" stat, not as a pay input.
        //
        // Authorized OT hours are excluded the same way, from whichever of
        // the three buckets they'd otherwise land in -- computeOvertimePay()'s
        // multiplier (or rest_day_ot_multiplier/*_holiday_ot_multiplier) is
        // the hour's FULL rate, not a premium stacked on top of an
        // already-paid base. Unauthorized (or partially-authorized) OT keeps
        // the remainder of $otHoursThisRow at 0 (see above), so it's
        // untouched by this subtraction -- still paid at straight time via
        // the bucket, just without a premium.
        if ($a['status'] === 'holiday' && $hoursThisDay > 0) {
            $holidayHoursByDate[$a['attendance_date']] = $hoursThisDay - $otHoursThisRow;
            if ($otHoursThisRow > 0) {
                $holidayOtHoursByDate[$a['attendance_date']] = ($holidayOtHoursByDate[$a['attendance_date']] ?? 0.0) + $otHoursThisRow;
            }
        } elseif ($a['status'] === 'rest_day' && $hoursThisDay > 0) {
            $restDayHoursByDate[$a['attendance_date']] = $hoursThisDay - $otHoursThisRow;
            $daysWorked++;
            $restDayOtHoursTotal += $otHoursThisRow;
        } else {
            $regularHoursWorked += $hoursThisDay - $otHoursThisRow;
            $regularOtHoursTotal += $otHoursThisRow;
            // Only statuses that represent actually-rendered work earn a
            // daily-paid day. 'absent' and 'on_leave' are excluded: absence
            // is "no work, no pay", and leave is paid separately through
            // $leavePay below -- counting either here would pay it twice.
            // A day counts as a FULL 1.0 even when cut short -- undertime
            // (leaving early, including what used to be a manual "half day")
            // is charged separately via $undertimeDeduction, not by shaving
            // this figure, so the shortfall is never charged twice.
            if (in_array($a['status'], ['present', 'late'], true)) {
                $regularDaysWorked += 1.0;
            }
        }
    }

    // -- Rest days (per schedule) and approved-leave dates for this period --
    // Needed below both for leave pay and to decide which "missing
    // attendance" dates are legitimately excused (a rest day or approved
    // leave) versus genuinely undocumented.
    $restDayDates = [];
    $restStmt = $pdo->prepare(
        "SELECT schedule_date FROM employee_schedules WHERE employee_id = ? AND schedule_date BETWEEN ? AND ? AND is_rest_day = 1"
    );
    $restStmt->execute([$employeeId, $cutoffStart, $cutoffEnd]);
    foreach ($restStmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $restDayDates[$d] = true;
    }

    $leaveDatesAll = [];
    $paidLeaveDates = [];
    $paidLeaveDaysInPeriod = 0;
    $unpaidLeaveDaysInPeriod = 0;
    $leaveStmt = $pdo->prepare(
        "SELECT r.start_date, r.end_date, lt.is_paid FROM employee_leave_records r
         JOIN leave_types lt ON lt.leave_type_id = r.leave_type_id
         WHERE r.employee_id = ? AND r.status = 'approved'
           AND r.start_date <= ? AND r.end_date >= ?"
    );
    $leaveStmt->execute([$employeeId, $cutoffEnd, $cutoffStart]);
    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
        $overlapStart = max($leave['start_date'], $cutoffStart);
        $overlapEnd = min($leave['end_date'], $cutoffEnd);
        if ($overlapEnd < $overlapStart) {
            continue;
        }
        $cursor = new DateTime($overlapStart);
        $leaveEnd = new DateTime($overlapEnd);
        while ($cursor <= $leaveEnd) {
            $dateKey = $cursor->format('Y-m-d');
            $leaveDatesAll[$dateKey] = true;
            // No work, no pay applies to rest days regardless of leave --
            // a date that's already a scheduled rest day was never a paid
            // workday to begin with, so leave filed on it neither earns
            // leave pay (paid leave type) nor an absence deduction (unpaid
            // leave type). It's just a rest day; the leave record covers it
            // for balance-tracking purposes only.
            if (!isset($restDayDates[$dateKey])) {
                if ((int)$leave['is_paid'] === 1) {
                    $paidLeaveDaysInPeriod++;
                    $paidLeaveDates[$dateKey] = true;
                } else {
                    $unpaidLeaveDaysInPeriod++;
                }
            }
            $cursor->modify('+1 day');
        }
    }

    // -- Active holiday dates in this period, by type. Used below both to
    // exclude holidays from the missing-attendance walk (a holiday, worked
    // or not, was never a "nobody logged anything" gap) and to compute
    // unworked-regular-holiday pay for daily-paid employees further down.
    $holidayTypeByDate = [];
    $holPeriodStmt = $pdo->prepare("SELECT holiday_date, holiday_type FROM holidays WHERE holiday_date BETWEEN ? AND ? AND is_active = 1");
    $holPeriodStmt->execute([$cutoffStart, $cutoffEnd]);
    foreach ($holPeriodStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $holidayTypeByDate[$h['holiday_date']] = $h['holiday_type'];
    }

    // -- Missing-attendance days count as absences too: a calendar day in
    // the cutoff with no attendance record at all, that isn't a scheduled
    // rest day, isn't a holiday, and isn't covered by approved leave, means
    // nobody logged anything for it -- treated the same as an explicit
    // 'absent' status. Without this, an employee with zero attendance for
    // the whole period (nothing to even mark 'absent', since there's no row
    // to mark) got their full pay with a ₱0 deduction -- a real bug, not an
    // edge case. A day with no schedule at all is NOT automatically excused
    // -- only an actual schedule row with is_rest_day=1 protects a date, so
    // setting up schedules (including rest days) matters for accurate
    // monthly payroll.
    // $ordinaryWorkingDaysInPeriod counts the same days this walk is allowed
    // to charge as absences -- every calendar day that is neither a scheduled
    // rest day nor a holiday -- regardless of whether attendance or leave
    // actually covered it. It is the denominator computeAbsenceDeduction()
    // needs to recognise a FULL-period absence exactly, instead of
    // approximating it as Daily Rate x Days and leaving a sub-peso residual
    // behind (see that function's docblock). Leave days are deliberately NOT
    // subtracted here: paid leave is covered by the flat monthly salary, so
    // an employee with 5 paid leave days and 21 absences has 21 < 26 and must
    // NOT be snapped to a full-month deduction.
    $ordinaryWorkingDaysInPeriod = 0;
    $attendanceDatesLogged = array_flip(array_column($attendance, 'attendance_date'));
    $cursor = new DateTime($cutoffStart);
    $periodEnd = new DateTime($cutoffEnd);
    while ($cursor <= $periodEnd) {
        $dateKey = $cursor->format('Y-m-d');
        if (!isset($restDayDates[$dateKey]) && !isset($holidayTypeByDate[$dateKey])) {
            $ordinaryWorkingDaysInPeriod++;
        }
        if (!isset($attendanceDatesLogged[$dateKey]) && !isset($restDayDates[$dateKey])
            && !isset($leaveDatesAll[$dateKey]) && !isset($holidayTypeByDate[$dateKey])) {
            $daysAbsent++;
        }
        $cursor->modify('+1 day');
    }

    // -- Unpaid leave is still leave (a legitimately documented, approved
    // absence -- not an undocumented gap, so it stays excluded from the
    // missing-attendance walk above), but "unpaid" means exactly that: pay
    // must actually be reduced for those days, the same as a real absence.
    // Folded into $daysAbsent here (after the walk, not inside it) so it's
    // priced through the exact same computeAbsenceDeduction() formula and
    // the same combined "Absences/Unpaid Leave" payslip line -- previously
    // this counted as fully excused, so a monthly employee on approved
    // unpaid leave got paid in full, which defeated the point of it being
    // unpaid. No-ops for daily-paid employees (computeAbsenceDeduction()
    // already returns 0 for them -- their basic pay excludes unclocked
    // hours by construction, so there's nothing left to deduct twice).
    $daysAbsent += $unpaidLeaveDaysInPeriod;

    // -- Rate conversion chain --------------------------------------------
    $dailyRate = computeDailyRate($basicRate, $salaryType, (float)$settings['annual_working_days_divisor']);
    $hourlyEquivalent = computeHourlyEquivalent($dailyRate, (float)$settings['standard_work_hours_per_day']);

    // -- Basic/regular pay --------------------------------------------------
    //
    // ROUNDING POLICY (applies to every pay/deduction component below):
    // each one is rounded to centavos HERE, the moment it is finalised, and
    // every total after this point is built from the rounded figures.
    //
    // This used to round only at the very end -- gross pay, taxable income,
    // total deductions and net pay were each computed from full-precision
    // floats and rounded once on the way out, while the individual components
    // were rounded separately for storage. The two roundings disagreed by a
    // centavo whenever enough components had fractional tails, and the payslip
    // then failed to add up against its OWN printed columns: a real July
    // payslip printed gross 30,819.49 less deductions 5,596.84 but a net of
    // 25,222.64 (25,222.65 by subtraction), and a taxable-income note of
    // 25,997.28 where its printed columns gave 25,997.29. Nothing was
    // "wrong" by a peso, but a payslip whose own lines do not foot is
    // indefensible to the person holding it.
    //
    // The rates themselves ($dailyRate/$hourlyEquivalent) are deliberately NOT
    // rounded -- they are intermediate conversion factors, never printed, and
    // rounding them would shift real pay rather than just settle a
    // presentation gap.
    $basicPay = round(computeBasicOrRegularPay($salaryType, $payFrequency, $basicRate, $regularDaysWorked), 2);

    // -- Deduction lines derived from attendance ----------------------------
    $lateDeduction = round(computeLateDeduction($hourlyEquivalent, (float)$lateMinutesTotal), 2);
    $undertimeDeduction = round(computeUndertimeDeduction($hourlyEquivalent, (float)$undertimeMinutesTotal), 2);
    // $basicPay is already rounded above, so the "capped at basic pay" branch
    // returns a rounded figure too.
    $absenceDeduction = round(computeAbsenceDeduction($dailyRate, (float)$daysAbsent, $salaryType, $basicPay, (float)$ordinaryWorkingDaysInPeriod), 2);

    // -- Overtime pay: bucketed by the day-type it was worked on --------------
    // ($holidayTypeByDate was already built above, ahead of the
    // missing-attendance walk -- it covers every active holiday in the
    // period, a superset of just the worked-holiday dates this used to be
    // scoped to.)
    $overtimePay = computeOvertimePay($hourlyEquivalent, $regularOtHoursTotal, (float)$settings['overtime_multiplier']);
    $overtimePay += computeOvertimePay($hourlyEquivalent, $restDayOtHoursTotal, (float)$settings['rest_day_ot_multiplier']);
    foreach ($holidayOtHoursByDate as $date => $hours) {
        $type = $holidayTypeByDate[$date] ?? 'regular';
        // A holiday that's ALSO the employee's scheduled rest day (per
        // employee_schedules, independent of attendance_records.status --
        // detectBiometricAttendanceStatus() picks 'holiday' over 'rest_day'
        // when both apply, so status alone can't tell these apart) earns the
        // combined DOLE rate, not the plain holiday rate: 260%->338%
        // (regular) or 130%->195% (special) for OT hours worked on it.
        $onRestDay = isset($restDayDates[$date]);
        if ($type === 'regular') {
            $otMultiplier = $onRestDay ? (float)$settings['regular_holiday_restday_ot_multiplier'] : (float)$settings['regular_holiday_ot_multiplier'];
        } else {
            $otMultiplier = $onRestDay ? (float)$settings['special_holiday_restday_ot_multiplier'] : (float)$settings['special_holiday_ot_multiplier'];
        }
        $overtimePay += computeOvertimePay($hourlyEquivalent, $hours, $otMultiplier);
    }
    // Rounded once, after every OT bucket has been added -- the payslip shows a
    // single Overtime figure, so that total is what must be exact, not each
    // bucket separately (see the rounding policy note above).
    $overtimePay = round($overtimePay, 2);

    // -- Holiday pay: per worked-holiday date, using the type lookup above --
    // Same holiday+restday combined-rate override as the OT loop above:
    // 200%->260% (regular) or 130%->150% (special) for the first 8 hours.
    $holidayPay = 0.0;
    foreach ($holidayHoursByDate as $date => $hours) {
        $type = $holidayTypeByDate[$date] ?? 'regular';
        $onRestDay = isset($restDayDates[$date]);
        if ($type === 'regular') {
            $multiplier = $onRestDay ? (float)$settings['regular_holiday_restday_multiplier'] : (float)$settings['regular_holiday_multiplier'];
        } else {
            $multiplier = $onRestDay ? (float)$settings['special_holiday_restday_multiplier'] : (float)$settings['special_holiday_multiplier'];
        }
        $holidayPay += computeHolidayPay($hourlyEquivalent, $hours, $multiplier, $salaryType);
    }

    // -- Unworked regular-holiday pay: daily-paid employees only (a monthly
    // employee's flat salary already covers unworked days by construction,
    // same reasoning as leave pay above). Special/non-working holidays get
    // no pay here -- "no work, no pay" is the legal default for those.
    // Reuses computeHolidayPay() with multiplier=1.0 and hours=one standard
    // day, since there's no real attendance row to derive hours from --
    // that gives exactly one day's pay at straight rate for a daily-paid
    // employee (the salaryType==='daily' branch skips the monthly -1.0
    // adjustment). Gated on employeeQualifiesForUnworkedHolidayPay()'s DOLE
    // "present or on paid leave the day before" check.
    if ($salaryType === 'daily') {
        foreach ($holidayTypeByDate as $date => $type) {
            if ($type !== 'regular') {
                continue;
            }
            if (isset($holidayHoursByDate[$date])) {
                continue; // worked -- already paid above
            }
            if (isset($paidLeaveDates[$date])) {
                continue; // already paid via leavePay below (same 100% rate) -- don't double-pay the same date.
                // Deliberately NOT checking $leaveDatesAll here -- unpaid leave
                // contributes $0 via leavePay, so skipping an unpaid-leave date
                // too would leave a regular holiday entitled to pay entirely
                // uncompensated, just because an unrelated unpaid leave request
                // happened to span it. Only a PAID leave date is a genuine
                // double-pay risk.
            }
            if ($employee['date_hired'] > $date) {
                continue; // not employed yet
            }
            if (!employeeQualifiesForUnworkedHolidayPay($pdo, $employeeId, $date)) {
                continue;
            }
            $holidayPay += computeHolidayPay($hourlyEquivalent, (float)$settings['standard_work_hours_per_day'], 1.0, $salaryType);
        }
    }
    // Rounded after BOTH holiday loops (worked holidays above, plus the
    // unworked regular-holiday pay just added) -- one printed figure, one
    // rounding.
    $holidayPay = round($holidayPay, 2);

    // -- Rest day pay: per worked-rest-day date -----------------------------
    $restDayPay = 0.0;
    foreach ($restDayHoursByDate as $hours) {
        $restDayPay += computeRestDayPay($hourlyEquivalent, $hours, (float)$settings['rest_day_multiplier'], $salaryType);
    }
    $restDayPay = round($restDayPay, 2);

    // -- Night differential pay ------------------------------------------
    $nightDiffPay = round(computeNightDifferentialPay($hourlyEquivalent, $nightDiffHoursTotal, (float)$settings['night_differential_rate']), 2);

    // -- Leave pay: daily-paid only. A monthly employee's flat basic pay
    // already covers every calendar day of the period, approved-leave days
    // included (same reasoning as computeHolidayPay()/computeRestDayPay()
    // above) -- adding leave pay on top would double-pay those days. A
    // daily-paid employee only earns for days actually rendered, and a
    // leave day is not one, so this genuinely is their only pay for it.
    // One paid leave day = one Daily Rate, straight.
    $leavePay = round($salaryType === 'monthly'
        ? 0.0
        : $dailyRate * $paidLeaveDaysInPeriod, 2);

    // -- Pending adjustments for this employee, TARGETED AT THIS EXACT CUTOFF --
    //
    // Used to be every pending, unattached adjustment for the employee, full
    // stop -- whichever draft run for their pay_frequency got processed FIRST
    // swept all of them in, regardless of which period the manager actually
    // meant. Two adjustments meant for two different future cutoffs could
    // both land on the same next run, and there was no way to schedule one
    // ahead for a period that had not opened yet.
    //
    // target_period_start/end is set once when the adjustment is created
    // (adjustment_save.php) and never changed afterward -- same immutability
    // as payroll_runs.cutoff_period_start/end. An exact match is correct
    // rather than an overlap check: both sides are computed from the SAME
    // payroll_settings formula (month + half), so a genuinely-intended match
    // always lands on identical dates.
    $adjStmt = $pdo->prepare(
        "SELECT * FROM payroll_adjustments
          WHERE employee_id = ? AND payroll_run_id IS NULL AND status = 'pending'
            AND target_period_start = ? AND target_period_end = ?"
    );
    $adjStmt->execute([$employeeId, $cutoffStart, $cutoffEnd]);
    $adjustments = $adjStmt->fetchAll(PDO::FETCH_ASSOC);

    $earningLines = [];
    $adjustmentEarningsTotal = 0.0;
    $nonTaxableAdjustmentEarningsTotal = 0.0;
    $manualDeductionLines = [];
    $manualDeductionsTotal = 0.0;
    foreach ($adjustments as $adj) {
        if ($adj['adjustment_category'] === 'earning') {
            $earningLines[] = $adj;
            $adjustmentEarningsTotal += (float)$adj['amount'];
            if ((int)($adj['is_taxable'] ?? 1) === 0) {
                $nonTaxableAdjustmentEarningsTotal += (float)$adj['amount'];
            }
        } else {
            $manualDeductionLines[] = $adj;
            $manualDeductionsTotal += (float)$adj['amount'];
        }
    }

    // -- Gross pay ------------------------------------------------------------
    // Every argument here is already rounded to centavos (adjustment amounts
    // come straight out of a DECIMAL(12,2) column), so this total IS the sum of
    // the figures the payslip prints. The round() is float-representation
    // hygiene, not a second rounding -- summing exact centavo values in binary
    // floating point can still land a hair off (0.1 + 0.2 != 0.3).
    $adjustmentEarningsTotal = round($adjustmentEarningsTotal, 2);
    $nonTaxableAdjustmentEarningsTotal = round($nonTaxableAdjustmentEarningsTotal, 2);
    $manualDeductionsTotal = round($manualDeductionsTotal, 2);
    $grossPay = round(computeGrossPay($basicPay, $overtimePay, $holidayPay, $restDayPay, $nightDiffPay, $leavePay + $adjustmentEarningsTotal), 2);

    // -- Government contributions (skipped if exempt, globally disabled, or
    // this period's absence deduction fully absorbs gross pay) --------------
    $sss = ['employee' => 0.0, 'employer' => 0.0];
    $philhealth = ['employee' => 0.0, 'employer' => 0.0];
    $pagibig = ['employee' => 0.0, 'employer' => 0.0];
    $withholdingTax = 0.0;
    $govDeductionLines = [];

    // A period where the (now Basic-Pay-capped) absence deduction fully
    // absorbs gross pay leaves zero actual compensable earnings -- charging
    // government contributions against the nominal salary regardless would
    // deduct real money for a period the employee earned nothing, and would
    // independently drag net pay negative even though the absence deduction
    // itself no longer can. Skipped entirely for that period rather than
    // pro-rated, since there's no partial contribution basis to prorate
    // from -- confirmed with the user rather than assumed, since this
    // touches real compliance-adjacent computation.
    $compensableThisPeriod = $grossPay - $absenceDeduction;
    $govEnabled = (bool)$settings['enable_government_contributions']
        && $compensableThisPeriod > 0;
    if ($govEnabled) {
        $periodsPerMonth = $payFrequency === 'semi_monthly' ? 2 : 1;
        $isSecondSemiMonthlyCutoff = $periodsPerMonth > 1
            && (int)date('j', strtotime($cutoffStart)) >= (int)$settings['semi_monthly_cutoff2_start_day'];

        // Monthly Basic Salary (MBS) for the SSS/PhilHealth/Pag-IBIG bracket
        // lookup. A monthly-salaried employee's basic_rate already IS the
        // fixed monthly figure. A daily-rate employee has no single
        // "monthly salary" on file, so MBS uses the standard DOLE
        // nominal-monthly-equivalent formula: Daily Rate x Annual Working
        // Days Divisor / 12 -- the same relationship computeDailyRate()
        // already uses in reverse to turn a MONTHLY employee's salary into
        // a daily rate for hourly premiums, just solved for the other side.
        // Deliberately a STABLE figure keyed to the employee's rate, not
        // derived from actual attendance -- it no longer fluctuates cutoff
        // to cutoff or dips in a month with absences, matching how SSS/
        // PhilHealth/Pag-IBIG expect one consistent monthly salary credit
        // per employee as long as their rate hasn't changed. (Per user
        // direction 2026-09-06, replacing an earlier attempt at deriving
        // this from actual cutoff earnings -- see project memory.)
        $mbs = $salaryType === 'monthly' ? $basicRate : ($dailyRate * (float)$settings['annual_working_days_divisor'] / 12);
        $monthlyEquivalentComp = $mbs;

        // SSS now shares the exact same stable MBS base as PhilHealth/Pag-IBIG,
        // rather than the employee's actual gross pay for the month. Per RA
        // 11199 Sec. 8(f), SSS "compensation" is technically defined as ALL
        // ACTUAL remuneration for employment -- a stricter standard than
        // PhilHealth's/Pag-IBIG's, which is why this used to be the one
        // agency kept on real earnings (summed across both real semi-monthly
        // cutoffs) while the other two used the nominal MBS shortcut. Client
        // explicitly chose the simpler one-nominal-base-for-all-three
        // approach instead (2026-09-12) -- a real, common employer practice,
        // accepting that a month with unusually many absences or unusually
        // much overtime will report a Monthly Salary Credit to SSS that no
        // longer exactly tracks that month's real pay, in exchange for one
        // consistent, attendance-independent number across all three
        // agencies. This is a deliberate simplification, not a bug: MBS is
        // still tied to the employee's real rate and updates immediately if
        // that rate changes, it just no longer moves with attendance the way
        // PhilHealth/Pag-IBIG never did either.
        $monthlySssComp = $monthlyEquivalentComp;

        $sss = computeSssContribution($monthlySssComp, $govTables['sss']);
        $philhealth = computePhilhealthContribution($monthlyEquivalentComp, $govTables['philhealth']);
        $pagibig = computePagibigContribution($monthlyEquivalentComp, $govTables['pagibig']);

        // The bracket lookups above need each contribution's own full MONTHLY
        // compensation, but the amount each compute*Contribution() returns is
        // the full MONTHLY contribution -- only correct to charge in full on
        // one payslip a month. A semi-monthly employee gets two payslips a
        // month, so charging the full monthly amount on BOTH (as this used to
        // do) collects double the correct monthly total -- a real bug, not a
        // rounding nuance. PH payroll practice allows splitting evenly across
        // both cutoffs OR deducting the whole month's amount on a single
        // cutoff, as long as the monthly total is correct either way. This
        // restaurant's chosen convention: nothing on the 1st cutoff, the full
        // monthly amount on the 2nd -- so a slower first half never leaves an
        // employee owing more contribution than that half's pay can cover,
        // and the deduction is visibly one round monthly figure rather than
        // a split one nobody can mentally reconstruct.
        $deferGovContributions = $periodsPerMonth > 1 && !$isSecondSemiMonthlyCutoff;
        if ($deferGovContributions) {
            $sss = ['employee' => 0.0, 'employer' => 0.0];
            $philhealth = ['employee' => 0.0, 'employer' => 0.0];
            $pagibig = ['employee' => 0.0, 'employer' => 0.0];
        }

        // Withholding tax is levied on compensation ACTUALLY EARNED, minus the
        // mandatory contributions. The absence/late/undertime deductions are the
        // difference between the nominal salary and what was really earned,
        // so they belong on this side of the subtraction -- leaving them out
        // (as this used to) taxes a monthly employee on a full month's salary
        // no matter how much of that month they were absent for. That was not
        // merely an edge case: it over-withheld on EVERY payslip carrying any
        // absence or late arrival. In the zero-work month that surfaced it,
        // taxable income came out as ₱30,000 − ₱2,450
        // = ₱27,550 and produced ₱1,007.55 of tax against ₱0 of real earnings.
        // Manual deduction adjustments (cash advances, loans) are NOT
        // subtracted -- those are collections against pay already earned, not
        // reductions of the compensation itself, so they are not tax-deductible.
        $mandatoryContributions = $sss['employee'] + $philhealth['employee'] + $pagibig['employee'];

        if (!empty($employee['is_minimum_wage_earner'])) {
            // RA 9504 / RR 10-2008: a statutory Minimum Wage Earner pays no
            // income tax on the minimum wage itself, nor on holiday pay,
            // overtime pay, night shift differential or hazard pay. Those are
            // exactly the earnings lines this engine computes from attendance,
            // so the whole attendance-derived side of gross pay is exempt.
            //
            // Leave pay is treated as exempt with them: paid leave pays the
            // basic wage for a day not worked, so taxing it would tax the
            // minimum wage by another name.
            //
            // What remains taxable is other compensation -- allowances,
            // bonuses and similar booked as earning adjustments. An MWE does
            // not lose the exemption by receiving these; only the other income
            // itself is taxable, which is what subtracting the exempt lines
            // from gross pay leaves behind.
            $exemptEarnings = round($basicPay + $overtimePay + $holidayPay
                + $restDayPay + $nightDiffPay + $leavePay, 2);
            $taxableIncome = round(max(0.0, $grossPay - $exemptEarnings
                - $nonTaxableAdjustmentEarningsTotal - $mandatoryContributions), 2);
        } else {
            // Every term is a centavo-exact figure the payslip itself prints, so
            // this subtraction reproduces exactly what an employee gets adding
            // up their own payslip. It used to run on full-precision floats and
            // could land a centavo away from the printed columns.
            $taxableIncome = round(max(0.0, $grossPay
                - $nonTaxableAdjustmentEarningsTotal
                - $absenceDeduction - $lateDeduction - $undertimeDeduction
                - $mandatoryContributions), 2);
        }
        $withholdingTax = computeWithholdingTax($taxableIncome, $payFrequency, $govTables['wtax']);
    }

    // -- Net pay floor: nobody is ever paid a negative wage ------------------
    // Attendance-driven deductions (absence/late/undertime) can never overshoot
    // on their own -- absence is capped at Basic Pay and late/undertime are each
    // bounded by minutes actually scheduled. What CAN overshoot is
    // everything charged against the nominal salary rather than what was
    // earned: government contributions are bracketed on the full monthly
    // compensation, so an employee who worked 1 day of 26 still owes the
    // whole ₱2,450 of SSS/PhilHealth/Pag-IBIG out of ~₱1,246 of real pay.
    // Cash advances and loan amortisations can obviously exceed a thin
    // period too. Rather than emit a negative payslip, collections are
    // trimmed until net pay reaches exactly ₱0; whatever could not be
    // collected this period is simply not deducted (it is NOT carried
    // forward -- there is no balance-tracking table behind that, and
    // inventing one silently would misstate what the employee still owes).
    //
    // Trim order runs least-obligatory first, so the statutory lines are the
    // last thing to give way: manual adjustments (discretionary collections
    // against pay already earned) -> withholding tax -> Pag-IBIG ->
    // PhilHealth -> SSS. The trimmed figures are what get written to
    // payslip_deductions below, so the printed line items always re-add to
    // the stored total instead of quietly disagreeing with it.
    $collectible = $grossPay - $lateDeduction - $undertimeDeduction - $absenceDeduction;
    $shortfall = max(0.0, round(
        ($manualDeductionsTotal + $sss['employee'] + $philhealth['employee'] + $pagibig['employee'] + $withholdingTax)
        - $collectible,
        2
    ));
    $manualTrim = 0.0;
    if ($shortfall > 0) {
        foreach ([
            ['manual', $manualDeductionsTotal],
            ['wtax', $withholdingTax],
            ['pagibig', $pagibig['employee']],
            ['philhealth', $philhealth['employee']],
            ['sss', $sss['employee']],
        ] as [$bucket, $amount]) {
            if ($shortfall <= 0) {
                break;
            }
            $take = min($amount, $shortfall);
            $shortfall = round($shortfall - $take, 2);
            switch ($bucket) {
                case 'manual':     $manualTrim = $take; $manualDeductionsTotal = round($amount - $take, 2); break;
                case 'wtax':       $withholdingTax = round($amount - $take, 2); break;
                case 'pagibig':    $pagibig['employee'] = round($amount - $take, 2); break;
                case 'philhealth': $philhealth['employee'] = round($amount - $take, 2); break;
                case 'sss':        $sss['employee'] = round($amount - $take, 2); break;
            }
        }
    }

    // Employer shares are NOT trimmed alongside the employee ones: they are
    // the employer's own remittance obligation, funded by the employer, and
    // are unaffected by whether the employee's pay could absorb the employee
    // share.
    //
    // These three lines carry NO description. They used to print the monthly
    // figure they were bracketed against ("Monthly Basic Salary ₱19,562.50"),
    // which was removed on request: the payslip now shows the deduction name
    // and its amount, nothing else. Purely a display change -- the basis
    // itself ($monthlySssComp / $monthlyEquivalentComp, computed above) is
    // unchanged and is still exactly what computeSssContribution() /
    // computePhilhealthContribution() / computePagibigContribution() were
    // looked up against, so every amount on the payslip is identical to what
    // it was before. What is no longer printed is only the explanation of
    // where that amount came from.
    //
    // The 1st cutoff still writes an explicit $0.00 row for each of the three
    // (rather than no row at all), unchanged: getPayslipWithDetails() filters
    // `employee_amount > 0`, so those rows never reach a payslip anyway, but
    // they keep the deferral auditable by direct query.
    //
    // $deferGovContributions / $periodsPerMonth only exist when $govEnabled --
    // they're set inside that block above (government contributions are
    // globally disabled, or this period had zero compensable pay). Guarded
    // here too, not just by $share['employee']/['employer'] being 0, because
    // reading those variables at all when they don't exist throws "Undefined
    // variable" warnings on every such payslip -- a real bug (confirmed
    // empirically), not just a defensive guard.
    if ($govEnabled) {
        foreach (['SSS' => $sss, 'PHILHEALTH' => $philhealth, 'PAGIBIG' => $pagibig] as $code => $share) {
            if ($share['employee'] > 0 || $share['employer'] > 0 || $deferGovContributions) {
                $govDeductionLines[] = [
                    'deduction_type_id' => $deductionTypesByCode[$code]['deduction_type_id'] ?? null,
                    'description' => null,
                    'employee_amount' => $share['employee'],
                    'employer_amount' => $share['employer'],
                    'adjustment_id' => null,
                ];
            }
        }
    }
    if ($withholdingTax > 0) {
        // This line carries no description, matching the SSS/PhilHealth/Pag-IBIG
        // lines above (see the "payslip basis note was REMOVED" entry in
        // project memory) -- removed on request so the payslip shows only the
        // deduction name and its amount, nothing else. $taxableIncome is still
        // exactly what computeWithholdingTax() was looked up against; only the
        // printed explanation of that figure is gone.
        $govDeductionLines[] = [
            'deduction_type_id' => $deductionTypesByCode['WTAX']['deduction_type_id'] ?? null,
            'description' => null,
            'employee_amount' => $withholdingTax,
            'employer_amount' => 0.0,
            'adjustment_id' => null,
        ];
    }

    // -- Manual deduction adjustment lines (cash advance, loans, other) ------
    // $manualTrim is applied from the LAST adjustment backwards so earlier
    // (older) collections are honoured in full and only the most recent one
    // absorbs the shortfall, rather than every line being shaved a little.
    $adjustmentDeductionLines = [];
    foreach ($manualDeductionLines as $adj) {
        $typeInfo = $deductionTypesByCode[$adj['adjustment_type']] ?? null;
        $adjustmentDeductionLines[] = [
            'deduction_type_id' => $typeInfo['deduction_type_id'] ?? null,
            'description' => $adj['description'],
            'employee_amount' => (float)$adj['amount'],
            'employer_amount' => 0.0,
            'adjustment_id' => (int)$adj['adjustment_id'],
        ];
    }
    for ($i = count($adjustmentDeductionLines) - 1; $i >= 0 && $manualTrim > 0; $i--) {
        $take = min($adjustmentDeductionLines[$i]['employee_amount'], $manualTrim);
        $adjustmentDeductionLines[$i]['employee_amount'] = round($adjustmentDeductionLines[$i]['employee_amount'] - $take, 2);
        $manualTrim = round($manualTrim - $take, 2);
    }
    $adjustmentDeductionLines = array_values(array_filter(
        $adjustmentDeductionLines,
        fn($line) => $line['employee_amount'] > 0
    ));

    // -- Totals -----------------------------------------------------------
    // Every input is centavo-exact by this point: the attendance deductions
    // were rounded where they were computed, the statutory shares come from the
    // bracket tables as-is, the trim block above rounds whatever it touches,
    // and withholding tax was rounded with the taxable income it came from. So
    // this total equals the sum of the deduction lines the payslip prints, and
    // net pay equals printed gross minus printed deductions -- which is what an
    // employee checking their own payslip with a calculator will get.
    $totalDeductions = round(computeTotalDeductions(
        $lateDeduction,
        $undertimeDeduction,
        $absenceDeduction,
        0.0, // cash advance/loans are already folded into $manualDeductionsTotal below
        $manualDeductionsTotal,
        $sss['employee'],
        $philhealth['employee'],
        $pagibig['employee'],
        $withholdingTax,
        0.0
    ), 2);
    $netPay = round(computeNetPay($grossPay, $totalDeductions), 2);

    return [
        'days_worked' => $daysWorked,
        'hours_worked' => round($totalHoursWorked, 2),
        'basic_pay' => round($basicPay, 2),
        'overtime_pay' => round($overtimePay, 2),
        'holiday_pay' => round($holidayPay, 2),
        'rest_day_pay' => round($restDayPay, 2),
        'night_differential_pay' => round($nightDiffPay, 2),
        'leave_pay' => round($leavePay, 2),
        'paid_leave_days' => round($paidLeaveDaysInPeriod, 2),
        'late_deduction' => round($lateDeduction, 2),
        'undertime_deduction' => round($undertimeDeduction, 2),
        'absence_deduction' => round($absenceDeduction, 2),
        'gross_pay' => round($grossPay, 2),
        'total_deductions' => round($totalDeductions, 2),
        'net_pay' => round($netPay, 2),
        'earning_lines' => array_map(fn($adj) => [
            'earning_type' => $adj['adjustment_type'],
            'description' => $adj['description'],
            'amount' => (float)$adj['amount'],
            'adjustment_id' => (int)$adj['adjustment_id'],
        ], $earningLines),
        'deduction_lines' => array_merge($govDeductionLines, $adjustmentDeductionLines),
        'consumed_adjustment_ids' => array_map(fn($adj) => (int)$adj['adjustment_id'], $adjustments),
        'days_absent' => $daysAbsent,
    ];
}
