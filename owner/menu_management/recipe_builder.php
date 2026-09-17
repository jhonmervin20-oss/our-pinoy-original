<?php
/**
 * owner/menu_management/recipe_builder.php
 *
 * Recipe Builder + Packaging Rules for one menu item (?item_id=N). A
 * recipe/packaging rule has no identity of its own outside its menu item,
 * so this is reached from menu_items.php's row actions rather than being
 * a top-level list page. Two independent forms/saves on one page (recipe
 * vs packaging rule), each logging its own recipe_cost_history reason.
 *
 * Ingredient/packaging rows use plain <select> pickers rather than the
 * searchable combobox widget used elsewhere -- rows are added/removed
 * dynamically via JS template-cloning, and re-initializing the combobox
 * widget per clone would add real complexity for little benefit at this
 * app's scale (a few dozen inventory items at most).
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/flash.php';
require_once __DIR__ . '/../../config/back_link.php';   // back_link()
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

$itemId = trim((string)($_GET['item_id'] ?? ''));
if ($itemId === '' || !ctype_digit($itemId)) {
    header('Location: menu_items.php');
    exit;
}
$itemId = (int)$itemId;

$activePage = 'menu_items';
$pageTitle  = 'Recipe builder';
$ownerBase  = '../';

$dbError = null;
$menuItem = null;
$ingredients = [];
$packagingRule = null;
$packagingItems = [];
$eligibleIngredients = [];
$eligiblePackaging = [];
$units = [];
$recipeCostInfo = ['cost' => 0.0, 'has_uncostable_line' => false];
$packagingCostInfo = ['cost' => 0.0, 'has_uncostable_line' => false];
$unitConversions = [];
$fifoCosts = [];
$pricingSettings = [];
$suggestion = ['cost_basis' => 0.0, 'method' => 'markup', 'percentage' => 0.0, 'suggested_price' => 0.0];

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare(
        'SELECT mi.item_id, mi.menu_code, mi.item_name, mi.selling_price, mi.is_vat_exempt,
                mi.available_takeout, c.category_name
         FROM menu_items mi
         LEFT JOIN menu_categories c ON c.category_id = mi.category_id
         WHERE mi.item_id = ?'
    );
    $stmt->execute([$itemId]);
    $menuItem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$menuItem) {
        header('Location: menu_items.php');
        exit;
    }

    $ingredients = $pdo->prepare(
        'SELECT mii.recipe_id, mii.inventory_item_id, mii.quantity_required, mii.recipe_unit_id,
                ii.item_name AS ingredient_name, u.unit_code
         FROM menu_item_ingredients mii
         JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id
         JOIN unit_of_measures u ON u.unit_id = mii.recipe_unit_id
         WHERE mii.menu_item_id = ?
         ORDER BY mii.recipe_id'
    );
    $ingredients->execute([$itemId]);
    $ingredients = $ingredients->fetchAll(PDO::FETCH_ASSOC);

    $ruleStmt = $pdo->prepare('SELECT * FROM packaging_rules WHERE menu_item_id = ?');
    $ruleStmt->execute([$itemId]);
    $packagingRule = $ruleStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($packagingRule) {
        $packagingItems = $pdo->prepare(
            'SELECT pri.rule_item_id, pri.inventory_item_id, pri.quantity, pri.unit_id,
                    ii.item_name AS packaging_name, u.unit_code
             FROM packaging_rule_items pri
             JOIN inventory_items ii ON ii.item_id = pri.inventory_item_id
             JOIN unit_of_measures u ON u.unit_id = pri.unit_id
             WHERE pri.rule_id = ?
             ORDER BY pri.rule_item_id'
        );
        $packagingItems->execute([$packagingRule['rule_id']]);
        $packagingItems = $packagingItems->fetchAll(PDO::FETCH_ASSOC);
    }

    // Ingredient-eligible = active inventory items NOT in the Packaging category.
    $eligibleIngredients = $pdo->query(
        "SELECT i.item_id, i.item_name, i.base_unit_id, u.unit_code
         FROM inventory_items i
         JOIN inventory_categories c ON c.category_id = i.category_id
         JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
         WHERE i.is_active = 1 AND c.category_name != 'Packaging'
         ORDER BY i.item_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Packaging-eligible = active inventory items IN the Packaging category.
    $eligiblePackaging = $pdo->query(
        "SELECT i.item_id, i.item_name, i.base_unit_id, u.unit_code
         FROM inventory_items i
         JOIN inventory_categories c ON c.category_id = i.category_id
         JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
         WHERE i.is_active = 1 AND c.category_name = 'Packaging'
         ORDER BY i.item_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $units = $pdo->query(
        "SELECT unit_id, unit_code, unit_name FROM unit_of_measures WHERE is_active = 1 ORDER BY unit_type, unit_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // FIFO cost per eligible item, for the live client-side cost preview.
    $fifoCosts = [];
    foreach (array_merge($eligibleIngredients, $eligiblePackaging) as $it) {
        $fifoCosts[$it['item_id']] = getCurrentFifoCost($pdo, (int)$it['item_id']);
    }

    $recipeCostInfo    = computeRecipeCost($pdo, $itemId);
    $packagingCostInfo = computePackagingCost($pdo, $itemId);

    $unitConversions = $pdo->query(
        'SELECT from_unit_id, to_unit_id, conversion_factor FROM unit_conversions'
    )->fetchAll(PDO::FETCH_ASSOC);

    $pricingSettings = getPricingSettings($pdo);

    $previewMethod = $_GET['preview_method'] ?? null;
    $previewPercentage = isset($_GET['preview_percentage']) && is_numeric($_GET['preview_percentage'])
        ? (float)$_GET['preview_percentage']
        : null;
    if (!in_array($previewMethod, ['markup', 'margin'], true)) {
        $previewMethod = null;
    }

    $suggestion = computeSuggestedPrice($pdo, $itemId, $previewMethod, $previewPercentage);
} catch (PDOException $e) {
    $dbError = "Couldn't load recipe data. Please refresh this page.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recipe builder | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../assets/css/owner-panel.css') ?>">
<style>
    /* Current vs Suggested on one footing -- same shape as costing.php's cost
       summary, so the two pages read alike. */
    .rb-pricing{ font-variant-numeric:tabular-nums; }
    .rb-pricing .rbp-num{ text-align:right; white-space:nowrap; }
    .rb-pricing .rbp-sub{ color:var(--op-ink-faint); font-size:0.74rem; margin-left:4px; }
    .rb-pricing .rbp-emph td{ font-weight:600; background:var(--op-canvas); }
    /* Food cost % carries the same health colours costing.php uses, against the
       same pricing_settings.target_food_cost_percentage -- so the two pages
       cannot disagree about whether a price is on target. Duplicated here
       rather than shared: owner-panel.css exists as five un-synced copies, so
       these five lines must be kept in step with costing.php by hand.

           Excellent  green   Good  blue   Watch  orange   High  red */
    :root{ --ocf-blue:#3f7ea6; --ocf-orange:#c87a2e; }
    .rb-pricing .rbp-health.is-success{ color:var(--op-success); }
    .rb-pricing .rbp-health.is-active{  color:var(--ocf-blue); }
    .rb-pricing .rbp-health.is-warning{ color:var(--ocf-orange); }
    .rb-pricing .rbp-health.is-danger{  color:var(--op-danger); }
    .rb-pricing .rbp-health.is-neutral{ color:var(--op-ink-faint); }
    /* owner-panel.css only styles :disabled inside .owner-pagination, so the
       Update button sat fully gold and clickable while actually being inert --
       e.g. at margin 100%%, where the price is undefined. Scoped here rather than
       added to owner-panel.css, which exists as five un-synced copies. */
    #updatePriceBtn:disabled{ opacity:0.45; cursor:not-allowed; }
</style>
<style>
    .rb-row{ display:grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap:10px; align-items:end; padding:12px 0; border-bottom:1px solid var(--op-border-soft); }
    .rb-row:last-child{ border-bottom:none; }
    .rb-row-label{ font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:var(--op-ink-faint); margin-bottom:4px; }
    .rb-row-cost{ font-size:0.85rem; color:var(--op-ink-soft); align-self:center; }
    .rb-row-cost strong{ color:var(--op-ink); }
    .rb-empty-note{ color:var(--op-ink-faint); font-size:0.85rem; padding:14px 0; }
    @media (max-width: 900px){ .rb-row{ grid-template-columns: 1fr 1fr; } }
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
            <?php else: ?>

            <div style="margin-bottom:16px;">
                <a href="<?= htmlspecialchars(back_link('menu_items.php')) ?>" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back</a>
            </div>

            

            <?php if ($recipeCostInfo['has_uncostable_line'] || $packagingCostInfo['has_uncostable_line']): ?>
                <div class="owner-alert owner-alert-error" style="margin-bottom:20px;">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span>One or more lines below can't be costed (no conversion path between their unit and the ingredient's base unit) -- costs shown are understated until that's fixed.</span>
                </div>
            <?php endif; ?>

            <!-- Recipe -->
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title"><?= htmlspecialchars($menuItem['item_name']) ?></h2>
                        <span class="owner-card-subtitle"><?= htmlspecialchars($menuItem['menu_code']) ?> &middot; Recipe</span>
                    </div>
                </div>

                <form method="post" action="recipe_save.php" id="recipeForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">

                    <h3 class="owner-form-section-title">Ingredients</h3>
                    <div class="rb-row" style="border-bottom:2px solid var(--op-border);padding-top:0;">
                        <div class="rb-row-label">Ingredient</div>
                        <div class="rb-row-label">Quantity</div>
                        <div class="rb-row-label">Unit</div>
                        <div class="rb-row-label">Cost</div>
                        <div></div>
                    </div>
                    <div id="ingredientRows">
                        <?php if (empty($ingredients)): ?>
                            <p class="rb-empty-note" id="noIngredientsNote">No ingredients yet. Add one below.</p>
                        <?php endif; ?>
                        <?php foreach ($ingredients as $ing): ?>
                            <div class="rb-row" data-ingredient-row>
                                <select class="owner-select" name="ingredient_item_id[]" data-role="item-select" required></select>
                                <input type="number" step="0.001" min="0.001" class="owner-input" name="ingredient_quantity[]" value="<?= htmlspecialchars($ing['quantity_required']) ?>" data-role="qty" required>
                                <select class="owner-select" name="ingredient_unit_id[]" data-role="unit-select" required></select>
                                <div class="rb-row-cost" data-role="cost-display">&mdash;</div>
                                <button type="button" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" data-role="remove-row" aria-label="Remove ingredient"><i class="ph ph-trash" aria-hidden="true"></i></button>
                                <script>window.__prefill = window.__prefill || []; window.__prefill.push({ type: 'ingredient', itemId: <?= (int)$ing['inventory_item_id'] ?>, unitId: <?= (int)$ing['recipe_unit_id'] ?> });</script>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnAddIngredient" style="margin-top:12px;">
                        <i class="ph ph-plus" aria-hidden="true"></i> Add ingredient
                    </button>

                    <div class="owner-modal-footer" style="padding:20px 0 0;border-top:1px solid var(--op-border);margin-top:20px;">
                        <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save recipe</button>
                    </div>
                </form>
            </div>

            <!-- Packaging Rule -->
            <div class="owner-card">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Packaging rule</h2>
                        <?php
                            // menu_items.available_takeout is the flag
                            // cashier/api/create_order.php enforces at the point of
                            // sale: with it off the item CANNOT be ordered for
                            // takeout, so a packaging rule on it can never be
                            // consumed. Saving one is still allowed (the flag may be
                            // flipped on later, and blocking it would strand any rule
                            // already saved), but it is no longer silent.
                            $itemTakeout = (int)($menuItem['available_takeout'] ?? 1) === 1;
                        ?>
                        <span class="owner-card-subtitle"><?= $itemTakeout
                            ? 'Consumed only on Takeout orders &mdash; dine-in never deducts packaging.'
                            : 'This item is set to dine-in only, so packaging saved here will never be consumed.' ?></span>
                    </div>
                </div>

                <?php if (!$itemTakeout && !empty($packagingItems)): ?>
                    <div class="owner-alert owner-alert-error" style="margin-bottom:20px;">
                        <i class="ph ph-warning-circle" aria-hidden="true"></i>
                        <span><strong><?= htmlspecialchars($menuItem['item_name']) ?></strong> is not available for takeout, but <?= count($packagingItems) ?> packaging item<?= count($packagingItems) === 1 ? '' : 's' ?> <?= count($packagingItems) === 1 ? 'is' : 'are' ?> configured below. This packaging is never deducted and never costed into a sale. Either turn on Takeout for this item on the Menu items page, or remove the rows below.</span>
                    </div>
                <?php endif; ?>

                <form method="post" action="packaging_rule_save.php" id="packagingForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">

                    <div class="rb-row" style="border-bottom:2px solid var(--op-border);padding-top:0;">
                        <div class="rb-row-label">Packaging item</div>
                        <div class="rb-row-label">Quantity</div>
                        <div class="rb-row-label">Unit</div>
                        <div class="rb-row-label">Cost</div>
                        <div></div>
                    </div>
                    <div id="packagingRows">
                        <?php if (empty($packagingItems)): ?>
                            <p class="rb-empty-note" id="noPackagingNote">No packaging needed for this item on takeout. Add one below if it needs any.</p>
                        <?php endif; ?>
                        <?php foreach ($packagingItems as $pkg): ?>
                            <div class="rb-row" data-packaging-row>
                                <select class="owner-select" name="packaging_item_id[]" data-role="item-select" required></select>
                                <input type="number" step="0.001" min="0.001" class="owner-input" name="packaging_quantity[]" value="<?= htmlspecialchars($pkg['quantity']) ?>" data-role="qty" required>
                                <select class="owner-select" name="packaging_unit_id[]" data-role="unit-select" required></select>
                                <div class="rb-row-cost" data-role="cost-display">&mdash;</div>
                                <button type="button" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" data-role="remove-row" aria-label="Remove packaging item"><i class="ph ph-trash" aria-hidden="true"></i></button>
                                <script>window.__prefill = window.__prefill || []; window.__prefill.push({ type: 'packaging', itemId: <?= (int)$pkg['inventory_item_id'] ?>, unitId: <?= (int)$pkg['unit_id'] ?> });</script>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnAddPackaging" style="margin-top:12px;">
                        <i class="ph ph-plus" aria-hidden="true"></i> Add packaging item
                    </button>

                    <div class="owner-modal-footer" style="padding:20px 0 0;border-top:1px solid var(--op-border);margin-top:20px;">
                        <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save packaging rule</button>
                    </div>
                </form>
            </div>

            <!-- Pricing -->
            <div class="owner-card" style="margin-top:20px;">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Pricing</h2>
                         </div>
                </div>

                <?php
                $priceDiff = round($suggestion['suggested_price'] - (float)$menuItem['selling_price'], 2);
                // Same two names costing.php uses for the same two figures.
                $costBasisLabel = $pricingSettings['packaging_fee_policy'] === 'included'
                    ? 'Total cost <span class="rbp-sub">recipe + packaging</span>'
                    : 'Recipe cost <span class="rbp-sub">ingredients only</span>';
                $profitAtSuggested = round($suggestion['suggested_price'] - $suggestion['cost_basis'], 2);
                ?>

                <div class="owner-alert owner-alert-error" id="priceSuggestionAlert" style="margin-bottom:20px;<?= abs($priceDiff) >= 0.01 ? '' : 'display:none;' ?>">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span id="priceSuggestionText">
                        Suggested price is &#8369;<?= number_format($suggestion['suggested_price'], 2) ?>, <?= $priceDiff > 0 ? 'higher' : 'lower' ?> than the current &#8369;<?= number_format((float)$menuItem['selling_price'], 2) ?>.
                    </span>
                    <button type="button" class="owner-alert-dismiss" aria-label="Dismiss" onclick="this.closest('.owner-alert').remove()"><i class="ph ph-x" aria-hidden="true"></i></button>
                </div>

                <?php
                    // Everything below is stated on ONE footing. The shelf price is
                    // VAT-inclusive; markup/margin are ratios on the VAT-exclusive net,
                    // the same basis costing.php and menu_item_costing use. Mixing the
                    // two is what made the old panel's "Difference" meaningless.
                    $curGross  = (float)$menuItem['selling_price'];
                    $vatRate   = (float)$suggestion['vat_rate'];
                    $curNet    = $suggestion['vat_inclusive']
                        ? round($curGross - ($curGross * $vatRate / (100 + $vatRate)), 2)
                        : $curGross;
                    $sugGross  = (float)$suggestion['suggested_price'];
                    $sugNet    = (float)$suggestion['suggested_net'];
                    $costBasis = (float)$suggestion['cost_basis'];

                    // Derived by subtraction rather than recomputed as
                    // gross * rate/(100+rate) -- both give 19.39 here, but only
                    // subtraction GUARANTEES the column foots (price - VAT = net)
                    // at every price, instead of drifting a centavo apart on the
                    // prices where the two roundings disagree.
                    $curVat    = round($curGross - $curNet, 2);
                    $sugVat    = round($sugGross - $sugNet, 2);

                    $curProfit = round($curNet - $costBasis, 2);
                    $sugProfit = round($sugNet - $costBasis, 2);

                    // Food cost % -- cost as a share of the net price. It is the
                    // exact complement of Margin % (cost + profit = net, so
                    // food cost % = 100 - margin %), but it is the figure the
                    // restaurant actually sets a target against, and this panel
                    // is where the price gets decided. Leaving it to be inferred
                    // from Margin % meant pricing toward a 30% food cost target
                    // on a screen that never showed food cost.
                    $targetFoodCost = (float)($pricingSettings['target_food_cost_percentage'] ?? 30.0);
                    $curFoodCost = $curNet > 0 ? round($costBasis / $curNet * 100, 2) : 0.0;
                    $sugFoodCost = $sugNet > 0 ? round($costBasis / $sugNet * 100, 2) : 0.0;
                    $pct = fn($num, $den) => $den > 0 ? round($num / $den * 100, 2) : 0.0;
                ?>

                <div class="owner-table-wrap" style="margin-bottom:16px;">
                    <table class="owner-table rb-pricing">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th class="rbp-num">Current</th>
                                <th class="rbp-num">Suggested</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="rbp-emph">
                                <td>Selling price <span class="rbp-sub"><?= $suggestion['vat_inclusive'] ? 'incl. VAT' : 'no VAT' ?></span></td>
                                <td class="rbp-num">&#8369;<?= number_format($curGross, 2) ?></td>
                                <td class="rbp-num" id="sugGrossValue"><strong>&#8369;<?= number_format($sugGross, 2) ?></strong></td>
                            </tr>
                            <?php if ($suggestion['vat_inclusive']): ?>
                            <tr>
                                <td>VAT <span class="rbp-sub"><?= rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') ?>%</span></td>
                                <td class="rbp-num">&#8369;<?= number_format($curVat, 2) ?></td>
                                <td class="rbp-num" id="sugVatValue">&#8369;<?= number_format($sugVat, 2) ?></td>
                            </tr>
                            <tr>
                                <td>Net price <span class="rbp-sub">excl. VAT</span></td>
                                <td class="rbp-num">&#8369;<?= number_format($curNet, 2) ?></td>
                                <td class="rbp-num" id="sugNetValue">&#8369;<?= number_format($sugNet, 2) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <?php // Not escaped: $costBasisLabel is a fixed literal chosen from two
                                      // hard-coded branches above, carrying the same .rbp-sub qualifier
                                      // markup every other row uses. No user or DB data reaches it. ?>
                                <td><?= $costBasisLabel ?></td>
                                <td class="rbp-num">&#8369;<?= number_format($costBasis, 2) ?></td>
                                <td class="rbp-num">&#8369;<?= number_format($costBasis, 2) ?></td>
                            </tr>
                            <tr class="rbp-emph">
                                <td>Profit <span class="rbp-sub">net price &minus; cost</span></td>
                                <td class="rbp-num">&#8369;<?= number_format($curProfit, 2) ?></td>
                                <td class="rbp-num" id="profitValue">&#8369;<?= number_format($sugProfit, 2) ?></td>
                            </tr>
                            <tr>
                                <td>Food cost % <span class="rbp-sub">cost &divide; net price &middot; target &le;<?= rtrim(rtrim(number_format($targetFoodCost, 2, '.', ''), '0'), '.') ?>%</span></td>
                                <td class="rbp-num"><span class="rbp-health <?= foodCostHealthClass($curFoodCost, $targetFoodCost) ?>"><?= number_format($curFoodCost, 2) ?>%</span></td>
                                <td class="rbp-num"><span class="rbp-health <?= foodCostHealthClass($sugFoodCost, $targetFoodCost) ?>" id="sugFoodCostValue"><?= number_format($sugFoodCost, 2) ?>%</span></td>
                            </tr>
                            <tr>
                                <td>Margin % <span class="rbp-sub">profit &divide; net price</span></td>
                                <td class="rbp-num"><?= number_format($pct($curProfit, $curNet), 2) ?>%</td>
                                <td class="rbp-num" id="sugMarginValue"><?= number_format($pct($sugProfit, $sugNet), 2) ?>%</td>
                            </tr>
                            <tr>
                                <td>Markup % <span class="rbp-sub">profit &divide; cost</span></td>
                                <td class="rbp-num"><?= number_format($pct($curProfit, $costBasis), 2) ?>%</td>
                                <td class="rbp-num" id="sugMarkupValue"><?= number_format($pct($sugProfit, $costBasis), 2) ?>%</td>
                            </tr>
                            <tr>
                                <td>Difference <span class="rbp-sub">suggested &minus; current</span></td>
                                <td class="rbp-num">&mdash;</td>
                                <td class="rbp-num" id="priceDiffValue"><?= $priceDiff >= 0 ? '+' : '&minus;' ?>&#8369;<?= number_format(abs($priceDiff), 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="owner-form-grid" style="align-items:start;margin-bottom:20px;">
                    <div class="owner-form-group">
                        <label for="preview_method">Preview method</label>
                        <select id="preview_method" name="preview_method" class="owner-select">
                            <option value="markup" <?= $suggestion['method'] === 'markup' ? 'selected' : '' ?>>Markup %</option>
                            <option value="margin" <?= $suggestion['method'] === 'margin' ? 'selected' : '' ?>>Margin %</option>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="preview_percentage">Percentage</label>
                        <input type="number" id="preview_percentage" name="preview_percentage" class="owner-input" step="0.01" min="0" value="<?= htmlspecialchars($suggestion['percentage']) ?>">
                        <span class="owner-form-error" id="marginPercentageError"></span>
                    </div>
                </div>

                <form method="post" action="menu_item_price_save.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
                    <input type="hidden" id="newPriceInput" name="new_price" value="<?= htmlspecialchars($suggestion['suggested_price']) ?>">
                    <button type="submit" id="updatePriceBtn" class="owner-btn owner-btn-primary" <?= abs($priceDiff) < 0.01 ? 'disabled' : '' ?>>
                        <i class="ph ph-check" aria-hidden="true"></i> Update selling price to &#8369;<?= number_format($suggestion['suggested_price'], 2) ?>
                    </button>
                </form>
            </div>

            <?php endif; ?>

        </main>

    </div>

