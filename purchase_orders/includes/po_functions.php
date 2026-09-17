<?php
/**
 * purchase_orders/includes/po_functions.php
 *
 * Shared helpers for the Purchase Orders module.
 * Self-contained on purpose (small helpers like poFmtQtyPlain()/poComboboxOptions()
 * are duplicated rather than required from inventory/ or owner/ — same
 * convention already used between those two modules).
 */

/* -- Role split -------------------------------------------------------------
   The Owner owns the purchasing pipeline: creating, editing, finalizing,
   emailing, cancelling. The Manager's only job here is receiving deliveries,
   so a PO is invisible to them until it has actually been placed with the
   supplier -- drafts, pending approval and approved POs are commercial
   decisions that aren't theirs to see or act on.

   'received' stays in the Manager's set even though it is no longer
   actionable: purchase_order_receive.php flips a fully-received PO to
   'received' and then redirects to its view page, so dropping it here would
   bounce a Manager out of the very screen confirming what they just did. */
const PO_MANAGER_VISIBLE_STATUSES = ['ordered', 'partially_received', 'received'];

/** True for the Owner, who alone may create/edit/finalize/email/cancel a PO. */
function poCanManage(): bool
{
    return Session::hasRole(['owner']);
}

/** True when the current user is allowed to open this PO at all. */
function poCanView(string $status): bool
{
    return poCanManage() || in_array($status, PO_MANAGER_VISIBLE_STATUSES, true);
}

/** Bounce a Manager who reached an Owner-only screen or a PO they can't see. */
function poDenyAccess(string $message = 'You do not have access to that purchase order.'): void
{
    flash_set('error', $message);
    header('Location: purchase_orders.php');
    exit;
}

/**
 * When this PO was last emailed to its supplier, or null if it never was.
 *
 * There is no sent_at column on purchase_orders -- the only record a send ever
 * happened is the activity_logs row purchase_order_email.php writes. Matching
 * on reference_id covers rows written from now on; the po_number LIKE covers
 * the ones logged before that column was populated.
 *
 * Worth being exact about, because 'ordered' does NOT imply "emailed": a PO
 * can reach that status without any message being sent.
 */
function poLastEmailedAt(PDO $db, int $poId, string $poNumber): ?string
{
    $stmt = $db->prepare(
        "SELECT MAX(created_at) FROM activity_logs
         WHERE action = 'Email purchase order'
           AND (reference_id = ? OR description LIKE ?)"
    );
    $stmt->execute([$poId, '%' . $poNumber . '%']);
    $at = $stmt->fetchColumn();
    return $at !== false && $at !== null ? (string)$at : null;
}

/** Format a decimal quantity without trailing zeros, e.g. 12.500 -> 12.5 */
function poFmtQtyPlain($val): string
{
    return rtrim(rtrim(number_format((float)$val, 3, '.', ''), '0'), '.') ?: '0';
}

/**
 * Serialize rows into the [{id, label}, ...] JSON the searchable combobox
 * widget reads from a field's data-options attribute. Named poComboboxOptions()
 * (not the plain comboboxOptions() used elsewhere) because
 * config/inventory_alerts.php requires this file alongside
 * owner/includes/demand_forecast_functions.php, which transitively requires
 * owner/menu_management/includes/menu_functions.php -- that file has its own
 * comboboxOptions() with identical signature, so loading both under the same
 * name would fatal with "Cannot redeclare". Renamed the module-local copy
 * here rather than menu_functions.php's, since every call site is contained
 * within purchase_orders/ itself (checked before renaming).
 */
function poComboboxOptions(array $rows, string $idKey, string $labelKey): string
{
    $opts = array_map(
        fn($r) => ['id' => (string)$r[$idKey], 'label' => (string)$r[$labelKey]],
        $rows
    );
    return htmlspecialchars(json_encode($opts), ENT_QUOTES, 'UTF-8');
}

/** JSON blob for a table row's data-* attribute. */
function poRowJson(array $data): string
{
    return htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8');
}

/** PO-{year}-{seq}. Resets yearly and stays 3 digits, so the lexicographic
    ORDER BY is safe here (unlike supplier_code, which never resets). Callers
    must catch a duplicate-key error (SQLSTATE 23000) on insert and retry with
    a fresh number -- the UNIQUE constraint is the real safety net. */
