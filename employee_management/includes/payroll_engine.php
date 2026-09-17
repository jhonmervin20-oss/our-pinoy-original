<?php
/**
 * employee_management/includes/payroll_engine.php
 *
 * The Philippine payroll computation engine. Every function here is pure
 * (numbers/arrays in, numbers/arrays out) so the rules can be verified
 * standalone against known worked examples without touching the database
 * — see employee_management/includes/payroll_engine_test.php. The DB-backed caller
 * that assembles these inputs from attendance/leave/adjustments records
 * (runPayrollForEmployee()) is a separate concern, added once those pages
 * exist to feed it.
 */

/**
 * The Daily Rate every premium in this engine is a multiple of.
 *
 *   daily   : basic_rate IS the daily rate — returned as-is.
 *   monthly : (Monthly Salary × 12) ÷ Annual Working Days Divisor.
 *
 * This matters because DOLE expresses every premium (holiday 200%, rest
 * day 130%, OT +25%, night diff +10%) as a multiple of the DAILY WAGE, not
 * of an hourly one. Daily-paid staff are the case those rules were written
 * for, so their rate needs no conversion at all.
 */
function computeDailyRate(float $basicRate, string $salaryType, float $annualWorkingDaysDivisor): float
{
    if ($salaryType === 'daily') {
        return $basicRate;
    }
    if ($salaryType !== 'monthly' || $annualWorkingDaysDivisor <= 0) {
        return 0.0;
    }
    return ($basicRate * 12) / $annualWorkingDaysDivisor;
}

/**
 * Hourly figure that late/OT/holiday/night-differential key off.
 * Uniform for both salary types now: Daily Rate ÷ standard hours/day —
 * DOLE's own basis for hourly premiums (Handbook on Workers' Statutory
 * Monetary Benefits: "Hourly rate = Daily rate ÷ 8").
 */
function computeHourlyEquivalent(float $dailyRate, float $standardHoursPerDay): float
{
    return $standardHoursPerDay > 0 ? $dailyRate / $standardHoursPerDay : 0.0;
}

/** Daily Rate -> Hourly Rate -> Minute Rate. Not used by the late deduction (which divides by 60 inline); kept as a standalone conversion step. */
function computeMinuteRate(float $hourlyEquivalent): float
{
    return $hourlyEquivalent / 60;
}

/**
 * The salary_type × pay_frequency combinations:
 *   monthly + semi_monthly -> Monthly Salary ÷ 2
 *   monthly + monthly      -> Monthly Salary
 *   daily   + either        -> Regular Days Worked × Daily Rate
 *
 * "No work, no pay" is the defining rule for daily-paid workers under
 * Philippine practice: they earn for days actually rendered, and unworked
 * days simply produce no pay rather than a deduction (which is why
 * computeAbsenceDeduction() stays monthly-only).
 *
 * $regularDaysWorked counts ORDINARY working days only. Days worked on a
 * holiday or a rest day are deliberately excluded by the caller and paid
 * through computeHolidayPay()/computeRestDayPay() at their full DOLE
 * multiplier instead — counting them here as well would pay those days
 * twice. Every present/late day counts as a FULL 1.0, even one cut short --
 * partial attendance (arriving late or leaving early) is handled entirely
 * by the separate late/undertime deductions, not by shaving this figure, so
 * an employee is never docked twice for the same shortfall.
 */
function computeBasicOrRegularPay(string $salaryType, string $payFrequency, float $basicRate, float $regularDaysWorked): float
{
    if ($salaryType === 'daily') {
        return $regularDaysWorked * $basicRate;
    }
    return $payFrequency === 'semi_monthly' ? $basicRate / 2 : $basicRate;
}

function computeLateDeduction(float $hourlyEquivalent, float $lateMinutes): float
{
    return ($lateMinutes / 60) * $hourlyEquivalent;
}

/** Mirrors computeLateDeduction(), on the departure side -- clocking out early past the grace period. */
function computeUndertimeDeduction(float $hourlyEquivalent, float $undertimeMinutes): float
{
    return ($undertimeMinutes / 60) * $hourlyEquivalent;
}

