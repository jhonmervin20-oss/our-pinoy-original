<?php
/**
 * admin/users.php
 *
 * User management for the Admin panel. Staff accounts (admin, owner,
 * manager, cashier) and customer accounts are pulled from the same
 * `users` table but shown in separate tabs, since they're managed
 * differently and mixing them in one list would be noisy.
 *
 * Staff accounts can be created/edited/deactivated here (see
 * users_save.php / users_toggle_status.php). Customers are self-service
 * (sign_up.php) — this page can only activate/deactivate them, never
 * create or edit their details.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/display_helpers.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'users';
$pageTitle  = 'Users';
$currentUserId = Session::getUserId();

$staff     = [];
$customers = [];
$dbError   = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $staff = $pdo->query(
        "SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone,
                u.is_active, u.created_at, r.role_name
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name != 'customer'
         ORDER BY u.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $customers = $pdo->query(
        "SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone,
                u.is_active, u.created_at
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name = 'customer'
         ORDER BY u.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load live user data. Run the schema migration, then refresh this page.";
}

$staffActive    = count(array_filter($staff, fn($u) => (int)$u['is_active'] === 1));
$customerActive = count(array_filter($customers, fn($u) => (int)$u['is_active'] === 1));

/** Role badge color follows the same is-active/is-warning/is-danger/is-success pill set already used elsewhere. */
function roleBadgeClass(string $role): string
{
    return match ($role) {
        'admin'   => 'is-danger',
        'owner'   => 'is-active',
        'manager' => 'is-warning',
        'cashier' => 'is-success',
        default   => 'is-inactive',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users | Admin Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<style>
    /* Reveal toggle for the two staff password fields. Page-scoped rather
       than added to owner-panel.css -- that file has five separate copies
       across module folders, and this control exists only here. The button
       sits inside the field, so the input reserves room for it via
       padding-right and the text never runs under the icon. */
    .owner-password-field{ position:relative; display:block; }
    .owner-password-field .owner-input{ padding-right:40px; }
    .owner-password-toggle{
        position:absolute; top:50%; right:6px; transform:translateY(-50%);
        display:flex; align-items:center; justify-content:center;
        width:30px; height:30px; padding:0;
        background:none; border:0; border-radius:6px; cursor:pointer;
        color:var(--op-ink-faint); font-size:1.05rem; line-height:1;
        transition:color 0.15s ease, background 0.15s ease;
    }
    .owner-password-toggle:hover{ color:var(--op-gold); background:var(--op-gold-soft); }
    .owner-password-toggle:focus-visible{ outline:2px solid var(--op-gold); outline-offset:1px; }
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/includes/header.php'; ?>

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
                <a href="#" class="owner-tabs-link is-active" data-user-tab="staff">Staff</a>
                <a href="#" class="owner-tabs-link" data-user-tab="customers">Customers</a>
            </div>

            <!-- Staff panel -->
            <?php /* Page actions + filters sit in their own card above the table --
                     same split used across every list page. Both cards carry
                     data-user-panel so the tab toggle moves them together. */ ?>
            <div class="owner-card owner-user-panel" data-user-panel="staff" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="staffFilterSearch" placeholder="Search staff&hellip;" autocomplete="off">
                    </div>
                    <select id="staffFilterRole" class="owner-select">
                        <option value="">All roles</option>
                        <option value="admin">Admin</option>
                        <option value="owner">Owner</option>
                        <option value="manager">Manager</option>
                        <option value="cashier">Cashier</option>
                    </select>
                    <select id="staffFilterArchived" class="owner-select">
                        <option value="active" selected>Active</option>
                        <option value="archived">Archived</option>
                        <option value="">All records</option>
                    </select>
                    <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddStaff" style="margin-left:auto;">
                        <i class="ph ph-plus-circle" aria-hidden="true"></i> Add staff account
                    </button>
                </div>
            </div>

            <div class="owner-card owner-user-panel" data-user-panel="staff" id="user-panel-staff">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staff)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No staff accounts yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($staff as $u): $active = (int)$u['is_active'] === 1; ?>
                                <tr data-name="<?= htmlspecialchars(strtolower($u['first_name'] . ' ' . $u['last_name'])) ?>" data-role="<?= htmlspecialchars($u['role_name']) ?>" data-active="<?= $active ? '1' : '0' ?>">
                                    <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td><span class="owner-status-pill <?= roleBadgeClass($u['role_name']) ?>"><?= htmlspecialchars(ucfirst($u['role_name'])) ?></span></td>
                                    <td>
                                        <?php if ($active): ?>
                                            <span class="owner-status-pill is-active"><i class="ph ph-check" aria-hidden="true"></i> Active</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($u['created_at']))) ?></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-staff"
                                                aria-label="Edit account"
                                                data-user-id="<?= (int)$u['user_id'] ?>"
                                                data-first-name="<?= htmlspecialchars($u['first_name']) ?>"
                                                data-last-name="<?= htmlspecialchars($u['last_name']) ?>"
                                                data-email="<?= htmlspecialchars($u['email']) ?>"
                                                data-role="<?= htmlspecialchars($u['role_name']) ?>">
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <?php if ((int)$u['user_id'] === $currentUserId): ?>
                                                <span class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" style="opacity:0.4;cursor:not-allowed;" aria-label="This is you" title="This is you">
                                                    <i class="ph ph-user" aria-hidden="true"></i>
                                                </span>
                                            <?php else: ?>
                                                <form method="POST" action="users_toggle_status.php" style="display:inline;" <?php if ($active): ?>data-confirm="Deactivate <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>? They will no longer be able to log in."<?php endif; ?>>
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                    <input type="hidden" name="tab" value="staff">
                                                    <?php if ($active): ?>
                                                        <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Deactivate account">
                                                            <i class="ph ph-prohibit" aria-hidden="true"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Activate account">
                                                            <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="staffNoMatchRow" class="owner-table-empty-row" hidden>
                                    <td colspan="6" class="owner-table-empty">No staff match your filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Customers panel -->
            <div class="owner-card owner-user-panel" data-user-panel="customers" style="display:none;margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="customerFilterSearch" placeholder="Search customers&hellip;" autocomplete="off">
                    </div>
                    <select id="customerFilterArchived" class="owner-select">
                        <option value="active" selected>Active</option>
                        <option value="archived">Archived</option>
                        <option value="">All records</option>
                    </select>
                </div>
            </div>

            <div class="owner-card owner-user-panel" data-user-panel="customers" id="user-panel-customers" style="display:none;">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No customer accounts yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($customers as $u): $active = (int)$u['is_active'] === 1; ?>
                                <tr data-name="<?= htmlspecialchars(strtolower($u['first_name'] . ' ' . $u['last_name'])) ?>" data-active="<?= $active ? '1' : '0' ?>">
                                    <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td><?= htmlspecialchars(phoneOrNa($u['phone'])) ?></td>
                                    <td>
                                        <?php if ($active): ?>
                                            <span class="owner-status-pill is-active"><i class="ph ph-check" aria-hidden="true"></i> Active</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($u['created_at']))) ?></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <form method="POST" action="users_toggle_status.php" style="display:inline;" <?php if ($active): ?>data-confirm="Deactivate <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>? They will no longer be able to log in."<?php endif; ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                <input type="hidden" name="tab" value="customers">
                                                <?php if ($active): ?>
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Deactivate account">
                                                        <i class="ph ph-prohibit" aria-hidden="true"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Activate account">
                                                        <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="customerNoMatchRow" class="owner-table-empty-row" hidden>
                                    <td colspan="6" class="owner-table-empty">No customers match your search.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- Add / edit staff modal -->
