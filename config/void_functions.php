<?php
/**
 * config/void_functions.php
 *
 * The order-void workflow: a cashier requests that one of their own orders be
 * reversed, an owner or manager approves or rejects it, and approval performs
 * the actual reversal.
 *
 * Lives in config/ rather than as a per-module copy for the same reason
 * config/notifications.php does: approveVoidRequest() is a WRITE that moves
 * money and stock, and two near-identical copies of it (one under cashier/,
 * one under orders/) could drift apart silently. Read helpers here are shared
 * by the same token -- the cashier's own list and the owner/manager review
 * queue must agree on what "pending" looks like.
 *
 * Function names are all prefixed `orderVoid*` / `voidRequest*` on purpose:
 * this file gets required alongside pos_functions.php, order_functions.php and
 * inventory_functions.php in different combinations, and this app has already
 * been bitten by two modules' includes defining the same bare helper name.
 *
 * Requires config/database.php (a live PDO handle passed in by the caller) and,
 * for the notification fan-out, config/notifications.php.
 */

require_once __DIR__ . '/notifications.php';

/**
 * Why a cashier is asking. A closed list rather than free text alone, so the
 * review queue can be filtered and the reasons can actually be counted later --
 * `other` keeps the escape hatch, and reason_notes is available on every code.
 */
const VOID_REASON_LABELS = [
    'wrong_order'        => 'Wrong order rung up',
    'customer_cancelled' => 'Customer cancelled',
    'duplicate_entry'    => 'Duplicate entry',
    'pricing_error'      => 'Pricing / discount error',
    'quality_issue'      => 'Food quality issue',
    'other'              => 'Other',
];

const VOID_STATUS_LABELS = [
    'pending'   => 'Pending review',
    'approved'  => 'Approved',
    'rejected'  => 'Rejected',
    'cancelled' => 'Withdrawn',
];

function voidReasonLabel(string $code): string
{
    return VOID_REASON_LABELS[$code] ?? ucfirst(str_replace('_', ' ', $code));
}

function voidStatusLabel(string $status): string
{
    return VOID_STATUS_LABELS[$status] ?? ucfirst($status);
}

/**
 * Only the four pill modifiers that exist in BOTH design systems are used here
 * (owner-panel.css and pos.css) -- this same label/badge pair renders on the
 * cashier's POS-styled Orders page and on the owner/manager review queue, and
 * pos.css has no `.is-warning`.
 */
function voidStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending'  => 'is-info',
        'approved' => 'is-danger',   // approved means the sale is gone -- red is the honest colour
        default    => 'is-neutral',  // rejected / cancelled
    };
}

/**
 * VOID-{year}-{seq}, mirroring generateOrderNumber() (cashier/includes/
 * pos_functions.php) and generateAdjustmentNumber() (inventory/includes/
 * inventory_functions.php) exactly. Numeric MAX(), not a string ORDER BY --
 * a string sort puts 'VOID-2026-1000' before 'VOID-2026-999'. Callers must
 * catch a duplicate-key error and retry; the UNIQUE index is the real net.
 */
