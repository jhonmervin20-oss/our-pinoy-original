<?php
/**
 * cashier/includes/pos_functions.php
 *
 * Shared logic used by pos.php's initial render and both cashier/api/*.php
 * endpoints, so the tax/credit/inventory rules only ever live in one place.
 */

/**
 * The tax_settings row currently in effect, same active-row lookup used by
 * owner/settings/tax.php. Falls back to 0% rather than blocking checkout
 * if nothing is configured yet (fresh install), or if the owner has
 * toggled "Enable tax" off (no row has is_active = 1).
 */
function getActiveTaxSettings(PDO $db): array
{
    $row = $db->query(
        'SELECT tax_setting_id, tax_name, tax_rate, tax_type FROM tax_settings
         WHERE is_active = 1
         ORDER BY effective_date DESC, tax_setting_id DESC
         LIMIT 1'
    )->fetch();

    if (!$row) {
        return ['tax_setting_id' => null, 'tax_name' => 'VAT', 'tax_rate' => 0.0, 'tax_type' => 'exclusive'];
    }

    $row['tax_rate'] = (float)$row['tax_rate'];

    return $row;
}

/**
 * Available + active menu items, grouped by category name.
 * Unavailable items (is_available=0) are still included — the UI shows
 * them grayed out rather than hiding them; only is_active=0 (discontinued)
 * is excluded here. Each row also carries packaging_cost (for the
 * takeout Packaging Fee preview) and availability status for both
 * channels (see computeMenuItemAvailability()).
 */
function getMenuCatalog(PDO $db): array
{
    $items = $db->query(
        'SELECT mi.item_id, mi.menu_code, mi.item_name, mi.description,
                mi.selling_price, mi.is_vat_exempt, mi.is_available,
                mi.available_dine_in, mi.available_takeout, mi.image_url,
                mc.category_id, mc.category_name
         FROM menu_items mi
         LEFT JOIN menu_categories mc ON mc.category_id = mi.category_id
         WHERE mi.is_active = 1
         ORDER BY mc.category_name ASC, mi.item_name ASC'
    )->fetchAll();

    foreach ($items as &$item) {
        $item['packaging_cost'] = computePackagingCostForMenuItem($db, (int)$item['item_id']);
        $availability = computeMenuItemAvailability($db, (int)$item['item_id']);
        $item['dine_in_status'] = $availability['dine_in_status'];
        $item['takeout_status'] = $availability['takeout_status'];
        $item['dine_in_stock']  = $availability['dine_in_stock'];
        $item['takeout_stock']  = $availability['takeout_stock'];
    }
    unset($item);

    return $items;
}

/**
 * The data the POS needs to recompute "how many can I still make" LIVE in the
 * browser, as the cart is built, without another round trip per keystroke.
 *
 * Why this exists: the stock badge used to show the figure from page load, so
 * adding 60 of a 63-serving dish still showed "Stock: 63" and the cashier had
 * no way to see they were near the limit. Recomputing client-side needs the
 * same three things the server uses -- per-ingredient stock, and what one
 * serving of each menu item consumes.
 *
 * Quantities are pre-converted to each inventory item's BASE unit here, using
 * the same convertQuantityForPOS() the server-side check uses, so the browser
 * never has to know about unit conversion and the two cannot disagree.
 *
 * This is display maths only. Inventory is still deducted exactly where it
 * always was -- deductInventoryForOrderItem() / deductPackagingForOrderItem(),
 * after the order row is committed -- so nothing here touches stock.
 *
 * A line whose unit cannot be converted is marked need=null; the client treats
 * that item as unmakeable, matching computeMenuItemAvailability()'s
 * fail-safe-to-zero behaviour rather than silently ignoring the line.
 */
function getPosStockModel(PDO $db): array
{
    $recipes   = [];
    $packaging = [];
    $needed    = []; // inventory_item_id => true, for the stock lookup below

    $collect = function (string $sql, string $qtyKey, string $unitKey) use ($db, &$needed): array {
        $out = [];
        foreach ($db->query($sql)->fetchAll() as $row) {
            $menuItemId = (int)$row['menu_item_id'];
            $invId      = (int)$row['inventory_item_id'];
            $perServing = convertQuantityForPOS(
                $db,
                (float)$row[$qtyKey],
                (int)$row[$unitKey],
                (int)$row['base_unit_id']
            );
            $out[$menuItemId][] = [
                'id'   => $invId,
                'need' => ($perServing === null || $perServing <= 0) ? null : $perServing,
            ];
            $needed[$invId] = true;
        }
        return $out;
    };

    $recipes = $collect(
        'SELECT mii.menu_item_id, mii.inventory_item_id, mii.quantity_required, mii.recipe_unit_id, ii.base_unit_id
         FROM menu_item_ingredients mii
         JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id',
        'quantity_required',
        'recipe_unit_id'
    );

    $packaging = $collect(
        'SELECT pr.menu_item_id, pri.inventory_item_id, pri.quantity, pri.unit_id, ii.base_unit_id
         FROM packaging_rules pr
         JOIN packaging_rule_items pri ON pri.rule_id = pr.rule_id
         JOIN inventory_items ii ON ii.item_id = pri.inventory_item_id',
        'quantity',
        'unit_id'
    );

    $stock = [];
    foreach (array_keys($needed) as $invId) {
        $stock[$invId] = getAvailableStock($db, (int)$invId);
    }

    return ['stock' => $stock, 'recipes' => $recipes, 'packaging' => $packaging];
}

