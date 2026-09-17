<?php
/**
 * owner/menu_management/includes/menu_functions.php
 *
 * Shared helpers for the Menu Management module (menu_items.php,
 * costing.php, recipe_builder.php).
 */

/** MENU-0001, mirroring generateSupplierCode() in owner/includes/supplier_functions.php. */
function generateMenuCode(PDO $db): string
{
    $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(menu_code, 6) AS UNSIGNED)) FROM menu_items WHERE menu_code LIKE 'MENU-%'");
    $max = (int)$stmt->fetchColumn();
    return 'MENU-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Maps a Food Cost %/Margin % value to one of .owner-status-pill's existing
 * color variants, banded relative to $target (pricing_settings.
 * target_food_cost_percentage, default 30%): at/under target excellent,
 * up to +8pts healthy, up to +15pts watch, beyond that high. Those offsets
 * reproduce the original fixed 30/38/45 bands when $target is the 30%
 * default, so existing colors don't shift for restaurants that never touch
 * the setting. 0% (unpriced/uncosted item) is neutral rather than
 * "excellent" -- there's nothing to celebrate about a $0 ratio.
 */
function foodCostHealthClass(float $percent, float $target = 30.0): string
{
    if ($percent <= 0) {
        return 'is-neutral';
    }
    if ($percent <= $target) {
        return 'is-success';
    }
    if ($percent <= $target + 8) {
        return 'is-active';
    }
    if ($percent <= $target + 15) {
        return 'is-warning';
    }
    return 'is-danger';
}

/**
 * Serialize rows into the [{id, label}, ...] JSON that the searchable
 * combobox widget (copied from inventory/inventory.php) reads from each
 * field's data-options attribute. Identical to inventory/includes/
 * inventory_functions.php's comboboxOptions() -- duplicated here rather
 * than shared across module directories, matching this app's established
 * per-module-copy convention.
 */
function comboboxOptions(array $rows, string $idKey, string $labelKey): string
{
    $opts = array_map(
        fn($r) => ['id' => (string)$r[$idKey], 'label' => (string)$r[$labelKey]],
        $rows
    );
    return htmlspecialchars(json_encode($opts), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate + move an uploaded menu item image into
 * owner/assets/uploads/menu/, returning the web-relative path to store in
 * menu_items.image_url (e.g. "assets/uploads/menu/6821a...f3.jpg"), or null
 * if no file was uploaded. Throws RuntimeException with a user-facing
 * message on validation failure (caller catches and surfaces via flash).
 */
function handleMenuImageUpload(): ?string
{
    if (empty($_FILES['image']['name'])) {
        return null;
    }

    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Image must be 2MB or smaller.');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Image must be a JPG, PNG, or WEBP file.');
    }

    $destDir = __DIR__ . '/../../assets/uploads/menu/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $filename = uniqid('menu_', true) . '.' . $allowed[$mime];
    $destPath = $destDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Could not save the uploaded image. Please try again.');
    }

    return 'assets/uploads/menu/' . $filename;
}

/**
 * Current FIFO cost for one inventory item -- the unit_cost of the oldest
 * batch that still has stock, matching the exact cursor order
 * sp_deduct_inventory_fifo() uses (received_date ASC, batch_id ASC). Falls
 * back to inventory_items.last_purchase_cost with is_estimate=true when no
 * batches remain (out of stock), so a recipe can still be costed/priced
 * even at zero stock rather than showing nothing.
 */
function getCurrentFifoCost(PDO $db, int $itemId): array
{
    $stmt = $db->prepare(
        "SELECT unit_cost, batch_number FROM inventory_batches
         WHERE item_id = ? AND quantity_remaining > 0
         ORDER BY received_date ASC, batch_id ASC LIMIT 1"
    );
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row !== false) {
        return ['unit_cost' => (float)$row['unit_cost'], 'is_estimate' => false, 'batch_number' => $row['batch_number']];
    }

    $fallback = $db->prepare('SELECT last_purchase_cost FROM inventory_items WHERE item_id = ?');
    $fallback->execute([$itemId]);
    $lastCost = $fallback->fetchColumn();

    return ['unit_cost' => $lastCost !== false && $lastCost !== null ? (float)$lastCost : 0.0, 'is_estimate' => true, 'batch_number' => null];
}

