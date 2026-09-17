<?php
/**
 * admin/notification_go.php
 *
 * Click-through for every admin notification link (bell dropdown +
 * notifications.php's full history) -- routes through here instead of linking
 * straight to the target so "click it" and "mark it read" happen as one
 * action. Mirrors owner/notification_go.php but for the activity_logs trail:
 * because activity_logs is a shared append-only table with no per-user read
 * column, "marking read" means advancing the session watermark
 * (admin_notif_read_at) to the clicked log's timestamp, which clears every
 * notification up to and including the one just opened. The destination is
 * always activity_logs.php?log_id=N, which highlights that one row.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/notification_functions.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$target = 'notifications.php';

if (isset($_GET['log_id']) && ctype_digit((string)$_GET['log_id'])) {
    $logId = (int)$_GET['log_id'];
    try {
        $pdo = Database::getInstance()->getConnection();

        $stmt = $pdo->prepare('SELECT created_at FROM activity_logs WHERE log_id = ?');
        $stmt->execute([$logId]);
        $createdAt = $stmt->fetchColumn();

        if ($createdAt !== false) {
            $createdTs = (int)strtotime($createdAt);

            // Advance the watermark forward only -- never backward -- so opening
            // an older entry doesn't un-read newer ones that came after it.
            if (!isset($_SESSION['admin_notif_read_at'])) {
                $_SESSION['admin_notif_read_at'] = $createdTs;
            } else {
                $_SESSION['admin_notif_read_at'] = max((int)$_SESSION['admin_notif_read_at'], $createdTs);
            }

            $target = 'activity_logs.php?log_id=' . $logId;
        }
    } catch (PDOException $e) {
        error_log('admin/notification_go.php failed: ' . $e->getMessage());
    }
}

header('Location: ' . $target);
exit;
