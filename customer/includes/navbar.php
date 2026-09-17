<?php
/**
 * customer/includes/navbar.php
 *
 * Top nav (desktop) + bottom tab bar (mobile) for the logged-in customer
 * app. Sticky/fixed top bar with the logo, a pill-style link group, a
 * "Book a Reservation" CTA, notifications, and the profile menu. Below
 * 900px the pill nav and CTA hide in favor of a bottom tab bar with a
 * raised center action button.
 *
 * Usage — set $activePage to the current page's key, then include:
 *
 *   $activePage = 'home';
 *   require_once __DIR__ . '/includes/navbar.php';
 *
 * Requires Session::start() to have already run before this is included.
 * All links are same-folder relative — every customer-facing page lives
 * directly under customer/.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/reservation_functions.php';
require_once __DIR__ . '/notification_functions.php';

$activePage = $activePage ?? '';
$customerId = Session::getUserId();

$customerName    = Session::getFullName() ?: 'Customer';
$customerInitial = Session::getInitials();
$customerFirstName = trim(explode(' ', $customerName)[0]);

// Bell badge + dropdown contents. No cron exists in this app, so the three
// "about to X" sweeps run opportunistically right here -- this include
// runs on every logged-in customer page, so real notifications appear as
// soon as the customer next loads any page, not instantly the moment the
// underlying deadline is crossed. Event-triggered notifications
// (confirmed/completed) don't need this -- they're inserted directly at
// the moment those statuses actually change (see reservation_functions.php
// / cashier/api/create_order.php / reservation/reservation_action.php).
// Wrapped in one try/catch: a DB hiccup here should degrade to "no
// notifications this load", never break the page underneath it.
$unreadNotifCount = 0;
$recentNotifications = [];
try {
    $pdo = Database::getInstance()->getConnection();
    $resSettings = getReservationSettings($pdo);

    notifyUpcomingHoldExpiry($pdo, $customerId, $resSettings['reservation_hold_minutes']);
    notifyTodayReservations($pdo, $customerId);
    notifyUpcomingNoShow($pdo, $customerId, $resSettings['reservation_no_show_hours']);

    $notifStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $notifStmt->execute([$customerId]);
    $unreadNotifCount = (int)$notifStmt->fetchColumn();

    $recentStmt = $pdo->prepare(
        'SELECT notification_id, title, message, reference_type, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10'
    );
    $recentStmt->execute([$customerId]);
    $recentNotifications = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // no badge, empty dropdown
}
$notifAriaLabel = $unreadNotifCount > 0
    ? "Notifications ({$unreadNotifCount} unread)"
    : 'Notifications';

// Mobile-only contextual header title -- replaces the logo (via the
// .has-page-title class below) once the customer has navigated away from
// Home, so the header still says where you are without a persistent nav.
$mobilePageTitles = [
    'menu'         => 'Menu',
    'reservations' => 'My Reservations',
    'feedbacks'    => 'Feedbacks',
];
$mobilePageTitle = $mobilePageTitles[$activePage] ?? null;

/**
 * Render one nav link (shared markup shape for both the pill nav and the
 * bottom bar). The pill nav (desktop) is text-only -- four short words
 * don't need icons, and the icons were pulling attention away from the
 * "Book a Reservation" CTA. The bottom bar (mobile) keeps icons; with only
 * a label underneath at that size, the icon is doing real identification
 * work there.
 */