/**
 * quantity in $fromUnitId, converted to $toUnitId, via the DB's own
 * fn_convert_quantity() (unit_conversions table) -- reused rather than
 * reimplemented in PHP so this always agrees with what
 * sp_deduct_inventory_fifo_recipe_unit() actually deducts at checkout.
 * Returns null if no conversion path is defined (caller should treat the
 * line as un-costable rather than guessing).
 */
function convertQuantity(PDO $db, float $quantity, int $fromUnitId, int $toUnitId): ?float
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
 * Recipe Cost: Sum(ingredient qty, converted to the ingredient's base unit,
 * x its current FIFO cost). Ingredient quantities are entered per serving --
 * the amount used to make the one plate that's actually sold as a menu item
 * -- so the sum is the cost of that serving directly, with no batch split.
 * Returns ['cost' => float, 'has_uncostable_line' => bool] -- the flag is
 * set if any ingredient couldn't be unit-converted or costed, so callers can
 * warn rather than silently under-costing the recipe.
 */
function computeRecipeCost(PDO $db, int $menuItemId): array
{
    $ingredientsStmt = $db->prepare(
        'SELECT mii.inventory_item_id, mii.quantity_required, mii.recipe_unit_id, ii.base_unit_id
         FROM menu_item_ingredients mii
         JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id
         WHERE mii.menu_item_id = ?'
    );
    $ingredientsStmt->execute([$menuItemId]);

    $recipeCost = 0.0;
    $hasUncostableLine = false;

    foreach ($ingredientsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $convertedQty = convertQuantity($db, (float)$row['quantity_required'], (int)$row['recipe_unit_id'], (int)$row['base_unit_id']);
        if ($convertedQty === null) {
            $hasUncostableLine = true;
            continue;
        }
        $fifo = getCurrentFifoCost($db, (int)$row['inventory_item_id']);
        $recipeCost += $convertedQty * $fifo['unit_cost'];
    }

    return ['cost' => round($recipeCost, 2), 'has_uncostable_line' => $hasUncostableLine];
}

/**
 * Packaging Cost: Sum(packaging qty, converted to base unit, x current FIFO
 * cost). Returns 0.00 if the item has no packaging rule.
 */
function computePackagingCost(PDO $db, int $menuItemId): array
{
    $itemsStmt = $db->prepare(
        'SELECT pri.inventory_item_id, pri.quantity, pri.unit_id, ii.base_unit_id
         FROM packaging_rules pr
         JOIN packaging_rule_items pri ON pri.rule_id = pr.rule_id
         JOIN inventory_items ii ON ii.item_id = pri.inventory_item_id
         WHERE pr.menu_item_id = ?'
    );
    $itemsStmt->execute([$menuItemId]);

    $cost = 0.0;
    $hasUncostableLine = false;

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $convertedQty = convertQuantity($db, (float)$row['quantity'], (int)$row['unit_id'], (int)$row['base_unit_id']);
        if ($convertedQty === null) {
            $hasUncostableLine = true;
            continue;
        }
        $fifo = getCurrentFifoCost($db, (int)$row['inventory_item_id']);
        $cost += $convertedQty * $fifo['unit_cost'];
    }

    return ['cost' => round($cost, 2), 'has_uncostable_line' => $hasUncostableLine];
}

/**
 * Recomputes Recipe Cost + Packaging Cost fresh from live data and appends
 * one row to recipe_cost_history -- but ONLY when those costs actually
 * differ from the item's most recent history row.
 *
 * recipe_cost_history stores nothing but costs, so a row identical to the
 * one before it records no event: it says "on this date the cost was X"
 * when the previous row already said exactly that. Before this guard, every
 * "Recalculate" click appended one such row per item, which is why the
 * table reached 314 manual_recalc rows across 47 items -- the real cost
 * changes were buried in near-duplicate noise, and the auto sweep in
 * config/costing_alerts.php (which re-checks on every cron tick) would have
 * made that far worse.
 *
 * Comparison is on the DECIMAL(10,2) values the column actually stores, via
 * number_format(), so a float representation difference can never register
 * as a change the owner would never see on screen.
 *
 * @return bool true if a row was written (the cost really moved), false if
 *   it was suppressed as unchanged. Callers use this to report how many
 *   items actually changed instead of claiming a change every time.
 */
