<?php
/**
 * employee_management/employees.php
 *
 * Employee master list for the Payroll module — the foundation every
 * other payroll page (scheduling, attendance, runs, payslips) depends on.
 * Manager-only: workforce management/payroll is the manager's job, not
 * owner's or admin's (owner runs strategy/menu/insights, admin runs system
 * administration — neither does day-to-day HR data entry).
 * List/filter/modal shape is modeled directly on admin/users.php.
 *
 * The Add/Edit form's Work schedule section (shift + work days; every
 * unticked day is a rest day) is the only place an employee's schedule is
 * set -- employee_save.php generates their employee_schedules rows from it,
 * and schedules.php just shows the result. The shifts it picks from are
 * managed in the "Manage shifts" modal here.
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
// Manager reads this list, Admin maintains it. The page itself is open to
// both; every mutating control on it is behind emCanManageEmployees().
if (!Session::hasRole(['manager', 'admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage  = 'payroll_employees';
// Manager sees this list read-only; every control that writes is behind this.
$canManageEmployees = emCanManageEmployees();
$canManageShifts    = emCanManageSchedules();
$pageTitle   = 'Employees';

$employees  = [];
$dbError    = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $employees = $pdo->query(
        "SELECT e.employee_id, e.employee_number, e.first_name, e.middle_name, e.last_name,
                e.email, e.contact_number, e.employment_type, e.employment_status,
                e.salary_type, e.basic_rate, e.pay_frequency, e.is_minimum_wage_earner, e.date_hired, e.is_active,
                e.work_days, st.start_time AS shift_start_time, st.end_time AS shift_end_time,
                d.department_id, d.department_name, p.position_id, p.position_title
         FROM employees e
         LEFT JOIN departments d ON d.department_id = e.department_id
         LEFT JOIN positions p ON p.position_id = e.position_id
         LEFT JOIN shift_templates st ON st.shift_id = e.shift_id
         ORDER BY e.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // The Work schedule section's Shift options, and the Manage shifts modal.
    // employee_count counts archived employees too -- shift_template_delete.php
    // refuses while anyone at all still holds the shift.
    $shifts = $pdo->query(
        "SELECT st.shift_id, st.start_time, st.end_time, st.is_night_shift,
                (SELECT COUNT(*) FROM employees e WHERE e.shift_id = st.shift_id) AS employee_count
         FROM shift_templates st
         ORDER BY st.start_time ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Both include archived rows now -- an employee whose department/
    // position was archived after assignment still needs their own
    // current one to render as a selectable option when editing them
    // (see syncDepartmentOptions()/populatePositions() in the JS below),
    // otherwise the field silently resets to blank and gets cleared on
    // save. Archived rows are still excluded from the plain list-filter
    // dropdown further below, and hidden/disabled everywhere else unless
    // they're the specific employee's own current value.
    $departments = $pdo->query(
        "SELECT department_id, department_name, is_active FROM departments ORDER BY department_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $positions = $pdo->query(
        "SELECT position_id, position_title, department_id, salary_type, pay_frequency,
                regular_rate, probationary_rate, is_active
         FROM positions ORDER BY position_title ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError       = "Couldn't load employee data. Please refresh this page.";
    $departments   = [];
    $positions     = [];
    $shifts        = [];
}

$totalCount   = count($employees);
$activeCount  = count(array_filter($employees, fn($e) => (int)$e['is_active'] === 1));
$monthlyCount = count(array_filter($employees, fn($e) => $e['salary_type'] === 'monthly'));
$dailyCount   = count(array_filter($employees, fn($e) => $e['salary_type'] === 'daily'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employees | Payroll | <?= emPanelLabel() ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<style>
    /* Work days: seven checkboxes styled as one row of toggles, so they read
       as a single control. Same chips the old "Add schedule" form used. */
    .emp-workdays{ display:flex; flex-wrap:wrap; gap:6px; }
    .emp-workday{ cursor:pointer; }
    .emp-workday input{ position:absolute; opacity:0; width:0; height:0; }
    .emp-workday span{ display:inline-block; min-width:44px; text-align:center; padding:7px 10px;
        border:1px solid var(--op-border); border-radius:var(--op-radius-sm);
        font-size:0.8rem; color:var(--op-ink-soft); background:var(--op-surface);
        transition:background .15s var(--op-ease), color .15s var(--op-ease), border-color .15s var(--op-ease); }
    .emp-workday input:checked + span{ background:var(--op-gold); border-color:var(--op-gold); color:#fff; font-weight:600; }
    .emp-workday input:focus-visible + span{ outline:2px solid var(--op-gold); outline-offset:1px; }
    .emp-workdays.is-invalid .emp-workday span{ border-color:var(--op-danger); }
    .emp-hint-warn{ color:var(--op-danger); }
    /* A direct child of the section, not of a .owner-form-group -- as a bare
       inline span it picked up the section's loose line-height. */
    .emp-schedule-note{ display:block; margin-top:6px; line-height:1.5; }
    /* .owner-btn sets its own display, which beats the native [hidden] -- so
       "Cancel edit" showed in Add mode without this. */
    #btnCancelEditShift[hidden]{ display:none; }
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

            <?php /* Page actions + filters sit in their own card above the
                     table -- same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="empFilterSearch" placeholder="Search name, employee #, email&hellip;" autocomplete="off">
                    </div>
                    <select id="empFilterDepartment" class="owner-select">
                        <option value="">All departments</option>
                        <?php foreach (array_filter($departments, fn($d) => (int)$d['is_active'] === 1) as $d): ?>
                            <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="empFilterArchived" class="owner-select">
                        <option value="active" selected>Active</option>
                        <option value="archived">Archived</option>
                        <option value="">All records</option>
                    </select>
                    <?php if ($canManageEmployees): ?>
                        <?php // No Departments & Positions button here. It is Admin-only, and
                              // the Admin sidebar already carries its own link to that page,
                              // so this was a second door to somewhere the only role that can
                              // use it can already reach. ?>
                        <div style="margin-left:auto;display:flex;gap:8px;">
                            <?php if ($canManageShifts): ?>
                                <button type="button" class="owner-btn owner-btn-secondary" id="btnOpenManageShifts">
                                    <i class="ph ph-clock" aria-hidden="true"></i> Manage shifts
                                </button>
                            <?php endif; ?>
                            <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddEmployee">
                                <i class="ph ph-user-plus" aria-hidden="true"></i> Add employee
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* Employee directory as a card grid rather than the list table.
                     The filter data-* attributes below are unchanged from the old
                     <tr>, so the search/department/status/salary/archived filtering
                     still works -- only the JS selector moved from a table row to
                     .owner-emp-card. */ ?>
            <?php if (empty($employees)): ?>
                <div class="owner-card">
                    <div class="owner-emp-empty">No employees yet. Click "Add employee" to create the first record.</div>
                </div>
            <?php else: ?>
                <div class="owner-emp-grid">
                                <?php foreach ($employees as $e):
                                    $active   = (int)$e['is_active'] === 1;
                                    $fullName = trim($e['first_name'] . ' ' . $e['last_name']);
                                    $initials = mb_strtoupper(mb_substr($e['first_name'], 0, 1) . mb_substr($e['last_name'], 0, 1));
                                    $workDaysText = describeWeekdays(parseEmployeeWorkDays($e['work_days']));
                                    $scheduleText = ($e['shift_start_time'] !== null && $workDaysText !== '')
                                        ? formatTimeOfDay($e['shift_start_time']) . '–' . formatTimeOfDay($e['shift_end_time']) . ' · ' . $workDaysText
                                        : 'Not set';
                                ?>
                    <div class="owner-card owner-emp-card"
                        data-name="<?= htmlspecialchars(strtolower($fullName . ' ' . $e['employee_number'] . ' ' . $e['email'])) ?>"
                        data-department="<?= (int)($e['department_id'] ?? 0) ?>"
                        data-active="<?= $active ? '1' : '0' ?>">

                        <div class="owner-emp-card-head">
                            <span class="owner-avatar owner-avatar-sm"><?= htmlspecialchars($initials) ?></span>
                            <span class="owner-emp-card-id">
                                <span class="owner-emp-card-name"><?= htmlspecialchars($fullName) ?></span>
                                <span class="owner-emp-card-number"><?= htmlspecialchars($e['employee_number']) ?></span>
                            </span>
                            <span class="owner-status-pill <?= employmentStatusBadgeClass($e['employment_status']) ?>"><?= htmlspecialchars(employmentStatusLabel($e['employment_status'])) ?></span>
                            <?php if (!$active): ?><span class="owner-status-pill is-inactive">Archived</span><?php endif; ?>
                        </div>

                        <div class="owner-emp-card-fields">
                            <div class="owner-emp-card-row">
                                <span class="owner-emp-card-label">Position</span>
                                <span class="owner-emp-card-value"><?= htmlspecialchars($e['position_title'] ?? '—') ?></span>
                            </div>
                            <div class="owner-emp-card-row">
                                <span class="owner-emp-card-label">Department</span>
                                <span class="owner-emp-card-value"><?= htmlspecialchars($e['department_name'] ?? '—') ?></span>
                            </div>
                            <div class="owner-emp-card-row">
                                <span class="owner-emp-card-label">Schedule</span>
                                <span class="owner-emp-card-value"><?= htmlspecialchars($scheduleText) ?></span>
                            </div>
                            <div class="owner-emp-card-row">
                                <span class="owner-emp-card-label"><?= htmlspecialchars(salaryTypeLabel($e['salary_type'])) ?> basic</span>
                                <span class="owner-emp-card-value"><?= htmlspecialchars(formatBasicRate((float)$e['basic_rate'], $e['salary_type'])) ?></span>
                            </div>
                            <div class="owner-emp-card-row">
                                <span class="owner-emp-card-label">Date hired</span>
                                <span class="owner-emp-card-value"><?= htmlspecialchars(date('M j, Y', strtotime($e['date_hired']))) ?></span>
                            </div>
                        </div>

                        <div class="owner-emp-card-foot">
                            <?php // View stays for the Manager -- reading a record is the whole
                                  // point of their access. Edit and Archive are writes. ?>
                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-view-employee"
                                data-employee-id="<?= (int)$e['employee_id'] ?>">View Details</button>
                            <?php if ($canManageEmployees): ?>
                                <div class="owner-emp-card-actions">
                                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-edit-employee"
                                        data-employee-id="<?= (int)$e['employee_id'] ?>">Edit</button>
                                    <form method="POST" action="employee_toggle_status.php" style="display:inline;" <?php if ($active): ?>data-confirm="Archive <?= htmlspecialchars($fullName) ?>? They will be excluded from active payroll but their history is kept."<?php endif; ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="employee_id" value="<?= (int)$e['employee_id'] ?>">
                                        <?php if ($active): ?>
                                            <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm">Archive</button>
                                        <?php else: ?>
                                            <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm">Restore</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <div id="empNoMatchRow" class="owner-card" hidden>
                    <div class="owner-emp-empty">No employees match your filters.</div>
                </div>
            <?php endif; ?>

        </main>

    </div>

</div>

<?php // Not rendered at all for a Manager: the form posts to employee_save.php,
      // which is Admin-only, so shipping it would only offer a dead end. Its JS
      // is guarded the same way at the bottom of this page. ?>
<?php if ($canManageEmployees): ?>
<!-- Add / edit employee modal -->
<div class="owner-modal-backdrop" id="employeeFormBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="employeeFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="employeeFormTitle">Add employee</h2>
            <button type="button" class="owner-modal-close" id="btnCloseEmployeeForm" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="POST" action="employee_save.php" id="employeeForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="employee_id" id="empEmployeeId" value="">

                <div class="owner-form-note owner-alert owner-alert-error" id="employeeFormAlert" hidden>
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span>Please fix the highlighted fields before saving.</span>
                </div>

                <div class="owner-form-section" id="empNumberSection" style="display:none;">
                    <h3 class="owner-form-section-title">Employee number</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="empNumberDisplay">Employee number</label>
                            <input type="text" id="empNumberDisplay" class="owner-input" disabled>
                            <span class="owner-form-hint">Assigned automatically and can't be changed.</span>
                        </div>
                    </div>
                </div>

                <!-- Personal information -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Personal information</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="empFirstName">First name <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="text" id="empFirstName" name="first_name" class="owner-input" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="empMiddleName">Middle name <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empMiddleName" name="middle_name" class="owner-input">
                        </div>
                        <div class="owner-form-group">
                            <label for="empLastName">Last name <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="text" id="empLastName" name="last_name" class="owner-input" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="empSuffix">Suffix <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empSuffix" name="suffix" class="owner-input" placeholder="Jr., Sr., III">
                        </div>
                        <div class="owner-form-group">
                            <label for="empBirthDate">Birth date <span class="owner-form-optional">(optional)</span></label>
                            <input type="date" id="empBirthDate" name="birth_date" class="owner-input">
                        </div>
                        <div class="owner-form-group">
                            <label for="empGender">Gender <span class="owner-form-optional">(optional)</span></label>
                            <select id="empGender" name="gender" class="owner-select">
                                <option value="">Not specified</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="empCivilStatus">Civil status <span class="owner-form-optional">(optional)</span></label>
                            <select id="empCivilStatus" name="civil_status" class="owner-select">
                                <option value="">Not specified</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="widowed">Widowed</option>
                                <option value="separated">Separated</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="empContactNumber">Contact number <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empContactNumber" name="contact_number" class="owner-input" placeholder="e.g. 0912 345 6789">
                        </div>
                        <div class="owner-form-group">
                            <label for="empEmail">Email <span class="owner-form-optional">(optional)</span></label>
                            <input type="email" id="empEmail" name="email" class="owner-input">
                        </div>
                        <div class="owner-form-group owner-form-group-full">
                            <label for="empAddress">Address <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empAddress" name="address" class="owner-input">
                        </div>
                    </div>
                </div>

                <!-- Employment -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Employment</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="empDepartment">Department <span class="owner-form-optional">(optional)</span></label>
                            <select id="empDepartment" name="department_id" class="owner-select">
                                <option value="">No department</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= (int)$d['department_id'] ?>" data-active="<?= (int)$d['is_active'] ?>"><?= htmlspecialchars($d['department_name']) ?><?= $d['is_active'] ? '' : ' (archived)' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="empPosition">Position <span class="owner-form-optional">(optional)</span></label>
                            <select id="empPosition" name="position_id" class="owner-select">
                                <option value="">No position</option>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="empEmploymentType">Employment type</label>
                            <select id="empEmploymentType" name="employment_type" class="owner-select" required>
                                <option value="regular">Regular</option>
                                <option value="probationary" selected>Probationary</option>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="empEmploymentStatus">Employment status</label>
                            <select id="empEmploymentStatus" name="employment_status" class="owner-select" required>
                                <option value="active" selected>Active</option>
                                <option value="suspended">Suspended</option>
                                <option value="resigned">Resigned</option>
                                <option value="terminated">Terminated</option>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="empDateHired">Date hired <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="date" id="empDateHired" name="date_hired" class="owner-input" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="empDateRegularized">Date regularized <span class="owner-form-optional">(optional)</span></label>
                            <input type="date" id="empDateRegularized" name="date_regularized" class="owner-input">
                        </div>
                        <div class="owner-form-group" id="empDateSeparatedGroup" style="display:none;">
                            <label for="empDateSeparated">Date separated <span class="owner-form-optional">(optional)</span></label>
                            <input type="date" id="empDateSeparated" name="date_separated" class="owner-input">
                        </div>
                        <div class="owner-form-group owner-form-group-full" id="empSeparationReasonGroup" style="display:none;">
                            <label for="empSeparationReason">Separation reason <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empSeparationReason" name="separation_reason" class="owner-input">
                        </div>
                    </div>
                </div>

                <!-- Work schedule -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Work schedule</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="empShift">Shift <span class="owner-required" aria-hidden="true">*</span></label>
                            <select id="empShift" name="shift_id" class="owner-select" required>
                                <option value="">Select a shift</option>
                                <?php foreach ($shifts as $sh): ?>
                                    <option value="<?= (int)$sh['shift_id'] ?>"><?= htmlspecialchars(formatTimeOfDay($sh['start_time'])) ?> – <?= htmlspecialchars(formatTimeOfDay($sh['end_time'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($shifts)): ?>
                                <span class="owner-form-hint">No shifts yet. Add one with "Manage shifts" first.</span>
                            <?php endif; ?>
                        </div>
                        <div class="owner-form-group owner-form-group-full">
                            <label id="empWorkDaysLabel">Work days <span class="owner-required" aria-hidden="true">*</span></label>
                            <div class="emp-workdays" id="empWorkDays" role="group" aria-labelledby="empWorkDaysLabel">
                                <?php foreach (SCHEDULE_WEEKDAYS as $day): ?>
                                    <label class="emp-workday" title="<?= htmlspecialchars($day['full']) ?>">
                                        <input type="checkbox" name="work_days[]" value="<?= htmlspecialchars($day['key']) ?>">
                                        <span><?= htmlspecialchars($day['label']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <span class="owner-form-hint" id="empRestDaysHint">Unticked days are rest days.</span>
                        </div>
                    </div>
                    <span class="owner-form-error" id="empScheduleError" role="alert"></span>
                    <span class="owner-form-hint emp-schedule-note">This repeats every week from the date hired (or today) until you change it. Days already worked keep the schedule they were worked on.</span>
                </div>

                <!-- Compensation -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Compensation</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="empSalaryType">Salary type</label>
                            <select id="empSalaryType" class="owner-select" required>
                                <option value="monthly" selected>Monthly</option>
                                <option value="daily">Daily</option>
                            </select>
                            <input type="hidden" id="empSalaryTypeInput" name="salary_type" value="monthly">
                            <span class="owner-form-hint" id="empSalaryTypeHint">Fixed salary, split across pay periods.</span>
                        </div>
                        <div class="owner-form-group">
                            <label for="empBasicRate" id="empBasicRateLabel">Monthly salary <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="number" id="empBasicRate" class="owner-input" step="0.01" min="0" required>
                            <input type="hidden" id="empBasicRateInput" name="basic_rate" value="0">
                            <span class="owner-form-hint" id="empBasicRateHint" hidden>Determined by this employee's position and employment type.</span>
                        </div>
                        <div class="owner-form-group">
                            <label for="empPayFrequency">Pay frequency</label>
                            <select id="empPayFrequency" class="owner-select" required>
                                <option value="semi_monthly" selected>Semi-monthly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                            <input type="hidden" id="empPayFrequencyInput" name="pay_frequency" value="semi_monthly">
                            <span class="owner-form-hint" id="empPayFrequencyHint" hidden>Determined by the employee's position.</span>
                        </div>
                        <?php // RA 9504. Ticking this exempts the employee from income tax on
                              // the minimum wage and on holiday, overtime, night-differential
                              // and hazard pay -- so it changes withholding tax on every
                              // payslip and is a compliance statement, not a preference. ?>
                        <div class="owner-form-group owner-form-group-full">
                            <label class="owner-checkbox">
                                <input type="checkbox" id="empIsMwe" name="is_minimum_wage_earner" value="1">
                                <span>Minimum wage earner (RA 9504)</span>
                            </label>
                            <span class="owner-form-hint">Exempt from income tax on the statutory minimum wage, holiday pay, overtime, night differential and hazard pay. Other taxable pay such as allowances stays taxable. Tick only if this rate is the regional statutory minimum wage.</span>
                        </div>
                    </div>
                </div>

                <!-- Government IDs -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Government IDs</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="empSssNumber">SSS number <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empSssNumber" name="sss_number" class="owner-input">
                        </div>
                        <div class="owner-form-group">
                            <label for="empPhilhealthNumber">PhilHealth number <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empPhilhealthNumber" name="philhealth_number" class="owner-input">
                        </div>
                        <div class="owner-form-group">
                            <label for="empPagibigNumber">Pag-IBIG number <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empPagibigNumber" name="pagibig_number" class="owner-input">
                        </div>
                        <div class="owner-form-group">
                            <label for="empTinNumber">TIN <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empTinNumber" name="tin_number" class="owner-input">
                        </div>
                    </div>
                </div>

                <!-- Biometric attendance -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Biometric attendance</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="empBiometricUserId">Biometric ID <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="empBiometricUserId" name="biometric_user_id" class="owner-input" placeholder="e.g. 9001" maxlength="50">
                        </div>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelEmployeeForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save employee</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- View employee modal -->
<div class="owner-modal-backdrop" id="employeeViewBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="employeeViewTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title">Employee Profile</h2>
            <button type="button" class="owner-modal-close" id="btnCloseEmployeeView" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body">

            <div class="owner-view-hero">
                <div class="owner-view-hero-main">
                    <span class="owner-avatar" id="empViewAvatar" style="width:56px;height:56px;font-size:1.3rem;"></span>
                    <div>
                        <div class="owner-view-hero-name" id="employeeViewTitle">&nbsp;</div>
                        <div class="owner-view-hero-number" id="empViewNumber"></div>
                        <div class="owner-view-hero-meta"><i class="ph ph-briefcase" aria-hidden="true"></i> <span id="empViewPositionDept"></span></div>
                        <div class="owner-view-hero-meta"><i class="ph ph-calendar" aria-hidden="true"></i> <span id="empViewHiredMeta"></span></div>
                    </div>
                </div>
                <span class="owner-status-pill" id="empViewStatusPill"></span>
            </div>

            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Personal information</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label>Birth date</label>
                        <div id="empViewBirthDate">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Gender</label>
                        <div id="empViewGender">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Civil status</label>
                        <div id="empViewCivilStatus">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Contact number</label>
                        <div id="empViewContactNumber">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Email</label>
                        <div id="empViewEmail">&mdash;</div>
                    </div>
                    <div class="owner-form-group owner-form-group-full">
                        <label>Address</label>
                        <div id="empViewAddress">&mdash;</div>
                    </div>
                </div>
            </div>

            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Employment</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label>Department</label>
                        <div id="empViewDepartment">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Position</label>
                        <div id="empViewPosition">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Employment type</label>
                        <div id="empViewEmploymentType">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Date hired</label>
                        <div id="empViewDateHired">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Date regularized</label>
                        <div id="empViewDateRegularized">&mdash;</div>
                    </div>
                    <div class="owner-form-group owner-form-group-full" id="empViewSeparationWrap" style="display:none;">
                        <label>Separation</label>
                        <div id="empViewSeparation">&mdash;</div>
                    </div>
                </div>
            </div>

            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Work schedule</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label>Shift</label>
                        <div id="empViewShift">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Work days</label>
                        <div id="empViewWorkDays">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Rest days</label>
                        <div id="empViewRestDays">&mdash;</div>
                    </div>
                </div>
            </div>

            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Compensation</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label>Salary type</label>
                        <div id="empViewSalaryType">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Pay frequency</label>
                        <div id="empViewPayFrequency">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Rate</label>
                        <div id="empViewBasicRate">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>Tax status</label>
                        <div id="empViewMwe">&mdash;</div>
                    </div>
                </div>
            </div>

            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Government IDs</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label>SSS number</label>
                        <div id="empViewSssNumber">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>PhilHealth number</label>
                        <div id="empViewPhilhealthNumber">&mdash;</div>
                    </div>
                </div>
                <div class="owner-form-grid" style="margin-top:18px;">
                    <div class="owner-form-group">
                        <label>Pag-IBIG number</label>
                        <div id="empViewPagibigNumber">&mdash;</div>
                    </div>
                    <div class="owner-form-group">
                        <label>TIN</label>
                        <div id="empViewTinNumber">&mdash;</div>
                    </div>
                </div>
            </div>

            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Biometric attendance</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label>Biometric ID</label>
                        <div id="empViewBiometricUserId">&mdash;</div>
                    </div>
                </div>
            </div>

        </div>
        <div class="owner-modal-footer">
            <button type="button" class="owner-btn owner-btn-secondary" id="btnCloseEmployeeViewFooter">Close</button>
            <?php if ($canManageEmployees): ?>
                <button type="button" class="owner-btn owner-btn-primary" id="btnEditFromView">Edit employee</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php // Admin-only, like the endpoints it posts to -- not sent to a Manager at all. ?>
<?php if ($canManageShifts): ?>
<!-- Manage shifts modal -->
<div class="owner-modal-backdrop" id="manageShiftsBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="manageShiftsTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="manageShiftsTitle">Manage shifts</h2>
            <button type="button" class="owner-modal-close" id="btnCloseManageShifts" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body">

            <div class="owner-table-wrap" style="margin-bottom:20px;">
                <table class="owner-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Night shift</th>
                            <th>Employees</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($shifts)): ?>
                            <tr><td colspan="4" class="owner-table-empty">No shifts yet. Add your first one below.</td></tr>
                        <?php else: ?>
                            <?php foreach ($shifts as $sh): $holders = (int)$sh['employee_count']; ?>
                            <tr>
                                <td><?= htmlspecialchars(formatTimeOfDay($sh['start_time'])) ?> – <?= htmlspecialchars(formatTimeOfDay($sh['end_time'])) ?></td>
                                <td><?= ((int)$sh['is_night_shift'] === 1) ? '<i class="ph ph-check" aria-hidden="true"></i>' : '—' ?></td>
                                <td><?= $holders ?></td>
                                <td>
                                    <div class="owner-table-actions">
                                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-shift"
                                            aria-label="Edit shift" data-shift='<?= htmlspecialchars(json_encode($sh), ENT_QUOTES, 'UTF-8') ?>'>
                                            <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                        </button>
                                        <?php if ($holders > 0): ?>
                                            <?php // shift_template_delete.php refuses this anyway; saying so up front beats a round trip. ?>
                                            <button type="button" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" disabled
                                                aria-label="Delete shift" title="Assigned to <?= $holders ?> <?= $holders === 1 ? 'employee' : 'employees' ?> — give them a different shift first">
                                                <i class="ph ph-trash" aria-hidden="true"></i>
                                            </button>
                                        <?php else: ?>
                                            <form method="post" action="shift_template_delete.php" style="display:inline;" data-confirm="Permanently delete this shift? This can't be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="shift_id" value="<?= (int)$sh['shift_id'] ?>">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete shift">
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

            <form method="post" action="shift_template_save.php" id="addShiftForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="shift_id" id="shiftFormShiftId" value="">
                <h3 class="owner-form-section-title" id="shiftFormTitle">Add shift</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="new_shift_start">Start time <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="time" id="new_shift_start" name="start_time" class="owner-input" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="new_shift_end">End time <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="time" id="new_shift_end" name="end_time" class="owner-input" required>
                    </div>
                </div>
                <span class="owner-form-hint">Break duration is set once for everyone in Payroll Settings, not per shift. Changing a shift's times moves every upcoming day not yet worked to the new times.</span>
                <span class="owner-form-hint" id="new_shift_night_indicator" style="display:none;margin-top:14px;color:var(--op-gold);">
                    <i class="ph ph-moon-stars" aria-hidden="true"></i> Night shift — end time is earlier than start time, so it's treated as crossing into the next calendar day.
                </span>
                <div style="margin-top:16px;display:flex;gap:8px;">
                    <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="shiftFormSubmitLabel">Add shift</span></button>
                    <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelEditShift" hidden>Cancel edit</button>
                </div>
            </form>

        </div>
    </div>
</div>
<?php endif; ?>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const positions = <?= json_encode(array_map(fn($p) => [
        'id' => (int)$p['position_id'],
        'title' => $p['position_title'],
        'department_id' => $p['department_id'] ? (int)$p['department_id'] : null,
        'salary_type' => $p['salary_type'],
        'pay_frequency' => $p['pay_frequency'],
        'active' => (bool)$p['is_active'],
        'rates' => [
            'regular' => $p['regular_rate'],
            'probationary' => $p['probationary_rate'],
        ],
    ], $positions), JSON_UNESCAPED_UNICODE) ?>;

    const employees = <?= json_encode(array_map(function ($e) {
        return [
            'employee_id' => (int)$e['employee_id'],
            'employee_number' => $e['employee_number'],
            'first_name' => $e['first_name'],
            'middle_name' => $e['middle_name'],
            'last_name' => $e['last_name'],
            'suffix' => $e['suffix'] ?? null,
            'birth_date' => $e['birth_date'] ?? null,
            'gender' => $e['gender'] ?? '',
            'civil_status' => $e['civil_status'] ?? '',
            'contact_number' => $e['contact_number'] ?? '',
            'email' => $e['email'] ?? '',
            'address' => $e['address'] ?? '',
            'department_id' => $e['department_id'] ? (int)$e['department_id'] : '',
            'position_id' => $e['position_id'] ? (int)$e['position_id'] : '',
            'employment_type' => $e['employment_type'],
            'employment_status' => $e['employment_status'],
            'date_hired' => $e['date_hired'],
            'date_regularized' => $e['date_regularized'] ?? null,
            'date_separated' => $e['date_separated'] ?? null,
            'separation_reason' => $e['separation_reason'] ?? '',
            'salary_type' => $e['salary_type'],
            'basic_rate' => $e['basic_rate'],
            'pay_frequency' => $e['pay_frequency'],
            'is_minimum_wage_earner' => (int)$e['is_minimum_wage_earner'],
            'sss_number' => $e['sss_number'] ?? '',
            'philhealth_number' => $e['philhealth_number'] ?? '',
            'pagibig_number' => $e['pagibig_number'] ?? '',
            'tin_number' => $e['tin_number'] ?? '',
            'biometric_user_id' => $e['biometric_user_id'] ?? '',
            'department_name' => $e['department_name'] ?? '',
            'position_title' => $e['position_title'] ?? '',
            'shift_id' => $e['shift_id'] ? (int)$e['shift_id'] : '',
            'work_days' => parseEmployeeWorkDays($e['work_days'] ?? null),
            // Display strings for the View modal, built with the same PHP
            // helpers the cards use so the two can never disagree.
            'shift_label' => $e['shift_start_time'] !== null
                ? formatTimeOfDay($e['shift_start_time']) . ' – ' . formatTimeOfDay($e['shift_end_time'])
                : '',
            'work_days_label' => describeWeekdays(parseEmployeeWorkDays($e['work_days'] ?? null)),
            'rest_days_label' => parseEmployeeWorkDays($e['work_days'] ?? null) === []
                ? ''
                : (describeWeekdays(array_diff(array_column(SCHEDULE_WEEKDAYS, 'key'), parseEmployeeWorkDays($e['work_days']))) ?: 'None'),
        ];
    }, (function () use ($pdo) {
        // Full field set for the edit AND view modals — the list query
        // above only selects display columns, so re-fetch everything once
        // here.
        try {
            return $pdo->query(
                "SELECT e.*, b.biometric_user_id, d.department_name, p.position_title,
                        st.start_time AS shift_start_time, st.end_time AS shift_end_time
                 FROM employees e
                 LEFT JOIN employee_biometric_ids b ON b.employee_id = e.employee_id AND b.is_active = 1
                 LEFT JOIN departments d ON d.department_id = e.department_id
                 LEFT JOIN positions p ON p.position_id = e.position_id
                 LEFT JOIN shift_templates st ON st.shift_id = e.shift_id"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    })()), JSON_UNESCAPED_UNICODE) ?>;

    <?php if ($canManageEmployees): ?>
    // Everything to the matching endif wires the add/edit form, whose markup a
    // Manager is never sent. It has to be gated in PHP, not just null-checked:
    // roughly forty top-level statements here dereference form fields, and the
    // first one to hit null throws and stops the whole IIFE -- which is exactly
    // what stopped the View modal (further down, and perfectly valid) from ever
    // binding its click handler.
    // -- Department -> position cascading -------------------------------------
    const departmentSelect = document.getElementById('empDepartment');
    const positionSelect   = document.getElementById('empPosition');

    // Archived departments/positions stay hidden from the dropdowns UNLESS
    // they're the specific employee's own current value -- otherwise an
    // employee whose department/position gets archived after assignment
    // would have it silently reset to blank (and cleared on save) the next
    // time anyone opens Edit on them, since the option simply wasn't there
    // to select. (No exclusivity concern here unlike the linked-user-account
    // dropdown -- many employees can share one department/position, so an
    // archived one just needs to stay visible for whoever already has it,
    // not hidden from everyone but one specific employee.)
    function syncDepartmentOptions(currentDepartmentId) {
        departmentSelect.querySelectorAll('option[data-active]').forEach((opt) => {
            const isArchived = opt.getAttribute('data-active') === '0';
            const isCurrent = currentDepartmentId && String(currentDepartmentId) === opt.value;
            opt.hidden = isArchived && !isCurrent;
            opt.disabled = isArchived && !isCurrent;
        });
    }

    function populatePositions(departmentId, selectedPositionId) {
        const deptId = departmentId ? parseInt(departmentId, 10) : null;
        const selectedId = selectedPositionId ? parseInt(selectedPositionId, 10) : null;
        positionSelect.innerHTML = '<option value="">No position</option>';
        positions
            .filter(p => (!deptId || p.department_id === deptId) && (p.active || p.id === selectedId))
            .forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.title + (p.active ? '' : ' (archived)');
                if (selectedId === p.id) opt.selected = true;
                positionSelect.appendChild(opt);
            });
    }

    // -- Salary type / pay frequency / rate: normally free-choice, but a
    // position with its own rates overrides and locks all three once
    // assigned -- e.g. "Head Chef" being Monthly at a fixed rate isn't a
    // per-employee decision, it's a property of the role itself. The rate
    // shown/locked is whichever of the position's 4 employment-type rates
    // matches this employee's own Employment Type, so it also has to
    // re-resolve whenever Employment Type changes, not just Position.
    const salaryTypeSelect    = document.getElementById('empSalaryType');
    const salaryTypeInput     = document.getElementById('empSalaryTypeInput');
    const basicRateLabel      = document.getElementById('empBasicRateLabel');
    const basicRateField      = document.getElementById('empBasicRate');
    const basicRateInput      = document.getElementById('empBasicRateInput');
    const basicRateHint       = document.getElementById('empBasicRateHint');
    const salaryTypeHint      = document.getElementById('empSalaryTypeHint');
    const payFrequencySelect  = document.getElementById('empPayFrequency');
    const payFrequencyInput   = document.getElementById('empPayFrequencyInput');
    const payFrequencyHint    = document.getElementById('empPayFrequencyHint');
    const employmentTypeSelect = document.getElementById('empEmploymentType');
    const POSITION_LOCKED_HINT = "Determined by this employee's position and employment type.";

    function syncSalaryTypeUi() {
        const isDaily = salaryTypeSelect.value === 'daily';
        basicRateLabel.innerHTML = (isDaily ? 'Daily rate' : 'Monthly salary') + ' <span class="owner-required" aria-hidden="true">*</span>';
        if (!salaryTypeSelect.disabled) {
            salaryTypeHint.textContent = isDaily
                ? 'Paid per day actually worked — no work, no pay.'
                : 'Fixed salary, split across pay periods.';
        }
    }

    function applyPositionOverride(positionIdRaw) {
        const pos = positions.find(p => positionIdRaw && p.id === parseInt(positionIdRaw, 10));

        if (pos) {
            salaryTypeSelect.value = pos.salary_type;
            payFrequencySelect.value = pos.pay_frequency;
            basicRateField.value = pos.rates[employmentTypeSelect.value] ?? 0;
            salaryTypeSelect.disabled = true;
            payFrequencySelect.disabled = true;
            basicRateField.disabled = true;
            salaryTypeHint.textContent = POSITION_LOCKED_HINT;
            payFrequencyHint.hidden = false;
            basicRateHint.hidden = false;
        } else {
            salaryTypeSelect.disabled = false;
            payFrequencySelect.disabled = false;
            basicRateField.disabled = false;
            payFrequencyHint.hidden = true;
            basicRateHint.hidden = true;
        }

        salaryTypeInput.value = salaryTypeSelect.value;
        payFrequencyInput.value = payFrequencySelect.value;
        basicRateInput.value = basicRateField.value;
        syncSalaryTypeUi();
    }

    salaryTypeSelect.addEventListener('change', () => {
        salaryTypeInput.value = salaryTypeSelect.value;
        syncSalaryTypeUi();
    });
    payFrequencySelect.addEventListener('change', () => {
        payFrequencyInput.value = payFrequencySelect.value;
    });
    basicRateField.addEventListener('input', () => {
        basicRateInput.value = basicRateField.value;
    });
    employmentTypeSelect.addEventListener('change', () => applyPositionOverride(positionSelect.value));

    departmentSelect.addEventListener('change', () => {
        populatePositions(departmentSelect.value, null);
        applyPositionOverride('');
    });
    positionSelect.addEventListener('change', () => applyPositionOverride(positionSelect.value));
    populatePositions('', null);

    // -- Employment status toggles separation fields ---------------------------
    const statusSelect = document.getElementById('empEmploymentStatus');
    const dateSeparatedGroup = document.getElementById('empDateSeparatedGroup');
    const separationReasonGroup = document.getElementById('empSeparationReasonGroup');

    function syncSeparationFields() {
        const show = ['resigned', 'terminated'].includes(statusSelect.value);
        dateSeparatedGroup.style.display = show ? '' : 'none';
        separationReasonGroup.style.display = show ? '' : 'none';
    }
    statusSelect.addEventListener('change', syncSeparationFields);

    // -- Work schedule: shift + work days ----------------------------------------
    // Unticked days are rest days. The hint under the chips always says which,
    // and flags a week with no rest day at all (advisory -- Labor Code Art. 91
    // gives every employee a rest day after six consecutive work days, but a
    // genuine seven-day arrangement is the Admin's call, not the form's).
    const shiftSelect   = document.getElementById('empShift');
    const workDaysGroup = document.getElementById('empWorkDays');
    const workDayBoxes  = Array.from(workDaysGroup.querySelectorAll('input[name="work_days[]"]'));
    const restDaysHint  = document.getElementById('empRestDaysHint');
    const scheduleError = document.getElementById('empScheduleError');
    const DAY_LABELS    = <?= json_encode(array_column(SCHEDULE_WEEKDAYS, 'label', 'key')) ?>;

    function syncRestDaysHint() {
        const rest = workDayBoxes.filter(b => !b.checked).map(b => DAY_LABELS[b.value]);
        const worked = workDayBoxes.length - rest.length;
        restDaysHint.classList.toggle('emp-hint-warn', worked === 7);
        if (worked === 0) {
            restDaysHint.textContent = 'Unticked days are rest days.';
        } else if (worked === 7) {
            restDaysHint.textContent = 'No rest day. The Labor Code gives every employee a rest day after six consecutive work days.';
        } else {
            restDaysHint.textContent = (rest.length === 1 ? 'Rest day: ' : 'Rest days: ') + rest.join(', ');
        }
    }

    function clearScheduleError() {
        scheduleError.textContent = '';
        shiftSelect.classList.remove('is-invalid');
        workDaysGroup.classList.remove('is-invalid');
    }

    workDayBoxes.forEach(b => b.addEventListener('change', () => { syncRestDaysHint(); clearScheduleError(); }));
    shiftSelect.addEventListener('change', clearScheduleError);

    // Caught here rather than only server-side: a failed save reopens this
    // modal empty, and losing a whole filled-in employee over a missed shift
    // is a poor trade.
    document.getElementById('employeeForm').addEventListener('submit', (e) => {
        const missing = [];
        if (!shiftSelect.value) { missing.push('a shift'); shiftSelect.classList.add('is-invalid'); }
        if (!workDayBoxes.some(b => b.checked)) { missing.push('at least one work day'); workDaysGroup.classList.add('is-invalid'); }
        if (missing.length) {
            e.preventDefault();
            e.stopImmediatePropagation();
            scheduleError.textContent = 'Please choose ' + missing.join(' and ') + '.';
            (shiftSelect.value ? workDayBoxes[0] : shiftSelect).focus();
        }
    });

    // -- Modal open/close -------------------------------------------------------
    const backdrop = document.getElementById('employeeFormBackdrop');
    const form      = document.getElementById('employeeForm');
    const title     = document.getElementById('employeeFormTitle');
    const idField   = document.getElementById('empEmployeeId');
    const numberSection = document.getElementById('empNumberSection');
    const numberDisplay = document.getElementById('empNumberDisplay');

    function openModal() {
        backdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
        const firstField = form.querySelector('input:not([type=hidden]), select');
        if (firstField) firstField.focus();
    }

    function closeModal() {
        backdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    function resetToAddMode() {
        form.reset();
        idField.value = '';
        title.textContent = 'Add employee';
        numberSection.style.display = 'none';
        syncDepartmentOptions(null);
        populatePositions('', null);
        applyPositionOverride('');
        syncSeparationFields();
        clearScheduleError();
        syncRestDaysHint();
    }

    // Null-guarded, not assumed: the Manager's read-only view renders neither
    // this button nor the form modal, and an unguarded getElementById here would
    // throw and take the rest of this script -- including the View modal they DO
    // get -- down with it.
    const btnOpenAdd = document.getElementById('btnOpenAddEmployee');
    if (btnOpenAdd) {
        btnOpenAdd.addEventListener('click', () => {
            resetToAddMode();
            openModal();
        });
    }

    function populateEditForm(emp) {
        form.reset();
        idField.value = emp.employee_id;
        title.textContent = 'Edit employee';
        numberSection.style.display = '';
        numberDisplay.value = emp.employee_number;

        document.getElementById('empFirstName').value = emp.first_name || '';
        document.getElementById('empMiddleName').value = emp.middle_name || '';
        document.getElementById('empLastName').value = emp.last_name || '';
        document.getElementById('empSuffix').value = emp.suffix || '';
        document.getElementById('empBirthDate').value = emp.birth_date || '';
        document.getElementById('empGender').value = emp.gender || '';
        document.getElementById('empCivilStatus').value = emp.civil_status || '';
        document.getElementById('empContactNumber').value = emp.contact_number || '';
        document.getElementById('empEmail').value = emp.email || '';
        document.getElementById('empAddress').value = emp.address || '';

        syncDepartmentOptions(emp.department_id);
        departmentSelect.value = emp.department_id || '';
        populatePositions(emp.department_id, emp.position_id);
        document.getElementById('empEmploymentType').value = emp.employment_type;
        statusSelect.value = emp.employment_status;
        document.getElementById('empDateHired').value = emp.date_hired || '';
        document.getElementById('empDateRegularized').value = emp.date_regularized || '';
        document.getElementById('empDateSeparated').value = emp.date_separated || '';
        document.getElementById('empSeparationReason').value = emp.separation_reason || '';

        // Set the employee's own stored values first (used as-is when no
        // position is assigned), then let applyPositionOverride() take over
        // if emp.position_id resolves to a position -- it locks the fields
        // and re-resolves the rate from Employment Type (already set above)
        // in that case.
        salaryTypeSelect.value = emp.salary_type;
        payFrequencySelect.value = emp.pay_frequency;
        document.getElementById('empBasicRate').value = emp.basic_rate;
        document.getElementById('empIsMwe').checked = Number(emp.is_minimum_wage_earner) === 1;
        applyPositionOverride(emp.position_id);

        document.getElementById('empSssNumber').value = emp.sss_number || '';
        document.getElementById('empPhilhealthNumber').value = emp.philhealth_number || '';
        document.getElementById('empPagibigNumber').value = emp.pagibig_number || '';
        document.getElementById('empTinNumber').value = emp.tin_number || '';
        document.getElementById('empBiometricUserId').value = emp.biometric_user_id || '';

        shiftSelect.value = emp.shift_id || '';
        workDayBoxes.forEach(b => { b.checked = (emp.work_days || []).includes(b.value); });
        clearScheduleError();
        syncRestDaysHint();

        syncSeparationFields();
    }

    document.querySelectorAll('.owner-btn-edit-employee').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.getAttribute('data-employee-id'), 10);
            const emp = employees.find(e => e.employee_id === id);
            if (!emp) return;
            populateEditForm(emp);
            openModal();
        });
    });

    // The whole form-modal wiring is skipped when the modal isn't on the page.
    // Each of these three lines dereferences an element that only exists for an
    // Admin -- unguarded, the first one throws on a Manager's page load and
    // every listener registered after it silently never attaches.
    if (backdrop) {
        document.getElementById('btnCloseEmployeeForm').addEventListener('click', closeModal);
        document.getElementById('btnCancelEmployeeForm').addEventListener('click', closeModal);
        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
        });

        <?php if (!empty($_SESSION['_reopen_employee_modal'])): unset($_SESSION['_reopen_employee_modal']); ?>
        openModal();
        <?php endif; ?>
    }
    <?php endif; /* $canManageEmployees -- closes the add/edit form wiring */ ?>

    <?php if ($canManageShifts): ?>
    // -- Manage shifts modal ---------------------------------------------------
    const shiftBackdrop = document.getElementById('manageShiftsBackdrop');
    const shiftForm = document.getElementById('addShiftForm');
    const shiftFormTitle = document.getElementById('shiftFormTitle');
    const shiftSubmitLabel = document.getElementById('shiftFormSubmitLabel');
    const shiftIdField = document.getElementById('shiftFormShiftId');
    const shiftCancelEditBtn = document.getElementById('btnCancelEditShift');
    const shiftStartInput = document.getElementById('new_shift_start');
    const shiftEndInput = document.getElementById('new_shift_end');
    const shiftNightIndicator = document.getElementById('new_shift_night_indicator');

    // Night shift is derived, not a manual checkbox -- see the same rule
    // enforced server-side in shift_template_save.php.
    function syncNightIndicator() {
        const crossesMidnight = !!shiftStartInput.value && !!shiftEndInput.value && shiftEndInput.value < shiftStartInput.value;
        shiftNightIndicator.style.display = crossesMidnight ? 'block' : 'none';
    }
    shiftStartInput.addEventListener('input', syncNightIndicator);
    shiftEndInput.addEventListener('input', syncNightIndicator);

    function openShiftModal() {
        shiftBackdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closeShiftModal() {
        shiftBackdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }
    function resetShiftFormToAddMode() {
        shiftForm.reset();
        shiftIdField.value = '';
        shiftFormTitle.textContent = 'Add shift';
        shiftSubmitLabel.textContent = 'Add shift';
        shiftCancelEditBtn.hidden = true;
        syncNightIndicator();
    }

    document.getElementById('btnOpenManageShifts').addEventListener('click', () => { resetShiftFormToAddMode(); openShiftModal(); });
    document.getElementById('btnCloseManageShifts').addEventListener('click', closeShiftModal);
    shiftCancelEditBtn.addEventListener('click', resetShiftFormToAddMode);
    shiftBackdrop.addEventListener('click', (e) => { if (e.target === shiftBackdrop) closeShiftModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && shiftBackdrop.classList.contains('is-open')) closeShiftModal(); });

    document.querySelectorAll('.owner-btn-edit-shift').forEach((btn) => {
        btn.addEventListener('click', () => {
            let sh;
            try { sh = JSON.parse(btn.getAttribute('data-shift')); } catch (e) { return; }

            shiftIdField.value = sh.shift_id;
            shiftStartInput.value = (sh.start_time || '').slice(0, 5);
            shiftEndInput.value = (sh.end_time || '').slice(0, 5);
            syncNightIndicator();

            shiftFormTitle.textContent = 'Edit shift';
            shiftSubmitLabel.textContent = 'Save changes';
            shiftCancelEditBtn.hidden = false;
            openShiftModal();
        });
    });

    <?php if (!empty($_SESSION['_reopen_shift_modal'])): unset($_SESSION['_reopen_shift_modal']); ?>
    openShiftModal();
    <?php endif; ?>
    <?php endif; /* $canManageShifts */ ?>

    // -- View employee modal -------------------------------------------------
    const viewBackdrop = document.getElementById('employeeViewBackdrop');
    const viewTitle = document.getElementById('employeeViewTitle');
    const EMPLOYMENT_STATUS_LABELS = { active: 'Active', suspended: 'Suspended', resigned: 'Resigned', terminated: 'Terminated' };
    const EMPLOYMENT_STATUS_PILL_CLASS = { active: 'is-active', suspended: 'is-danger', resigned: 'is-neutral', terminated: 'is-critical' };
    const EMPLOYMENT_TYPE_LABELS = { regular: 'Regular', probationary: 'Probationary' };
    const GENDER_LABELS = { male: 'Male', female: 'Female', other: 'Other' };
    const CIVIL_STATUS_LABELS = { single: 'Single', married: 'Married', widowed: 'Widowed', separated: 'Separated', other: 'Other' };

    function fmtDate(iso) {
        if (!iso) return null;
        const d = new Date(iso + 'T00:00:00');
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }
    function setViewText(id, value) {
        document.getElementById(id).textContent = (value !== null && value !== undefined && value !== '') ? value : '—';
    }

    let viewedEmployeeId = null;

    function openViewModal() {
        viewBackdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closeViewModal() {
        viewBackdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    function populateViewModal(emp) {
        viewedEmployeeId = emp.employee_id;
        viewTitle.textContent = [emp.first_name, emp.middle_name, emp.last_name, emp.suffix].filter(Boolean).join(' ');
        setViewText('empViewNumber', emp.employee_number);

        document.getElementById('empViewAvatar').textContent =
            ((emp.first_name || '').charAt(0) + (emp.last_name || '').charAt(0)).toUpperCase();
        document.getElementById('empViewPositionDept').textContent =
            [emp.position_title, emp.department_name].filter(Boolean).join(' · ') || '—';
        document.getElementById('empViewHiredMeta').textContent =
            'Hired on ' + (fmtDate(emp.date_hired) || '—') + ' · ' + (EMPLOYMENT_TYPE_LABELS[emp.employment_type] || emp.employment_type);

        const statusPill = document.getElementById('empViewStatusPill');
        statusPill.textContent = EMPLOYMENT_STATUS_LABELS[emp.employment_status] || emp.employment_status;
        statusPill.className = 'owner-status-pill ' + (EMPLOYMENT_STATUS_PILL_CLASS[emp.employment_status] || 'is-inactive');

        setViewText('empViewBirthDate', fmtDate(emp.birth_date));
        setViewText('empViewGender', GENDER_LABELS[emp.gender] || null);
        setViewText('empViewCivilStatus', CIVIL_STATUS_LABELS[emp.civil_status] || null);
        setViewText('empViewContactNumber', emp.contact_number);
        setViewText('empViewEmail', emp.email);
        setViewText('empViewAddress', emp.address);

        setViewText('empViewDepartment', emp.department_name);
        setViewText('empViewPosition', emp.position_title);
        setViewText('empViewEmploymentType', EMPLOYMENT_TYPE_LABELS[emp.employment_type] || emp.employment_type);
        setViewText('empViewDateHired', fmtDate(emp.date_hired));
        setViewText('empViewDateRegularized', fmtDate(emp.date_regularized));

        // .owner-form-group-full has its own `display` rule that silently
        // defeats the native [hidden] attribute -- toggle style.display
        // directly instead (see the CSS specificity gotcha memory).
        const sepWrap = document.getElementById('empViewSeparationWrap');
        if (emp.date_separated || emp.separation_reason) {
            sepWrap.style.display = '';
            setViewText('empViewSeparation', [fmtDate(emp.date_separated), emp.separation_reason].filter(Boolean).join(' — '));
        } else {
            sepWrap.style.display = 'none';
        }

        setViewText('empViewShift', emp.shift_label || 'Not set');
        setViewText('empViewWorkDays', emp.work_days_label);
        setViewText('empViewRestDays', emp.rest_days_label);

        setViewText('empViewSalaryType', emp.salary_type === 'daily' ? 'Daily' : 'Monthly');
        setViewText('empViewPayFrequency', emp.pay_frequency === 'monthly' ? 'Monthly' : 'Semi-monthly');
        setViewText('empViewMwe', Number(emp.is_minimum_wage_earner) === 1
            ? 'Minimum wage earner — income-tax exempt (RA 9504)'
            : 'Not a minimum wage earner');
        const rate = '₱' + Number(emp.basic_rate || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        setViewText('empViewBasicRate', rate + (emp.salary_type === 'daily' ? ' / day' : ' / mo'));

        setViewText('empViewSssNumber', emp.sss_number);
        setViewText('empViewPhilhealthNumber', emp.philhealth_number);
        setViewText('empViewPagibigNumber', emp.pagibig_number);
        setViewText('empViewTinNumber', emp.tin_number);

        setViewText('empViewBiometricUserId', emp.biometric_user_id);
    }

    document.querySelectorAll('.owner-btn-view-employee').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.getAttribute('data-employee-id'), 10);
            const emp = employees.find(e => e.employee_id === id);
            if (!emp) return;
            populateViewModal(emp);
            openViewModal();
        });
    });

    document.getElementById('btnCloseEmployeeView').addEventListener('click', closeViewModal);
    document.getElementById('btnCloseEmployeeViewFooter').addEventListener('click', closeViewModal);
    viewBackdrop.addEventListener('click', (e) => { if (e.target === viewBackdrop) closeViewModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && viewBackdrop.classList.contains('is-open')) closeViewModal();
    });

    const btnEditFromView = document.getElementById('btnEditFromView');
    if (btnEditFromView) {
        btnEditFromView.addEventListener('click', () => {
            const emp = employees.find(e => e.employee_id === viewedEmployeeId);
            if (!emp) return;
            closeViewModal();
            populateEditForm(emp);
            openModal();
        });
    }

    // -- Filters -----------------------------------------------------------
    const search   = document.getElementById('empFilterSearch');
    const deptSel  = document.getElementById('empFilterDepartment');
    const archSel  = document.getElementById('empFilterArchived');
    const noMatch  = document.getElementById('empNoMatchRow');
    const countEl  = document.getElementById('employeeCount');
    // Directory is a card grid now, not table rows -- same data-* attributes,
    // so only the selector changed.
    const rows     = Array.from(document.querySelectorAll('.owner-emp-card[data-name]'));

    function applyFilters() {
        const q = search.value.trim().toLowerCase();
        const dept = deptSel.value;
        const arch = archSel.value;
        let visible = 0;

        rows.forEach((row) => {
            const matchesSearch = !q || row.getAttribute('data-name').includes(q);
            const matchesDept = !dept || row.getAttribute('data-department') === dept;
            const matchesArchived = !arch || row.getAttribute('data-active') === (arch === 'active' ? '1' : '0');
            const show = matchesSearch && matchesDept && matchesArchived;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (noMatch) noMatch.hidden = (rows.length === 0 || visible > 0);
        if (countEl) countEl.textContent = visible + (visible === 1 ? ' employee' : ' employees');
    }

    [search, deptSel, archSel].forEach((el) => {
        el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', applyFilters);
    });
    applyFilters();
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
</body>
</html>
