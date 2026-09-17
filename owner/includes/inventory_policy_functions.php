<?php
/**
 * owner/includes/inventory_policy_functions.php
 *
 * Ingredient-level demand forecasting + min-max ("order-up-to") inventory
 * policy engine. The order-up-to level is surfaced everywhere in this app
 * as "restock target" (never "max level"/"max stock") to avoid reading as
 * an overstock cap -- it's the level a triggered reorder brings you back
 * up to, not a ceiling on how much you're allowed to hold. This is the ingredient/auto-PO half of what used to live
 * in demand_forecast_functions.php's computeIngredientForecastProjections()/
 * buildPurchaseRecommendations() -- replaced rather than patched, because
 * the underlying approach changes: the old code forecast each MENU ITEM's
 * sales separately, then converted each forecast into ingredient units via
 * the BOM and summed. This translates menu-item sales into ingredient units
 * FIRST (one dense daily consumption series per ingredient, summed across
 * every recipe that uses it), then forecasts THAT series directly -- the
 * statistically correct order (and cheaper: one Prophet call per ingredient
 * instead of one per menu item).
 *
 * Classification (fast/medium/slow mover), the Prophet/trailing-average
 * forecast itself, and the reorder-level/restock-target policy math all
 * happen in the Python service (forecast_service/app.py's POST
 * /forecast_policy, backed by the forecast_service/engine/ package) -- by
 * design, Python has no database access at all; PHP owns every read and
 * write. This file's job is: build the ingredient consumption series
 * (data prep), the known-demand overlay, call that service, and
 * persist/report the result.
 *
 * demand_forecast_functions.php's own menu-item-level sales forecasting
 * (Sections 1-5 of owner/demand_forecast.php -- KPIs, Fast/Slow Moving, ABC)
 * is a different problem (predicting units of a menu item sold) and is
 * untouched by this file.
 */

require_once __DIR__ . '/prophet_client.php';
require_once __DIR__ . '/demand_forecast_functions.php'; // denseDailyMap(), convertQuantity(), businessDate*() helpers

/** Mirrors forecast_service/engine/data_prep.py's MIN_HISTORY_DAYS -- below this, don't even ask the engine. */
const INVENTORY_POLICY_MIN_HISTORY_DAYS = 14;

// ---------------------------------------------------------------------
// Data prep
// ---------------------------------------------------------------------

/**
 * Every active inventory item this engine can forecast: recipe ingredients
 * (linked via menu_item_ingredients, deducted on every paid order) AND
 * packaging (linked via packaging_rule_items, deducted only for takeout
 * orders -- see getDailyDenseIngredientConsumption()). These two BOM
 * systems are mutually exclusive by business rule (recipe_save.php/
 * packaging_rule_save.php each reject the other's category), so an item
 * showing up via one EXISTS clause never also matches the other -- the OR
 * just avoids needing a UNION/DISTINCT for what's already a disjoint set.
 */
function getActiveIngredientItems(PDO $db, ?int $categoryId = null, ?int $supplierIdFilter = null, ?string $search = null): array
{
    $sql = "SELECT ii.item_id, ii.item_name, ii.category_id, ic.category_name,
                   ii.base_unit_id, u.unit_code, u.unit_type,
                   ii.preferred_supplier_id, s.supplier_name,
                   ii.lead_time_days,
                   ii.reorder_level, ii.critical_level
            FROM inventory_items ii
            JOIN inventory_categories ic ON ic.category_id = ii.category_id
            JOIN unit_of_measures u ON u.unit_id = ii.base_unit_id
            LEFT JOIN suppliers s ON s.supplier_id = ii.preferred_supplier_id
            WHERE ii.is_active = 1
              -- Recipe ingredients only. Packaging is deliberately OUT of demand
              -- forecasting: it is not consumed by a recipe, it tracks takeout
              -- order COUNT rather than dish composition, and mixing the two put
              -- a container on the same footing as an ingredient in every
              -- forecast, policy and purchase recommendation. Packaging is
              -- reordered from its own stock levels, not from a demand model.
              AND EXISTS (SELECT 1 FROM menu_item_ingredients mii WHERE mii.inventory_item_id = ii.item_id)";
    $params = [];

    if ($categoryId !== null) {
        $sql .= " AND ii.category_id = ?";
        $params[] = $categoryId;
    }
    if ($supplierIdFilter !== null) {
        $sql .= " AND ii.preferred_supplier_id = ?";
        $params[] = $supplierIdFilter;
    }
    if ($search !== null && $search !== '') {
        $sql .= " AND ii.item_name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    $sql .= " ORDER BY ii.item_name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Null when not yet configured on this item -- can't size a policy without one. */
function resolveItemLeadTimeDays(array $item): ?int
{
    return $item['lead_time_days'] !== null && $item['lead_time_days'] !== ''
        ? (int)$item['lead_time_days']
        : null;
}

/** Optional supplier pack multiple to round an auto-order quantity up to; unset means no rounding. */
// resolveItemPackSize() and resolveItemShelfLifeDays() were removed: both
// columns were NULL on every one of the 52 ingredients, so neither ever changed
// an order quantity or capped a coverage window.

/** Optional shelf-life cap on the order-up-to review window; unset means no cap. */
/**
 * BOM factor for every menu item that uses $ingredientItemId, via either
 * BOM source (recipe or packaging -- mutually exclusive by business rule,
 * see getActiveIngredientItems()'s docblock), pre-converted to the
 * ingredient's base unit: per_unit_required_base is how much of the
 * ingredient (in base units) ONE unit of that menu item consumes.
 * Computed once per menu-item-ingredient link, not once per order row --
 * conversion is linear (weight/volume/count units only, no additive
 * offsets), so converting the per-unit ratio once and multiplying by
 * whatever quantity comes later (a real order row, a trailing average, an
 * advance-order booking) is equivalent to converting each row
 * individually, just cheaper, and gives every caller (history, the
 * known-demand overlay, contribution persistence) the identical factor.
 *
 * A menu-item-ingredient link with no conversion path is OMITTED from the
 * returned map (never fabricated) and appended to $missingConversions
 * instead -- skip-and-disclose, this file's standing convention.
 *
 * Returns [menu_item_id => ['source_type' => 'recipe'|'packaging',
 * 'per_unit_required_base' => float]].
 */
function getIngredientBomFactors(PDO $db, int $ingredientItemId, int $baseUnitId, array &$missingConversions = []): array
{
    $factors = [];

    $recipeStmt = $db->prepare(
        "SELECT mii.menu_item_id, mii.quantity_required, mii.recipe_unit_id
         FROM menu_item_ingredients mii
         JOIN recipes r ON r.menu_item_id = mii.menu_item_id AND r.is_active = 1
         WHERE mii.inventory_item_id = ?"
    );
    $recipeStmt->execute([$ingredientItemId]);
    foreach ($recipeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $converted = convertQuantity($db, (float)$row['quantity_required'], (int)$row['recipe_unit_id'], $baseUnitId);
        if ($converted === null) {
            $missingConversions[] = ['menu_item_id' => (int)$row['menu_item_id'], 'source_type' => 'recipe'];
            continue;
        }
        $factors[(int)$row['menu_item_id']] = ['source_type' => 'recipe', 'per_unit_required_base' => $converted];
    }

    $packagingStmt = $db->prepare(
        "SELECT pr.menu_item_id, pri.quantity AS qty_per_unit, pri.unit_id
         FROM packaging_rule_items pri
         JOIN packaging_rules pr ON pr.rule_id = pri.rule_id AND pr.is_active = 1
         WHERE pri.inventory_item_id = ?"
    );
    $packagingStmt->execute([$ingredientItemId]);
    foreach ($packagingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $converted = convertQuantity($db, (float)$row['qty_per_unit'], (int)$row['unit_id'], $baseUnitId);
        if ($converted === null) {
            $missingConversions[] = ['menu_item_id' => (int)$row['menu_item_id'], 'source_type' => 'packaging'];
            continue;
        }
        $factors[(int)$row['menu_item_id']] = ['source_type' => 'packaging', 'per_unit_required_base' => $converted];
    }

    return $factors;
}

/**
 * Converts one (menu_item, quantity) pair into ingredient base-unit
 * quantity for a SINGLE ingredient -- null if there's no BOM link, or a
 * unit conversion path is missing. A thin single-item convenience
 * wrapper over getIngredientBomFactors() for callers (the known-demand
 * overlay functions below) that only ever need one or two specific menu
 * items' factors at a time, not the full per-ingredient map -- recomputing
 * the map per call is negligible at that scale.
 */
function explodeMenuItemQtyToIngredientUnits(PDO $db, int $menuItemId, float $quantity, int $ingredientItemId, int $baseUnitId): ?float
{
    $missing = [];
    $factors = getIngredientBomFactors($db, $ingredientItemId, $baseUnitId, $missing);
    if (!isset($factors[$menuItemId])) {
        return null;
    }
    return $quantity * $factors[$menuItemId]['per_unit_required_base'];
}

/**
 * Dense daily consumption for one inventory item -- the correct `y`
 * series for Prophet, AND (called with a later date range, once those
 * days have closed) the correct basis for accuracy reconciliation
 * against a forecast (Fix #10 in the project plan: measuring accuracy
 * against a BOM-exploded actual, not raw inventory_transactions stock-
 * out, since the latter also carries waste/adjustments/packaging noise
 * unrelated to forecast skill -- calling this SAME function for both
 * training and reconciliation guarantees they're measured on an
 * identical basis by construction, not by convention).
 *
 * Sums TWO independent, mutually-exclusive BOM sources (an item only
 * ever matches one in practice, per getActiveIngredientItems()'s
 * docblock, but both are queried unconditionally rather than branching on
 * category so this stays correct even if that business rule ever changes):
 *
 *   - Recipe ingredients (menu_item_ingredients): deducted on every paid
 *     order regardless of dine-in/takeout.
 *   - Packaging (packaging_rule_items): deducted ONLY for takeout orders
 *     (see cashier/includes/pos_functions.php::deductPackagingForOrderItem()
 *     -- dine-in never touches packaging stock by business rule), so this
 *     series is built from takeout order_items only, matching real
 *     deduction exactly.
 *
 * Business-day attributed (Fix #3) via businessDateExpr()/businessRangeBounds()
 * -- a 00:30 sale belongs to the prior service day, matching how the
 * ingredient was actually consumed relative to the day's prep, not a
 * plain calendar split at midnight.
 *
 * Quiet days are filled with 0, never skipped (see denseDailyMap()).
 * $missingConversions (by ref) accumulates any BOM link this ingredient
 * has that couldn't be unit-converted -- visible, not silent (Fix #8).
 */
function getDailyDenseIngredientConsumption(PDO $db, int $ingredientItemId, int $baseUnitId, DateTime $start, DateTime $end, array &$missingConversions = []): array
{
    $cutoff   = getBusinessDayCutoffTime($db);
    $dateExpr = businessDateExpr('COALESCE(op.paid_at, op.created_at)', $cutoff);
    [$rangeStart, $rangeEndExclusive] = businessRangeBounds($start, $end, $cutoff);

    $factors = getIngredientBomFactors($db, $ingredientItemId, $baseUnitId, $missingConversions);
    $recipeMenuItemIds     = array_keys(array_filter($factors, fn($f) => $f['source_type'] === 'recipe'));
    $packagingMenuItemIds  = array_keys(array_filter($factors, fn($f) => $f['source_type'] === 'packaging'));

    $sparse = [];

    if (!empty($recipeMenuItemIds)) {
        $placeholders = implode(',', array_fill(0, count($recipeMenuItemIds), '?'));
        $stmt = $db->prepare(
            "SELECT {$dateExpr} AS d, oi.menu_item_id, SUM(oi.quantity) AS qty
             FROM order_items oi
             JOIN orders o ON o.order_id = oi.order_id
             JOIN order_payments op ON op.order_id = o.order_id
             WHERE oi.menu_item_id IN ({$placeholders}) AND op.payment_status = 'paid' AND oi.status != 'cancelled'
               AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) < ?
             GROUP BY d, oi.menu_item_id"
        );
        $stmt->execute([...$recipeMenuItemIds, $rangeStart, $rangeEndExclusive]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contribution = (float)$row['qty'] * $factors[(int)$row['menu_item_id']]['per_unit_required_base'];
            $sparse[$row['d']] = ($sparse[$row['d']] ?? 0.0) + $contribution;
        }
    }

    if (!empty($packagingMenuItemIds)) {
        $placeholders = implode(',', array_fill(0, count($packagingMenuItemIds), '?'));
        $stmt = $db->prepare(
            "SELECT {$dateExpr} AS d, oi.menu_item_id, SUM(oi.quantity) AS qty
             FROM order_items oi
             JOIN orders o ON o.order_id = oi.order_id
             JOIN order_payments op ON op.order_id = o.order_id
             WHERE oi.menu_item_id IN ({$placeholders}) AND op.payment_status = 'paid' AND oi.status != 'cancelled'
               AND o.order_type = 'takeout'
               AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) < ?
             GROUP BY d, oi.menu_item_id"
        );
        $stmt->execute([...$packagingMenuItemIds, $rangeStart, $rangeEndExclusive]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contribution = (float)$row['qty'] * $factors[(int)$row['menu_item_id']]['per_unit_required_base'];
            $sparse[$row['d']] = ($sparse[$row['d']] ?? 0.0) + $contribution;
        }
    }

    return denseDailyMap($sparse, $start, $end);
}

