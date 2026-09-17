<?php
/**
 * cashier/api/receipt_fragment.php
 *
 * GET ?order_id=N -> the itemized invoice markup (via includes/receipt_body.php)
 * as a bare HTML fragment (no <html>/<head> page chrome) for fetch()-ing
 * into the "receipt shown automatically after checkout" modal on pos.php --
 * the only place a receipt is shown; there is no separate standalone page.
 * fetch()-only endpoint, so it uses the inline 401-JSON auth pattern other
 * cashier/api/*.php endpoints use rather than pos_guard.php's redirect
 * (a redirect is meaningless to a fetch() caller -- see pos_guard.php's own
 * doc comment).
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['cashier', 'owner'])) {
    http_response_code(401);
    echo 'Your session has expired. Please log in again.';
    exit;
}

$db = Database::getInstance()->getConnection();

$orderId = (int)($_GET['order_id'] ?? 0);

$settingsStmt = $db->query(
    "SELECT setting_key, setting_value FROM system_settings
     WHERE setting_key IN ('restaurant_name', 'restaurant_address', 'restaurant_tin', 'receipt_paper_width')"
);
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$restaurantName    = $settings['restaurant_name'] ?: 'OPO! Our Pinoy Original';
$restaurantAddress = $settings['restaurant_address'] ?? '';
$restaurantTin      = $settings['restaurant_tin'] ?? '';

// '58mm' or '80mm' -- owner-configurable on Settings > General, drives both
// the on-screen preview width and the @page print size in receipt_body.php.
$receiptPaperWidth = $settings['receipt_paper_width'] ?: '80mm';
if (!in_array($receiptPaperWidth, ['58mm', '80mm'], true)) {
    $receiptPaperWidth = '80mm';
}

$orderStmt = $db->prepare(
    'SELECT o.*, r.reservation_number, cu.first_name AS customer_first, cu.last_name AS customer_last,
            ca.first_name AS cashier_first, ca.last_name AS cashier_last
     FROM orders o
     LEFT JOIN reservations r ON r.reservation_id = o.reservation_id
     LEFT JOIN users cu ON cu.user_id = o.customer_id
     JOIN users ca ON ca.user_id = o.cashier_id
     WHERE o.order_id = ?
     LIMIT 1'
);
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

if (!$order) {
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

$itemsStmt = $db->prepare(
    'SELECT oi.quantity, oi.unit_price, oi.subtotal, oi.notes, mi.item_name
     FROM order_items oi
     JOIN menu_items mi ON mi.item_id = oi.menu_item_id
     WHERE oi.order_id = ?
     ORDER BY oi.order_item_id'
);
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

$paymentsStmt = $db->prepare('SELECT * FROM order_payments WHERE order_id = ? ORDER BY payment_id');
$paymentsStmt->execute([$orderId]);
$payments = $paymentsStmt->fetchAll();

$paymentLabels = ['cash' => 'Cash', 'paymongo_gcash' => 'GCash'];
$totalCollected = array_sum(array_column($payments, 'amount'));
// total_amount is already net of discount_amount (create_order.php bakes the
// discount into subtotal before adding VAT/service/packaging), so it's not
// subtracted again here.
$creditApplied  = round((float)$order['total_amount'] - $totalCollected, 2);

require __DIR__ . '/../includes/receipt_body.php';
