<?php
/**
 * inventory/inventory_adjustment_save.php
 *
 * Creates a Stock Adjustment (Increase or Decrease) plus its linked
 * inventory_transactions row, atomically.
 *
 * Increase: inserts a brand-new inventory_batches row (this is genuinely
 * new stock — arbitrarily growing an existing FIFO lot would corrupt its
 * real expiry/cost history), generates the adjustment's reference number,
 * backfills the new batch's batch_number with it, then logs a 'stock_in'
 * transaction (reference_type='adjustment' disambiguates it from a real
 * PO receipt without needing a new enum value).
 *
 * Decrease: locks and decrements one specific existing batch chosen by
 * the user (same SELECT ... FOR UPDATE pattern the old
 * inventory_batch_adjust.php used), then logs an 'adjustment' transaction.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/includes/inventory_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . adjustmentReturnTo());
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . adjustmentReturnTo());
    exit;
}

const ADJUSTMENT_REASONS = ['physical_count', 'damaged', 'lost', 'theft', 'correction', 'supplier_correction', 'other'];

/**
 * Where to go when this is done.
 *
 * Wastage and adjustments can now be recorded straight from the Batches tab on
 * inventory.php, so the handler has to be able to return there. Only known
 * pages are honoured -- `return_to` arrives from a form post, and echoing it
 * into a Location header unchecked is an open redirect.
 */
function adjustmentReturnTo(): string
{
    $allowed = [
        // A query param, not a #fragment -- inventory.php's tab-switching JS
        // only ever reads window.location.search, so a fragment here silently
        // never activated the Batches tab and the page fell back to Items.
        'inventory'   => 'inventory.php?tab=batches',
        'wastage'     => 'inventory_wastage.php',
        'adjustments' => 'inventory_adjustments.php',
    ];
    $key = trim((string)($_POST['return_to'] ?? ''));
    return $allowed[$key] ?? 'inventory_adjustments.php';
}

$direction  = trim((string)($_POST['direction'] ?? ''));
$itemId     = trim((string)($_POST['item_id'] ?? ''));
$batchId    = trim((string)($_POST['batch_id'] ?? ''));
$unitCost   = trim((string)($_POST['unit_cost'] ?? ''));
$quantity   = trim((string)($_POST['quantity'] ?? ''));
$reason     = trim((string)($_POST['reason'] ?? ''));
$remarks    = trim((string)($_POST['remarks'] ?? ''));
$adjDate    = trim((string)($_POST['adjustment_date'] ?? ''));

$errors = [];

if (!in_array($direction, ['increase', 'decrease'], true)) {
    $errors[] = 'Please choose Increase or Decrease.';
}
if ($itemId === '' || !ctype_digit($itemId)) {
    $errors[] = 'Please select an inventory item.';
}
if ($quantity === '' || !is_numeric($quantity) || (float)$quantity <= 0) {
    $errors[] = 'Please enter a quantity greater than 0.';
}
if (!in_array($reason, ADJUSTMENT_REASONS, true)) {
    $errors[] = 'Please choose a valid reason.';
}
if ($adjDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $adjDate) || $adjDate > date('Y-m-d')) {
    $errors[] = 'Please enter a valid date (not in the future).';
}
if ($direction === 'decrease' && ($batchId === '' || !ctype_digit($batchId))) {
    $errors[] = 'Please select a batch to decrease from.';
}
if ($direction === 'increase' && ($unitCost === '' || !is_numeric($unitCost) || (float)$unitCost < 0)) {
    $errors[] = 'Please enter a valid unit cost.';
}
if (mb_strlen($remarks) > 255) {
    $remarks = mb_substr($remarks, 0, 255);
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    header('Location: ' . adjustmentReturnTo());
    exit;
}

$pdo = Database::getInstance()->getConnection();