// ---------------------------------------------------------------------
// Known-demand overlay (project plan Fix #2) -- two channels, never
// blended into one. Both operate on a FUTURE date range (the forecast
// horizon, starting tomorrow), unlike getDailyDenseIngredientConsumption()
// above which is always a past/training window.
// ---------------------------------------------------------------------

/**
 * BOM-exploded reservation_advance_orders for future reservation dates --
 * a genuine floor, combined via max() with the forecast (never additive).
 * Confirmed this session: advance orders only deduct real inventory at
 * actual POS checkout (reference_type='order_item'), never in advance --
 * so this forward-looking floor and the past-looking training history
 * above never double-count the same physical deduction.
 *
 * Returns a dense day=>qty map over [$start, $end] (0 on days with no
 * advance-order commitment).
 */
function getCommittedFloorDemand(PDO $db, int $ingredientItemId, int $baseUnitId, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT r.reservation_date AS d, rao.menu_item_id, SUM(rao.quantity) AS qty
         FROM reservation_advance_orders rao
         JOIN reservations r ON r.reservation_id = rao.reservation_id
         WHERE r.reservation_date >= ? AND r.reservation_date <= ?
           AND r.status NOT IN ('cancelled', 'no_show')
         GROUP BY d, rao.menu_item_id"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $contribution = explodeMenuItemQtyToIngredientUnits($db, (int)$row['menu_item_id'], (float)$row['qty'], $ingredientItemId, $baseUnitId);
        if ($contribution === null) {
            continue; // this menu item doesn't use this ingredient, or has a conversion gap
        }
        $d = $row['d'];
        $sparse[$d] = ($sparse[$d] ?? 0.0) + $contribution;
    }
    return denseDailyMap($sparse, $start, $end);
}

// ---------------------------------------------------------------------
// Engine call + policy result
// ---------------------------------------------------------------------

/**
 * Runs the full engine for one ingredient: builds its daily consumption
 * history, the known-demand overlay, calls the classification+forecast+
 * policy service, and returns an enriched row -- or a row with
 * gated_reason explaining why no policy could be computed (never a
 * fabricated number). Shared by the dashboard (read-only live preview),
 * export/PDF, and the auto-PO sweep (which additionally persists the
 * result via persistInventoryPolicyResult()/persistIngredientDemandForecast()/
 * persistIngredientDemandContributions()).
 */
