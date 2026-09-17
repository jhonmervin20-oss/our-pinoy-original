<?php
/**
 * admin/includes/header.php
 *
 * Top bar for the Admin panel — identical shell to owner/includes/header.php
 * (page title, mobile menu toggle, notifications bell,
 * profile menu), trimmed to the links that actually exist in this panel.
 *
 * Requires Session::start() to have already run before this is included.
 *
 * Usage:
 *   $pageTitle = 'Dashboard';
 *   require_once __DIR__ . '/includes/header.php';
 *
 * Pages that live one level deeper than admin/ must also set
 * $adminBase = '../'; before requiring this (see sidebar.php).
 */

$pageTitle = $pageTitle ?? 'Admin panel';
$adminBase = $adminBase ?? '';

// adminNotifTimeAgo() / adminNotifIcon() / adminNotifLink() / adminNotifListHtml()
// now live in notification_functions.php, shared with admin/notifications.php
// (the full history page) and admin/notifications_poll.php so all three stay
// on identical rendering logic.
require_once __DIR__ . '/notification_functions.php';

// Real feed: the system-wide `activity_logs` audit trail (same table as
// admin/activity_logs.php and admin/dashboard.php) -- NOT the per-user
// `notifications` table, which is for Owner/Manager/Customer operational
// events. Admin is audit-only, so it watches every logged action across the
// whole system (Inventory, Payroll, Cashier, Reservations, Purchase Orders,
// Users, ...). Unread is tracked with a session watermark
// (adminNotifWatermark()) rather than a per-row is_read flag, since
// activity_logs is a shared append-only trail with no per-user read column.
// Self-contained DB connection (not relying on the including page's own
// $pdo) so this include works identically no matter which admin page pulls
// it in; degrades to "no badge, empty dropdown" on a DB hiccup rather than
// breaking the page.
// Named $headerBellNotifications, not $notifications -- admin/notifications.php
// (the full-history page) sets its own $notifications before require'ing this
// file into the same scope; a same-named var here would silently clobber that
// full list with just this dropdown's LIMIT 8 right before the page renders it.
$headerBellNotifications = [];
$unreadCount = 0;
try {
    $adminPdo = Database::getInstance()->getConnection();

    // First time this browser session sees the admin panel, seed the unread
    // watermark to "now" so the badge starts empty (nothing is retroactively
    // marked unread from before the admin logged in). After that it only ever
    // moves forward via notification_go.php / notifications_mark_all_read.php.
    if (!isset($_SESSION['admin_notif_read_at'])) {
        $_SESSION['admin_notif_read_at'] = time();
    }

    $notifStmt = $adminPdo->prepare(
        "SELECT l.log_id, l.module, l.action, l.description, l.created_at,
                u.first_name, u.last_name
         FROM activity_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         ORDER BY l.created_at DESC
         LIMIT 8"
    );
    $notifStmt->execute();
    $headerBellNotifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $adminPdo->prepare(
        'SELECT COUNT(*) FROM activity_logs WHERE created_at > FROM_UNIXTIME(?)'
    );
    $unreadStmt->execute([(int)$_SESSION['admin_notif_read_at']]);
    $unreadCount = (int)$unreadStmt->fetchColumn();
} catch (PDOException $e) {
    // no badge, empty dropdown
}

$adminName    = Session::getFullName() ?: 'Admin';
$adminInitial = Session::getInitials();
$adminRole    = ucfirst(Session::getRole() ?? 'Admin');
?>
<header class="owner-topbar">
    <div class="owner-topbar-left">
        <button type="button" class="owner-icon-btn owner-menu-toggle" id="ownerMenuToggle" aria-label="Open menu">
            <i class="ph ph-list" aria-hidden="true"></i>
        </button>
        <h1 class="owner-page-title"><?= htmlspecialchars($pageTitle) ?></h1>
    </div>

    <div class="owner-topbar-actions">

        <div class="owner-dropdown" data-owner-dropdown>
            <button type="button" class="owner-icon-btn" data-dropdown-toggle aria-label="Notifications" id="adminNotifToggle">
                <i class="ph ph-bell" aria-hidden="true"></i>
                <span class="owner-badge" id="adminNotifBadge"<?= $unreadCount > 0 ? '' : ' hidden' ?>><?= (int)$unreadCount ?></span>
            </button>

            <div class="owner-dropdown-panel owner-notif-panel">
                <div class="owner-dropdown-header">
                    <span>Notifications</span>
                    <a href="<?= htmlspecialchars($adminBase) ?>notifications_mark_all_read.php?return=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>">Mark all as read</a>
                </div>
                <ul class="owner-notif-list" id="adminNotifList"><?= adminNotifListHtml($headerBellNotifications, $adminBase, adminNotifWatermark()) ?></ul>
                <a href="<?= htmlspecialchars($adminBase) ?>notifications.php" class="owner-dropdown-footer">View all notifications</a>
            </div>
        </div>

        <div class="owner-dropdown" data-owner-dropdown>
            <button type="button" class="owner-profile-btn" data-dropdown-toggle aria-label="Account menu">
                <span class="owner-avatar"><?= htmlspecialchars($adminInitial) ?></span>
                <span class="owner-profile-btn-text">
                    <span class="owner-profile-btn-name"><?= htmlspecialchars($adminName) ?></span>
                    <span class="owner-profile-btn-role"><?= htmlspecialchars($adminRole) ?></span>
                </span>
                <i class="ph ph-caret-down owner-profile-btn-caret" aria-hidden="true"></i>
            </button>

            <div class="owner-dropdown-panel owner-profile-panel">
                <div class="owner-profile-panel-header">
                    <span class="owner-profile-name"><?= htmlspecialchars($adminName) ?></span>
                    <span class="owner-profile-role"><?= htmlspecialchars($adminRole) ?></span>
                </div>
                <div class="owner-dropdown-divider"></div>
                <a href="<?= htmlspecialchars($adminBase) ?>profile.php"><i class="ph ph-user" aria-hidden="true"></i> My profile</a>
                <div class="owner-dropdown-divider"></div>
                <a href="<?= htmlspecialchars($adminBase) ?>../auth/logout.php" class="owner-logout-link"><i class="ph ph-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>

    </div>
