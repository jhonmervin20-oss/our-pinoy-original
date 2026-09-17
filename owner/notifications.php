<?php
/**
 * owner/notifications.php
 *
 * Full, paginated history behind the bell's "View all notifications"
 * footer link (owner/includes/header.php) -- the bell itself only ever
 * shows the latest 8. Reads the real per-user `notifications` table
 * (config/notifications.php), same as the bell, so a row here looks and
 * links identically to its bell counterpart (ownerNotifIcon()/ownerNotifLink(),
 * owner/includes/notification_functions.php). Every link routes through
 * notification_go.php so opening one from here marks it read too, exactly
 * like opening it from the bell does.
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
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'notifications';
$pageTitle  = 'Notifications';
$ownerBase  = '';
$ownerId    = Session::getUserId();

$statusFilter = in_array($_GET['status'] ?? '', ['unread', 'read'], true) ? $_GET['status'] : '';

$notifications = [];
$totalCount     = 0;
$unreadCount    = 0;
$dbError        = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $unreadCountStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $unreadCountStmt->execute([$ownerId]);
    $unreadCount = (int)$unreadCountStmt->fetchColumn();

    $where  = ['user_id = ?'];
    $params = [$ownerId];
    if ($statusFilter === 'unread') {
        $where[] = 'is_read = 0';
    } elseif ($statusFilter === 'read') {
        $where[] = 'is_read = 1';
    }
    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications{$whereSql}");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT notification_id, type, title, message, reference_type, reference_id, is_read, created_at
         FROM notifications
         {$whereSql}
         ORDER BY created_at DESC"
    );
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load notifications. Please refresh this page.";
}

$typeLabels = [
    'inventory'   => 'Inventory',
    'procurement' => 'Procurement',
    'payroll'     => 'Payroll',
    'cashier'     => 'Cashier',
    'reservation' => 'Reservations',
    'payment'     => 'Payments',
    'order'       => 'Orders',
    'system'      => 'System',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Notifications | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
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
                        $referenceId = $n['reference_id'] !== null ? (int)$n['reference_id'] : null;
                        $isClickable = ownerNotifLink($n['reference_type'], $referenceId, $ownerBase) !== null;
                        $href = $isClickable ? 'notification_go.php?id=' . (int)$n['notification_id'] : null;
                        // A notification with nowhere to go is still its own card,
                        // just not a link -- so the stack stays uniform.
                        $cardClass = 'owner-card owner-notif-card' . ($n['is_read'] ? '' : ' is-unread');
                    ?>
                        <?php if ($href !== null): ?>
                        <a href="<?= htmlspecialchars($href) ?>" class="<?= $cardClass ?>">
                        <?php else: ?>
                        <div class="<?= $cardClass ?>">
                        <?php endif; ?>
                            <span class="owner-notif-card-icon"><i class="ph <?= ownerNotifIcon($n['type']) ?>" aria-hidden="true"></i></span>
                            <span class="owner-notif-card-body">
                                <span class="owner-notif-card-title"><?= htmlspecialchars($n['title']) ?></span>
                                <span class="owner-notif-card-msg"><?= htmlspecialchars($n['message']) ?></span>
                                <span class="owner-notif-card-meta"><?= htmlspecialchars($typeLabels[$n['type']] ?? ucfirst($n['type'])) ?> &middot; <?= htmlspecialchars(ownerNotifTimeAgo($n['created_at'])) ?></span>
                            </span>
                            <?php if (!$n['is_read']): ?><span class="owner-notif-dot" aria-label="Unread"></span><?php endif; ?>
                        <?php if ($href !== null): ?></a><?php else: ?></div><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>

    </div>

</div>

</body>
</html>
