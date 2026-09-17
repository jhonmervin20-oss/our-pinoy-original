<?php
/**
 * cashier/profile.php
 *
 * "My profile" -- the logged-in cashier's own identity fields (name,
 * email, phone, role, member-since). Same self-service scope as
 * owner/manager/profile.php: first name, last name, and phone are
 * editable (profile_save.php); email and password stay admin-only changes
 * via admin/users.php. Built with this module's own pos-* shell/classes
 * (not owner-panel.css's owner-* card grid) to match the rest of the
 * cashier module's visual language, but mirrors owner/manager/profile.php's
 * two-card layout (identity summary card + details card) rather than one
 * combined card, so the page reads the same across roles.
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
if (!Session::hasRole(['cashier'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'profile';

$restaurantName = null;
$user    = null;
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $restaurantName = $pdo->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
    )->fetchColumn() ?: 'OPO! Our Pinoy Original';

    $stmt = $pdo->prepare(
        "SELECT u.first_name, u.last_name, u.email, u.phone, r.role_name
         FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ?"
    );
    $stmt->execute([Session::getUserId()]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load your profile. Please refresh this page.";
}

if (!$user) {
    $user = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'role_name' => Session::getRole()];
}
$restaurantName = $restaurantName ?: 'OPO! Our Pinoy Original';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>My Profile | <?= htmlspecialchars($restaurantName) ?></title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/pos.css?v=<?= filemtime(__DIR__ . '/assets/css/pos.css') ?>">
<style>
    .pos-profile-avatar{ width:56px; height:56px; border-radius:50%; background:var(--op-gold); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.3rem; flex-shrink:0; }
    .pos-profile-field-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .pos-profile-field.is-full{ grid-column:1 / -1; }
    .pos-profile-field{ display:flex; flex-direction:column; gap:4px; }
    .pos-profile-field label{ font-size:0.75rem; text-transform:uppercase; letter-spacing:0.03em; color:var(--op-ink-faint); }
    .pos-profile-field-value{ font-size:0.95rem; color:var(--op-ink); }
    .pos-profile-field-input{ display:none; }
    @media (max-width:640px){ .pos-profile-field-grid{ grid-template-columns:1fr; } }
    .pos-profile-edit-actions{ display:none; gap:10px; margin-top:18px; }
    .pos-report-card.is-editing .pos-profile-field-value{ display:none; }
    .pos-report-card.is-editing .pos-profile-field-input{ display:block; }
    .pos-report-card.is-editing .pos-profile-edit-actions{ display:flex; }
    .pos-report-card.is-editing #btnEditProfile{ display:none; }
    /* One card now, so a single centred column -- no row, no fixed 300px
       sidebar, and nothing to leave a stubby half beside a taller half. */
    .pos-profile-layout{ display:flex; flex-direction:column; gap:20px; max-width:720px; margin-inline:auto; align-items:stretch; }
    @media (min-width: 1100px){
        .pos-profile-layout{ flex-direction:row; max-width:1160px; }
        .pos-profile-layout > .pos-report-card{ flex:1; min-width:0; display:flex; flex-direction:column; margin-bottom:0; }
    }
    .pos-profile-identity{ display:flex; align-items:center; gap:14px; min-width:0; }
    .pos-profile-identity .pos-report-card-title{ margin:0; }
    .pos-profile-layout .pos-report-card-head{ align-items:flex-start; gap:16px; margin-bottom:4px; }
    .pos-profile-layout .pos-report-card-title{ font-size:1.02rem; margin:0 0 4px; }
    .pos-profile-layout .pos-checkout-btn{ font-family:inherit; }
</style>
</head>
<body>