function computeInventoryPolicyForItem(PDO $db, array $item, int $windowDays, int $forecastHorizonDays): array
{
    $itemId = (int)$item['item_id'];

    $base = [
        'item_id'                  => $itemId,
        'item_name'                => $item['item_name'],
        'category_name'            => $item['category_name'] ?? null,
        'unit_code'                => $item['unit_code'],
        // Carried through so consumers of a policy row (e.g. the demand-driver
        // lookup, order sizing) can work without re-querying the item.
        'base_unit_id'             => (int)$item['base_unit_id'],
        'unit_type'                => $item['unit_type'] ?? 'count',
        'supplier_id'              => $item['preferred_supplier_id'] !== null ? (int)$item['preferred_supplier_id'] : null,
        'supplier_name'            => $item['supplier_name'] ?? null,
        'lead_time_days'           => null,
        'reorder_level'            => (float)$item['reorder_level'],
        'critical_level'           => $item['critical_level'] !== null ? (float)$item['critical_level'] : null,
        'gated_reason'             => null,
        'has_conversion_gap'       => false,
        'demand_pattern'           => null,
        'slow_reason'              => null,
        'model_used'               => null,
        'nonzero_day_ratio'        => null,
        'daily_forecast'           => null,
        'lead_time_demand_qty'     => null,
        'policy_reorder_level'     => null,
        'review_period_demand_qty' => null,
        'policy_restock_target'    => null,
        'safety_stock_qty'         => null,
        'coverage_days'            => null,
        'committed_floor_daily'    => null,
        'adjustment_delta_daily'   => null,
    ];

    $leadTimeDays = resolveItemLeadTimeDays($item);
    $base['lead_time_days'] = $leadTimeDays;
    if ($leadTimeDays === null) {
        $base['gated_reason'] = 'No lead time configured — set one on this item.';
        return $base;
    }

    if (!forecastServiceEnabled()) {
        $base['gated_reason'] = 'AI forecast policy service is disabled.';
        return $base;
    }

    // Train up to the last day that actually has sales, not to yesterday.
    // The sales-forecast path already does this (forecastTrainingEnd); this
    // one did not, and the difference is not cosmetic: with no transactions
    // recorded for the last 20 days, a plain 30-day trailing window is mostly
    // empty, so every ingredient forecasts near zero, every reorder level
    // collapses, and the sweep stops proposing purchase orders at all. The
    // page already discloses how stale the underlying data is.
    $rangeEnd   = forecastTrainingEnd($db, new DateTime('yesterday'));
    $rangeStart = (clone $rangeEnd)->modify('-' . ($windowDays - 1) . ' days');
    $missingConversions = [];
    $dailyMap = getDailyDenseIngredientConsumption($db, $itemId, (int)$item['base_unit_id'], $rangeStart, $rangeEnd, $missingConversions);

    $totalHistoryDays = count($dailyMap);
    if ($totalHistoryDays < INVENTORY_POLICY_MIN_HISTORY_DAYS) {
        $base['gated_reason'] = 'Not enough consumption history yet (need ' . INVENTORY_POLICY_MIN_HISTORY_DAYS
            . '+ days, have ' . $totalHistoryDays . ').';
        return $base;
    }

    // A total conversion block (every BOM link for this ingredient hit a
    // missing conversion path, so the whole series is an artifact of the
    // gap rather than genuine zero consumption) is a distinct, honest
    // gate -- not silently reported as "slow mover, 0 consumption."
    // A PARTIAL gap (some links convert fine) still computes normally,
    // just flagged via has_conversion_gap so it's visible, not blocking.
    if (!empty($missingConversions) && array_sum($dailyMap) <= 0.0) {
        $base['gated_reason'] = 'Consumption could not be computed — every recipe/packaging line for this ingredient has a missing unit conversion.';
        $base['has_conversion_gap'] = true;
        return $base;
    }
    if (!empty($missingConversions)) {
        $base['has_conversion_gap'] = true;
    }

    $history = [];
    foreach ($dailyMap as $date => $qty) {
        $history[] = ['date' => $date, 'quantity' => $qty];
    }

    // Known-demand overlay, over the FORECAST horizon -- day-aligned with
    // what Prophet will return (index 0 = first forecast day). Training
    // data ends at $rangeEnd (yesterday), so Prophet's own
    // _future_dates() (forecast_service/engine/forecasting.py) computes
    // its first forecast day as last_training_date + 1 = yesterday + 1 =
    // TODAY, not tomorrow -- confirmed against a live response. Using
    // 'tomorrow' here was a real one-day misalignment: today's committed
    // floor was silently dropped (queried range started one day too
    // late), every other day's overlay was applied to the wrong Prophet
    // row, and the last Prophet-forecasted day got no overlay at all
    // (array ran one entry short).
    $forecastStart = new DateTime('today');
    $forecastEnd   = (clone $forecastStart)->modify('+' . ($forecastHorizonDays - 1) . ' days');
    $committedFloorMap   = getCommittedFloorDemand($db, $itemId, (int)$item['base_unit_id'], $forecastStart, $forecastEnd);
    $committedFloorDaily  = array_values($committedFloorMap);
    $base['committed_floor_daily']  = $committedFloorDaily;

    // Holidays cover the training window AND the forecast horizon -- a holiday
    // next week is precisely what the ingredient forecast needs to know about.
    $holidays = getActiveHolidays(
        $db,
        (clone $forecastStart)->modify('-2 years'),
        (clone $forecastEnd)->modify('+1 year')
    );

    $result = callProphetPolicyService(
        $history,
        $leadTimeDays,
        $forecastHorizonDays,
        $holidays,
        $committedFloorDaily
    );
    if ($result === null) {
        // Service unreachable (or genuinely disabled) -- gated, exactly
        // like the sales-forecast side. No substitute model is ever
        // computed here; there is no fallback anywhere in this app.
        $base['gated_reason'] = 'AI forecast policy service unavailable right now.';
        return $base;
    }

    // The live schema enforces critical_level <= reorder_level
    // (chk_critical_le_reorder) -- critical_level stays manually set (the
    // forecast job never touches it, per the master spec), so a
    // forecast-derived reorder_level that comes out below an existing
    // manual critical_level must be clamped up to it, not rejected by the
    // DB. restock_target and safety_stock are then recomputed from whichever
    // reorder_level actually gets used, so "order-up-to" and "how far above
    // lead-time-demand" stay internally consistent. Applied once here so
    // every consumer (dashboard, export, PDF, the sweep's trigger check,
    // persistence) sees the same numbers.
    $reorderLevel = (float)$result['reorder_level'];
    if ($base['critical_level'] !== null) {
        $reorderLevel = max($reorderLevel, $base['critical_level']);
    }

    $base['demand_pattern']           = $result['demand_pattern'];
    $base['slow_reason']              = $result['slow_reason'] ?? null;
    $base['model_used']               = $result['model_used'];
    $base['nonzero_day_ratio']        = $result['nonzero_day_ratio'] ?? null;
    $base['daily_forecast']           = $result['daily_forecast'];
    $base['lead_time_demand_qty']     = (float)$result['lead_time_demand_qty'];
    $base['policy_reorder_level']     = $reorderLevel;
    $base['review_period_demand_qty'] = (float)$result['review_period_demand_qty'];
    $base['policy_restock_target']    = $reorderLevel + (float)$result['review_period_demand_qty'];
    $base['safety_stock_qty']         = $reorderLevel - (float)$result['lead_time_demand_qty'];
    $base['coverage_days']            = $result['coverage_days'] ?? null;
    $base['engine_version']           = $result['engine_version'] ?? null;

    return $base;
}

/** Persists a successful (non-gated) policy result to inventory_items -- the "forecast job" the schema comment refers to. Never called for a gated result: a bad/missing computation must never overwrite a good existing value. */
function persistInventoryPolicyResult(PDO $db, array $policy): void
{
    if ($policy['gated_reason'] !== null) {
        return;
    }
    $stmt = $db->prepare(
        "UPDATE inventory_items SET
            reorder_level = ?, lead_time_demand_qty = ?, safety_stock_qty = ?,
            review_period_demand_qty = ?, demand_pattern = ?
         WHERE item_id = ?"
    );
    $stmt->execute([
        round($policy['policy_reorder_level'], 3),
        round($policy['lead_time_demand_qty'], 3),
        round($policy['safety_stock_qty'], 3),
        round($policy['review_period_demand_qty'], 3),
        $policy['demand_pattern'],
        $policy['item_id'],
    ]);
}

/**
 * Persists the per-day forecast curve (including the overlay
 * breakdown) for one item under one forecast_runs run -- the durable
 * version of what computeInventoryPolicyForItem() computes live and
 * previously discarded. Written for every item a run evaluates
 * successfully (not just triggered ones), so the "why" behind ANY day's
 * number is queryable later, not just the day a reorder happened to fire.
 */
function persistIngredientDemandForecast(PDO $db, int $runId, array $policy): void
{
    if ($policy['gated_reason'] !== null || $policy['daily_forecast'] === null) {
        return;
    }

    $committedFloorDaily  = $policy['committed_floor_daily'] ?? [];
    $adjustmentDeltaDaily = $policy['adjustment_delta_daily'] ?? [];

    $stmt = $db->prepare(
        "INSERT INTO ingredient_demand_forecast
            (run_id, item_id, forecast_date, predicted_qty, yhat_lower, yhat_upper,
             committed_floor_qty, adjustment_delta_qty, overlaid_qty, model_used, demand_pattern)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            predicted_qty = VALUES(predicted_qty), yhat_lower = VALUES(yhat_lower), yhat_upper = VALUES(yhat_upper),
            committed_floor_qty = VALUES(committed_floor_qty), adjustment_delta_qty = VALUES(adjustment_delta_qty),
            overlaid_qty = VALUES(overlaid_qty), model_used = VALUES(model_used), demand_pattern = VALUES(demand_pattern)"
    );

    foreach ($policy['daily_forecast'] as $i => $day) {
        $floor = (float)($committedFloorDaily[$i] ?? 0.0);
        $delta = (float)($adjustmentDeltaDaily[$i] ?? 0.0);
        $overlaid = max(0.0, max((float)$day['yhat'], $floor) + $delta);

        $stmt->execute([
            $runId, $policy['item_id'], $day['date'],
            round((float)$day['yhat'], 3), round((float)$day['yhat_lower'], 3), round((float)$day['yhat_upper'], 3),
            round($floor, 3), round($delta, 3), round($overlaid, 3),
            $policy['model_used'], $policy['demand_pattern'],
        ]);
    }
}

/**
 * Persists the training-window BOM-explosion breakdown for one item
 * under one forecast_runs run -- "what's historically driven this
 * forecast," not a forward per-purchase-decision breakdown (see the
 * table's own schema comment). Bounded (project plan Fix #8): only the
 * last 14 days of the training window, and callers should only invoke
 * this for items whose decision this run was actionable (drafted /
 * flagged_no_supplier) -- that's the only case the "Why?"
 * panel is ever shown for, and it keeps this table's growth in the low
 * thousands of rows per week rather than tens of thousands.
 */