<div class="owner-modal-backdrop" id="staffFormBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="staffFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="staffFormTitle">Add staff account</h2>
            <button type="button" class="owner-modal-close" id="btnCloseStaffForm" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="POST" action="users_save.php" id="staffForm" novalidate>
            <div class="owner-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" id="staffUserId" value="">

                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="staffFirstName">First name</label>
                        <input type="text" id="staffFirstName" name="first_name" class="owner-input" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="staffLastName">Last name</label>
                        <input type="text" id="staffLastName" name="last_name" class="owner-input" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="staffEmail">Email</label>
                        <input type="email" id="staffEmail" name="email" class="owner-input" required>
                    </div>
                    <?php /* No phone field. It was optional and nobody filled it in --
                             every staff account has a NULL phone -- so it was a field
                             that only ever added a step. users_save.php no longer
                             writes the column at all, which also means editing an
                             account can't blank a number set elsewhere (a staff
                             member can still set their own under My profile). */ ?>
                    <div class="owner-form-group">
                        <label for="staffRole">Role</label>
                        <select id="staffRole" name="role" class="owner-select" required>
                            <option value="admin">Admin</option>
                            <option value="owner">Owner</option>
                            <option value="manager">Manager</option>
                            <option value="cashier">Cashier</option>
                        </select>
                    </div>
                </div>

                <div class="owner-form-grid" style="margin-top:18px;">
                    <div class="owner-form-group">
                        <label for="staffPassword" id="staffPasswordLabel">Password</label>
                        <div class="owner-password-field">
                            <input type="password" id="staffPassword" name="password" class="owner-input" autocomplete="new-password">
                            <button type="button" class="owner-password-toggle" data-toggle-for="staffPassword" aria-label="Show password" aria-pressed="false">
                                <i class="ph ph-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <span class="owner-form-hint" id="staffPasswordHint">At least 8 characters.</span>
                    </div>
                    <div class="owner-form-group">
                        <label for="staffPasswordConfirm">Confirm password</label>
                        <div class="owner-password-field">
                            <input type="password" id="staffPasswordConfirm" name="password_confirm" class="owner-input" autocomplete="new-password">
                            <button type="button" class="owner-password-toggle" data-toggle-for="staffPasswordConfirm" aria-label="Show password" aria-pressed="false">
                                <i class="ph ph-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelStaffForm">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    // -- Tabs (Staff / Customers) --------------------------------------------
    const tabs = document.querySelectorAll('[data-user-tab]');
    // Each tab owns TWO cards -- its filters card and its table card -- so the
    // toggle works off the shared data-user-panel attribute, not a single id.
    const panelCards = document.querySelectorAll('[data-user-panel]');

    function activateTab(key) {
        tabs.forEach(t => t.classList.toggle('is-active', t.getAttribute('data-user-tab') === key));
        panelCards.forEach((el) => {
            el.style.display = (el.getAttribute('data-user-panel') === key) ? '' : 'none';
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            activateTab(tab.getAttribute('data-user-tab'));
        });
    });

    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    if (requestedTab === 'customers') activateTab('customers');

    // -- Staff filters ---------------------------------------------------------
    const staffSearch     = document.getElementById('staffFilterSearch');
    const staffRole       = document.getElementById('staffFilterRole');
    const staffArchived   = document.getElementById('staffFilterArchived');
    const staffNoMatchRow = document.getElementById('staffNoMatchRow');
    const staffCountEl    = document.getElementById('staffCount');
    const staffRows       = Array.from(document.querySelectorAll('#user-panel-staff tbody tr[data-name]'));

    function applyStaffFilters() {
        const q        = (staffSearch ? staffSearch.value : '').trim().toLowerCase();
        const role     = staffRole ? staffRole.value : '';
        const archived = staffArchived ? staffArchived.value : '';
        let visibleCount = 0;

        staffRows.forEach((row) => {
            const matchesSearch   = !q || row.getAttribute('data-name').includes(q);
            const matchesRole     = !role || row.getAttribute('data-role') === role;
            const matchesArchived = !archived || row.getAttribute('data-active') === (archived === 'active' ? '1' : '0');
            const show = matchesSearch && matchesRole && matchesArchived;
            row.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        if (staffNoMatchRow) staffNoMatchRow.hidden = (staffRows.length === 0 || visibleCount > 0);
        if (staffCountEl) staffCountEl.textContent = visibleCount + (visibleCount === 1 ? ' account' : ' accounts');
    }

    if (staffSearch)   staffSearch.addEventListener('input', applyStaffFilters);
    if (staffRole)     staffRole.addEventListener('change', applyStaffFilters);
    if (staffArchived) staffArchived.addEventListener('change', applyStaffFilters);
    applyStaffFilters();

    // -- Customer filters --------------------------------------------------------
    const customerSearch     = document.getElementById('customerFilterSearch');
    const customerArchived   = document.getElementById('customerFilterArchived');
    const customerNoMatchRow = document.getElementById('customerNoMatchRow');
    const customerCountEl    = document.getElementById('customerCount');
    const customerRows       = Array.from(document.querySelectorAll('#user-panel-customers tbody tr[data-name]'));

    function applyCustomerFilters() {
        const q        = (customerSearch ? customerSearch.value : '').trim().toLowerCase();
        const archived = customerArchived ? customerArchived.value : '';
        let visibleCount = 0;

        customerRows.forEach((row) => {
            const matchesSearch   = !q || row.getAttribute('data-name').includes(q);
            const matchesArchived = !archived || row.getAttribute('data-active') === (archived === 'active' ? '1' : '0');
            const show = matchesSearch && matchesArchived;
            row.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        if (customerNoMatchRow) customerNoMatchRow.hidden = (customerRows.length === 0 || visibleCount > 0);
        if (customerCountEl) customerCountEl.textContent = visibleCount + (visibleCount === 1 ? ' customer' : ' customers');
    }

    if (customerSearch)   customerSearch.addEventListener('input', applyCustomerFilters);
    if (customerArchived) customerArchived.addEventListener('change', applyCustomerFilters);
    applyCustomerFilters();

    // -- Add / edit staff modal -------------------------------------------------
    const backdrop      = document.getElementById('staffFormBackdrop');
    const form           = document.getElementById('staffForm');
    const title           = document.getElementById('staffFormTitle');
    const userIdField     = document.getElementById('staffUserId');
    const passwordField   = document.getElementById('staffPassword');
    const passwordConfirm = document.getElementById('staffPasswordConfirm');
    const passwordLabel   = document.getElementById('staffPasswordLabel');
    // By id, like every other handle here. This used to walk the DOM --
    // passwordField.parentElement.querySelector('.owner-form-hint') -- which
    // stopped resolving the moment the input was wrapped in
    // .owner-password-field for the show/hide toggle: the hint is a SIBLING of
    // that wrapper, not a child, so the lookup returned null. Both entry points
    // then threw on `passwordHint.textContent` BEFORE reaching openModal(),
    // which is why Add staff account and Edit both did nothing at all.
    const passwordHint    = document.getElementById('staffPasswordHint');

    function openModal() {
        backdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
        const firstField = form.querySelector('input, select');
        if (firstField) firstField.focus();
    }

    function closeModal() {
        backdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    const passwordToggles = document.querySelectorAll('.owner-password-toggle');

    passwordToggles.forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.toggleFor);
            if (!input) return;
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            btn.querySelector('i').className = reveal ? 'ph ph-eye-slash' : 'ph ph-eye';
            btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        });
    });

    // form.reset() restores values but not the type attribute, so a revealed
    // field would stay readable the next time the modal opens. Both entry
    // points call this after resetting.
    function hidePasswords() {
        passwordToggles.forEach((btn) => {
            const input = document.getElementById(btn.dataset.toggleFor);
            if (input) input.type = 'password';
            btn.querySelector('i').className = 'ph ph-eye';
            btn.setAttribute('aria-label', 'Show password');
            btn.setAttribute('aria-pressed', 'false');
        });
    }

    function resetToAddMode() {
        form.reset();
        userIdField.value = '';
        title.textContent = 'Add staff account';
        passwordField.setAttribute('required', 'required');
        passwordConfirm.setAttribute('required', 'required');
        passwordLabel.textContent = 'Password';
        passwordHint.textContent = 'At least 8 characters.';
        hidePasswords();
    }

    document.getElementById('btnOpenAddStaff').addEventListener('click', () => {
        resetToAddMode();
        openModal();
    });

    document.querySelectorAll('.owner-btn-edit-staff').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.reset();
            userIdField.value = btn.getAttribute('data-user-id');
            document.getElementById('staffFirstName').value = btn.getAttribute('data-first-name');
            document.getElementById('staffLastName').value  = btn.getAttribute('data-last-name');
            document.getElementById('staffEmail').value     = btn.getAttribute('data-email');
            document.getElementById('staffRole').value      = btn.getAttribute('data-role');

            title.textContent = 'Edit staff account';
            passwordField.removeAttribute('required');
            passwordConfirm.removeAttribute('required');
            passwordLabel.textContent = 'New password';
            passwordHint.textContent = 'Leave blank to keep the current password.';
            hidePasswords();

            openModal();
        });
    });

    document.getElementById('btnCloseStaffForm').addEventListener('click', closeModal);
    document.getElementById('btnCancelStaffForm').addEventListener('click', closeModal);

    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
    });

    form.addEventListener('submit', (e) => {
        if (passwordField.value !== '' || passwordField.hasAttribute('required')) {
            if (passwordField.value.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                passwordField.focus();
                return;
            }
            if (passwordField.value !== passwordConfirm.value) {
                e.preventDefault();
                alert('Passwords do not match.');
                passwordConfirm.focus();
                return;
            }
        }
    });

    <?php if (!empty($_SESSION['_reopen_staff_modal'])): unset($_SESSION['_reopen_staff_modal']); ?>
    openModal();
    <?php endif; ?>
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
</body>
</html>