<div class="pos-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="pos-content">

        <button type="button" class="pos-icon-btn pos-menu-toggle" id="posMenuToggle" aria-label="Open menu">
            <i class="ph ph-list" aria-hidden="true"></i>
        </button>

        <?= flash_render() ?>

        <?php if ($dbError): ?>
            <div class="pos-report-alert">
                <i class="ph ph-warning-circle" aria-hidden="true"></i>
                <span><?= htmlspecialchars($dbError) ?></span>
            </div>
        <?php endif; ?>

        <main class="pos-report-main">

            <div class="pos-profile-layout">

                <div class="pos-report-card pos-profile-details" id="profileDetailsCard">
                    <div class="pos-report-card-head">
                        <?php /* Identity lives in this card's header rather than a separate
                                 summary card: that card held only the avatar, name and role,
                                 which the topbar already shows, and its short height left
                                 the row lopsided. Matches the other profile pages. */ ?>
                        <div class="pos-profile-identity">
                            <span class="pos-profile-avatar" style="width:56px;height:56px;font-size:1.25rem;"><?= htmlspecialchars(Session::getInitials()) ?></span>
                            <div>
                                <h2 class="pos-report-card-title"><?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?></h2>
                                <span class="pos-report-card-subtitle"><?= htmlspecialchars(ucfirst($user['role_name'] ?? '')) ?></span>
                            </div>
                        </div>
                        <button type="button" class="pos-btn-secondary" style="flex:0 0 auto;padding:8px 14px;" id="btnEditProfile">
                            <i class="ph ph-pencil-simple" aria-hidden="true"></i> Edit
                        </button>
                    </div>

                    <form method="POST" action="profile_save.php">
                        <?= csrf_field() ?>
                        <div class="pos-profile-field-grid">
                            <div class="pos-shift-form-group pos-profile-field">
                                <label>First name</label>
                                <span class="pos-profile-field-value"><?= htmlspecialchars($user['first_name']) ?></span>
                                <input type="text" name="first_name" class="pos-profile-field-input" value="<?= htmlspecialchars($user['first_name']) ?>" maxlength="100" required>
                            </div>
                            <div class="pos-shift-form-group pos-profile-field">
                                <label>Last name</label>
                                <span class="pos-profile-field-value"><?= htmlspecialchars($user['last_name']) ?></span>
                                <input type="text" name="last_name" class="pos-profile-field-input" value="<?= htmlspecialchars($user['last_name']) ?>" maxlength="100" required>
                            </div>
                            <div class="pos-shift-form-group pos-profile-field is-full">
                                <label>Email address</label>
                                <span class="pos-profile-field-value"><?= htmlspecialchars($user['email']) ?></span>
                            </div>
                        </div>

                        <p class="pos-modal-hint" style="margin-top:16px;">Email address can only be changed by an administrator.</p>

                        <div class="pos-profile-edit-actions">
                            <button type="submit" class="pos-checkout-btn" style="width:auto;padding:10px 20px;">Save changes</button>
                            <button type="button" class="pos-btn-secondary" style="flex:0 0 auto;padding:10px 20px;" id="btnCancelProfileEdit">Cancel</button>
                        </div>
                    </form>
                </div>

                <div class="pos-report-card">
                    <h2 class="pos-report-card-title">Password</h2>
                    <p class="pos-modal-hint" style="margin:4px 0 16px;">Change the password you use to sign in.</p>
                    <form method="POST" action="profile_password_save.php">
                        <?= csrf_field() ?>
                        <div class="pos-profile-field-grid">
                            <div class="pos-profile-field" style="grid-column:1 / -1;">
                                <label for="current_password">Current password</label>
                                <input type="password" id="current_password" name="current_password" class="pos-input" required autocomplete="current-password">
                            </div>
                            <div class="pos-profile-field">
                                <label for="new_password">New password</label>
                                <input type="password" id="new_password" name="new_password" class="pos-input" required minlength="8" autocomplete="new-password">
                                <span class="pos-modal-hint">At least 8 characters.</span>
                            </div>
                            <div class="pos-profile-field">
                                <label for="confirm_password">Confirm new password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="pos-input" required minlength="8" autocomplete="new-password">
                            </div>
                        </div>
                        <button type="submit" class="pos-checkout-btn" style="width:auto;padding:10px 20px;margin-top:16px;">Update password</button>
                    </form>
                </div>

            </div>

        </main>

    </div>

</div>

<script>
(function () {
    var card = document.getElementById('profileDetailsCard');
    var editBtn = document.getElementById('btnEditProfile');
    var cancelBtn = document.getElementById('btnCancelProfileEdit');
    if (editBtn) {
        editBtn.addEventListener('click', function () {
            card.classList.add('is-editing');
        });
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function (e) {
            e.preventDefault();
            card.querySelectorAll('.pos-profile-field-input').forEach(function (el) { el.value = el.defaultValue; });
            card.classList.remove('is-editing');
        });
    }

    var menuToggle = document.getElementById('posMenuToggle');
    var sidebar = document.getElementById('posSidebar');
    var backdrop = document.getElementById('posSidebarBackdrop');
    if (menuToggle && sidebar && backdrop) {
        menuToggle.addEventListener('click', function () {
            sidebar.classList.toggle('is-open');
            backdrop.classList.toggle('is-visible');
        });
        backdrop.addEventListener('click', function () {
            sidebar.classList.remove('is-open');
            backdrop.classList.remove('is-visible');
        });
    }
})();
</script>
</body>
</html>
