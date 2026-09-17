<?php
/**
 * inventory/batch_fragment.php
 *
 * Chrome-free batch detail markup for inventory.php's Batches tab to fetch()
 * into a modal -- same fragment-endpoint convention as orders/order_fragment.php
 * and employee_management/payslip_fragment.php.
 *
 * Fetched on demand rather than embedded in the page like the Items tab's JSON:
 * the provenance joins (purchase order, receiving user) are only ever needed for
 * the one batch actually opened, and there are 92 on-hand batches.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['owner', 'manager'])) {
    http_response_code(403);
    exit;
}

$batchId = (int)($_GET['id'] ?? 0);
if ($batchId <= 0) {
    http_response_code(400);
    exit;
}

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare(
    "SELECT b.batch_id, b.batch_number, b.quantity_received, b.quantity_remaining,
            b.unit_cost, b.expiry_date, b.received_date, b.created_at,
            i.item_name, u.unit_code, s.supplier_name,
            rec.first_name AS rec_first, rec.last_name AS rec_last,
            po.po_number
     FROM inventory_batches b
     JOIN inventory_items i             ON i.item_id = b.item_id
     LEFT JOIN unit_of_measures u       ON u.unit_id = i.base_unit_id
     LEFT JOIN suppliers s              ON s.supplier_id = b.supplier_id
     LEFT JOIN users rec                ON rec.user_id = b.received_by
     LEFT JOIN purchase_order_items poi ON poi.po_item_id = b.po_item_id
     LEFT JOIN purchase_orders po       ON po.po_id = poi.po_id
     WHERE b.batch_id = ?"
);
$stmt->execute([$batchId]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
    echo '<div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Batch not found.</div>';
    exit;
}

$unit      = $b['unit_code'] ?? '';
$received  = (float)$b['quantity_received'];
$remaining = (float)$b['quantity_remaining'];
// Derived, never stored: what has left this batch by any route (sales, waste,
// adjustments). Clamped at 0 so a data oddity can't render a negative "used".
$consumed  = max(0.0, $received - $remaining);
$usedPct   = $received > 0 ? ($consumed / $received) * 100 : 0.0;
$valueOnHand = $remaining * (float)$b['unit_cost'];

$today  = new DateTime('today');
$expiry = $b['expiry_date'] ? new DateTime($b['expiry_date']) : null;
if ($expiry === null) {
    $expiryPill = ['label' => 'No expiry set', 'class' => 'is-active'];
} elseif ($expiry < $today) {
    $expiryPill = ['label' => 'Expired', 'class' => 'is-danger'];
} else {
    $days = $today->diff($expiry)->days;
    $expiryPill = $days <= 7
        ? ['label' => "Expires in {$days}d", 'class' => 'is-warning']
        : ['label' => 'Fresh', 'class' => 'is-success'];
}

/** Trailing zeros make 0.659 kg read as 0.659000 kg -- match the list's format. */
function batchQty(float $q): string
{
    return rtrim(rtrim(number_format($q, 3, '.', ','), '0'), '.');
}

?>
<div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <div style="font-family:'Lexend',sans-serif;font-size:1.1rem;font-weight:700;color:var(--op-ink);">
            <?= htmlspecialchars($b['item_name']) ?>
        </div>
        <div style="font-size:0.82rem;color:var(--op-ink-faint);margin-top:2px;">
            Batch <?= $b['batch_number'] !== null && $b['batch_number'] !== '' ? htmlspecialchars($b['batch_number']) : '#' . (int)$b['batch_id'] ?>
        </div>
    </div>
    <span class="owner-status-pill <?= $expiryPill['class'] ?>" style="align-self:flex-start;"><?= htmlspecialchars($expiryPill['label']) ?></span>
</div>

<div class="owner-form-section">
    <div class="owner-form-section-title">Stock on hand</div>
</div>
<div class="owner-form-grid" style="margin-bottom:22px;">
    <div class="owner-form-group">
        <label>Received</label>
        <div><?= batchQty($received) ?> <?= htmlspecialchars($unit) ?></div>
    </div>
    <div class="owner-form-group">
        <label>Used</label>
        <div><?= batchQty($consumed) ?> <?= htmlspecialchars($unit) ?> <span style="color:var(--op-ink-faint);">(<?= number_format($usedPct, 1) ?>%)</span></div>
    </div>
    <div class="owner-form-group">
        <label>Remaining</label>
        <div><strong><?= batchQty($remaining) ?> <?= htmlspecialchars($unit) ?></strong></div>
    </div>
    <div class="owner-form-group">
        <label>Unit cost</label>
        <div>&#8369;<?= number_format((float)$b['unit_cost'], 2) ?></div>
    </div>
    <div class="owner-form-group">
        <label>Value on hand</label>
        <div>&#8369;<?= number_format($valueOnHand, 2) ?></div>
    </div>
    <div class="owner-form-group">
        <label>Expiry date</label>
        <div><?= $b['expiry_date'] ? htmlspecialchars(date('M j, Y', strtotime($b['expiry_date']))) : '&mdash;' ?></div>
    </div>
</div>

<div class="owner-form-section" style="margin-top:4px;">
    <div class="owner-form-section-title">Where it came from</div>
</div>
<div class="owner-form-grid" style="margin-bottom:22px;">
    <div class="owner-form-group">
        <label>Supplier</label>
        <div><?= $b['supplier_name'] !== null ? htmlspecialchars($b['supplier_name']) : '&mdash;' ?></div>
    </div>
    <div class="owner-form-group">
        <label>Purchase order</label>
        <div><?= $b['po_number'] !== null ? htmlspecialchars($b['po_number']) : '&mdash;' ?></div>
    </div>
    <div class="owner-form-group">
        <label>Date received</label>
        <div><?= htmlspecialchars(date('M j, Y', strtotime($b['received_date']))) ?></div>
    </div>
    <div class="owner-form-group">
        <label>Received by</label>
        <div><?= $b['rec_first'] !== null ? htmlspecialchars(trim($b['rec_first'] . ' ' . $b['rec_last'])) : '&mdash;' ?></div>
    </div>
</div>