function logCostHistory(PDO $db, int $menuItemId, string $reason): bool
{
    $recipeCost    = computeRecipeCost($db, $menuItemId)['cost'];
    $packagingCost = computePackagingCost($db, $menuItemId)['cost'];

    $lastStmt = $db->prepare(
        'SELECT recipe_cost, packaging_cost FROM recipe_cost_history
         WHERE menu_item_id = ? ORDER BY recorded_at DESC, history_id DESC LIMIT 1'
    );
    $lastStmt->execute([$menuItemId]);
    $last = $lastStmt->fetch(PDO::FETCH_ASSOC);

    if ($last !== false
        && number_format((float)$last['recipe_cost'], 2, '.', '') === number_format($recipeCost, 2, '.', '')
        && number_format((float)$last['packaging_cost'], 2, '.', '') === number_format($packagingCost, 2, '.', '')
    ) {
        return false;
    }

    $db->prepare(
        'INSERT INTO recipe_cost_history (menu_item_id, recipe_cost, packaging_cost, dine_in_cost, takeout_cost, reason)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $menuItemId, $recipeCost, $packagingCost, $recipeCost, $recipeCost + $packagingCost, $reason,
    ]);

    return true;
}

/**
 * Upserts the menu_item_costing cache row other pages read (the Food Cost %
 * column on menu_items.php, the Cost Summary panel on costing.php).
 *
 * Extracted here from costing_recalculate.php's own persistCosting() so the
 * manual "Recalculate" button and the automatic FIFO sweep
 * (config/costing_alerts.php) write the exact same columns -- two copies of
 * a 13-column UPDATE would drift silently, and the whole point of the sweep
 * is that its output is indistinguishable from a human clicking Recalculate.
 *
 * $computedBy is null for the automatic sweep: menu_item_costing.computed_by
 * is nullable, and there is no logged-in user on a cron tick to attribute
 * it to. costing.php already renders a missing user as "System".
 */
