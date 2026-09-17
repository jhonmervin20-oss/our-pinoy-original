<?php
/**
 * owner/menu_management/costing.php
 *
 * "Recipes & Costing" -- merges the old standalone recipes.php overview
 * (ingredient/packaging counts, has-recipe filter) into this page, since
 * the two overlapped almost entirely and costing.php already showed
 * strictly more. Read-only + fully-automatic: costs are always computed
 * fresh from live data on page load, never manually encoded. "Recalculate"
 * persists the current live numbers into menu_item_costing (the cache
 * menu_items.php's Food Cost % column reads) and logs a
 * recipe_cost_history row.
 *
 * Food Cost %/Margin % sit in the card's figure strip with their value
 * colour-coded by foodCostHealthClass(), so you can still scan for problem
 * items without reading every row. No stat-card row at the top by design --
 * that colour coding already answers "what needs attention" without a
 * separate summary competing for space above the table.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/flash.php';
require_once __DIR__ . '/includes/menu_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$activePage = 'costing';
$pageTitle  = 'Costing';
$ownerBase  = '../';

$rows = [];
$breakdowns = [];
$categories = [];
$totalItems = 0;
$targetFoodCost = 30.00;
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $categories = $pdo->query(
        "SELECT DISTINCT c.category_id, c.category_name
         FROM menu_items mi JOIN menu_categories c ON c.category_id = mi.category_id
         WHERE mi.is_active = 1 ORDER BY c.category_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $items = $pdo->query(
        "SELECT mi.item_id, mi.menu_code, mi.item_name, mi.category_id, c.category_name, mi.is_vat_exempt,
                mi.available_takeout,
                mc.computed_at, mc.dine_in_cost AS cached_dine_in_cost, mc.takeout_cost AS cached_takeout_cost,
                rc.version AS recipe_version, rc.updated_at AS recipe_updated_at,
                (SELECT COUNT(*) FROM menu_item_ingredients mii WHERE mii.menu_item_id = mi.item_id) AS ingredient_count,
                (SELECT COUNT(*) FROM packaging_rule_items pri
                    JOIN packaging_rules pr ON pr.rule_id = pri.rule_id
                 WHERE pr.menu_item_id = mi.item_id) AS packaging_item_count
         FROM menu_items mi
         LEFT JOIN menu_categories c ON c.category_id = mi.category_id
         LEFT JOIN menu_item_costing mc ON mc.menu_item_id = mi.item_id
         LEFT JOIN recipes rc ON rc.menu_item_id = mi.item_id
         WHERE mi.is_active = 1
         ORDER BY mi.item_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $totalItems = count($items);
    $targetFoodCost = (float)getPricingSettings($pdo)['target_food_cost_percentage'];

    foreach ($items as $item) {
        $itemId = (int)$item['item_id'];
        $costing = computeFullCosting($pdo, $itemId);
        $hasRecipe = (int)$item['ingredient_count'] > 0;

        $isStale = $item['computed_at'] === null
            || abs((float)$item['cached_dine_in_cost'] - $costing['dine_in_cost']) > 0.005
            || abs((float)$item['cached_takeout_cost'] - $costing['takeout_cost']) > 0.005;

        $rows[] = array_merge($costing, [
            'item_id'              => $itemId,
            'menu_code'            => $item['menu_code'],
            'item_name'            => $item['item_name'],
            'category_id'          => $item['category_id'],
            'category_name'        => $item['category_name'],
            'ingredient_count'     => (int)$item['ingredient_count'],
            'packaging_item_count' => (int)$item['packaging_item_count'],
            'has_recipe'           => $hasRecipe,
            'is_stale'             => $isStale,
            'food_cost_class'      => foodCostHealthClass($costing['food_cost_percentage_dine_in'], $targetFoodCost),
            // Margin is the inverse of food cost off the same net price -- profit =
            // net - cost, so (100 - margin%) IS the food cost %. Inverting the VALUE
            // is right; inverting the TARGET too was the bug: it compared food cost
            // against 70% instead of 30%, so every item came back "healthy". An item
            // at 64.2% food cost showed a red food cost beside a green 35.8% margin,
            // which are the same fact. Same target for both, so they cannot disagree.
            'margin_class'         => foodCostHealthClass(100 - $costing['gross_margin_percentage'], $targetFoodCost),
            'computed_at'          => $item['computed_at'],
            'recipe_version'       => (int)($item['recipe_version'] ?? 1),
            'recipe_updated_at'    => $item['recipe_updated_at'],
            'is_vat_exempt'        => (bool)$item['is_vat_exempt'],
            // Takeout figures only mean something for an item actually sold that
            // way. menu_items.available_takeout is the same flag
            // cashier/api/create_order.php enforces at the point of sale, so an
            // item with it off CANNOT be ordered for takeout -- printing a
            // takeout cost, margin and food-cost % for it implied a channel that
            // does not exist. computeFullCosting() still returns those keys (they
            // are the honest "what it WOULD cost" figures, and other callers read
            // them); this only stops the UI presenting them as a live channel.
            'available_takeout'    => (int)$item['available_takeout'] === 1,
            // ...with one exception: nothing stops a packaging rule being attached
            // to a dine-in-only item, and silently hiding the column would hide
            // that contradiction too. Surfaced instead of suppressed.
            'orphan_packaging'     => (int)$item['available_takeout'] !== 1 && $costing['packaging_cost'] > 0,
        ]);

        // Ingredient/packaging line detail for the breakdown modal, each
        // priced individually so the modal can show a proportion-of-cost
        // bar per line (not just quantities like before).
        $ingredientRows = $pdo->prepare(
            "SELECT ii.item_id, ii.item_name, ii.base_unit_id, mii.quantity_required, mii.recipe_unit_id, u.unit_code, bu.unit_code AS base_unit_code
             FROM menu_item_ingredients mii
             JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id
             JOIN unit_of_measures u ON u.unit_id = mii.recipe_unit_id
             JOIN unit_of_measures bu ON bu.unit_id = ii.base_unit_id
             WHERE mii.menu_item_id = ?"
        );
        $ingredientRows->execute([$itemId]);
        $ingredientLines = [];
        foreach ($ingredientRows->fetchAll(PDO::FETCH_ASSOC) as $ing) {
            $fifo = getCurrentFifoCost($pdo, (int)$ing['item_id']);
            $convertedQty = convertQuantity($pdo, (float)$ing['quantity_required'], (int)$ing['recipe_unit_id'], (int)$ing['base_unit_id']);
            $lineCost = $convertedQty !== null ? $convertedQty * $fifo['unit_cost'] : 0.0;
            $ingredientLines[] = [
                'item_name'         => $ing['item_name'],
                'quantity_required' => $ing['quantity_required'],
                'unit_code'         => $ing['unit_code'],
                'unit_cost'         => round($fifo['unit_cost'], 2),
                'base_unit_code'    => $ing['base_unit_code'],
                'line_cost'         => round($lineCost, 2),
                'is_estimate'       => $fifo['is_estimate'],
            ];
        }

        $packagingRowsStmt = $pdo->prepare(
            "SELECT ii.item_id, ii.item_name, ii.base_unit_id, pri.quantity, pri.unit_id, u.unit_code, bu.unit_code AS base_unit_code
             FROM packaging_rules pr
             JOIN packaging_rule_items pri ON pri.rule_id = pr.rule_id
             JOIN inventory_items ii ON ii.item_id = pri.inventory_item_id
             JOIN unit_of_measures u ON u.unit_id = pri.unit_id
             JOIN unit_of_measures bu ON bu.unit_id = ii.base_unit_id
             WHERE pr.menu_item_id = ?"
        );
        $packagingRowsStmt->execute([$itemId]);
        $packagingLines = [];
        foreach ($packagingRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $pkg) {
            $fifo = getCurrentFifoCost($pdo, (int)$pkg['item_id']);
            $convertedQty = convertQuantity($pdo, (float)$pkg['quantity'], (int)$pkg['unit_id'], (int)$pkg['base_unit_id']);
            $lineCost = $convertedQty !== null ? $convertedQty * $fifo['unit_cost'] : 0.0;
            $packagingLines[] = [
                'item_name'      => $pkg['item_name'],
                'quantity'       => $pkg['quantity'],
                'unit_code'      => $pkg['unit_code'],
                'unit_cost'      => round($fifo['unit_cost'], 2),
                'base_unit_code' => $pkg['base_unit_code'],
                'line_cost'      => round($lineCost, 2),
                'is_estimate'    => $fifo['is_estimate'],
            ];
        }

        $historyStmt = $pdo->prepare(
            'SELECT recipe_cost, packaging_cost, dine_in_cost, takeout_cost, reason, recorded_at
             FROM recipe_cost_history
             WHERE menu_item_id = ?
             ORDER BY recorded_at DESC
             LIMIT 10'
        );
        $historyStmt->execute([$itemId]);
        $costHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        $historyCountStmt = $pdo->prepare('SELECT COUNT(*) FROM recipe_cost_history WHERE menu_item_id = ?');
        $historyCountStmt->execute([$itemId]);
        $historyTotalCount = (int)$historyCountStmt->fetchColumn();

        $breakdowns[$itemId] = [
            'ingredients'         => $ingredientLines,
            'packaging'           => $packagingLines,
            'history'             => $costHistory,
            'history_total_count' => $historyTotalCount,
        ];
    }
} catch (PDOException $e) {
    $dbError = "Couldn't load costing data. Please refresh this page.";
}

// menu_items.php links here as costing.php?highlight=<item_id> to jump
// straight into that item's breakdown modal.
$highlightId = isset($_GET['highlight']) && ctype_digit((string)$_GET['highlight']) ? (int)$_GET['highlight'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Costing | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../assets/css/owner-panel.css') ?>">
<style>
    /* ---- Card list ------------------------------------------------------
       One landscape card per menu item, replacing the old 10-column table.
       Full-width rather than a multi-column grid: the breakdown that opens
       inside a card holds two side-by-side tables, so the card needs the whole
       width anyway, and a card that is already full width expands in place
       without shoving its neighbours around. */
    .owner-cost-grid{
        display:grid; grid-template-columns:1fr;
        gap:10px; align-items:start; margin-top:4px;
    }
    .owner-cost-empty{
        grid-column:1/-1; text-align:center; padding:32px 0;
        color:var(--op-ink-faint); font-size:0.85rem;
    }
    .owner-cost-empty[hidden]{ display:none; }

    .owner-cost-card{
        position:relative; background:var(--op-surface);
        border:1px solid var(--op-border); border-radius:var(--op-radius);
        overflow:hidden; transition:border-color 0.15s ease;
    }
    .owner-cost-card:hover{ border-color:var(--op-ink-faint); }
    .owner-cost-card.is-expanded{ grid-column:1/-1; border-color:var(--op-ink-soft); }
    /* Arriving from a notification: say which card the link meant, without a
       flashing animation the owner has to wait out. */
    .owner-cost-card.is-deeplinked{ box-shadow:0 0 0 2px var(--op-ink-soft); }

    /* The summary IS the toggle -- reset the button chrome so it reads as card
       body, then lay its four blocks out side by side: identity, figures,
       health bars, expand affordance. The right padding reserves room for the
       absolutely-positioned Recalculate form, which cannot live inside the
       button (a <button> can't nest in a <button>). */
    .owner-cost-card-summary{
        display:grid; width:100%; text-align:left; cursor:pointer;
        background:none; border:0; font:inherit; color:inherit;
        /* 3 tracks, not 4: the Food Cost/Margin bars used to own the third one.
           Leaving it declared kept a dead ~190px column while the figures
           beside it wrapped onto a second row. */
        grid-template-columns:minmax(150px, 0.85fr) minmax(430px, 2.6fr) auto;
        align-items:center; gap:20px;
        padding:14px 58px 14px 16px;
    }
    .owner-cost-card-summary:focus-visible{ outline:2px solid var(--op-ink-soft); outline-offset:-2px; }

    .owner-cost-card-id{ display:block; min-width:0; }
    .owner-cost-card-title{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .owner-cost-card-title strong{ font-size:0.95rem; line-height:1.3; }
    .owner-cost-card-meta{ display:block; font-size:0.74rem; color:var(--op-ink-faint); margin-top:2px; }
    .owner-cost-card-pills{ display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }

    /* align-items:end keeps the five amounts on one line even when a longer
       label ("Takeout cost") wraps to two -- each cell grows upward from a
       shared bottom edge instead of shoving its own value down. */
    .owner-cost-card-figures{
        /* 7 figures now (Food cost and Margin joined the row when their bars
           were dropped); at 5 they wrapped. */
        display:grid; grid-template-columns:repeat(7, minmax(0, 1fr));
        gap:4px 12px; align-items:end;
    }
    .owner-cost-card-figures > span{ display:block; font-size:0.84rem; font-weight:600; }
    /* Labels wrap inside their own column. nowrap here let "TAKEOUT COST" and
       "SELLING PRICE" run together into "TAKEOUT COSTSELLING PRICE" once the
       columns narrowed. */
    .owner-cost-card-figures em{
        display:block; font-style:normal; font-size:0.66rem; font-weight:500;
        text-transform:uppercase; letter-spacing:0.02em; line-height:1.25;
        color:var(--op-ink-faint); margin-bottom:2px;
    }

    /* Food cost / margin health colours. ONE scale, four steps, used by the card
       strip, the breakdown and the target callout below, and mirrored in
       recipe_builder.php's .rbp-health -- change one, change the other.

           Excellent  green   at or under target
           Good       blue    up to target + 8
           Watch      orange  up to target + 15
           High       red     beyond that

       Green and red come from owner-panel.css's own --op-success/--op-danger.
       Blue and orange have no variable there, so they are declared here.
       (This replaces an earlier scheme that used blue for Excellent and two
       shades of gold for the middle steps -- the golds were too close to tell
       apart, and green/red is what people already read as good/bad.) */
    :root{ --ocf-blue:#3f7ea6; --ocf-orange:#c87a2e; }
    .ocf-health.is-success{ color:var(--op-success); }
    .ocf-health.is-active{  color:var(--ocf-blue); }
    .ocf-health.is-warning{ color:var(--ocf-orange); }
    .ocf-health.is-danger{  color:var(--op-danger); }
    .ocf-health.is-neutral{ color:var(--op-ink-faint); }

    .owner-cost-card-expand{
        display:flex; align-items:center; justify-content:flex-end; gap:6px;
        font-size:0.75rem; font-weight:600; color:var(--op-ink-soft); white-space:nowrap;
    }
    .owner-cost-card-head{ position:relative; }
    .owner-cost-card-recalc{ position:absolute; top:50%; right:14px; transform:translateY(-50%); }

    /* Narrower than this the four columns squeeze the figures into unreadable
       slivers, so drop to two columns -- NOT to one. A single stacked column in
       a full-width card leaves the whole right half of every card empty, which
       is exactly what the landscape layout was meant to avoid. Identity and the
       expand row span both columns; figures and bars sit side by side and are
       uncapped so they still reach the right edge. */
    /* 1240px, not lower: the four columns' own minimums (160+260+190+~120) plus
       gaps and padding need ~858px of card, and the card is roughly the viewport
       minus the 260px sidebar and the content gutters. Below this the last
       column overflowed the card's right edge instead of wrapping. */
    @media (max-width: 1240px){
        .owner-cost-card-summary{ grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px 20px; padding:16px 58px 16px 16px; }
        .owner-cost-card-id{ grid-column:1/-1; }
        .owner-cost-card-figures{ grid-template-columns:repeat(auto-fit, minmax(84px, 1fr)); }
        .owner-cost-card-expand{ grid-column:1/-1; justify-content:flex-start; }
        .owner-cost-card-recalc{ top:12px; transform:none; }
    }

    /* Phone: two columns of figures would wrap mid-label, so give each block a
       full row of its own. */
    @media (max-width: 640px){
        .owner-cost-card-summary{ grid-template-columns:1fr; }
    }
    .owner-accordion-caret{ transition:transform 0.2s ease; }
    .owner-btn-toggle-breakdown.is-expanded .owner-accordion-caret{ transform:rotate(180deg); }

    /* Recalculate all now lives in the filter row (the card head is gone).
       margin-left:auto pushes it to the far end so it stays visually separate
       from the filters it sits beside -- it acts on the whole list, it is not
       another filter. .owner-inv-filters already wraps, so on a narrow screen it
       drops to its own line rather than squeezing the search box. */
    .owner-cost-recalc-all{ margin-left:auto; }
    .owner-inv-filters{ margin-top:0; }

    /* ---- Expandable breakdown ------------------------------------------- */
    .owner-cost-detail-panel{ max-height:0; overflow:hidden; transition:max-height 0.35s ease; }
    /* White, continuous with the card it opens out of.
       It used to be filled with var(--op-canvas) so the panels inside could
       read as separate cards -- but canvas IS the page background, and once
       the wrapper card was removed the open breakdown looked like a hole
       punched through the card rather than part of it. The panels below keep
       their own border, which is what separates them on white. */
    .owner-cost-detail-inner{
        padding:18px 16px 18px; border-top:1px solid var(--op-border-soft);
        margin-top:4px; background:var(--op-surface);
    }
    .owner-cost-panel{
        background:var(--op-surface); border:1px solid var(--op-border);
        border-radius:var(--op-radius); padding:16px 18px;
    }
    /* Section titles carry their own top margin for stacked headings; inside a
       panel that just pushes the first heading off its own padding. */
    .owner-cost-panel > .owner-form-section-title:first-child{ margin-top:0; }
    /* Equal columns: the left column stacks two panels, the right one. Whichever
       column is taller sets the row, and the single right-hand panel stretches
       to match rather than leaving a short box beside a tall one. */
    .owner-cost-detail-left, .owner-cost-detail-right{ display:flex; flex-direction:column; gap:16px; min-width:0; }
    .owner-cost-detail-right > .owner-cost-panel{ flex:1 1 auto; }
    /* Was 1.3fr 1fr for a narrow field list; the summary is now a 3-column
       comparison table and needs comparable room to the ingredient tables. */
    .owner-cost-detail-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:stretch; }
    .owner-cost-detail-bottom{ margin-top:16px; }
    .owner-cost-detail-footer{
        margin-top:20px; padding-top:14px; border-top:1px solid var(--op-border-soft);
        display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:10px 20px;
        font-size:0.76rem; color:var(--op-ink-faint);
    }
    .owner-cost-detail-footer strong{ display:block; color:var(--op-ink-soft); font-weight:600; margin-bottom:2px; }

    /* ---- Cost summary comparison ----------------------------------------
       Dine-in and takeout side by side rather than eleven flat fields: the
       whole reason packaging cost is tracked separately is that the two
       channels have different economics, and a single column hid that. */
    .owner-cost-summary{ font-variant-numeric:tabular-nums; }
    .owner-cost-summary th, .owner-cost-summary td{ vertical-align:middle; }
    .owner-cost-summary thead th{ white-space:nowrap; }
    .owner-cost-summary thead th span{
        display:block; font-weight:400; text-transform:none; letter-spacing:0;
        font-size:0.68rem; color:var(--op-ink-faint); margin-top:2px;
    }
    .owner-cost-summary .ocs-num{ text-align:right; white-space:nowrap; }
    .owner-cost-summary .ocs-muted{ color:var(--op-ink-faint); }
    /* Qualifiers ("excl. VAT", "recipe cost / net price") ride with the metric
       name instead of padding it out, so the rows stay one line each. */
    .owner-cost-summary .ocs-sub{ color:var(--op-ink-faint); font-size:0.74rem; margin-left:4px; }
    .owner-cost-summary .ocs-sub-val{ color:var(--op-ink-faint); font-size:0.74rem; margin-left:6px; }
    .owner-cost-summary .ocs-subtotal td{ font-weight:600; background:var(--op-canvas); }
    .owner-cost-summary .ocs-total td{
        font-weight:700; color:var(--op-ink);
        background:var(--op-gold-soft); border-top:1px solid var(--op-gold-soft-2);
    }

    .owner-cost-target{
        display:flex; align-items:flex-start; gap:10px; margin-top:14px;
        padding:12px 14px; border-radius:var(--op-radius-sm); font-size:0.8rem;
        border:1px solid var(--op-border);
    }
    .owner-cost-target i{ font-size:1.05rem; flex-shrink:0; margin-top:1px; }
    .owner-cost-target strong{ display:block; font-weight:600; margin-bottom:2px; }
    .owner-cost-target span{ color:var(--op-ink-soft); }
    /* Same four levels, same colours, as .owner-status-pill and the Food Cost
       bar in the row header -- so the callout and the bar can never contradict
       each other. strong takes the status colour; the sentence stays in soft
       ink so a red heading doesn't turn a whole paragraph into an alarm. */
    .owner-cost-target strong{ color:inherit; }
    .owner-cost-target.is-success{ background:var(--op-success-soft);  border-color:transparent; color:var(--op-success); }
    .owner-cost-target.is-active{  background:rgba(63,126,166,0.12);  border-color:transparent; color:var(--ocf-blue); }
    .owner-cost-target.is-warning{ background:rgba(200,122,46,0.12);  border-color:transparent; color:var(--ocf-orange); }
    .owner-cost-target.is-danger{  background:var(--op-danger-soft);  border-color:transparent; color:var(--op-danger); }
    .owner-cost-target.is-neutral{ background:var(--op-canvas);       border-color:var(--op-border); color:var(--op-ink-faint); }

    @media (max-width: 900px){ .owner-cost-detail-grid{ grid-template-columns:1fr; gap:20px; } }
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/../includes/header.php'; ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <?php /* Page actions + filters sit in their own card above the content --
                     same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <!-- No card head: the sidebar and the top bar already say "Costing",
                     so a third heading plus a subtitle was only pushing the actual
                     content down. Recalculate all sits in the filter row instead. -->
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="filterSearch" placeholder="Search menu items&hellip;" autocomplete="off">
                    </div>
                    <select id="filterCategory" class="owner-select">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="filterRecipe" class="owner-select">
                        <option value="">All items</option>
                        <option value="has-recipe">Has a recipe</option>
                        <option value="no-recipe">No recipe yet</option>
                    </select>
                    <select id="filterHealth" class="owner-select">
                        <option value="">All food cost levels</option>
                        <option value="is-success">Excellent (&le;<?= number_format($targetFoodCost, 0) ?>%)</option>
                        <option value="is-active">Good (&le;<?= number_format($targetFoodCost + 8, 0) ?>%)</option>
                        <option value="is-warning">Watch (&le;<?= number_format($targetFoodCost + 15, 0) ?>%)</option>
                        <option value="is-danger">High (&gt;<?= number_format($targetFoodCost + 15, 0) ?>%)</option>
                    </select>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnClearFilters">Clear</button>
                    <?php if ($totalItems > 0): ?>
                    <form method="post" action="costing_recalculate.php" class="owner-cost-recalc-all">
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="all">
                        <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Recalculate all</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

                <div class="owner-cost-grid" id="costGrid">
                    <?php if (empty($rows)): ?>
                        <p class="owner-cost-empty">No active menu items yet.</p>
                    <?php else: ?>
                        <?php foreach ($rows as $r):
                            $bid = (int)$r['item_id'];
                            $b = $breakdowns[$bid];
                            $recipeCostBasis = $r['recipe_cost'];
                            $packagingCostBasis = $r['packaging_cost'];
                            $searchKey = strtolower($r['item_name'] . ' ' . $r['menu_code'] . ' ' . ($r['category_name'] ?? ''));
                        ?>
                        <article class="owner-cost-card" id="costCard-<?= $bid ?>"
                                 data-name="<?= htmlspecialchars($searchKey) ?>"
                                 data-category="<?= (int)($r['category_id'] ?? 0) ?>"
                                 data-recipe="<?= $r['has_recipe'] ? 'has-recipe' : 'no-recipe' ?>"
                                 data-health="<?= $r['food_cost_class'] ?>">

                            <!-- The whole summary is the accordion trigger, so the card reads as
                                 one clickable object rather than a card with a hidden caret to hunt for.
                                 The Recalculate form sits outside it (a <button> can't nest in a <button>)
                                 but inside this wrapper, which is the positioning context that keeps the
                                 button centred on the summary row rather than on the whole card -- the
                                 card grows very tall once the breakdown opens. -->
                            <div class="owner-cost-card-head">
                            <button type="button" class="owner-cost-card-summary owner-btn-toggle-breakdown"
                                    data-toggle-breakdown="<?= $bid ?>" aria-expanded="false"
                                    aria-controls="costDetailPanel-<?= $bid ?>">
                                <span class="owner-cost-card-id">
                                    <span class="owner-cost-card-title">
                                        <strong><?= htmlspecialchars($r['item_name']) ?></strong>
                                        <?php if ($r['is_stale']): ?>
                                            <span class="owner-status-pill is-warning" title="Live cost differs from the last saved snapshot">Stale</span>
                                        <?php endif; ?>
                                        <?php if ($r['has_uncostable_line']): ?>
                                            <i class="ph ph-warning-circle" style="color:var(--op-danger);" title="One or more lines can't be costed (missing unit conversion)" aria-hidden="true"></i>
                                        <?php endif; ?>
                                    </span>
                                    <span class="owner-cost-card-meta"><?= htmlspecialchars($r['menu_code']) ?> &middot; <?= $r['category_name'] !== null ? htmlspecialchars($r['category_name']) : '&mdash;' ?></span>

                                    <span class="owner-cost-card-pills">
                                        <?php if ($r['has_recipe']): ?>
                                            <span class="owner-status-pill is-neutral"><?= (int)$r['ingredient_count'] ?> ingredient<?= $r['ingredient_count'] === 1 ? '' : 's' ?></span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-warning">No recipe</span>
                                        <?php endif; ?>
                                        <?php if ($r['packaging_item_count'] > 0): ?>
                                            <span class="owner-status-pill is-neutral"><?= (int)$r['packaging_item_count'] ?> packaging</span>
                                        <?php endif; ?>
                                        <?php if (!$r['available_takeout']): ?>
                                            <span class="owner-status-pill is-neutral" title="Not sold for takeout, so no takeout costing is shown">Dine-in only</span>
                                        <?php endif; ?>
                                        <?php if ($r['orphan_packaging']): ?>
                                            <span class="owner-status-pill is-warning" title="This item has a packaging rule but is not available for takeout">Packaging on a dine-in-only item</span>
                                        <?php endif; ?>
                                    </span>
                                </span>

                                <span class="owner-cost-card-figures">
                                    <span><em>Recipe cost</em>&#8369;<?= number_format($r['recipe_cost'], 2) ?></span>
                                    <?php if ($r['available_takeout'] || $r['orphan_packaging']): ?>
                                    <span><em>Packaging cost</em>&#8369;<?= number_format($r['packaging_cost'], 2) ?></span>
                                    <?php endif; ?>
                                    <?php if ($r['available_takeout']): ?>
                                    <span><em>Total cost</em>&#8369;<?= number_format($r['takeout_cost'], 2) ?></span>
                                    <?php endif; ?>
                                    <span><em>Selling price</em>&#8369;<?= number_format($r['selling_price'], 2) ?></span>
                                    <span><em>Profit</em>&#8369;<?= number_format($r['gross_profit'], 2) ?></span>
                                    <?php // Same block shape as the figures beside them; the value carries the
                                          // health colour so the strip is still scannable without the old bars. ?>
                                    <span><em>Food cost</em><span class="ocf-health <?= $r['food_cost_class'] ?>"><?= number_format($r['food_cost_percentage_dine_in'], 2) ?>%</span></span>
                                    <span><em>Margin</em><span class="ocf-health <?= $r['margin_class'] ?>"><?= number_format($r['gross_margin_percentage'], 2) ?>%</span></span>
                                </span>

                                <span class="owner-cost-card-expand">
                                    <span class="owner-cost-card-expand-label">View breakdown</span>
                                    <i class="ph ph-caret-down owner-accordion-caret" aria-hidden="true"></i>
                                </span>
                            </button>

                            <form method="post" action="costing_recalculate.php" class="owner-cost-card-recalc">
                                <?= csrf_field() ?>
                                <input type="hidden" name="item_id" value="<?= $bid ?>">
                                <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Recalculate <?= htmlspecialchars($r['item_name']) ?>" title="Recalculate">
                                    <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                </button>
                            </form>
                            </div>

                            <div class="owner-cost-detail-panel" id="costDetailPanel-<?= $bid ?>">
                                <div class="owner-cost-detail-inner">
                            <div class="owner-cost-detail-grid">
                                <div class="owner-cost-detail-left">
                                    <div class="owner-cost-panel">
                                    <h3 class="owner-form-section-title">Recipe cost</h3>
                                    <?php if (empty($b['ingredients'])): ?>
                                        <p class="owner-form-hint">No ingredients yet.</p>
                                    <?php else: ?>
                                        <div class="owner-table-wrap">
                                            <table class="owner-table">
                                                <thead>
                                                    <tr><th>Ingredient</th><th>Quantity</th><th>Unit</th><th>FIFO Unit Cost</th><th>Ingredient Cost</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($b['ingredients'] as $ing): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($ing['item_name']) ?><?= $ing['is_estimate'] ? ' <span class="owner-status-pill is-warning" style="margin-left:4px;" title="No live stock -- estimated from last purchase cost">est.</span>' : '' ?></td>
                                                        <td><?= htmlspecialchars(rtrim(rtrim($ing['quantity_required'], '0'), '.')) ?></td>
                                                        <td><?= htmlspecialchars($ing['unit_code']) ?></td>
                                                        <td>&#8369;<?= number_format($ing['unit_cost'], 2) ?> / <?= htmlspecialchars($ing['base_unit_code']) ?></td>
                                                        <td>&#8369;<?= number_format($ing['line_cost'], 2) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <tr>
                                                        <td colspan="4" style="text-align:right;"><strong>Subtotal Recipe Cost</strong></td>
                                                        <td><strong>&#8369;<?= number_format($recipeCostBasis, 2) ?></strong></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>

                                    </div>

                                    <div class="owner-cost-panel">
                                    <h3 class="owner-form-section-title">Packaging cost</h3>
                                    <?php if (empty($b['packaging'])): ?>
                                        <p class="owner-form-hint">No packaging needed for this item.</p>
                                    <?php else: ?>
                                        <div class="owner-table-wrap">
                                            <table class="owner-table">
                                                <thead>
                                                    <tr><th>Packaging Item</th><th>Quantity</th><th>Unit Cost</th><th>Packaging Cost</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($b['packaging'] as $pkg): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($pkg['item_name']) ?><?= $pkg['is_estimate'] ? ' <span class="owner-status-pill is-warning" style="margin-left:4px;" title="No live stock -- estimated from last purchase cost">est.</span>' : '' ?></td>
                                                        <td><?= htmlspecialchars(rtrim(rtrim($pkg['quantity'], '0'), '.')) ?> <?= htmlspecialchars($pkg['unit_code']) ?></td>
                                                        <td>&#8369;<?= number_format($pkg['unit_cost'], 2) ?> / <?= htmlspecialchars($pkg['base_unit_code']) ?></td>
                                                        <td>&#8369;<?= number_format($pkg['line_cost'], 2) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <tr>
                                                        <td colspan="3" style="text-align:right;"><strong>Subtotal Packaging Cost</strong></td>
                                                        <td><strong>&#8369;<?= number_format($packagingCostBasis, 2) ?></strong></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                    </div>
                                </div>

                                <div class="owner-cost-detail-right">
                                    <div class="owner-cost-panel">
                                    <h3 class="owner-form-section-title">Cost summary</h3>
                                    <?php
                                        // Read the VAT rate back off the figures themselves rather than
                                        // re-reading tax settings here: this way the printed rate can
                                        // never disagree with the amount printed beside it.
                                        $vatRateLabel = $r['net_selling_price'] > 0
                                            ? rtrim(rtrim(number_format($r['vat_amount'] / $r['net_selling_price'] * 100, 2, '.', ''), '0'), '.')
                                            : null;
                                        $fcDineIn = $r['food_cost_percentage_dine_in'];
                                        $fcGap    = round($fcDineIn - $targetFoodCost, 2);
                                        $showTakeout = $r['available_takeout'];
                                    ?>
                                    <div class="owner-table-wrap">
                                        <table class="owner-table owner-cost-summary">
                                            <thead>
                                                <tr>
                                                    <th>Metric</th>
                                                    <th class="ocs-num">Dine-in <span><?= $showTakeout ? 'No packaging' : 'Dine-in only' ?></span></th>
                                                    <?php if ($showTakeout): ?>
                                                    <th class="ocs-num">Takeout <span>With packaging</span></th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Recipe cost <span class="ocs-sub">ingredients only</span></td>
                                                    <td class="ocs-num">&#8369;<?= number_format($r['recipe_cost'], 2) ?></td>
                                                    <?php if ($showTakeout): ?><td class="ocs-num">&#8369;<?= number_format($r['recipe_cost'], 2) ?></td><?php endif; ?>
                                                </tr>
                                                <?php if ($showTakeout): ?>
                                                <tr>
                                                    <td>Packaging cost</td>
                                                    <td class="ocs-num ocs-muted">&mdash;</td>
                                                    <td class="ocs-num">&#8369;<?= number_format($r['packaging_cost'], 2) ?></td>
                                                </tr>
                                                <?php elseif ($r['orphan_packaging']): ?>
                                                <?php // Dine-in only, yet a packaging rule is configured. The amount is
                                                      // shown rather than hidden -- it is the evidence that this item's
                                                      // channel flag and its packaging rule disagree. ?>
                                                <tr>
                                                    <td>Packaging cost <span class="ocs-sub">configured, but this item is not sold for takeout</span></td>
                                                    <td class="ocs-num">&#8369;<?= number_format($r['packaging_cost'], 2) ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr class="ocs-subtotal">
                                                    <td>Total cost <span class="ocs-sub">recipe + packaging</span></td>
                                                    <td class="ocs-num">&#8369;<?= number_format($r['dine_in_cost'], 2) ?></td>
                                                    <?php if ($showTakeout): ?><td class="ocs-num">&#8369;<?= number_format($r['takeout_cost'], 2) ?></td><?php endif; ?>
                                                </tr>
                                                <tr>
                                                    <td>Net price <span class="ocs-sub">excl. VAT</span></td>
                                                    <td class="ocs-num">&#8369;<?= number_format($r['net_selling_price'], 2) ?></td>
                                                    <?php if ($showTakeout): ?><td class="ocs-num">&#8369;<?= number_format($r['net_selling_price'], 2) ?></td><?php endif; ?>
                                                </tr>
                                                <tr>
                                                    <td>VAT<?= $vatRateLabel !== null ? ' <span class="ocs-sub">' . htmlspecialchars($vatRateLabel) . '%</span>' : '' ?></td>
                                                    <td class="ocs-num">&#8369;<?= number_format($r['vat_amount'], 2) ?></td>
                                                    <?php if ($showTakeout): ?><td class="ocs-num">&#8369;<?= number_format($r['vat_amount'], 2) ?></td><?php endif; ?>
                                                </tr>
                                                <tr class="ocs-total">
                                                    <td>Selling price <span class="ocs-sub">incl. VAT</span></td>
                                                    <td class="ocs-num">&#8369;<?= number_format($r['selling_price'], 2) ?></td>
                                                    <?php if ($showTakeout): ?><td class="ocs-num">&#8369;<?= number_format($r['selling_price'], 2) ?></td><?php endif; ?>
                                                </tr>
                                                <tr>
                                                    <td>Food cost % <span class="ocs-sub">recipe cost &divide; net price</span></td>
                                                    <td class="ocs-num"><?= number_format($fcDineIn, 2) ?>%</td>
                                                    <?php if ($showTakeout): ?><td class="ocs-num"><?= number_format($fcDineIn, 2) ?>%</td><?php endif; ?>
                                                </tr>
                                                <?php // No "Total cost %" row: it restated Food cost % in the dine-in
                                                      // column, and in the takeout column it is the exact complement of
                                                      // the Margin % row below (cost + profit = net price), so the
                                                      // packaging effect is still readable without a row that repeats
                                                      // one neighbour and inverts another. Total cost and Profit above
                                                      // already carry it in pesos. ?>
                                                <tr>
                                                    <td>Profit <span class="ocs-sub">net price &minus; cost</span></td>
                                                    <td class="ocs-num">&#8369;<?= number_format($r['gross_profit'], 2) ?><span class="ocs-sub-val">(<?= number_format($r['gross_margin_percentage'], 2) ?>%)</span></td>
                                                    <?php if ($showTakeout): ?><td class="ocs-num">&#8369;<?= number_format($r['gross_profit_takeout'], 2) ?><span class="ocs-sub-val">(<?= number_format($r['gross_margin_percentage_takeout'], 2) ?>%)</span></td><?php endif; ?>
                                                </tr>
                                                <tr>
                                                    <td>Markup % <span class="ocs-sub">profit &divide; cost</span></td>
                                                    <td class="ocs-num"><?= number_format($r['markup_percentage'], 2) ?>%</td>
                                                    <?php // Takeout markup is profit over the TAKEOUT cost (recipe + packaging).
                                                          // This column printed markup_percentage -- the dine-in figure -- so a
                                                          // takeout item with packaging showed a markup it does not earn, while
                                                          // the Profit row directly above it was already channel-correct. ?>
                                                    <?php if ($showTakeout): ?><td class="ocs-num"><?= number_format($r['markup_percentage_takeout'], 2) ?>%</td><?php endif; ?>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php
                                        // Target comes from Pricing settings, never a hardcoded figure.
                                        // Severity reuses $r['food_cost_class'] -- the SAME foodCostHealthClass()
                                        // call that colours the Food Cost bar in the row header. A binary
                                        // over/under here would have painted an item 25% over target exactly
                                        // like one 1% over, and could disagree with the bar directly above it.
                                        $targetIcons = [
                                            'is-success' => 'ph-check-circle',
                                            'is-active'  => 'ph-info',
                                            'is-warning' => 'ph-warning',
                                            'is-danger'  => 'ph-warning-circle',
                                            'is-neutral' => 'ph-info',
                                        ];
                                        $targetClass = $r['food_cost_class'];
                                        $targetIcon  = $targetIcons[$targetClass] ?? 'ph-info';
                                    ?>
                                    <div class="owner-cost-target <?= htmlspecialchars($targetClass) ?>">
                                        <i class="ph <?= $targetIcon ?>" aria-hidden="true"></i>
                                        <div>
                                            <strong>Food cost % target: &le; <?= number_format($targetFoodCost, 2) ?>%</strong>
                                            <span><?php
                                                if ($fcGap > 0) {
                                                    echo 'This item is ' . number_format($fcGap, 2) . '% above the target.';
                                                } elseif ($fcGap < 0) {
                                                    echo 'This item is ' . number_format(abs($fcGap), 2) . '% below the target.';
                                                } else {
                                                    echo 'This item is exactly on target.';
                                                }
                                            ?></span>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>

                            <div class="owner-cost-detail-bottom">
                                <div class="owner-cost-panel">
                                <h3 class="owner-form-section-title">Cost History</h3>
                                <?php if (empty($b['history'])): ?>
                                    <p class="owner-form-hint">No cost history yet &mdash; a row is added only when this item's cost actually changes, whether from a recipe or packaging edit, a recalculation, or a new inventory batch price.</p>
                                <?php else: ?>
                                    <?php
                                        $reasonLabels = [
                                            'recipe_edit'    => 'Recipe edited',
                                            'packaging_edit' => 'Packaging rule edited',
                                            'manual_recalc'  => 'Manual recalculation',
                                            'fifo_change'    => 'Inventory cost changed',
                                            'initial'        => 'Initial setup',
                                        ];
                                    ?>
                                    <div class="owner-table-wrap">
                                        <table class="owner-table">
                                            <thead>
                                                <tr><th>Date</th><th>Reason</th><th>Recipe Cost</th><th>Packaging Cost</th><th>Dine-in Cost</th><th>Takeout Cost</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($b['history'] as $h): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($h['recorded_at']))) ?></td>
                                                    <td><?= htmlspecialchars($reasonLabels[$h['reason']] ?? $h['reason']) ?></td>
                                                    <td>&#8369;<?= number_format($h['recipe_cost'], 2) ?></td>
                                                    <td>&#8369;<?= number_format($h['packaging_cost'], 2) ?></td>
                                                    <td>&#8369;<?= number_format($h['dine_in_cost'], 2) ?></td>
                                                    <td>&#8369;<?= number_format($h['takeout_cost'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if ($b['history_total_count'] > 10): ?>
                                        <p class="owner-form-hint" style="margin-top:8px;">Showing the latest 10 of <?= $b['history_total_count'] ?> recorded changes.</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                </div>
                            </div>

                            <div class="owner-cost-detail-footer">
                                <div><strong>Last Recalculated</strong><?= $r['computed_at'] !== null ? htmlspecialchars(date('M j, Y g:i A', strtotime($r['computed_at']))) : 'Never recalculated' ?></div>
                            </div>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                        <p class="owner-cost-empty" id="noMatchRow" hidden>No items match your filters.</p>
                    <?php endif; ?>
                </div>

                <div class="owner-pagination" id="pagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="pagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="pageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="pageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>

        </main>

    </div>

