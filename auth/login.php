<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/login_throttle.php';

Session::start();

// Already logged in? Bounce to the right place for their role.
if (Session::isLoggedIn()) {
    header('Location: ' . redirectForRole(Session::getRole()));
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$email  = '';

// Pull one-time flash messages (e.g. "password reset successful")
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

/**
 * Where each role should land after logging in. All five roles in the `roles`
 * table are covered, so the fallback is only reachable if a user somehow has
 * no role name at all -- in which case the public landing page is the only
 * safe destination, since we can't assume any level of access.
 */
function redirectForRole(?string $roleName): string
{
    $map = [
        'admin'    => '../admin/dashboard.php',
        'owner'    => '../owner/dashboard.php',
        'manager'  => '../manager/dashboard.php',
        'cashier'  => '../cashier/pos.php',
        'customer' => '../customer/dashboard.php',
    ];

    return $map[$roleName] ?? '../index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $errors[] = 'Please enter both your email and password.';
        } else {
            $db = Database::getInstance()->getConnection();
            $ip = client_ip();

            // Checked BEFORE the user lookup so a locked-out caller costs one
            // indexed COUNT and nothing else -- no row read, no bcrypt round.
            $throttle = loginThrottleState($db, $email, $ip);

            if ($throttle['locked']) {
                $errors[] = 'Too many failed login attempts. Please try again in '
                    . formatRetryAfter($throttle['retry_after']) . '.';
            } else {
                $stmt = $db->prepare(
                    "SELECT u.user_id, u.first_name, u.last_name, u.email,
                            u.password_hash, u.is_active, u.role_id, r.role_name
                     FROM   users u
                     JOIN   roles r ON r.role_id = u.role_id
                     WHERE  u.email = ?
                     LIMIT 1"
                );
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // Generic error message on purpose — never reveal whether the
                // email exists, whether it has a password set, etc.
                $invalidMsg = 'Incorrect email or password.';

                if (!$user || empty($user['password_hash'])) {
                    // Deliberately still pay for a bcrypt round against a
                    // dummy hash. Returning here without one made an unknown
                    // address answer far faster than a known one, which is an
                    // account enumeration oracle — see LOGIN_DUMMY_HASH.
                    password_verify($password, LOGIN_DUMMY_HASH);
                    recordFailedLogin($db, $email, $ip);
                    noteLockoutIfJustTripped($db, $email, $ip);
                    $errors[] = $invalidMsg;
                } elseif (!password_verify($password, $user['password_hash'])) {
                    recordFailedLogin($db, $email, $ip);
                    noteLockoutIfJustTripped($db, $email, $ip);
                    $errors[] = $invalidMsg;
                } elseif (!$user['is_active']) {
                    $errors[] = 'This account has been deactivated. Please contact us for help.';
                } else {
                    // Clear this pair's failures before anything else — a
                    // legitimate user who mistyped twice and then got in
                    // leaves no history to count against their next attempt.
                    clearLoginAttempts($db, $email, $ip);

                    Session::login($user);

                    // Re-hash if PHP's default algorithm/cost has changed since this hash was made
                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        $rehash = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                        $upd->execute([$rehash, $user['user_id']]);
                    }

                    header('Location: ' . redirectForRole($user['role_name']));
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="auth-style.css?v=<?= filemtime(__DIR__ . '/auth-style.css') ?>">
</head>
<body>

<div class="auth-page">
    <div class="auth-visual" aria-hidden="true">
        <div class="auth-visual-glow"></div>
        <div class="auth-visual-caption">
            <img src="../assets/images/logo.jpg" alt="OPO! Our Pinoy Original" class="auth-visual-logo">
            <strong>Our Pinoy Original</strong>
            <span>Log in to book and manage your reservations, and feel the comfort of Filipinon foods.</span>
            <div class="auth-visual-rule"></div>
        </div>
    </div>

    <div class="auth-form-side">
    <div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-title">Log In</h1>
        <p class="auth-subtitle">Log in to book and track your reservations.</p>

        <?php if ($flashSuccess): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
        <?php endif; ?>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-envelope leading-icon"></i>
                    <input type="email" class="form-control has-leading" id="email" name="email"
                           value="<?= htmlspecialchars($email) ?>" placeholder="juan_delacruz@example.com" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-field">
                    <i class="fa-solid fa-lock leading-icon"></i>
                    <input type="password" class="form-control has-leading has-icon" id="password" name="password" placeholder="••••••••" required>
                    <button type="button" class="password-toggle" data-toggle-for="password" aria-label="Show password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-row-between">
               <a href="forgot_password.php">Forgot password?</a>
            </div>

            <button type="submit" class="btn-auth">Log In</button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="sign_up.php">Sign up</a>
        </div>
    </div>
    </div>
    </div>
</div>

<script>
// Login is usually reached by a "not logged in" bounce from a protected page
// (every module's own guard does header('Location: .../auth/login.php')),
// so the browser's Back button would otherwise return to that protected
// page -- which just bounces straight back here since the session still
// isn't valid, or shows a stale bfcache'd copy that looks logged in but
// isn't. Sending Back to index.php instead is a real destination either way.
history.pushState(null, '', location.href);
window.addEventListener('popstate', function () {
    window.location.replace('../index.php');
});

document.querySelectorAll('.password-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.toggleFor);
        const icon  = btn.querySelector('i');
        const show  = input.type === 'password';
        input.type  = show ? 'text' : 'password';
        icon.classList.toggle('fa-eye', !show);
        icon.classList.toggle('fa-eye-slash', show);
    });
});
</script>
<script src="auth-interactions.js"></script>
</body>
</html>