function persistMenuItemCosting(PDO $db, int $menuItemId, array $c, ?int $computedBy): void
{
    $existing = $db->prepare('SELECT costing_id FROM menu_item_costing WHERE menu_item_id = ?');
    $existing->execute([$menuItemId]);

    if ($existing->fetchColumn()) {
        $db->prepare(
            'UPDATE menu_item_costing SET
                total_ingredient_cost = ?, packaging_cost = ?, dine_in_cost = ?, takeout_cost = ?,
                total_cost = ?, selling_price = ?, suggested_selling_price = NULL, vat_amount = ?,
                markup_percentage = ?, gross_profit = ?, food_cost_percentage_dine_in = ?,
                food_cost_percentage_takeout = ?, gross_margin_percentage = ?, computed_by = ?, computed_at = NOW()
             WHERE menu_item_id = ?'
        )->execute([
            $c['recipe_cost'], $c['packaging_cost'], $c['dine_in_cost'], $c['takeout_cost'],
            $c['total_cost'], $c['selling_price'], $c['vat_amount'],
            $c['markup_percentage'], $c['gross_profit'], $c['food_cost_percentage_dine_in'],
            $c['food_cost_percentage_takeout'], $c['gross_margin_percentage'], $computedBy,
            $menuItemId,
        ]);
        return;
    }

    $db->prepare(
        'INSERT INTO menu_item_costing
            (menu_item_id, total_ingredient_cost, packaging_cost, dine_in_cost, takeout_cost, total_cost,
             selling_price, vat_amount, markup_percentage, gross_profit, food_cost_percentage_dine_in,
             food_cost_percentage_takeout, gross_margin_percentage, computed_by, computed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([
        $menuItemId, $c['recipe_cost'], $c['packaging_cost'], $c['dine_in_cost'], $c['takeout_cost'], $c['total_cost'],
        $c['selling_price'], $c['vat_amount'], $c['markup_percentage'], $c['gross_profit'], $c['food_cost_percentage_dine_in'],
        $c['food_cost_percentage_takeout'], $c['gross_margin_percentage'], $computedBy,
    ]);
}

/** Round to the nearest multiple of $increment (e.g. 128.4 rounded to 5.00 -> 130.00). */
function roundToNearest(float $value, float $increment): float
{
    if ($increment <= 0) {
        return round($value, 2);
    }
    return round(round($value / $increment) * $increment, 2);
}

/** The single pricing_settings row (always id=1, seeded on creation). */
function getPricingSettings(PDO $db): array
{
    $row = $db->query('SELECT * FROM pricing_settings WHERE setting_id = 1')->fetch();
    return $row ?: [
        'pricing_method' => 'markup', 'default_markup_percentage' => 100, 'default_margin_percentage' => 50,
        'target_food_cost_percentage' => 30.00,
        'rounding_increment' => 1.00, 'packaging_fee_policy' => 'separate',
    ];
}

/**
 * Suggested Selling Price per the plan's formula. Cost basis follows the
 * active packaging_fee_policy: Takeout Cost if packaging cost must be
 * absorbed into the menu price ('included'), otherwise Recipe Cost alone
 * ('separate' bills it at checkout instead; 'none' absorbs it as a
 * business cost either way -- neither should inflate the menu price).
 * $method/$percentage let the Pricing calculator UI preview a value other
 * than the restaurant-wide default without changing pricing_settings.
 */
function computeSuggestedPrice(PDO $db, int $menuItemId, ?string $method = null, ?float $percentage = null): array
{
    $settings = getPricingSettings($db);
    $method = $method ?? $settings['pricing_method'];

    if ($percentage === null) {
        $percentage = $method === 'margin' ? (float)$settings['default_margin_percentage'] : (float)$settings['default_markup_percentage'];
    }

    $recipeCost  = computeRecipeCost($db, $menuItemId)['cost'];
    $takeoutCost = $recipeCost + computePackagingCost($db, $menuItemId)['cost'];
    $costBasis   = $settings['packaging_fee_policy'] === 'included' ? $takeoutCost : $recipeCost;

    if ($method === 'margin') {
        $percentage = min($percentage, 99.99);
        $rawSuggested = $percentage < 100 ? $costBasis / (1 - $percentage / 100) : $costBasis;
    } else {
        $rawSuggested = $costBasis * (1 + $percentage / 100);
    }

    // The markup/margin is applied to COST, so $rawSuggested is a net (VAT-exclusive)
    // price. menu_items.selling_price is the shelf price, and under an 'inclusive'
    // tax setting that INCLUDES VAT -- which is what the Update button writes and
    // what every other screen compares against. Without adding VAT back here, a
    // "100% markup" suggestion of 96.70 stored as a 96.70 shelf price is really a
    // net 86.34, i.e. a 79% markup: the setting silently under-delivers, and the
    // suggested price could not be compared to the current one at all.
    $tax = getActiveTaxSettingsForCosting($db);
    $vatExemptStmt = $db->prepare('SELECT is_vat_exempt FROM menu_items WHERE item_id = ?');
    $vatExemptStmt->execute([$menuItemId]);
    $isVatExempt = (bool)$vatExemptStmt->fetchColumn();

    $grossSuggested = $rawSuggested;
    if (!$isVatExempt && $tax['tax_type'] === 'inclusive') {
        $grossSuggested = $rawSuggested * (1 + $tax['tax_rate'] / 100);
    }

    // Rounded AFTER the VAT step so the shelf price lands on the rounding
    // increment, not some pre-tax figure that VAT then knocks off it.
    // rounding_increment is fixed at 1.00 (whole pesos) -- owner/settings/
    // pricing.php no longer offers it as a choice, so this is read from the
    // column purely so the value has one home rather than being hardcoded here
    // AND in recipe_builder.php's JS copy.
    $suggestedPrice = roundToNearest($grossSuggested, (float)$settings['rounding_increment']);

    // Net of the suggested shelf price, so the caller can show profit/margin on
    // the same VAT-exclusive footing every other costing screen uses.
    $suggestedNet = (!$isVatExempt && $tax['tax_type'] === 'inclusive')
        ? round($suggestedPrice - ($suggestedPrice * $tax['tax_rate'] / (100 + $tax['tax_rate'])), 2)
        : $suggestedPrice;

    return [
        'cost_basis'          => round($costBasis, 2),
        'method'              => $method,
        'percentage'          => $percentage,
        'suggested_price'     => $suggestedPrice,
        'suggested_net'       => $suggestedNet,
        'vat_rate'            => (float)$tax['tax_rate'],
        'vat_inclusive'       => (!$isVatExempt && $tax['tax_type'] === 'inclusive'),
    ];
}

/**
 * The tax_settings row currently in effect. Identical lookup to
 * getActiveTaxSettings() in cashier/includes/pos_functions.php --
 * duplicated here rather than shared across module directories, matching
 * this app's established per-module-copy convention (see comboboxOptions()
 * above, and this session's owner-panel.css duplication finding).
 */
function getActiveTaxSettingsForCosting(PDO $db): array
{
    $row = $db->query(
        'SELECT tax_rate, tax_type FROM tax_settings
         WHERE is_active = 1
         ORDER BY effective_date DESC, tax_setting_id DESC
         LIMIT 1'
    )->fetch();

    if (!$row) {
        return ['tax_rate' => 0.0, 'tax_type' => 'exclusive'];
    }

    $row['tax_rate'] = (float)$row['tax_rate'];
    return $row;
}

/**
 * Full costing breakdown for one menu item, per the plan's formulas
 * (section 2): Recipe/Packaging/Dine-in/Takeout cost, Net Selling Price
 * (tax-aware, follows the active tax_settings + this item's
 * is_vat_exempt), and Food Cost %/Gross Margin %/Markup %/Gross Profit
 * computed per channel. The single (non-channel-suffixed) markup_
 * percentage/gross_profit/gross_margin_percentage figures represent the
 * Dine-in channel -- the base cost before any packaging/takeout handling
 * -- consistent with Pricing's cost basis defaulting to Recipe Cost.
 * total_cost = takeout_cost (the fullest cost: recipe + packaging +
 * overhead, which stays 0 -- out of scope for this build).
 */
function computeFullCosting(PDO $db, int $menuItemId): array
{
    $itemStmt = $db->prepare('SELECT selling_price, is_vat_exempt FROM menu_items WHERE item_id = ?');
    $itemStmt->execute([$menuItemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    $sellingPrice = (float)($item['selling_price'] ?? 0);
    $isVatExempt  = !empty($item['is_vat_exempt']);

    $tax = getActiveTaxSettingsForCosting($db);
    $rate = $tax['tax_rate'];

    if ($isVatExempt) {
        $vatAmount = 0.0;
        $netSellingPrice = $sellingPrice;
    } elseif ($tax['tax_type'] === 'inclusive') {
        $vatAmount = round($sellingPrice * $rate / (100 + $rate), 2);
        $netSellingPrice = round($sellingPrice - $vatAmount, 2);
    } else {
        $netSellingPrice = $sellingPrice;
        $vatAmount = round($netSellingPrice * $rate / 100, 2);
    }

    $recipeCost    = computeRecipeCost($db, $menuItemId);
    $packagingCost = computePackagingCost($db, $menuItemId);
    $dineInCost    = $recipeCost['cost'];
    $takeoutCost   = round($recipeCost['cost'] + $packagingCost['cost'], 2);

    // Percent formulas are undefined at NetSellingPrice/Cost = 0 -- treat as
    // 0 rather than dividing by zero (an unpriced or free item just shows
    // blank ratios, not a fatal error).
    $foodCostPercent = function (float $cost) use ($netSellingPrice): float {
        return $netSellingPrice > 0 ? round($cost / $netSellingPrice * 100, 2) : 0.0;
    };
    $marginPercent = function (float $profit) use ($netSellingPrice): float {
        return $netSellingPrice > 0 ? round($profit / $netSellingPrice * 100, 2) : 0.0;
    };
    $markupPercent = function (float $profit, float $cost): float {
        return $cost > 0 ? round($profit / $cost * 100, 2) : 0.0;
    };

    $dineInProfit  = round($netSellingPrice - $dineInCost, 2);
    $takeoutProfit = round($netSellingPrice - $takeoutCost, 2);

    return [
        'selling_price'                => $sellingPrice,
        'net_selling_price'            => $netSellingPrice,
        'vat_amount'                   => $vatAmount,
        'recipe_cost'                  => $recipeCost['cost'],
        'packaging_cost'               => $packagingCost['cost'],
        'dine_in_cost'                 => $dineInCost,
        'takeout_cost'                 => $takeoutCost,
        'total_cost'                   => $takeoutCost,
        'gross_profit'                 => $dineInProfit,
        'gross_profit_takeout'         => $takeoutProfit,
        'gross_margin_percentage'      => $marginPercent($dineInProfit),
        'gross_margin_percentage_takeout' => $marginPercent($takeoutProfit),
        'markup_percentage'            => $markupPercent($dineInProfit, $dineInCost),
        'markup_percentage_takeout'    => $markupPercent($takeoutProfit, $takeoutCost),
        'food_cost_percentage_dine_in' => $foodCostPercent($dineInCost),
        'food_cost_percentage_takeout' => $foodCostPercent($takeoutCost),
        'has_uncostable_line'          => $recipeCost['has_uncostable_line'] || $packagingCost['has_uncostable_line'],
    ];
}