</div>

<script>
(function () {
    const highlightId = <?= json_encode($highlightId) ?>;

    // -- Cost breakdown accordion ---------------------------------------------
    // The panel now lives INSIDE its card rather than in a sibling <tr>, so
    // expanding also widens the card to the full grid row (.is-expanded ->
    // grid-column:1/-1). That width change happens before the height is
    // measured, which matters: the breakdown's two tables reflow narrower in a
    // one-column card, so measuring scrollHeight first would clip the panel.
    let expandedItemId = null;

    function collapseRow(id) {
        const btn = document.querySelector(`.owner-btn-toggle-breakdown[data-toggle-breakdown="${id}"]`);
        const panel = document.getElementById('costDetailPanel-' + id);
        const card = document.getElementById('costCard-' + id);
        if (panel) {
            // max-height can't animate away from 'none' (see the transitionend
            // handler below), so pin it back to its real height and force a
            // reflow first -- otherwise the card would snap shut with no
            // animation.
            if (panel.style.maxHeight === 'none') {
                panel.style.maxHeight = panel.scrollHeight + 'px';
                void panel.offsetHeight;
            }
            panel.style.maxHeight = '0px';
        }
        if (card) card.classList.remove('is-expanded', 'is-deeplinked');
        if (btn) { btn.classList.remove('is-expanded'); btn.setAttribute('aria-expanded', 'false'); }
    }
    function expandRow(id) {
        const btn = document.querySelector(`.owner-btn-toggle-breakdown[data-toggle-breakdown="${id}"]`);
        const panel = document.getElementById('costDetailPanel-' + id);
        const card = document.getElementById('costCard-' + id);
        if (!panel || !card) return;
        card.classList.add('is-expanded');
        panel.style.maxHeight = panel.scrollHeight + 'px';
        if (btn) { btn.classList.add('is-expanded'); btn.setAttribute('aria-expanded', 'true'); }
    }

    // Once the open animation has finished, drop the pixel cap entirely. The
    // panel holds tables whose height can still shift afterwards (late webfont,
    // a wrapped cell), and a stale cap would silently clip the bottom of the
    // cost history.
    document.querySelectorAll('.owner-cost-detail-panel').forEach((panel) => {
        panel.addEventListener('transitionend', (e) => {
            if (e.propertyName !== 'max-height') return;
            if (panel.style.maxHeight !== '0px' && panel.style.maxHeight !== '') {
                panel.style.maxHeight = 'none';
            }
        });
    });

    // A panel pinned to a fixed pixel maxHeight would clip if the card's width
    // changes under it (window resize, sidebar collapse), so remeasure the one
    // that's open.
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        if (expandedItemId === null) return;
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            const panel = document.getElementById('costDetailPanel-' + expandedItemId);
            // 'none' means the open animation already finished and the cap is
            // gone -- nothing can clip, so leave it alone.
            if (panel && panel.style.maxHeight !== 'none') {
                panel.style.maxHeight = panel.scrollHeight + 'px';
            }
        }, 150);
    });
    function toggleRow(id) {
        if (expandedItemId === id) { collapseRow(id); expandedItemId = null; return; }
        if (expandedItemId !== null) collapseRow(expandedItemId);
        expandRow(id);
        expandedItemId = id;
    }
    document.querySelectorAll('.owner-btn-toggle-breakdown').forEach((btn) => {
        btn.addEventListener('click', () => toggleRow(parseInt(btn.getAttribute('data-toggle-breakdown'), 10)));
    });

    // -- Filters + pagination (same pattern as menu_items.php) -------------
    const search = document.getElementById('filterSearch');
    const categoryFilter = document.getElementById('filterCategory');
    const recipeFilter = document.getElementById('filterRecipe');
    const healthFilter = document.getElementById('filterHealth');
    const clearBtn = document.getElementById('btnClearFilters');
    const noMatchRow = document.getElementById('noMatchRow');
    const rows = Array.from(document.querySelectorAll('.owner-cost-card[data-name]'));

    const PAGE_SIZE = 20;
    const pagination = document.getElementById('pagination');
    const pagePrev = document.getElementById('pagePrev');
    const pageNext = document.getElementById('pageNext');
    const pageInfo = document.getElementById('pageInfo');
    let currentPage = 1;

    function applyFilters(resetPage) {
        if (resetPage) currentPage = 1;

        const q        = (search ? search.value : '').trim().toLowerCase();
        const category = categoryFilter ? categoryFilter.value : '';
        const recipe   = recipeFilter ? recipeFilter.value : '';
        const health   = healthFilter ? healthFilter.value : '';

        const matched = rows.filter((row) => {
            const matchesSearch   = !q || row.getAttribute('data-name').includes(q);
            const matchesCategory = !category || row.getAttribute('data-category') === category;
            const matchesRecipe   = !recipe || row.getAttribute('data-recipe') === recipe;
            const matchesHealth   = !health || row.getAttribute('data-health') === health;
            return matchesSearch && matchesCategory && matchesRecipe && matchesHealth;
        });

        const totalPages = Math.max(1, Math.ceil(matched.length / PAGE_SIZE));
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * PAGE_SIZE;
        const matchedSet = new Set(matched.slice(start, start + PAGE_SIZE));

        // The breakdown is inside the card now, so hiding the card hides its
        // panel with it -- but an open card filtered off the page must still be
        // collapsed, or it would reappear expanded (and mis-measured) later.
        rows.forEach((row) => {
            const visible = matchedSet.has(row);
            row.style.display = visible ? '' : 'none';
            if (!visible && expandedItemId !== null && row.id === 'costCard-' + expandedItemId) {
                collapseRow(expandedItemId);
                expandedItemId = null;
            }
        });

        if (noMatchRow) noMatchRow.hidden = (rows.length === 0 || matched.length > 0);
        if (pagination) pagination.hidden = matched.length === 0 || totalPages <= 1;
        if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        if (pagePrev) pagePrev.disabled = currentPage <= 1;
        if (pageNext) pageNext.disabled = currentPage >= totalPages;
    }

    if (search)         search.addEventListener('input', () => applyFilters(true));
    if (categoryFilter) categoryFilter.addEventListener('change', () => applyFilters(true));
    if (recipeFilter)   recipeFilter.addEventListener('change', () => applyFilters(true));
    if (healthFilter)   healthFilter.addEventListener('change', () => applyFilters(true));
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (search) search.value = '';
            if (categoryFilter) categoryFilter.value = '';
            if (recipeFilter) recipeFilter.value = '';
            if (healthFilter) healthFilter.value = '';
            applyFilters(true);
        });
    }
    if (pagePrev) pagePrev.addEventListener('click', () => { currentPage--; applyFilters(false); });
    if (pageNext) pageNext.addEventListener('click', () => { currentPage++; applyFilters(false); });

    // A deep-linked card (from menu_items.php, or from the owner's "Menu costs
    // updated from inventory" notification) may not be on page 1 -- jump to its
    // page before the first render, so expandRow() below doesn't measure a
    // display:none card (scrollHeight would read 0 and the panel would open
    // to nothing).
    if (highlightId) {
        const targetCard = document.getElementById('costCard-' + highlightId);
        if (targetCard && rows.indexOf(targetCard) !== -1) {
            currentPage = Math.floor(rows.indexOf(targetCard) / PAGE_SIZE) + 1;
        }
    }
    applyFilters(false);

    // Deep link: arrive with that item's breakdown already open and scrolled to.
    if (highlightId) {
        const targetCard = document.getElementById('costCard-' + highlightId);
        if (targetCard) {
            toggleRow(highlightId);
            targetCard.classList.add('is-deeplinked');
            targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Images/webfonts can settle after this first measurement, so take
            // the height once more on load rather than leaving a clipped panel.
            window.addEventListener('load', () => {
                if (expandedItemId !== highlightId) return;
                const panel = document.getElementById('costDetailPanel-' + highlightId);
                if (panel && panel.style.maxHeight !== 'none') {
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                }
            });
        }
    }
})();
</script>

<script src="../assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../assets/js/filter-persist.js') ?>"></script>
</body>
</html>