function customerNavLink(string $key, string $href, string $icon, string $label, string $activePage, string $variant): string
{
    $isActive = $key === $activePage;

    if ($variant === 'pill') {
        $class = 'ca-pill-link' . ($isActive ? ' is-active' : '');
        return sprintf(
            '<a href="%s" class="%s"%s>%s</a>',
            htmlspecialchars($href), $class, $isActive ? ' aria-current="page"' : '',
            htmlspecialchars($label)
        );
    }

    $class = 'ca-bottom-link' . ($isActive ? ' is-active' : '');
    return sprintf(
        '<a href="%s" class="%s"%s><i class="ph %s" aria-hidden="true"></i><span>%s</span></a>',
        htmlspecialchars($href), $class, $isActive ? ' aria-current="page"' : '',
        htmlspecialchars($icon), htmlspecialchars($label)
    );
}
?>
<header class="ca-topbar" id="caTopbar">
    <div class="ca-topbar-inner<?= $mobilePageTitle ? ' has-page-title' : '' ?>">

        <div class="ca-topbar-left">
            <a href="dashboard.php" class="ca-logo" aria-label="Our Pinoy Original — Home">
                <img src="../assets/images/logo.jpg" alt="OPO! Our Pinoy Original">
            </a>

            <?php if ($mobilePageTitle): ?>
                <h1 class="ca-mobile-title"><?= htmlspecialchars($mobilePageTitle) ?></h1>
            <?php endif; ?>
        </div>

        <!-- Text-only: four short, unambiguous words don't need icons here. -->
        <div class="ca-topbar-center">
        <nav class="ca-pill-nav" aria-label="Main">
            <?= customerNavLink('home', 'dashboard.php', 'ph-house', 'Home', $activePage, 'pill') ?>
            <?= customerNavLink('menu', 'menu.php', 'ph-fork-knife', 'Menu', $activePage, 'pill') ?>
            <?= customerNavLink('reservations', 'reservations.php', 'ph-calendar-check', 'Reservations', $activePage, 'pill') ?>
            <?= customerNavLink('feedbacks', 'feedback.php', 'ph-chat-circle-dots', 'Feedbacks', $activePage, 'pill') ?>
        </nav>

        <a href="make_reservation.php" class="ca-btn-reserve">
            <i class="ph ph-calendar-plus" aria-hidden="true"></i>
            <span class="ca-btn-reserve-full">Book</span>
            <span class="ca-btn-reserve-short">Book</span>
        </a>
        </div>

        <div class="ca-topbar-actions">

            <div class="ca-dropdown" id="caNotifDropdown">
                <button type="button" class="ca-icon-btn" id="caNotifToggle" aria-label="<?= htmlspecialchars($notifAriaLabel) ?>">
                    <i class="ph ph-bell" aria-hidden="true"></i>
                    <span class="ca-badge" id="caNotifBadge"<?= $unreadNotifCount > 0 ? '' : ' hidden' ?>><?= $unreadNotifCount > 9 ? '9+' : $unreadNotifCount ?></span>
                </button>

                <div class="ca-dropdown-panel ca-notif-panel" id="caNotifPanel">
                    <div class="ca-dropdown-header ca-notif-header">
                        <strong>Notifications</strong>
                        <a href="api/notifications_mark_all_read.php?return=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>" class="ca-notif-mark-all">Mark all as read</a>
                    </div>
                    <div class="ca-notif-list" id="caNotifList"><?= customerNotifListHtml($recentNotifications) ?></div>
                    <a href="notifications.php" class="ca-dropdown-footer">View all notifications</a>
                </div>
            </div>

            <div class="ca-dropdown" id="caProfileDropdown">
                <button type="button" class="ca-profile-btn" id="caProfileToggle" aria-label="Account menu">
                    <span class="ca-avatar"><?= htmlspecialchars($customerInitial) ?></span>
                    <span class="ca-profile-text">
                        <span class="ca-profile-name"><?= htmlspecialchars($customerName) ?></span>
                    </span>
                    <i class="ph ph-caret-down ca-profile-caret" aria-hidden="true"></i>
                </button>

                <div class="ca-dropdown-panel" id="caProfilePanel">
                    <div class="ca-dropdown-header">
                        <strong><?= htmlspecialchars($customerName) ?></strong>
                    </div>
                    <div class="ca-dropdown-divider"></div>
                    <a href="profile.php"><i class="ph ph-user" aria-hidden="true"></i> My profile</a>
                    <a href="reservations.php"><i class="ph ph-calendar-check" aria-hidden="true"></i> My reservations</a>
                    <div class="ca-dropdown-divider"></div>
                    <a href="../auth/logout.php" class="ca-logout-link"><i class="ph ph-sign-out" aria-hidden="true"></i> Log out</a>
                </div>
            </div>

        </div>

    </div>
</header>

<nav class="ca-bottom-nav" aria-label="Mobile navigation">
    <?= customerNavLink('home', 'dashboard.php', 'ph-house', 'Home', $activePage, 'bottom') ?>
    <?= customerNavLink('menu', 'menu.php', 'ph-fork-knife', 'Menu', $activePage, 'bottom') ?>
    <a href="make_reservation.php" class="ca-bottom-fab" aria-label="Book a Reservation">
        <i class="ph ph-plus" aria-hidden="true"></i>
    </a>
    <?= customerNavLink('reservations', 'reservations.php', 'ph-calendar-check', 'Reservations', $activePage, 'bottom') ?>
    <?= customerNavLink('feedbacks', 'feedback.php', 'ph-chat-circle-dots', 'Feedbacks', $activePage, 'bottom') ?>
