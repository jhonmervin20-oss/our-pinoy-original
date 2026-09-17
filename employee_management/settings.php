<?php
/**
 * employee_management/settings.php
 *
 * Payroll configuration: cutoff periods, attendance/overtime/holiday/night
 * differential rules (the single payroll_settings row), plus the 4
 * Philippine government contribution tables (SSS/PhilHealth/Pag-IBIG/BIR
 * withholding tax) the payroll computation engine reads from.
 *
 * OWNER-only, unlike the rest of employee_management/ (which is manager-only):
 * payroll rules and the statutory contribution tables are a business-owner
 * decision, not day-to-day staff management. It renders in the OWNER chrome
 * and appears as the Payroll tab of owner/settings/, even though the file
 * still lives here beside the payroll engine that reads these tables.
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
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Owner chrome, addressed from this directory: '../owner/' makes every
// sidebar/header link in owner/includes/ resolve correctly from here.
$ownerBase   = '../owner/';
$activePage  = 'settings';
$pageTitle   = 'Payroll settings';

$settings        = null;
$sssTable        = [];
$philhealthTable = [];
$pagibigTable    = [];
$wtaxTable       = [];
$dbError         = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $settings = $pdo->query("SELECT * FROM payroll_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $sssTable = $pdo->query(
        "SELECT * FROM sss_contribution_table ORDER BY compensation_from ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $philhealthTable = $pdo->query(
        "SELECT * FROM philhealth_contribution_table ORDER BY salary_floor ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $pagibigTable = $pdo->query(
        "SELECT * FROM pagibig_contribution_table ORDER BY compensation_from ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $wtaxTable = $pdo->query(
        "SELECT * FROM bir_withholding_tax_table ORDER BY pay_frequency ASC, taxable_income_from ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load payroll settings. Please refresh this page.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll Settings | Payroll | Manager Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/../owner/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/../owner/includes/header.php'; ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php
                // Same sub-nav as owner/settings/*.php. This page sits one
                // directory over, so both hrefs have to be redirected:
                // the other tabs point into owner/settings/, and Payroll
                // points at this very file.
                $activeSettingsTab      = 'payroll';
                $settingsNavBase        = '../owner/settings/';
                $settingsNavPayrollHref = 'settings.php';
                require __DIR__ . '/../owner/settings/includes/settings_nav.php';
            ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($settings): ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Payroll Settings</h2>
                        <span class="owner-card-subtitle">Cutoffs, attendance rules, multipliers, and automation</span>
                    </div>
                </div>

                <form method="POST" action="settings_save.php" id="settingsForm" novalidate>
                    <?= csrf_field() ?>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Semi-monthly cutoff</h3>
                        <div class="owner-form-grid">
                            <div class="owner-form-group">
                                <label for="semiCutoff1Start">1st cutoff — start day</label>
                                <input type="number" id="semiCutoff1Start" name="semi_monthly_cutoff1_start_day" class="owner-input" min="1" max="31" value="<?= (int)$settings['semi_monthly_cutoff1_start_day'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="semiCutoff1End">1st cutoff — end day</label>
                                <input type="number" id="semiCutoff1End" name="semi_monthly_cutoff1_end_day" class="owner-input" min="1" max="31" value="<?= (int)$settings['semi_monthly_cutoff1_end_day'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="semiCutoff1Payout">1st cutoff — payout day</label>
                                <input type="number" id="semiCutoff1Payout" name="semi_monthly_cutoff1_payout_day" class="owner-input" min="1" max="31" value="<?= (int)$settings['semi_monthly_cutoff1_payout_day'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="semiCutoff2Start">2nd cutoff — start day</label>
                                <input type="number" id="semiCutoff2Start" name="semi_monthly_cutoff2_start_day" class="owner-input" min="1" max="31" value="<?= (int)$settings['semi_monthly_cutoff2_start_day'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="semiCutoff2End">2nd cutoff — end day <span class="owner-form-optional">(0 = last day)</span></label>
                                <input type="number" id="semiCutoff2End" name="semi_monthly_cutoff2_end_day" class="owner-input" min="0" max="31" value="<?= (int)$settings['semi_monthly_cutoff2_end_day'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="semiCutoff2Payout">2nd cutoff — payout day</label>
                                <input type="number" id="semiCutoff2Payout" name="semi_monthly_cutoff2_payout_day" class="owner-input" min="1" max="31" value="<?= (int)$settings['semi_monthly_cutoff2_payout_day'] ?>" required>
                            </div>
                            <div class="owner-form-group owner-form-group-full">
                                <label class="owner-checkbox-row" for="semiCutoff2PayoutNextMonth">
                                    <input type="checkbox" id="semiCutoff2PayoutNextMonth" name="semi_monthly_cutoff2_payout_next_month" value="1" class="owner-checkbox-input" <?= $settings['semi_monthly_cutoff2_payout_next_month'] ? 'checked' : '' ?>>
                                    <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                                    <span class="owner-checkbox-text">
                                        <span class="owner-checkbox-label">2nd cutoff payout falls in the next month</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Monthly cutoff</h3>
                        <div class="owner-form-grid">
                            <div class="owner-form-group">
                                <label for="monthlyCutoffStart">Start day</label>
                                <input type="number" id="monthlyCutoffStart" name="monthly_cutoff_start_day" class="owner-input" min="1" max="31" value="<?= (int)$settings['monthly_cutoff_start_day'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="monthlyCutoffEnd">End day <span class="owner-form-optional">(0 = last day)</span></label>
                                <input type="number" id="monthlyCutoffEnd" name="monthly_cutoff_end_day" class="owner-input" min="0" max="31" value="<?= (int)$settings['monthly_cutoff_end_day'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="monthlyPayoutDay">Payout day</label>
                                <input type="number" id="monthlyPayoutDay" name="monthly_payout_day" class="owner-input" min="1" max="31" value="<?= (int)$settings['monthly_payout_day'] ?>" required>
                            </div>
                            <div class="owner-form-group owner-form-group-full">
                                <label class="owner-checkbox-row" for="monthlyPayoutNextMonth">
                                    <input type="checkbox" id="monthlyPayoutNextMonth" name="monthly_payout_next_month" value="1" class="owner-checkbox-input" <?= $settings['monthly_payout_next_month'] ? 'checked' : '' ?>>
                                    <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                                    <span class="owner-checkbox-text">
                                        <span class="owner-checkbox-label">Payout falls in the next month</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Attendance rules</h3>
                        <div class="owner-form-grid">
                            <div class="owner-form-group">
                                <label for="lateGrace">Late grace period (minutes)</label>
                                <input type="number" id="lateGrace" name="late_grace_period_minutes" class="owner-input" min="0" value="<?= (int)$settings['late_grace_period_minutes'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="undertimeGrace">Undertime grace period (minutes)</label>
                                <input type="number" id="undertimeGrace" name="undertime_grace_period_minutes" class="owner-input" min="0" value="<?= (int)$settings['undertime_grace_period_minutes'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="attendanceImportCutoff">Attendance import cutoff (hours)</label>
                                <input type="number" id="attendanceImportCutoff" name="attendance_import_cutoff_hours" class="owner-input" min="0" value="<?= (int)$settings['attendance_import_cutoff_hours'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="stdHours">Standard work hours / day</label>
                                <input type="number" id="stdHours" name="standard_work_hours_per_day" class="owner-input" step="0.01" min="0" value="<?= htmlspecialchars($settings['standard_work_hours_per_day']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="defaultBreakMinutes">Default break duration (minutes)</label>
                                <input type="number" id="defaultBreakMinutes" name="default_break_minutes" class="owner-input" min="0" value="<?= (int)$settings['default_break_minutes'] ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="workDaysDivisor">Annual working days divisor</label>
                                <input type="number" id="workDaysDivisor" name="annual_working_days_divisor" class="owner-input" step="0.01" min="0" value="<?= htmlspecialchars($settings['annual_working_days_divisor']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Overtime &amp; holiday multipliers</h3>
                        <div class="owner-form-grid">
                            <div class="owner-form-group">
                                <label for="otMult">Overtime multiplier</label>
                                <input type="number" id="otMult" name="overtime_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['overtime_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="restMult">Rest day multiplier</label>
                                <input type="number" id="restMult" name="rest_day_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['rest_day_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="restOtMult">Rest day OT multiplier</label>
                                <input type="number" id="restOtMult" name="rest_day_ot_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['rest_day_ot_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="regHolMult">Regular holiday multiplier</label>
                                <input type="number" id="regHolMult" name="regular_holiday_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['regular_holiday_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="regHolOtMult">Regular holiday OT multiplier</label>
                                <input type="number" id="regHolOtMult" name="regular_holiday_ot_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['regular_holiday_ot_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="specHolMult">Special holiday multiplier</label>
                                <input type="number" id="specHolMult" name="special_holiday_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['special_holiday_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="specHolOtMult">Special holiday OT multiplier</label>
                                <input type="number" id="specHolOtMult" name="special_holiday_ot_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['special_holiday_ot_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="regHolRestMult">Regular holiday + rest day multiplier</label>
                                <input type="number" id="regHolRestMult" name="regular_holiday_restday_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['regular_holiday_restday_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="regHolRestOtMult">Regular holiday + rest day OT multiplier</label>
                                <input type="number" id="regHolRestOtMult" name="regular_holiday_restday_ot_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['regular_holiday_restday_ot_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="specHolRestMult">Special holiday + rest day multiplier</label>
                                <input type="number" id="specHolRestMult" name="special_holiday_restday_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['special_holiday_restday_multiplier']) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="specHolRestOtMult">Special holiday + rest day OT multiplier</label>
                                <input type="number" id="specHolRestOtMult" name="special_holiday_restday_ot_multiplier" class="owner-input" step="0.01" min="1" value="<?= htmlspecialchars($settings['special_holiday_restday_ot_multiplier']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Night differential</h3>
                        <div class="owner-form-grid">
                            <div class="owner-form-group">
                                <label for="nightDiffRate">Rate (%)</label>
                                <input type="number" id="nightDiffRate" name="night_differential_rate_percent" class="owner-input" step="0.1" min="0" max="100" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$settings['night_differential_rate'] * 100, 2), '0'), '.')) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="nightDiffStart">Start time</label>
                                <input type="time" id="nightDiffStart" name="night_differential_start" class="owner-input" value="<?= htmlspecialchars(substr($settings['night_differential_start'], 0, 5)) ?>" required>
                            </div>
                            <div class="owner-form-group">
                                <label for="nightDiffEnd">End time</label>
                                <input type="time" id="nightDiffEnd" name="night_differential_end" class="owner-input" value="<?= htmlspecialchars(substr($settings['night_differential_end'], 0, 5)) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Automation</h3>
                        <label class="owner-checkbox-row" for="enableGovContrib">
                            <input type="checkbox" id="enableGovContrib" name="enable_government_contributions" value="1" class="owner-checkbox-input" <?= $settings['enable_government_contributions'] ? 'checked' : '' ?>>
                            <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                            <span class="owner-checkbox-text">
                                <span class="owner-checkbox-label">Enable government contributions</span>
                                <span class="owner-checkbox-desc">SSS, PhilHealth, Pag-IBIG and withholding tax will be deducted during payroll runs.</span>
                            </span>
                        </label>
                    </div>

                    <div style="margin-top:16px;">
                        <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save settings</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- SSS Contribution Table -->
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">SSS Contribution Table</h2>
                        <span class="owner-card-subtitle"><?= count($sssTable) ?> bracket<?= count($sssTable) === 1 ? '' : 's' ?></span>
                    </div>
                    <button type="button" class="owner-btn owner-btn-secondary" id="btnOpenAddSss">
                        <i class="ph ph-plus-circle" aria-hidden="true"></i> Add bracket
                    </button>
                </div>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Compensation range</th>
                                <th>Monthly salary credit</th>
                                <th>Employee</th>
                                <th>Employer</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="sssTableBody">
                            <?php if (empty($sssTable)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No SSS brackets yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sssTable as $b): ?>
                                <tr class="pageable-row">
                                    <td><?= htmlspecialchars(formatMoneyRange((float)$b['compensation_from'], $b['compensation_to'] !== null ? (float)$b['compensation_to'] : null)) ?></td>
                                    <td><?= htmlspecialchars(formatPeso((float)$b['monthly_salary_credit'])) ?></td>
                                    <td><?= htmlspecialchars(formatPeso((float)$b['employee_share'])) ?></td>
                                    <td><?= htmlspecialchars(formatPeso((float)$b['employer_share'])) ?></td>
                                    <td><?= htmlspecialchars(formatPeso((float)$b['total_contribution'])) ?></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-sss"
                                                aria-label="Edit bracket" data-bracket='<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="post" action="sss_bracket_delete.php" style="display:inline;" data-confirm="Delete this bracket? This can't be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="sss_bracket_id" value="<?= (int)$b['sss_bracket_id'] ?>">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete bracket">
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

                <div class="owner-pagination" id="sssPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="sssPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="sssPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="sssPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

            <!-- PhilHealth Contribution Table -->
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">PhilHealth Contribution Table</h2>
                        <span class="owner-card-subtitle"><?= count($philhealthTable) ?> bracket<?= count($philhealthTable) === 1 ? '' : 's' ?></span>
                    </div>
                    <button type="button" class="owner-btn owner-btn-secondary" id="btnOpenAddPhilhealth">
                        <i class="ph ph-plus-circle" aria-hidden="true"></i> Add bracket
                    </button>
                </div>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Salary range</th>
                                <th>Premium rate</th>
                                <th>Employee share</th>
                                <th>Employer share</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="philhealthTableBody">
                            <?php if (empty($philhealthTable)): ?>
                                <tr><td colspan="5" class="owner-table-empty">No PhilHealth brackets yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($philhealthTable as $b): ?>
                                <tr class="pageable-row">
                                    <td><?= htmlspecialchars(formatMoneyRange((float)$b['salary_floor'], (float)$b['salary_ceiling'])) ?></td>
                                    <td><?= htmlspecialchars(formatPercent((float)$b['premium_rate_percent'])) ?></td>
                                    <td><?= htmlspecialchars(formatPercent((float)$b['employee_share_percent'])) ?></td>
                                    <td><?= htmlspecialchars(formatPercent((float)$b['employer_share_percent'])) ?></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-philhealth"
                                                aria-label="Edit bracket" data-bracket='<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="post" action="philhealth_bracket_delete.php" style="display:inline;" data-confirm="Delete this bracket? This can't be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="philhealth_bracket_id" value="<?= (int)$b['philhealth_bracket_id'] ?>">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete bracket">
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

                <div class="owner-pagination" id="philhealthPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="philhealthPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="philhealthPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="philhealthPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

            <!-- Pag-IBIG Contribution Table -->
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Pag-IBIG Contribution Table</h2>
                        <span class="owner-card-subtitle"><?= count($pagibigTable) ?> bracket<?= count($pagibigTable) === 1 ? '' : 's' ?></span>
                    </div>
                    <button type="button" class="owner-btn owner-btn-secondary" id="btnOpenAddPagibig">
                        <i class="ph ph-plus-circle" aria-hidden="true"></i> Add bracket
                    </button>
                </div>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Compensation range</th>
                                <th>Employee rate</th>
                                <th>Employer rate</th>
                                <th>Max fund salary</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="pagibigTableBody">
                            <?php if (empty($pagibigTable)): ?>
                                <tr><td colspan="5" class="owner-table-empty">No Pag-IBIG brackets yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pagibigTable as $b): ?>
                                <tr class="pageable-row">
                                    <td><?= htmlspecialchars(formatMoneyRange((float)$b['compensation_from'], $b['compensation_to'] !== null ? (float)$b['compensation_to'] : null)) ?></td>
                                    <td><?= htmlspecialchars(formatPercent((float)$b['employee_rate_percent'])) ?></td>
                                    <td><?= htmlspecialchars(formatPercent((float)$b['employer_rate_percent'])) ?></td>
                                    <td><?= htmlspecialchars(formatPeso((float)$b['max_fund_salary'])) ?></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-pagibig"
                                                aria-label="Edit bracket" data-bracket='<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="post" action="pagibig_bracket_delete.php" style="display:inline;" data-confirm="Delete this bracket? This can't be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="pagibig_bracket_id" value="<?= (int)$b['pagibig_bracket_id'] ?>">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete bracket">
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

                <div class="owner-pagination" id="pagibigPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="pagibigPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="pagibigPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="pagibigPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

            <!-- BIR Withholding Tax Table -->
            <div class="owner-card">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">BIR Withholding Tax Table</h2>
                        <span class="owner-card-subtitle"><?= count($wtaxTable) ?> bracket<?= count($wtaxTable) === 1 ? '' : 's' ?></span>
                    </div>
                    <button type="button" class="owner-btn owner-btn-secondary" id="btnOpenAddWtax">
                        <i class="ph ph-plus-circle" aria-hidden="true"></i> Add bracket
                    </button>
                </div>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Frequency</th>
                                <th>Taxable income range</th>
                                <th>Base tax</th>
                                <th>Rate over excess</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="wtaxTableBody">
                            <?php if (empty($wtaxTable)): ?>
                                <tr><td colspan="5" class="owner-table-empty">No withholding tax brackets yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($wtaxTable as $b): ?>
                                <tr class="pageable-row">
                                    <td><?= htmlspecialchars(payFrequencyBadgeLabel($b['pay_frequency'])) ?></td>
                                    <td><?= htmlspecialchars(formatMoneyRange((float)$b['taxable_income_from'], $b['taxable_income_to'] !== null ? (float)$b['taxable_income_to'] : null)) ?></td>
                                    <td><?= htmlspecialchars(formatPeso((float)$b['base_tax'])) ?></td>
                                    <td><?= htmlspecialchars(formatPercent((float)$b['tax_rate_percent'])) ?> over <?= htmlspecialchars(formatPeso((float)$b['excess_over'])) ?></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-wtax"
                                                aria-label="Edit bracket" data-bracket='<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="post" action="wtax_bracket_delete.php" style="display:inline;" data-confirm="Delete this bracket? This can't be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="wtax_bracket_id" value="<?= (int)$b['wtax_bracket_id'] ?>">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete bracket">
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

                <div class="owner-pagination" id="wtaxPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="wtaxPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="wtaxPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="wtaxPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- Add / edit SSS bracket modal -->
<div class="owner-modal-backdrop" id="sssFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="sssFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="sssFormTitle">Add SSS bracket</h2>
            <button type="button" class="owner-modal-close" id="btnCloseSssForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="sss_bracket_save.php" id="sssForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="sss_bracket_id" id="sssBracketId" value="">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="sssCompFrom">Compensation from</label>
                        <input type="number" id="sssCompFrom" name="compensation_from" class="owner-input" step="0.01" min="0" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="sssCompTo">Compensation to <span class="owner-form-optional">(blank = and up)</span></label>
                        <input type="number" id="sssCompTo" name="compensation_to" class="owner-input" step="0.01" min="0">
                    </div>
                    <div class="owner-form-group">
                        <label for="sssMsc">Monthly salary credit</label>
                        <input type="number" id="sssMsc" name="monthly_salary_credit" class="owner-input" step="0.01" min="0" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="sssEmployeeShare">Employee share</label>
                        <input type="number" id="sssEmployeeShare" name="employee_share" class="owner-input" step="0.01" min="0" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="sssEmployerShare">Employer share</label>
                        <input type="number" id="sssEmployerShare" name="employer_share" class="owner-input" step="0.01" min="0" required>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelSssForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save bracket</button>
            </div>
        </form>
    </div>
</div>

<!-- Add / edit PhilHealth bracket modal -->
<div class="owner-modal-backdrop" id="philhealthFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="philhealthFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="philhealthFormTitle">Add PhilHealth bracket</h2>
            <button type="button" class="owner-modal-close" id="btnClosePhilhealthForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="philhealth_bracket_save.php" id="philhealthForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="philhealth_bracket_id" id="philhealthBracketId" value="">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="philhealthFloor">Salary floor</label>
                        <input type="number" id="philhealthFloor" name="salary_floor" class="owner-input" step="0.01" min="0" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="philhealthCeiling">Salary ceiling</label>
                        <input type="number" id="philhealthCeiling" name="salary_ceiling" class="owner-input" step="0.01" min="0" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="philhealthPremium">Premium rate (%)</label>
                        <input type="number" id="philhealthPremium" name="premium_rate_percent" class="owner-input" step="0.001" min="0" max="100" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="philhealthEmployee">Employee share (%)</label>
                        <input type="number" id="philhealthEmployee" name="employee_share_percent" class="owner-input" step="0.001" min="0" max="100" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="philhealthEmployer">Employer share (%)</label>
                        <input type="number" id="philhealthEmployer" name="employer_share_percent" class="owner-input" step="0.001" min="0" max="100" required>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelPhilhealthForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save bracket</button>
            </div>
        </form>
    </div>
</div>

<!-- Add / edit Pag-IBIG bracket modal -->
<div class="owner-modal-backdrop" id="pagibigFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="pagibigFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="pagibigFormTitle">Add Pag-IBIG bracket</h2>
            <button type="button" class="owner-modal-close" id="btnClosePagibigForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="pagibig_bracket_save.php" id="pagibigForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="pagibig_bracket_id" id="pagibigBracketId" value="">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="pagibigCompFrom">Compensation from</label>
                        <input type="number" id="pagibigCompFrom" name="compensation_from" class="owner-input" step="0.01" min="0" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="pagibigCompTo">Compensation to <span class="owner-form-optional">(blank = and up)</span></label>
                        <input type="number" id="pagibigCompTo" name="compensation_to" class="owner-input" step="0.01" min="0">
                    </div>
                    <div class="owner-form-group">
                        <label for="pagibigEmployeeRate">Employee rate (%)</label>
                        <input type="number" id="pagibigEmployeeRate" name="employee_rate_percent" class="owner-input" step="0.001" min="0" max="100" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="pagibigEmployerRate">Employer rate (%)</label>
                        <input type="number" id="pagibigEmployerRate" name="employer_rate_percent" class="owner-input" step="0.001" min="0" max="100" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="pagibigMaxFund">Max fund salary</label>
                        <input type="number" id="pagibigMaxFund" name="max_fund_salary" class="owner-input" step="0.01" min="0" required>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelPagibigForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save bracket</button>
            </div>
        </form>
    </div>
</div>

<!-- Add / edit BIR withholding tax bracket modal -->
<div class="owner-modal-backdrop" id="wtaxFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="wtaxFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="wtaxFormTitle">Add withholding tax bracket</h2>
            <button type="button" class="owner-modal-close" id="btnCloseWtaxForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="wtax_bracket_save.php" id="wtaxForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="wtax_bracket_id" id="wtaxBracketId" value="">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="wtaxPayFrequency">Pay frequency</label>
                        <select id="wtaxPayFrequency" name="pay_frequency" class="owner-select" required>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="semi_monthly" selected>Semi-monthly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="wtaxIncomeFrom">Taxable income from</label>
                        <input type="number" id="wtaxIncomeFrom" name="taxable_income_from" class="owner-input" step="0.01" min="0" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="wtaxIncomeTo">Taxable income to <span class="owner-form-optional">(blank = and up)</span></label>
                        <input type="number" id="wtaxIncomeTo" name="taxable_income_to" class="owner-input" step="0.01" min="0">
                    </div>
                    <div class="owner-form-group">
                        <label for="wtaxBaseTax">Base tax</label>
                        <input type="number" id="wtaxBaseTax" name="base_tax" class="owner-input" step="0.01" min="0" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="wtaxRate">Tax rate (%)</label>
                        <input type="number" id="wtaxRate" name="tax_rate_percent" class="owner-input" step="0.01" min="0" max="100" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="wtaxExcessOver">Excess over</label>
                        <input type="number" id="wtaxExcessOver" name="excess_over" class="owner-input" step="0.01" min="0" required>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelWtaxForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save bracket</button>
            </div>
        </form>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    function wireBracketModal(cfg) {
        const backdrop = document.getElementById(cfg.backdropId);
        const form = document.getElementById(cfg.formId);
        const title = document.getElementById(cfg.titleId);
        const idField = document.getElementById(cfg.idFieldId);

        function open() {
            backdrop.classList.add('is-open');
            document.body.classList.add('owner-modal-open');
            const first = form.querySelector('input:not([type=hidden]), select');
            if (first) first.focus();
        }
        function close() {
            backdrop.classList.remove('is-open');
            document.body.classList.remove('owner-modal-open');
        }
        function resetToAdd() {
            form.reset();
            idField.value = '';
            title.textContent = cfg.addTitle;
        }

        document.getElementById(cfg.openBtnId).addEventListener('click', () => { resetToAdd(); open(); });
        document.getElementById(cfg.closeBtnId).addEventListener('click', close);
        document.getElementById(cfg.cancelBtnId).addEventListener('click', close);
        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) close(); });

        document.querySelectorAll(cfg.editBtnSelector).forEach((btn) => {
            btn.addEventListener('click', () => {
                let b;
                try { b = JSON.parse(btn.getAttribute('data-bracket')); } catch (e) { return; }
                idField.value = b[cfg.idKey];
                cfg.fields.forEach(({ id, key }) => {
                    const el = document.getElementById(id);
                    if (el) el.value = b[key] !== null && b[key] !== undefined ? b[key] : '';
                });
                title.textContent = cfg.editTitle;
                open();
            });
        });

        if (cfg.reopenFlag) {
            open();
        }
    }

    wireBracketModal({
        backdropId: 'sssFormBackdrop', formId: 'sssForm', titleId: 'sssFormTitle', idFieldId: 'sssBracketId',
        openBtnId: 'btnOpenAddSss', closeBtnId: 'btnCloseSssForm', cancelBtnId: 'btnCancelSssForm',
        editBtnSelector: '.owner-btn-edit-sss', idKey: 'sss_bracket_id',
        addTitle: 'Add SSS bracket', editTitle: 'Edit SSS bracket',
        fields: [
            { id: 'sssCompFrom', key: 'compensation_from' },
            { id: 'sssCompTo', key: 'compensation_to' },
            { id: 'sssMsc', key: 'monthly_salary_credit' },
            { id: 'sssEmployeeShare', key: 'employee_share' },
            { id: 'sssEmployerShare', key: 'employer_share' },
        ],
        reopenFlag: <?= !empty($_SESSION['_reopen_sss_modal']) ? 'true' : 'false' ?>,
    });

    wireBracketModal({
        backdropId: 'philhealthFormBackdrop', formId: 'philhealthForm', titleId: 'philhealthFormTitle', idFieldId: 'philhealthBracketId',
        openBtnId: 'btnOpenAddPhilhealth', closeBtnId: 'btnClosePhilhealthForm', cancelBtnId: 'btnCancelPhilhealthForm',
        editBtnSelector: '.owner-btn-edit-philhealth', idKey: 'philhealth_bracket_id',
        addTitle: 'Add PhilHealth bracket', editTitle: 'Edit PhilHealth bracket',
        fields: [
            { id: 'philhealthFloor', key: 'salary_floor' },
            { id: 'philhealthCeiling', key: 'salary_ceiling' },
            { id: 'philhealthPremium', key: 'premium_rate_percent' },
            { id: 'philhealthEmployee', key: 'employee_share_percent' },
            { id: 'philhealthEmployer', key: 'employer_share_percent' },
        ],
        reopenFlag: <?= !empty($_SESSION['_reopen_philhealth_modal']) ? 'true' : 'false' ?>,
    });

    wireBracketModal({
        backdropId: 'pagibigFormBackdrop', formId: 'pagibigForm', titleId: 'pagibigFormTitle', idFieldId: 'pagibigBracketId',
        openBtnId: 'btnOpenAddPagibig', closeBtnId: 'btnClosePagibigForm', cancelBtnId: 'btnCancelPagibigForm',
        editBtnSelector: '.owner-btn-edit-pagibig', idKey: 'pagibig_bracket_id',
        addTitle: 'Add Pag-IBIG bracket', editTitle: 'Edit Pag-IBIG bracket',
        fields: [
            { id: 'pagibigCompFrom', key: 'compensation_from' },
            { id: 'pagibigCompTo', key: 'compensation_to' },
            { id: 'pagibigEmployeeRate', key: 'employee_rate_percent' },
            { id: 'pagibigEmployerRate', key: 'employer_rate_percent' },
            { id: 'pagibigMaxFund', key: 'max_fund_salary' },
        ],
        reopenFlag: <?= !empty($_SESSION['_reopen_pagibig_modal']) ? 'true' : 'false' ?>,
    });

    wireBracketModal({
        backdropId: 'wtaxFormBackdrop', formId: 'wtaxForm', titleId: 'wtaxFormTitle', idFieldId: 'wtaxBracketId',
        openBtnId: 'btnOpenAddWtax', closeBtnId: 'btnCloseWtaxForm', cancelBtnId: 'btnCancelWtaxForm',
        editBtnSelector: '.owner-btn-edit-wtax', idKey: 'wtax_bracket_id',
        addTitle: 'Add withholding tax bracket', editTitle: 'Edit withholding tax bracket',
        fields: [
            { id: 'wtaxPayFrequency', key: 'pay_frequency' },
            { id: 'wtaxIncomeFrom', key: 'taxable_income_from' },
            { id: 'wtaxIncomeTo', key: 'taxable_income_to' },
            { id: 'wtaxBaseTax', key: 'base_tax' },
            { id: 'wtaxRate', key: 'tax_rate_percent' },
            { id: 'wtaxExcessOver', key: 'excess_over' },
        ],
        reopenFlag: <?= !empty($_SESSION['_reopen_wtax_modal']) ? 'true' : 'false' ?>,
    });
    <?php
    unset($_SESSION['_reopen_sss_modal'], $_SESSION['_reopen_philhealth_modal'], $_SESSION['_reopen_pagibig_modal'], $_SESSION['_reopen_wtax_modal']);
    ?>

    // -- Pagination for the 4 government contribution tables (client-side,
    // 5 rows/page -- these are static reference tables with no search/filter,
    // so this is plain slice-by-page over each table's own rows). ----------
    function setupTablePagination(cfg) {
        const rows = Array.from(document.querySelectorAll(cfg.rowSelector));
        if (rows.length === 0) return;

        const pagination = document.getElementById(cfg.paginationId);
        const pagePrev = document.getElementById(cfg.prevId);
        const pageNext = document.getElementById(cfg.nextId);
        const pageInfo = document.getElementById(cfg.infoId);
        let currentPage = 1;

        function render() {
            const totalPages = Math.max(1, Math.ceil(rows.length / cfg.pageSize));
            if (currentPage > totalPages) currentPage = totalPages;
            const start = (currentPage - 1) * cfg.pageSize;
            const end = start + cfg.pageSize;
            rows.forEach((row, i) => { row.style.display = (i >= start && i < end) ? '' : 'none'; });
            if (pagination) pagination.hidden = totalPages <= 1;
            if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
            if (pagePrev) pagePrev.disabled = currentPage <= 1;
            if (pageNext) pageNext.disabled = currentPage >= totalPages;
        }

        if (pagePrev) pagePrev.addEventListener('click', () => { currentPage--; render(); });
        if (pageNext) pageNext.addEventListener('click', () => { currentPage++; render(); });
        render();
    }

    setupTablePagination({
        rowSelector: '#sssTableBody tr.pageable-row', paginationId: 'sssPagination',
        prevId: 'sssPagePrev', nextId: 'sssPageNext', infoId: 'sssPageInfo', pageSize: 5,
    });
    setupTablePagination({
        rowSelector: '#philhealthTableBody tr.pageable-row', paginationId: 'philhealthPagination',
        prevId: 'philhealthPagePrev', nextId: 'philhealthPageNext', infoId: 'philhealthPageInfo', pageSize: 5,
    });
    setupTablePagination({
        rowSelector: '#pagibigTableBody tr.pageable-row', paginationId: 'pagibigPagination',
        prevId: 'pagibigPagePrev', nextId: 'pagibigPageNext', infoId: 'pagibigPageInfo', pageSize: 5,
    });
    setupTablePagination({
        rowSelector: '#wtaxTableBody tr.pageable-row', paginationId: 'wtaxPagination',
        prevId: 'wtaxPagePrev', nextId: 'wtaxPageNext', infoId: 'wtaxPageInfo', pageSize: 5,
    });

    // -- PhilHealth: employee/employer share auto-split from the premium
    // rate -- PhilHealth premiums are split 50/50 by law, so this fills
    // in the usual case while staying editable for an unusual one. ------
    const philhealthPremiumInput = document.getElementById('philhealthPremium');
    const philhealthEmployeeInput = document.getElementById('philhealthEmployee');
    const philhealthEmployerInput = document.getElementById('philhealthEmployer');
    philhealthPremiumInput.addEventListener('input', () => {
        const premium = parseFloat(philhealthPremiumInput.value);
        if (isNaN(premium)) return;
        const half = Math.round((premium / 2) * 1000) / 1000;
        philhealthEmployeeInput.value = half;
        philhealthEmployerInput.value = half;
    });

    // -- Withholding tax: "Excess over" auto-fills as this bracket's own
    // floor (Taxable income from minus &#8369;0.01), matching how every row
    // of the real BIR table is already structured -- still editable. ----
    const wtaxIncomeFromInput = document.getElementById('wtaxIncomeFrom');
    const wtaxExcessOverInput = document.getElementById('wtaxExcessOver');
    wtaxIncomeFromInput.addEventListener('input', () => {
        const from = parseFloat(wtaxIncomeFromInput.value);
        if (isNaN(from)) return;
        wtaxExcessOverInput.value = Math.max(0, Math.round((from - 0.01) * 100) / 100);
    });
})();
</script>

</body>
</html>
