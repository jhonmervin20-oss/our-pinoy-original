<?php
/**
 * config/profile_password.php
 *
 * The one place the app's self-service password rules live. Every profile page
 * (customer, owner, manager, cashier, admin) posts to its own small endpoint
 * for session/role/CSRF handling and its own redirect target, but they all
 * call through here -- five copies of "at least 8 characters, must match,
 * verify the current one first" is exactly the kind of thing that drifts, and
 * this is a security boundary.
 *
 * Two modes, decided by the caller, never by the submitted form:
 *   $allowSetWhenMissing = false  (staff)
 *       A password already exists and must be verified before it changes.
 *       This is the safe default.
 *   $allowSetWhenMissing = true   (customer)
 *       Additionally permits a first-time SET when the stored hash is NULL.
 *       There is nothing to verify against in that case, and the live session
 *       is already proof of identity. Gated on the DATABASE value being NULL,
 *       so an account that has a password can never reach it and skip
 *       verification.
 */

/**
 * @return array{ok: bool, message: string}
 */
function changeOwnPassword(
    PDO $db,
    int $userId,
    string $currentPassword,
    string $newPassword,
    string $confirmPassword,
    bool $allowSetWhenMissing = false
): array {
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();

    $isFirstTimeSet = ($hash === false || $hash === null || $hash === '');

    if ($isFirstTimeSet && !$allowSetWhenMissing) {
        return ['ok' => false, 'message' => "This account doesn't have a password set. Ask an administrator to set one."];
    }
    if (!$isFirstTimeSet && !password_verify($currentPassword, (string)$hash)) {
        return ['ok' => false, 'message' => 'Your current password is incorrect.'];
    }
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'New password must be at least 8 characters long.'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'message' => 'New passwords do not match.'];
    }
    if (!$isFirstTimeSet && password_verify($newPassword, (string)$hash)) {
        return ['ok' => false, 'message' => 'Your new password must be different from your current one.'];
    }

    $db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
       ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

    return [
        'ok'      => true,
        'message' => $isFirstTimeSet
            ? 'Password set. You can now sign in with your email and password.'
            : 'Password updated.',
    ];
}
