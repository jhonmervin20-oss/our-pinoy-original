<?php
/**
 * inventory/inventory_wastage_save.php
 *
 * Logs a wastage record against a specific existing batch. Same
 * SELECT ... FOR UPDATE locking pattern the old inventory_batch_adjust.php
 * used for its waste branch. Wastage records are never editable or
 * deletable — this file only ever inserts.
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
    header('Location: ' . wastageReturnTo());
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . wastageReturnTo());
    exit;
}

const WASTAGE_REASONS = ['expired', 'spoiled', 'burned', 'overcooked', 'dropped', 'customer_complaint', 'quality_issue', 'other'];

/**
 * Where to go when this is done.
 *
 * Wastage and adjustments can now be recorded straight from the Batches tab on
 * inventory.php, so the handler has to be able to return there. Only known
 * pages are honoured -- `return_to` arrives from a form post, and echoing it
 * into a Location header unchecked is an open redirect.
 */
function wastageReturnTo(): string
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
    return $allowed[$key] ?? 'inventory_wastage.php';
}

$itemId   = trim((string)($_POST['item_id'] ?? ''));
$batchId  = trim((string)($_POST['batch_id'] ?? ''));
$quantity = trim((string)($_POST['quantity'] ?? ''));
$reason   = trim((string)($_POST['reason'] ?? ''));
$remarks  = trim((string)($_POST['remarks'] ?? ''));
$wstDate  = trim((string)($_POST['wastage_date'] ?? ''));

$errors = [];

if ($itemId === '' || !ctype_digit($itemId)) {
    $errors[] = 'Please select an inventory item.';
}
if ($batchId === '' || !ctype_digit($batchId)) {
    $errors[] = 'Please select a batch.';
}
if ($quantity === '' || !is_numeric($quantity) || (float)$quantity <= 0) {
    $errors[] = 'Please enter a quantity greater than 0.';
}
if (!in_array($reason, WASTAGE_REASONS, true)) {
    $errors[] = 'Please choose a valid reason.';
}
if ($wstDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $wstDate) || $wstDate > date('Y-m-d')) {
    $errors[] = 'Please enter a valid date (not in the future).';
}
if (mb_strlen($remarks) > 255) {
    $remarks = mb_substr($remarks, 0, 255);
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    header('Location: ' . wastageReturnTo());
    exit;
}

$pdo = Database::getInstance()->getConnection();

try {
    $pdo->beginTransaction();

    $itemStmt = $pdo->prepare('SELECT item_id, item_name FROM inventory_items WHERE item_id = ?');
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    $batchStmt = $pdo->prepare(
        'SELECT batch_id, item_id, quantity_remaining FROM inventory_batches WHERE batch_id = ? AND item_id = ? FOR UPDATE'
    );
    $batchStmt->execute([$batchId, $itemId]);
    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item || !$batch) {
        $pdo->rollBack();
        flash_set('error', "That item or batch doesn't exist.");
        header('Location: ' . wastageReturnTo());
        exit;
    }

    $remaining = (float)$batch['quantity_remaining'];
    if ((float)$quantity > $remaining) {
        $pdo->rollBack();
        flash_set('error', "Quantity exceeds what's remaining on this batch ({$remaining}).");
        header('Location: ' . wastageReturnTo());
        exit;
    }

    $pdo->prepare('UPDATE inventory_batches SET quantity_remaining = quantity_remaining - ? WHERE batch_id = ?')
        ->execute([$quantity, $batchId]);

    // inventory_transactions first — wastage_records.transaction_id is a real
    // FK (unlike inventory_transactions.reference_id, which is an
    // unconstrained polymorphic pointer), so the transaction row must exist
    // before the wastage row can reference it.
    $insTxn = $pdo->prepare(
        "INSERT INTO inventory_transactions (item_id, batch_id, transaction_type, quantity, reference_type, remarks, performed_by)
         VALUES (?, ?, 'waste', ?, 'waste', ?, ?)"
    );
    $insTxn->execute([$itemId, $batchId, $quantity, $remarks !== '' ? $remarks : null, Session::getUserId()]);
    $transactionId = (int)$pdo->lastInsertId();

    $wastageId = null;
    $attempts = 0;
    while ($wastageId === null) {
        $attempts++;
        $candidateNumber = generateWastageNumber($pdo);
        try {
            $insWst = $pdo->prepare(
                "INSERT INTO wastage_records (wastage_number, item_id, batch_id, quantity, reason, remarks, wastage_date, recorded_by, transaction_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insWst->execute([$candidateNumber, $itemId, $batchId, $quantity, $reason, $remarks !== '' ? $remarks : null, $wstDate, Session::getUserId(), $transactionId]);
            $wastageId = (int)$pdo->lastInsertId();
            $wastageNumber = $candidateNumber;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && $attempts < 3) {
                continue;
            }
            throw $e;
        }
    }

    $pdo->prepare('UPDATE inventory_transactions SET reference_id = ? WHERE transaction_id = ?')
        ->execute([$wastageId, $transactionId]);

    $pdo->commit();

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Inventory', 'Log wastage', ?, ?)"
        )->execute([
            Session::getUserId(),
            "{$wastageNumber}: {$quantity} of {$item['item_name']} ({$reason})",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    notifyUsersByRole(
        $pdo, ['owner', 'manager'], 'inventory', 'Wastage recorded',
        "{$wastageNumber}: {$quantity} of {$item['item_name']} wasted ({$reason}).",
        'inventory_waste', $wastageId
    );

    flash_set('success', "Wastage {$wastageNumber} logged.");
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('inventory_wastage_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong logging this wastage. Please try again.');
}

header('Location: ' . wastageReturnTo());
exit;