</header>

<script src="<?= htmlspecialchars($adminBase) ?>../assets/js/notification-sound.js"></script>
<script>
(function () {
    // Dropdowns (notifications + profile)
    const dropdownToggles = document.querySelectorAll('[data-dropdown-toggle]');

    function closeAllDropdowns() {
        document.querySelectorAll('.owner-dropdown-panel.is-open').forEach(p => p.classList.remove('is-open'));
        dropdownToggles.forEach(t => t.classList.remove('is-open'));
    }

    document.querySelectorAll('[data-owner-dropdown]').forEach((wrap) => {
        const toggle = wrap.querySelector('[data-dropdown-toggle]');
        const panel  = wrap.querySelector('.owner-dropdown-panel');
        if (!toggle || !panel) return;

        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = panel.classList.contains('is-open');
            closeAllDropdowns();
            panel.classList.toggle('is-open', !isOpen);
            toggle.classList.toggle('is-open', !isOpen);
        });
    });

    document.addEventListener('click', closeAllDropdowns);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllDropdowns(); });

    // Mobile sidebar drawer
    const menuToggle = document.getElementById('ownerMenuToggle');
    const sidebar    = document.getElementById('ownerSidebar');
    const backdrop   = document.getElementById('ownerSidebarBackdrop');

    function closeSidebar() {
        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-visible');
    }

    if (menuToggle && sidebar && backdrop) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('is-open');
            backdrop.classList.toggle('is-visible');
        });
        backdrop.addEventListener('click', closeSidebar);
    }

    // Notification polling -- this app has no WebSocket/SSE, so this is
    // what "real-time" means here: check for anything new every 15s and
    // patch the badge + dropdown list in place. Paused while the tab is
    // hidden (no point burning requests on a background tab) and resumed
    // immediately when it becomes visible again.
    const adminBase = <?= json_encode($adminBase) ?>;
    const notifBadge = document.getElementById('adminNotifBadge');
    const notifList = document.getElementById('adminNotifList');
    const POLL_INTERVAL_MS = 15000;
    let pollTimer = null;
    // Sounds on the RISE in unread count, so one poll that brings back five
    // notifications is one chime, not five. null until the first poll answers,
    // so simply opening a page with unread items already waiting is silent --
    // the sound means "something just arrived", not "you have mail".
    let lastUnread = null;

    function pollNotifications() {
        fetch(adminBase + 'notifications_poll.php?base=' + encodeURIComponent(adminBase), { credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : null)
            .then((data) => {
                if (!data) return;
                const unread = Number(data.unread_count) || 0;
                if (lastUnread !== null && unread > lastUnread && window.opoNotificationChime) {
                    window.opoNotificationChime();
                }
                lastUnread = unread;
                if (notifBadge) {
                    notifBadge.textContent = data.unread_count;
                    notifBadge.hidden = data.unread_count <= 0;
                }
                if (notifList) notifList.innerHTML = data.list_html;
            })
            .catch(() => { /* a missed poll just tries again next interval */ });
    }

    function startPolling() {
        if (pollTimer) return;
        pollNotifications();
        pollTimer = setInterval(pollNotifications, POLL_INTERVAL_MS);
    }
    function stopPolling() {
        if (!pollTimer) return;
        clearInterval(pollTimer);
        pollTimer = null;
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopPolling(); else startPolling();
    });
    if (!document.hidden) startPolling();
})();
</script>
