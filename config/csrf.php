<?php
/**
 * config/csrf.php
 *
 * Shared CSRF helpers. Mirrors the token mechanism already used inline in
 * auth/login.php, auth/sign_up.php etc. (bin2hex(random_bytes(32)) stored in
 * $_SESSION['csrf_token'], checked with hash_equals) rather than a different
 * scheme, so the pattern stays consistent across the app.
 *
 * Requires Session::start() to have already run.
 *
 * Usage:
 *   require_once __DIR__ . '/../../config/csrf.php';
 *   ... inside a <form> ...      <?= csrf_field() ?>
 *   ... at the top of a POST handler ...
 *     if (!verify_csrf_token()) { ... }
 */

/**
 * Return the current CSRF token, generating one if this session doesn't
 * have one yet.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Ready-made hidden input for a <form>.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Check the token submitted in $_POST against the session's token.
 *
 * Both halves are checked for emptiness before comparing, because
 * hash_equals('', '') returns TRUE. Without that, a session that has not yet
 * been issued a token -- one where no page has called csrf_field() yet --
 * would accept a POST carrying no token at all, which is precisely the
 * request a cross-site form submits. Fails closed instead: no token on
 * either side means no.
 */
function verify_csrf_token(): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken  = $_POST['csrf_token'] ?? '';

    if ($sessionToken === '' || $postedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $postedToken);
}
