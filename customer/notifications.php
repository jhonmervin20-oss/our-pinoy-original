<?php
/**
 * customer/notifications.php
 *
 * Full, paginated history behind the bell's "View all notifications"
 * footer link (customer/includes/navbar.php) -- the bell itself only ever
 * shows the latest handful. Reads the real per-user `notifications` table
 * (config/notifications.php), same as the bell, and reuses
 * customerNotifListHtml() (customer/includes/notification_functions.php)
 * so a row here looks and links identically to its bell counterpart --
 * every link routes through api/notification_open.php so opening one from
 * here marks it read too, exactly like opening it from the bell does.
 *
 * Same layout/feature set as owner/manager/admin's own notifications.php
 * (flat list, All/Unread/Read chips, Mark all as read, no pagination, no
 * date grouping, no page-content heading duplicating anything already
 * shown elsewhere) -- just styled with this app's own ca- customer skin
 * instead of the owner-panel one, matching every other customer-facing page.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/notification_functions.php';
// ca_flash_render() lives here. It used to arrive via the navbar's own
// include chain; this page no longer includes the navbar, so it has to
// require the function it actually calls.
require_once __DIR__ . '/includes/reservation_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['customer'])) {
    header('Location: ../auth/login.php');
    exit;
}

$customerId = Session::getUserId();

$statusFilter = in_array($_GET['status'] ?? '', ['unread', 'read'], true) ? $_GET['status'] : '';

$notifications = [];
$totalCount     = 0;
$unreadCount    = 0;
$dbError        = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $unreadCountStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $unreadCountStmt->execute([$customerId]);
    $unreadCount = (int)$unreadCountStmt->fetchColumn();

    $where  = ['user_id = ?'];
    $params = [$customerId];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">

</head>
<body class="ca-body">

<?php
    // Navbar yes, floating assistant no -- the chat bubble sits on top of the
    // notification rows on this page. See $caHideChatbot in includes/navbar.php.
    $caHideChatbot = true;
    require_once __DIR__ . '/includes/navbar.php';
?>

<main class="ca-content">

    <?= ca_flash_render() ?>

    <?php if ($dbError): ?>
        <div class="ca-card" style="border-color:var(--ca-danger);color:var(--ca-danger);margin-bottom:16px;">
            <i class="ph ph-warning-circle" aria-hidden="true"></i>
            <?= htmlspecialchars($dbError) ?>
        </div>
    <?php endif; ?>

    <div class="ca-res-page-head">
        <div>
           
            <p class="ca-welcome-sub"><?= $totalCount ?> total<?= $unreadCount > 0 ? " &middot; {$unreadCount} unread" : '' ?></p>
        </div>
        <?php if ($unreadCount > 0): ?>
            <a href="api/notifications_mark_all_read.php" class="ca-btn ca-btn-secondary">
                <i class="ph ph-check-circle" aria-hidden="true"></i> Mark all as read
            </a>
        <?php else: ?>
            <button type="button" class="ca-btn ca-btn-secondary" disabled>
                <i class="ph ph-check-circle" aria-hidden="true"></i> Mark all as read
            </button>
        <?php endif; ?>
    </div>

    <div class="ca-notif-tabs">
        <a href="notifications.php" class="ca-notif-tab<?= $statusFilter === '' ? ' is-active' : '' ?>">All</a>
        <a href="notifications.php?status=unread" class="ca-notif-tab<?= $statusFilter === 'unread' ? ' is-active' : '' ?>">Unread</a>
        <a href="notifications.php?status=read" class="ca-notif-tab<?= $statusFilter === 'read' ? ' is-active' : '' ?>">Read</a>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="ca-card">
            <div class="ca-empty-state">
                <i class="ph ph-bell-slash" aria-hidden="true"></i>
                <?php if ($statusFilter === 'unread'): ?>No unread notifications.
                <?php elseif ($statusFilter === 'read'): ?>No read notifications yet.
                <?php else: ?>You're all caught up.
                <?php endif; ?>
                <?php if ($statusFilter !== ''): ?>
                    <div style="margin-top:16px;">
                        <a href="notifications.php" class="ca-btn ca-btn-secondary">Show all</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php /* Rendered inline rather than through customerNotifListHtml() --
                 that helper still backs the bell dropdown and the poll API,
                 which want the tight row, not a card. */ ?>
        <div class="ca-notif-cards">
            <?php foreach ($notifications as $n): ?>
                <a href="api/notification_open.php?id=<?= (int)$n['notification_id'] ?>" class="ca-card ca-notif-card<?= $n['is_read'] ? '' : ' is-unread' ?>">
                    <span class="ca-notif-item-icon<?= notifIsNegative($n['reference_type']) ? ' is-negative' : '' ?>"><i class="ph <?= notifIcon($n['reference_type']) ?>" aria-hidden="true"></i></span>
                    <span class="ca-notif-item-content">
                        <span class="ca-notif-card-title"><?= htmlspecialchars($n['title']) ?></span>
                        <span class="ca-notif-card-msg"><?= htmlspecialchars($n['message']) ?></span>
                        <span class="ca-notif-item-time"><?= htmlspecialchars(timeAgo($n['created_at'])) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

</body>
</html>
