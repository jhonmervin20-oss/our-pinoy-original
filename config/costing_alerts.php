<?php
/**
 * config/costing_alerts.php
 *
 * Keeps menu costing in step with inventory by itself.
 *
 * WHY THIS EXISTS: every cost on the Costing page is derived from the FIFO
 * unit cost of the oldest inventory batch that still has stock
 * (menu_functions.php's getCurrentFifoCost()). That cost moves on its own,
 * without anyone touching a recipe:
 *
 *   - a new batch is received at a different supplier price, for an item
 *     that had run out (so the new batch immediately becomes the FIFO head);
 *   - the current FIFO head is drained to zero at checkout by
 *     sp_deduct_inventory_fifo(), promoting the next batch and its price;
 *   - a batch is written off as waste or adjusted down to zero.
 *
 * Until now nothing reacted to any of that. menu_item_costing held whatever
 * the last human "Recalculate" click produced, so an owner could be reading
 * a Food Cost % computed against a price the kitchen stopped paying weeks
 * ago -- and recipe_cost_history's 'fifo_change' reason had a label on
 * costing.php but no writer anywhere in the codebase. This file is that
 * writer.
 *
 * THE CHEAP GATE: a full recost is ~20 queries per menu item, and the cron
 * ticks every 5 minutes, so recosting unconditionally would mean roughly a
 * thousand pointless queries 288 times a day. Instead three small queries
 * fingerprint everything a menu cost is derived from -- ingredient FIFO
 * costs, recipe composition, and packaging composition. If that fingerprint
 * matches the stored one, nothing that can affect any menu cost has moved and
 * the sweep returns immediately. The expensive pass only runs on a real
 * change. See computeCostingInputsFingerprint() for why the recipe and
 * packaging halves had to be added.
 *
 * Deliberately NOT hooked onto the two batch-INSERT sites (PO receiving and
 * inventory adjustments) the way notifyIfCostIncreased() is: receiving is
 * only one of the three ways a FIFO head changes, and the other two
 * (depletion at checkout, waste) have no natural insert hook to hang this
 * on. Fingerprinting the outcome catches all three with one mechanism
 * instead of three partial ones.
 */

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../owner/menu_management/includes/menu_functions.php';

/** system_settings key holding the last-seen costing-inputs fingerprint. Created on first run. */
const COSTING_FIFO_FINGERPRINT_KEY = 'last_costing_inputs_fingerprint';

/**
 * One fingerprint of everything a menu cost is computed FROM.
 *
 * Three inputs decide what a dish costs to make, and a change in any of them
 * should recost it:
 *
 *   1. the effective FIFO unit cost of every ingredient/packaging item,
 *   2. the RECIPE -- which ingredients, and how much of each,
 *   3. the PACKAGING RULE -- same, for takeout.
 *
 * Only (1) used to be hashed, and that was a real defect rather than an
 * oversimplification: editing a recipe changed the live cost but left this
 * fingerprint identical, so sweepAutoRecostMenuItems() returned 'unchanged',
 * never refreshed the snapshot and never notified anyone. The dish then sat
 * flagged "Stale" on the Costing page indefinitely -- until someone happened
 * to press Recalculate -- while the owner was told nothing. Cost-driven
 * changes cleared themselves within a cron tick; recipe-driven ones never
 * did, which is exactly why staleness looked intermittent.
 *
 * The FIFO half repeats getCurrentFifoCost()'s exact cursor --
 * quantity_remaining > 0, ORDER BY received_date ASC, batch_id ASC -- and its
 * COALESCE tail repeats the same last_purchase_cost fallback for an item with
 * no stock left, so this can never disagree with what the recost pass will
 * compute a moment later. Scoped to referenced items only: a price move on
 * something no dish is made of cannot change a menu cost.
 *
 * The recipe/packaging half hashes the quantities themselves, not just a
 * version counter, so a change made directly in the database -- or by any
 * future code path that forgets to bump `recipes.version` -- is still caught.
 */
