<?php
/**
 * purchase_orders/purchase_order_receive.php
 *
 * Receive stock against an 'ordered'/'partially_received' PO. Shared by
 * all 3 roles (a warehouse-floor task, same tier as Stock Adjustment/
 * Wastage). Per line still short of quantity_ordered: enter a received
 * quantity (defaults to the remaining amount), an actual unit cost (can
 * differ from the ordered price), and an optional expiry date.
 *
 * On submit, per line: insert one inventory_batches row -> insert one
 * inventory_transactions row (reference_type='purchase_order',
 * reference_id=po_id, the PO header) -> bump
 * purchase_order_items.quantity_received. Same insert-batch-then-insert
 * -transaction shape as the increase branch of inventory_adjustment_save.php,
 * except reference_id is already known up front (the PO already exists),
 * so there's no insert-then-backfill dance needed here.
 *
 * inventory_items.last_purchase_cost is NOT touched here — the existing
 * trg_update_last_purchase_cost trigger (AFTER INSERT ON inventory_batches)
 * already does that automatically for every batch, PO-sourced or not.
 *
 * A received unit cost that jumps well above the item's PREVIOUS
 * last_purchase_cost fires an owner/manager notification via
 * notifyIfCostIncreased() (config/notifications.php) -- the item's old
 * cost has to be read before the batch insert, since the trigger above
 * overwrites it the instant that insert commits.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/back_link.php';   // back_link()
require_once __DIR__ . '/../config/notifications.php';
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

$activePage  = 'purchase_orders';
$isManager   = Session::hasRole(['manager']);
$currentUserId = Session::getUserId();

$poId = trim((string)($_GET['id'] ?? ''));
if ($poId === '' || !ctype_digit($poId)) {
    header('Location: purchase_orders.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        flash_set('error', 'Your session expired. Please try again.');
        header("Location: purchase_order_receive.php?id={$poId}");
        exit;
    }

    $linesRaw = (string)($_POST['receive_lines'] ?? '[]');
    $lines = json_decode($linesRaw, true);

    if (!is_array($lines) || count($lines) === 0) {
        flash_set('error', 'Enter a quantity for at least one item.');
        header("Location: purchase_order_receive.php?id={$poId}");
        exit;
    }

    try {
        $pdo->beginTransaction();

        $poStmt = $pdo->prepare("SELECT supplier_id, po_number, status FROM purchase_orders WHERE po_id = ? FOR UPDATE");
        $poStmt->execute([$poId]);
        $po = $poStmt->fetch(PDO::FETCH_ASSOC);

        if (!$po || !in_array($po['status'], ['ordered', 'partially_received'], true)) {
            $pdo->rollBack();
            flash_set('error', "This purchase order can't receive stock right now.");
            header('Location: purchase_orders.php');
            exit;
        }

        $receivedAny = false;

        foreach ($lines as $line) {
            $poItemId = trim((string)($line['po_item_id'] ?? ''));
            $quantity = trim((string)($line['quantity'] ?? ''));
            $unitCost = trim((string)($line['unit_cost'] ?? ''));
            $expiryDate = trim((string)($line['expiry_date'] ?? ''));

            if ($poItemId === '' || !ctype_digit($poItemId)) continue;
            if ($quantity === '' || !is_numeric($quantity) || (float)$quantity <= 0) continue;

            $itemStmt = $pdo->prepare(
                "SELECT poi.item_id, poi.quantity_ordered, poi.quantity_received, i.item_name, i.last_purchase_cost
                 FROM purchase_order_items poi
                 JOIN inventory_items i ON i.item_id = poi.item_id
                 WHERE poi.po_item_id = ? AND poi.po_id = ? FOR UPDATE"
            );
            $itemStmt->execute([$poItemId, $poId]);
            $poItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if (!$poItem) continue;

            $remaining = (float)$poItem['quantity_ordered'] - (float)$poItem['quantity_received'];
            if ($remaining <= 0) continue;

            $qty = min((float)$quantity, $remaining);
            $cost = ($unitCost !== '' && is_numeric($unitCost) && (float)$unitCost >= 0) ? round((float)$unitCost, 2) : null;
            if ($cost === null) continue;

            // Whatever the receiver typed on the line, or nothing. There is no
            // longer a shelf-life setting to derive a date from -- an expiry is
            // read off the delivery itself, not guessed from the item.
            $finalExpiry = ($expiryDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate))
                ? $expiryDate
                : null;

            $oldCost = $poItem['last_purchase_cost'] !== null ? (float)$poItem['last_purchase_cost'] : null;

            $insBatch = $pdo->prepare(
                "INSERT INTO inventory_batches (item_id, po_item_id, supplier_id, batch_number, quantity_received, quantity_remaining, unit_cost, expiry_date, received_date, received_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)"
            );
            $insBatch->execute([$poItem['item_id'], $poItemId, $po['supplier_id'], $po['po_number'], $qty, $qty, $cost, $finalExpiry, $currentUserId]);
            $newBatchId = $pdo->lastInsertId();

            notifyIfCostIncreased($pdo, (int)$poItem['item_id'], $poItem['item_name'], $oldCost, $cost);

            $insTxn = $pdo->prepare(
                "INSERT INTO inventory_transactions (item_id, batch_id, transaction_type, quantity, reference_type, reference_id, performed_by)
                 VALUES (?, ?, 'stock_in', ?, 'purchase_order', ?, ?)"
            );
            $insTxn->execute([$poItem['item_id'], $newBatchId, $qty, $poId, $currentUserId]);

            $pdo->prepare('UPDATE purchase_order_items SET quantity_received = quantity_received + ? WHERE po_item_id = ?')
                ->execute([$qty, $poItemId]);

            $receivedAny = true;
        }

        if (!$receivedAny) {
            $pdo->rollBack();
            flash_set('error', 'Enter a valid quantity and unit cost for at least one item.');
            header("Location: purchase_order_receive.php?id={$poId}");
            exit;
        }

        $allItemsStmt = $pdo->prepare('SELECT quantity_ordered, quantity_received FROM purchase_order_items WHERE po_id = ?');
        $allItemsStmt->execute([$poId]);
        $allItems = $allItemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $fullyReceived = true;
        foreach ($allItems as $it) {
            if ((float)$it['quantity_received'] < (float)$it['quantity_ordered']) {
                $fullyReceived = false;
                break;
            }
        }
        $newStatus = $fullyReceived ? 'received' : 'partially_received';
        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE po_id = ?')->execute([$newStatus, $poId]);

        $pdo->commit();

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Purchase Orders', 'Receive stock', ?, ?)"
            )->execute([
                $currentUserId,
                "Received stock for {$po['po_number']} (now {$newStatus})",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }

        flash_set('success', "Stock received for {$po['po_number']}.");
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('purchase_order_receive.php failed: ' . $e->getMessage());
        flash_set('error', 'Something went wrong receiving this stock. Please try again.');
        header("Location: purchase_order_receive.php?id={$poId}");
        exit;
    }

    header("Location: purchase_order_view.php?id={$poId}");
    exit;
}

// -- GET: render the receiving form --------------------------------------
$stmt = $pdo->prepare(
    "SELECT po.po_id, po.po_number, po.status, s.supplier_name
     FROM purchase_orders po
     JOIN suppliers s ON s.supplier_id = po.supplier_id
     WHERE po.po_id = ?"
);
$stmt->execute([$poId]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po || !in_array($po['status'], ['ordered', 'partially_received'], true)) {
    header("Location: purchase_order_view.php?id={$poId}");
    exit;
}

$itemsStmt = $pdo->prepare(
    "SELECT poi.po_item_id, poi.quantity_ordered, poi.quantity_received, poi.unit_cost,
            i.item_name, u.unit_code
     FROM purchase_order_items poi
     JOIN inventory_items i       ON i.item_id = poi.item_id
     LEFT JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
     WHERE poi.po_id = ?
     ORDER BY i.item_name"
);
$itemsStmt->execute([$poId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$pendingItems = array_filter($items, fn($it) => (float)$it['quantity_received'] < (float)$it['quantity_ordered']);

$pageTitle = 'Receive Stock';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receive Stock | <?= htmlspecialchars($po['po_number']) ?> | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">

    <?php
    if ($isManager) {
        $managerBase = '../manager/';
        require_once __DIR__ . '/../manager/includes/sidebar.php';
    } else {
        $ownerBase = '../owner/';
        require_once __DIR__ . '/../owner/includes/sidebar.php';
    }
    ?>

    <div class="owner-main">

        <?php
        if ($isManager) {
            require_once __DIR__ . '/../manager/includes/header.php';
        } else {
            require_once __DIR__ . '/../owner/includes/header.php';
        }
        ?>

        <main class="owner-content">

            <div style="margin-bottom:16px;">
                <a href="<?= htmlspecialchars(back_link("purchase_order_view.php?id=" . (int)$poId)) ?>" class="owner-btn owner-btn-secondary owner-btn-sm">
                    <?php // Just "Back": the destination is back_link()'s, which follows
                          // where the user actually came from, so naming a specific PO
                          // here would promise somewhere this button may not go. ?>
                    <i class="ph ph-arrow-left" aria-hidden="true"></i> Back
                </a>
            </div>

            <?= flash_render() ?>

            <form method="post" id="receiveForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="receive_lines" id="receiveLinesField" value="">

                <div class="owner-form-note owner-alert owner-alert-error" id="receiveFormAlert" hidden>
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span id="receiveFormAlertText">Please fix the highlighted fields before saving.</span>
                </div>

                <div class="owner-card">
                    <div class="owner-card-head">
                        <div>
                            <h2 class="owner-card-title">Receive stock &mdash; <?= htmlspecialchars($po['po_number']) ?></h2>
                            <span class="owner-card-subtitle"><?= htmlspecialchars($po['supplier_name']) ?></span>
                        </div>
                    </div>

                    <?php if (empty($pendingItems)): ?>
                        <div class="owner-table-empty">Every item on this purchase order has already been fully received.</div>
                    <?php else: ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Remaining</th>
                                    <th>Receive now</th>
                                    <th>Unit cost</th>
                                    <th>Expiry date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingItems as $it):
                                    $remaining = (float)$it['quantity_ordered'] - (float)$it['quantity_received'];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['item_name']) ?></td>
                                    <td><?= poFmtQtyPlain($remaining) ?> <?= htmlspecialchars($it['unit_code'] ?? '') ?></td>
                                    <td>
                                        <input type="number" step="0.001" min="0" max="<?= htmlspecialchars(poFmtQtyPlain($remaining)) ?>" class="owner-input recv-qty" style="max-width:120px;" data-po-item-id="<?= (int)$it['po_item_id'] ?>" value="<?= htmlspecialchars(poFmtQtyPlain($remaining)) ?>">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" class="owner-input recv-cost" style="max-width:120px;" data-po-item-id="<?= (int)$it['po_item_id'] ?>" value="<?= number_format((float)$it['unit_cost'], 2, '.', '') ?>">
                                    </td>
                                    <td>
                                        <input type="date" class="owner-input recv-expiry" data-po-item-id="<?= (int)$it['po_item_id'] ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                        <a href="purchase_order_view.php?id=<?= (int)$poId ?>" class="owner-btn owner-btn-secondary">Cancel</a>
                        <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-package" aria-hidden="true"></i> Confirm receiving</button>
                    </div>
                    <?php endif; ?>
                </div>
            </form>

        </main>

    </div>

</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const form = document.getElementById('receiveForm');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('receiveFormAlert');
        const alertText = document.getElementById('receiveFormAlertText');

        const lines = [];
        document.querySelectorAll('.recv-qty').forEach((qtyInput) => {
            const poItemId = qtyInput.getAttribute('data-po-item-id');
            const qty = qtyInput.value;
            if (!qty || parseFloat(qty) <= 0) return;
            const costInput = document.querySelector('.recv-cost[data-po-item-id="' + poItemId + '"]');
            const expiryInput = document.querySelector('.recv-expiry[data-po-item-id="' + poItemId + '"]');
            lines.push({ po_item_id: poItemId, quantity: qty, unit_cost: costInput ? costInput.value : '', expiry_date: expiryInput ? expiryInput.value : '' });
        });

        if (lines.length === 0) {
            alertText.textContent = 'Enter a quantity for at least one item.';
            alertEl.hidden = false;
            return;
        }

        for (const line of lines) {
            if (line.unit_cost === '' || parseFloat(line.unit_cost) < 0) {
                alertText.textContent = 'Every item being received needs a unit cost.';
                alertEl.hidden = false;
                return;
            }
        }

        const ok = await confirmAction('Confirm receiving ' + lines.length + ' item(s) into inventory? This cannot be undone.');
        if (!ok) return;

        alertEl.hidden = true;
        document.getElementById('receiveLinesField').value = JSON.stringify(lines);
        form.submit();
    });
})();
</script>

</body>
</html>
