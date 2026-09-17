<?php
/**
 * orders/includes/order_functions.php
 *
 * Shared helpers for the Orders module — a read-only history/oversight
 * view of orders.* for Owner/Manager, distinct from cashier/pos.php (the
 * checkout terminal that actually creates these rows).
 */

const ORDER_TYPE_LABELS = [
    'dine_in' => 'Dine In',
    'takeout' => 'Takeout',
];

/** Plain label for the real order_type ENUM value -- never overloaded with reservation status (see orderSourceLabel() for that, kept as a separate concept so "Type" always means what the database column actually says). */
function orderTypeLabel(string $type): string
{
    return ORDER_TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/**
 * order_type's ENUM only has 'dine_in'/'takeout' -- there is no 'reservation'
 * value. Whether an order came from a reservation is a separate fact, driven
 * by reservation_id: walk-ins = reservation_id IS NULL, reservations =
 * reservation_id IS NOT NULL. Kept as its own "Source" label/filter (rather
 * than folded into Type) so the Type column always matches the real column
 * value and never reads like a third order_type to anyone viewing the page.
 */
function orderSourceLabel($reservationId): string
{
    return $reservationId !== null ? 'Reservation' : 'Walk-in';
}

const ORDER_SOURCE_OPTIONS = [
    'walk_in'     => 'Walk-in',
    'reservation' => 'Reservation',
];

function orderPaymentStatusBadgeClass(?string $status): string
{
    return match ($status) {
        'paid'   => 'is-success',
        'failed' => 'is-danger',
        'voided' => 'is-danger',
        default  => 'is-neutral', // pending / no payment row yet
    };
}

function orderPaymentStatusLabel(?string $status): string
{
    return $status === null ? 'No payment' : ucfirst($status);
}

function orderPaymentMethodLabel(?string $method): string
{
    return match ($method) {
        'cash'             => 'Cash',
        'paymongo_gcash'   => 'GCash',
        'paymongo_card'    => 'Card',
        'paymongo_paymaya' => 'Maya',
        default            => $method === null ? '&mdash;' : ucfirst(str_replace('_', ' ', $method)),
    };
}

/**
 * Shared query builder for the order list, its pagination COUNT(*), and
 * the print view -- same "one builder, several consumers" convention as
 * buildInventoryTransactionQuery() (inventory/includes/inventory_functions.php)
 * and buildPayrollRunsQuery() (employee_management/includes/payroll_functions.php).
 *
 * Every order has exactly one order_payments row in practice (confirmed
 * against live data, and every write path only ever inserts one) -- the
 * LEFT JOIN below doesn't defensively dedupe against a hypothetical
 * second row, matching how cashier/includes/pos_functions.php's
 * computeShiftTotals() already treats this same relationship.
 *
 * orders.order_status IS selected now, but isn't offered as its own filter. No
 * code writes anything but 'open' to it except the void workflow, where an
 * approved void writes 'voided' -- so the column is read here purely to mark
 * reversed orders in the Status column, not to filter by.
 *
 * There is no payment-status filter on the page any more either. The clause
 * below still honours $filters['payment_status'] if a caller passes one, but
 * nothing in the UI sets it: every payment this POS writes is 'paid' (see
 * cashier/api/create_order.php), so the only value that ever differed was
 * 'voided', which the Status column already shows and the Void requests tab
 * lists in full.
 */
function buildOrdersQuery(array $filters): array
{
    $sql = "
        SELECT
            o.order_id, o.order_number, o.order_type, o.reservation_id,
            o.total_amount, o.created_at, o.cashier_id, o.customer_id, o.order_status,
            cu.first_name AS cashier_first_name, cu.last_name AS cashier_last_name,
            cust.first_name AS customer_first_name, cust.last_name AS customer_last_name,
            op.payment_method, op.payment_status, r.reservation_number
        FROM orders o
        LEFT JOIN users cu ON cu.user_id = o.cashier_id
        LEFT JOIN users cust ON cust.user_id = o.customer_id
        LEFT JOIN order_payments op ON op.order_id = o.order_id
        LEFT JOIN reservations r ON r.reservation_id = o.reservation_id
    ";

    $where  = [];
    $params = [];

    if (!empty($filters['date_from'])) {
        $where[] = 'o.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'o.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['order_type'])) {
        $where[] = 'o.order_type = ?';
        $params[] = $filters['order_type'];
    }
    if (!empty($filters['order_source'])) {
        $where[] = $filters['order_source'] === 'reservation'
            ? 'o.reservation_id IS NOT NULL'
            : 'o.reservation_id IS NULL';
    }
    if (!empty($filters['payment_status'])) {
        $where[] = 'op.payment_status = ?';
        $params[] = $filters['payment_status'];
    }
    if (!empty($filters['cashier_id'])) {
        $where[] = 'o.cashier_id = ?';
        $params[] = $filters['cashier_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = 'o.order_number LIKE ?';
        $params[] = '%' . $filters['search'] . '%';
    }
    if (!empty($filters['item_id'])) {
        // EXISTS rather than a JOIN -- order_items is a one-to-many child of
        // orders, and this filter only needs to test presence, not pull any
        // of its own columns into the result set (a JOIN would duplicate a
        // multi-item order's row once per matching line).
        $where[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.order_id AND oi.menu_item_id = ?)';
        $params[] = $filters['item_id'];
    }

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY o.created_at DESC, o.order_id DESC';

    return [$sql, $params];
}

/**
 * Self-contained, professional print/PDF document for the order history
 * list -- same convention as owner/includes/report_functions.php's
 * renderReportPrintDocumentHtml() (own letterhead, bordered doc-table,
 * no dashboard chrome). Deliberately does NOT load owner-panel.css: that
 * stylesheet's `@media print` rule is scoped to printing an *open modal*
 * (`.owner-modal-backdrop.is-open`) and hides everything else on the page
 * when printing -- loading it here would print a blank page, which is
 * exactly what orders.php's old "reuse the on-screen table + window.print()"
 * approach silently did. Building a fully separate print document sidesteps
 * that rule entirely, the same way report.php's does.
 */
function renderOrdersPrintDocumentHtml(array $orders, string $restaurantName, string $filterSummary, bool $includeActions, string $printUrl = '', string $downloadUrl = '', string $backUrl = ''): string
{
    // Voided orders stay VISIBLE in the printed list -- a reversed sale is part
    // of the history and hiding it would make the document disagree with the
    // screen -- but they are excluded from every total. A voided order counted
    // toward "Total revenue" would overstate takings by exactly the amount that
    // was reversed, which is the one number this report exists to get right.
    $voided       = array_filter($orders, fn($o) => ($o['order_status'] ?? '') === 'voided');
    $counted      = array_filter($orders, fn($o) => ($o['order_status'] ?? '') !== 'voided');
    $voidedCount  = count($voided);
    $voidedValue  = array_sum(array_column($voided, 'total_amount'));
    $totalOrders  = count($counted);
    $totalRevenue = array_sum(array_column($counted, 'total_amount'));
    $avgOrder     = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

    ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<?php if ($includeActions): ?><meta name="viewport" content="width=device-width, initial-scale=1.0"><?php endif; ?>
<title>Order History | <?= htmlspecialchars($restaurantName) ?></title>
<style>
    @page{ size:A4; margin:15mm; }
    body{ font-family:"DejaVu Sans", Helvetica, Arial, sans-serif; color:#000; font-size:12px; margin:0; padding:0; background:<?= $includeActions ? '#f6f3ec' : '#fff' ?>; }
    .doc{ max-width:820px; margin:0 auto; padding:32px; background:#fff; <?= $includeActions ? 'border:1px solid #ddd; margin-top:24px; margin-bottom:24px;' : '' ?> }
    .doc-header{ width:100%; border-collapse:collapse; margin-bottom:14px; }
    .doc-header td{ vertical-align:top; padding:0; }
    h1{ font-size:20px; margin:0 0 4px; }
    .muted{ color:#555; }
    .divider{ border-top:1px solid #000; margin:14px 0; }
    table.doc-table{ width:100%; border-collapse:collapse; margin-top:6px; }
    table.doc-table th, table.doc-table td{ border:1px solid #000; padding:6px 8px; font-size:11px; text-align:left; }
    table.doc-table th{ background:#eee; }
    table.doc-table td.num, table.doc-table th.num{ text-align:right; }
    table.doc-kpi-table{ width:auto; min-width:340px; }
    table.doc-kpi-table td{ font-size:12px; }
    .empty-note{ font-size:11px; color:#555; font-style:italic; margin:6px 0; }
    .footer{ margin-top:24px; font-size:10px; color:#555; }
    <?php if ($includeActions): ?>
    .pdf-actions{ max-width:820px; margin:14px auto 24px; display:flex; justify-content:center; gap:10px; }
    .pdf-actions button, .pdf-actions a{ display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:8px; border:1px solid #ddd; background:#fff; color:#000; font-family:sans-serif; font-size:0.85rem; font-weight:600; cursor:pointer; text-decoration:none; }
    .pdf-actions .primary{ background:#9c7734; border-color:#9c7734; color:#fff; }
    @media print{ .pdf-actions{ display:none !important; } body{ background:#fff; } .doc{ border:none; margin-top:0; } }
    <?php endif; ?>
</style>
</head>
<body>
<div class="doc">
    <table class="doc-header">
        <tr>
            <td>
                <h1><?= htmlspecialchars($restaurantName) ?></h1>
                <div class="muted">Order History Report</div>
            </td>
            <td style="text-align:right;">
                <div><strong><?= htmlspecialchars($filterSummary) ?></strong></div>
                <div class="muted" style="margin-top:4px;">Printed <?= htmlspecialchars(date('M j, Y g:i A')) ?></div>
            </td>
        </tr>
    </table>
    <div class="divider"></div>

    <table class="doc-table doc-kpi-table">
        <tr><td>Total orders</td><td class="num"><strong><?= number_format($totalOrders) ?></strong></td></tr>
        <tr><td>Total revenue</td><td class="num"><strong>&#8369;<?= number_format($totalRevenue, 2) ?></strong></td></tr>
        <tr><td>Avg order value</td><td class="num"><strong>&#8369;<?= number_format($avgOrder, 2) ?></strong></td></tr>
        <?php if ($voidedCount > 0): ?>
        <tr><td>Voided (excluded above)</td><td class="num"><strong><?= number_format($voidedCount) ?> &mdash; &#8369;<?= number_format($voidedValue, 2) ?></strong></td></tr>
        <?php endif; ?>
    </table>

    <h1 style="font-size:14px;margin:22px 0 8px;border-bottom:1px solid #000;padding-bottom:4px;">Orders</h1>
    <?php if (empty($orders)): ?>
        <div class="empty-note">No orders match your filters.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead>
                <tr><th>Order #</th><th>Date &amp; time</th><th>Type</th><th>Visit type</th><th>Cashier</th><th>Payment method</th><th class="num">Total</th></tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o):
                    $rowVoided = ($o['order_status'] ?? '') === 'voided';
                ?>
                <tr>
                    <td><?= htmlspecialchars($o['order_number']) ?><?= $rowVoided ? ' (VOID)' : '' ?></td>
                    <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($o['created_at']))) ?></td>
                    <td><?= htmlspecialchars(orderTypeLabel($o['order_type'])) ?></td>
                    <td><?= htmlspecialchars(orderSourceLabel($o['reservation_id'])) ?></td>
                    <td><?= $o['cashier_first_name'] !== null ? htmlspecialchars($o['cashier_first_name'] . ' ' . $o['cashier_last_name']) : '&mdash;' ?></td>
                    <td><?= htmlspecialchars(orderPaymentMethodLabel($o['payment_method'])) ?></td>
                    <?php // Struck through AND parenthesised: Dompdf renders the line-through, but
                          // a printed page photocopied in greyscale still has to read as reversed. ?>
                    <td class="num"<?= $rowVoided ? ' style="text-decoration:line-through;"' : '' ?>>&#8369;<?= number_format((float)$o['total_amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="footer">Generated <?= htmlspecialchars(date('M j, Y g:i A')) ?> &mdash; <?= htmlspecialchars($restaurantName) ?></div>
</div>

<?php if ($includeActions): ?>
<div class="pdf-actions">
    <button type="button" class="primary" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>
    <?php if ($downloadUrl !== ''): ?><a href="<?= htmlspecialchars($downloadUrl) ?>"><i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF</a><?php endif; ?>
    <?php if ($backUrl !== ''): ?><a href="<?= htmlspecialchars($backUrl) ?>"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back</a><?php endif; ?>
</div>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<?php endif; ?>

</body>
</html>
    <?php
    return ob_get_clean();
}

/** One order + its line items + its payment, for the detail modal/fragment. */
function getOrderWithDetails(PDO $db, int $orderId): ?array
{
    $stmt = $db->prepare(
        "SELECT o.*, cu.first_name AS cashier_first_name, cu.last_name AS cashier_last_name,
                cust.first_name AS customer_first_name, cust.last_name AS customer_last_name,
                op.payment_method, op.payment_status, op.amount AS payment_amount,
                op.amount_tendered, op.paid_at, r.reservation_number
         FROM orders o
         LEFT JOIN users cu ON cu.user_id = o.cashier_id
         LEFT JOIN users cust ON cust.user_id = o.customer_id
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         LEFT JOIN reservations r ON r.reservation_id = o.reservation_id
         WHERE o.order_id = ?"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return null;
    }

    $itemsStmt = $db->prepare(
        "SELECT oi.order_item_id, oi.quantity, oi.unit_price, oi.subtotal, oi.status, oi.notes,
                mi.item_name
         FROM order_items oi
         JOIN menu_items mi ON mi.item_id = oi.menu_item_id
         WHERE oi.order_id = ?
         ORDER BY oi.order_item_id ASC"
    );
    $itemsStmt->execute([$orderId]);
    $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    return $order;
}
