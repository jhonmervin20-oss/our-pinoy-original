<?php
/**
 * purchase_orders/purchase_order_email.php
 *
 * Emails a formatted copy of a Purchase Order to the supplier's email on
 * file, via the existing Mailer/PHPMailer setup (config/mailer.php,
 * already used by auth/forgot_password.php) -- with a real .pdf attachment
 * generated via Dompdf from the same renderPurchaseOrderDocumentHtml()
 * template used by purchase_order_pdf.php, so what the supplier receives
 * matches what staff already previewed. A finalized snapshot only —
 * available once the PO has left 'draft' (a draft isn't a real commitment
 * yet) and hasn't been cancelled. Independent of status transitions: this
 * never changes the PO's status itself, so a failed send never leaves the
 * record in an inconsistent state.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/includes/po_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}
// Owner-only: creating and managing the purchasing pipeline is theirs. A
// Manager reaching this by URL is bounced back to the list rather than to
// login -- they ARE signed in and do have the module, just not this action.
if (!poCanManage()) {
    poDenyAccess('Only the owner can create or manage purchase orders.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: purchase_orders.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: purchase_orders.php');
    exit;
}

$poId = trim((string)($_POST['po_id'] ?? ''));
if ($poId === '' || !ctype_digit($poId)) {
    flash_set('error', 'Invalid purchase order.');
    header('Location: purchase_orders.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare(
    "SELECT po.*, s.supplier_name, s.contact_person, s.phone, s.email AS supplier_email,
            CONCAT(usr.first_name, ' ', usr.last_name) AS created_by_name
     FROM purchase_orders po
     JOIN suppliers s ON s.supplier_id = po.supplier_id
     JOIN users usr   ON usr.user_id = po.created_by
     WHERE po.po_id = ?"
);
$stmt->execute([$poId]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    flash_set('error', 'That purchase order no longer exists.');
    header('Location: purchase_orders.php');
    exit;
}
if (in_array($po['status'], ['draft', 'cancelled'], true)) {
    flash_set('error', 'Finalize this purchase order before emailing it to the supplier.');
    header("Location: purchase_order_view.php?id={$poId}");
    exit;
}
if ($po['supplier_email'] === null || trim((string)$po['supplier_email']) === '') {
    flash_set('error', 'This supplier has no email address on file.');
    header("Location: purchase_order_view.php?id={$poId}");
    exit;
}

$itemsStmt = $pdo->prepare(
    "SELECT poi.quantity_ordered, poi.unit_cost, poi.subtotal, poi.vat_amount, i.item_name, u.unit_code
     FROM purchase_order_items poi
     JOIN inventory_items i       ON i.item_id = poi.item_id
     LEFT JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
     WHERE poi.po_id = ?
     ORDER BY i.item_name"
);
$itemsStmt->execute([$poId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$restaurantName = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name'")->fetchColumn() ?: (getenv('APP_NAME') ?: 'OPO! Our Pinoy Original');

$rowsHtml = '';
foreach ($items as $it) {
    $lineTotal = (float)$it['subtotal'] + (float)$it['vat_amount'];
    $rowsHtml .= '<tr>'
        . '<td style="padding:8px;border-bottom:1px solid #e8ded0;">' . htmlspecialchars($it['item_name']) . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #e8ded0;text-align:right;">' . poFmtQtyPlain($it['quantity_ordered']) . ' ' . htmlspecialchars($it['unit_code'] ?? '') . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #e8ded0;text-align:right;">&#8369;' . number_format((float)$it['unit_cost'], 2) . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #e8ded0;text-align:right;">&#8369;' . number_format($lineTotal, 2) . '</td>'
        . '</tr>';
}

$toName = $po['contact_person'] !== null && $po['contact_person'] !== '' ? $po['contact_person'] : $po['supplier_name'];
$subject = "Purchase Order {$po['po_number']} from " . $restaurantName;

$html = '
<div style="font-family:sans-serif;max-width:600px;margin:auto;color:#191410;">
    <h2 style="color:#9c7734;margin-bottom:4px;">Purchase Order ' . htmlspecialchars($po['po_number']) . '</h2>
    <p style="margin-top:0;color:#6b5f4f;">' . htmlspecialchars($restaurantName) . '</p>
    <p>Hi ' . htmlspecialchars($toName) . ',</p>
    <p>Please find our purchase order details below.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">
        <tr><td style="padding:4px 8px 4px 0;color:#6b5f4f;">Order date</td><td style="padding:4px 0;font-weight:600;">' . htmlspecialchars(date('M j, Y', strtotime($po['order_date']))) . '</td></tr>
        <tr><td style="padding:4px 8px 4px 0;color:#6b5f4f;">Expected delivery</td><td style="padding:4px 0;font-weight:600;">' . ($po['expected_delivery_date'] !== null ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '&mdash;') . '</td></tr>
    </table>
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead>
            <tr style="background:#f5efe4;">
                <th style="padding:8px;text-align:left;">Item</th>
                <th style="padding:8px;text-align:right;">Quantity</th>
                <th style="padding:8px;text-align:right;">Unit cost</th>
                <th style="padding:8px;text-align:right;">Line total</th>
            </tr>
        </thead>
        <tbody>' . $rowsHtml . '</tbody>
    </table>
    <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:14px;">
        <tr><td style="padding:2px 0;text-align:right;color:#6b5f4f;">Subtotal</td><td style="padding:2px 0;text-align:right;width:120px;">&#8369;' . number_format((float)$po['subtotal_amount'], 2) . '</td></tr>
        <tr><td style="padding:2px 0;text-align:right;color:#6b5f4f;">VAT</td><td style="padding:2px 0;text-align:right;">&#8369;' . number_format((float)$po['vat_amount'], 2) . '</td></tr>
        <tr><td style="padding:6px 0;text-align:right;font-weight:700;border-top:1px solid #e8ded0;">Total</td><td style="padding:6px 0;text-align:right;font-weight:700;border-top:1px solid #e8ded0;">&#8369;' . number_format((float)$po['total_amount'], 2) . '</td></tr>
    </table>'
    . ($po['remarks'] !== null && $po['remarks'] !== '' ? '<p><strong>Remarks:</strong> ' . nl2br(htmlspecialchars($po['remarks'])) . '</p>' : '')
    . '<p style="margin-top:24px;color:#6b5f4f;font-size:12px;">This is an automated message from ' . htmlspecialchars($restaurantName) . '.</p>
</div>';

require_once __DIR__ . '/../vendor/autoload.php';

$pdfHtml = renderPurchaseOrderDocumentHtml($po, $items, $restaurantName, false);
$dompdf = new \Dompdf\Dompdf();
$dompdf->loadHtml($pdfHtml);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdfContent = $dompdf->output();

$sent = Mailer::sendWithAttachment(
    $po['supplier_email'],
    $toName,
    $subject,
    $html,
    $pdfContent,
    $po['po_number'] . '.pdf'
);

if ($sent) {
    try {
        // reference_type/reference_id are populated here so poLastEmailedAt()
        // can find this row by id. Rows written before this change carry NULLs,
        // which is why that lookup also matches on the PO number in the text.
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent, reference_type, reference_id)
             VALUES (?, 'Purchase Orders', 'Email purchase order', ?, ?, 'purchase_order', ?)"
        )->execute([
            Session::getUserId(),
            "Emailed {$po['po_number']} to {$po['supplier_name']} ({$po['supplier_email']})",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $poId,
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }
}

// Combined "Email & mark as ordered" action. The status change is deliberately
// NOT conditional on $sent: marking a PO as ordered records the owner's
// decision to place the order, and a mail outage shouldn't strand the PO at
// approved when the order was really made. The flash below says plainly which
// half succeeded so a failed email is never silently swallowed.
$alsoMarkOrdered = ($_POST['mark_ordered'] ?? '') === '1';
$markedOrdered   = false;

if ($alsoMarkOrdered && $po['status'] === 'approved') {
    try {
        $pdo->prepare("UPDATE purchase_orders SET status = 'ordered' WHERE po_id = ?")->execute([$poId]);
        $markedOrdered = true;

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Purchase Orders', 'Mark purchase order as ordered', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Mark purchase order as ordered: {$po['po_number']}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }
    } catch (PDOException $e) {
        error_log('purchase_order_email.php mark_ordered failed: ' . $e->getMessage());
    }
}

if ($sent && $markedOrdered) {
    flash_set('success', "{$po['po_number']} marked as ordered and emailed to {$po['supplier_email']}.");
} elseif ($sent) {
    flash_set('success', "Purchase order emailed to {$po['supplier_email']}.");
} elseif ($markedOrdered) {
    flash_set('error', "{$po['po_number']} was marked as ordered, but the email to {$po['supplier_email']} could not be sent. Use \"Email to supplier\" to try again, or contact them directly.");
} else {
    flash_set('error', "Couldn't send the email. Please check the mail settings and try again.");
}

header("Location: purchase_order_view.php?id={$poId}");
exit;