function persistIngredientDemandContributions(PDO $db, int $runId, int $itemId, int $baseUnitId, DateTime $trainingWindowEnd): void
{
    $contribStart = (clone $trainingWindowEnd)->modify('-13 days');

    $cutoff   = getBusinessDayCutoffTime($db);
    $dateExpr = businessDateExpr('COALESCE(op.paid_at, op.created_at)', $cutoff);
    [$rangeStart, $rangeEndExclusive] = businessRangeBounds($contribStart, $trainingWindowEnd, $cutoff);

    $missing = [];
    $factors = getIngredientBomFactors($db, $itemId, $baseUnitId, $missing);
    if (empty($factors)) {
        return;
    }

    $recipeMenuItemIds    = array_keys(array_filter($factors, fn($f) => $f['source_type'] === 'recipe'));
    $packagingMenuItemIds = array_keys(array_filter($factors, fn($f) => $f['source_type'] === 'packaging'));

    $stmt = $db->prepare(
        "INSERT INTO ingredient_demand_contributions
            (run_id, item_id, consumption_date, menu_item_id, source_type, quantity_sold, per_unit_required, contributed_qty)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity_sold = VALUES(quantity_sold), contributed_qty = VALUES(contributed_qty)"
    );

    $insertRows = function (array $menuItemIds, string $sourceType, string $extraWhere) use ($db, $dateExpr, $rangeStart, $rangeEndExclusive, $factors, $runId, $itemId, $stmt) {
        if (empty($menuItemIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($menuItemIds), '?'));
        $rowStmt = $db->prepare(
            "SELECT {$dateExpr} AS d, oi.menu_item_id, SUM(oi.quantity) AS qty
             FROM order_items oi
             JOIN orders o ON o.order_id = oi.order_id
             JOIN order_payments op ON op.order_id = o.order_id
             WHERE oi.menu_item_id IN ({$placeholders}) AND op.payment_status = 'paid' AND oi.status != 'cancelled'
               {$extraWhere}
               AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) < ?
             GROUP BY d, oi.menu_item_id"
        );
        $rowStmt->execute([...$menuItemIds, $rangeStart, $rangeEndExclusive]);
        foreach ($rowStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $menuItemId = (int)$row['menu_item_id'];
            $perUnit    = $factors[$menuItemId]['per_unit_required_base'];
            $qty        = (float)$row['qty'];
            $stmt->execute([$runId, $itemId, $row['d'], $menuItemId, $sourceType, round($qty, 3), round($perUnit, 6), round($qty * $perUnit, 3)]);
        }
    };

    $insertRows($recipeMenuItemIds, 'recipe', '');
    $insertRows($packagingMenuItemIds, 'packaging', "AND o.order_type = 'takeout'");
}

/**
 * Top-N menu items driving each given ingredient's demand, as plain names
 * ("driven by Sisig, Pork BBQ, Lechon Kawali").
 *
 * Contribution = units of that menu item sold in the window x how much of
 * this ingredient one unit consumes -- i.e. the same BOM explosion the
 * forecast itself is built from (getIngredientBomFactors(), including its
 * unit-conversion handling), just attributed back to the menu items instead
 * of summed into one series. No new table, no new math.
 *
 * Batched: one sales-quantity query for every menu item up front, then the
 * per-ingredient factor lookup. Returns [item_id => ['name', ...]].
 */
function getTopDemandDriversForIngredients(PDO $db, array $ingredients, DateTime $start, DateTime $end, int $topN = 3): array
{
    if (empty($ingredients)) {
        return [];
    }

    $cutoff = getBusinessDayCutoffTime($db);
    [$rangeStart, $rangeEndExclusive] = businessRangeBounds($start, $end, $cutoff);

    // Units sold per menu item across the window -- one query, reused for
    // every ingredient below.
    $soldStmt = $db->prepare(
        "SELECT oi.menu_item_id, mi.item_name, SUM(oi.quantity) qty
         FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         JOIN menu_items mi ON mi.item_id = oi.menu_item_id
         WHERE op.payment_status = 'paid' AND oi.status != 'cancelled'
           AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) < ?
         GROUP BY oi.menu_item_id, mi.item_name"
    );
    $soldStmt->execute([$rangeStart, $rangeEndExclusive]);
    $sold = [];
    $names = [];
    foreach ($soldStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sold[(int)$r['menu_item_id']]  = (float)$r['qty'];
        $names[(int)$r['menu_item_id']] = $r['item_name'];
    }

    $out = [];
    foreach ($ingredients as $item) {
        $itemId = (int)$item['item_id'];
        if (empty($item['base_unit_id'])) {
            continue; // caller passed a row without unit context -- skip rather than convert against unit 0
        }
        $missing = [];
        $factors = getIngredientBomFactors($db, $itemId, (int)$item['base_unit_id'], $missing);
        if (empty($factors)) {
            continue;
        }

        $contrib = [];
        foreach ($factors as $menuItemId => $f) {
            $qty = $sold[$menuItemId] ?? 0.0;
            if ($qty <= 0) {
                continue;
            }
            $contrib[$menuItemId] = $qty * $f['per_unit_required_base'];
        }
        if (empty($contrib)) {
            continue;
        }
        arsort($contrib);
        $top = array_slice($contrib, 0, $topN, true);

        $out[$itemId] = array_values(array_map(fn($mid) => $names[$mid] ?? ('#' . $mid), array_keys($top)));
    }

    return $out;
}

/**
 * The SAME report shape buildInventoryPolicyReport() returns, but read
 * from what the last completed sweep already persisted instead of
 * recomputing it.
 *
 * Why this exists: buildInventoryPolicyReport() makes one Prophet HTTP
 * call per active ingredient -- measured at 31.5s for 51 items -- and the
 * Demand Forecast page and the owner Dashboard were each paying that on
 * every single page view, re-deriving numbers the nightly sweep had
 * already computed and written to reorder_suggestions minutes earlier.
 * Reading the persisted run is ~1000x cheaper and is also the more
 * defensible story: the sweep computes, the page displays.
 *
 * Returns [] when no completed sweep exists yet, so callers can fall back
 * or show an honest empty state rather than a fabricated one. Pair with
 * getLatestPolicyRunMeta() to tell the viewer how fresh the numbers are.
 *
 * Known gap, deliberate: slow_reason is not persisted (it's a UI-layer
 * nuance, see demandPatternLabel()), so a "Building history" row reads as
 * "Slow mover" here. model_used IS persisted per run, so the model column
 * stays accurate.
 */