function generateVoidNumber(PDO $db): string
{
    $prefix = 'VOID-' . date('Y') . '-';
    $stmt = $db->prepare('SELECT MAX(CAST(SUBSTRING(void_number, ?) AS UNSIGNED)) FROM order_void_requests WHERE void_number LIKE ?');
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $next = (int)$stmt->fetchColumn() + 1;

    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * The most recent void request for each of the given order ids, keyed by
 * order_id -- one query for a whole page of orders rather than one per row.
 *
 * "Most recent" is by void_request_id DESC: an order can accumulate several
 * requests over its life (rejected, then re-requested), and what every list
 * needs to show is where it stands NOW.
 */
function orderVoidStatesByOrderIds(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
    if (empty($orderIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    // The self-join on (order_id, MAX(void_request_id)) rather than a window
    // function: MariaDB 10.4 has them, but this matches the "no window function
    // needed" pattern computeBestSellerPerCategory() already established here.
    $stmt = $db->prepare(
        "SELECT v.*, ru.first_name AS requester_first_name, ru.last_name AS requester_last_name,
                rv.first_name AS reviewer_first_name, rv.last_name AS reviewer_last_name
         FROM order_void_requests v
         JOIN (
             SELECT order_id, MAX(void_request_id) AS latest_id
             FROM order_void_requests
             WHERE order_id IN ({$placeholders})
             GROUP BY order_id
         ) latest ON latest.latest_id = v.void_request_id
         LEFT JOIN users ru ON ru.user_id = v.requested_by
         LEFT JOIN users rv ON rv.user_id = v.reviewed_by"
    );
    $stmt->execute($orderIds);

    $byOrder = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byOrder[(int)$row['order_id']] = $row;
    }

    return $byOrder;
}

/** Single-order convenience wrapper around orderVoidStatesByOrderIds(). */
function orderVoidState(PDO $db, int $orderId): ?array
{
    return orderVoidStatesByOrderIds($db, [$orderId])[$orderId] ?? null;
}

/**
 * Whether a cashier may raise a void request against this order right now, and
 * if not, why. Returned as [bool, reason] so the caller can both hide the
 * button AND reject a hand-crafted POST with the same sentence -- the two must
 * never disagree, which is exactly what happens when the check is duplicated.
 *
 * $order must carry order_id, order_status and cashier_id.
 *
 * $knownVoidState lets a list page that has already bulk-loaded its void states
 * via orderVoidStatesByOrderIds() hand the row straight in, instead of this
 * running one more query per row. Pass the row (or null for "no request
 * exists"); leave it as the `false` default to have this look it up itself.
 */
function orderVoidEligibility(PDO $db, array $order, int $requesterId, array|null|false $knownVoidState = false): array
{
    if ((int)$order['cashier_id'] !== $requesterId) {
        return [false, 'You can only request a void for an order you rang up yourself.'];
    }
    if ($order['order_status'] === 'voided') {
        return [false, 'This order has already been voided.'];
    }

    // Deliberately NOT gated on payment_status = 'paid'. An order fully covered
    // by a reservation deposit gets no order_payments row at all (create_order.php
    // only writes one when a balance is actually due), yet it still consumed
    // stock and still marked its reservation completed -- so it is exactly the
    // kind of order that must remain voidable. The reversal handles every case:
    // its UPDATE on order_payments is scoped to paid rows and is simply a no-op
    // when there is nothing to reverse.

    $existing = $knownVoidState === false
        ? orderVoidState($db, (int)$order['order_id'])
        : $knownVoidState;
    if ($existing !== null && $existing['status'] === 'pending') {
        return [false, 'A void request for this order is already awaiting review.'];
    }

    return [true, ''];
}

/**
 * Creates a pending void request. Returns [bool $ok, string $message, ?string $voidNumber].
 *
 * The eligibility check runs INSIDE this function as well as in the page that
 * draws the button, because the button is not the only way to reach here.
 */
function createVoidRequest(PDO $db, int $orderId, int $requesterId, string $reasonCode, string $reasonNotes, ?int $shiftId): array
{
    if (!isset(VOID_REASON_LABELS[$reasonCode])) {
        return [false, 'Please choose a reason for this void.', null];
    }

    $orderStmt = $db->prepare(
        "SELECT o.order_id, o.order_number, o.order_status, o.cashier_id, o.total_amount, op.payment_status
         FROM orders o
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE o.order_id = ?"
    );
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return [false, 'That order no longer exists.', null];
    }

    [$eligible, $why] = orderVoidEligibility($db, $order, $requesterId);
    if (!$eligible) {
        return [false, $why, null];
    }

    $notes = trim($reasonNotes);
    if ($notes === '' && $reasonCode === 'other') {
        return [false, 'Please describe the reason when choosing "Other".', null];
    }

    $insert = $db->prepare(
        'INSERT INTO order_void_requests (void_number, order_id, requested_by, shift_id, reason_code, reason_notes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $attempts = 0;
    while (true) {
        $attempts++;
        $voidNumber = generateVoidNumber($db);

        try {
            $insert->execute([$voidNumber, $orderId, $requesterId, $shiftId, $reasonCode, $notes !== '' ? mb_substr($notes, 0, 500) : null]);
            break;
        } catch (PDOException $e) {
            // Both guards on this table raise SQLSTATE 23000, so the generic
            // code cannot tell them apart -- the DRIVER code has to. 1062 on
            // uq_voidreq_one_pending_per_order means another terminal raised a
            // request for this same order a moment ago (the check above lost a
            // race); 1062 on uq_voidreq_number just means two requests picked
            // the same candidate number, which is worth retrying.
            $driverCode = (int)($e->errorInfo[1] ?? 0);
            if ($driverCode === 1062 && str_contains($e->getMessage(), 'uq_voidreq_one_pending_per_order')) {
                return [false, 'A void request for this order is already awaiting review.', null];
            }
            if ($driverCode === 1062 && $attempts < 3) {
                continue;
            }
            throw $e;
        }
    }

    notifyUsersByRole(
        $db,
        ['owner', 'manager'],
        'order',
        'Void request awaiting review',
        "{$voidNumber}: order {$order['order_number']} (\u{20b1}" . number_format((float)$order['total_amount'], 2) . ') — ' . voidReasonLabel($reasonCode) . '.',
        'void_request',
        (int)$db->lastInsertId()
    );

    return [true, "Void request {$voidNumber} submitted for approval.", $voidNumber];
}

/**
 * Puts back exactly the stock this order consumed, to exactly the batches it
 * came out of. Returns how many ledger rows were reversed.
 *
 * Reversal is driven by the LEDGER, never by re-reading the recipe. Re-deriving
 * quantities from menu_item_ingredients would restore whatever the recipe says
 * TODAY, and recipes genuinely change under this app -- the 2026-08-17
 * yield/wastage removal rewrote every quantity in that table, and costing
 * re-costs items on a schedule. The stock_out rows written at checkout are the
 * only record of what this order actually took and which batch it came from, so
 * they are what gets given back. An order that predates per-transaction logging
 * simply restores nothing, which is the honest outcome: there is no record to
 * reverse, and inventing one would corrupt the ledger the reconciliation report
 * is built on.
 */
function restoreInventoryForVoidedOrder(PDO $db, int $orderId, int $performedBy): int
{
    $stmt = $db->prepare(
        "SELECT t.transaction_id, t.item_id, t.batch_id, t.quantity, t.reference_id
         FROM inventory_transactions t
         JOIN order_items oi ON oi.order_item_id = t.reference_id
         WHERE oi.order_id = ?
           AND t.transaction_type = 'stock_out'
           AND t.reference_type IN ('order_item', 'packaging_deduction')
           AND t.batch_id IS NOT NULL
         ORDER BY t.transaction_id ASC"
    );
    $stmt->execute([$orderId]);
    $ledgerRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ledgerRows)) {
        return 0;
    }

    // One UPDATE per ledger row, deliberately NOT a single UPDATE ... JOIN over
    // the whole set: two lines of the same order can draw the same ingredient
    // from the same batch, and a multi-row-source UPDATE JOIN silently applies
    // only ONE of the matching increments in MySQL/MariaDB -- no error, no
    // warning, just a short restock nobody would notice.
    $bumpBatch = $db->prepare('UPDATE inventory_batches SET quantity_remaining = quantity_remaining + ? WHERE batch_id = ?');

    // transaction_type='stock_in' (not 'adjustment') because that is this app's
    // established convention for an increase-direction correction -- see
    // buildInventoryReconciliationTable()'s docblock in
    // owner/includes/report_functions.php. Every existing signed running-balance
    // query ("stock_in -> +qty, everything else -> -qty") therefore handles
    // these rows correctly with no change. reference_type='void_return' is what
    // keeps them distinguishable from a purchase receipt.
    $logReturn = $db->prepare(
        "INSERT INTO inventory_transactions
            (item_id, batch_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by)
         VALUES (?, ?, 'stock_in', ?, 'void_return', ?, ?, ?)"
    );

    $restored = 0;
    foreach ($ledgerRows as $row) {
        $bumpBatch->execute([$row['quantity'], $row['batch_id']]);
        $logReturn->execute([
            (int)$row['item_id'],
            (int)$row['batch_id'],
            $row['quantity'],
            (int)$row['reference_id'],
            'Void return of stock-out #' . (int)$row['transaction_id'],
            $performedBy,
        ]);
        $restored++;
    }

    return $restored;
}