try {
    $pdo->beginTransaction();

    $itemStmt = $pdo->prepare('SELECT item_id, item_name, base_unit_id, last_purchase_cost FROM inventory_items WHERE item_id = ? AND is_active = 1');
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $pdo->rollBack();
        flash_set('error', "That item doesn't exist or is inactive.");
        header('Location: ' . adjustmentReturnTo());
        exit;
    }

    if ($direction === 'increase') {
        // Expiry is a property of a delivery, not of an item, and this form
        // has no date field -- so a batch created by an adjustment carries no
        // expiry date. It used to be derived from the item's shelf life; that
        // setting no longer exists.
        $expiryDate = null;

        // Read before the batch insert -- trg_update_last_purchase_cost
        // overwrites this the instant that insert commits, same reasoning
        // as purchase_order_receive.php's identical capture.
        $oldCost = $item['last_purchase_cost'] !== null ? (float)$item['last_purchase_cost'] : null;

        $insBatch = $pdo->prepare(
            "INSERT INTO inventory_batches (item_id, quantity_received, quantity_remaining, unit_cost, expiry_date, received_date)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $insBatch->execute([$itemId, $quantity, $quantity, $unitCost, $expiryDate, $adjDate]);
        $newBatchId = $pdo->lastInsertId();

        notifyIfCostIncreased($pdo, (int)$itemId, $item['item_name'], $oldCost, (float)$unitCost);

        // inventory_transactions first — stock_adjustments.transaction_id is a
        // real FK (unlike inventory_transactions.reference_id, which is an
        // unconstrained polymorphic pointer), so the transaction row must
        // exist before the adjustment row can reference it.
        $insTxn = $pdo->prepare(
            "INSERT INTO inventory_transactions (item_id, batch_id, transaction_type, quantity, reference_type, remarks, performed_by)
             VALUES (?, ?, 'stock_in', ?, 'adjustment', ?, ?)"
        );
        $insTxn->execute([$itemId, $newBatchId, $quantity, $remarks !== '' ? $remarks : null, Session::getUserId()]);
        $transactionId = (int)$pdo->lastInsertId();

        $adjustmentId = null;
        $attempts = 0;
        while ($adjustmentId === null) {
            $attempts++;
            $candidateNumber = generateAdjustmentNumber($pdo);
            try {
                $insAdj = $pdo->prepare(
                    "INSERT INTO stock_adjustments (adjustment_number, item_id, batch_id, direction, quantity, reason, remarks, adjustment_date, adjusted_by, transaction_id)
                     VALUES (?, ?, ?, 'increase', ?, ?, ?, ?, ?, ?)"
                );
                $insAdj->execute([$candidateNumber, $itemId, $newBatchId, $quantity, $reason, $remarks !== '' ? $remarks : null, $adjDate, Session::getUserId(), $transactionId]);
                $adjustmentId = (int)$pdo->lastInsertId();
                $adjustmentNumber = $candidateNumber;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' && $attempts < 3) {
                    continue;
                }
                throw $e;
            }
        }

        $pdo->prepare('UPDATE inventory_batches SET batch_number = ? WHERE batch_id = ?')
            ->execute([$adjustmentNumber, $newBatchId]);

        $pdo->prepare('UPDATE inventory_transactions SET reference_id = ? WHERE transaction_id = ?')
            ->execute([$adjustmentId, $transactionId]);
    } else {
        $batchStmt = $pdo->prepare(
            'SELECT batch_id, item_id, quantity_remaining FROM inventory_batches WHERE batch_id = ? AND item_id = ? FOR UPDATE'
        );
        $batchStmt->execute([$batchId, $itemId]);
        $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            $pdo->rollBack();
            flash_set('error', "That batch doesn't belong to the selected item.");
            header('Location: ' . adjustmentReturnTo());
            exit;
        }

        $remaining = (float)$batch['quantity_remaining'];
        if ((float)$quantity > $remaining) {
            $pdo->rollBack();
            flash_set('error', "Quantity exceeds what's remaining on this batch ({$remaining}).");
            header('Location: ' . adjustmentReturnTo());
            exit;
        }

        $pdo->prepare('UPDATE inventory_batches SET quantity_remaining = quantity_remaining - ? WHERE batch_id = ?')
            ->execute([$quantity, $batchId]);

        $insTxn = $pdo->prepare(
            "INSERT INTO inventory_transactions (item_id, batch_id, transaction_type, quantity, reference_type, remarks, performed_by)
             VALUES (?, ?, 'adjustment', ?, 'adjustment', ?, ?)"
        );
        $insTxn->execute([$itemId, $batchId, $quantity, $remarks !== '' ? $remarks : null, Session::getUserId()]);
        $transactionId = (int)$pdo->lastInsertId();

        $adjustmentId = null;
        $attempts = 0;
        while ($adjustmentId === null) {
            $attempts++;
            $candidateNumber = generateAdjustmentNumber($pdo);
            try {
                $insAdj = $pdo->prepare(
                    "INSERT INTO stock_adjustments (adjustment_number, item_id, batch_id, direction, quantity, reason, remarks, adjustment_date, adjusted_by, transaction_id)
                     VALUES (?, ?, ?, 'decrease', ?, ?, ?, ?, ?, ?)"
                );
                $insAdj->execute([$candidateNumber, $itemId, $batchId, $quantity, $reason, $remarks !== '' ? $remarks : null, $adjDate, Session::getUserId(), $transactionId]);
                $adjustmentId = (int)$pdo->lastInsertId();
                $adjustmentNumber = $candidateNumber;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' && $attempts < 3) {
                    continue;
                }
                throw $e;
            }
        }

        $pdo->prepare('UPDATE inventory_transactions SET reference_id = ? WHERE transaction_id = ?')
            ->execute([$adjustmentId, $transactionId]);
    }

    $pdo->commit();

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Inventory', 'Stock adjustment', ?, ?)"
        )->execute([
            Session::getUserId(),
            "{$adjustmentNumber}: " . ucfirst($direction) . " {$quantity} of {$item['item_name']} ({$reason})",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    notifyUsersByRole(
        $pdo, ['owner', 'manager'], 'inventory', 'Stock adjustment recorded',
        "{$adjustmentNumber}: " . ucfirst($direction) . " {$quantity} of {$item['item_name']} ({$reason}).",
        'inventory_adjustment', $adjustmentId
    );

    flash_set('success', "Adjustment {$adjustmentNumber} saved.");
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('inventory_adjustment_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong saving this adjustment. Please try again.');
}

header('Location: ' . adjustmentReturnTo());
exit;