/**
 * Monthly employees only — Daily Rate × Days Absent, capped at Basic Pay.
 * Daily-paid employees never get this deduction line: an unworked day is
 * simply never counted in computeBasicOrRegularPay()'s regularDaysWorked
 * input ("no work, no pay"), so charging it here too would deduct the same
 * absence twice.
 *
 * The cap exists because Daily Rate is derived from an annual working-days
 * divisor (payroll_settings.annual_working_days_divisor, e.g. 261 ≈ 21.75
 * working days/month) — a figure meant to represent *payable* days, not
 * literal calendar days. Days Absent, however, can legitimately reach the
 * full calendar-day count of a cutoff period (e.g. an employee with no
 * schedule set up at all has no rest days to exclude, so every day in the
 * period counts). Multiplying a working-days-calibrated rate by a
 * calendar-days count can therefore exceed a full month's Basic Pay —
 * mathematically correct arithmetic, but not a real payroll outcome: an
 * employee can never legitimately owe back more than they were ever going
 * to be paid for the period. Basic Pay is the hard ceiling.
 *
 * $ordinaryWorkingDaysInPeriod is the FLOOR side of that same divisor
 * mismatch, and it matters just as much. The divisor describes an AVERAGE
 * month (313 ÷ 12 = 26.083 payable days), but a real cutoff contains a
 * whole number of working days that rarely equals it — August 2026 has
 * exactly 26 (31 calendar days − 5 Sundays). An employee absent every one
 * of those 26 days was therefore deducted 26 × ₱1,150.16 = ₱29,904.15
 * against a flat ₱30,000 Basic Pay, leaving a phantom ₱95.85 of "earnings"
 * for a month in which nothing was earned. That residual is not harmless:
 * it slips past runPayrollForEmployee()'s `compensable > 0` guard, which
 * then charges SSS/PhilHealth/Pag-IBIG/withholding tax bracketed on the
 * full nominal salary — and drove net pay NEGATIVE (−₱3,361.70) on a
 * zero-work month. So when every ordinary working day in the period is
 * absent, the deduction is Basic Pay exactly, not the rate-times-days
 * approximation of it. Partial absences keep using the statutory Daily
 * Rate, which stays stable month to month as DOLE intends; only the
 * all-absent case is snapped, since that is the one where the correct
 * answer is known exactly and independently of any divisor.
 */
function computeAbsenceDeduction(float $dailyRate, float $daysAbsent, string $salaryType, float $basicPay, float $ordinaryWorkingDaysInPeriod = 0.0): float
{
    if ($salaryType !== 'monthly') {
        return 0.0;
    }
    if ($ordinaryWorkingDaysInPeriod > 0 && $daysAbsent >= $ordinaryWorkingDaysInPeriod) {
        return $basicPay;
    }
    return min($dailyRate * $daysAbsent, $basicPay);
}

function computeOvertimePay(float $hourlyEquivalent, float $otHours, float $otMultiplier): float
{
    return $hourlyEquivalent * $otHours * $otMultiplier;
}

/**
 * $holidayMultiplier is whichever of payroll_settings' regular/special
 * holiday multiplier columns applies to the date being paid (e.g. 2.00 =
 * "200% of the daily rate, total, for that day"). For a DAILY-PAID
 * employee that full multiplier IS the day's entire pay, since their
 * basic/regular pay only covers ordinary days and the caller must exclude
 * holiday-worked days/hours from those totals (see runPayrollForEmployee())
 * to avoid paying the same day twice. For a MONTHLY employee, the "first 100%" is
 * already included in their flat salary regardless of whether the day
 * was worked (the "no work, no pay" exception for regular holidays
 * doesn't apply to monthly-paid staff) — so only the multiplier's
 * INCREMENTAL amount above 100% is added here, or a monthly employee
 * who works a regular holiday would be paid 300% instead of the correct
 * 200% (100% baked into salary + a spurious extra 200% on top).
 */
function computeHolidayPay(float $hourlyEquivalent, float $hoursOnHoliday, float $holidayMultiplier, string $salaryType): float
{
    $effectiveMultiplier = $salaryType === 'monthly' ? max(0.0, $holidayMultiplier - 1.0) : $holidayMultiplier;
    return $hourlyEquivalent * $hoursOnHoliday * $effectiveMultiplier;
}

/**
 * Same shape and same monthly-vs-daily baseline reasoning as
 * computeHolidayPay() (see its docblock), applied to payroll_settings'
 * rest_day_multiplier instead of the holiday multipliers.
 */
function computeRestDayPay(float $hourlyEquivalent, float $hoursOnRestDay, float $restDayMultiplier, string $salaryType): float
{
    $effectiveMultiplier = $salaryType === 'monthly' ? max(0.0, $restDayMultiplier - 1.0) : $restDayMultiplier;
    return $hourlyEquivalent * $hoursOnRestDay * $effectiveMultiplier;
}

/** $nightDiffRate is payroll_settings.night_differential_rate, a fraction (0.10 = 10%), not a percent integer. */
function computeNightDifferentialPay(float $hourlyEquivalent, float $nightDiffHours, float $nightDiffRate): float
{
    return $hourlyEquivalent * $nightDiffHours * $nightDiffRate;
}

function computeGrossPay(float $basicOrRegularPay, float $overtimePay, float $holidayPay, float $restDayPay, float $nightDiffPay, float $earningsAdjustmentsTotal): float
{
    return $basicOrRegularPay + $overtimePay + $holidayPay + $restDayPay + $nightDiffPay + $earningsAdjustmentsTotal;
}

/**
 * Shared range-bracket lookup: the first row where $value falls within
 * [$fromColumn, $toColumn] (a null $toColumn means "and up" — the open-
 * ended top bracket). Reused for SSS/PhilHealth/Pag-IBIG/withholding tax
 * since all 4 government tables are structured as from/to range tables,
 * just with different column names.
 */