/**
 * Approves a pending void request and reverses the sale, all in one
 * transaction. Returns [bool $ok, string $message].
 *
 * WHAT APPROVAL CHANGES, and why each one is needed:
 *
 *   inventory        stock back to its original batches (see above)
 *   order_items      status -> 'cancelled'; sales_analytics_functions.php
 *                    already excludes `oi.status != 'cancelled'` from every
 *                    revenue / best-seller / category query
 *   orders           order_status -> 'voided'; every sales figure already
 *                    filters order_status = 'completed'
 *   order_payments   payment_status -> 'voided'; computeShiftTotals() and the
 *                    revenue queries already filter payment_status = 'paid',
 *                    so an OPEN shift's totals correct themselves
 *   reservations     a reservation whose only order was just voided goes back
 *                    to 'confirmed' (create_order.php had set it 'completed'),
 *                    which also releases the customer's deposit for re-use --
 *                    see getReservationCredit()
 *
 * WHAT APPROVAL DELIBERATELY DOES NOT CHANGE: a CLOSED shift's frozen
 * total_sales / expected_cash / counted_cash / variance. Those record what was
 * physically counted and remitted that day and have already been reconciled;
 * rewriting them to match a decision taken later would falsify a settled cash
 * count. The request is flagged shift_was_closed instead, so the review queue
 * and the order detail can say so out loud rather than leaving a silent gap.
 */
