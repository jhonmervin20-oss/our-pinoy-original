<?php
/**
 * purchase_orders/purchase_order_pdf.php
 *
 * ?id=N -- browser preview of the purchase order document (chrome-free,
 * mirrors inventory_transactions.php's ?print=1 convention). ?id=N&download=1
 * streams the same document as a real .pdf via Dompdf instead. Both paths
 * render through the one shared renderPurchaseOrderDocumentHtml() template
 * so the preview, the download, and the emailed PDF are all identical.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
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

$poId = trim((string)($_GET['id'] ?? ''));
if ($poId === '' || !ctype_digit($poId)) {
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
    header('Location: purchase_orders.php');
    exit;
}

// Same visibility rule as purchase_order_view.php -- without it a Manager
// could read a draft PO's full contents, prices and all, straight out of the
// document renderer just by guessing an ?id=. No flash here: this file never
// loads config/flash.php, and a bare redirect is the right answer for what is
// really a document endpoint.
if (!poCanView($po['status'])) {
    header('Location: purchase_orders.php');
    exit;
}

$itemsStmt = $pdo->prepare(
    "SELECT poi.item_id, poi.quantity_ordered, poi.unit_cost, poi.subtotal, poi.vat_amount,
            i.item_name, u.unit_code
     FROM purchase_order_items poi
     JOIN inventory_items i       ON i.item_id = poi.item_id
     LEFT JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
     WHERE poi.po_id = ?
     ORDER BY i.item_name"
);
$itemsStmt->execute([$poId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$restaurantName = $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
)->fetchColumn() ?: 'OPO! Our Pinoy Original';

$isDownload = ($_GET['download'] ?? '') === '1';

if ($isDownload) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $html = renderPurchaseOrderDocumentHtml($po, $items, $restaurantName, false);

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = $po['po_number'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $dompdf->output();
    exit;
}

echo renderPurchaseOrderDocumentHtml($po, $items, $restaurantName, true);
