<?php
/**
 * owner/includes/notification_functions.php
 *
 * Shared helpers for the Owner panel's notification bell
 * (owner/includes/header.php), the full history page (owner/notifications.php),
 * and the read/redirect click-through (owner/notification_go.php) -- all three
 * read off the real per-user `notifications` table (config/notifications.php's
 * createNotificationOnce()/notifyUsersByRole()), not activity_logs. Owner-
 * specific on purpose (manager has its own near-identical copy in
 * manager/includes/notification_functions.php) since the two roles' reachable
 * destinations genuinely differ -- see ownerNotifLink() below.
 */

/** "5m ago" / "3h ago" / "2d ago", falling back to a short date beyond a week. */
function ownerNotifTimeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}

/** One icon per notifications.type, so the feed isn't just a wall of text. */
function ownerNotifIcon(string $type): string
{
    return match ($type) {
        'inventory'    => 'ph-package',
        'procurement'  => 'ph-clipboard-text',
        'cashier'      => 'ph-cash-register',
        'feedback'     => 'ph-chat-circle-dots',
        'reservation'  => 'ph-calendar-check',
        'payment'      => 'ph-credit-card',
        'order'        => 'ph-shopping-cart',
        default        => 'ph-info',
    };
}

/**
 * Where clicking a notification should go -- null means "don't make this one
 * clickable" (shouldn't happen in practice now, since every owner-facing
 * reference_type below has a real destination, but kept as a safe fallback
 * for any future type this hasn't been taught yet).
 *
 * item_id/batch_id deep-link into inventory.php's own client-side filters;
 * adjustment_id/wastage_id deep-link into their own list
 * pages' row-highlight scripts (same pattern, added alongside this file).
 *
 * Cross-module paths follow the exact convention already established in
 * owner/includes/sidebar.php ($ownerBase . '../module/page.php' for sibling
 * top-level modules, $ownerBase . 'sub/page.php' for pages that live inside
 * owner/ itself).
 */
function ownerNotifLink(string $referenceType, ?int $referenceId, string $ownerBase): ?string
{
    if ($referenceId === null) {
        return null;
    }

    switch ($referenceType) {
        case 'inventory_stock_out':
        case 'inventory_stock_critical':
        case 'inventory_stock_reorder':
        case 'inventory_cost_increase':
            return "{$ownerBase}../inventory/inventory.php?item_id={$referenceId}";
        case 'inventory_batch_expired':
        case 'inventory_batch_expiring':
            return "{$ownerBase}../inventory/inventory.php?tab=batches&batch_id={$referenceId}";
        case 'inventory_adjustment':
            return "{$ownerBase}../inventory/inventory_adjustments.php?adjustment_id={$referenceId}";
        case 'inventory_waste':
            return "{$ownerBase}../inventory/inventory_wastage.php?wastage_id={$referenceId}";
        case 'menu_cost_change':
            // Costing page, deep-linked to the dish that moved most (the
            // ?highlight= param costing_recalculate.php already redirects with).
            return "{$ownerBase}menu_management/costing.php?highlight={$referenceId}";
        case 'purchase_order_auto_created':
            return "{$ownerBase}../purchase_orders/purchase_order_view.php?id={$referenceId}";
        case 'shift_discrepancy':
            return "{$ownerBase}report.php?tab=cashier&shift_id={$referenceId}";
        case 'void_request':
            // The unfiltered queue, not ?status=pending: buildVoidRequestsQuery()
            // already floats pending rows to the top, and landing on an unfiltered
            // list still shows the request when a colleague has already decided it
            // -- a pending-only filter would render it invisible and read as if the
            // notification had been a mistake.
            return "{$ownerBase}../orders/orders.php?tab=void_requests";
        case 'feedback_flagged':
            // Moderation page, not sentiment. The notification exists because
            // something was withheld and needs a decision, and the deep-link
            // filters the moderation table down to that one row -- the
            // sentiment page no longer has a table to filter.
            return "{$ownerBase}feedback_moderation.php?feedback_id={$referenceId}";
    }

    return null;
}

/**
 * Renders the bell dropdown's <li> list -- shared by owner/includes/header.php's
 * initial page render and owner/notifications_poll.php's JSON response
 * (see the polling <script> added to header.php), so the two can never
 * drift into rendering a notification differently depending on which one
 * produced it.
 *
 * @param array $notifications Rows shaped like the SELECT in header.php:
 *   notification_id, type, title, message, reference_type, reference_id, is_read, created_at.
 */
function ownerNotifListHtml(array $notifications, string $ownerBase): string
{
    if (empty($notifications)) {
        return '<li class="owner-notif-empty">You\'re all caught up.</li>';
    }

    $html = '';
    foreach ($notifications as $n) {
        $referenceId = $n['reference_id'] !== null ? (int)$n['reference_id'] : null;
        $isClickable = ownerNotifLink($n['reference_type'], $referenceId, $ownerBase) !== null;
        $href = $isClickable ? "{$ownerBase}notification_go.php?id=" . (int)$n['notification_id'] : null;
        $unreadClass = $n['is_read'] ? '' : ' is-unread';

        $html .= '<li' . ($href !== null ? '' : ' class="owner-notif-item' . $unreadClass . '"') . '>';
        if ($href !== null) {
            $html .= '<a href="' . htmlspecialchars($href) . '" class="owner-notif-item' . $unreadClass . '">';
        }
        $html .= '<span class="owner-notif-icon"><i class="ph ' . ownerNotifIcon($n['type']) . '" aria-hidden="true"></i></span>';
        $html .= '<span class="owner-notif-body"><p>' . htmlspecialchars($n['title']) . ' ' . htmlspecialchars($n['message']) . '</p>';
        $html .= '<span>' . htmlspecialchars(ownerNotifTimeAgo($n['created_at'])) . '</span></span>';
        if ($href !== null) {
            $html .= '</a>';
        }
        $html .= '</li>';
    }

    return $html;
}