function getPersistedInventoryPolicyReport(PDO $db, ?int $supplierIdFilter = null, ?string $search = null): array
{
    $runId = $db->query(
        "SELECT run_id FROM forecast_runs
         WHERE run_type = 'ingredient_policy_sweep' AND status IN ('completed','partial')
         ORDER BY started_at DESC LIMIT 1"
    )->fetchColumn();
    if ($runId === false) {
        return [];
    }
    $runId = (int)$runId;

    $sql = "SELECT rs.*, ii.item_name, ii.category_id, ii.base_unit_id, ii.critical_level,
                   ii.lead_time_days, ii.preferred_supplier_id,
                   ii.reorder_level AS manual_reorder_level, ii.demand_pattern,
                   u.unit_code, u.unit_type, ic.category_name, s.supplier_name,
                   idf.model_used
            FROM reorder_suggestions rs
            JOIN inventory_items ii ON ii.item_id = rs.item_id
            JOIN unit_of_measures u ON u.unit_id = ii.base_unit_id
            LEFT JOIN inventory_categories ic ON ic.category_id = ii.category_id
            LEFT JOIN suppliers s ON s.supplier_id = ii.preferred_supplier_id
            LEFT JOIN (
                SELECT run_id, item_id, MIN(model_used) AS model_used
                FROM ingredient_demand_forecast GROUP BY run_id, item_id
            ) idf ON idf.run_id = rs.run_id AND idf.item_id = rs.item_id
            WHERE rs.run_id = ? AND ii.is_active = 1";
    $params = [$runId];
    if ($supplierIdFilter !== null) {
        $sql .= " AND ii.preferred_supplier_id = ?";
        $params[] = $supplierIdFilter;
    }
    if ($search !== null && $search !== '') {
        $sql .= " AND ii.item_name LIKE ?";
        $params[] = '%' . $search . '%';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($raw)) {
        return [];
    }

    // Covering PO numbers AND their outstanding quantities, batched -- one
    // query beats one per row.
    //
    // These are read LIVE rather than taken from the run snapshot, because a
    // PO's status changes independently of the sweep. reorder_suggestions
    // froze on_order_qty at sweep time, so cancelling (or receiving) a PO
    // afterwards left the recommendation still counting it: PO-2026-024 was
    // cancelled after run 18, and Shrimp kept reporting "Covered, incl. 15.00
    // on order" against a real on-hand of 0.793 kg and a 2.00 kg reorder
    // level. That is a stockout waiting to happen, not a cosmetic label --
    // the row said "already ordered" for an order that no longer existed.
    //
    // Only the PO side is refreshed here. Everything genuinely expensive in
    // the sweep (a Prophet call per ingredient, ~31.5s for 51 items) stays
    // persisted; this is one cheap aggregate over purchase_order_items, and
    // it uses the same status rule as getIngredientAvailableStock().
    $itemIds = array_column($raw, 'item_id');
    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    $poRefs = [];
    $liveOnOrder = [];

    // Forecast demand over the horizon, from the same run these rows came from
    // -- one grouped query, not one per row.
    $horizonDays  = (int)($db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'forecast_horizon_days'")->fetchColumn() ?: 7);
    $horizonDemand = getIngredientHorizonDemand($db, $runId, $horizonDays);
    $poStmt = $db->prepare(
        "SELECT poi.item_id, po.po_number,
                SUM(poi.quantity_ordered - poi.quantity_received) AS qty
         FROM purchase_order_items poi
         JOIN purchase_orders po ON po.po_id = poi.po_id
         WHERE poi.item_id IN ({$ph}) AND po.status NOT IN ('received','cancelled')
         GROUP BY poi.item_id, po.po_id, po.po_number"
    );
    $poStmt->execute($itemIds);
    foreach ($poStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['item_id'];
        $poRefs[$id][] = $r['po_number'];
        $liveOnOrder[$id] = ($liveOnOrder[$id] ?? 0.0) + (float)$r['qty'];
    }

    $rows = [];
    foreach ($raw as $r) {
        $itemId    = (int)$r['item_id'];
        $gated     = $r['gated_reason'];
        $isGatedDecision = in_array($r['decision'], ['skipped_gated', 'skipped_conversion_gap'], true);
        $triggered = in_array($r['decision'], ['drafted', 'flagged_no_supplier'], true);

        $reorderLevel  = $r['reorder_level'] !== null ? (float)$r['reorder_level'] : null;
        $leadTimeDemand = $r['lead_time_demand_qty'] !== null ? (float)$r['lead_time_demand_qty'] : null;
        $suggested = $r['final_purchase_qty'] !== null ? (float)$r['final_purchase_qty'] : (float)$r['final_suggested_qty'];

        // Re-derive the stock position from the LIVE on-order figure. The
        // snapshot's on_hand and the policy's reorder_level are still the
        // sweep's (both are Prophet-derived or slow-moving), but anything
        // that depends on what is actually still on order has to be
        // recomputed or a cancelled/received PO keeps masking a shortfall.
        $onHand           = (float)$r['on_hand_qty'];
        $scheduledInbound = $liveOnOrder[$itemId] ?? 0.0;
        $availableStock   = $onHand + $scheduledInbound;

        // Re-apply the sweep's OWN trigger rule (inventory_alerts.php:
        // `available_stock <= reorder_level`) to the live stock position, in
        // BOTH directions.
        //
        // An earlier version only ever turned the trigger ON, never off, and
        // that left rows contradicting themselves: the sweep saw Shrimp at
        // 0.793 kg against a 2.00 kg level, drafted PO-2026-027 for 3.785 kg,
        // and froze decision='drafted'. That PO then lifted live available
        // stock to 4.578 kg -- comfortably above the level -- but the row kept
        // reporting "At/under reorder level" and recommending 3.785 kg more.
        // The sweep's own order was the thing that fixed the shortage, so a
        // one-way re-derivation reported a solved problem as outstanding and
        // invited a duplicate purchase.
        //
        // Gated rows are exempt: their level was never computed, so there is
        // no threshold to compare against.
        $suppressed     = (bool)$r['suppressed_by_open_commitment'] && $scheduledInbound > 0;
        $effectiveLevel = $reorderLevel ?? (float)$r['manual_reorder_level'];
        $restockTarget  = $r['restock_target'] !== null ? (float)$r['restock_target'] : null;
        if (!$isGatedDecision && $effectiveLevel > 0) {
            $triggered = $availableStock <= $effectiveLevel;
        }

        $rows[] = [
            'item_id'                  => $itemId,
            'item_name'                => $r['item_name'],
            'category_name'            => $r['category_name'],
            'unit_code'                => $r['unit_code'],
            'base_unit_id'             => (int)$r['base_unit_id'],
            'unit_type'                => $r['unit_type'] ?? 'count',
            'supplier_id'              => $r['preferred_supplier_id'] !== null ? (int)$r['preferred_supplier_id'] : null,
            'supplier_name'            => $r['supplier_name'],
            'lead_time_days'           => $r['lead_time_days'] !== null ? (int)$r['lead_time_days'] : null,
            'reorder_level'            => (float)$r['manual_reorder_level'],
            'critical_level'           => $r['critical_level'] !== null ? (float)$r['critical_level'] : null,
            // Only a genuinely gated decision carries the reason forward; a
            // suppressed no_action row also has gated_reason set (naming its
            // covering PO), and treating that as "not classified" would
            // wrongly blank out its whole row.
            'gated_reason'             => $isGatedDecision ? $gated : null,
            'has_conversion_gap'       => (bool)$r['has_conversion_gap'],
            'demand_pattern'           => $r['demand_pattern'],
            'slow_reason'              => null,
            'model_used'               => $r['model_used'],
            'nonzero_day_ratio'        => null,
            'daily_forecast'           => null,
            'lead_time_demand_qty'     => $leadTimeDemand,
            'policy_reorder_level'     => $reorderLevel,
            'review_period_demand_qty' => null,
            'policy_restock_target'    => $r['restock_target'] !== null ? (float)$r['restock_target'] : null,
            'safety_stock_qty'         => ($reorderLevel !== null && $leadTimeDemand !== null) ? $reorderLevel - $leadTimeDemand : null,
            'coverage_days'            => $r['coverage_days'] !== null ? (int)$r['coverage_days'] : null,
            'committed_floor_daily'    => null,
            'horizon_demand'           => $horizonDemand[$itemId]['qty']   ?? null,
            'horizon_demand_lower'     => $horizonDemand[$itemId]['lower'] ?? null,
            'horizon_demand_upper'     => $horizonDemand[$itemId]['upper'] ?? null,
            'horizon_demand_days'      => $horizonDemand[$itemId]['days']  ?? null,
            'on_hand'                  => $onHand,
            'scheduled_inbound'        => $scheduledInbound,
            'available_stock'          => $availableStock,
            'stock_status'             => null,
            'inbound_po_refs'          => $poRefs[$itemId] ?? [],
            'triggered'                => $triggered,
            // Sized against LIVE stock, not the sweep's frozen figure, using
            // the same order-up-to rule the sweep applies
            // (restock_target - available_stock). Showing the stored quantity
            // beside a live available-stock column let the two disagree: after
            // the sweep's own PO landed, Shrimp still recommended the full
            // 3.785 kg it had already ordered.
            //
            // Falls back to the reorder level when no restock target was
            // computed, so a row re-triggered by a cancelled PO still shows a
            // real shortfall rather than a misleading 0 next to "Needs ordering".
            'suggested_qty'            => $triggered
                ? max(0.0, ($restockTarget ?? $effectiveLevel) - $availableStock)
                : null,
            'suppressed_by_open_commitment' => $suppressed,
        ];
    }

    // Same ordering buildInventoryPolicyReport() uses: needs-action first,
    // then closest to breaching its reorder level.
    usort($rows, function ($a, $b) {
        if ($a['triggered'] !== $b['triggered']) {
            return $a['triggered'] ? -1 : 1;
        }
        $aGap = $a['available_stock'] - ($a['policy_reorder_level'] ?? $a['reorder_level']);
        $bGap = $b['available_stock'] - ($b['policy_reorder_level'] ?? $b['reorder_level']);
        return $aGap <=> $bGap;
    });

    return $rows;
}

/**
 * Forecast demand per ingredient over the next $horizonDays, summed from the
 * per-day rows the sweep already stored in ingredient_demand_forecast.
 *
 * This exists because the policy table showed only the STOCK LEVELS derived
 * from the forecast -- available stock, reorder level, restock target, safety
 * stock -- and never the demand itself, while the section header advertised a
 * forecast horizon. The numbers were being computed and persisted (1,514 rows
 * for a 51-ingredient run), just never surfaced, so the table read as ordinary
 * stock management rather than as forecasting.
 *
 * Sums `overlaid_qty` -- the figure the policy engine actually consumed, after
 * the committed-order floor and any manual adjustment have been applied -- not
 * raw `predicted_qty`, so the column agrees with the levels beside it rather
 * than quietly disagreeing by the size of the overlay.
 *
 * Returns [item_id => ['qty', 'lower', 'upper', 'days', 'model']].
 */
function getIngredientHorizonDemand(PDO $db, int $runId, int $horizonDays): array
{
    $stmt = $db->prepare(
        "SELECT item_id,
                SUM(overlaid_qty) AS qty,
                SUM(yhat_lower)   AS lower_qty,
                SUM(yhat_upper)   AS upper_qty,
                COUNT(*)          AS days,
                MIN(model_used)   AS model
         FROM ingredient_demand_forecast
         WHERE run_id = ? AND forecast_date < DATE_ADD(CURDATE(), INTERVAL ? DAY)
         GROUP BY item_id"
    );
    $stmt->execute([$runId, max(1, $horizonDays)]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['item_id']] = [
            'qty'   => (float)$r['qty'],
            'lower' => $r['lower_qty'] !== null ? (float)$r['lower_qty'] : null,
            'upper' => $r['upper_qty'] !== null ? (float)$r['upper_qty'] : null,
            'days'  => (int)$r['days'],
            'model' => $r['model'],
        ];
    }
    return $out;
}

