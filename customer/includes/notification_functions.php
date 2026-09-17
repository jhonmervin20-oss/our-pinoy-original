<?php
/**
 * Shared helpers for the customer bell (customer/includes/navbar.php) and
 * its poll endpoint (customer/api/notifications_poll.php) -- extracted out
 * of navbar.php (previously defined inline) so the initial page render and
 * the polling response can never drift apart, matching the same convention
 * owner/manager/admin each use for their own notification_functions.php.
 */

/** "5m ago" / "3h ago" / "2d ago", falling back to a short date beyond a week. */
function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}

/** One icon per notification reference_type, so the dropdown isn't just a wall of text. */
function notifIcon(?string $referenceType): string
{
    return match ($referenceType) {
        'reservation_confirmed'      => 'ph-calendar-check',
        'reservation_completed'      => 'ph-flag-checkered',
        'reservation_hold_expiring'  => 'ph-hourglass',
        'reservation_today'          => 'ph-bell-ringing',
        'reservation_no_show_warning' => 'ph-warning-circle',
        // Distinct from the warning above -- one says "you still have time",
        // the other says it already happened, and they must not look alike.
        'reservation_no_show'         => 'ph-trash',
        default                       => 'ph-bell',
    };
}

/**
 * Which notifications carry a bad outcome, and so get the red treatment.
 *
 * Only the reservation that was actually lost. The warning is deliberately not
 * included: it still has time to be acted on, and colouring both the same would
 * throw away the distinction notifIcon() draws above.
 */
function notifIsNegative(?string $referenceType): bool
{
    return $referenceType === 'reservation_no_show';
}

/**
 * Renders the bell dropdown's notification items -- shared by
 * customer/includes/navbar.php's initial page render and
 * customer/api/notifications_poll.php's JSON response. Each item is its
 * own <a> (not wrapped in an <li>, unlike owner/manager/admin's list) --
 * matches this panel's existing markup shape exactly.
 */
function customerNotifListHtml(array $notifications): string
{
    if (empty($notifications)) {
        return '<p class="ca-notif-empty">You\'re all caught up.</p>';
    }

    $html = '';
    foreach ($notifications as $n) {
        $unreadClass = $n['is_read'] ? '' : ' is-unread';
        $html .= '<a href="api/notification_open.php?id=' . (int)$n['notification_id'] . '" class="ca-notif-item' . $unreadClass . '">';
        $iconClass = 'ca-notif-item-icon' . (notifIsNegative($n['reference_type']) ? ' is-negative' : '');
        $html .= '<span class="' . $iconClass . '"><i class="ph ' . notifIcon($n['reference_type']) . '" aria-hidden="true"></i></span>';
        $html .= '<span class="ca-notif-item-content">';
        $html .= '<span class="ca-notif-item-message">' . htmlspecialchars($n['title']) . ' ' . htmlspecialchars($n['message']) . '</span>';
        $html .= '<span class="ca-notif-item-time">' . htmlspecialchars(timeAgo($n['created_at'])) . '</span>';
        $html .= '</span></a>';
    }

    return $html;
}