function approveVoidRequest(PDO $db, int $voidRequestId, int $reviewerId, string $reviewNotes): array
{
    try {
        $db->beginTransaction();

        // FOR UPDATE plus the status re-check below is what makes a double-click
        // (or two managers acting at once) safe: the second one blocks here,
        // then sees a non-pending status and stops without reversing twice.
        $reqStmt = $db->prepare('SELECT * FROM order_void_requests WHERE void_request_id = ? FOR UPDATE');
        $reqStmt->execute([$voidRequestId]);
        $request = $reqStmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $db->rollBack();
            return [false, 'That void request no longer exists.'];
        }
        if ($request['status'] !== 'pending') {
            $db->rollBack();
            return [false, 'That request was already ' . strtolower(voidStatusLabel($request['status'])) . '.'];
        }

        $orderId = (int)$request['order_id'];

        $orderStmt = $db->prepare('SELECT * FROM orders WHERE order_id = ? FOR UPDATE');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $db->rollBack();
            return [false, 'The order behind this request no longer exists.'];
        }
        if ($order['order_status'] === 'voided') {
            $db->rollBack();
            return [false, 'That order has already been voided.'];
        }

        $restored = restoreInventoryForVoidedOrder($db, $orderId, $reviewerId);

        $db->prepare("UPDATE order_items SET status = 'cancelled' WHERE order_id = ?")->execute([$orderId]);
        $db->prepare("UPDATE orders SET order_status = 'voided' WHERE order_id = ?")->execute([$orderId]);
        $db->prepare("UPDATE order_payments SET payment_status = 'voided' WHERE order_id = ? AND payment_status = 'paid'")
           ->execute([$orderId]);

        // Was the money already counted and remitted? Read the ORDER's shift,
        // not the request's -- a request raised today can target an order from
        // a shift that closed last week, and it is the latter that decides
        // whether a settled cash count is involved.
        $shiftWasClosed = 0;
        if ($order['shift_id'] !== null) {
            $shiftStmt = $db->prepare('SELECT status FROM cash_balances WHERE shift_id = ?');
            $shiftStmt->execute([(int)$order['shift_id']]);
            $shiftWasClosed = $shiftStmt->fetchColumn() === 'closed' ? 1 : 0;
        }

        if ($order['reservation_id'] !== null) {
            $reservationId = (int)$order['reservation_id'];

            // Only when nothing else still stands for that reservation -- a
            // reservation re-rung on a second order is still a completed visit.
            $otherStmt = $db->prepare(
                "SELECT COUNT(*) FROM orders WHERE reservation_id = ? AND order_id <> ? AND order_status <> 'voided'"
            );
            $otherStmt->execute([$reservationId, $orderId]);

            if ((int)$otherStmt->fetchColumn() === 0) {
                $db->prepare("UPDATE reservations SET status = 'confirmed' WHERE reservation_id = ? AND status = 'completed'")
                   ->execute([$reservationId]);
            }
        }

        $notes = trim($reviewNotes);
        $db->prepare(
            "UPDATE order_void_requests
             SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ?,
                 voided_amount = ?, restored_txn_count = ?, shift_was_closed = ?
             WHERE void_request_id = ?"
        )->execute([
            $reviewerId,
            $notes !== '' ? mb_substr($notes, 0, 500) : null,
            $order['total_amount'],
            $restored,
            $shiftWasClosed,
            $voidRequestId,
        ]);

        $db->commit();

        // Logged AFTER the commit, never inside it: an activity-log or
        // notification failure must not be able to roll back a completed
        // reversal (same best-effort convention as config/notifications.php).
        logActivityOnce(
            $db, $reviewerId, 'orders', 'void_approved',
            "Void {$request['void_number']} approved for order {$order['order_number']} (\u{20b1}"
                . number_format((float)$order['total_amount'], 2) . "); {$restored} stock line(s) returned.",
            'void_request', $voidRequestId
        );

        $message = "Order {$order['order_number']} voided. "
            . ($restored > 0 ? "{$restored} stock line(s) returned to inventory." : 'No stock movement was on record to return.');
        if ($shiftWasClosed) {
            $message .= ' That shift was already closed, so its remitted cash figures were left untouched.';
        }

        return [true, $message];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('approveVoidRequest failed: ' . $e->getMessage());

        return [false, 'Something went wrong voiding this order. Nothing was changed. Please try again.'];
    }
}

