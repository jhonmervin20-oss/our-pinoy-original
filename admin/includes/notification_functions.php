<?php
/**
 * admin/includes/notification_functions.php
 *
 * Shared helpers for the Admin panel's notification bell
 * (admin/includes/header.php), the full history page (admin/notifications.php),
 * and the read/redirect click-through (admin/notification_go.php) -- all three
 * read off the system-wide `activity_logs` audit trail (the same table
 * admin/activity_logs.php and admin/dashboard.php display), not the per-user
 * `notifications` table.
 *
 * Why activity_logs? Admin is audit-only (see project_admin_nav_lockdown) --
 * it owns no operational module, so it has no per-user notification events of
 * its own. Its job is to oversee the whole system's activity, and that trail
 * already exists in activity_logs. Reading it directly means the admin bell
 * lights up the moment ANYONE (owner, manager, cashier, another admin --
 * Inventory, Purchase Orders, Payroll, Reservations, Suppliers, Menu, Cashier,
 * Feedback, Database, Users, ...) logs a new activity row, with no per-module
 * call site needing to remember to also notify the admin.
 *
 * "Unread" is tracked with a session watermark timestamp (admin_notif_read_at)
 * rather than a per-row is_read flag -- activity_logs is a shared, append-only
 * audit trail with no per-user read column, so a watermark is the natural fit.
 * It only tracks the current browser session; a fresh login starts with a
 * watermark of "now" (nothing unread), exactly like opening a fresh mail client.
 *
 * The admin's own actions also appear -- logging in, creating a staff account,
 * taking a backup, etc. all write activity_logs rows, so the admin sees
 * everything the system records, including its own operations.
 */

/** "5m ago" / "3h ago" / "2d ago", falling back to a short date beyond a week. */
function adminNotifTimeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}

/**
 * One icon per activity_logs.module, so the feed isn't just a wall of text.
 * Falls back to a generic info icon for any module not explicitly listed.
 */
function adminNotifIcon(string $module): string
{
    return match (strtolower($module)) {
        'users'               => 'ph-user',
        'inventory'           => 'ph-package',
        'purchase orders'     => 'ph-clipboard-text',
        'payroll'             => 'ph-money',
        'cashier'             => 'ph-cash-register',
        'reservations'        => 'ph-calendar-check',
        'feedback'            => 'ph-chat-circle-dots',
        'suppliers'           => 'ph-truck',
        'menu management'     => 'ph-bowl-food',
        'demand forecasting'  => 'ph-chart-line-up',
        'analytics'           => 'ph-chart-bar',
        'employee performance'=> 'ph-gauge',
        'orders'              => 'ph-shopping-cart',
        default               => 'ph-info',
    };
}

/**
 * Every admin notification takes the user to the activity_logs page, deeply
 * linked to the exact log_id that produced the bell entry (activity_logs.php
 * already supports ?log_id=N and highlights that one row). Always returns a
 * real link -- the audit trail is the admin's one destination for every module.
 */
function adminNotifLink(string $referenceType, ?int $referenceId, string $adminBase): ?string
{
    if ($referenceId === null) {
        return null;
    }
    return "{$adminBase}activity_logs.php?log_id=" . (int)$referenceId;
}

/**
 * Reads the session "watermark" timestamp that separates read from unread rows.
 * Returns null until the admin has ever had a watermark set (which header.php
 * sets on first load), so the badge can treat "no watermark yet" however the
 * caller prefers.
 */
function adminNotifWatermark(): ?int
{
    return isset($_SESSION['admin_notif_read_at']) ? (int)$_SESSION['admin_notif_read_at'] : null;
}

/**
 * Renders the bell dropdown's <li> list from activity_logs rows -- shared by
 * admin/includes/header.php's initial page render and admin/notifications_poll.php's
 * JSON response, so the two can never drift into rendering a notification
 * differently depending on which one produced it.
 *
 * @param array $logs Rows shaped like the SELECT in header.php:
 *   log_id, module, action, description, created_at, first_name, last_name.
 * @param int|null $watermark Unix timestamp; rows newer than this render unread.
 */
function adminNotifListHtml(array $logs, string $adminBase, ?int $watermark): string
{
    if (empty($logs)) {
        return '<li class="owner-notif-empty">You\'re all caught up.</li>';
    }

    $html = '';
    foreach ($logs as $log) {
        $logId     = (int)$log['log_id'];
        $href      = "{$adminBase}notification_go.php?log_id=" . $logId;
        $userName  = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'System';
        $createdTs = strtotime($log['created_at']);
        $isUnread  = $watermark !== null && $createdTs > $watermark;
        $unreadClass = $isUnread ? ' is-unread' : '';

        $html .= '<li>';
        $html .= '<a href="' . htmlspecialchars($href) . '" class="owner-notif-item' . $unreadClass . '">';
        $html .= '<span class="owner-notif-icon"><i class="ph ' . adminNotifIcon($log['module']) . '" aria-hidden="true"></i></span>';
        $html .= '<span class="owner-notif-body"><p><strong>' . htmlspecialchars(ucfirst($log['module'])) . '</strong> '
              . htmlspecialchars($log['action']) . '</p>';
        $html .= '<span>' . htmlspecialchars(adminNotifTimeAgo($log['created_at'])) . ' &middot; ' . htmlspecialchars($userName) . '</span></span>';
        if ($isUnread) {
            $html .= '<span class="owner-notif-dot" aria-label="Unread"></span>';
        }
        $html .= '</a>';
        $html .= '</li>';
    }

    return $html;
}
