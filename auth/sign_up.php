<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

Session::start();

// Already logged in? Hand off to login.php, which owns the role -> landing
// page mapping (redirectForRole()) and bounces an authenticated visitor
// straight on to their own dashboard. Duplicating that map here would mean a
// staff member who wandered onto the sign-up page got sent to the customer
// dashboard and bounced again by its role check.
if (Session::isLoggedIn()) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Public sign-up always creates a customer account, matching the
// customer-only rule already enforced for password reset.
const CUSTOMER_ROLE_ID = 5;

$errors    = [];
$firstName = '';
$lastName  = '';
$email     = '';
$phone     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $password  = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password_confirm'] ?? '');

        if ($firstName === '' || mb_strlen($firstName) > 100) {
            $errors[] = 'Please enter your first name.';
        }
        if ($lastName === '' || mb_strlen($lastName) > 100) {
            $errors[] = 'Please enter your last name.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            $errors[] = 'Please enter a valid phone number, or leave it blank.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        } elseif ($password !== $password2) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            $db = Database::getInstance()->getConnection();

            // Friendly duplicate-email check up front (email is UNIQUE in the schema)
            $check = $db->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $check->execute([$email]);

            if ($check->fetch()) {
                $errors[] = 'An account with this email already exists. Try logging in instead.';
            } else {
                try {
                    $ins = $db->prepare(
                        "INSERT INTO users (role_id, first_name, last_name, email, phone, password_hash, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, 1)"
                    );
                    $ins->execute([
                        CUSTOMER_ROLE_ID,
                        $firstName,
                        $lastName,
                        $email,
                        $phone !== '' ? $phone : null,
                        password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    $newUserId = (int)$db->lastInsertId();

                    Session::login([
                        'user_id'    => $newUserId,
                        'first_name' => $firstName,
                        'last_name'  => $lastName,
                        'email'      => $email,
                        'role_id'    => CUSTOMER_ROLE_ID,
                        'role_name'  => 'customer',
                    ]);

                    header('Location: ../customer/dashboard.php');
                    exit;
                } catch (PDOException $e) {
                    // Catch a rare race-condition duplicate (two signups, same email, same instant)
                    if ((int)$e->getCode() === 23000) {
                        $errors[] = 'An account with this email already exists. Try logging in instead.';
                    } else {
                        error_log('Sign up failed: ' . $e->getMessage());
                        $errors[] = 'Something went wrong creating your account. Please try again.';
                    }
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
<title>Sign Up | OPO! Our Pinoy Original</title>
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
            <span>Create an account to book tables, place advance orders, and share your feedback.</span>
            <div class="auth-visual-rule"></div>
        </div>
    </div>

    <div class="auth-form-side">
    <div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-subtitle">Sign up to book reservations, place advance orders, and enjoy the comfort of Filipino foods.</p>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="sign_up.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" class="form-control" id="first_name" name="first_name" maxlength="100"
                           value="<?= htmlspecialchars($firstName) ?>" placeholder="Juan" required autofocus>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" class="form-control" id="last_name" name="last_name" maxlength="100"
                           value="<?= htmlspecialchars($lastName) ?>" placeholder="Dela Cruz" required>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="150"
                       value="<?= htmlspecialchars($email) ?>" placeholder="juan_delacruz@example.com" required>
            </div>

            <div class="form-group">
                <label for="phone">Phone <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                <input type="tel" class="form-control" id="phone" name="phone" maxlength="20"
                       value="<?= htmlspecialchars($phone) ?>" placeholder="09XX XXX XXXX">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" class="form-control" id="password" name="password" placeholder="At least 8 characters" required minlength="8">
                    <button type="button" class="password-toggle" data-toggle-for="password" aria-label="Show password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirm Password</label>
                <div class="password-field">
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" placeholder="Repeat password" required minlength="8">
                    <button type="button" class="password-toggle" data-toggle-for="password_confirm" aria-label="Show password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-auth">Create Account</button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="login.php">Log in</a>
        </div>
    </div>
    </div>
    </div>
</div>

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
<script src="auth-interactions.js"></script>
</body>
</html>