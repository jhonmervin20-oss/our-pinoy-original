<?php
/**
 * employee_management/holidays.php
 *
 * The calendar of Philippine holidays used by holiday-pay computation:
 * each date is tagged 'regular' or 'special', which is what the
 * computation engine will look up to pick the right multiplier from
 * payroll_settings (regular_holiday_multiplier vs special_holiday_multiplier,
 * and their _ot_ counterparts) once a payroll run processes an
 * attendance_records row marked status='holiday'. This calendar table
 * didn't exist in the original schema -- attendance_records.status could
 * already say 'holiday' and payroll_settings already had both
 * multipliers, but nothing recorded which specific dates were holidays or
 * which type -- so it was added here. Admin-only since 2026-09-15 (moved from
 * the Manager panel); payroll runs still read this table as before.
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
// Holidays moved to the Admin on 2026-09-15 -- see includes/em_access.php.
if (!emCanManageHolidays()) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage  = 'payroll_holidays';
$pageTitle   = 'Holidays';

$currentYear = (int)date('Y');
$yearFilter = trim((string)($_GET['year'] ?? $currentYear));
if (!preg_match('/^\d{4}$/', $yearFilter)) {
    $yearFilter = (string)$currentYear;
}

// Defaults to Active only, same as every other list page's archive filter
// in this app -- Archived/All only shown when explicitly picked.
$statusFilter = trim((string)($_GET['status'] ?? 'active'));
if (!in_array($statusFilter, ['active', 'archived', 'all'], true)) {
    $statusFilter = 'active';
}

$holidays = [];
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $sql = "SELECT holiday_id, holiday_date, holiday_name, holiday_type, is_active FROM holidays WHERE YEAR(holiday_date) = ?";
    if ($statusFilter === 'active') {
        $sql .= " AND is_active = 1";
    } elseif ($statusFilter === 'archived') {
        $sql .= " AND is_active = 0";
    }
    $sql .= " ORDER BY holiday_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$yearFilter]);
    $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load holidays. Please refresh this page.";
}

$regularCount = count(array_filter($holidays, fn($h) => $h['holiday_type'] === 'regular'));
$specialCount = count(array_filter($holidays, fn($h) => $h['holiday_type'] === 'special'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Holidays | Payroll | <?= emPanelLabel() ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
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

           

            <?php /* Filters + actions card, then a bare table card -- the list-page
                     split used everywhere else. No title or count above a list table. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <form method="GET" class="owner-card-tools" style="margin-left:auto;">
                        <select name="year" class="owner-select" style="width:auto;" onchange="this.form.submit()">
                            <?php for ($y = $currentYear + 1; $y >= $currentYear - 2; $y--): ?>
                                <option value="<?= $y ?>" <?= (string)$y === $yearFilter ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="status" class="owner-select" style="width:auto;" onchange="this.form.submit()">
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All records</option>
                        </select>
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddHoliday">
                            <i class="ph ph-plus-circle" aria-hidden="true"></i> Add holiday
                        </button>
                    </form>
                </div>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Holiday</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($holidays)): ?>
                                <tr><td colspan="5" class="owner-table-empty">No holidays set for <?= htmlspecialchars($yearFilter) ?> yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($holidays as $h):
                                    $hActive = (int)$h['is_active'] === 1;
                                    $hData = $h;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('D, M j, Y', strtotime($h['holiday_date']))) ?></td>
                                    <td><?= htmlspecialchars($h['holiday_name']) ?></td>
                                    <td><span class="owner-status-pill <?= holidayTypeBadgeClass($h['holiday_type']) ?>"><?= htmlspecialchars(holidayTypeLabel($h['holiday_type'])) ?></span></td>
                                    <td>
                                        <?php if ($hActive): ?>
                                            <span class="owner-status-pill is-active">Active</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-inactive">Archived</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-holiday"
                                                aria-label="Edit holiday" data-holiday='<?= htmlspecialchars(json_encode($hData), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="post" action="holiday_toggle_status.php" style="display:inline;" <?php if ($hActive): ?>data-confirm="Archive this holiday?"<?php endif; ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="holiday_id" value="<?= (int)$h['holiday_id'] ?>">
                                                <input type="hidden" name="return_year" value="<?= htmlspecialchars($yearFilter) ?>">
                                                <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                                                <?php if ($hActive): ?>
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Archive holiday">
                                                        <i class="ph ph-archive" aria-hidden="true"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Reactivate holiday">
                                                        <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- Add / edit holiday modal -->
<div class="owner-modal-backdrop" id="holFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="holFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="holFormTitle">Add holiday</h2>
            <button type="button" class="owner-modal-close" id="btnCloseHolForm" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <form method="POST" action="holiday_save.php" id="holForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="holiday_id" id="holIdHidden" value="">
                <input type="hidden" name="return_year" value="<?= htmlspecialchars($yearFilter) ?>">
                <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="holDate">Date <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="date" id="holDate" name="holiday_date" class="owner-input" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="holType">Type <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="holType" name="holiday_type" class="owner-select" required>
                            <option value="regular" selected>Regular</option>
                            <option value="special">Special (non-working)</option>
                        </select>
                    </div>
                    <div class="owner-form-group owner-form-group-full">
                        <label for="holName">Holiday name <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="text" id="holName" name="holiday_name" class="owner-input" placeholder="e.g. Independence Day" required>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelHolForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save holiday</button>
            </div>
        </form>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const backdrop = document.getElementById('holFormBackdrop');
    const form = document.getElementById('holForm');
    const title = document.getElementById('holFormTitle');
    const idField = document.getElementById('holIdHidden');
    const dateField = document.getElementById('holDate');
    const typeField = document.getElementById('holType');
    const nameField = document.getElementById('holName');

    function open() { backdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); }
    function close() { backdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }

    document.getElementById('btnOpenAddHoliday').addEventListener('click', () => {
        form.reset();
        idField.value = '';
        title.textContent = 'Add holiday';
        open();
    });
    document.getElementById('btnCloseHolForm').addEventListener('click', close);
    document.getElementById('btnCancelHolForm').addEventListener('click', close);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) close(); });

    document.querySelectorAll('.owner-btn-edit-holiday').forEach((btn) => {
        btn.addEventListener('click', () => {
            let h;
            try { h = JSON.parse(btn.getAttribute('data-holiday')); } catch (e) { return; }

            idField.value = h.holiday_id;
            dateField.value = h.holiday_date;
            typeField.value = h.holiday_type;
            nameField.value = h.holiday_name;

            title.textContent = 'Edit holiday';
            open();
        });
    });

    <?php if (!empty($_SESSION['_reopen_holiday_modal'])): unset($_SESSION['_reopen_holiday_modal']); ?>
    open();
    <?php endif; ?>
})();
</script>

</body>
</html>