/**
 * The actual first/last dates the horizon-demand column is summing, so the
 * section can name its forecast period instead of only saying "over N days".
 *
 * Read from the stored rows rather than derived from today + horizon: the two
 * are not always the same window. The sweep forecasts from the day after its
 * own training cutoff, which is not necessarily tomorrow, so quoting a
 * computed range risks labelling the column with dates it does not contain.
 *
 * Returns ['start' => 'Y-m-d', 'end' => 'Y-m-d', 'days' => int] or null.
 */
function getIngredientHorizonPeriod(PDO $db, int $runId, int $horizonDays): ?array
{
    $stmt = $db->prepare(
        "SELECT MIN(forecast_date) AS start_date, MAX(forecast_date) AS end_date,
                COUNT(DISTINCT forecast_date) AS days
         FROM ingredient_demand_forecast
         WHERE run_id = ? AND forecast_date < DATE_ADD(CURDATE(), INTERVAL ? DAY)"
    );
    $stmt->execute([$runId, max(1, $horizonDays)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['start_date'] === null) {
        return null;
    }
    return ['start' => $row['start_date'], 'end' => $row['end_date'], 'days' => (int)$row['days']];
}

/** When the displayed policy numbers were computed, so the UI can say so instead of implying they're live. */
function getLatestPolicyRunMeta(PDO $db): ?array
{
    $row = $db->query(
        "SELECT run_id, status, started_at, finished_at, engine_version
         FROM forecast_runs
         WHERE run_type = 'ingredient_policy_sweep' AND status IN ('completed','partial')
         ORDER BY started_at DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ---------------------------------------------------------------------
// Stock position + trigger sizing
// ---------------------------------------------------------------------

/**
 * on_hand (from the existing inventory_stock_status view) + scheduled_inbound
 * -- the min-max policy's "available_stock". Named getIngredientAvailableStock()
 * (not getAvailableStock()) -- that name already exists in
 * cashier/includes/pos_functions.php for a different (float-returning,
 * POS-context) purpose; config/inventory_alerts.php requires both files
 * transitively, so a plain name would collide.
 *
 * scheduled_inbound nets from TWO sources, with no time window (fixed
 * from an earlier draft that only netted 'ordered'/'partially_received'
 * POs and left a real double-order hole -- a freshly auto-created
 * 'draft' PO went uncounted, so the only
 * thing preventing a second PO for the same shortfall was the sweep's
 * 24h throttle):
 *   - Open purchase orders: any status except 'received'/'cancelled'
 *     counts as coming (draft, pending_approval, approved, ordered,
 *     partially_received all represent a real, already-accounted-for
 *     commitment once this ran once, per config/inventory_alerts.php's
 *     idempotent sweep).
 * Also returns which specific PO numbers were summed, so a
 * caller can name them in an audit trail (e.g. reorder_suggestions'
 * gated_reason when a reorder is suppressed by one of them).
 */
function getIngredientAvailableStock(PDO $db, int $itemId): array
{
    $stockStmt = $db->prepare("SELECT current_stock, stock_status FROM inventory_stock_status WHERE item_id = ?");
    $stockStmt->execute([$itemId]);
    $stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC) ?: ['current_stock' => 0.0, 'stock_status' => 'ok'];
    $onHand = (float)$stockRow['current_stock'];

    $poStmt = $db->prepare(
        "SELECT po.po_number, SUM(poi.quantity_ordered - poi.quantity_received) AS qty
         FROM purchase_order_items poi
         JOIN purchase_orders po ON po.po_id = poi.po_id
         WHERE poi.item_id = ? AND po.status NOT IN ('received', 'cancelled')
         GROUP BY po.po_id, po.po_number"
    );
    $poStmt->execute([$itemId]);
    $poRows = $poStmt->fetchAll(PDO::FETCH_ASSOC);

    $scheduledInbound = (float)array_sum(array_column($poRows, 'qty'));

    return [
        'on_hand'              => $onHand,
        'stock_status'         => $stockRow['stock_status'],
        'scheduled_inbound'    => $scheduledInbound,
        'available_stock'      => $onHand + $scheduledInbound,
        'inbound_po_refs'      => array_column($poRows, 'po_number'),
    ];
}

/**
 * Order-up-to sizing: order EXACTLY the forecast shortfall, expressed in a
 * quantity the unit can actually be bought in.
 *
 * Supplier minimum/maximum order quantities were removed deliberately.
 * Measured on a real sweep, min_order_qty was binding on 23 of 29 drafted
 * lines (79%) -- Corn 2.96 -> 5.00 kg, Sago Pearls 2.90 -> 5.00 -- so most
 * "forecast-driven" quantities were really supplier-minimum-driven, which
 * contradicts what this pipeline claims to do. max_order_qty was binding on
 * 0 of 29 and could only ever truncate an order BELOW the restock target,
 * i.e. defeat the very policy that computed it.
 *
 * Rounding is unit-aware rather than blanket ceil(), via
 * unit_of_measures.unit_type:
 *   - count  (pcs)     -> ceil() to a whole unit; you cannot buy 33.27 bananas.
 *   - weight/volume    -> keep 3 decimals; 3.76 kg is a real orderable
 *                         quantity, and purchase_order_items.quantity_ordered
 *                         is DECIMAL(10,3), so nothing is lost storing it.
 * Blanket ceil() would have re-introduced a smaller version of the same
 * over-ordering on the 42 of 52 items measured in kg or L.
 *
 * There is deliberately no pack-size stage. The pack_size column was NULL on
 * all 52 items -- it never rounded a single order -- so the ordered quantity is
 * the forecast shortfall itself, rounded only to a physically orderable amount.
 *
 * Returns float, not int: callers persist into DECIMAL columns.
 */
function sizeAutoOrderQuantity(float $rawQty, string $unitType = 'count'): float
{
    $qty = max(0.0, $rawQty);
    if ($qty <= 0.0) {
        return 0.0;
    }

    return $unitType === 'count' ? (float)ceil($qty) : round($qty, 3);
}

/**
 * Early-warning day-walk: projected_stock(t) = available_stock -
 * cumsum(yhat[1..t]) for t = 1..horizonDays; returns the first day the
 * projection would breach the (already forecast-derived) reorder level, or
 * null if no breach is projected within the horizon. Independent of
 * whether today's trigger is already true.
 */
function projectShortageBreachDay(float $availableStock, ?array $dailyForecast, float $reorderLevel, int $horizonDays): ?int
{
    if ($dailyForecast === null) {
        return null;
    }
    $cumulative = 0.0;
    foreach ($dailyForecast as $day) {
        $dayNumber = (int)$day['day'];
        if ($dayNumber > $horizonDays) {
            break;
        }
        $cumulative += (float)$day['yhat'];
        if ($availableStock - $cumulative <= $reorderLevel) {
            return $dayNumber;
        }
    }
    return null;
}

// ---------------------------------------------------------------------
// Report builder (dashboard / export / PDF) -- read-only, does not persist
// ---------------------------------------------------------------------

/**
 * One row per active ingredient: policy numbers (live-computed, not
 * necessarily identical to what's currently stored on inventory_items if
 * the sweep hasn't run recently -- see inventory_items.updated_at for when
 * it last did), stock position, trigger status, and suggested order qty.
 * Sorted triggered-first, then by how far below reorder level.
 */
function buildInventoryPolicyReport(PDO $db, int $windowDays, int $forecastHorizonDays, ?int $categoryId = null, ?int $supplierIdFilter = null, ?string $search = null): array
{
    $items = getActiveIngredientItems($db, $categoryId, $supplierIdFilter, $search);

    $rows = [];
    foreach ($items as $item) {
        $policy = computeInventoryPolicyForItem($db, $item, $windowDays, $forecastHorizonDays);
        $stock  = getIngredientAvailableStock($db, (int)$item['item_id']);

        $policy['on_hand']           = $stock['on_hand'];
        $policy['stock_status']      = $stock['stock_status'];
        $policy['scheduled_inbound'] = $stock['scheduled_inbound'];
        $policy['available_stock']   = $stock['available_stock'];
        $policy['inbound_po_refs']         = $stock['inbound_po_refs'];

        $effectiveReorderLevel  = $policy['policy_reorder_level'] ?? $policy['reorder_level'];
        $effectiveRestockTarget = $policy['policy_restock_target'];

        $policy['triggered']     = $policy['gated_reason'] === null && $stock['available_stock'] <= $effectiveReorderLevel;
        $policy['suggested_qty'] = null;
        if ($policy['triggered'] && $effectiveRestockTarget !== null) {
            $rawQty = max(0.0, $effectiveRestockTarget - $stock['available_stock']);
            $policy['suggested_qty'] = sizeAutoOrderQuantity($rawQty, $policy['unit_type']);
        }

        // Suppressed reordering (project plan Fix #9): on-hand alone
        // would already trigger, but an open commitment covers the gap.
        // Still correctly "no new action needed" -- but must read
        // differently from a genuinely healthy item, not identically.
        $policy['suppressed_by_open_commitment'] = false;
        if ($policy['gated_reason'] === null && !$policy['triggered'] && $stock['on_hand'] <= $effectiveReorderLevel) {
            $policy['suppressed_by_open_commitment'] = true;
        }

        $rows[] = $policy;
    }

    usort($rows, function ($a, $b) {
        if ($a['triggered'] !== $b['triggered']) {
            return $a['triggered'] ? -1 : 1;
        }
        $aGap = $a['available_stock'] - ($a['policy_reorder_level'] ?? $a['reorder_level']);
        $bGap = $b['available_stock'] - ($b['policy_reorder_level'] ?? $b['reorder_level']);
        return $aGap <=> $bGap;
    });

    return $rows;
}

function demandPatternBadgeClass(?string $pattern): string
{
    return match ($pattern) {
        'fast'   => 'is-success',
        'medium' => 'is-warning',
        'slow'   => 'is-neutral',
        default  => 'is-neutral',
    };
}

/**
 * $slowReason distinguishes a genuinely sparse-selling item ("slow mover")
 * from one that's simply too new to trust Prophet's fit yet ("Building
 * history") -- both route through the same trailing-average model
 * (demand_pattern='slow'), but they're different facts and shouldn't read
 * the same way to an owner deciding whether to worry about a mover label.
 */
function demandPatternLabel(?string $pattern, ?string $slowReason = null): string
{
    if ($pattern === 'slow' && $slowReason === 'insufficient_history') {
        return 'Building history';
    }
    return match ($pattern) {
        'fast'   => 'Fast mover',
        'medium' => 'Medium mover',
        'slow'   => 'Slow mover',
        default  => 'Not yet classified',
    };
}

function modelUsedLabel(?string $modelUsed): string
{
    return match ($modelUsed) {
        'prophet'          => 'Prophet',
        'trailing_average' => 'Trailing average',
        default            => '—',
    };
}

// ---------------------------------------------------------------------
// Ingredient-domain accuracy (project plan Phase 6 / Fix #10) --
// persisted counterpart to demand_forecast_functions.php's
// computeForecastAccuracyWape(), for the forecast_accuracy table.
// ---------------------------------------------------------------------

/**
 * Ingredient-domain counterpart to computeForecastAccuracyWape() --
 * reconciles ingredient_demand_forecast's persisted per-day curve against
 * REALIZED consumption, BOM-exploded from actual order_items via the same
 * getDailyDenseIngredientConsumption() the training series itself uses
 * (project plan Fix #10 -- deliberately never inventory_transactions,
 * which mixes in wastage/manual-adjustment noise unrelated to forecast
 * skill; see that function's own docblock).
 *
 * Grouped by lag_days (days between the sweep run that produced a given
 * forecast_date's row and that forecast_date itself): the sweep re-forecasts
 * every open day of the horizon on every daily run, so the same
 * forecast_date naturally accumulates one data point per lag as more daily
 * sweeps run over time -- a 1-day-ahead forecast and a 7-day-ahead forecast
 * have genuinely different accuracy profiles, and averaging them together
 * would hide that. Reconciliation is scored against overlaid_qty (the
 * actual decision-driving number, including the known-demand overlay);
 * interval coverage is scored against the raw Prophet interval
 * (yhat_lower/yhat_upper, pre-overlay) -- "did the model's stated
 * uncertainty capture reality" is a question about the model, not the
 * overlay on top of it.
 *
 * Same min-3-reconciled-points gate as computeForecastAccuracyWape(), for
 * the same reason: an "accuracy" built from 1-2 points isn't one yet.
 * Returns one array per lag that cleared the gate -- empty if none did
 * (the true, expected state until enough daily sweeps have accumulated
 * and their forecast_dates have closed).
 */
function computeIngredientForecastAccuracyByLag(PDO $db, int $itemId, int $baseUnitId): array
{
    // Only score dates for which ground truth actually exists. A forecast for
    // a day the restaurant recorded no sales at all is not a wrong forecast --
    // there is simply nothing to compare it against, and counting those days
    // as "predicted N, actual 0" was inflating every stored WAPE into the
    // thousands. The live forecast already clamps its training window the
    // same way (forecastTrainingEnd); reconciliation has to match it.
    $lastData = getLastSalesDataDate($db);
    if ($lastData === null) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT idf.forecast_date, idf.overlaid_qty, idf.yhat_lower, idf.yhat_upper,
                DATEDIFF(idf.forecast_date, DATE(fr.started_at)) AS lag_days
         FROM ingredient_demand_forecast idf
         JOIN forecast_runs fr ON fr.run_id = idf.run_id
         WHERE idf.item_id = ? AND idf.forecast_date < CURDATE()
           AND idf.forecast_date <= ?
         ORDER BY idf.forecast_date ASC"
    );
    $stmt->execute([$itemId, $lastData->format('Y-m-d')]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return [];
    }

    $dates = array_column($rows, 'forecast_date');
    $earliestDate = new DateTime(min($dates));
    $latestDate   = new DateTime(max($dates));
    $lookbackStart = (clone $earliestDate)->modify('-8 weeks');

    $missing = [];
    $realized = getDailyDenseIngredientConsumption($db, $itemId, $baseUnitId, $lookbackStart, $latestDate, $missing);

    $byLag = [];
    foreach ($rows as $row) {
        $byLag[(int)$row['lag_days']][] = $row;
    }

    $results = [];
    foreach ($byLag as $lag => $points) {
        $totalActual        = 0.0;
        $absErrorSum         = 0.0;
        $signedErrorSum      = 0.0;
        $baselineAbsErrorSum = 0.0;
        $coveredCount        = 0;
        $coverableCount      = 0;

        foreach ($points as $point) {
            $actual    = (float)($realized[$point['forecast_date']] ?? 0.0);
            $predicted = (float)$point['overlaid_qty'];
            $totalActual    += $actual;
            $absErrorSum    += abs($predicted - $actual);
            $signedErrorSum += ($predicted - $actual);

            $forecastDate    = new DateTime($point['forecast_date']);
            $historyUpToDate = array_filter($realized, fn($d) => $d < $forecastDate->format('Y-m-d'), ARRAY_FILTER_USE_KEY);
            $baseline        = seasonalNaiveBaseline($historyUpToDate, $forecastDate);
            $baselineAbsErrorSum += abs($baseline - $actual);

            if ($point['yhat_lower'] !== null && $point['yhat_upper'] !== null) {
                $coverableCount++;
                if ($actual >= (float)$point['yhat_lower'] && $actual <= (float)$point['yhat_upper']) {
                    $coveredCount++;
                }
            }
        }

        if (count($points) < 3 || $totalActual <= 0) {
            continue; // not enough reconciled data yet at this lag
        }

        $wape         = ($absErrorSum / $totalActual) * 100;
        $baselineWape = ($baselineAbsErrorSum / $totalActual) * 100;
        $pointDates   = array_column($points, 'forecast_date');

        $results[] = [
            'lag_days'          => $lag,
            'reconciled_count'  => count($points),
            // Absolute error in base units, carried beside every percentage.
            // WAPE divides by the actual, so an ingredient consuming 0.05 kg a
            // day produces a four-figure percentage from a rounding-sized miss.
            // The MAE says "out by 0.04 kg", which is the honest reading.
            'mae'               => $absErrorSum / max(1, count($points)),
            'total_actual'      => $totalActual,
            'abs_error_sum'     => $absErrorSum,
            'wape_pct'          => $wape,
            'baseline_wape_pct' => $baselineWape,
            'beats_baseline'    => $wape < $baselineWape,
            'coverage_pct'      => $coverableCount > 0 ? ($coveredCount / $coverableCount) * 100 : null,
            'bias_pct'          => ($signedErrorSum / $totalActual) * 100,
            'period_start'      => min($pointDates),
            'period_end'        => max($pointDates),
        ];
    }

    usort($results, fn($a, $b) => $a['lag_days'] <=> $b['lag_days']);
    return $results;
}

/**
 * Accuracy over a REPLENISHMENT WINDOW, scored against a single forecast run.
 *
 * Two things make a naive version of this wrong, and both were hit while
 * building it:
 *
 *  1. ingredient_demand_forecast keeps EVERY run's prediction, so the same
 *     date carries up to 8 rows per item. Summing them all inflated one
 *     item's 30-day total to 2420 against an actual of 43.
 *  2. Runs that finished `partial` (the AI service dropped out midway)
 *     covered only some items, and no run covers dates before the sweep that
 *     produced it. Summing forecasts over a fixed calendar window therefore
 *     compares an INCOMPLETE forecast against COMPLETE actuals, which reads
 *     as a large under-forecast that the model never actually made.
 *
 * So: pick one completed run made at or before the window opens -- a genuine
 * ahead-of-time forecast, which is also how purchasing uses it, ordering at
 * the start of a cycle -- and score it only over the item/date pairs it
 * actually predicted. Actuals are summed over exactly those same pairs.
 *
 * Returns null when no completed run predates the window, which is honest:
 * there is nothing to score, rather than a flattering partial number.
 */
function computeWindowForecastAccuracy(PDO $db, int $windowDays = 7): ?array
{
    // Score the most recent CLOSED window -- one that has fully elapsed, so
    // the actuals are complete. An open window would score the model against
    // days that have not happened yet.
    $end   = new DateTime('yesterday');
    $start = (clone $end)->modify('-' . ($windowDays - 1) . ' days');

    // The forecast we actually had in hand when the window opened. Completed
    // only: a `partial` run covers an arbitrary subset of items, which would
    // bias whichever items happened to get through.
    $runStmt = $db->prepare(
        "SELECT run_id, started_at FROM forecast_runs
         WHERE run_type = 'ingredient_policy_sweep' AND status = 'completed'
           AND started_at <= ?
         ORDER BY started_at DESC LIMIT 1"
    );
    $runStmt->execute([$start->format('Y-m-d') . ' 23:59:59']);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        return null;
    }
    $runId = (int)$run['run_id'];

    $items = getActiveIngredientItems($db);

    $absError         = 0.0;
    $actualTotal      = 0.0;
    $forecastTotal    = 0.0;
    $baselineAbsError = 0.0;
    $itemsScored      = 0;
    $coveredDates     = [];

    $fcStmt = $db->prepare(
        'SELECT forecast_date, SUM(overlaid_qty) AS qty
         FROM ingredient_demand_forecast
         WHERE run_id = ? AND item_id = ? AND forecast_date BETWEEN ? AND ?
         GROUP BY forecast_date'
    );

    foreach ($items as $item) {
        $itemId = (int)$item['item_id'];
        $unitId = (int)$item['base_unit_id'];

        $fcStmt->execute([$runId, $itemId, $start->format('Y-m-d'), $end->format('Y-m-d')]);
        $byDate = $fcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!$byDate) {
            // This run never predicted this item. Scoring it would be scoring
            // a forecast that was never made.
            continue;
        }

        $predicted = 0.0;
        foreach ($byDate as $q) {
            $predicted += (float)$q;
        }

        // Actuals over exactly the dates the run predicted -- not the whole
        // calendar window.
        $missing  = [];
        $realized = getDailyDenseIngredientConsumption($db, $itemId, $unitId, $start, $end, $missing);
        $actual   = 0.0;
        foreach ($byDate as $date => $_) {
            $coveredDates[$date] = true;
            $actual += (float)($realized[$date] ?? 0.0);
        }

        // Seasonal-naive baseline: the same weekdays a week earlier, over the
        // same covered dates, so it is scored on identical footing.
        $baseline = 0.0;
        foreach ($byDate as $date => $_) {
            $prior     = (new DateTime($date))->modify('-7 days');
            $bMiss     = [];
            $priorDay  = getDailyDenseIngredientConsumption($db, $itemId, $unitId, $prior, $prior, $bMiss);
            $baseline += (float)($priorDay[$prior->format('Y-m-d')] ?? 0.0);
        }

        // Nothing predicted and nothing used tells us nothing either way.
        if ($predicted <= 0 && $actual <= 0) {
            continue;
        }

        $absError         += abs($predicted - $actual);
        $baselineAbsError += abs($baseline - $actual);
        $actualTotal      += $actual;
        $forecastTotal    += $predicted;
        $itemsScored++;
    }

    if ($itemsScored === 0 || $actualTotal <= 0) {
        return null;
    }

    ksort($coveredDates);
    $dates = array_keys($coveredDates);
    $wape  = ($absError / $actualTotal) * 100;
    $totalErrorPct = (($forecastTotal - $actualTotal) / $actualTotal) * 100;

    return [
        'window_days'       => $windowDays,
        'period_start'      => $dates ? reset($dates) : $start->format('Y-m-d'),
        'period_end'        => $dates ? end($dates) : $end->format('Y-m-d'),
        'covered_days'      => count($dates),
        'run_id'            => $runId,
        'forecast_made_on'  => date('Y-m-d', strtotime((string)$run['started_at'])),
        'items'             => $itemsScored,
        'forecast_total'    => $forecastTotal,
        'actual_total'      => $actualTotal,
        'mae'               => $absError / $itemsScored,
        'wape_pct'          => $wape,
        'baseline_wape_pct' => ($baselineAbsError / $actualTotal) * 100,
        'beats_baseline'    => $absError < $baselineAbsError,
        'total_error_pct'   => abs($totalErrorPct),
        'direction'         => $totalErrorPct >= 0 ? 'over' : 'under',
    ];
}

