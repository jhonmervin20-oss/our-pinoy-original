<?php
/**
 * employee_management/departments_positions.php
 *
 * Departments and Positions, promoted out of the two "Manage departments"/
 * "Manage positions" modals that used to live on employees.php into their
 * own dedicated page with a Departments/Positions tab each -- managing the
 * org structure no longer competes for space with the (already dense)
 * Add/Edit Employee form. employees.php still reads from these same two
 * tables for its Department/Position dropdowns and the position-locks-
 * salary-fields behavior; only the CRUD screens moved.
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
// Departments & Positions moved to the Admin panel -- the Manager has no
// access to this area at all any more. See includes/em_access.php.
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage  = 'payroll_departments_positions';
$pageTitle   = 'Departments & Positions';

$allDepartments = [];
$allPositions   = [];
$dbError        = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $allDepartments = $pdo->query(
        "SELECT d.department_id, d.department_name, d.is_active,
                (SELECT COUNT(*) FROM positions p WHERE p.department_id = d.department_id) AS position_count,
                (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.department_id) AS employee_count
         FROM departments d
         ORDER BY d.department_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $allPositions = $pdo->query(
        "SELECT p.position_id, p.position_title, p.is_active,
                p.department_id, d.department_name, p.salary_type, p.pay_frequency,
                p.regular_rate, p.probationary_rate,
                (SELECT COUNT(*) FROM employees e WHERE e.position_id = p.position_id) AS employee_count
         FROM positions p
         LEFT JOIN departments d ON d.department_id = p.department_id
         ORDER BY p.position_title ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load department/position data. Please refresh this page.";
}

$activeDepartments = array_values(array_filter($allDepartments, fn($d) => (int)$d['is_active'] === 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Departments &amp; Positions | Payroll | <?= emPanelLabel() ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<style>
    .owner-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(20, 18, 16, 0.55);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        z-index: 1000;
    }
    .owner-modal-overlay[hidden] {
        display: none;
    }
    .owner-modal {
        background: #fff;
        border-radius: 12px;
        width: 100%;
        max-width: 560px;
        max-height: 90vh;
        overflow-y: auto;
        padding: 24px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.28);
    }
    .owner-modal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
    }
    .owner-modal-head .owner-form-section-title {
        margin: 0;
    }
    .owner-modal-close {
        background: none;
        border: none;
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
        color: #6b6b6b;
        padding: 4px 8px;
        border-radius: 6px;
    }
    .owner-modal-close:hover {
        background: #f2f2f2;
        color: #222;
    }
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

            <!-- Tabs -->
            <div class="owner-tabs">
                <a href="#" class="owner-tabs-link is-active" data-org-tab="departments"><i class="ph ph-sitemap" aria-hidden="true"></i> Departments</a>
                <a href="#" class="owner-tabs-link" data-org-tab="positions"> Positions</a>
            </div>

            <!-- Departments panel -->
            <?php /* Page actions + filters sit in their own card above the table --
                     same split used across every list page. Both cards carry
                     data-org-panel so the tab toggle moves them together. */ ?>
            <div class="owner-card" data-org-panel="departments" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <select id="deptFilterArchived" class="owner-select">
                        <option value="active" selected>Active</option>
                        <option value="archived">Archived</option>
                        <option value="">All records</option>
                    </select>
                    <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddDepartment" style="margin-left:auto;">
                        <i class="ph ph-plus" aria-hidden="true"></i> Add department
                    </button>
                </div>
            </div>

            <div class="owner-card" data-org-panel="departments" id="org-panel-departments">
                <div class="owner-table-wrap" style="margin-bottom:20px;">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Positions</th>
                                <th>Employees</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allDepartments)): ?>
                                <tr><td colspan="5" class="owner-table-empty">No departments yet. Add your first one below.</td></tr>
                            <?php else: ?>
                                <?php foreach ($allDepartments as $d):
                                    $deptIsActive = (int)$d['is_active'] === 1;
                                    $deptData = [
                                        'department_id'   => $d['department_id'],
                                        'department_name' => $d['department_name'],
                                        'is_active'       => $deptIsActive ? 1 : 0,
                                    ];
                                ?>
                                <tr data-active="<?= $deptIsActive ? '1' : '0' ?>">
                                    <td><?= htmlspecialchars($d['department_name']) ?></td>
                                    <td><?= (int)$d['position_count'] ?></td>
                                    <td><?= (int)$d['employee_count'] ?></td>
                                    <td>
                                        <?php if ($deptIsActive): ?>
                                            <span class="owner-status-pill is-active">Active</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-inactive">Archived</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-department"
                                                aria-label="Edit department" data-department='<?= htmlspecialchars(json_encode($deptData), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="post" action="department_toggle_status.php" style="display:inline;" <?php if ($deptIsActive): ?>data-confirm="Archive <?= htmlspecialchars($d['department_name']) ?>? It will no longer be selectable for new employees."<?php endif; ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="department_id" value="<?= (int)$d['department_id'] ?>">
                                                <?php if ($deptIsActive): ?>
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Archive department">
                                                        <i class="ph ph-archive" aria-hidden="true"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Reactivate department">
                                                        <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="owner-table-empty-row" id="deptNoMatchRow" hidden>
                                    <td colspan="5" class="owner-table-empty">No departments match this filter.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-modal-overlay" id="departmentModalOverlay" hidden>
                    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="departmentFormTitle">
                        <div class="owner-modal-head">
                            <h3 class="owner-form-section-title" id="departmentFormTitle">Add department</h3>
                            <button type="button" class="owner-modal-close" id="btnCloseDepartmentModal" aria-label="Close">
                                <i class="ph ph-x" aria-hidden="true"></i>
                            </button>
                        </div>
                        <form method="post" action="department_save.php" id="addDepartmentForm" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="department_id" id="departmentFormDepartmentId" value="">
                            <div class="owner-form-grid">
                                <div class="owner-form-group">
                                    <label for="new_department_name">Department name <span class="owner-required" aria-hidden="true">*</span></label>
                                    <input type="text" id="new_department_name" name="department_name" class="owner-input" placeholder="e.g. Kitchen" required>
                                </div>
                            </div>
                            <label class="owner-checkbox-row" for="new_department_is_active" style="margin-top:14px;">
                                <input type="checkbox" id="new_department_is_active" name="is_active" value="1" class="owner-checkbox-input" checked>
                                <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                                <span class="owner-checkbox-text">
                                    <span class="owner-checkbox-label">Active department</span>
                                </span>
                            </label>
                            <div style="margin-top:16px;display:flex;gap:8px;">
                                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="departmentFormSubmitLabel">Add department</span></button>
                                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelEditDepartment" style="display:none;">Cancel edit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Positions panel -->
            <div class="owner-card" data-org-panel="positions" style="display:none;margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <select id="posFilterArchived" class="owner-select">
                        <option value="active" selected>Active</option>
                        <option value="archived">Archived</option>
                        <option value="">All records</option>
                    </select>
                    <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddPosition" style="margin-left:auto;">
                        <i class="ph ph-plus" aria-hidden="true"></i> Add position
                    </button>
                </div>
            </div>

            <div class="owner-card" data-org-panel="positions" id="org-panel-positions" style="display:none;">
                <div class="owner-table-wrap" style="margin-bottom:20px;">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Position</th>
                                <th>Department</th>
                                <th>Pay basis</th>
                                <th>Employees</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allPositions)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No positions yet. Add your first one below.</td></tr>
                            <?php else: ?>
                                <?php foreach ($allPositions as $p):
                                    $posIsActive = (int)$p['is_active'] === 1;
                                    $posData = [
                                        'position_id'       => $p['position_id'],
                                        'position_title'    => $p['position_title'],
                                        'department_id'     => $p['department_id'],
                                        'salary_type'       => $p['salary_type'],
                                        'pay_frequency'     => $p['pay_frequency'],
                                        'regular_rate'      => $p['regular_rate'],
                                        'probationary_rate' => $p['probationary_rate'],
                                        'is_active'         => $posIsActive ? 1 : 0,
                                    ];
                                ?>
                                <tr data-active="<?= $posIsActive ? '1' : '0' ?>">
                                    <td><?= htmlspecialchars($p['position_title']) ?></td>
                                    <td><?= htmlspecialchars($p['department_name'] ?? '—') ?></td>
                                    <td>
                                        <div class="owner-cell-stack">
                                            <strong><?= htmlspecialchars(salaryTypeLabel($p['salary_type'])) ?> · <?= htmlspecialchars(payFrequencyLabel($p['pay_frequency'])) ?></strong>
                                            <small>Reg <?= htmlspecialchars(formatBasicRate((float)$p['regular_rate'], $p['salary_type'])) ?> · Prob <?= htmlspecialchars(formatBasicRate((float)$p['probationary_rate'], $p['salary_type'])) ?></small>
                                        </div>
                                    </td>
                                    <td><?= (int)$p['employee_count'] ?></td>
                                    <td>
                                        <?php if ($posIsActive): ?>
                                            <span class="owner-status-pill is-active">Active</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-inactive">Archived</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-position"
                                                aria-label="Edit position" data-position='<?= htmlspecialchars(json_encode($posData), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="post" action="position_toggle_status.php" style="display:inline;" <?php if ($posIsActive): ?>data-confirm="Archive <?= htmlspecialchars($p['position_title']) ?>? It will no longer be selectable for new employees."<?php endif; ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="position_id" value="<?= (int)$p['position_id'] ?>">
                                                <?php if ($posIsActive): ?>
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Archive position">
                                                        <i class="ph ph-archive" aria-hidden="true"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Reactivate position">
                                                        <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="owner-table-empty-row" id="posNoMatchRow" hidden>
                                    <td colspan="6" class="owner-table-empty">No positions match this filter.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-modal-overlay" id="positionModalOverlay" hidden>
                    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="positionFormTitle">
                        <div class="owner-modal-head">
                            <h3 class="owner-form-section-title" id="positionFormTitle">Add position</h3>
                            <button type="button" class="owner-modal-close" id="btnClosePositionModal" aria-label="Close">
                                <i class="ph ph-x" aria-hidden="true"></i>
                            </button>
                        </div>
                        <form method="post" action="position_save.php" id="addPositionForm" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="position_id" id="positionFormPositionId" value="">
                            <div class="owner-form-grid">
                                <div class="owner-form-group">
                                    <label for="new_position_title">Position title <span class="owner-required" aria-hidden="true">*</span></label>
                                    <input type="text" id="new_position_title" name="position_title" class="owner-input" placeholder="e.g. Head Chef" required>
                                </div>
                                <div class="owner-form-group">
                                    <label for="new_position_department">Department <span class="owner-form-optional">(optional)</span></label>
                                    <select id="new_position_department" name="department_id" class="owner-select">
                                        <option value="">No department</option>
                                        <?php foreach ($activeDepartments as $d): ?>
                                            <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="owner-form-group">
                                    <label for="new_position_salary_type">Salary type</label>
                                    <select id="new_position_salary_type" name="salary_type" class="owner-select" required>
                                        <option value="monthly" selected>Monthly</option>
                                        <option value="daily">Daily</option>
                                    </select>
                                  
                                </div>
                                <div class="owner-form-group">
                                    <label for="new_position_pay_frequency">Pay frequency</label>
                                    <select id="new_position_pay_frequency" name="pay_frequency" class="owner-select" required>
                                        <option value="semi_monthly" selected>Semi-monthly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                            </div>
                            <div class="owner-form-section-title" style="margin-top:14px;font-size:0.85rem;">Rate by employment type</div>
                           
                            <div class="owner-form-grid" style="margin-top:8px;">
                                <div class="owner-form-group">
                                    <label for="new_position_regular_rate" class="js-position-rate-label" data-rate-prefix="Regular">Regular monthly rate</label>
                                    <input type="number" id="new_position_regular_rate" name="regular_rate" class="owner-input" step="0.01" min="0" value="0" required>
                                </div>
                                <div class="owner-form-group">
                                    <label for="new_position_probationary_rate" class="js-position-rate-label" data-rate-prefix="Probationary">Probationary monthly rate</label>
                                    <input type="number" id="new_position_probationary_rate" name="probationary_rate" class="owner-input" step="0.01" min="0" value="0" required>
                                </div>
                            </div>
                            <label class="owner-checkbox-row" for="new_position_is_active" style="margin-top:14px;">
                                <input type="checkbox" id="new_position_is_active" name="is_active" value="1" class="owner-checkbox-input" checked>
                                <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                                <span class="owner-checkbox-text">
                                    <span class="owner-checkbox-label">Active position</span>
                                </span>
                            </label>
                            <div style="margin-top:16px;display:flex;gap:8px;">
                                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="positionFormSubmitLabel">Add position</span></button>
                                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelEditPosition" style="display:none;">Cancel edit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </main>

    </div>

