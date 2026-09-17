<?php
/**
 * customer/includes/menu_functions.php
 *
 * Read-only menu-browsing helpers, shared by menu.php and the advance-order
 * step of make_reservation.php. computeMenuItemStock() mirrors the
 * "servings this recipe can still make" math cashier/includes/
 * pos_functions.php's computeMenuItemAvailability() already does (same
 * floor-and-take-the-minimum logic against menu_item_ingredients +
 * inventory_batches), duplicated here per this codebase's per-module-copy
 * convention rather than cross-included -- but additionally excludes
 * expired batches, since a customer browsing the public menu shouldn't see
 * "in stock" backed by stock past its own expiry date.
 */

// formatServingSize() lived here -- serving size was removed from menu_items
// (2026-09-18). Both customer/menu.php and the chatbot's get_menu payload used
// it so a dish read identically in both places; neither does any more.

/** Non-expired quantity_remaining across all batches, in the ingredient's own base unit. */
function getAvailableStockForCustomer(PDO $pdo, int $inventoryItemId): float
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(quantity_remaining), 0) FROM inventory_batches
         WHERE item_id = ? AND (expiry_date IS NULL OR expiry_date >= CURDATE())'
    );
    $stmt->execute([$inventoryItemId]);
    return (float)$stmt->fetchColumn();
}

/**
 * quantity in $fromUnitId converted to $toUnitId through unit_conversions
 * (via the DB's fn_convert_quantity(), the same single source of truth for
 * conversion factors used everywhere else in the app). Null if no
 * conversion path is defined between the two units.
 */
function convertQuantityForCustomer(PDO $pdo, float $quantity, int $fromUnitId, int $toUnitId): ?float
{
    if ($fromUnitId === $toUnitId) {
        return $quantity;
    }
    try {
        $stmt = $pdo->prepare('SELECT fn_convert_quantity(?, ?, ?)');
        $stmt->execute([$quantity, $fromUnitId, $toUnitId]);
        $result = $stmt->fetchColumn();
        return $result !== false && $result !== null ? (float)$result : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * How many servings of this menu item can still be made right now, from
 * its recipe (menu_item_ingredients) vs. current non-expired stock -- the
 * minimum across every required ingredient, each floored individually.
 *
 * Null means "no recipe defined" (unconstrained -- nothing to be short
 * of, so the badge shows a plain "Available" rather than a number). 0
 * means genuinely out of stock, or a required ingredient has no
 * unit-conversion path (failed safe as out-of-stock rather than silently
 * treating an uncostable line as available).
 */
function computeMenuItemStock(PDO $pdo, int $menuItemId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT mii.inventory_item_id, mii.quantity_required, mii.recipe_unit_id, ii.base_unit_id
         FROM menu_item_ingredients mii
         JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id
         WHERE mii.menu_item_id = ?'
    );
    $stmt->execute([$menuItemId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return null;
    }

    // quantity_required is the amount one serving uses, so available/needed
    // is the servings that ingredient can cover. The dish can only make as
    // many as its scarcest ingredient allows. Same ratio
    // cashier/includes/pos_functions.php's computeMenuItemAvailability() uses.
    $minCount = null;
    foreach ($rows as $row) {
        $needed = convertQuantityForCustomer(
            $pdo,
            (float)$row['quantity_required'],
            (int)$row['recipe_unit_id'],
            (int)$row['base_unit_id']
        );
        if ($needed === null || $needed <= 0) {
            return 0;
        }

        $available = getAvailableStockForCustomer($pdo, (int)$row['inventory_item_id']);
        $possible = (int)floor($available / $needed);
        if ($minCount === null || $possible < $minCount) {
            $minCount = $possible;
        }
    }

    return $minCount;
}
