<?php
/**
 * admin/notifications.php
 *
 * Full history behind the bell's "View all notifications" footer link
 * (admin/includes/header.php) -- the bell itself only ever shows the latest
 * 8 activity_logs entries. Reads the same system-wide `activity_logs` audit
 * trail the bell and admin/activity_logs.php use, so a row here looks and
 * links identically to its bell counterpart (adminNotifIcon()/adminNotifLink(),
 * admin/includes/notification_functions.php). Every link routes through
 * notification_go.php so opening one from here marks it read too (advancing
 * the session watermark), exactly like opening it from the bell does.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/notification_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'activity_logs';
$pageTitle  = 'Notifications';
$adminBase  = '';

$statusFilter = in_array($_GET['status'] ?? '', ['unread', 'read'], true) ? $_GET['status'] : '';

$notifications = [];
$totalCount     = 0;
$unreadCount    = 0;
$dbError        = null;

try {
    $pdo = Database::getInstance()->getConnection();

    if (!isset($_SESSION['admin_notif_read_at'])) {
        $_SESSION['admin_notif_read_at'] = time();
    }
    $watermark = (int)$_SESSION['admin_notif_read_at'];

    $unreadCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM activity_logs WHERE created_at > FROM_UNIXTIME(?)'
    );
    $unreadCountStmt->execute([$watermark]);
    $unreadCount = (int)$unreadCountStmt->fetchColumn();

    $where  = [];
    $params = [];
    if ($statusFilter === 'unread') {
        $where[]  = 'l.created_at > FROM_UNIXTIME(?)';
        $params[] = $watermark;
    } elseif ($statusFilter === 'read') {
        $where[]  = 'l.created_at <= FROM_UNIXTIME(?)';
        $params[] = $watermark;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM activity_logs l{$whereSql}"
    );
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT l.log_id, l.module, l.action, l.description, l.created_at,
                u.first_name, u.last_name
         FROM activity_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         {$whereSql}
         ORDER BY l.created_at DESC"
    );
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load notifications. Please refresh this page.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Notifications | Admin Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/includes/header.php'; ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <div class="owner-notif-toolbar">
                <span class="owner-notif-toolbar-count"><?= $totalCount ?> total<?= $unreadCount > 0 ? " &middot; {$unreadCount} unread" : '' ?></span>
                <?php if ($unreadCount > 0): ?>
                <a href="notifications_mark_all_read.php?return=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>" class="owner-btn owner-btn-secondary owner-btn-sm">
                    <i class="ph ph-check-circle" aria-hidden="true"></i> Mark all as read
                </a>
                <?php else: ?>
                <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" disabled>
                    <i class="ph ph-check-circle" aria-hidden="true"></i> Mark all as read
                </button>
                <?php endif; ?>
            </div>

            <div class="owner-tabs">
                <a href="notifications.php" class="owner-tabs-link<?= $statusFilter === '' ? ' is-active' : '' ?>">All</a>
                <a href="notifications.php?status=unread" class="owner-tabs-link<?= $statusFilter === 'unread' ? ' is-active' : '' ?>">Unread</a>
                <a href="notifications.php?status=read" class="owner-tabs-link<?= $statusFilter === 'read' ? ' is-active' : '' ?>">Read</a>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="owner-card">
                    <div class="owner-notif-empty-state">
                        <i class="ph ph-bell-slash" aria-hidden="true"></i>
                        <p>
                            <?php if ($statusFilter === 'unread'): ?>No unread notifications.
                            <?php elseif ($statusFilter === 'read'): ?>No read notifications yet.
                            <?php else: ?>You're all caught up.
                            <?php endif; ?>
                        </p>
                        <?php if ($statusFilter !== ''): ?>
                            <a href="notifications.php" class="owner-btn owner-btn-secondary owner-btn-sm">Show all</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="owner-notif-cards">
                    <?php foreach ($notifications as $n):
                        $logId = (int)$n['log_id'];
                        $href = 'notification_go.php?log_id=' . $logId;
                        $userName = trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? '')) ?: 'System';
                        $isUnread = (int)strtotime($n['created_at']) > $watermark;
                    ?>
                        <a href="<?= htmlspecialchars($href) ?>" class="owner-card owner-notif-card<?= $isUnread ? ' is-unread' : '' ?>">
                            <span class="owner-notif-card-icon"><i class="ph <?= adminNotifIcon($n['module']) ?>" aria-hidden="true"></i></span>
                            <span class="owner-notif-card-body">
                                <span class="owner-notif-card-title"><?= htmlspecialchars(ucfirst($n['module'])) ?></span>
                                <span class="owner-notif-card-msg"><?= htmlspecialchars($n['action']) ?></span>
                                <span class="owner-notif-card-meta"><?= htmlspecialchars($userName) ?> &middot; <?= htmlspecialchars(adminNotifTimeAgo($n['created_at'])) ?></span>
                            </span>
                            <?php if ($isUnread): ?><span class="owner-notif-dot" aria-label="Unread"></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>

    </div>

</div>

</body>
</html>
