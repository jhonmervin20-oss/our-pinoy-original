<?php
/**
 * cashier/api/create_order.php
 *
 * POST checkout payload -> JSON. Runs the whole sale as one transaction:
 * re-verifies the reservation's credit fresh (locked, to guard against a
 * second terminal billing the same reservation concurrently), re-prices
 * every line server-side (the client only ever sends WHICH lines, never
 * prices), writes orders/order_items/order_payments, and leniently
 * deducts inventory (a deduction failure never blocks the sale — inventory
 * data isn't fully populated yet).
 *
 * reservation_advance_orders / reservation_payments are never written to —
 * they stay the historical pre-order/pre-payment record.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/notifications.php';
require_once __DIR__ . '/../includes/pos_functions.php';

header('Content-Type: application/json');

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['cashier', 'owner'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Your session has expired. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token()) {
    http_response_code(400);
    echo json_encode(['error' => 'Your session expired. Please refresh the page and try again.']);
    exit;
}

function pos_fail(string $message): void
{
    http_response_code(422);
    echo json_encode(['error' => $message]);
    exit;
}

// Every order must belong to an open shift -- checked here, not just hidden
// behind pos.php's "start your shift" overlay, so this can't be bypassed by
// calling this endpoint directly. Looked up fresh (never client-sent) so it
// can't be spoofed either.
$db = Database::getInstance()->getConnection();
$openShift = getOpenShiftForCashier($db, Session::getUserId());
if (!$openShift) {
    pos_fail('You need to start your shift before taking orders.');
}

$reservationId = trim((string)($_POST['reservation_id'] ?? ''));
$reservationId = ($reservationId !== '' && ctype_digit($reservationId)) ? (int)$reservationId : null;

$includedAdvanceOrderIds = json_decode((string)($_POST['included_advance_order_ids'] ?? '[]'), true);
if (!is_array($includedAdvanceOrderIds)) {
    $includedAdvanceOrderIds = [];
}
$includedAdvanceOrderIds = array_values(array_unique(array_map('intval', $includedAdvanceOrderIds)));

$manualItemsRaw = json_decode((string)($_POST['manual_items'] ?? '[]'), true);
if (!is_array($manualItemsRaw)) {
    $manualItemsRaw = [];
}

$manualItems = [];
foreach ($manualItemsRaw as $row) {
    $menuItemId = (int)($row['menu_item_id'] ?? 0);
    $quantity   = (int)($row['quantity'] ?? 0);
    if ($menuItemId > 0 && $quantity > 0 && $quantity <= 999) {
        $manualItems[] = ['menu_item_id' => $menuItemId, 'quantity' => $quantity];
    }
}

$orderType     = $_POST['order_type'] ?? 'dine_in';
$paymentMethod = trim((string)($_POST['payment_method'] ?? ''));

$discountTypeIdRaw = trim((string)($_POST['discount_type_id'] ?? ''));
$discountTypeId    = ($discountTypeIdRaw !== '' && ctype_digit($discountTypeIdRaw)) ? (int)$discountTypeIdRaw : null;

$cashTenderedRaw = trim((string)($_POST['cash_tendered'] ?? ''));
$cashTendered    = ($cashTenderedRaw !== '' && is_numeric($cashTenderedRaw)) ? round((float)$cashTenderedRaw, 2) : null;

if (!$reservationId && !in_array($orderType, ['dine_in', 'takeout'], true)) {
    pos_fail('Please choose dine-in or takeout for a walk-in order.');
}

if (empty($includedAdvanceOrderIds) && empty($manualItems)) {
    pos_fail('Add at least one item before completing the order.');
}

$db = Database::getInstance()->getConnection();

try {
    $db->beginTransaction();

    $reservation = null;
    $creditToApply = 0.0;

    if ($reservationId) {
        $lockStmt = $db->prepare('SELECT * FROM reservations WHERE reservation_id = ? FOR UPDATE');
        $lockStmt->execute([$reservationId]);
        $reservation = $lockStmt->fetch();

        if (!$reservation) {
            $db->rollBack();
            pos_fail('That reservation no longer exists.');
        }

        // A no_show/cancelled reservation's table was already released and its
        // deposit forfeited (staffCancelConfirmedReservation() and the no-show
        // sweep both deliberately leave reservation_payments untouched — see
        // that function's own comment — so getReservationCredit() below would
        // otherwise still report the old deposit as spendable). 'completed' is
        // allowed through: that's the already-checked-in case (a second order
        // against the same table), the normal path this same lock re-verifies
        // credit for on every call.
        if (in_array($reservation['status'], ['no_show', 'cancelled'], true)) {
            $db->rollBack();
            $label = $reservation['status'] === 'no_show' ? 'a no-show' : 'cancelled';
            pos_fail("This reservation was marked {$label} and can no longer be used to check in or apply its credit.");
        }

        $credit = getReservationCredit($db, $reservationId);
        $creditToApply = $credit['remaining'];
    }

    $taxSettings = getActiveTaxSettings($db);

    $discountType = null;
    if ($discountTypeId) {
        $discountType = getActiveDiscountTypeById($db, $discountTypeId);
        if (!$discountType) {
            $db->rollBack();
            pos_fail('That discount is no longer available. Please refresh and try again.');
        }
    }
    $forceVatExempt = $discountType && (bool)$discountType['is_vat_exempt'];

    // ---- Assemble + re-price every line server-side --------------------
    $lines = [];
    $inventoryPlan = []; // [['menu_item_id'=>, 'quantity'=>, 'unit_price'=>, 'subtotal'=>, 'is_vat_exempt'=>, 'notes'=>], ...]
    $itemWarnings = [];

    if (!empty($includedAdvanceOrderIds)) {
        if (!$reservationId) {
            $db->rollBack();
            pos_fail('Pre-ordered items require a linked reservation.');
        }

        $placeholders = implode(',', array_fill(0, count($includedAdvanceOrderIds), '?'));
        $advStmt = $db->prepare(
            "SELECT rao.advance_order_id, rao.menu_item_id, rao.quantity, rao.unit_price, rao.subtotal, mi.is_vat_exempt
             FROM reservation_advance_orders rao
             JOIN menu_items mi ON mi.item_id = rao.menu_item_id
             WHERE rao.reservation_id = ? AND rao.advance_order_id IN ($placeholders)"
        );
        $advStmt->execute(array_merge([$reservationId], $includedAdvanceOrderIds));
        $advanceRows = $advStmt->fetchAll();

        if (count($advanceRows) !== count($includedAdvanceOrderIds)) {
            $db->rollBack();
            pos_fail('One or more pre-ordered items no longer match this reservation.');
        }

        foreach ($advanceRows as $adv) {
            $inventoryPlan[] = [
                'menu_item_id' => (int)$adv['menu_item_id'],
                'quantity'     => (int)$adv['quantity'],
                'unit_price'   => (float)$adv['unit_price'],
                'subtotal'     => (float)$adv['subtotal'],
                'is_vat_exempt' => (bool)$adv['is_vat_exempt'],
                'notes'        => 'Pre-ordered (advance order)',
            ];
        }
    }

    if (!empty($manualItems)) {
        $menuIds = array_column($manualItems, 'menu_item_id');
        $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
        $menuStmt = $db->prepare(
            "SELECT item_id, item_name, selling_price, is_vat_exempt, is_available, available_dine_in, available_takeout
             FROM menu_items WHERE item_id IN ($placeholders) AND is_active = 1"
        );
        $menuStmt->execute($menuIds);
        $menuRows = [];
        foreach ($menuStmt->fetchAll() as $row) {
            $menuRows[(int)$row['item_id']] = $row;
        }

        foreach ($manualItems as $item) {
            $menuItemId = $item['menu_item_id'];
            if (!isset($menuRows[$menuItemId])) {
                $db->rollBack();
                pos_fail('One of the selected menu items is no longer available for sale.');
            }
            $menuRow = $menuRows[$menuItemId];

            if (!$menuRow['is_available']) {
                $itemWarnings[] = $menuRow['item_name'] . ' is marked sold out but was added anyway.';
            }

            $unitPrice = (float)$menuRow['selling_price'];
            $quantity  = $item['quantity'];

            $inventoryPlan[] = [
                'menu_item_id'      => $menuItemId,
                'item_name'         => $menuRow['item_name'],
                'quantity'          => $quantity,
                'unit_price'        => $unitPrice,
                'subtotal'          => round($unitPrice * $quantity, 2),
                'is_vat_exempt'     => (bool)$menuRow['is_vat_exempt'],
                'notes'             => null,
                'available_dine_in' => (bool)$menuRow['available_dine_in'],
                'available_takeout' => (bool)$menuRow['available_takeout'],
            ];
        }
    }

    // ---- Stock guard -----------------------------------------------------
    // Nothing above this point checked whether the requested quantity can
    // actually be made. Without it, selling 105 of a 104-serving item went
    // through: the order was created and paid, then
    // sp_deduct_inventory_fifo_recipe_unit() raised "insufficient stock",
    // deductInventoryForOrderItem() caught it and downgraded it to a warning,
    // and NOTHING was deducted -- so the sale was booked while inventory still
    // read 104. Silent ledger drift, not a visible failure.
    //
    // Quantities are summed per menu item first: two separate lines of 60 for
    // the same dish are 120 servings, even though neither line alone exceeds
    // the count. Channel matters too -- takeout is additionally capped by
    // packaging stock (computeMenuItemAvailability()).
    // Hoisted above the guard (it is also used further down for the INSERT and
    // the packaging fee): a reservation-linked order is always dine-in, so the
    // guard must check dine-in stock for it, not whatever the POST said.
    $finalOrderType = $reservationId ? 'dine_in' : $orderType;

    // ---- Channel eligibility guard ---------------------------------------
    // menu_items.available_dine_in/available_takeout are manager-set per item
    // (e.g. "this dish is dine-in only") -- selected in the POS UI already,
    // but never actually enforced, so a channel-restricted item could still
    // be rung up under the wrong one. Only walk-in-added lines are checked
    // here: a reservation's advance-order items are a locked historical
    // record (same "never re-priced" rule as their unit_price/subtotal
    // above) and stay redeemable even if a channel is disabled for that item
    // afterward.
    $channelField = $finalOrderType === 'takeout' ? 'available_takeout' : 'available_dine_in';
    $channelLabel = $finalOrderType === 'takeout' ? 'takeout' : 'dine-in';
    foreach ($inventoryPlan as $line) {
        if (!array_key_exists($channelField, $line)) {
            continue; // advance-order line, not channel-checked
        }
        if (!$line[$channelField]) {
            $db->rollBack();
            pos_fail("{$line['item_name']} is not available for {$channelLabel} orders.");
        }
    }

    $requestedByItem = [];
    foreach ($inventoryPlan as $line) {
        $requestedByItem[$line['menu_item_id']] =
            ($requestedByItem[$line['menu_item_id']] ?? 0) + (int)$line['quantity'];
    }

    $stockField = $finalOrderType === 'takeout' ? 'takeout_stock' : 'dine_in_stock';
    foreach ($requestedByItem as $menuItemId => $wanted) {
        $availability = computeMenuItemAvailability($db, (int)$menuItemId);
        $onHand = $availability[$stockField];

        // null = no recipe/packaging rows at all, so this item is not
        // inventory-constrained and there is nothing to be short of.
        if ($onHand === null || $wanted <= $onHand) {
            continue;
        }

        $nameStmt = $db->prepare('SELECT item_name FROM menu_items WHERE item_id = ?');
        $nameStmt->execute([$menuItemId]);
        $itemName = (string)$nameStmt->fetchColumn();

        $db->rollBack();
        pos_fail(sprintf(
            'Not enough stock for %s — %d requested, only %d can be made right now.',
            $itemName !== '' ? $itemName : 'one of the items',
            $wanted,
            $onHand
        ));
    }

    foreach ($inventoryPlan as $line) {
        $lines[] = ['line_gross' => $line['subtotal'], 'is_vat_exempt' => $forceVatExempt || $line['is_vat_exempt']];
    }

    $totals = computeOrderTotals($lines, $taxSettings);
    $discountAmount = computeDiscountAmount($totals['subtotal'], $discountType);
    $netSubtotal    = round($totals['subtotal'] - $discountAmount, 2);

    // ---- Insert the order, retrying the order number once on a race ----
    // A reservation-linked order is always dine-in (reservations only ever
    // seat guests in-house) -- order_type has no separate 'reservation'
    // value; reservation_id being set is what marks an order as reservation-
    // sourced (walk-ins = reservation_id IS NULL).
    $customerId     = $reservationId ? (int)$reservation['customer_id'] : null;
    $cashierId      = Session::getUserId();

    // ---- Packaging Fee, per Restaurant Settings -> Pricing ----
    // Pass-through packaging cost, only when the policy is 'separate' and
    // only for takeout (packaging is never deducted or billed for dine-in --
    // business rule).
    $pricingSettings = posGetPricingSettings($db);

    $packagingFeeAmount = 0.0;
    if ($finalOrderType === 'takeout' && $pricingSettings['packaging_fee_policy'] === 'separate') {
        foreach ($inventoryPlan as $line) {
            $packagingFeeAmount += computePackagingCostForMenuItem($db, $line['menu_item_id']) * $line['quantity'];
        }
        $packagingFeeAmount = round($packagingFeeAmount, 2);
    }

    $grandTotal = round($netSubtotal + $totals['vat_amount'] + $packagingFeeAmount, 2);

    $creditToApply = min($creditToApply, $grandTotal);
    $balanceDue    = round(max(0.0, $grandTotal - $creditToApply), 2);

    if ($balanceDue > 0 && !in_array($paymentMethod, ['cash', 'gcash'], true)) {
        $db->rollBack();
        pos_fail('Please choose a payment method for the remaining balance.');
    }

    // Re-validated server-side rather than trusting the client's own
    // disabled-button check -- the amount actually collected/credited
    // (order_payments.amount) stays the exact balance due either way;
    // amount_tendered is only extra context for what change to hand back.
    if ($balanceDue > 0 && $paymentMethod === 'cash' && ($cashTendered === null || $cashTendered < $balanceDue - 0.001)) {
        $db->rollBack();
        pos_fail('Cash tendered must cover the balance due.');
    }

    $orderId = null;
    $orderNumber = null;
    $attempts = 0;

    while ($orderId === null) {
        $attempts++;
        $candidateNumber = generateOrderNumber($db);

        try {
            $insertOrder = $db->prepare(
                'INSERT INTO orders (order_number, order_type, reservation_id, customer_id, cashier_id, shift_id, order_status, subtotal, discount_amount, discount_type_id, discount_name, vat_amount, packaging_fee_amount, total_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertOrder->execute([
                $candidateNumber,
                $finalOrderType,
                $reservationId,
                $customerId,
                $cashierId,
                $openShift['shift_id'],
                'open',
                $totals['subtotal'],
                $discountAmount,
                $discountType['discount_type_id'] ?? null,
                $discountType['discount_name'] ?? null,
                $totals['vat_amount'],
                $packagingFeeAmount,
                $grandTotal,
            ]);
            $orderId = (int)$db->lastInsertId();
            $orderNumber = $candidateNumber;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && $attempts < 3) {
                continue;
            }
            throw $e;
        }
    }

    // ---- Order items + lenient inventory deduction ----------------------
    $insertItem = $db->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, subtotal, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $inventoryWarnings = $itemWarnings;

    foreach ($inventoryPlan as $line) {
        $insertItem->execute([
            $orderId,
            $line['menu_item_id'],
            $line['quantity'],
            $line['unit_price'],
            $line['subtotal'],
            'pending',
            $line['notes'],
        ]);
        $orderItemId = (int)$db->lastInsertId();

        $warnings = deductInventoryForOrderItem($db, $orderItemId, $line['menu_item_id'], $line['quantity'], $cashierId);
        $inventoryWarnings = array_merge($inventoryWarnings, $warnings);

        if ($finalOrderType === 'takeout') {
            $packagingWarnings = deductPackagingForOrderItem($db, $orderItemId, $line['menu_item_id'], $line['quantity'], $cashierId);
            $inventoryWarnings = array_merge($inventoryWarnings, $packagingWarnings);
        }
    }

    // ---- Payment, only if there's a balance left to collect --------------
    $paymentRecorded = false;
    if ($balanceDue > 0) {
        $gatewayValue = $paymentMethod === 'gcash' ? 'paymongo_gcash' : 'cash';
        // amount stays exactly the balance due regardless of tendered cash
        // (that's what's actually credited to the order); amount_tendered
        // is only stored for cash, so the receipt can show real change.
        $tenderedToStore = $paymentMethod === 'cash' ? $cashTendered : null;
        $insertPayment = $db->prepare(
            "INSERT INTO order_payments (order_id, payment_method, amount, amount_tendered, payment_status, paid_at)
             VALUES (?, ?, ?, ?, 'paid', NOW())"
        );
        $insertPayment->execute([$orderId, $gatewayValue, $balanceDue, $tenderedToStore]);
        $paymentRecorded = true;
    }

    // A linked order is this app's established "customer actually showed
    // up" signal (same one sweepNoShowReservations() checks for) — so
    // reaching this point auto-completes the reservation, whether or not
    // any advance-order items were redeemed. No-op if it's not 'confirmed'
    // (e.g. already completed, or somehow linked twice).
    if ($reservationId) {
        $completeStmt = $db->prepare("UPDATE reservations SET status = 'completed' WHERE reservation_id = ? AND status = 'confirmed'");
        $completeStmt->execute([$reservationId]);

        // Same copy as customer/includes/reservation_functions.php's
        // notifyReservationCompleted() -- duplicated inline rather than
        // cross-included, since cashier/ doesn't otherwise reach into
        // customer/'s includes (matches this app's per-module-copy
        // convention elsewhere). Only fires when the UPDATE actually
        // flipped a row, not on the no-op cases noted above.
        if ($completeStmt->rowCount() === 1) {
            createNotificationOnce(
                $db, (int)$reservation['customer_id'], 'reservation',
                'Thanks for dining with us!',
                "Your visit for reservation {$reservation['reservation_number']} is complete. We'd love to hear your feedback!",
                'reservation_completed', $reservationId
            );
        }
    }

    $db->commit();

    echo json_encode([
        'order_id'                  => $orderId,
        'order_number'               => $orderNumber,
        'subtotal'                   => $totals['subtotal'],
        'discount_amount'            => $discountAmount,
        'discount_name'              => $discountType['discount_name'] ?? null,
        'vat_amount'                 => $totals['vat_amount'],
        'packaging_fee_amount'       => $packagingFeeAmount,
        'total_amount'               => $grandTotal,
        'reservation_credit_applied' => round($creditToApply, 2),
        'balance_due'                => $balanceDue,
        'payment_recorded'           => $paymentRecorded,
        'inventory_warnings'         => $inventoryWarnings,
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('create_order.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong completing this order. Please try again.']);
}