function computePortfolioForecastAccuracy(PDO $db): array
{
    $items = getActiveIngredientItems($db);
    $pool  = [];

    foreach ($items as $item) {
        $perLag = computeIngredientForecastAccuracyByLag($db, (int)$item['item_id'], (int)$item['base_unit_id']);
        foreach ($perLag as $r) {
            $lag = (int)$r['lag_days'];
            if (!isset($pool[$lag])) {
                $pool[$lag] = [
                    'lag_days' => $lag, 'abs_error' => 0.0, 'actual' => 0.0,
                    'baseline_abs_error' => 0.0, 'items' => 0, 'reconciled' => 0,
                ];
            }
            $pool[$lag]['abs_error'] += (float)$r['abs_error_sum'];
            $pool[$lag]['actual']    += (float)$r['total_actual'];
            // Reconstruct the baseline's absolute error from its own WAPE, which
            // was computed against this same item's actual total.
            $pool[$lag]['baseline_abs_error'] += ((float)$r['baseline_wape_pct'] / 100) * (float)$r['total_actual'];
            $pool[$lag]['items']++;
            $pool[$lag]['reconciled'] += (int)$r['reconciled_count'];
        }
    }

    $out = [];
    foreach ($pool as $lag => $p) {
        if ($p['actual'] <= 0) {
            continue;
        }
        $wape         = ($p['abs_error'] / $p['actual']) * 100;
        $baselineWape = ($p['baseline_abs_error'] / $p['actual']) * 100;
        $out[$lag] = [
            'lag_days'          => $lag,
            'items'             => $p['items'],
            'reconciled_count'  => $p['reconciled'],
            'total_actual'      => $p['actual'],
            'mae'               => $p['reconciled'] > 0 ? $p['abs_error'] / $p['reconciled'] : null,
            'wape_pct'          => $wape,
            'baseline_wape_pct' => $baselineWape,
            'beats_baseline'    => $wape < $baselineWape,
        ];
    }

    ksort($out);
    return $out;
}

