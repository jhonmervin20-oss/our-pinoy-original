<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/login_throttle.php';

Session::start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $db = Database::getInstance()->getConnection();
            $ip = client_ip();

            // Counted in the login_attempts table rather than in $_SESSION.
            // The old session counter reset itself the moment a caller
            // discarded their cookie — no cookie means a brand new session,
            // which means a count of zero on every single request, so it
            // stopped nobody who wasn't using a browser normally.
            $resetThrottle = resetRequestThrottleState($db, $email, $ip);

            if ($resetThrottle['locked']) {
                $errors[] = 'Too many requests. Please try again in '
                    . formatRetryAfter($resetThrottle['retry_after']) . '.';
            } else {
                recordResetRequest($db, $email, $ip);
                $stmt = $db->prepare(
                    "SELECT u.user_id, u.first_name, u.email, u.password_hash, r.role_name
                     FROM users u
                     JOIN roles r ON r.role_id = u.role_id
                     WHERE u.email = ? LIMIT 1"
                );
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // Self-service is customer-only (matches the DB trigger
                // trg_password_reset_customer_only) -- staff are reset by an
                // admin. A NULL password_hash is allowed through too: it's the
                // same "prove you own this email, then set a password" flow,
                // just naming the account's very first password instead of
                // replacing one -- reset_password.php's own form already reads
                // either way, it just says "Set a new password".
                if ($user && $user['role_name'] === 'customer') {
                    $code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash  = hash('sha256', $code);
                    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

                    // Invalidate any older unused codes for this user first
                    $inv = $db->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                    $inv->execute([$user['user_id']]);

                    $ins = $db->prepare(
                        "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, requested_ip)
                         VALUES (?, ?, ?, ?)"
                    );
                    $ins->execute([
                        $user['user_id'],
                        $codeHash,
                        $expiresAt,
                        $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);

                    $appName = getenv('APP_NAME') ?: 'OPO! - Our Pinoy Original';
                    $html = "
                        <div style='font-family:sans-serif;max-width:480px;margin:auto;'>
                            <h2 style='color:#9c7734;'>Your password reset code</h2>
                            <p>Hi " . htmlspecialchars($user['first_name']) . ",</p>
                            <p>Use this code to reset your {$appName} account password. It expires in 10 minutes.</p>
                            <p style='font-size:32px;font-weight:bold;letter-spacing:8px;color:#191410;background:#f5efe4;padding:16px 24px;border-radius:8px;text-align:center;'>{$code}</p>
                            <p>If you didn't request this, you can safely ignore this email.</p>
                        </div>
                    ";

                    Mailer::send($user['email'], $user['first_name'], "Your {$appName} password reset code", $html);

                    // Remember which user this pending reset belongs to,
                    // so reset_password.php doesn't need the user id in the URL.
                    $_SESSION['reset_pending_user_id'] = $user['user_id'];
                    $_SESSION['reset_pending_email']   = $user['email'];
                }

                // Always redirect to the same next step, whether or not the
                // email was found — this prevents account enumeration.
                header('Location: reset_password.php');
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
<title>Forgot Password | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
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
            <span>No worries — we'll email you a code so you can get right back to your account.</span>
            <div class="auth-visual-rule"></div>
        </div>
    </div>

    <div class="auth-form-side">
    <div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-title">Forgot your password?</h1>
        <p class="auth-subtitle">Enter the email on your account and we'll send you a 6-digit code to reset it.</p>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="forgot_password.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="form-group">
                <label for="email">Email</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-envelope leading-icon"></i>
                    <input type="email" class="form-control has-leading" id="email" name="email"
                           value="<?= htmlspecialchars($email) ?>" placeholder="you@example.com" required autofocus data-email-check>
                </div>
                <div class="validation-hint"></div>
            </div>
            <div class="form-row-between">
                <span>Remember your password? <a href="login.php" style="font-weight:700">Back to Log in</a></span>
            </div>
            <button type="submit" class="btn-auth">Send Reset Code</button>
        </form>
    </div>
    </div>
    </div>
</div>
<script src="auth-interactions.js"></script>
</body>
</html>