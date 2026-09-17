<?php
/**
 * purchase_orders/purchase_order_save.php
 *
 * Create or update a draft Purchase Order. Every save (create or edit)
 * keeps the PO in 'draft' — Finalize/Mark as Ordered live on
 * purchase_order_status.php instead. Every line is re-validated and
 * re-priced from the DB (item must be active, is_vat_exempt is always
 * re-fetched — never trusted from the client); unit_cost itself is
 * staff-entered and trusted after a basic >= 0 check, same as every other
 * money field in this app.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
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

$currentUserId = Session::getUserId();

$poId              = trim((string)($_POST['po_id'] ?? ''));
$isEdit            = $poId !== '' && ctype_digit($poId);
$supplierId        = trim((string)($_POST['supplier_id'] ?? ''));
$orderDate         = trim((string)($_POST['order_date'] ?? ''));
$expectedDate      = trim((string)($_POST['expected_delivery_date'] ?? ''));
$remarks           = trim((string)($_POST['remarks'] ?? ''));
$lineItemsRaw      = (string)($_POST['line_items'] ?? '[]');

$backTo = $isEdit ? "purchase_order_form.php?id={$poId}"
    : 'purchase_order_form.php';

$errors = [];

if ($supplierId === '' || !ctype_digit($supplierId)) {
    $errors[] = 'Please select a supplier.';
}
if ($orderDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
    $errors[] = 'Please enter a valid order date.';
}
if ($expectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDate)) {
    $errors[] = 'Please enter a valid expected delivery date.';
}
if (mb_strlen($remarks) > 2000) {
    $remarks = mb_substr($remarks, 0, 2000);
}

$lines = json_decode($lineItemsRaw, true);
if (!is_array($lines) || count($lines) === 0) {
    $errors[] = 'Add at least one item to this purchase order.';
    $lines = [];
}

$cleanLines = [];
foreach ($lines as $line) {
    $itemId   = trim((string)($line['item_id'] ?? ''));
    $quantity = trim((string)($line['quantity'] ?? ''));
    $unitCost = trim((string)($line['unit_cost'] ?? ''));

    if ($itemId === '' || !ctype_digit($itemId)) {
        $errors[] = 'One of the line items is invalid.';
        continue;
    }
    if ($quantity === '' || !is_numeric($quantity) || (float)$quantity <= 0) {
        $errors[] = 'Every item needs a quantity greater than 0.';
        continue;
    }
    if ($unitCost === '' || !is_numeric($unitCost) || (float)$unitCost < 0) {
        $errors[] = 'Every item needs a valid unit cost.';
        continue;
    }

    $cleanLines[] = ['item_id' => (int)$itemId, 'quantity' => (float)$quantity, 'unit_cost' => round((float)$unitCost, 2)];
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    header("Location: {$backTo}");
    exit;
}

$pdo = Database::getInstance()->getConnection();

try {
    $pdo->beginTransaction();

    $supplierCheck = $pdo->prepare('SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND is_active = 1');
    $supplierCheck->execute([$supplierId]);
    if (!$supplierCheck->fetch()) {
        $pdo->rollBack();
        flash_set('error', "That supplier doesn't exist or is inactive.");
        header("Location: {$backTo}");
        exit;
    }

    $itemIds = array_column($cleanLines, 'item_id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $itemStmt = $pdo->prepare("SELECT item_id, is_vat_exempt, preferred_supplier_id FROM inventory_items WHERE item_id IN ({$placeholders}) AND is_active = 1");
    $itemStmt->execute($itemIds);
    $vatExemptByItem = [];
    $preferredSupplierByItem = [];
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $vatExemptByItem[(int)$row['item_id']] = (bool)$row['is_vat_exempt'];
        $preferredSupplierByItem[(int)$row['item_id']] = $row['preferred_supplier_id'] !== null ? (int)$row['preferred_supplier_id'] : null;
    }

    // Never trust the combobox's client-side supplier filter alone -- an
    // item linked to a different supplier than the one chosen for this PO
    // is rejected here too. Items with no preferred supplier assigned are
    // allowed on any PO.
    foreach ($cleanLines as $line) {
        if (!array_key_exists($line['item_id'], $vatExemptByItem)) {
            $pdo->rollBack();
            flash_set('error', 'One of the selected items no longer exists or is inactive.');
            header("Location: {$backTo}");
            exit;
        }
        $linkedSupplier = $preferredSupplierByItem[$line['item_id']];
        if ($linkedSupplier !== null && $linkedSupplier !== (int)$supplierId) {
            $pdo->rollBack();
            flash_set('error', 'One of the selected items is linked to a different supplier and cannot be added to this purchase order.');
            header("Location: {$backTo}");
            exit;
        }
    }

    $taxSettings = poGetActiveTaxSettings($pdo);
    $totalsLines = array_map(
        fn($l) => ['line_gross' => round($l['quantity'] * $l['unit_cost'], 2), 'is_vat_exempt' => $vatExemptByItem[$l['item_id']]],
        $cleanLines
    );
    $totals = computePOTotals($totalsLines, $taxSettings);

    if ($isEdit) {
        $poCheck = $pdo->prepare("SELECT po_id FROM purchase_orders WHERE po_id = ? AND status = 'draft' FOR UPDATE");
        $poCheck->execute([$poId]);
        if (!$poCheck->fetch()) {
            $pdo->rollBack();
            flash_set('error', 'This purchase order can no longer be edited.');
            header('Location: purchase_orders.php');
            exit;
        }

        $pdo->prepare(
            "UPDATE purchase_orders SET supplier_id = ?, order_date = ?, expected_delivery_date = ?, remarks = ?,
                subtotal_amount = ?, vat_amount = ?, is_vat_inclusive = ?, tax_setting_id = ?, total_amount = ?
             WHERE po_id = ?"
        )->execute([
            $supplierId, $orderDate, $expectedDate !== '' ? $expectedDate : null, $remarks !== '' ? $remarks : null,
            $totals['subtotal'], $totals['vat_amount'], $taxSettings['tax_type'] === 'inclusive' ? 1 : 0, $taxSettings['tax_setting_id'], $totals['total_amount'],
            $poId,
        ]);

        $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id = ?')->execute([$poId]);

        $insItem = $pdo->prepare(
            'INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_cost, subtotal, vat_amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($cleanLines as $i => $line) {
            $lineTotals = computePOTotals([$totalsLines[$i]], $taxSettings);
            $insItem->execute([$poId, $line['item_id'], $line['quantity'], $line['unit_cost'], $lineTotals['subtotal'], $lineTotals['vat_amount']]);
        }

        $poNumber = null;
        $logAction = 'Update purchase order';
    } else {
        // createPurchaseOrderCore() (purchase_orders/includes/po_functions.php) is the
        // same core used by config/inventory_alerts.php's auto purchase order sweep --
        // both stay on identical totals/VAT/po_number logic instead of two copies drifting.
        $coreLines = array_map(
            fn($l) => ['item_id' => $l['item_id'], 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost'], 'is_vat_exempt' => $vatExemptByItem[$l['item_id']]],
            $cleanLines
        );
        $created = createPurchaseOrderCore(
            $pdo, (int)$supplierId, $orderDate, $expectedDate !== '' ? $expectedDate : null,
            $coreLines, $currentUserId, $remarks !== '' ? $remarks : null, false
        );
        $poId       = $created['po_id'];
        $poNumber   = $created['po_number'];
        $totals     = ['subtotal' => null, 'vat_amount' => null, 'total_amount' => $created['total_amount']];
        $logAction  = 'Create purchase order';
    }

    $pdo->commit();

    if ($poNumber === null) {
        $numStmt = $pdo->prepare('SELECT po_number FROM purchase_orders WHERE po_id = ?');
        $numStmt->execute([$poId]);
        $poNumber = $numStmt->fetchColumn();
    }

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Purchase Orders', ?, ?, ?)"
        )->execute([
            $currentUserId,
            $logAction,
            "{$logAction}: {$poNumber} (" . count($cleanLines) . ' item' . (count($cleanLines) === 1 ? '' : 's') . ", total \xe2\x82\xb1" . number_format($totals['total_amount'], 2) . ')',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    flash_set('success', "Purchase order {$poNumber} saved.");
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('purchase_order_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong saving this purchase order. Please try again.');
    header("Location: {$backTo}");
    exit;
}

header("Location: purchase_order_view.php?id={$poId}");
exit;