</div>

<script>
(function () {
    const ELIGIBLE_INGREDIENTS = <?= json_encode($eligibleIngredients) ?>;
    const ELIGIBLE_PACKAGING   = <?= json_encode($eligiblePackaging) ?>;
    const UNITS                = <?= json_encode($units) ?>;
    const FIFO_COSTS           = <?= json_encode($fifoCosts) ?>;
    const UNIT_CONVERSIONS     = <?= json_encode($unitConversions) ?>;
    const PREFILL = window.__prefill || [];

    const COST_BASIS         = <?= json_encode((float)$suggestion['cost_basis']) ?>;
    const ROUNDING_INCREMENT = <?= json_encode((float)$pricingSettings['rounding_increment']) ?>;
    // Markup % and margin % are different scales -- 100% markup is a sane default,
    // 100% margin is undefined. Carrying the old number across a method switch
    // is what produced a 'suggested' price in the hundreds of thousands.
    const DEFAULT_MARKUP_PCT = <?= json_encode((float)$pricingSettings['default_markup_percentage']) ?>;
    const DEFAULT_MARGIN_PCT = <?= json_encode((float)$pricingSettings['default_margin_percentage']) ?>;
    const CURRENT_PRICE      = <?= json_encode((float)$menuItem['selling_price']) ?>;
    // The live preview has to apply VAT exactly as computeSuggestedPrice() does,
    // or typing in the percentage box would quietly disagree with the figures PHP
    // rendered a moment earlier.
    const VAT_RATE           = <?= json_encode((float)$suggestion['vat_rate']) ?>;
    const VAT_INCLUSIVE      = <?= json_encode((bool)$suggestion['vat_inclusive']) ?>;
    const TARGET_FOOD_COST   = <?= json_encode($targetFoodCost) ?>;

    function unitOptionsHtml(selectedId) {
        return UNITS.map(u => `<option value="${u.unit_id}" ${String(u.unit_id) === String(selectedId) ? 'selected' : ''}>${u.unit_code}</option>`).join('');
    }

    function itemOptionsHtml(catalog, selectedId) {
        let html = '<option value="">Select item&hellip;</option>';
        html += catalog.map(it => `<option value="${it.item_id}" data-base-unit="${it.base_unit_id}" ${String(it.item_id) === String(selectedId) ? 'selected' : ''}>${it.item_name}</option>`).join('');
        return html;
    }

    function convertQty(qty, fromUnitId, toUnitId) {
        if (String(fromUnitId) === String(toUnitId)) return qty;
        const conv = UNIT_CONVERSIONS.find(c => String(c.from_unit_id) === String(fromUnitId) && String(c.to_unit_id) === String(toUnitId));
        return conv ? qty * parseFloat(conv.conversion_factor) : null;
    }

    function updateRowCost(row, catalog) {
        const itemSelect = row.querySelector('[data-role="item-select"]');
        const unitSelect = row.querySelector('[data-role="unit-select"]');
        const qtyInput = row.querySelector('[data-role="qty"]');
        const costDisplay = row.querySelector('[data-role="cost-display"]');

        const itemId = itemSelect.value;
        const unitId = unitSelect.value;
        const qty = parseFloat(qtyInput.value) || 0;

        if (!itemId || !unitId || !qty) { costDisplay.textContent = '—'; return; }

        const item = catalog.find(it => String(it.item_id) === String(itemId));
        if (!item) { costDisplay.textContent = '—'; return; }

        const converted = convertQty(qty, unitId, item.base_unit_id);
        const fifo = FIFO_COSTS[itemId];
        if (converted === null || !fifo) { costDisplay.textContent = 'No conversion path'; return; }

        const lineCost = converted * fifo.unit_cost;
        costDisplay.innerHTML = `<strong>&#8369;${lineCost.toFixed(2)}</strong>` + (fifo.is_estimate ? ' <span title="No live stock -- estimated from last purchase cost">*</span>' : '');
    }

    function wireRow(row, catalog, prefillItemId, prefillUnitId) {
        const itemSelect = row.querySelector('[data-role="item-select"]');
        const unitSelect = row.querySelector('[data-role="unit-select"]');
        const qtyInput = row.querySelector('[data-role="qty"]');
        const removeBtn = row.querySelector('[data-role="remove-row"]');

        itemSelect.innerHTML = itemOptionsHtml(catalog, prefillItemId);
        unitSelect.innerHTML = unitOptionsHtml(prefillUnitId);

        itemSelect.addEventListener('change', () => updateRowCost(row, catalog));
        unitSelect.addEventListener('change', () => updateRowCost(row, catalog));
        qtyInput.addEventListener('input', () => updateRowCost(row, catalog));
        removeBtn.addEventListener('click', () => row.remove());

        updateRowCost(row, catalog);
    }

    // -- Existing (server-rendered) rows: populate their selects + wire events --
    let prefillIndex = 0;
    document.querySelectorAll('[data-ingredient-row]').forEach((row) => {
        const p = PREFILL[prefillIndex++];
        wireRow(row, ELIGIBLE_INGREDIENTS, p ? p.itemId : '', p ? p.unitId : '');
    });
    document.querySelectorAll('[data-packaging-row]').forEach((row) => {
        const p = PREFILL[prefillIndex++];
        wireRow(row, ELIGIBLE_PACKAGING, p ? p.itemId : '', p ? p.unitId : '');
    });

    // -- Add row buttons -----------------------------------------------------
    function addRow(containerId, catalog, rowAttr, namePrefix, emptyNoteId) {
        const container = document.getElementById(containerId);
        const emptyNote = document.getElementById(emptyNoteId);
        if (emptyNote) emptyNote.remove();

        const row = document.createElement('div');
        row.className = 'rb-row';
        row.setAttribute(rowAttr, '');
        row.innerHTML = `
            <select class="owner-select" name="${namePrefix}_item_id[]" data-role="item-select" required></select>
            <input type="number" step="0.001" min="0.001" class="owner-input" name="${namePrefix}_quantity[]" value="1" data-role="qty" required>
            <select class="owner-select" name="${namePrefix}_unit_id[]" data-role="unit-select" required></select>
            <div class="rb-row-cost" data-role="cost-display">&mdash;</div>
            <button type="button" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" data-role="remove-row" aria-label="Remove"><i class="ph ph-trash" aria-hidden="true"></i></button>
        `;
        container.appendChild(row);
        wireRow(row, catalog, '', '');
    }

    const addIngredientBtn = document.getElementById('btnAddIngredient');
    if (addIngredientBtn) {
        addIngredientBtn.addEventListener('click', () => addRow('ingredientRows', ELIGIBLE_INGREDIENTS, 'data-ingredient-row', 'ingredient', 'noIngredientsNote'));
    }
    const addPackagingBtn = document.getElementById('btnAddPackaging');
    if (addPackagingBtn) {
        addPackagingBtn.addEventListener('click', () => addRow('packagingRows', ELIGIBLE_PACKAGING, 'data-packaging-row', 'packaging', 'noPackagingNote'));
    }

    // -- Live pricing preview -------------------------------------------------
    function fmtPeso(n) {
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function roundToNearest(value, increment) {
        if (!increment || increment <= 0) return Math.round(value * 100) / 100;
        return Math.round(Math.round(value / increment) * increment * 100) / 100;
    }

    function recomputeSuggestedPrice() {
        const methodEl = document.getElementById('preview_method');
        const pctEl = document.getElementById('preview_percentage');
        if (!methodEl || !pctEl) return;

        const method = methodEl.value;
        const pct = parseFloat(pctEl.value) || 0;

        let raw;
        const isInvalidMargin = method === 'margin' && pct >= 100;
        if (method === 'margin') {
            const p = Math.min(pct, 99.99);
            raw = p < 100 ? COST_BASIS / (1 - p / 100) : COST_BASIS;
        } else {
            raw = COST_BASIS * (1 + pct / 100);
        }

        const marginError = document.getElementById('marginPercentageError');
        if (marginError) {
            marginError.textContent = isInvalidMargin
                ? 'Margin % must be less than 100 — at 100% or higher the price calculation is undefined.'
                : '';
        }
        pctEl.classList.toggle('is-invalid', isInvalidMargin);

        // raw is a NET price (markup/margin apply to cost); the shelf price adds
        // VAT back, then rounds, mirroring computeSuggestedPrice().
        const gross = VAT_INCLUSIVE ? raw * (1 + VAT_RATE / 100) : raw;
        const suggested = roundToNearest(gross, ROUNDING_INCREMENT);
        const suggestedNet = VAT_INCLUSIVE
            ? Math.round((suggested - (suggested * VAT_RATE / (100 + VAT_RATE))) * 100) / 100
            : suggested;

        const diff = Math.round((suggested - CURRENT_PRICE) * 100) / 100;
        const profit = Math.round((suggestedNet - COST_BASIS) * 100) / 100;
        // Named ratioPct, not pct: 'pct' is already the entered percentage in this
        // same function, and redeclaring a const is a SyntaxError that kills the WHOLE
        // inline script -- every handler on the page, not just this preview.
        // 2 decimals, matching the PHP-rendered Margin %/Markup % this replaces
        // when the preview controls change -- otherwise the figures would gain
        // or lose a decimal the moment you touched the percentage box.
        const ratioPct = (n, d) => (d > 0 ? (Math.round((n / d) * 10000) / 100).toFixed(2) : '0.00');

        const setText = (id, text) => {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        };

        const grossEl = document.getElementById('sugGrossValue');
        if (grossEl) grossEl.innerHTML = `<strong>${fmtPeso(suggested)}</strong>`;

        setText('sugVatValue', fmtPeso(Math.round((suggested - suggestedNet) * 100) / 100));
        setText('sugNetValue', fmtPeso(suggestedNet));
        setText('profitValue', fmtPeso(profit));
        // Mirrors foodCostHealthClass() in menu_functions.php exactly -- same
        // band offsets (target, +8, +15), so the live preview cannot colour a
        // price differently from how costing.php will once it is saved.
        const foodCost = suggestedNet > 0 ? (COST_BASIS / suggestedNet) * 100 : 0;
        const foodCostEl = document.getElementById('sugFoodCostValue');
        if (foodCostEl) {
            foodCostEl.textContent = ratioPct(COST_BASIS, suggestedNet) + '%';
            const band = foodCost <= 0 ? 'is-neutral'
                : foodCost <= TARGET_FOOD_COST ? 'is-success'
                : foodCost <= TARGET_FOOD_COST + 8 ? 'is-active'
                : foodCost <= TARGET_FOOD_COST + 15 ? 'is-warning'
                : 'is-danger';
            foodCostEl.className = 'rbp-health ' + band;
        }

        setText('sugMarginValue', ratioPct(profit, suggestedNet) + '%');
        setText('sugMarkupValue', ratioPct(profit, COST_BASIS) + '%');
        setText('priceDiffValue', (diff >= 0 ? '+' : '\u2212') + fmtPeso(Math.abs(diff)));

        const alertBox = document.getElementById('priceSuggestionAlert');
        if (alertBox) {
            if (Math.abs(diff) >= 0.01) {
                const textEl = document.getElementById('priceSuggestionText');
                if (textEl) {
                    textEl.textContent = `Suggested price is ${fmtPeso(suggested)}, ${diff > 0 ? 'higher' : 'lower'} than the current ${fmtPeso(CURRENT_PRICE)}.`;
                }
                alertBox.style.display = '';
            } else {
                alertBox.style.display = 'none';
            }
        }

        const newPriceInput = document.getElementById('newPriceInput');
        if (newPriceInput) newPriceInput.value = suggested.toFixed(2);

        const btn = document.getElementById('updatePriceBtn');
        if (btn) {
            btn.disabled = Math.abs(diff) < 0.01 || isInvalidMargin;
            btn.innerHTML = `<i class="ph ph-check" aria-hidden="true"></i> Update selling price to ${fmtPeso(suggested)}`;
        }
    }

    const previewMethodEl = document.getElementById('preview_method');
    const previewPercentageEl = document.getElementById('preview_percentage');
    if (previewMethodEl) {
        previewMethodEl.addEventListener('change', function () {
            // Snap the percentage to the default configured for the newly chosen
            // method (Settings > Pricing), rather than leaving the other method's
            // number behind where it means something completely different.
            if (previewPercentageEl) {
                previewPercentageEl.value = previewMethodEl.value === 'margin'
                    ? DEFAULT_MARGIN_PCT
                    : DEFAULT_MARKUP_PCT;
            }
            recomputeSuggestedPrice();
        });
    }
    if (previewPercentageEl) previewPercentageEl.addEventListener('input', recomputeSuggestedPrice);
})();
</script>

</body>
</html>
