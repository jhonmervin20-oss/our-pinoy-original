<?php
/**
 * customer/profile.php
 *
 * "My profile" -- fully self-editable, unlike the staff panels
 * (owner/manager/cashier/profile.php), which restrict self-editing to
 * name/phone because a real admin exists to change anyone's
 * email/password for them. Customers have no such admin to fall back on
 * (self-registered via auth/sign_up.php), so first name, last name, email,
 * and phone are all editable here (profile_save.php), and password
 * gets its own separate change form (profile_password_save.php) -- an
 * account with no password_hash yet gets a "set a password" form instead of
 * the normal "change password" one.
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
if (!Session::hasRole(['customer'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'profile';
$customerId = Session::getUserId();

$user    = null;
$dbError = null;
$hasPassword = false;

try {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare(
        "SELECT first_name, last_name, email, phone, password_hash IS NOT NULL AS has_password
         FROM users WHERE user_id = ?"
    );
    $stmt->execute([$customerId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasPassword = !empty($user['has_password']);
} catch (PDOException $e) {
    $dbError = "Couldn't load your profile. Please refresh this page.";
}

if (!$user) {
    $user = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>My Profile | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">
<style>
    .ca-profile-head{ display:flex; align-items:center; gap:14px; margin-bottom:18px; }
    .ca-profile-avatar{ width:56px; height:56px; border-radius:50%; background:var(--ca-gold); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.3rem; flex-shrink:0; }
    .ca-profile-field-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .ca-profile-field{ display:flex; flex-direction:column; gap:4px; }
    .ca-profile-field.is-full{ grid-column:1 / -1; }
    .ca-profile-field-label{ font-size:0.75rem; text-transform:uppercase; letter-spacing:0.03em; color:var(--ca-ink-faint, #8a8072); }
    .ca-profile-field-value{ font-size:0.95rem; color:var(--ca-ink, #2a2318); }
    .ca-profile-field-input{ display:none; }
    @media (max-width:640px){ .ca-profile-field-grid{ grid-template-columns:1fr; } }
    /* Small helper line under an input. No .ca-form-hint exists in the shared
       customer stylesheets, so it's defined here rather than left unstyled. */
    .ca-form-hint{ display:block; margin-top:5px; font-size:0.76rem; color:var(--ca-ink-faint, #8a8072); }
    .ca-alert{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:10px; margin-bottom:16px; font-size:0.9rem; }
    .ca-alert-error{ background:rgba(200,60,60,0.1); color:#a83a3a; }
    .ca-profile-edit-actions{ display:none; gap:10px; margin-top:18px; }
    .ca-profile-card.is-editing .ca-profile-field-value{ display:none; }
    .ca-profile-card.is-editing .ca-profile-field-input{ display:block; }
    .ca-profile-card.is-editing .ca-profile-edit-actions{ display:flex; }
    .ca-profile-card.is-editing #btnEditProfile{ display:none; }
    /* One centred column for the whole page -- heading, flash and cards alike.
       Centring only the cards (as a first attempt did) left the "My Profile"
       heading pinned to .ca-content's left edge while the cards sat in the
       middle, so the two no longer lined up. The width lives here, on their
       shared parent, which is the only place that keeps them in step. */
    .ca-profile-page{ max-width:640px; margin-inline:auto; }
    @media (min-width: 1000px){
        .ca-profile-page{ max-width:1000px; }
    }
    .ca-profile-layout{ display:flex; flex-direction:column; gap:20px; }
    @media (min-width: 1000px){
        .ca-profile-layout{ flex-direction:row; align-items:flex-start; }
        .ca-profile-layout > .ca-card{ flex:1; margin-top:0 !important; }
    }
</style>
</head>
<body class="ca-body">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="ca-content">
  <div class="ca-profile-page">
    <div class="ca-res-page-head">
        <div>
            <h1 class="ca-welcome-title">My Profile</h1>
            <p class="ca-welcome-sub">Your account information.</p>
        </div>
    </div>

    <?= flash_render() ?>

    <?php if ($dbError): ?>
        <div class="ca-alert ca-alert-error">
            <i class="ph ph-warning-circle" aria-hidden="true"></i>
            <span><?= htmlspecialchars($dbError) ?></span>
        </div>
    <?php endif; ?>

    <div class="ca-profile-layout">

    <div class="ca-card ca-profile-card" id="profileDetailsCard">
        <div class="ca-profile-head">
            <span class="ca-profile-avatar"><?= htmlspecialchars(Session::getInitials()) ?></span>
            <div style="flex:1;">
                <h2 class="ca-card-title" style="margin-bottom:2px;"><?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?></h2>
                <span class="ca-welcome-sub" style="margin:0;">Customer</span>
            </div>
            <button type="button" class="ca-btn ca-btn-secondary" id="btnEditProfile">
                <i class="ph ph-pencil-simple" aria-hidden="true"></i> Edit
            </button>
        </div>

        <form method="POST" action="profile_save.php">
            <?= csrf_field() ?>
            <div class="ca-profile-field-grid">
                <div class="ca-profile-field">
                    <span class="ca-profile-field-label">First name</span>
                    <span class="ca-profile-field-value"><?= htmlspecialchars($user['first_name']) ?></span>
                    <input type="text" name="first_name" class="ca-input ca-profile-field-input" value="<?= htmlspecialchars($user['first_name']) ?>" maxlength="100" required>
                </div>
                <div class="ca-profile-field">
                    <span class="ca-profile-field-label">Last name</span>
                    <span class="ca-profile-field-value"><?= htmlspecialchars($user['last_name']) ?></span>
                    <input type="text" name="last_name" class="ca-input ca-profile-field-input" value="<?= htmlspecialchars($user['last_name']) ?>" maxlength="100" required>
                </div>
                <div class="ca-profile-field is-full">
                    <span class="ca-profile-field-label">Email address</span>
                    <span class="ca-profile-field-value"><?= htmlspecialchars($user['email']) ?></span>
                    <input type="email" name="email" class="ca-input ca-profile-field-input" value="<?= htmlspecialchars($user['email']) ?>" maxlength="150" required>
                </div>
                <div class="ca-profile-field">
                    <span class="ca-profile-field-label">Phone</span>
                    <span class="ca-profile-field-value"><?= htmlspecialchars(phoneOrNa($user['phone'])) ?></span>
                    <input type="text" name="phone" class="ca-input ca-profile-field-input" value="<?= htmlspecialchars((string)$user['phone']) ?>" maxlength="20" placeholder="Optional">
                </div>
            </div>

            <div class="ca-profile-edit-actions">
                <button type="submit" class="ca-btn ca-btn-primary">Save changes</button>
                <button type="button" class="ca-btn ca-btn-secondary" id="btnCancelProfileEdit">Cancel</button>
            </div>
        </form>
    </div>

    <div class="ca-card">
        <h2 class="ca-card-title">Password</h2>
        <?php if ($hasPassword): ?>
            <p class="ca-welcome-sub" style="margin:4px 0 16px;">Change the password used to log in.</p>
            <form method="POST" action="profile_password_save.php">
                <?= csrf_field() ?>
                <div class="ca-form-group">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" class="ca-input" required autocomplete="current-password">
                </div>
                <div class="ca-form-group">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" class="ca-input" required minlength="8" autocomplete="new-password">
                </div>
                <div class="ca-form-group">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="ca-input" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="ca-btn ca-btn-primary">
                    Update password
                </button>
            </form>
        <?php else: ?>
            <?php /* No password set yet. Rather than a dead end, offer to add
                     one -- there is no current-password field since there is
                     nothing to verify against, and the live session is already
                     proof of identity. */ ?>
            <p class="ca-welcome-sub" style="margin:4px 0 16px;">This account doesn't have a password set yet. Set one below to sign in with your email address.</p>
            <form method="POST" action="profile_password_save.php">
                <?= csrf_field() ?>
                <div class="ca-form-group">
                    <label for="set_password">New password</label>
                    <input type="password" id="set_password" name="new_password" class="ca-input" required minlength="8" autocomplete="new-password">
                    <span class="ca-form-hint">At least 8 characters.</span>
                </div>
                <div class="ca-form-group">
                    <label for="set_confirm_password">Confirm password</label>
                    <input type="password" id="set_confirm_password" name="confirm_password" class="ca-input" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="ca-btn ca-btn-primary">
                    Set password
                </button>
            </form>
        <?php endif; ?>
    </div>

    </div>
  </div>
</main>

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
            card.querySelectorAll('.ca-profile-field-input').forEach(function (el) { el.value = el.defaultValue; });
            card.classList.remove('is-editing');
        });
    }
})();
</script>
</body>
</html>