function computeCostingInputsFingerprint(PDO $db): string
{
    $rows = $db->query(
        "SELECT ii.item_id,
                COALESCE((
                    SELECT b.unit_cost FROM inventory_batches b
                    WHERE b.item_id = ii.item_id AND b.quantity_remaining > 0
                    ORDER BY b.received_date ASC, b.batch_id ASC LIMIT 1
                ), ii.last_purchase_cost, 0) AS effective_cost
         FROM inventory_items ii
         WHERE ii.item_id IN (SELECT inventory_item_id FROM menu_item_ingredients)
            OR ii.item_id IN (SELECT inventory_item_id FROM packaging_rule_items)
         ORDER BY ii.item_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $parts = [];
    foreach ($rows as $row) {
        $parts[] = $row['item_id'] . ':' . number_format((float)$row['effective_cost'], 2, '.', '');
    }

    // Recipe composition: which ingredient, how much, in which unit, per dish.
    $recipeRows = $db->query(
        "SELECT mii.menu_item_id, mii.inventory_item_id, mii.quantity_required, mii.recipe_unit_id
         FROM menu_item_ingredients mii
         ORDER BY mii.menu_item_id, mii.inventory_item_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recipeRows as $row) {
        $parts[] = 'r' . $row['menu_item_id'] . '.' . $row['inventory_item_id']
            . ':' . number_format((float)$row['quantity_required'], 3, '.', '')
            . ':' . (int)$row['recipe_unit_id'];
    }

    // Packaging composition. Only active rules are costed, so only those are
    // hashed -- deactivating a rule changes the takeout cost and must register.
    $packRows = $db->query(
        "SELECT pr.menu_item_id, pri.inventory_item_id, pri.quantity, pri.unit_id
         FROM packaging_rule_items pri
         JOIN packaging_rules pr ON pr.rule_id = pri.rule_id
         WHERE pr.is_active = 1
         ORDER BY pr.menu_item_id, pri.inventory_item_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($packRows as $row) {
        $parts[] = 'p' . $row['menu_item_id'] . '.' . $row['inventory_item_id']
            . ':' . number_format((float)$row['quantity'], 3, '.', '')
            . ':' . (int)$row['unit_id'];
    }

    return md5(implode('|', $parts));
}

/**
 * Recosts every active menu item that has a recipe or a packaging rule,
 * writes history only where the cost genuinely moved, and tells the owner.
 *
 * Safe to call on every cron tick and every owner page load: the fingerprint
 * gate above makes the no-change path a single query, and logCostHistory()
 * independently refuses to write a history row identical to the item's
 * previous one, so nothing here can pad recipe_cost_history the way the
 * manual Recalculate button used to.
 *
 * $force skips only the fingerprint gate (for a "recheck now" style caller);
 * it never forces a history row for an unchanged cost.
 *
 * @return array{status:string, changed?:int, checked?:int}
 */
function sweepAutoRecostMenuItems(PDO $db, bool $force = false): array
{
    $fingerprint = computeCostingInputsFingerprint($db);

    $stored = $db->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = '" . COSTING_FIFO_FINGERPRINT_KEY . "'"
    )->fetchColumn();

    if (!$force && $stored !== false && $stored === $fingerprint) {
        return ['status' => 'unchanged'];
    }

    $itemIds = $db->query(
        'SELECT DISTINCT mi.item_id
         FROM menu_items mi
         WHERE mi.is_active = 1
           AND (EXISTS (SELECT 1 FROM menu_item_ingredients mii WHERE mii.menu_item_id = mi.item_id)
                OR EXISTS (SELECT 1 FROM packaging_rules pr WHERE pr.menu_item_id = mi.item_id))'
    )->fetchAll(PDO::FETCH_COLUMN);

    $changes = [];

    foreach ($itemIds as $id) {
        $itemId = (int)$id;

        $beforeStmt = $db->prepare(
            'SELECT dine_in_cost, takeout_cost, selling_price FROM menu_item_costing WHERE menu_item_id = ?'
        );
        $beforeStmt->execute([$itemId]);
        $cached = $beforeStmt->fetch(PDO::FETCH_ASSOC);
        $before = $cached === false ? null : (float)$cached['dine_in_cost'];

        $c = computeFullCosting($db, $itemId);

        // Refresh the cache row whenever it disagrees with live data at all --
        // including on selling_price, which moves without any cost changing.
        // This is free here (the costing is already computed) and it clears
        // costing.php's "Stale" badge, which compares those same fields.
        if ($cached === false
            || abs((float)$cached['dine_in_cost'] - $c['dine_in_cost']) > 0.005
            || abs((float)$cached['takeout_cost'] - $c['takeout_cost']) > 0.005
            || abs((float)$cached['selling_price'] - $c['selling_price']) > 0.005
        ) {
            persistMenuItemCosting($db, $itemId, $c, null);
        }

        // logCostHistory() is the single arbiter of "did the COST really
        // change", comparing against the last history row rather than against
        // the cache above -- the cache can be stale or absent (a
        // never-recalculated item), and trusting it would either miss a real
        // change or invent one on the item's very first sweep. A selling-price
        // edit refreshes the cache but writes no history row, which is right:
        // recipe_cost_history records what a dish costs to make, not what it
        // is sold for.
        if (!logCostHistory($db, $itemId, 'fifo_change')) {
            continue;
        }

        $nameStmt = $db->prepare('SELECT item_name FROM menu_items WHERE item_id = ?');
        $nameStmt->execute([$itemId]);

        $changes[] = [
            'item_id' => $itemId,
            'name'    => (string)$nameStmt->fetchColumn(),
            'before'  => $before,
            'after'   => (float)$c['dine_in_cost'],
            'delta'   => $before === null ? 0.0 : round((float)$c['dine_in_cost'] - $before, 2),
        ];
    }

    // Stored even when nothing changed, so an inventory movement that turns
    // out not to shift any menu cost (a batch of an ingredient used in no
    // priced dish, a same-price restock) is not re-examined on every tick
    // for the rest of the day.
    $db->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, description)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([
        COSTING_FIFO_FINGERPRINT_KEY,
        $fingerprint,
        'Fingerprint of current FIFO ingredient costs; changes trigger an automatic menu recost.',
    ]);

    if (empty($changes)) {
        return ['status' => 'no_cost_change', 'checked' => count($itemIds), 'changed' => 0];
    }

    notifyOwnerOfCostChanges($db, $changes);

    return ['status' => 'recosted', 'checked' => count($itemIds), 'changed' => count($changes)];
}