/**
 * Upserts one forecast_accuracy row (project plan round 5's design: a
 * live snapshot per (domain, entity_id, horizon_days, lag_days), refreshed
 * in place -- never an ever-growing insert-only log, see that table's own
 * schema comment). Shared by both domains below.
 */
function upsertForecastAccuracy(PDO $db, string $domain, int $entityId, int $horizonDays, int $lagDays, array $result): void
{
    $stmt = $db->prepare(
        "INSERT INTO forecast_accuracy
            (forecast_domain, entity_id, horizon_days, lag_days, period_start, period_end,
             wape_pct, baseline_wape_pct, beats_baseline, coverage_pct, bias_pct, reconciled_count, fold_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            period_start = VALUES(period_start), period_end = VALUES(period_end),
            wape_pct = VALUES(wape_pct), baseline_wape_pct = VALUES(baseline_wape_pct),
            beats_baseline = VALUES(beats_baseline), coverage_pct = VALUES(coverage_pct),
            bias_pct = VALUES(bias_pct), reconciled_count = VALUES(reconciled_count),
            fold_count = VALUES(fold_count), computed_at = NOW()"
    );
    $stmt->execute([
        $domain, $entityId, $horizonDays, $lagDays, $result['period_start'], $result['period_end'],
        $result['wape_pct'], $result['baseline_wape_pct'], $result['beats_baseline'] ? 1 : 0,
        $result['coverage_pct'], $result['bias_pct'], $result['reconciled_count'],
    ]);
}

/**
 * The Phase 6 orchestrator: computes and persists forecast_accuracy for
 * every active menu item (sales domain, daily/prophet -- the standard case
 * demand_forecast_generate.php produces) and every active ingredient
 * (ingredient domain, one row per lag that has enough reconciled data).
 * Called from cron/run_sweeps.php on the same 5-minute cadence as the
 * other sweeps -- cheap (pure SELECT + upsert, no Python calls) and safe
 * to run repeatedly; a lag/item with insufficient reconciled data simply
 * produces no row that tick, matching this app's "skip and disclose rather
 * than fabricate" convention.
 */
function computeAndPersistForecastAccuracy(PDO $db): array
{
    $horizonDays = (int)($db->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'forecast_horizon_days'"
    )->fetchColumn() ?: 7);

    $salesPersisted = 0;
    $menuItems = $db->query("SELECT item_id FROM menu_items WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($menuItems as $itemId) {
        $result = computeForecastAccuracyWape($db, (int)$itemId, 'daily', 'prophet');
        if ($result['gate_ok']) {
            upsertForecastAccuracy($db, 'menu_item_sales', (int)$itemId, 1, 1, $result);
            $salesPersisted++;
        }
    }

    $ingredientPersisted = 0;
    $ingredients = getActiveIngredientItems($db);
    foreach ($ingredients as $item) {
        $byLag = computeIngredientForecastAccuracyByLag($db, (int)$item['item_id'], (int)$item['base_unit_id']);
        foreach ($byLag as $result) {
            upsertForecastAccuracy($db, 'ingredient_demand', (int)$item['item_id'], $horizonDays, $result['lag_days'], $result);
            $ingredientPersisted++;
        }
    }

    return ['sales_rows_persisted' => $salesPersisted, 'ingredient_rows_persisted' => $ingredientPersisted];
}
