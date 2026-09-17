<?php
/**
 * manager/includes/notification_functions.php
 *
 * Shared helpers for the Manager panel's notification bell
 * (manager/includes/header.php), the full history page
 * (manager/notifications.php), and the read/redirect click-through
 * (manager/notification_go.php) -- all three read off the real per-user
 * `notifications` table (config/notifications.php's createNotificationOnce()/
 * notifyUsersByRole()), not activity_logs. Manager-specific on purpose
 * (owner has its own near-identical copy in owner/includes/notification_functions.php)
 * since the two roles' reachable destinations genuinely differ -- see
 * managerNotifLink() below (payroll cutoffs are manager-only; purchase
 * purchase-order activity is owner-only).
 */

/** "5m ago" / "3h ago" / "2d ago", falling back to a short date beyond a week. */
function managerNotifTimeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}

/** One icon per notifications.type, so the feed isn't just a wall of text. */
function managerNotifIcon(string $type): string
{
    return match ($type) {
        'inventory'    => 'ph-package',
        'procurement'  => 'ph-clipboard-text',
        'payroll'      => 'ph-money',
        'attendance'   => 'ph-chart-line-up',
        'reservation'  => 'ph-calendar-check',
        'payment'      => 'ph-credit-card',
        'order'        => 'ph-shopping-cart',
        default        => 'ph-info',
    };
}

/**
 * Where clicking a notification should go -- null means "don't make this one
 * clickable". payroll_cutoff_due_* returns a plain (non-prefilled) link to
 * employee_management/runs.php here as a safe fallback; manager/notification_go.php
 * special-cases that reference_type BEFORE calling this, to build the real
 * prefilled-modal URL from payroll_settings + the reference_id (the end
 * date), since that reconstruction needs a live DB connection this function
 * doesn't have.
 */
function managerNotifLink(string $referenceType, ?int $referenceId, string $managerBase): ?string
{
    if ($referenceId === null) {
        return null;
    }

    switch ($referenceType) {
        case 'inventory_stock_out':
        case 'inventory_stock_critical':
        case 'inventory_stock_reorder':
        case 'inventory_cost_increase':
            return "{$managerBase}../inventory/inventory.php?item_id={$referenceId}";
        case 'inventory_batch_expired':
        case 'inventory_batch_expiring':
            return "{$managerBase}../inventory/inventory.php?tab=batches&batch_id={$referenceId}";
        case 'inventory_adjustment':
            return "{$managerBase}../inventory/inventory_adjustments.php?adjustment_id={$referenceId}";
        case 'inventory_waste':
            return "{$managerBase}../inventory/inventory_wastage.php?wastage_id={$referenceId}";
        case 'purchase_order_auto_created':
            // Deliberately the list, not purchase_order_view.php?id=N. These
            // notifications are Owner-only now (config/inventory_alerts.php),
            // but rows sent to Managers before the purchasing/receiving split
            // are still sitting in their bell -- and they point at drafts the
            // Manager's own guard refuses, which would bounce them with an
            // error instead of opening anything.
            return "{$managerBase}../purchase_orders/purchase_orders.php";
        case 'payroll_cutoff_due_monthly':
        case 'payroll_cutoff_due_semi_monthly':
            return "{$managerBase}../employee_management/runs.php";
        case 'attendance_critical_threshold':
            return "{$managerBase}../employee_management/performance_monitoring.php?employee_id={$referenceId}";
        case 'attendance_import_pending':
            /* reference_id is the date as YYYYMMDD (notifyPendingAttendanceImports(),
               config/attendance_alerts.php), which attendance.php's own "Missing
               attendance records" panel then lists employee by employee.

               Rows written before that notification was aggregated per date still
               hold a schedule_id instead, and decoding one of those as a date would
               send the manager to a nonsense day. Anything that isn't a real
               calendar date falls back to the unfiltered page, which still opens
               somewhere useful. */
            $y = (int)substr((string)$referenceId, 0, 4);
            $m = (int)substr((string)$referenceId, 4, 2);
            $d = (int)substr((string)$referenceId, 6, 2);
            if ($referenceId >= 20000101 && $referenceId <= 99991231 && checkdate($m, $d, $y)) {
                $date = sprintf('%04d-%02d-%02d', $y, $m, $d);
                return "{$managerBase}../employee_management/attendance.php?date={$date}&missing=1";
            }
            return "{$managerBase}../employee_management/attendance.php";
        case 'void_request':
            // The unfiltered queue, not ?status=pending: buildVoidRequestsQuery()
            // already floats pending rows to the top, and landing on an unfiltered
            // list still shows the request when a colleague has already decided it
            // -- a pending-only filter would render it invisible and read as if the
            // notification had been a mistake.
            return "{$managerBase}../orders/orders.php?tab=void_requests";
    }

    return null;
}

/**
 * Renders the bell dropdown's <li> list -- shared by manager/includes/header.php's
 * initial page render and manager/notifications_poll.php's JSON response,
 * so the two can never drift into rendering a notification differently
 * depending on which one produced it.
 */
function managerNotifListHtml(array $notifications, string $managerBase): string
{
    if (empty($notifications)) {
        return '<li class="owner-notif-empty">You\'re all caught up.</li>';
    }

    $html = '';
    foreach ($notifications as $n) {
        $referenceId = $n['reference_id'] !== null ? (int)$n['reference_id'] : null;
        $isClickable = managerNotifLink($n['reference_type'], $referenceId, $managerBase) !== null;
        $href = $isClickable ? "{$managerBase}notification_go.php?id=" . (int)$n['notification_id'] : null;
        $unreadClass = $n['is_read'] ? '' : ' is-unread';

        $html .= '<li' . ($href !== null ? '' : ' class="owner-notif-item' . $unreadClass . '"') . '>';
        if ($href !== null) {
            $html .= '<a href="' . htmlspecialchars($href) . '" class="owner-notif-item' . $unreadClass . '">';
        }
        $html .= '<span class="owner-notif-icon"><i class="ph ' . managerNotifIcon($n['type']) . '" aria-hidden="true"></i></span>';
        $html .= '<span class="owner-notif-body"><p>' . htmlspecialchars($n['title']) . ' ' . htmlspecialchars($n['message']) . '</p>';
        $html .= '<span>' . htmlspecialchars(managerNotifTimeAgo($n['created_at'])) . '</span></span>';
        if ($href !== null) {
            $html .= '</a>';
        }
        $html .= '</li>';
    }

    return $html;
}
