<?php
/**
 * employee_management/includes/payroll_engine_test.php
 *
 * Standalone correctness check for payroll_engine.php, run from the CLI:
 *   php employee_management/includes/payroll_engine_test.php
 *
 * Exercises all 4 salary_type × pay_frequency combinations against the
 * exact worked examples in the original spec, one late/OT/holiday/night-diff
 * case each, and the 4 government contribution lookups
 * against this database's real seeded brackets. Exits non-zero if any
 * assertion fails, so it can be re-run as a regression check whenever the
 * engine changes.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/payroll_engine.php';

// CLI-only, like cron/refresh_forecast_backtest.php's guard -- this file has
// no Session/role check (it's a standalone regression script, not a page),
// so without this it was directly requestable over HTTP by anyone, running
// real payroll-engine assertions against this database's real seeded
// statutory brackets and echoing the results to an unauthenticated visitor.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
$passes   = 0;

function assertClose(string $label, float $expected, float $actual, float $tolerance = 0.01): void
{
    global $failures, $passes;
    $diff = abs($expected - $actual);
    if ($diff <= $tolerance) {
        echo "  PASS  $label = $actual\n";
        $passes++;
    } else {
        echo "  FAIL  $label: expected $expected, got $actual (diff $diff)\n";
        $failures++;
    }
}

// -- Settings used throughout (matches this DB's seeded payroll_settings defaults) --
$standardHoursPerDay      = 8.0;
$annualWorkingDaysDivisor = 261.0;
$overtimeMultiplier       = 1.25;
$regularHolidayMultiplier = 2.00;
$regularHolidayRestdayMultiplier   = 2.60;
$regularHolidayRestdayOtMultiplier = 3.38;
$specialHolidayRestdayMultiplier   = 1.50;
$specialHolidayRestdayOtMultiplier = 1.95;
$restDayMultiplier        = 1.30;
$nightDifferentialRate    = 0.100;

echo "=== 1. Basic/Regular Pay — the 4 salary_type x pay_frequency combinations ===\n";
assertClose(
    'Monthly ₱30,000 + Semi-monthly -> basic pay',
    15000.00,
    computeBasicOrRegularPay('monthly', 'semi_monthly', 30000.00, 0.0)
);
assertClose(
    'Monthly ₱30,000 + Monthly -> basic pay',
    30000.00,
    computeBasicOrRegularPay('monthly', 'monthly', 30000.00, 0.0)
);
assertClose(
    'Daily ₱800/day x 12 ordinary days (semi-monthly cutoff) -> regular pay',
    9600.00,
    computeBasicOrRegularPay('daily', 'semi_monthly', 800.00, 12)
);
assertClose(
    'Daily ₱800/day x 24 ordinary days (full month) -> regular pay',
    19200.00,
    computeBasicOrRegularPay('daily', 'monthly', 800.00, 24)
);
assertClose(
    'Daily half-day counts as 0.5 of a day',
    400.00,
    computeBasicOrRegularPay('daily', 'monthly', 800.00, 0.5)
);
assertClose(
    'Daily with zero days rendered -> zero pay (no work, no pay)',
    0.00,
    computeBasicOrRegularPay('daily', 'monthly', 800.00, 0)
);

echo "\n=== 2. Late / OT / Holiday / Night Diff — Monthly employee (₱30,000) ===\n";
$monthlyDailyRate  = computeDailyRate(30000.00, 'monthly', $annualWorkingDaysDivisor);
$monthlyHourlyEq   = computeHourlyEquivalent($monthlyDailyRate, $standardHoursPerDay);
$monthlyMinuteRate = computeMinuteRate($monthlyHourlyEq);

assertClose('Monthly daily rate = (30000 x 12) / 261', (30000 * 12) / 261, $monthlyDailyRate);
assertClose('Monthly hourly equivalent = daily rate / 8', $monthlyDailyRate / 8, $monthlyHourlyEq);
assertClose('Monthly minute rate = hourly equivalent / 60', $monthlyHourlyEq / 60, $monthlyMinuteRate);
assertClose('Monthly late deduction, 10 minutes', $monthlyMinuteRate * 10, computeLateDeduction($monthlyHourlyEq, 10));
assertClose('Monthly undertime deduction, 15 minutes -- same per-minute rate as late, mirrored on the departure side', $monthlyMinuteRate * 15, computeUndertimeDeduction($monthlyHourlyEq, 15));
assertClose('Monthly absence deduction, 1 day', $monthlyDailyRate * 1, computeAbsenceDeduction($monthlyDailyRate, 1, 'monthly', 30000.00));
assertClose('Monthly absence deduction is capped at Basic Pay, not raw Daily Rate x Days -- a no-schedule employee absent all 31 calendar days of a 261-divisor month would otherwise overshoot', 30000.00, computeAbsenceDeduction($monthlyDailyRate, 31, 'monthly', 30000.00));

// -- The FLOOR side of the same divisor mismatch (the ceiling is covered above).
// Real case that surfaced it: a 313-day divisor (6-day week) makes the Daily
// Rate ₱1,150.1597, calibrated to an average 26.083-day month -- but August
// 2026 has exactly 26 working days (31 calendar - 5 Sundays). Absent all 26,
// the raw product lands ₱95.85 SHORT of Basic Pay, and that phantom residual
// slipped past the caller's "compensable > 0" guard, charged a full month of
// SSS/PhilHealth/Pag-IBIG/tax against nominal salary, and produced a NEGATIVE
// payslip (-₱3,361.70) for a month with zero days worked.
$divisor313DailyRate = (30000 * 12) / 313;
assertClose('Divisor-313 daily rate leaves a residual against a real 26-working-day month (the bug being fixed)', 29904.15, $divisor313DailyRate * 26);
assertClose(
    'Monthly absence deduction snaps to Basic Pay EXACTLY when every ordinary working day in the period is absent -- no phantom residual left to charge contributions against',
    30000.00,
    computeAbsenceDeduction($divisor313DailyRate, 26, 'monthly', 30000.00, 26)
);
assertClose(
    'Snap also applies when days absent EXCEEDS the period working days (no-schedule employee: every calendar day counts)',
    30000.00,
    computeAbsenceDeduction($divisor313DailyRate, 31, 'monthly', 30000.00, 26)
);
assertClose(
    'Partial absence is NOT snapped -- it keeps the stable statutory Daily Rate so an absent day costs the same in every month',
    $divisor313DailyRate * 5,
    computeAbsenceDeduction($divisor313DailyRate, 5, 'monthly', 30000.00, 26)
);
assertClose(
    'Paid leave keeps an employee off the snap: 21 absences + 5 paid leave days in a 26-day period is 21 < 26, so basic pay still covers the leave',
    $divisor313DailyRate * 21,
    computeAbsenceDeduction($divisor313DailyRate, 21, 'monthly', 30000.00, 26)
);
assertClose('Daily-paid employees are never snapped either -- still always 0', 0.0, computeAbsenceDeduction($divisor313DailyRate, 26, 'daily', 30000.00, 26));
assertClose('Monthly OT pay, 2 hours @ 1.25x', $monthlyHourlyEq * 2 * $overtimeMultiplier, computeOvertimePay($monthlyHourlyEq, 2, $overtimeMultiplier));
assertClose(
    'Monthly holiday pay, 8 hours worked regular holiday: only the incremental (2.00 - 1.00)x, since the base 100% is already in the flat salary',
    $monthlyHourlyEq * 8 * ($regularHolidayMultiplier - 1.0),
    computeHolidayPay($monthlyHourlyEq, 8, $regularHolidayMultiplier, 'monthly')
);
assertClose(
    'Monthly holiday pay, 8 hours worked regular holiday that ALSO falls on the scheduled rest day: incremental (2.60 - 1.00)x, using the combined DOLE rate instead of the plain holiday rate',
    $monthlyHourlyEq * 8 * ($regularHolidayRestdayMultiplier - 1.0),
    computeHolidayPay($monthlyHourlyEq, 8, $regularHolidayRestdayMultiplier, 'monthly')
);
assertClose(
    'Monthly rest day pay, 6 hours worked rest day: only the incremental (1.30 - 1.00)x',
    $monthlyHourlyEq * 6 * ($restDayMultiplier - 1.0),
    computeRestDayPay($monthlyHourlyEq, 6, $restDayMultiplier, 'monthly')
);
assertClose('Monthly night differential pay, 4 hours @ 10%', $monthlyHourlyEq * 4 * $nightDifferentialRate, computeNightDifferentialPay($monthlyHourlyEq, 4, $nightDifferentialRate));

echo "\n=== 3. Late / OT / Holiday / Night Diff / Absence — Daily-paid employee (₱800/day) ===\n";
$dailyDailyRate  = computeDailyRate(800.00, 'daily', $annualWorkingDaysDivisor);
$dailyHourlyEq   = computeHourlyEquivalent($dailyDailyRate, $standardHoursPerDay);
$dailyMinuteRate = computeMinuteRate($dailyHourlyEq);

assertClose('Daily rate is the basic_rate itself (no conversion)', 800.00, $dailyDailyRate);
assertClose('Daily hourly equivalent = daily rate / 8 (DOLE basis)', 100.00, $dailyHourlyEq);
assertClose('Daily late deduction, 10 minutes', $dailyMinuteRate * 10, computeLateDeduction($dailyHourlyEq, 10));
assertClose('Daily undertime deduction, 240 minutes -- a shift cut short by 4 hours (what used to be a manual "half day") is just a bigger undertime figure, same formula', $dailyMinuteRate * 240, computeUndertimeDeduction($dailyHourlyEq, 240));
assertClose('Daily OT pay, 2 hours @ 1.25x', 100.00 * 2 * $overtimeMultiplier, computeOvertimePay($dailyHourlyEq, 2, $overtimeMultiplier));
assertClose(
    'Daily holiday pay, 8 hours worked regular holiday: full 2.00x, since daily-paid has no baseline salary already covering that day (caller must exclude these hours from regular pay -- see runPayrollForEmployee())',
    100.00 * 8 * $regularHolidayMultiplier,
    computeHolidayPay($dailyHourlyEq, 8, $regularHolidayMultiplier, 'daily')
);
assertClose(
    'Daily UNWORKED regular holiday pay: multiplier=1.0 x one standard day (8h), a straight day-rate stand-in reused from the worked-holiday function since there is no real attendance row to derive hours from -- see runPayrollForEmployee()\'s employeeQualifiesForUnworkedHolidayPay() gate',
    100.00 * 8 * 1.0,
    computeHolidayPay($dailyHourlyEq, $standardHoursPerDay, 1.0, 'daily')
);
assertClose(
    'Daily holiday pay, 8 hours worked regular holiday that ALSO falls on the scheduled rest day: full 2.60x (DOLE combined rate), not the plain 2.00x holiday rate',
    100.00 * 8 * $regularHolidayRestdayMultiplier,
    computeHolidayPay($dailyHourlyEq, 8, $regularHolidayRestdayMultiplier, 'daily')
);
assertClose(
    'Daily OT pay, 2 hours worked beyond 8 on a regular holiday that ALSO falls on the scheduled rest day: full 3.38x',
    100.00 * 2 * $regularHolidayRestdayOtMultiplier,
    computeOvertimePay($dailyHourlyEq, 2, $regularHolidayRestdayOtMultiplier)
);
assertClose(
    'Daily holiday pay, 8 hours worked special (non-working) day that ALSO falls on the scheduled rest day: full 1.50x, not the plain 1.30x special-day rate',
    100.00 * 8 * $specialHolidayRestdayMultiplier,
    computeHolidayPay($dailyHourlyEq, 8, $specialHolidayRestdayMultiplier, 'daily')
);
assertClose(
    'Daily OT pay, 2 hours worked beyond 8 on a special day that ALSO falls on the scheduled rest day: full 1.95x',
    100.00 * 2 * $specialHolidayRestdayOtMultiplier,
    computeOvertimePay($dailyHourlyEq, 2, $specialHolidayRestdayOtMultiplier)
);
assertClose(
    'Daily rest day pay, 6 hours worked rest day: full 1.30x',
    100.00 * 6 * $restDayMultiplier,
    computeRestDayPay($dailyHourlyEq, 6, $restDayMultiplier, 'daily')
);
assertClose('Daily night differential pay, 4 hours @ 10%', 100.00 * 4 * $nightDifferentialRate, computeNightDifferentialPay($dailyHourlyEq, 4, $nightDifferentialRate));
assertClose('Daily absence deduction is always 0 (unworked days already excluded from regular pay)', 0.0, computeAbsenceDeduction($dailyDailyRate, 1, 'daily', 800.00));

echo "\n=== 4. Gross pay assembly ===\n";
$grossPay = computeGrossPay(15000.00, 431.03, 1379.31, 195.00, 68.97, 500.00);
assertClose('Gross pay = basic + OT + holiday + rest day + night diff + adjustments', 15000.00 + 431.03 + 1379.31 + 195.00 + 68.97 + 500.00, $grossPay);

echo "\n=== 5. Government contribution lookups (against this DB's real seeded brackets) ===\n";
$pdo = Database::getInstance()->getConnection();
$sssTable        = $pdo->query("SELECT * FROM sss_contribution_table")->fetchAll(PDO::FETCH_ASSOC);
$philhealthTable = $pdo->query("SELECT * FROM philhealth_contribution_table")->fetchAll(PDO::FETCH_ASSOC);
$pagibigTable    = $pdo->query("SELECT * FROM pagibig_contribution_table")->fetchAll(PDO::FETCH_ASSOC);
$wtaxTable       = $pdo->query("SELECT * FROM bir_withholding_tax_table")->fetchAll(PDO::FETCH_ASSOC);

$sss = computeSssContribution(30000.00, $sssTable);
assertClose('SSS employee share for MSC 30,000', 1500.00, $sss['employee']);
assertClose('SSS employer share for MSC 30,000', 3030.00, $sss['employer']);

$philhealth = computePhilhealthContribution(30000.00, $philhealthTable);
assertClose('PhilHealth employee share for basic pay 30,000 (2.5%)', 750.00, $philhealth['employee']);
assertClose('PhilHealth employer share for basic pay 30,000 (2.5%)', 750.00, $philhealth['employer']);

// Below the salary floor -- PhilHealth's real rule is to charge AS IF the salary were the floor,
// not to charge nothing. This is the bug being fixed: lookupBracket()'s strict range match used
// to return null for anyone under 10,000, short-circuiting to a $0 contribution.
$philhealthBelowFloor = computePhilhealthContribution(8000.00, $philhealthTable);
assertClose('PhilHealth employee share for basic pay BELOW the 10,000 floor is charged on the floor, not $0', 250.00, $philhealthBelowFloor['employee']);
assertClose('PhilHealth employer share for basic pay BELOW the 10,000 floor is charged on the floor, not $0', 250.00, $philhealthBelowFloor['employer']);

// Above the salary ceiling -- capped at the ceiling, same bug, opposite end.
$philhealthAboveCeiling = computePhilhealthContribution(150000.00, $philhealthTable);
assertClose('PhilHealth employee share for basic pay ABOVE the 100,000 ceiling is capped at the ceiling, not $0', 2500.00, $philhealthAboveCeiling['employee']);
assertClose('PhilHealth employer share for basic pay ABOVE the 100,000 ceiling is capped at the ceiling, not $0', 2500.00, $philhealthAboveCeiling['employer']);

$pagibig = computePagibigContribution(30000.00, $pagibigTable);
assertClose('Pag-IBIG employee share, capped at max fund salary 10,000 @ 2%', 200.00, $pagibig['employee']);
assertClose('Pag-IBIG employer share, capped at max fund salary 10,000 @ 2%', 200.00, $pagibig['employer']);

$wtax = computeWithholdingTax(50000.00, 'monthly', $wtaxTable);
assertClose('Monthly withholding tax on taxable income 50,000 (bracket: 1875 + 20% over 33,333)', 1875.00 + 0.20 * (50000 - 33333), $wtax);

echo "\n=== 6. Net pay ===\n";
$totalDeductions = computeTotalDeductions(28.74, 16.67, 0.0, 0.0, 0.0, 1500.00, 750.00, 200.00, 1875.00, 0.0);
assertClose('Total deductions sums every line correctly, including undertime', 28.74 + 16.67 + 1500.00 + 750.00 + 200.00 + 1875.00, $totalDeductions);
assertClose('Net pay = gross - total deductions', $grossPay - $totalDeductions, computeNetPay($grossPay, $totalDeductions));

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
