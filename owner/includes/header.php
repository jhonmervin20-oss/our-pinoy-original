<?php
/**
 * owner/includes/header.php
 *
 * Top bar for the Owner panel: page title, mobile menu toggle, the
 * notifications bell, and the profile menu.
 *
 * Requires Session::start() (and Session::requireRole('owner')) to have
 * already run before this is included.
 *
 * Usage:
 *   $pageTitle = 'Dashboard';
 *   require_once __DIR__ . '/includes/header.php';
 *
 * Pages that live one level deeper than owner/ (e.g. owner/settings/*.php)
 * must also set $ownerBase = '../'; before requiring this (see sidebar.php).
 */

$pageTitle = $pageTitle ?? 'Owner panel';
$ownerBase = $ownerBase ?? '';

// Opportunistic sweeps -- this app has no cron, so every "check a
// condition and notify" feature runs on a real page load instead (same
// pattern as customer/includes/reservation_functions.php's
// notifyUpcomingHoldExpiry() etc.). sweepInventoryStockAlerts() is cheap
// (pure SQL against existing columns/views).
//
// Auto purchase-order generation is NOT here any more. It used to be:
// sweepAutoPurchaseOrders() ran on every owner and manager page load, doing
// its own per-ingredient Prophet forecasting. That was the old engine, and
// it competed with forecasting/ over the same forecast_runs and
// reorder_suggestions rows -- whichever ran last won. The pipeline now runs
// once nightly from cron/run_sweeps.php (sweepForecastPipeline()), which is
// also what drafts the POs. See config/forecast_auto_po.php.
//
// These run before the activity_logs query just
// below, so anything they log shows up in this very page load's bell, not
// just the next one. Best-effort: a hiccup here should never break the
// page underneath it, matching this file's own existing convention below.
// sweepAutoRecostMenuItems() is gated on a one-query fingerprint of current
// FIFO ingredient costs, so on the overwhelmingly common no-change page load
// it costs a single SELECT; it only does real work right after an inventory
// batch actually moved. Running it here as well as from cron/run_sweeps.php
// means the Costing page an owner is about to open is already current,
// rather than up to five minutes behind the last received delivery.
require_once __DIR__ . '/../../config/inventory_alerts.php';
require_once __DIR__ . '/../../config/feedback_insights.php';
require_once __DIR__ . '/../../config/costing_alerts.php';
try {
    $sweepPdo = Database::getInstance()->getConnection();
    sweepInventoryStockAlerts($sweepPdo);
    sweepFeedbackInsights($sweepPdo);
    sweepAutoRecostMenuItems($sweepPdo);
} catch (Throwable $e) {
    error_log('owner/includes/header.php sweep failed: ' . $e->getMessage());
}

// Real feed: the per-user `notifications` table (config/notifications.php),
// the same one the customer app already uses -- NOT activity_logs, which is
// admin-only now (a system-wide audit trail with no per-user read state or
// role targeting, wrong fit for "manager gets payroll alerts, owner gets PR
// alerts, both get discrepancy alerts"). is_read/read_at are real per-row
// columns here, so "unread" is a straightforward per-notification flag, not
// a single watermark timestamp.
// Named $headerBellNotifications, not $notifications -- owner/notifications.php
// (the full-history page) sets its own $notifications before require'ing this
// file into the same scope; a same-named var here would silently clobber that
// full list with just this dropdown's LIMIT 8 right before the page renders it.
$headerBellNotifications = [];
$unreadCount = 0;
try {
    $ownerNotifPdo = Database::getInstance()->getConnection();
    $ownerNotifUserId = Session::getUserId();

    $notifStmt = $ownerNotifPdo->prepare(
        "SELECT notification_id, type, title, message, reference_type, reference_id, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 8"
    );
    $notifStmt->execute([$ownerNotifUserId]);
    $headerBellNotifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $ownerNotifPdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $unreadStmt->execute([$ownerNotifUserId]);
    $unreadCount = (int)$unreadStmt->fetchColumn();
} catch (PDOException $e) {
    // no badge, empty dropdown
}

// ownerNotifTimeAgo() / ownerNotifIcon() / ownerNotifLink() now live in
// notification_functions.php, shared with owner/notifications.php (the
// full history page) so both stay on identical icon/link logic.
require_once __DIR__ . '/notification_functions.php';

$ownerName    = Session::getFullName() ?: 'Owner';
$ownerInitial = Session::getInitials();
$ownerRole    = ucfirst(Session::getRole() ?? 'Owner');
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
            <button type="button" class="owner-icon-btn" data-dropdown-toggle aria-label="Notifications" id="ownerNotifToggle">
                <i class="ph ph-bell" aria-hidden="true"></i>
                <span class="owner-badge" id="ownerNotifBadge"<?= $unreadCount > 0 ? '' : ' hidden' ?>><?= (int)$unreadCount ?></span>
            </button>

            <div class="owner-dropdown-panel owner-notif-panel">
                <div class="owner-dropdown-header">
                    <span>Notifications</span>
                    <a href="<?= htmlspecialchars($ownerBase) ?>notifications_mark_all_read.php?return=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>">Mark all as read</a>
                </div>
                <ul class="owner-notif-list" id="ownerNotifList"><?= ownerNotifListHtml($headerBellNotifications, $ownerBase) ?></ul>
                <a href="<?= htmlspecialchars($ownerBase) ?>notifications.php" class="owner-dropdown-footer">View all notifications</a>
            </div>
        </div>

        <div class="owner-dropdown" data-owner-dropdown>
            <button type="button" class="owner-profile-btn" data-dropdown-toggle aria-label="Account menu">
                <span class="owner-avatar"><?= htmlspecialchars($ownerInitial) ?></span>
                <span class="owner-profile-btn-text">
                    <span class="owner-profile-btn-name"><?= htmlspecialchars($ownerName) ?></span>
                    <span class="owner-profile-btn-role"><?= htmlspecialchars($ownerRole) ?></span>
                </span>
                <i class="ph ph-caret-down owner-profile-btn-caret" aria-hidden="true"></i>
            </button>

            <div class="owner-dropdown-panel owner-profile-panel">
                <div class="owner-profile-panel-header">
                    <span class="owner-profile-name"><?= htmlspecialchars($ownerName) ?></span>
                    <span class="owner-profile-role"><?= htmlspecialchars($ownerRole) ?></span>
                </div>
                <div class="owner-dropdown-divider"></div>
                <a href="<?= htmlspecialchars($ownerBase) ?>profile.php"><i class="ph ph-user" aria-hidden="true"></i> My profile</a>
                <div class="owner-dropdown-divider"></div>
                <a href="<?= htmlspecialchars($ownerBase) ?>../auth/logout.php" class="owner-logout-link"><i class="ph ph-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>

    </div>
</header>

<script src="<?= htmlspecialchars($ownerBase) ?>../assets/js/notification-sound.js"></script>
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
    const ownerBase = <?= json_encode($ownerBase) ?>;
    const notifBadge = document.getElementById('ownerNotifBadge');
    const notifList = document.getElementById('ownerNotifList');
    const POLL_INTERVAL_MS = 15000;
    let pollTimer = null;
    // Sounds on the RISE in unread count, so one poll that brings back five
    // notifications is one chime, not five. null until the first poll answers,
    // so simply opening a page with unread items already waiting is silent --
    // the sound means "something just arrived", not "you have mail".
    let lastUnread = null;

    function pollNotifications() {
        fetch(ownerBase + 'notifications_poll.php?base=' + encodeURIComponent(ownerBase), { credentials: 'same-origin' })
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