/** Rejects a pending request. Changes nothing about the order itself. */
function rejectVoidRequest(PDO $db, int $voidRequestId, int $reviewerId, string $reviewNotes): array
{
    try {
        $db->beginTransaction();

        $reqStmt = $db->prepare('SELECT * FROM order_void_requests WHERE void_request_id = ? FOR UPDATE');
        $reqStmt->execute([$voidRequestId]);
        $request = $reqStmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $db->rollBack();
            return [false, 'That void request no longer exists.'];
        }
        if ($request['status'] !== 'pending') {
            $db->rollBack();
            return [false, 'That request was already ' . strtolower(voidStatusLabel($request['status'])) . '.'];
        }

        $notes = trim($reviewNotes);
        $db->prepare(
            "UPDATE order_void_requests
             SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
             WHERE void_request_id = ?"
        )->execute([$reviewerId, $notes !== '' ? mb_substr($notes, 0, 500) : null, $voidRequestId]);

        $db->commit();

        logActivityOnce(
            $db, $reviewerId, 'orders', 'void_rejected',
            "Void {$request['void_number']} rejected.",
            'void_request_rejected', $voidRequestId
        );

        return [true, "Void request {$request['void_number']} rejected. The order is unchanged."];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('rejectVoidRequest failed: ' . $e->getMessage());

        return [false, 'Something went wrong rejecting this request. Please try again.'];
    }
}

/**
 * Lets the cashier who raised a request withdraw it while it is still pending.
 * Scoped to the requester -- a cashier can withdraw their own ask, never
 * someone else's, and never one that has already been decided.
 */
function withdrawVoidRequest(PDO $db, int $voidRequestId, int $requesterId): array
{
    try {
        $stmt = $db->prepare(
            "UPDATE order_void_requests SET status = 'cancelled'
             WHERE void_request_id = ? AND requested_by = ? AND status = 'pending'"
        );
        $stmt->execute([$voidRequestId, $requesterId]);

        if ($stmt->rowCount() !== 1) {
            return [false, 'That request can no longer be withdrawn.'];
        }

        return [true, 'Void request withdrawn.'];
    } catch (PDOException $e) {
        error_log('withdrawVoidRequest failed: ' . $e->getMessage());

        return [false, 'Something went wrong withdrawing this request. Please try again.'];
    }
}

/**
 * Shared query builder for the owner/manager review queue, its pagination
 * COUNT(*) and its print view -- same "one builder, several consumers"
 * convention as buildOrdersQuery() and buildInventoryTransactionQuery().
 */
function buildVoidRequestsQuery(array $filters): array
{
    $sql = "
        SELECT v.*, o.order_number, o.total_amount, o.order_type, o.reservation_id, o.created_at AS order_created_at,
               ru.first_name AS requester_first_name, ru.last_name AS requester_last_name,
               rv.first_name AS reviewer_first_name, rv.last_name AS reviewer_last_name
        FROM order_void_requests v
        JOIN orders o ON o.order_id = v.order_id
        LEFT JOIN users ru ON ru.user_id = v.requested_by
        LEFT JOIN users rv ON rv.user_id = v.reviewed_by
    ";

    $where  = [];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'v.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['reason_code'])) {
        $where[] = 'v.reason_code = ?';
        $params[] = $filters['reason_code'];
    }
    if (!empty($filters['requested_by'])) {
        $where[] = 'v.requested_by = ?';
        $params[] = $filters['requested_by'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'v.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'v.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['search'])) {
        $where[] = '(o.order_number LIKE ? OR v.void_number LIKE ?)';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    // Pending first regardless of date -- this is a work queue before it is a
    // history, and a two-week-old unreviewed request must not fall below
    // yesterday's already-decided ones.
    $sql .= " ORDER BY (v.status = 'pending') DESC, v.created_at DESC, v.void_request_id DESC";

    return [$sql, $params];
}

/** How many requests are waiting -- drives the nav badge on the review queue. */
function pendingVoidRequestCount(PDO $db): int
{
    try {
        return (int)$db->query("SELECT COUNT(*) FROM order_void_requests WHERE status = 'pending'")->fetchColumn();
    } catch (PDOException $e) {
        error_log('pendingVoidRequestCount failed: ' . $e->getMessage());

        return 0;
    }
}
