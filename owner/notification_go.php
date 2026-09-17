<?php
/**
 * owner/notification_go.php
 *
 * Read/redirect click-through for every owner notification link (bell
 * dropdown + notifications.php's full history) -- routes through here
 * instead of linking straight to the target so "click it" and "mark it
 * read" happen as one action. Never trusts ?id= blindly: the row must
 * belong to the logged-in owner, or this falls back to the notifications
 * list instead of leaking another user's notification existence.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/notification_functions.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$notificationId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : null;
$ownerId = Session::getUserId();
$target = 'notifications.php';

if ($notificationId !== null) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $stmt = $pdo->prepare('SELECT reference_type, reference_id FROM notifications WHERE notification_id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $ownerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?')
                ->execute([$notificationId]);

            $referenceId = $row['reference_id'] !== null ? (int)$row['reference_id'] : null;
            $link = ownerNotifLink($row['reference_type'], $referenceId, '');
            if ($link !== null) {
                $target = $link;
            }
        }
    } catch (PDOException $e) {
        error_log('owner/notification_go.php failed: ' . $e->getMessage());
    }
}

header('Location: ' . $target);
exit;