function generatePoNumber(PDO $db): string
{
    $prefix = 'PO-' . date('Y') . '-';
    $stmt = $db->prepare('SELECT MAX(CAST(SUBSTRING(po_number, ?) AS UNSIGNED)) FROM purchase_orders WHERE po_number LIKE ?');
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $next = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/** Status pill class for a purchase_orders.status value. */
function poOrderStatusPillClass(string $status): string
{
    return match ($status) {
        'draft'              => 'is-inactive',
        'pending_approval'   => 'is-warning',
        'approved'           => 'is-warning',
        'ordered'            => 'is-active',
        'partially_received' => 'is-active',
        'received'           => 'is-success',
        'cancelled'          => 'is-danger',
        default              => 'is-inactive',
    };
}

/**
 * The tax_settings row currently in effect, same active-row lookup used
 * by cashier/includes/pos_functions.php::poGetActiveTaxSettings(). Falls
 * back to 0% rather than blocking a save if nothing is configured yet.
 */
function poGetActiveTaxSettings(PDO $db): array
{
    $row = $db->query(
        'SELECT tax_setting_id, tax_name, tax_rate, tax_type FROM tax_settings
         WHERE is_active = 1
         ORDER BY effective_date DESC, tax_setting_id DESC
         LIMIT 1'
    )->fetch();

    if (!$row) {
        return ['tax_setting_id' => null, 'tax_name' => 'VAT', 'tax_rate' => 0.0, 'tax_type' => 'exclusive'];
    }

    $row['tax_rate'] = (float)$row['tax_rate'];

    return $row;
}

/**
 * Split a set of PO lines into net/VAT/total, ported from
 * cashier/includes/pos_functions.php::computeOrderTotals() against
 * inventory_items.is_vat_exempt instead of menu_items.is_vat_exempt.
 *
 * $lines: array of ['line_gross' => float, 'is_vat_exempt' => bool]
 * (line_gross = quantity * unit_cost for that line).
 *
 * subtotal_amount is the NET (pre-VAT) amount in both tax_type branches —
 * the only definition under which total_amount = subtotal + vat_amount
 * holds true either way.
 */
function computePOTotals(array $lines, array $taxSettings): array
{
    $rate = (float)$taxSettings['tax_rate'];
    $inclusive = $taxSettings['tax_type'] === 'inclusive';

    $subtotal = 0.0;
    $vatAmount = 0.0;

    foreach ($lines as $line) {
        $gross  = (float)$line['line_gross'];
        $exempt = !empty($line['is_vat_exempt']);

        if ($inclusive) {
            $lineVat = $exempt ? 0.0 : round($gross * $rate / (100 + $rate), 2);
            $lineNet = round($gross - $lineVat, 2);
        } else {
            $lineNet = $gross;
            $lineVat = $exempt ? 0.0 : round($lineNet * $rate / 100, 2);
        }

        $subtotal  += $lineNet;
        $vatAmount += $lineVat;
    }

    $subtotal    = round($subtotal, 2);
    $vatAmount   = round($vatAmount, 2);
    $totalAmount = round($subtotal + $vatAmount, 2);

    return ['subtotal' => $subtotal, 'vat_amount' => $vatAmount, 'total_amount' => $totalAmount];
}

/**
 * Creates a new draft purchase order + its line items -- the reusable
 * core of purchase_order_save.php's create path (see that file for the
 * original, request-shaped version), stripped of anything HTTP-specific
 * (POST parsing, CSRF, flash messages, redirects) so it can also be
 * called from config/inventory_alerts.php's auto purchase order sweep.
 * Both callers stay on the exact same totals/VAT/po_number logic this way
 * instead of two copies drifting apart.
 *
 * Caller owns the transaction (the auto-sweep writes its own activity_logs
 * row with the real po_id afterward, inside the same transaction).
 *
 * $lines: [['item_id' => int, 'quantity' => float, 'unit_cost' => float,
 *           'is_vat_exempt' => bool], ...] -- caller has already resolved
 * is_vat_exempt from inventory_items itself (never trust a stale/client
 * value), same as purchase_order_save.php does.
 *
 * Returns ['po_id' => int, 'po_number' => string, 'total_amount' => float].
 */
function createPurchaseOrderCore(
    PDO $db,
    int $supplierId,
    string $orderDate,
    ?string $expectedDeliveryDate,
    array $lines,
    int $createdBy,
    ?string $remarks = null,
    bool $isAutoGenerated = false
): array {
    $taxSettings = poGetActiveTaxSettings($db);
    $totalsLines = array_map(
        fn($l) => ['line_gross' => round($l['quantity'] * $l['unit_cost'], 2), 'is_vat_exempt' => $l['is_vat_exempt']],
        $lines
    );
    $totals = computePOTotals($totalsLines, $taxSettings);

    $poId = null;
    $poNumber = null;
    $attempts = 0;
    while ($poId === null) {
        $attempts++;
        $candidateNumber = generatePoNumber($db);
        try {
            $ins = $db->prepare(
                "INSERT INTO purchase_orders (po_number, supplier_id, status, order_date, expected_delivery_date,
                    subtotal_amount, vat_amount, is_vat_inclusive, tax_setting_id, total_amount, created_by, is_auto_generated, remarks)
                 VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([
                $candidateNumber, $supplierId, $orderDate, $expectedDeliveryDate,
                $totals['subtotal'], $totals['vat_amount'], $taxSettings['tax_type'] === 'inclusive' ? 1 : 0, $taxSettings['tax_setting_id'], $totals['total_amount'],
                $createdBy, $isAutoGenerated ? 1 : 0, $remarks,
            ]);
            $poId = (int)$db->lastInsertId();
            $poNumber = $candidateNumber;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && $attempts < 3) {
                continue;
            }
            throw $e;
        }
    }

    $insItem = $db->prepare(
        'INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_cost, subtotal, vat_amount)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($lines as $i => $line) {
        $lineTotals = computePOTotals([$totalsLines[$i]], $taxSettings);
        $insItem->execute([$poId, $line['item_id'], $line['quantity'], $line['unit_cost'], $lineTotals['subtotal'], $lineTotals['vat_amount']]);
    }

    return ['po_id' => $poId, 'po_number' => $poNumber, 'total_amount' => $totals['total_amount']];
}

