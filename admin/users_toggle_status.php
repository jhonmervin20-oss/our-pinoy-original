<?php
/**
 * admin/users_toggle_status.php
 *
 * Flip a user's is_active flag (works for both staff and customer rows).
 * Deliberately a soft toggle, never a DELETE — matches the is_active
 * convention already used for inventory items etc. elsewhere in the app.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/notifications.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

$tab = ($_POST['tab'] ?? '') === 'customers' ? 'customers' : 'staff';
$redirect = 'users.php?tab=' . $tab;

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$userId = trim((string)($_POST['user_id'] ?? ''));

if ($userId === '' || !ctype_digit($userId)) {
    flash_set('error', 'Invalid account.');
    header('Location: ' . $redirect);
    exit;
}

if ((int)$userId === Session::getUserId()) {
    flash_set('error', "You can't deactivate your own account.");
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("SELECT first_name, last_name, email, is_active FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        flash_set('error', 'That account no longer exists.');
    } else {
        $newStatus = (int)$user['is_active'] === 1 ? 0 : 1;

        $upd = $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
        $upd->execute([$newStatus, $userId]);

        try {
            $log = $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Users', ?, ?, ?)"
            );
            $log->execute([
                Session::getUserId(),
                $newStatus === 1 ? 'Activate account' : 'Deactivate account',
                ($newStatus === 1 ? 'Activated ' : 'Deactivated ') . "{$user['first_name']} {$user['last_name']} ({$user['email']})",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            // reference_id is this specific log row, not the user_id -- a given
            // account gets toggled back and forth repeatedly, and each toggle is
            // its own distinct, non-deduplicable event, unlike e.g. "account
            // created" which only ever happens once per user_id.
            notifyUsersByRole(
                $pdo, ['admin'], 'users',
                $newStatus === 1 ? 'Staff account activated' : 'Staff account deactivated',
                "{$user['first_name']} {$user['last_name']} ({$user['email']})",
                'user_account_toggle', (int)$pdo->lastInsertId()
            );
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual toggle on it.
        }

        flash_set('success', $newStatus === 1 ? 'Account activated.' : 'Account deactivated.');
    }
} catch (PDOException $e) {
    error_log('users_toggle_status.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating this account. Please try again.');
}

header('Location: ' . $redirect);
exit;