/**
 * One summary notification, not one per dish. A single restock of an
 * ingredient as common as rice or oil moves a dozen dishes at once; twelve
 * separate bell entries saying the same thing is how an owner learns to
 * ignore the bell. The deep link points at the item that moved the most, so
 * the click still lands somewhere specific on the Costing page.
 *
 * Dedup is (menu_cost_change, that item's id) over 1 DAY, matching the
 * existing notifyIfCostIncreased() convention in config/notifications.php.
 */
function notifyOwnerOfCostChanges(PDO $db, array $changes): void
{
    usort($changes, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));
    $top = $changes[0];
    $count = count($changes);

    $headline = $top['before'] === null
        ? "{$top['name']} is now costed at \u{20b1}" . number_format($top['after'], 2) . '.'
        : "{$top['name']} moved from \u{20b1}" . number_format($top['before'], 2)
            . " to \u{20b1}" . number_format($top['after'], 2)
            . ' (' . ($top['delta'] >= 0 ? '+' : '-') . "\u{20b1}" . number_format(abs($top['delta']), 2) . ').';

    // Deliberately does not name the cause. Three different things reach here
    // now -- an inventory batch price move, a recipe edit, a packaging change --
    // and the fingerprint reports THAT something changed, not WHICH. Naming one
    // of them would be right two thirds of the time at best; the Cost History
    // panel on the linked item states the actual reason.
    $message = $count === 1
        ? "This dish's cost to make has changed. {$headline}"
        : "The cost to make {$count} dishes has changed. Largest: {$headline}";

    notifyUsersByRole(
        $db, ['owner'], 'inventory', 'Menu costs updated',
        $message, 'menu_cost_change', $top['item_id'], '1 DAY'
    );

    logActivityOnce(
        $db, null, 'Menu Management', 'Auto-recalculate costing',
        "FIFO cost change recosted {$count} menu item" . ($count === 1 ? '' : 's')
            . "; largest movement on {$top['name']}",
        'menu_cost_change', $top['item_id'], '1 DAY'
    );
}