/** Total quantity remaining (in the item's own base unit) across all batches. */
function getAvailableStock(PDO $db, int $itemId): float
{
    $stmt = $db->prepare('SELECT COALESCE(SUM(quantity_remaining), 0) FROM inventory_batches WHERE item_id = ?');
    $stmt->execute([$itemId]);
    return (float)$stmt->fetchColumn();
}

/**
 * quantity in $fromUnitId converted to $toUnitId via the DB's own
 * fn_convert_quantity(). Duplicated from owner/menu_management/includes/
 * menu_functions.php's convertQuantity() -- same per-module-copy
 * convention as getActiveTaxSettings()/comboboxOptions() elsewhere.
 * Returns null if no conversion path is defined.
 */
function convertQuantityForPOS(PDO $db, float $quantity, int $fromUnitId, int $toUnitId): ?float
{
    if ($fromUnitId === $toUnitId) {
        return $quantity;
    }
    try {
        $stmt = $db->prepare('SELECT fn_convert_quantity(?, ?, ?)');
        $stmt->execute([$quantity, $fromUnitId, $toUnitId]);
        $result = $stmt->fetchColumn();
        return $result !== false && $result !== null ? (float)$result : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Packaging Cost for one menu item -- Sum(packaging qty, converted to base
 * unit, x current FIFO unit cost). 0.00 if the item has no packaging rule.
 * Duplicated from owner/menu_management's computePackagingCost(), trimmed
 * to just the float (POS doesn't need the has-uncostable-line flag; an
 * uncostable line here just contributes 0 rather than blocking checkout).
 */
function computePackagingCostForMenuItem(PDO $db, int $menuItemId): float
{
    $stmt = $db->prepare(
        'SELECT pri.inventory_item_id, pri.quantity, pri.unit_id, ii.base_unit_id
         FROM packaging_rules pr
         JOIN packaging_rule_items pri ON pri.rule_id = pr.rule_id
         JOIN inventory_items ii ON ii.item_id = pri.inventory_item_id
         WHERE pr.menu_item_id = ?'
    );
    $stmt->execute([$menuItemId]);

    $cost = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $convertedQty = convertQuantityForPOS($db, (float)$row['quantity'], (int)$row['unit_id'], (int)$row['base_unit_id']);
        if ($convertedQty === null) {
            continue;
        }
        $fifoStmt = $db->prepare(
            'SELECT unit_cost FROM inventory_batches WHERE item_id = ? AND quantity_remaining > 0
             ORDER BY received_date ASC, batch_id ASC LIMIT 1'
        );
        $fifoStmt->execute([(int)$row['inventory_item_id']]);
        $unitCost = $fifoStmt->fetchColumn();
        if ($unitCost === false) {
            $lastCostStmt = $db->prepare('SELECT last_purchase_cost FROM inventory_items WHERE item_id = ?');
            $lastCostStmt->execute([(int)$row['inventory_item_id']]);
            $unitCost = $lastCostStmt->fetchColumn() ?: 0;
        }
        $cost += $convertedQty * (float)$unitCost;
    }

    return round($cost, 2);
}

/**
 * Availability for both channels, from CURRENT stock vs. what one serving
 * (recipe) / one unit (packaging) actually needs -- the "how many could I
 * sell right now" number, floored to a whole count.
 *
 * dine_in_status reflects only the recipe (ingredients) -- packaging never
 * affects it (business rule: dine-in never deducts packaging).
 * takeout_status starts from dine_in_status (takeout still needs the
 * recipe) and additionally degrades to 'unavailable' if packaging itself
 * is out of stock, even when ingredients are fine.
 *
 * A menu item with no recipe/packaging rows yet is treated as 'available'
 * for that channel -- there's nothing to be short of.
 */
function computeMenuItemAvailability(PDO $db, int $menuItemId): array
{
    $lowStockThreshold = 5; // servings/units remaining

    // Returns the max servings/units this set of lines can currently make --
    // null means "unconstrained" (no recipe/packaging rows at all, so this
    // channel isn't limited by inventory). 0 covers both "genuinely out of
    // stock" and "a line has no unit-conversion path" (fail safe as zero
    // rather than silently treating an uncostable line as unconstrained,
    // which would let a genuinely un-makeable item show as sellable).
    // Quantities are per serving on both sides (a recipe line is what one
    // plate uses, a packaging line is what one order uses), so
    // $available/$needed is the servings that row can cover directly.
    $countFor = function (array $rows, string $qtyKey, string $unitKey) use ($db): ?int {
        if (empty($rows)) {
            return null;
        }
        $maxCount = null;
        foreach ($rows as $row) {
            $needed = convertQuantityForPOS($db, (float)$row[$qtyKey], (int)$row[$unitKey], (int)$row['base_unit_id']);
            if ($needed === null || $needed <= 0) {
                return 0;
            }
            $available = getAvailableStock($db, (int)$row['inventory_item_id']);
            $possible = (int)floor($available / $needed);
            if ($maxCount === null || $possible < $maxCount) {
                $maxCount = $possible;
            }
        }
        return $maxCount;
    };

    $statusFromCount = function (?int $count) use ($lowStockThreshold): string {
        if ($count === null) {
            return 'available'; // unconstrained
        }
        if ($count <= 0) {
            return 'unavailable';
        }
        return $count < $lowStockThreshold ? 'low_stock' : 'available';
    };

    $ingredientStmt = $db->prepare(
        'SELECT mii.inventory_item_id, mii.quantity_required, mii.recipe_unit_id, ii.base_unit_id
         FROM menu_item_ingredients mii
         JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id
         WHERE mii.menu_item_id = ?'
    );
    $ingredientStmt->execute([$menuItemId]);
    $dineInCount = $countFor($ingredientStmt->fetchAll(), 'quantity_required', 'recipe_unit_id');

    $packagingStmt = $db->prepare(
        'SELECT pri.inventory_item_id, pri.quantity, pri.unit_id, ii.base_unit_id
         FROM packaging_rules pr
         JOIN packaging_rule_items pri ON pri.rule_id = pr.rule_id
         JOIN inventory_items ii ON ii.item_id = pri.inventory_item_id
         WHERE pr.menu_item_id = ?'
    );
    $packagingStmt->execute([$menuItemId]);
    $packagingCount = $countFor($packagingStmt->fetchAll(), 'quantity', 'unit_id');

    // Takeout is capped by whichever of the two is more limiting; packaging
    // being unconstrained (null, no packaging rule) just falls back to the
    // dine-in count.
    $takeoutCount = $packagingCount === null
        ? $dineInCount
        : ($dineInCount === null ? $packagingCount : min($dineInCount, $packagingCount));

    return [
        'dine_in_status' => $statusFromCount($dineInCount),
        'takeout_status' => $statusFromCount($takeoutCount),
        'dine_in_stock'  => $dineInCount,  // null = not inventory-constrained (no recipe)
        'takeout_stock'  => $takeoutCount,
    ];
}

/** Active discount types, for the POS discount dropdown. Inactive ones are hidden -- same convention as getMenuCatalog()'s is_active filter. */
function getActiveDiscountTypes(PDO $db): array
{
    return $db->query(
        'SELECT discount_type_id, discount_name, discount_kind, discount_value, is_vat_exempt
         FROM discount_types WHERE is_active = 1 ORDER BY discount_name ASC'
    )->fetchAll();
}

/**
 * One active discount type by id, re-fetched fresh at checkout so the
 * server never trusts a client-sent percentage/amount -- same "re-price
 * server-side" rule create_order.php already applies to menu items.
 * Returns null if the id doesn't exist or has since been deactivated.
 */
function getActiveDiscountTypeById(PDO $db, int $discountTypeId): ?array
{
    $stmt = $db->prepare(
        'SELECT discount_type_id, discount_name, discount_kind, discount_value, is_vat_exempt
         FROM discount_types WHERE discount_type_id = ? AND is_active = 1'
    );
    $stmt->execute([$discountTypeId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Peso amount to deduct for the given discount type, applied to the order's
 * NET subtotal (pre-VAT, same "subtotal" the tax split already uses).
 * Percentage discounts are a straight cut of the subtotal; fixed discounts
 * are clamped to the subtotal so a discount can never push it negative.
 */
function computeDiscountAmount(float $subtotal, ?array $discountType): float
{
    if (!$discountType || $subtotal <= 0) {
        return 0.0;
    }

    if ($discountType['discount_kind'] === 'percentage') {
        $amount = $subtotal * (float)$discountType['discount_value'] / 100;
    } else {
        $amount = (float)$discountType['discount_value'];
    }

    return round(min(max($amount, 0.0), $subtotal), 2);
}

/** The single pricing_settings row. Duplicated from owner/menu_management -- same per-module-copy convention. */
function posGetPricingSettings(PDO $db): array
{
    $row = $db->query('SELECT * FROM pricing_settings WHERE setting_id = 1')->fetch();
    return $row ?: [
        'pricing_method' => 'markup', 'default_markup_percentage' => 100, 'default_margin_percentage' => 50,
        'rounding_increment' => 1.00, 'packaging_fee_policy' => 'separate',
    ];
}

/**
 * Look up one reservation by its reservation_number. Returns null on no
 * match — that's an expected, common outcome (cashiers mistype), not an
 * error condition.
 */
function findReservationByNumber(PDO $db, string $reservationNumber): ?array
{
    $stmt = $db->prepare(
        'SELECT r.*, u.first_name, u.last_name, u.email, u.phone, ts.slot_label
         FROM reservations r
         JOIN users u ON u.user_id = r.customer_id
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         WHERE r.reservation_number = ?
         LIMIT 1'
    );
    $stmt->execute([$reservationNumber]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * A reservation's pre-ordered items, joined to menu_items for display.
 * unit_price/subtotal are the LOCKED values from when the advance order
 * was placed — never re-priced against the current menu_items.selling_price.
 */
function getReservationAdvanceOrders(PDO $db, int $reservationId): array
{
    $stmt = $db->prepare(
        'SELECT rao.advance_order_id, rao.menu_item_id, rao.quantity, rao.unit_price, rao.subtotal,
                mi.item_name, mi.is_vat_exempt, mi.is_active, mi.is_available
         FROM reservation_advance_orders rao
         JOIN menu_items mi ON mi.item_id = rao.menu_item_id
         WHERE rao.reservation_id = ?
         ORDER BY rao.advance_order_id ASC'
    );
    $stmt->execute([$reservationId]);

    return $stmt->fetchAll();
}

/**
 * How much credit a reservation has left to apply at POS.
 *
 * total_paid       = everything ever confirmed paid toward this reservation
 *                     (reservation_fee / advance_order_deposit / balance_payment
 *                     rows with payment_status paid or partial — 'pending' isn't
 *                     confirmed money yet, so it doesn't count).
 * applied_at_pos    = how much of that has already been used against a prior
 *                     order for this same reservation (nothing in the schema
 *                     flags "this credit was spent," so this is derived from
 *                     orders/order_payments instead: for each prior order,
 *                     total_amount - collected is exactly the credit that
 *                     order consumed -- collected alone under-counts it,
 *                     since a prior order fully covered by credit collects
 *                     nothing and would otherwise look like no credit was
 *                     ever spent).
 * remaining         = what's left to apply now, clamped at 0.
 * already_checked_out = true if at least one order already exists for this
 *                        reservation — the caller should NOT auto re-pull
 *                        advance-order items in that case (no schema flag
 *                        prevents double-billing them otherwise).
 */
function getReservationCredit(PDO $db, int $reservationId): array
{
    $paidStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) FROM reservation_payments
         WHERE reservation_id = ? AND payment_status IN ('paid', 'partial')"
    );
    $paidStmt->execute([$reservationId]);
    $totalPaid = (float)$paidStmt->fetchColumn();

    // Voided orders are excluded from BOTH derivations below, and that exclusion
    // is load-bearing rather than cosmetic. `collected` only counts paid rows,
    // and voiding an order flips its payment to 'voided' -- so a voided order
    // left in this scan would read as "collected 0 against a total of N", i.e.
    // as if the customer's whole deposit had been spent on it. The deposit would
    // then be silently swallowed and NOT re-credited when the order is re-rung.
    // Dropping voided orders here gives the credit straight back, which is the
    // whole point of voiding, and also resets already_checked_out so the
    // advance-order items get re-pulled on the new order.
    $ordersStmt = $db->prepare(
        "SELECT o.order_id, o.order_number, o.total_amount, o.created_at,
                COALESCE(SUM(CASE WHEN op.payment_status = 'paid' THEN op.amount ELSE 0 END), 0) AS collected
         FROM orders o
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE o.reservation_id = ? AND o.order_status <> 'voided'
         GROUP BY o.order_id, o.order_number, o.total_amount, o.created_at
         ORDER BY o.created_at DESC"
    );
    $ordersStmt->execute([$reservationId]);
    $priorOrders = $ordersStmt->fetchAll();

    $appliedAtPos = 0.0;
    foreach ($priorOrders as $prior) {
        $creditAppliedToOrder = (float)$prior['total_amount'] - (float)$prior['collected'];
        $appliedAtPos += max(0.0, $creditAppliedToOrder);
    }

    return [
        'total_paid'          => round($totalPaid, 2),
        'applied_at_pos'      => round($appliedAtPos, 2),
        'remaining'           => max(0.0, round($totalPaid - $appliedAtPos, 2)),
        'prior_orders'        => $priorOrders,
        'already_checked_out' => count($priorOrders) > 0,
    ];
}

/**
 * Split a set of order lines into net/VAT/total per the active tax settings.
 *
 * $lines: array of ['line_gross' => float, 'is_vat_exempt' => bool]
 * (line_gross = quantity * unit_price for that line).
 *
 * orders.subtotal is defined here as the NET (pre-VAT) amount in both
 * tax_type branches — the only definition under which
 * total_amount = subtotal + vat_amount holds true either way, since the
 * schema itself doesn't say which convention "subtotal" should mean.
 */
function computeOrderTotals(array $lines, array $taxSettings): array
{
    $rate = (float)$taxSettings['tax_rate'];
    $inclusive = $taxSettings['tax_type'] === 'inclusive';

    $subtotal = 0.0;
    $vatAmount = 0.0;

    foreach ($lines as $line) {
        $gross  = (float)$line['line_gross'];
        $exempt = !empty($line['is_vat_exempt']);

        if ($inclusive) {
            $lineVat = $exempt ? 0.0 : round($gross * $rate / (100 + $rate), 2);
            $lineNet = round($gross - $lineVat, 2);
        } else {
            $lineNet = $gross;
            $lineVat = $exempt ? 0.0 : round($lineNet * $rate / 100, 2);
        }

        $subtotal  += $lineNet;
        $vatAmount += $lineVat;
    }

    $subtotal    = round($subtotal, 2);
    $vatAmount   = round($vatAmount, 2);
    $totalAmount = round($subtotal + $vatAmount, 2);

    return ['subtotal' => $subtotal, 'vat_amount' => $vatAmount, 'total_amount' => $totalAmount];
}

/**
 * ORD-{year}-{seq}, mirroring the reservation_number shape (RS-2026-001).
 * Callers should catch a duplicate-key (SQLSTATE 23000) on insert and
 * retry with a freshly generated number — the UNIQUE constraint on
 * orders.order_number is the real safety net, this just picks a candidate.
 */
function generateOrderNumber(PDO $db): string
{
    $prefix = 'ORD-' . date('Y') . '-';

    // ORDER BY order_number DESC sorts as a STRING, not numerically -- once
    // the counter reaches 4 digits, 'ORD-2026-1000' sorts *before*
    // 'ORD-2026-999' (character-by-character, '1' < '9'), so the old
    // implementation would keep re-suggesting the same already-taken number
    // for the rest of that year. Extracting the numeric suffix and taking
    // MAX() avoids that regardless of digit count.
    $stmt = $db->prepare(
        'SELECT MAX(CAST(SUBSTRING(order_number, ?) AS UNSIGNED)) FROM orders WHERE order_number LIKE ?'
    );
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $next = (int)$stmt->fetchColumn() + 1;

    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Lenient FIFO inventory deduction for one order_item, one ingredient at a
 * time. A failure (insufficient stock, or no batches at all — expected
 * right now since inventory hasn't been populated yet) is caught, logged,
 * and skipped — it never blocks or rolls back the sale. Returns a list of
 * human-readable warning strings (empty if everything deducted cleanly, or
 * if the menu item simply has no recipe defined).
 */
function deductInventoryForOrderItem(PDO $db, int $orderItemId, int $menuItemId, int $quantity, int $cashierId): array
{
    $warnings = [];

    // menu_item_ingredients.quantity_required is what one serving uses, so
    // the deduction for a line is simply that amount times the number of
    // servings sold. Same basis computeRecipeCost() uses to cost a single
    // serving (owner/menu_management/includes/menu_functions.php).
    $ingredients = $db->prepare(
        'SELECT inventory_item_id, quantity_required, recipe_unit_id FROM menu_item_ingredients WHERE menu_item_id = ?'
    );
    $ingredients->execute([$menuItemId]);

    foreach ($ingredients->fetchAll() as $ingredient) {
        $neededQty = (float)$ingredient['quantity_required'] * $quantity;

        try {
            $stmt = $db->prepare('CALL sp_deduct_inventory_fifo_recipe_unit(?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                (int)$ingredient['inventory_item_id'],
                $neededQty,
                (int)$ingredient['recipe_unit_id'],
                'order_item',
                $orderItemId,
                $cashierId,
            ]);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            error_log(sprintf(
                'POS inventory deduction failed: order_item_id=%d menu_item_id=%d inventory_item_id=%d qty=%s error=%s',
                $orderItemId,
                $menuItemId,
                (int)$ingredient['inventory_item_id'],
                $neededQty,
                $e->getMessage()
            ));
            $warnings[] = 'Could not deduct stock for one ingredient on order item #' . $orderItemId . '.';
        }
    }

    return $warnings;
}

/**
 * Lenient FIFO deduction of one menu item's packaging rule, for one
 * order_item's quantity -- mirrors deductInventoryForOrderItem() exactly,
 * just sourced from packaging_rule_items instead of menu_item_ingredients.
 * Called ONLY for takeout lines (business rule: dine-in never deducts
 * packaging) -- the caller is responsible for that order_type check.
 * reference_type='packaging_deduction' distinguishes these transactions
 * from ordinary ingredient deductions in the inventory ledger.
 */
function deductPackagingForOrderItem(PDO $db, int $orderItemId, int $menuItemId, int $quantity, int $cashierId): array
{
    $warnings = [];

    $packagingItems = $db->prepare(
        'SELECT pri.inventory_item_id, pri.quantity, pri.unit_id
         FROM packaging_rules pr
         JOIN packaging_rule_items pri ON pri.rule_id = pr.rule_id
         WHERE pr.menu_item_id = ?'
    );
    $packagingItems->execute([$menuItemId]);

    foreach ($packagingItems->fetchAll() as $pkg) {
        $neededQty = (float)$pkg['quantity'] * $quantity;

        try {
            $stmt = $db->prepare('CALL sp_deduct_inventory_fifo_recipe_unit(?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                (int)$pkg['inventory_item_id'],
                $neededQty,
                (int)$pkg['unit_id'],
                'packaging_deduction',
                $orderItemId,
                $cashierId,
            ]);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            error_log(sprintf(
                'POS packaging deduction failed: order_item_id=%d menu_item_id=%d inventory_item_id=%d qty=%s error=%s',
                $orderItemId,
                $menuItemId,
                (int)$pkg['inventory_item_id'],
                $neededQty,
                $e->getMessage()
            ));
            $warnings[] = 'Could not deduct packaging stock for order item #' . $orderItemId . '.';
        }
    }

    return $warnings;
}

/**
 * The payment methods this restaurant actually accepts at the register.
 * order_payments.payment_method's enum itself only ever had these two
 * values in practice -- 'paymongo_card'/'paymongo_paymaya' were narrowed
 * out of the schema entirely once confirmed unused (zero real rows).
 * Update this list if the restaurant ever actually turns on Card/Maya at
 * the counter (and widen the DB enum to match).
 */
const POS_PAYMENT_METHODS = ['cash', 'paymongo_gcash'];

function paymentMethodLabel(string $method): string
{
    return match ($method) {
        'cash'              => 'Cash',
        'paymongo_gcash'    => 'GCash',
        default             => ucfirst($method),
    };
}

/**
 * Shared query builder for cashier/orders.php's list + its pagination
 * COUNT(*) -- same "one builder, several consumers" convention as
 * orders/includes/order_functions.php's buildOrdersQuery(), trimmed to
 * this module's actual need: always scoped to ONE cashier (the viewer),
 * same self-scoping boundary remittance_report.php already enforces for
 * shifts ("every cashier only ever sees their OWN..."). No cashier_id
 * filter here for that reason -- there's nothing to filter, it's always you.
 */
function buildCashierOrdersQuery(int $cashierId, array $filters): array
{
    $sql = "
        SELECT o.order_id, o.order_number, o.order_type, o.reservation_id,
               o.total_amount, o.created_at, o.order_status, o.cashier_id,
               op.payment_method, op.payment_status,
               r.reservation_number
        FROM orders o
        LEFT JOIN order_payments op ON op.order_id = o.order_id
        LEFT JOIN reservations r ON r.reservation_id = o.reservation_id
        WHERE o.cashier_id = ?
    ";
    $params = [$cashierId];

    if (!empty($filters['date_from'])) {
        $sql .= ' AND o.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $sql .= ' AND o.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['order_type'])) {
        $sql .= ' AND o.order_type = ?';
        $params[] = $filters['order_type'];
    }
    if (!empty($filters['order_source'])) {
        $sql .= $filters['order_source'] === 'reservation'
            ? ' AND o.reservation_id IS NOT NULL'
            : ' AND o.reservation_id IS NULL';
    }
    if (!empty($filters['search'])) {
        $sql .= ' AND o.order_number LIKE ?';
        $params[] = '%' . $filters['search'] . '%';
    }

    $sql .= ' ORDER BY o.created_at DESC, o.order_id DESC';

    return [$sql, $params];
}

/**
 * Shared query builder for cashier/remittance_report.php's shift history
 * list + its pagination COUNT(*) -- same "one builder, several consumers"
 * convention as buildCashierOrdersQuery() above. Always scoped to ONE
 * cashier (the viewer), same self-scoping boundary as that function.
 * Always newest-first (cs.shift_id DESC) -- there's no column-sort UI on
 * this table, so this ordering is unconditional, not just a default.
 */
function buildCashierShiftHistoryQuery(int $cashierId, array $filters): array
{
    $sql = "
        SELECT cs.shift_id, cs.cashier_id, cs.opening_cash, cs.opened_at, cs.closed_at,
               cs.total_sales, cs.counted_cash, cs.variance, cs.status, u.first_name, u.last_name
        FROM cash_balances cs
        JOIN users u ON u.user_id = cs.cashier_id
        WHERE cs.cashier_id = ?
    ";
    $params = [$cashierId];

    if (!empty($filters['date_from'])) {
        $sql .= ' AND cs.opened_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $sql .= ' AND cs.opened_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['status'])) {
        $sql .= ' AND cs.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['search'])) {
        $sql .= ' AND CAST(cs.shift_id AS CHAR) LIKE ?';
        $params[] = '%' . $filters['search'] . '%';
    }

    $sql .= ' ORDER BY cs.shift_id DESC';

    return [$sql, $params];
}

/** The cashier's currently open shift, if any -- one open shift per cashier is enforced at shift_start.php, so LIMIT 1/ORDER BY DESC is just defensive. */
function getOpenShiftForCashier(PDO $db, int $cashierId): ?array
{
    $stmt = $db->prepare(
        "SELECT * FROM cash_balances WHERE cashier_id = ? AND status = 'open' ORDER BY shift_id DESC LIMIT 1"
    );
    $stmt->execute([$cashierId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Sales totals for one shift, computed fresh from orders/order_payments --
 * not read from cash_balances' own total_sales/expected_cash columns,
 * which stay NULL until the shift is closed (see shift_close.php). Safe to
 * call for an OPEN shift too (e.g. remittance_report.php's "my current
 * shift" view) since it never touches those columns itself.
 *
 * Only order_payments.payment_status='paid' rows count. orders.order_status
 * is deliberately NOT filtered on -- every order in this system is created
 * (and today, stays) 'open' in practice, so payment_status is the only
 * reliable "this is real, collected money" signal, matching how
 * payroll_run_functions.php treats attendance status the same way when an
 * enum value exists in the schema but isn't exercised by the real workflow
 * yet.
 */
function computeShiftTotals(PDO $db, int $shiftId, float $openingCash = 0.0): array
{
    $byMethod = [];
    foreach (POS_PAYMENT_METHODS as $method) {
        $byMethod[$method] = ['count' => 0, 'total' => 0.0];
    }

    $stmt = $db->prepare(
        "SELECT op.payment_method, COUNT(*) AS cnt, COALESCE(SUM(op.amount), 0) AS total
         FROM order_payments op
         JOIN orders o ON o.order_id = op.order_id
         WHERE o.shift_id = ? AND op.payment_status = 'paid'
         GROUP BY op.payment_method"
    );
    $stmt->execute([$shiftId]);
    foreach ($stmt->fetchAll() as $row) {
        $byMethod[$row['payment_method']] = ['count' => (int)$row['cnt'], 'total' => round((float)$row['total'], 2)];
    }

    $totalSales  = round(array_sum(array_column($byMethod, 'total')), 2);
    $totalOrders = (int)array_sum(array_column($byMethod, 'count'));
    $cashSales   = $byMethod['cash']['total'];
    $expectedCash = round($openingCash + $cashSales, 2);

    $ordersStmt = $db->prepare(
        "SELECT o.order_id, o.order_number, o.order_type, o.reservation_id, o.total_amount, o.created_at,
                op.payment_method, op.amount, op.amount_tendered
         FROM orders o
         JOIN order_payments op ON op.order_id = o.order_id AND op.payment_status = 'paid'
         WHERE o.shift_id = ?
         ORDER BY o.created_at ASC"
    );
    $ordersStmt->execute([$shiftId]);

    return [
        'by_method'     => $byMethod,
        'total_sales'   => $totalSales,
        'total_orders'  => $totalOrders,
        'cash_sales'    => $cashSales,
        'opening_cash'  => round($openingCash, 2),
        'expected_cash' => $expectedCash,
        'orders'        => $ordersStmt->fetchAll(),
    ];
}

function shiftStatusBadgeClass(string $status): string
{
    return $status === 'open' ? 'is-info' : 'is-neutral';
}

function shiftStatusLabel(string $status): string
{
    return $status === 'open' ? 'Open' : 'Closed';
}

/** "4h 37m" / "37m" between two timestamps -- $end defaults to now for an open shift. */
function shiftDurationLabel(string $openedAt, ?string $closedAt = null): string
{
    $seconds = (($closedAt !== null ? strtotime($closedAt) : time())) - strtotime($openedAt);
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
}

/**
 * The shift remittance document -- one shared template for the on-screen
 * ?print=1 preview and the ?download=1 Dompdf-generated PDF, same
 * "render once, output twice" convention as purchase_orders/includes/
 * po_functions.php's renderPurchaseOrderDocumentHtml(). $includeActions
 * toggles the on-screen-only Print/Download toolbar and viewport meta tag;
 * Dompdf never sees either (bool false when called from the download path).
 */
function renderRemittanceDocumentHtml(array $shift, array $totals, string $restaurantName, bool $includeActions = false): string
{
    $isClosed = $shift['status'] === 'closed';
    $totalSales   = $isClosed ? (float)$shift['total_sales'] : $totals['total_sales'];
    $expectedCash = $isClosed ? (float)$shift['expected_cash'] : $totals['expected_cash'];
    $countedCash  = $isClosed ? (float)$shift['counted_cash'] : null;
    $variance     = $isClosed ? (float)$shift['variance'] : null;
    $cashierName  = trim($shift['first_name'] . ' ' . $shift['last_name']);

    ob_start();
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<?php if ($includeActions): ?><meta name="viewport" content="width=device-width, initial-scale=1.0"><?php endif; ?>
<title>Shift #<?= (int)$shift['shift_id'] ?> | Remittance Report</title>
<style>
    @page{ size:A4; margin:15mm; }
    body{ font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color:#000; font-size:12px; margin:0; padding:0; background:<?= $includeActions ? '#f6f3ec' : '#fff' ?>; }
    .doc{ max-width:760px; margin:0 auto; padding:32px; background:#fff; <?= $includeActions ? 'border:1px solid #ddd; margin-top:24px;' : '' ?> }
    .doc-header{ width:100%; border-collapse:collapse; margin-bottom:14px; }
    .doc-header td{ vertical-align:top; padding:0; }
    h1{ font-size:20px; margin:0 0 4px; }
    .muted{ color:#555; }
    .divider{ border-top:1px solid #000; margin:14px 0; }
    table.doc-table{ width:100%; border-collapse:collapse; margin-top:10px; }
    table.doc-table th, table.doc-table td{ border:1px solid #000; padding:6px 8px; font-size:11px; text-align:left; }
    table.doc-table th{ background:#eee; }
    table.doc-table td.num, table.doc-table th.num{ text-align:right; }
    table.totals{ width:320px; margin-top:10px; border-collapse:collapse; }
    table.totals td{ padding:4px 0; font-size:12px; }
    table.totals td.num{ text-align:right; }
    table.totals tr.subtotal td{ font-weight:bold; border-top:1px solid #000; padding-top:6px; }
    table.totals tr.grand td{ font-weight:bold; border-top:1px solid #000; padding-top:6px; }
    .section-label{ font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:#555; font-weight:bold; margin:18px 0 4px; }
    .footer{ margin-top:24px; font-size:10px; color:#555; }
    <?php if ($includeActions): ?>
    .pdf-actions{ max-width:760px; margin:14px auto 24px; display:flex; justify-content:center; gap:10px; }
    .pdf-actions button, .pdf-actions a{ display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:8px; border:1px solid #ddd; background:#fff; color:#000; font-family:sans-serif; font-size:0.85rem; font-weight:600; cursor:pointer; text-decoration:none; }
    .pdf-actions .primary{ background:#9c7734; border-color:#9c7734; color:#fff; }
    @media print{ .pdf-actions{ display:none !important; } body{ background:#fff; } .doc{ border:none; margin-top:0; } }
    <?php endif; ?>
</style>
</head>
<body>
<?php if ($includeActions): ?>
<div class="pdf-actions">
    <button type="button" class="primary" onclick="window.print()">Print</button>
    <a href="?shift_id=<?= (int)$shift['shift_id'] ?>&download=1">Download PDF</a>
</div>
<?php endif; ?>
<div class="doc">
    <table class="doc-header">
        <tr>
            <td><h1><?= htmlspecialchars($restaurantName) ?></h1><div class="muted">Shift Remittance Report</div></td>
            <td style="text-align:right;">
                <div><strong>Shift #<?= (int)$shift['shift_id'] ?></strong></div>
                <div class="muted">Cashier: <?= htmlspecialchars($cashierName) ?></div>
                <div class="muted">Opened: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($shift['opened_at']))) ?></div>
                <?php if ($isClosed): ?><div class="muted">Closed: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($shift['closed_at']))) ?></div><?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    <?php
    // Cash and GCash sales broken out explicitly (not just implied by
    // "Total sales" minus "Expected cash") -- showing Expected Cash right
    // after Total Sales, with no visible breakdown of which payment
    // methods actually make up that total, reads as if Expected Cash
    // should equal Total Sales; GCash never touches the physical drawer,
    // so it doesn't. $totals['by_method'] is always the live figure
    // (computeShiftTotals() is called fresh regardless of shift status),
    // which is safe even for a closed shift since no order can ever
    // attach to a closed shift_id -- the historical split can't change.
    $cashSales  = $totals['by_method']['cash']['total'] ?? 0.0;
    $gcashSales = $totals['by_method']['paymongo_gcash']['total'] ?? 0.0;
    ?>
    <h2 class="section-label">Shift Summary</h2>
    <table class="totals">
        <tr><td>Petty cash fund</td><td class="num">&#8369;<?= number_format((float)$shift['opening_cash'], 2) ?></td></tr>
        <tr><td>Cash sales</td><td class="num">&#8369;<?= number_format($cashSales, 2) ?></td></tr>
        <tr><td>GCash sales</td><td class="num">&#8369;<?= number_format($gcashSales, 2) ?></td></tr>
        <tr class="subtotal"><td>Total sales (all payment methods)</td><td class="num">&#8369;<?= number_format($totalSales, 2) ?></td></tr>
    </table>

    <table class="totals" style="margin-top:16px;">
        <tr><td>Expected cash (petty cash fund + cash sales)</td><td class="num">&#8369;<?= number_format($expectedCash, 2) ?></td></tr>
        <tr><td>Cash on hand</td><td class="num"><?= $countedCash === null ? '________________' : '&#8369;' . number_format($countedCash, 2) ?></td></tr>
        <tr class="grand"><td>Variance (cash on hand − expected cash)</td><td class="num"><?= $variance === null ? '________________' : ($variance == 0 ? 'Balanced' : ($variance > 0 ? '+₱' . number_format($variance, 2) . ' over' : '−₱' . number_format(abs($variance), 2) . ' short')) ?></td></tr>
    </table>

    <table class="doc-table">
        <thead><tr><th>Payment method</th><th class="num">Orders</th><th class="num">Total</th></tr></thead>
        <tbody>
            <?php foreach ($totals['by_method'] as $method => $data): ?>
                <tr><td><?= htmlspecialchars(paymentMethodLabel($method)) ?></td><td class="num"><?= (int)$data['count'] ?></td><td class="num">&#8369;<?= number_format($data['total'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="doc-table">
        <thead><tr><th>Order #</th><th>Time</th><th>Type</th><th>Method</th><th class="num">Amount</th></tr></thead>
        <tbody>
            <?php if (empty($totals['orders'])): ?>
                <tr><td colspan="5">No orders this shift.</td></tr>
            <?php else: ?>
                <?php foreach ($totals['orders'] as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars($o['order_number']) ?></td>
                        <td><?= htmlspecialchars(date('g:i A', strtotime($o['created_at']))) ?></td>
                        <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $o['order_type']))) ?></td>
                        <td><?= htmlspecialchars(paymentMethodLabel($o['payment_method'])) ?></td>
                        <td class="num">&#8369;<?= number_format((float)$o['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (!empty($shift['closing_notes'])): ?>
        <div class="divider"></div>
        <div><strong>Closing notes:</strong> <?= nl2br(htmlspecialchars($shift['closing_notes'])) ?></div>
    <?php endif; ?>

    <div class="footer">Generated <?= date('M j, Y g:i A') ?>. This is a system-generated remittance report.</div>
</div>
</body>
</html>
    <?php
    return ob_get_clean();
}