</nav>

<?php
// The floating assistant lives in this partial because nearly every customer
// page wants it. A page that wants the nav but NOT the assistant (the
// notifications list, where the bubble covers the rows) sets
// $caHideChatbot = true before including this file.
$caHideChatbot = !empty($caHideChatbot);
?>
<?php if (!$caHideChatbot): ?>
<link rel="stylesheet" href="assets/css/chatbot.css?v=<?= filemtime(__DIR__ . '/../assets/css/chatbot.css') ?>">

<div class="ca-chatbot" id="caChatbot">
    <button type="button" class="ca-chatbot-toggle" id="caChatbotToggle" aria-label="Chat with the OPO! assistant">
        <i class="ph ph-chat-circle-dots ca-chatbot-toggle-icon" aria-hidden="true"></i>
        <i class="ph ph-x ca-chatbot-toggle-close" aria-hidden="true"></i>
    </button>

    <div class="ca-chatbot-scrim" id="caChatbotScrim"></div>

    <div class="ca-chatbot-panel" id="caChatbotPanel" role="dialog" aria-label="OPO! Assistant chat" aria-modal="true">
        <div class="ca-chatbot-sheet-handle" aria-hidden="true"></div>

        <div class="ca-chatbot-header">
            <div class="ca-chatbot-header-identity">
                <span class="ca-chatbot-header-avatar-wrap">
                    <img src="../assets/images/logo.jpg" alt="" class="ca-chatbot-header-avatar">
                    <span class="ca-chatbot-status-dot" aria-hidden="true"></span>
                </span>
                <span class="ca-chatbot-header-text">
                    <span class="ca-chatbot-header-title">OPO! Assistant</span>
                    <span class="ca-chatbot-header-subtitle">OPO AI Assistant</span>
                </span>
            </div>
            <div class="ca-chatbot-header-actions">
                <button type="button" class="ca-chatbot-icon-btn" id="caChatbotNewChat" aria-label="Start a new chat" title="New chat">
                    <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                </button>
                <button type="button" class="ca-chatbot-icon-btn is-on" id="caChatbotVoiceToggle" aria-label="Mute voice replies" aria-pressed="true" title="Toggle voice replies">
                    <i class="ph ph-speaker-high" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="ca-chatbot-messages-wrap">
        <div class="ca-chatbot-messages" id="caChatbotMessages">
            <div class="ca-chatbot-row is-bot" id="caChatbotWelcomeRow">
                <img src="../assets/images/logo.jpg" alt="" class="ca-chatbot-avatar">
                <div class="ca-chatbot-bubble-wrap">
                    <div class="ca-chatbot-msg is-bot">Hi <?= htmlspecialchars($customerFirstName) ?>! &#128075; I'm the OPO! Assistant. What can I help with?</div>
                    <span class="ca-chatbot-msg-time"><?= htmlspecialchars(date('g:i A')) ?></span>
                </div>
            </div>

            <div class="ca-chatbot-chips" id="caChatbotChips">
                <button type="button" class="ca-chatbot-chip" data-chip="Check table availability">
                    <span class="ca-chatbot-chip-label">Check Availability</span>
                </button>
                <button type="button" class="ca-chatbot-chip" data-chip="View my reservations">
                    <span class="ca-chatbot-chip-label">My Reservations</span>
                </button>
                <button type="button" class="ca-chatbot-chip" data-chip="Browse the menu">
                    <span class="ca-chatbot-chip-label">Browse Menu</span>
                </button>
                <button type="button" class="ca-chatbot-chip" data-chip="What are your restaurant hours?">
                    <span class="ca-chatbot-chip-label">Restaurant Hours</span>
                </button>
                <button type="button" class="ca-chatbot-chip" data-chip="Where is the restaurant located?">
                    <span class="ca-chatbot-chip-label">Restaurant Location</span>
                </button>
                <button type="button" class="ca-chatbot-chip" data-chip="How can I contact the restaurant?">
                    <span class="ca-chatbot-chip-label">Contact Restaurant</span>
                </button>
            </div>
        </div>

        <button type="button" class="ca-chatbot-scroll-btn" id="caChatbotScrollBtn" aria-label="Scroll to latest message" hidden>
            <i class="ph ph-arrow-down" aria-hidden="true"></i>
        </button>
        </div>

        <form class="ca-chatbot-input-row" id="caChatbotForm">
            <div class="ca-chatbot-input-wrap">
                <button type="button" class="ca-chatbot-mic" id="caChatbotMic" aria-label="Speak your message" aria-pressed="false">
                    <i class="ph ph-microphone" aria-hidden="true"></i>
                </button>
                <input type="text" id="caChatbotInput" class="ca-chatbot-input" placeholder="Ask anything about reservations or the menu…" autocomplete="off" maxlength="1000">
            </div>
            <button type="submit" class="ca-chatbot-send" id="caChatbotSend" aria-label="Send message" disabled>
                <i class="ph ph-paper-plane-tilt" aria-hidden="true"></i>
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="../assets/js/notification-sound.js"></script>
<script>
(function () {
    const dropdowns = [
        { toggle: document.getElementById('caNotifToggle'), panel: document.getElementById('caNotifPanel') },
        { toggle: document.getElementById('caProfileToggle'), panel: document.getElementById('caProfilePanel') },
    ].filter((d) => d.toggle && d.panel);

    function closeAll() {
        dropdowns.forEach(({ toggle, panel }) => {
            panel.classList.remove('is-open');
            toggle.classList.remove('is-open');
        });
    }

    dropdowns.forEach(({ toggle, panel }) => {
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = panel.classList.contains('is-open');
            closeAll();
            panel.classList.toggle('is-open', !isOpen);
            toggle.classList.toggle('is-open', !isOpen);
        });
    });

    // Capture phase (true) so it fires even when a child handler calls
    // stopPropagation() -- the chatbot toggle, notification bell, and
    // profile button all do that to keep their own panels from closing,
    // which was leaving the profile dropdown stuck open when the floating
    // chatbot was clicked. Capture phase runs before any target/bubble
    // handlers, so a stopPropagation() in them can't suppress this.
    // The closest('.ca-dropdown') check keeps clicks INSIDE a dropdown
    // (its toggle or panel) from triggering this -- those are handled by
    // the toggle's own bubble-phase handler below, which reads the current
    // is-open state and toggles accordingly. Without this guard, the
    // capture-phase closeAll() would close the dropdown first, making the
    // toggle handler think it was already closed and re-open it -- so a
    // second click could never close an open dropdown.
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.ca-dropdown')) closeAll();
    }, true);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAll();
    });

    // Notification polling -- this app has no WebSocket/SSE, so this is
    // what "real-time" means here: check for anything new every 15s and
    // patch the badge + dropdown list in place. Paused while the tab is
    // hidden (no point burning requests on a background tab) and resumed
    // immediately when it becomes visible again.
    const notifToggle = document.getElementById('caNotifToggle');
    const notifBadge = document.getElementById('caNotifBadge');
    const notifList = document.getElementById('caNotifList');
    const POLL_INTERVAL_MS = 15000;
    let pollTimer = null;
    // Sounds on the RISE in unread count, so one poll that brings back five
    // notifications is one chime, not five. null until the first poll answers,
    // so simply opening a page with unread items already waiting is silent --
    // the sound means "something just arrived", not "you have mail".
    let lastUnread = null;

    function pollNotifications() {
        fetch('api/notifications_poll.php', { credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : null)
            .then((data) => {
                if (!data) return;
                const unread = Number(data.unread_count) || 0;
                if (lastUnread !== null && unread > lastUnread && window.opoNotificationChime) {
                    window.opoNotificationChime();
                }
                lastUnread = unread;
                if (notifBadge) {
                    notifBadge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                    notifBadge.hidden = data.unread_count <= 0;
                }
                if (notifToggle) {
                    notifToggle.setAttribute('aria-label', data.unread_count > 0 ? `Notifications (${data.unread_count} unread)` : 'Notifications');
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
<?php if (!$caHideChatbot): ?>
<script src="assets/js/chatbot.js?v=<?= filemtime(__DIR__ . '/../assets/js/chatbot.js') ?>"></script>
<?php endif; ?>
