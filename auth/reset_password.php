<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

Session::start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Must have come from forgot_password.php with a pending request
if (empty($_SESSION['reset_pending_user_id']) || empty($_SESSION['reset_pending_email'])) {
    header('Location: forgot_password.php');
    exit;
}

$pendingUserId = (int)$_SESSION['reset_pending_user_id'];
$pendingEmail  = (string)$_SESSION['reset_pending_email'];

function maskEmail(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if (strlen($name) <= 2) {
        $masked = substr($name, 0, 1) . '***';
    } else {
        $masked = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 2, 3));
    }
    return $masked . '@' . $domain;
}

const MAX_ATTEMPTS = 5;

$db     = Database::getInstance()->getConnection();
$errors = [];

// Step 1 = enter + verify the 6-digit code.
// Step 2 = code already verified this session -> set a new password, or skip.
$codeVerified = !empty($_SESSION['reset_verified_token_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';

    } elseif (!$codeVerified) {

        // ---------------- STEP 1: verify the code ----------------
        $code = trim($_POST['code'] ?? '');

        if ($code === '' || !ctype_digit($code) || strlen($code) !== 6) {
            $errors[] = 'Please enter the 6-digit code we emailed you.';
        } else {
            $stmt = $db->prepare(
                "SELECT token_id, token_hash, attempts, expires_at, used_at
                 FROM password_reset_tokens
                 WHERE user_id = ?
                 ORDER BY token_id DESC
                 LIMIT 1"
            );
            $stmt->execute([$pendingUserId]);
            $row = $stmt->fetch();

            if (!$row || $row['used_at'] !== null) {
                $errors[] = 'This code is no longer valid. Please request a new one.';
            } elseif (strtotime($row['expires_at']) < time()) {
                $errors[] = 'This code has expired. Please request a new one.';
            } elseif ((int)$row['attempts'] >= MAX_ATTEMPTS) {
                $errors[] = 'Too many incorrect attempts. Please request a new code.';
            } elseif (!hash_equals($row['token_hash'], hash('sha256', $code))) {
                $upd = $db->prepare("UPDATE password_reset_tokens SET attempts = attempts + 1 WHERE token_id = ?");
                $upd->execute([$row['token_id']]);
                $left = MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
                $errors[] = $left > 0
                    ? "Incorrect code. {$left} attempt" . ($left === 1 ? '' : 's') . ' remaining.'
                    : 'Too many incorrect attempts. Please request a new code.';
            } else {
                // Correct code — consume it immediately so it can't be reused,
                // and move on to step 2.
                $mark = $db->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE token_id = ?");
                $mark->execute([$row['token_id']]);

                $_SESSION['reset_verified_token_id'] = $row['token_id'];
                $codeVerified = true;
            }
        }

    } else {

        // ---------------- STEP 2: change password, or skip ----------------
        $action = $_POST['action'] ?? '';

        if ($action === 'skip') {
            $stmt = $db->prepare(
                "SELECT u.user_id, u.first_name, u.last_name, u.email, u.role_id, r.role_name
                 FROM users u JOIN roles r ON r.role_id = u.role_id
                 WHERE u.user_id = ? LIMIT 1"
            );
            $stmt->execute([$pendingUserId]);
            $user = $stmt->fetch();

            if ($user) {
                Session::login($user);
                unset(
                    $_SESSION['reset_pending_user_id'],
                    $_SESSION['reset_pending_email'],
                    $_SESSION['reset_requests'],
                    $_SESSION['reset_verified_token_id']
                );
                header('Location: ../customer/dashboard.php');
                exit;
            }
            $errors[] = 'Something went wrong. Please try again.';

        } else {
            $password  = (string)($_POST['password'] ?? '');
            $password2 = (string)($_POST['password_confirm'] ?? '');

            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            } elseif ($password !== $password2) {
                $errors[] = 'Passwords do not match.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd  = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $upd->execute([$hash, $pendingUserId]);

                unset(
                    $_SESSION['reset_pending_user_id'],
                    $_SESSION['reset_pending_email'],
                    $_SESSION['reset_requests'],
                    $_SESSION['reset_verified_token_id']
                );
                $_SESSION['flash_success'] = 'Your password has been updated. You can now log in.';
                header('Location: login.php');
                exit;
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
<title>Reset Password | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="auth-style.css?v=<?= filemtime(__DIR__ . '/auth-style.css') ?>">
<style>
.code-input{
    width:100%;
    text-align:center;
    font-size:1.6rem;
    letter-spacing:14px;
    font-weight:700;
    font-family:'Lexend',sans-serif;
    padding:14px 10px 14px 24px; /* extra left padding to visually center with letter-spacing */
}
.btn-skip{
    display:block;
    width:100%;
    text-align:center;
    margin-top:12px;
    padding:12px;
    border-radius:8px;
    border:1px solid var(--gold-light, #9c7734);
    background:transparent;
    color:var(--gold-light, #9c7734);
    font-family:'Poppins',sans-serif;
    font-size:0.95rem;
    font-weight:500;
    cursor:pointer;
}
.btn-skip:hover{ background:rgba(156,119,52,0.08); }
.verified-badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:0.8rem;
    color:#2e7d32;
    background:rgba(46,125,50,0.1);
    padding:6px 12px;
    border-radius:20px;
    margin-bottom:14px;
}
</style>
</head>
<body>

<div class="auth-page">
    <div class="auth-visual" aria-hidden="true">
        <div class="auth-visual-glow"></div>
        <div class="auth-visual-caption">
            <img src="../assets/images/logo.jpg" alt="OPO! Our Pinoy Original" class="auth-visual-logo">
            <strong>Our Pinoy Original</strong>
            <span>Verify your code and set a fresh password to get back into your account.</span>
            <div class="auth-visual-rule"></div>
        </div>
    </div>

    <div class="auth-form-side">
    <div class="auth-wrap">
    <div class="auth-card">
       
        <?php if (!$codeVerified): ?>

            <h1 class="auth-title">Enter your code</h1>
            <p class="auth-subtitle">
                We sent a 6-digit code to <strong style="color:var(--gold-light);"><?= htmlspecialchars(maskEmail($pendingEmail)) ?></strong>.
                It expires in 10 minutes.
            </p>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <form method="POST" action="reset_password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-group">
                    <label for="code">6-Digit Code</label>
                    <input type="text" class="form-control code-input" id="code" name="code"
                           inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="------" required autofocus>
                </div>

                <button type="submit" class="btn-auth">Verify Code</button>
            </form>

            <div class="auth-footer">
                Didn't get a code? <a href="forgot_password.php">Send another</a>
            </div>

            <script>
            document.getElementById('code').addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
            });
            </script>

        <?php else: ?>

            <div class="verified-badge"><i class="fa-solid fa-circle-check"></i> Code verified</div>

            <h1 class="auth-title">Set a new password</h1>
            <p class="auth-subtitle">
                You're verified for <strong style="color:var(--gold-light);"><?= htmlspecialchars(maskEmail($pendingEmail)) ?></strong>.
                Set a new password now, or skip and log in with your current one.
            </p>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <form method="POST" action="reset_password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="set_password">

                <div class="form-group">
                    <label for="password">New Password</label>
                    <div class="password-field">
                        <input type="password" class="form-control" id="password" name="password" placeholder="At least 8 characters" required minlength="8">
                        <button type="button" class="password-toggle" data-toggle-for="password" aria-label="Show password">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirm New Password</label>
                    <div class="password-field">
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" placeholder="Repeat password" required minlength="8">
                        <button type="button" class="password-toggle" data-toggle-for="password_confirm" aria-label="Show password">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-auth">Update Password</button>
            </form>

            <div class="divider">or</div>

            <form method="POST" action="reset_password.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="skip">
                <button type="submit" class="btn-skip">Skip for now — log me in</button>
            </form>

            <script>
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

        <?php endif; ?>
    </div>
    </div>
    </div>
</div>
<script src="auth-interactions.js"></script>
</body>
</html>