</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    // -- Tabs (Departments / Positions) --------------------------------------
    const tabs = document.querySelectorAll('[data-org-tab]');
    // Each tab owns TWO cards -- its filters card and its table card -- so the
    // toggle works off the shared data-org-panel attribute, not a single id.
    const panelCards = document.querySelectorAll('[data-org-panel]');

    function activateTab(key) {
        tabs.forEach(t => t.classList.toggle('is-active', t.getAttribute('data-org-tab') === key));
        panelCards.forEach((el) => {
            el.style.display = (el.getAttribute('data-org-panel') === key) ? '' : 'none';
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            activateTab(tab.getAttribute('data-org-tab'));
        });
    });

    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    if (requestedTab === 'positions') activateTab('positions');

    // -- Modal helpers ------------------------------------------------------
    function openModal(overlay) {
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function closeModal(overlay) {
        overlay.hidden = true;
        document.body.style.overflow = '';
    }

    // -- Department add/edit form ---------------------------------------------
    const deptForm = document.getElementById('addDepartmentForm');
    const deptFormTitle = document.getElementById('departmentFormTitle');
    const deptSubmitLabel = document.getElementById('departmentFormSubmitLabel');
    const deptIdField = document.getElementById('departmentFormDepartmentId');
    const deptCancelEditBtn = document.getElementById('btnCancelEditDepartment');
    const deptModalOverlay = document.getElementById('departmentModalOverlay');
    const btnOpenAddDepartment = document.getElementById('btnOpenAddDepartment');
    const btnCloseDepartmentModal = document.getElementById('btnCloseDepartmentModal');

    function resetDepartmentFormToAddMode() {
        deptForm.reset();
        deptIdField.value = '';
        document.getElementById('new_department_is_active').checked = true;
        deptFormTitle.textContent = 'Add department';
        deptSubmitLabel.textContent = 'Add department';
        deptCancelEditBtn.style.display = 'none';
    }

    btnOpenAddDepartment.addEventListener('click', () => {
        resetDepartmentFormToAddMode();
        openModal(deptModalOverlay);
    });
    btnCloseDepartmentModal.addEventListener('click', () => closeModal(deptModalOverlay));
    deptModalOverlay.addEventListener('click', (e) => {
        if (e.target === deptModalOverlay) closeModal(deptModalOverlay);
    });
    deptCancelEditBtn.addEventListener('click', () => {
        resetDepartmentFormToAddMode();
        closeModal(deptModalOverlay);
    });

    document.querySelectorAll('.owner-btn-edit-department').forEach((btn) => {
        btn.addEventListener('click', () => {
            let d;
            try { d = JSON.parse(btn.getAttribute('data-department')); } catch (e) { return; }

            deptIdField.value = d.department_id;
            document.getElementById('new_department_name').value = d.department_name || '';
            document.getElementById('new_department_is_active').checked = !!d.is_active;

            deptFormTitle.textContent = 'Edit department';
            deptSubmitLabel.textContent = 'Save changes';
            deptCancelEditBtn.style.display = '';
            openModal(deptModalOverlay);
        });
    });

    // -- Position add/edit form ------------------------------------------------
    const posForm = document.getElementById('addPositionForm');
    const posFormTitle = document.getElementById('positionFormTitle');
    const posSubmitLabel = document.getElementById('positionFormSubmitLabel');
    const posIdField = document.getElementById('positionFormPositionId');
    const posCancelEditBtn = document.getElementById('btnCancelEditPosition');
    const posSalaryTypeSelect = document.getElementById('new_position_salary_type');
    const posRateLabels = document.querySelectorAll('.js-position-rate-label');
    const posModalOverlay = document.getElementById('positionModalOverlay');
    const btnOpenAddPosition = document.getElementById('btnOpenAddPosition');
    const btnClosePositionModal = document.getElementById('btnClosePositionModal');

    function syncPositionRateLabel() {
        const unit = posSalaryTypeSelect.value === 'daily' ? 'daily rate' : 'monthly rate';
        posRateLabels.forEach((label) => {
            label.textContent = label.getAttribute('data-rate-prefix') + ' ' + unit;
        });
    }
    posSalaryTypeSelect.addEventListener('change', syncPositionRateLabel);

    function resetPositionFormToAddMode() {
        posForm.reset();
        posIdField.value = '';
        document.getElementById('new_position_is_active').checked = true;
        posFormTitle.textContent = 'Add position';
        posSubmitLabel.textContent = 'Add position';
        posCancelEditBtn.style.display = 'none';
        syncPositionRateLabel();
    }

    btnOpenAddPosition.addEventListener('click', () => {
        resetPositionFormToAddMode();
        openModal(posModalOverlay);
    });
    btnClosePositionModal.addEventListener('click', () => closeModal(posModalOverlay));
    posModalOverlay.addEventListener('click', (e) => {
        if (e.target === posModalOverlay) closeModal(posModalOverlay);
    });
    posCancelEditBtn.addEventListener('click', () => {
        resetPositionFormToAddMode();
        closeModal(posModalOverlay);
    });

    document.querySelectorAll('.owner-btn-edit-position').forEach((btn) => {
        btn.addEventListener('click', () => {
            let p;
            try { p = JSON.parse(btn.getAttribute('data-position')); } catch (e) { return; }

            posIdField.value = p.position_id;
            document.getElementById('new_position_title').value = p.position_title || '';
            document.getElementById('new_position_department').value = p.department_id || '';
            document.getElementById('new_position_salary_type').value = p.salary_type || 'monthly';
            document.getElementById('new_position_pay_frequency').value = p.pay_frequency || 'semi_monthly';
            document.getElementById('new_position_regular_rate').value = p.regular_rate;
            document.getElementById('new_position_probationary_rate').value = p.probationary_rate;
            document.getElementById('new_position_is_active').checked = !!p.is_active;
            syncPositionRateLabel();

            posFormTitle.textContent = 'Edit position';
            posSubmitLabel.textContent = 'Save changes';
            posCancelEditBtn.style.display = '';
            openModal(posModalOverlay);
        });
    });

    // -- Escape key closes any open modal ---------------------------------------
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (!deptModalOverlay.hidden) closeModal(deptModalOverlay);
        if (!posModalOverlay.hidden) closeModal(posModalOverlay);
    });

    // -- Active/Archived filter (both tabs) -- defaults to Active only, same
    // as every other list page's archive filter in this app. --------------
    function wireArchivedFilter(selectId, tableSelector, noMatchId) {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll(tableSelector + ' tbody tr[data-active]'));
        const noMatch = document.getElementById(noMatchId);
        function apply() {
            const val = select.value;
            let visible = 0;
            rows.forEach((row) => {
                const show = !val || row.getAttribute('data-active') === (val === 'active' ? '1' : '0');
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (noMatch) noMatch.hidden = (rows.length === 0 || visible > 0);
        }
        select.addEventListener('change', apply);
        apply();
    }
    wireArchivedFilter('deptFilterArchived', '#org-panel-departments .owner-table', 'deptNoMatchRow');
    wireArchivedFilter('posFilterArchived', '#org-panel-positions .owner-table', 'posNoMatchRow');
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
</body>
</html>