<?php
/**
 * owner/profile.php
 *
 * "My profile" -- the logged-in owner's own identity fields (name, email,
 * phone, role, member-since). Self-service editing covers ONLY
 * first name, last name, and phone (profile_save.php) -- email and
 * password stay admin-only changes via admin/users.php, same as role_id
 * already was, so those two are always rendered read-only here regardless
 * of edit mode. Desktop layout splits into an identity summary card
 * (avatar/name/role) alongside a wider details card, rather than one
 * narrow centered card with empty space either side.
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
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'profile';
$pageTitle  = 'My Profile';
$ownerBase  = '';

$user    = null;
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>My Profile | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
<style>
    .op-profile-field{ display:flex; flex-direction:column; gap:4px; }
    .op-profile-field-label{ font-size:0.75rem; text-transform:uppercase; letter-spacing:0.03em; color:var(--op-ink-faint); }
    .op-profile-field-value{ font-size:0.95rem; color:var(--op-ink); }
    .op-profile-field-input{ display:none; }
    /* One card now, so a single centred column -- no row, no fixed 300px
       sidebar, and nothing to leave a stubby half beside a taller half. */
    /* Stacked on narrow screens, side by side once there's room for two
       comfortable columns. gap handles the spacing in both directions, which is
       why the password card no longer carries an inline margin-top -- that
       would have doubled the gap when stacked and pushed it out of line with
       the details card when side by side. */
    .op-profile-layout{ display:flex; flex-direction:column; gap:20px; max-width:720px; margin-inline:auto; align-items:stretch; }
    .op-profile-layout > .owner-card{ margin-top:0; }
    @media (min-width: 1100px){
        .op-profile-layout{ flex-direction:row; max-width:1160px; }
        .op-profile-layout > .owner-card{ flex:1; min-width:0; display:flex; flex-direction:column; }
    }
    .op-profile-identity{ display:flex; align-items:center; gap:14px; min-width:0; }
    .op-profile-identity .owner-card-title{ margin:0; }
    .op-profile-edit-actions{ display:none; gap:10px; margin-top:16px; }
    .op-profile-details.is-editing .op-profile-field-value{ display:none; }
    .op-profile-details.is-editing .op-profile-field-input{ display:block; width:100%; }
    .op-profile-details.is-editing .op-profile-edit-actions{ display:flex; }
    .op-profile-details.is-editing #btnEditProfile{ display:none; }

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

            <div class="op-profile-layout">

                <div class="owner-card op-profile-details" id="profileDetailsCard">
                    <div class="owner-card-head">
                        <?php /* Identity sits in this card's own header rather than a
                                 separate summary card beside it: that card held only the
                                 avatar, name and role, all three of which the topbar's
                                 user chip already shows a few pixels away, and its short
                                 height left the row visibly lopsided. Same shape the
                                 customer profile already uses. */ ?>
                        <div class="op-profile-identity">
                            <span class="owner-avatar" style="width:56px;height:56px;font-size:1.25rem;"><?= htmlspecialchars(Session::getInitials()) ?></span>
                            <div>
                                <h2 class="owner-card-title"><?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?></h2>
                                <span class="owner-card-subtitle"><?= htmlspecialchars(ucfirst($user['role_name'] ?? '')) ?></span>
                            </div>
                        </div>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnEditProfile">
                            <i class="ph ph-pencil-simple" aria-hidden="true"></i> Edit
                        </button>
                    </div>

                    <form method="POST" action="profile_save.php" id="profileForm">
                        <?= csrf_field() ?>
                        <div class="owner-form-grid" style="grid-template-columns: 1fr 1fr; margin-top: 14px;">
                            <div class="op-profile-field">
                                <span class="op-profile-field-label">First name</span>
                                <span class="op-profile-field-value"><?= htmlspecialchars($user['first_name']) ?></span>
                                <input type="text" name="first_name" class="owner-input op-profile-field-input" value="<?= htmlspecialchars($user['first_name']) ?>" maxlength="100" required>
                            </div>
                            <div class="op-profile-field">
                                <span class="op-profile-field-label">Last name</span>
                                <span class="op-profile-field-value"><?= htmlspecialchars($user['last_name']) ?></span>
                                <input type="text" name="last_name" class="owner-input op-profile-field-input" value="<?= htmlspecialchars($user['last_name']) ?>" maxlength="100" required>
                            </div>
                            <div class="op-profile-field" style="grid-column: span 2;">
                                <span class="op-profile-field-label">Email address</span>
                                <span class="op-profile-field-value"><?= htmlspecialchars($user['email']) ?></span>
                            </div>
                        </div>

                        <p class="owner-form-hint" style="margin-top:16px;">Email address can only be changed by an administrator.</p>

                        <div class="op-profile-edit-actions">
                            <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">Save changes</button>
                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnCancelProfileEdit">Cancel</button>
                        </div>
                    </form>
                </div>


                <div class="owner-card">
                    <h2 class="owner-card-title">Password</h2>
                    <p class="owner-form-hint" style="margin:4px 0 16px;">Change the password you use to sign in.</p>
                    <form method="POST" action="profile_password_save.php">
                        <?= csrf_field() ?>
                        <div class="owner-form-grid" style="grid-template-columns:1fr 1fr;">
                            <div class="owner-form-group" style="grid-column:1 / -1;">
                                <label for="current_password">Current password</label>
                                <input type="password" id="current_password" name="current_password" class="owner-input" required autocomplete="current-password">
                            </div>
                            <div class="owner-form-group">
                                <label for="new_password">New password</label>
                                <input type="password" id="new_password" name="new_password" class="owner-input" required minlength="8" autocomplete="new-password">
                                <span class="owner-form-hint">At least 8 characters.</span>
                            </div>
                            <div class="owner-form-group">
                                <label for="confirm_password">Confirm new password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="owner-input" required minlength="8" autocomplete="new-password">
                            </div>
                        </div>
                        <button type="submit" class="owner-btn owner-btn-primary" style="margin-top:16px;">
                            Update password
                        </button>
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
            card.querySelectorAll('.op-profile-field-input').forEach(function (el) { el.value = el.defaultValue; });
            card.classList.remove('is-editing');
        });
    }
})();
</script>
</body>
</html>
