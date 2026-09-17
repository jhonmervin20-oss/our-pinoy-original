<?php
/**
 * purchase_orders/purchase_order_delete.php
 *
 * Permanently removes a DRAFT purchase order. Owner only, same as every other
 * pipeline action (see includes/po_functions.php's poCanManage()).
 *
 * Draft is the only deletable status, and that is the whole rule. A draft was
 * never sent to anybody: nothing was promised to a supplier and no stock ever
 * moved, so there is no paper trail worth preserving. Every later status is
 * different -- 'approved' onward is a commitment that was actually made, and
 * 'cancelled' is itself a record of a decision someone took. Those stay.
 *
 * This matters more since auto-PO generation: the nightly run drafts orders on
 * its own, and an owner who does not want one should be able to remove it
 * outright rather than cancelling a thing that was never live just to get a
 * Delete button.
 *
 * Deleting a draft is also the correct way to undo it, not merely the tidy one.
 * `available` counts every non-cancelled, non-received PO -- drafts included --
 * so an unwanted draft suppresses reordering for its items until someone acts.
 * Deleting it hands that quantity straight back, and the next run re-evaluates
 * the item honestly. `reorder_suggestions.po_id` is ON DELETE SET NULL, so the
 * suggestion it was covering survives and correctly goes back to looking
 * uncovered -- which is what it now is.
 *
 * Two guards, both enforced server-side rather than by hiding a button:
 *
 *   1. Only 'draft' can be deleted.
 *
 *   2. Nothing that owns stock can be deleted, checked independently of status.
 *      A draft cannot legitimately have inventory_batches -- receiving requires
 *      'ordered'/'partially_received' -- so this should never fire for one. It
 *      stays because fk_batch_poitem is ON DELETE RESTRICT: were a batch ever to
 *      reference this PO, the cascade from purchase_orders ->
 *      purchase_order_items would abort with a raw FK error instead of a
 *      sentence the owner can act on, and stock physically on the shelf would be
 *      one bad query away from losing the record of where it came from.
 *
 * What the delete takes with it: purchase_order_items (fk_poitem_po is
 * ON DELETE CASCADE). reorder_suggestions.po_id is ON DELETE SET NULL, so any
 * suggestion this PO was covering survives and correctly goes back to looking
 * uncovered -- which is what it now is.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/po_functions.php'; // poCanManage()/poDenyAccess()

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}
if (!poCanManage()) {
    poDenyAccess('Only the owner can delete purchase orders.');
}

/* Where to send the owner back to: the filtered, paginated view they deleted
   from. Rebuilt from the three keys this list actually understands rather than
   echoed back as-is -- a return string straight from the request is an open
   redirect waiting to happen, and it would also let junk params through. */
$returnQs = '';
parse_str((string)($_POST['return_qs'] ?? ''), $returnParams);
$allowedReturn = [];
if (!empty($returnParams['search'])) {
    $allowedReturn['search'] = (string)$returnParams['search'];
}
if (!empty($returnParams['status'])) {
    $allowedReturn['status'] = (string)$returnParams['status'];
}
if (!empty($returnParams['page']) && ctype_digit((string)$returnParams['page'])) {
    $allowedReturn['page'] = (string)$returnParams['page'];
}
if ($allowedReturn) {
    $returnQs = '?' . http_build_query($allowedReturn);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: purchase_orders.php' . $returnQs);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: purchase_orders.php' . $returnQs);
    exit;
}

$poId = trim((string)($_POST['po_id'] ?? ''));
if ($poId === '' || !ctype_digit($poId)) {
    flash_set('error', 'Invalid purchase order.');
    header('Location: purchase_orders.php' . $returnQs);
    exit;
}

$pdo = Database::getInstance()->getConnection();

try {
    $stmt = $pdo->prepare('SELECT po_number, status FROM purchase_orders WHERE po_id = ?');
    $stmt->execute([$poId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        flash_set('error', 'That purchase order no longer exists.');
        header('Location: purchase_orders.php' . $returnQs);
        exit;
    }

    if ($po['status'] !== 'draft') {
        flash_set(
            'error',
            'Only a draft purchase order can be deleted. '
                . 'Once an order has been approved it stays on record -- cancel it instead.'
        );
        header("Location: purchase_order_view.php?id={$poId}");
        exit;
    }

    // Guard 2 -- see the docblock. Counts batches rather than trusting
    // quantity_received alone, because the batch rows are what the foreign key
    // actually restricts on.
    $linked = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM inventory_batches b
             JOIN purchase_order_items pi ON pi.po_item_id = b.po_item_id
             WHERE pi.po_id = ?) AS batches,
            (SELECT COALESCE(SUM(quantity_received), 0) FROM purchase_order_items WHERE po_id = ?) AS received"
    );
    $linked->execute([$poId, $poId]);
    $counts = $linked->fetch(PDO::FETCH_ASSOC);

    if ((int)$counts['batches'] > 0 || (float)$counts['received'] > 0) {
        flash_set(
            'error',
            "{$po['po_number']} has stock recorded against it, so it can't be deleted -- "
                . 'the inventory batches it created still reference it. Cancel it instead; that keeps it out of every report.'
        );
        header("Location: purchase_order_view.php?id={$poId}");
        exit;
    }

    $pdo->prepare('DELETE FROM purchase_orders WHERE po_id = ?')->execute([$poId]);

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Purchase Orders', 'Delete purchase order', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Deleted draft purchase order {$po['po_number']}",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort, same as the other endpoints here.
    }

    flash_set('success', "Purchase order {$po['po_number']} deleted.");
} catch (PDOException $e) {
    error_log('purchase_order_delete.php failed: ' . $e->getMessage());
    flash_set('error', "Couldn't delete that purchase order. Please try again.");
}

header('Location: purchase_orders.php' . $returnQs);
exit;
