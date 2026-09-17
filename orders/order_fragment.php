<?php
/**
 * orders/order_fragment.php
 *
 * Chrome-free order detail markup for orders.php's detail modal to
 * fetch() into innerHTML -- same fragment-endpoint convention as
 * cashier/api/receipt_fragment.php and employee_management/payslip_fragment.php.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/void_functions.php';
require_once __DIR__ . '/includes/order_functions.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['owner', 'manager'])) {
    http_response_code(403);
    exit;
}

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$order = getOrderWithDetails($pdo, $orderId);

if (!$order) {
    echo '<div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Order not found.</div>';
    exit;
}

// A reservation deposit already collected (reservation_payments) is credited
// against this order at checkout rather than being re-collected -- create_order.php
// never bakes that credit into total_amount, so the gap between total_amount and
// the actual order_payments.amount collected here is exactly the credit applied
// (same derivation cashier/api/receipt_fragment.php uses for the printed receipt).
$creditApplied = ($order['payment_status'] === 'paid' && $order['payment_amount'] !== null)
    ? round((float)$order['total_amount'] - (float)$order['payment_amount'], 2)
    : 0.0;

$customerName = $order['customer_first_name'] !== null
    ? htmlspecialchars($order['customer_first_name'] . ' ' . $order['customer_last_name'])
    : 'Walk-in';
$cashierName = $order['cashier_first_name'] !== null
    ? htmlspecialchars($order['cashier_first_name'] . ' ' . $order['cashier_last_name'])
    : '&mdash;';
?>
<div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
    <div>
        <div style="font-family:'Lexend',sans-serif;font-size:1.1rem;font-weight:700;color:var(--op-ink);"><?= htmlspecialchars($order['order_number']) ?></div>
        <div style="font-size:0.85rem;color:var(--op-ink-soft);"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at']))) ?></div>
    </div>
    <div style="display:flex;gap:8px;align-items:flex-start;">
        <span class="owner-status-pill <?= orderPaymentStatusBadgeClass($order['payment_status']) ?>"><?= htmlspecialchars(orderPaymentStatusLabel($order['payment_status'])) ?></span>
    </div>
</div>

<?php
// The void banner is rendered from the request record rather than from
// orders.order_status alone, so a reader gets the whole story in one place:
// that it was reversed, who asked, who approved, and -- when it matters -- that
// a closed shift's cash figures were deliberately left as counted.
$void = orderVoidState($pdo, $orderId);

if ($void !== null && in_array($void['status'], ['pending', 'approved'], true)):
    $isApproved = $void['status'] === 'approved';
    $requester = $void['requester_first_name'] !== null
        ? $void['requester_first_name'] . ' ' . $void['requester_last_name'] : 'Unknown';
    $reviewer = $void['reviewer_first_name'] !== null
        ? $void['reviewer_first_name'] . ' ' . $void['reviewer_last_name'] : null;
?>
<div class="owner-alert <?= $isApproved ? 'owner-alert-error' : '' ?>" style="margin-bottom:14px;<?= $isApproved ? '' : 'background:var(--op-canvas);color:var(--op-ink-soft);' ?>">
    <i class="ph <?= $isApproved ? 'ph-prohibit' : 'ph-clock-countdown' ?>" aria-hidden="true"></i>
    <span>
        <?php if ($isApproved): ?>
            <strong>This sale was voided.</strong>
            <?= htmlspecialchars($void['void_number']) ?> &mdash; requested by <?= htmlspecialchars($requester) ?>,
            approved<?= $reviewer !== null ? ' by ' . htmlspecialchars($reviewer) : '' ?><?= $void['reviewed_at'] ? ' on ' . htmlspecialchars(date('M j, Y g:i A', strtotime($void['reviewed_at']))) : '' ?>.
            Reason: <?= htmlspecialchars(voidReasonLabel($void['reason_code'])) ?>.
            <?= (int)$void['restored_txn_count'] > 0
                ? (int)$void['restored_txn_count'] . ' stock line(s) were returned to inventory.'
                : 'No stock movement was on record to return.' ?>
            <?php if ((int)$void['shift_was_closed'] === 1): ?>
                Its shift was already closed, so that shift&rsquo;s remitted cash figures were left as counted.
            <?php endif; ?>
        <?php else: ?>
            <strong>A void request is awaiting review.</strong>
            <?= htmlspecialchars($void['void_number']) ?> &mdash; raised by <?= htmlspecialchars($requester) ?>
            on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($void['created_at']))) ?>.
            Reason: <?= htmlspecialchars(voidReasonLabel($void['reason_code'])) ?>.
            This order is unchanged until it is approved.
        <?php endif; ?>
        <?php if (!empty($void['reason_notes'])): ?>
            <br>Cashier&rsquo;s note: &ldquo;<?= htmlspecialchars($void['reason_notes']) ?>&rdquo;
        <?php endif; ?>
        <?php if (!empty($void['review_notes'])): ?>
            <br>Reviewer&rsquo;s note: &ldquo;<?= htmlspecialchars($void['review_notes']) ?>&rdquo;
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>

<div class="owner-card" style="margin-bottom:14px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;font-size:0.85rem;">
        <div><div style="color:var(--op-ink-faint);font-size:0.72rem;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:3px;">Type</div><?= htmlspecialchars(orderTypeLabel($order['order_type'])) ?></div>
        <?php if ($order['reservation_id'] !== null): ?>
        <div><div style="color:var(--op-ink-faint);font-size:0.72rem;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:3px;">Reservation</div><?= $order['reservation_number'] ? htmlspecialchars($order['reservation_number']) : '&mdash;' ?></div>
        <?php endif; ?>
        <div><div style="color:var(--op-ink-faint);font-size:0.72rem;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:3px;">Cashier</div><?= $cashierName ?></div>
        <div><div style="color:var(--op-ink-faint);font-size:0.72rem;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:3px;">Customer</div><?= $customerName ?></div>
        <div><div style="color:var(--op-ink-faint);font-size:0.72rem;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:3px;">Payment method</div><?= htmlspecialchars(orderPaymentMethodLabel($order['payment_method'])) ?></div>
    </div>
</div>

<div class="owner-table-wrap" style="margin-bottom:14px;">
    <table class="owner-table">
        <thead>
            <tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Subtotal</th></tr>
        </thead>
        <tbody>
            <?php if (empty($order['items'])): ?>
                <tr><td colspan="4" class="owner-table-empty">No items recorded for this order.</td></tr>
            <?php else: ?>
                <?php foreach ($order['items'] as $item): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($item['item_name']) ?>
                            <?php if ($item['notes']): ?><div style="font-size:0.78rem;color:var(--op-ink-faint);"><?= htmlspecialchars($item['notes']) ?></div><?php endif; ?>
                        </td>
                        <td><?= (int)$item['quantity'] ?></td>
                        <td>&#8369;<?= number_format((float)$item['unit_price'], 2) ?></td>
                        <td>&#8369;<?= number_format((float)$item['subtotal'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<table style="width:100%;max-width:320px;margin-left:auto;font-size:0.88rem;">
    <tr><td style="padding:3px 0;color:var(--op-ink-soft);">Subtotal</td><td style="padding:3px 0;text-align:right;">&#8369;<?= number_format((float)$order['subtotal'], 2) ?></td></tr>
    <?php if ((float)$order['discount_amount'] > 0): ?>
        <tr><td style="padding:3px 0;color:var(--op-ink-soft);">Discount<?= $order['discount_name'] ? ' (' . htmlspecialchars($order['discount_name']) . ')' : '' ?></td><td style="padding:3px 0;text-align:right;">&minus;&#8369;<?= number_format((float)$order['discount_amount'], 2) ?></td></tr>
    <?php endif; ?>
    <tr><td style="padding:3px 0;color:var(--op-ink-soft);">VAT</td><td style="padding:3px 0;text-align:right;">&#8369;<?= number_format((float)$order['vat_amount'], 2) ?></td></tr>
    <?php if ((float)$order['packaging_fee_amount'] > 0): ?>
        <tr><td style="padding:3px 0;color:var(--op-ink-soft);">Packaging fee</td><td style="padding:3px 0;text-align:right;">&#8369;<?= number_format((float)$order['packaging_fee_amount'], 2) ?></td></tr>
    <?php endif; ?>
    <tr style="font-weight:700;font-family:'Lexend',sans-serif;"><td style="padding:6px 0 0;border-top:1px solid var(--op-border);">Total</td><td style="padding:6px 0 0;border-top:1px solid var(--op-border);text-align:right;">&#8369;<?= number_format((float)$order['total_amount'], 2) ?></td></tr>
    <?php if ($creditApplied > 0): ?>
        <tr><td style="padding:3px 0;color:var(--op-ink-soft);">Reservation credit applied</td><td style="padding:3px 0;text-align:right;">&minus;&#8369;<?= number_format($creditApplied, 2) ?></td></tr>
    <?php endif; ?>
    <?php if ($order['payment_status'] === 'paid'):
        $isCash = $order['payment_method'] === 'cash';
        $tendered = $order['amount_tendered'] !== null ? (float)$order['amount_tendered'] : null;
    ?>
        <?php if ($isCash && $tendered !== null): ?>
            <tr><td style="padding:8px 0 0;color:var(--op-ink-soft);">Cash received</td><td style="padding:8px 0 0;text-align:right;">&#8369;<?= number_format($tendered, 2) ?></td></tr>
            <tr><td style="padding:3px 0;color:var(--op-ink-soft);">Change</td><td style="padding:3px 0;text-align:right;">&#8369;<?= number_format(max(0, round($tendered - (float)$order['payment_amount'], 2)), 2) ?></td></tr>
        <?php else: ?>
            <tr><td style="padding:8px 0 0;color:var(--op-ink-soft);">Amount received</td><td style="padding:8px 0 0;text-align:right;">&#8369;<?= number_format((float)$order['payment_amount'], 2) ?></td></tr>
        <?php endif; ?>
        <tr><td style="padding:3px 0;color:var(--op-ink-soft);">Paid at</td><td style="padding:3px 0;text-align:right;"><?= $order['paid_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($order['paid_at']))) : '&mdash;' ?></td></tr>
    <?php endif; ?>
</table>
