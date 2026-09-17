<?php
/**
 * cashier/includes/receipt_body.php
 *
 * The itemized invoice markup, included by api/receipt_fragment.php as an
 * HTML fragment fetched into the "receipt shown automatically after
 * checkout" modal on pos.php -- the only place a receipt is shown; there is
 * no separate standalone page and no separate print template. This exact
 * markup/CSS is what's displayed on screen AND what gets printed (via
 * window.print() on the modal, isolated by pos.css's @media print rules) --
 * the preview is never a screenshot or a re-implementation, it's the real
 * thing.
 *
 * Requires: $restaurantName, $restaurantAddress, $restaurantTin,
 * $receiptPaperWidth ('58mm'|'80mm'), $order, $items, $payments,
 * $paymentLabels, $creditApplied -- all already fetched by the includer
 * (api/receipt_fragment.php) exactly as documented there. $restaurantAddress/
 * $restaurantTin may be empty strings (owner hasn't filled them in on
 * Settings > General yet) -- rendered conditionally, never as a blank line.
 * Pure presentation, no queries here.
 */

$paperWidthMm = $receiptPaperWidth === '58mm' ? 58 : 80;
$baseFontPx   = $paperWidthMm <= 58 ? 10 : 11;
?>
<style>
    /* Only one of these is ever live at a time -- the modal's content (this
       whole fragment, style tag included) is fully replaced via innerHTML
       on every checkout, so there's no risk of a stale @page rule from a
       previous receipt lingering. */
    @page{
        size: <?= $paperWidthMm ?>mm auto;
        margin: 0;
    }

    :root{ --receipt-ink:#1a1a1a; --receipt-ink-soft:#666; --receipt-line:#000; --receipt-accent:#000; }

    /* Flat, physical-paper look -- no border-radius, no card-in-a-card
       chrome. Width is the real physical paper width in mm, so this is a
       true 1:1 scale preview on screen (CSS mm already maps to real-world
       size at the browser's reference 96dpi) and prints at the exact right
       size when paired with the @page rule above. */
    .receipt{
        width: <?= $paperWidthMm ?>mm;
        max-width: 100%;
        margin: 0 auto;
        background: #fff;
        color: var(--receipt-ink);
        font-family: 'Poppins', 'Courier New', monospace;
        font-size: <?= $baseFontPx ?>px;
        line-height: 1.45;
        padding: 3mm;
        box-sizing: border-box;
        overflow-wrap: break-word; /* long item names / addresses / customer names wrap instead of forcing the paper wider than its own box */
        box-shadow: 0 1px 5px rgba(0,0,0,0.1); /* on-screen "this is paper" cue only -- stripped for print below */
    }

    .receipt-logo-wrap{ text-align: center; margin-bottom: 2mm; }
    /* Grayscale -- thermal printers are monochrome hardware anyway, and a
       full-color logo photo looks out of place next to an otherwise flat
       black-and-white receipt in the on-screen preview. */
    .receipt-logo{ max-width: 30%; max-height: 9mm; object-fit: contain; filter: grayscale(100%); }

    .receipt h1{
        font-family: 'Lexend', sans-serif;
        font-size: 1.25em;
        margin: 0 0 1mm;
        text-align: center;
        color: var(--receipt-accent);
    }

    .receipt-address,
    .receipt-tin{
        text-align: center;
        font-size: 0.82em;
        color: var(--receipt-ink-soft);
        margin-bottom: 0.5mm;
    }

    .receipt-sub{
        text-align: center;
        font-size: 0.9em;
        color: var(--receipt-ink-soft);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 2mm;
    }

    .receipt-meta{
        font-size: 0.95em;
        border-top: 1px dashed var(--receipt-line);
        border-bottom: 1px dashed var(--receipt-line);
        padding: 2mm 0;
        margin-bottom: 2mm;
    }
    /* Flex rows (label/value pairs) default to min-width:auto on their
       children, which refuses to shrink below the value's own content
       width -- a long customer name or reservation number would otherwise
       push the row (and the receipt paper around it) wider than intended
       instead of wrapping. min-width:0 lets the value shrink and wrap. */
    .receipt-meta div{ display: flex; justify-content: space-between; gap: 8px; margin-bottom: 0.8mm; }
    .receipt-meta div:last-child{ margin-bottom: 0; }
    .receipt-meta div span:last-child{ min-width: 0; overflow-wrap: break-word; text-align: right; }

    .receipt table{ width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 0.95em; margin-bottom: 1mm; }
    .receipt th{
        text-align: left;
        font-size: 0.82em;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        padding-bottom: 1mm;
        border-bottom: 1px solid var(--receipt-line);
    }
    /* table-layout:fixed sizes columns from these widths (not content) --
       ITEM gets whatever's left, QTY/AMOUNT get just enough for their own
       numbers -- so a long item name wraps within its own column instead
       of forcing the whole table (and the receipt paper around it) wider. */
    .receipt th:nth-child(2), .receipt td:nth-child(2){ width: 12%; text-align: center; }
    .receipt th:last-child, .receipt td:last-child{ width: 30%; text-align: right; }
    .receipt td{ padding: 0.9mm 0; vertical-align: top; overflow-wrap: break-word; }
    .receipt td small{ display: block; color: var(--receipt-ink-soft); font-size: 0.85em; }

    .receipt .totals{ margin-top: 2mm; border-top: 1px dashed var(--receipt-line); padding-top: 2mm; font-size: 0.95em; }
    .receipt .totals div{ display: flex; justify-content: space-between; gap: 8px; margin-bottom: 0.8mm; }
    .receipt .totals div span:last-child{ min-width: 0; overflow-wrap: break-word; text-align: right; }
    .receipt .totals .grand{
        font-weight: 700;
        font-size: 1.15em;
        border-top: 1px solid var(--receipt-line);
        padding-top: 1.5mm;
        margin-top: 1mm;
    }
    .receipt .totals .change{ font-weight: 700; }

    .receipt-thankyou{
        text-align: center;
        margin-top: 3mm;
        padding-top: 2mm;
        border-top: 1px dashed var(--receipt-line);
        font-size: 0.95em;
        color: var(--receipt-ink-soft);
    }

    /* Requirement: no shadows, no rounded corners, clean spacing on the
       actual printed output -- the on-screen preview above uses a subtle
       shadow purely as a "this is a sheet of paper" affordance. */
    @media print{
        .receipt{ margin: 0; box-shadow: none; border-radius: 0; }
    }
</style>

<div class="receipt">
    <div class="receipt-logo-wrap">
        <img src="../assets/images/logo.jpg" alt="" class="receipt-logo">
    </div>
    <h1><?= htmlspecialchars($restaurantName) ?></h1>
    <?php if ($restaurantAddress !== ''): ?>
        <div class="receipt-address"><?= htmlspecialchars($restaurantAddress) ?></div>
    <?php endif; ?>
    <?php if ($restaurantTin !== ''): ?>
        <div class="receipt-tin">TIN: <?= htmlspecialchars($restaurantTin) ?></div>
    <?php endif; ?>
    <?php // A voided sale must never reprint as a valid Official Receipt -- this
          // template is reachable from cashier/orders.php's Receipt button long
          // after the fact, so the reprint has to state the reversal itself. ?>
    <?php if (($order['order_status'] ?? '') === 'voided'): ?>
        <div class="receipt-sub" style="font-weight:700;letter-spacing:0.08em;">*** VOIDED ***</div>
        <div class="receipt-sub" style="font-size:0.9em;">This sale was reversed and is not a valid receipt.</div>
    <?php else: ?>
        <div class="receipt-sub">Official Receipt</div>
    <?php endif; ?>

    <div class="receipt-meta">
        <div><span>Order #</span><span><?= htmlspecialchars($order['order_number']) ?></span></div>
        <div><span>Date</span><span><?= htmlspecialchars(date('M j, Y', strtotime($order['created_at']))) ?></span></div>
        <div><span>Time</span><span><?= htmlspecialchars(date('g:i A', strtotime($order['created_at']))) ?></span></div>
        <div><span>Type</span><span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $order['order_type']))) ?></span></div>
        <?php if ($order['reservation_number']): ?>
            <div><span>Reservation</span><span><?= htmlspecialchars($order['reservation_number']) ?></span></div>
        <?php endif; ?>
        <?php if ($order['customer_first']): ?>
            <div><span>Customer</span><span><?= htmlspecialchars($order['customer_first'] . ' ' . $order['customer_last']) ?></span></div>
        <?php endif; ?>
        <div><span>Cashier</span><span><?= htmlspecialchars($order['cashier_first'] . ' ' . $order['cashier_last']) ?></span></div>
    </div>

    <table>
        <thead>
            <tr><th>Item</th><th>Qty</th><th>Amount</th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($item['item_name']) ?>
                        <?php if ($item['notes']): ?><small><?= htmlspecialchars($item['notes']) ?></small><?php endif; ?>
                    </td>
                    <td><?= (int)$item['quantity'] ?></td>
                    <td><?= number_format((float)$item['subtotal'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span><?= number_format((float)$order['subtotal'], 2) ?></span></div>
        <?php if ((float)$order['discount_amount'] > 0): ?>
            <div><span><?= htmlspecialchars($order['discount_name'] ?? 'Discount') ?></span><span>-<?= number_format((float)$order['discount_amount'], 2) ?></span></div>
        <?php endif; ?>
        <div><span>VAT</span><span><?= number_format((float)$order['vat_amount'], 2) ?></span></div>
        <?php if ((float)$order['packaging_fee_amount'] > 0): ?>
            <div><span>Packaging fee</span><span><?= number_format((float)$order['packaging_fee_amount'], 2) ?></span></div>
        <?php endif; ?>
        <?php if ($creditApplied > 0): ?>
            <div><span>Reservation credit applied</span><span>-<?= number_format($creditApplied, 2) ?></span></div>
        <?php endif; ?>
        <!-- Unlike discount_amount (already baked into total_amount by
             create_order.php), reservation credit is applied at collection
             time, not stored in total_amount -- so it has to be subtracted
             here too, or "Total" contradicts the credit line directly above
             it and the Cash received/Change math below it (which are both
             already correctly net of credit). -->
        <div class="grand"><span>Total</span><span><?= number_format((float)$order['total_amount'] - $creditApplied, 2) ?></span></div>

        <?php foreach ($payments as $payment):
            $isCash = $payment['payment_method'] === 'cash';
            $tendered = $payment['amount_tendered'] !== null ? (float)$payment['amount_tendered'] : null;
            $change = ($isCash && $tendered !== null) ? round($tendered - (float)$payment['amount'], 2) : null;
        ?>
            <div><span>Payment method</span><span><?= htmlspecialchars($paymentLabels[$payment['payment_method']] ?? $payment['payment_method']) ?></span></div>
            <?php if ($isCash && $tendered !== null): ?>
                <div><span>Cash received</span><span><?= number_format($tendered, 2) ?></span></div>
                <div class="change"><span>Change</span><span><?= number_format(max(0, $change), 2) ?></span></div>
            <?php else: ?>
                <div><span><?= $isCash ? 'Cash received' : 'Amount received' ?></span><span><?= number_format((float)$payment['amount'], 2) ?></span></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="receipt-thankyou">Thank you for dining with us!</div>
</div>