function lookupBracket(array $table, string $fromColumn, string $toColumn, float $value): ?array
{
    foreach ($table as $row) {
        $from = (float)$row[$fromColumn];
        $to   = $row[$toColumn] !== null ? (float)$row[$toColumn] : null;
        if ($value >= $from && ($to === null || $value <= $to)) {
            return $row;
        }
    }
    return null;
}

/** SSS contribution is a flat employee/employer peso amount per Monthly Salary Credit bracket — no percentage math involved. */
function computeSssContribution(float $monthlySalaryCredit, array $sssTable): array
{
    $bracket = lookupBracket($sssTable, 'compensation_from', 'compensation_to', $monthlySalaryCredit);
    if (!$bracket) {
        return ['employee' => 0.0, 'employer' => 0.0];
    }
    return [
        'employee' => (float)$bracket['employee_share'],
        'employer' => (float)$bracket['employer_share'],
    ];
}

/**
 * PhilHealth: basic pay clamped to [salary_floor, salary_ceiling], then split by the bracket's
 * employee/employer percentages. Unlike SSS/Pag-IBIG's tiered, fully-covered (0 to unbounded)
 * tables, PhilHealth's real schedule (per RA 11223's UHC Act) is a single floor-and-ceiling
 * range: income BELOW the floor is still charged as if it were the floor, and income ABOVE the
 * ceiling is capped at the ceiling -- falling outside the configured range is the NORMAL case
 * this function has to handle, not a missing-bracket error. lookupBracket()'s strict
 * value-must-be-inside-the-range match returns null for exactly those two cases, so it's
 * bypassed here in favor of explicitly floor/ceiling-clamping against the table's own extremes.
 */
function computePhilhealthContribution(float $basicPay, array $philhealthTable): array
{
    if (empty($philhealthTable)) {
        return ['employee' => 0.0, 'employer' => 0.0];
    }
    $bracket = lookupBracket($philhealthTable, 'salary_floor', 'salary_ceiling', $basicPay);
    if (!$bracket) {
        $sorted = $philhealthTable;
        usort($sorted, fn($a, $b) => (float)$a['salary_floor'] <=> (float)$b['salary_floor']);
        $bracket = $basicPay < (float)$sorted[0]['salary_floor']
            ? $sorted[0]
            : $sorted[count($sorted) - 1];
    }
    $base = min(max($basicPay, (float)$bracket['salary_floor']), (float)$bracket['salary_ceiling']);
    return [
        'employee' => round($base * ((float)$bracket['employee_share_percent'] / 100), 2),
        'employer' => round($base * ((float)$bracket['employer_share_percent'] / 100), 2),
    ];
}

/** Pag-IBIG: basic pay capped at the bracket's max_fund_salary, then split by the bracket's employee/employer percentages. */
function computePagibigContribution(float $basicPay, array $pagibigTable): array
{
    $bracket = lookupBracket($pagibigTable, 'compensation_from', 'compensation_to', $basicPay);
    if (!$bracket) {
        return ['employee' => 0.0, 'employer' => 0.0];
    }
    $base = min($basicPay, (float)$bracket['max_fund_salary']);
    return [
        'employee' => round($base * ((float)$bracket['employee_rate_percent'] / 100), 2),
        'employer' => round($base * ((float)$bracket['employer_rate_percent'] / 100), 2),
    ];
}

/** BIR withholding tax = Base Tax + Tax Rate% × (Taxable Income − Excess Over), bracket chosen by pay frequency + taxable income. */
function computeWithholdingTax(float $taxableIncome, string $payFrequency, array $wtaxTable): float
{
    $filtered = array_values(array_filter($wtaxTable, fn($row) => $row['pay_frequency'] === $payFrequency));
    $bracket  = lookupBracket($filtered, 'taxable_income_from', 'taxable_income_to', $taxableIncome);
    if (!$bracket) {
        return 0.0;
    }
    $excessOver = (float)$bracket['excess_over'];
    $baseTax    = (float)$bracket['base_tax'];
    $rate       = (float)$bracket['tax_rate_percent'];
    return round($baseTax + ($rate / 100) * max(0.0, $taxableIncome - $excessOver), 2);
}

function computeTotalDeductions(
    float $late,
    float $undertime,
    float $absence,
    float $cashAdvance,
    float $loans,
    float $sssEmployee,
    float $philhealthEmployee,
    float $pagibigEmployee,
    float $withholdingTax,
    float $otherDeductionsTotal
): float {
    return $late + $undertime + $absence + $cashAdvance + $loans
        + $sssEmployee + $philhealthEmployee + $pagibigEmployee + $withholdingTax + $otherDeductionsTotal;
}

function computeNetPay(float $grossPay, float $totalDeductions): float
{
    return $grossPay - $totalDeductions;
}