/**
 * Self-contained, print/PDF-safe HTML document for one purchase order --
 * plain tables, black & white, no flexbox/grid (Dompdf's CSS support is
 * limited to a mostly-table-based subset). One source of truth shared by
 * purchase_order_pdf.php (browser preview + real download) and
 * purchase_order_email.php (PDF attachment), so all three stay identical.
 *
 * @param array $po     Row from purchase_orders joined with supplier +
 *                       created_by fields (supplier_name, contact_person,
 *                       phone, supplier_email, created_by_name).
 * @param array $items  Rows: item_name, unit_code, quantity_ordered,
 *                       unit_cost, subtotal, vat_amount.
 */
function renderPurchaseOrderDocumentHtml(array $po, array $items, string $restaurantName, bool $includeActions = false): string
{
    ob_start();
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<?php if ($includeActions): ?><meta name="viewport" content="width=device-width, initial-scale=1.0"><?php endif; ?>
<title><?= htmlspecialchars($po['po_number']) ?> | Purchase Order</title>
<style>
    @page{ size:A4; margin:15mm; }
    body{ font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color:#000; font-size:12px; margin:0; padding:0; background:<?= $includeActions ? '#f6f3ec' : '#fff' ?>; }
    .doc{ max-width:760px; margin:0 auto; padding:32px; background:#fff; <?= $includeActions ? 'border:1px solid #ddd; margin-top:24px;' : '' ?> }
    .doc-header{ width:100%; border-collapse:collapse; margin-bottom:14px; }
    .doc-header td{ vertical-align:top; padding:0; }
    h1{ font-size:20px; margin:0 0 4px; }
    .muted{ color:#555; }
    .divider{ border-top:1px solid #000; margin:14px 0; }
    table.doc-table{ width:100%; border-collapse:collapse; margin-top:10px; }
    table.doc-table th, table.doc-table td{ border:1px solid #000; padding:6px 8px; font-size:11px; text-align:left; }
    table.doc-table th{ background:#eee; }
    table.doc-table td.num, table.doc-table th.num{ text-align:right; }
    table.totals{ width:260px; margin-left:auto; margin-top:10px; border-collapse:collapse; }
    table.totals td{ padding:4px 0; font-size:12px; }
    table.totals td.num{ text-align:right; }
    table.totals tr.grand td{ font-weight:bold; border-top:1px solid #000; padding-top:6px; }
    .status-badge{ display:inline-block; border:1px solid #000; padding:2px 10px; font-size:11px; font-weight:bold; }
    .footer{ margin-top:24px; font-size:10px; color:#555; }
    <?php if ($includeActions): ?>
    .pdf-actions{ max-width:760px; margin:14px auto 24px; display:flex; justify-content:center; gap:10px; }
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
                <div class="muted">Purchase Order</div>
            </td>
            <td style="text-align:right;">
                <div><strong><?= htmlspecialchars($po['po_number']) ?></strong></div>
                <div style="margin-top:4px;"><span class="status-badge"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $po['status']))) ?></span></div>
            </td>
        </tr>
    </table>
    <div class="divider"></div>

    <table class="doc-header">
        <tr>
            <td style="width:50%;">
                <div class="muted">SUPPLIER</div>
                <div><strong><?= htmlspecialchars($po['supplier_name']) ?></strong></div>
                <?php if (!empty($po['contact_person'])): ?><div><?= htmlspecialchars($po['contact_person']) ?></div><?php endif; ?>
                <?php if (!empty($po['phone'])): ?><div><?= htmlspecialchars($po['phone']) ?></div><?php endif; ?>
                <?php if (!empty($po['supplier_email'])): ?><div><?= htmlspecialchars($po['supplier_email']) ?></div><?php endif; ?>
            </td>
            <td style="width:50%; text-align:right;">
                <div><span class="muted">Order date:</span> <?= htmlspecialchars(date('M j, Y', strtotime($po['order_date']))) ?></div>
                <div><span class="muted">Expected delivery:</span> <?= $po['expected_delivery_date'] ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '&mdash;' ?></div>
                <?php if (!empty($po['created_by_name'])): ?><div><span class="muted">Prepared by:</span> <?= htmlspecialchars($po['created_by_name']) ?></div><?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="doc-table">
        <thead>
            <tr><th>Item</th><th class="num">Qty</th><th class="num">Unit Cost</th><th class="num">Line Total</th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['item_name']) ?></td>
                <td class="num"><?= poFmtQtyPlain($it['quantity_ordered']) ?> <?= htmlspecialchars($it['unit_code'] ?? '') ?></td>
                <td class="num">&#8369;<?= number_format((float)$it['unit_cost'], 2) ?></td>
                <td class="num">&#8369;<?= number_format((float)$it['subtotal'] + (float)$it['vat_amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">&#8369;<?= number_format((float)$po['subtotal_amount'], 2) ?></td></tr>
        <tr><td>VAT</td><td class="num">&#8369;<?= number_format((float)$po['vat_amount'], 2) ?></td></tr>
        <tr class="grand"><td>Total</td><td class="num">&#8369;<?= number_format((float)$po['total_amount'], 2) ?></td></tr>
    </table>

    <?php if (!empty($po['remarks'])): ?>
    <div style="margin-top:16px;"><strong>Remarks:</strong><br><?= nl2br(htmlspecialchars($po['remarks'])) ?></div>
    <?php endif; ?>

    <div class="footer">Generated <?= htmlspecialchars(date('M j, Y g:i A')) ?> &mdash; <?= htmlspecialchars($restaurantName) ?></div>
</div>

<?php if ($includeActions): ?>
<div class="pdf-actions">
    <button type="button" class="primary" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>
    <a href="?id=<?= (int)$po['po_id'] ?>&download=1"><i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF</a>
    <a href="purchase_order_view.php?id=<?= (int)$po['po_id'] ?>"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back</a>
</div>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<?php endif; ?>

</body>
</html>
    <?php
    return ob_get_clean();
}
