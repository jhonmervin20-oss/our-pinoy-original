<?php
/**
 * owner/menu_management/menu_items.php
 *
 * Menu Management: dashboard KPIs, filterable list, Add/Edit modal (image
 * upload + searchable category combobox), Archive/Preview actions.
 * Follows owner/suppliers.php's page shell.
 *
 * Recipe Cost / Packaging Cost / margin figures shown here read from the
 * menu_item_costing cache -- render as "--" until a recalculation has run
 * for that item, rather than 0.00, since 0 would misleadingly imply a free
 * item.
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

$activePage = 'menu_items';
$pageTitle  = 'Menu items';
$ownerBase  = '../';

$items            = [];
$categories       = [];
$allCategories    = [];
$totalItems       = 0;
$activeItems      = 0;
$avgPrice         = 0.0;
$suggestedCode    = 'MENU-0001';
$dbError          = null;

try {
    $pdo = Database::getInstance()->getConnection();

    // For the Add/Edit item combobox.
    $categories = $pdo->query(
        "SELECT category_id, category_name FROM menu_categories ORDER BY category_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Same list plus a usage count, for the Manage categories modal.
    $allCategories = $pdo->query(
        "SELECT c.category_id, c.category_name,
                COUNT(mi.item_id) AS menu_count
         FROM menu_categories c
         LEFT JOIN menu_items mi ON mi.category_id = c.category_id AND mi.is_active = 1
         GROUP BY c.category_id
         ORDER BY c.category_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $items = $pdo->query(
        "SELECT mi.item_id, mi.menu_code, mi.category_id, mi.item_name, mi.description,
                mi.image_url, mi.selling_price, mi.is_vat_exempt, mi.is_available,
                mi.available_dine_in, mi.available_takeout, mi.packaging_not_required, mi.is_active,
                c.category_name,
                mc.food_cost_percentage_dine_in, mc.food_cost_percentage_takeout, mc.gross_margin_percentage,
                mc.dine_in_cost, mc.takeout_cost, mc.computed_at,
                EXISTS(
                    SELECT 1 FROM packaging_rules pr WHERE pr.menu_item_id = mi.item_id AND pr.is_active = 1
                ) AS has_packaging_rule
         FROM menu_items mi
         LEFT JOIN menu_categories c ON c.category_id = mi.category_id
         LEFT JOIN menu_item_costing mc ON mc.menu_item_id = mi.item_id
         ORDER BY mi.item_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $totalItems    = count($items);
    $activeItems   = count(array_filter($items, fn($i) => (int)$i['is_active'] === 1));
    $avgPrice      = $totalItems > 0 ? array_sum(array_column($items, 'selling_price')) / $totalItems : 0.0;

    $suggestedCode  = generateMenuCode($pdo);
} catch (PDOException $e) {
    $dbError = "Couldn't load menu item data. Please refresh this page.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menu items | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../assets/css/owner-panel.css') ?>">
<style>
    .mm-thumb{ width:40px; height:40px; border-radius:8px; object-fit:cover; background:var(--op-canvas); border:1px solid var(--op-border); flex-shrink:0; }
    .mm-thumb-placeholder{ width:40px; height:40px; border-radius:8px; background:var(--op-canvas); border:1px solid var(--op-border); display:flex; align-items:center; justify-content:center; color:var(--op-ink-faint); flex-shrink:0; }
    .mm-item-cell{ display:flex; align-items:center; gap:10px; }
    .mm-item-name{ font-weight:600; color:var(--op-ink); }
    .mm-item-code{ font-size:0.74rem; color:var(--op-ink-faint); }
    .mm-channels{ display:flex; gap:6px; flex-wrap:wrap; }
    .mm-channel-label{ font-size:0.72rem; font-weight:600; padding:2px 8px; border-radius:20px; border:1px solid var(--op-border); }
    .mm-channel-label.is-off{ color:var(--op-ink-faint); opacity:0.6; }
    .mm-channel-label.is-on{ color:var(--op-gold); border-color:var(--op-gold); }
    .mm-image-upload{ display:flex; align-items:center; gap:14px; }
    .mm-image-preview{ width:72px; height:72px; border-radius:10px; object-fit:cover; background:var(--op-canvas); border:1px solid var(--op-border); }
    .mm-image-preview-placeholder{ width:72px; height:72px; border-radius:10px; background:var(--op-canvas); border:1px dashed var(--op-border); display:flex; align-items:center; justify-content:center; color:var(--op-ink-faint); font-size:1.4rem; }
    .mm-image-preview-placeholder[hidden]{ display:none; }
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

            <?php /* Page actions + filters sit in their own card above the
                     table -- same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="itemFilterSearch" placeholder="Search menu items&hellip;" autocomplete="off">
                    </div>
                    <select id="itemFilterCategory" class="owner-select">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="itemFilterStatus" class="owner-select">
                        <option value="active" selected>Active</option>
                        <option value="inactive">Archived</option>
                        <option value="">All statuses</option>
                    </select>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnClearItemFilters">Clear</button>
                    <div style="margin-left:auto;display:flex;gap:8px;">
                        <button type="button" class="owner-btn owner-btn-secondary" id="btnOpenManageCategories">
                            <i class="ph ph-tag" aria-hidden="true"></i> Manage categories
                        </button>
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddItem">
                            <i class="ph ph-plus-circle" aria-hidden="true"></i> Add menu item
                        </button>
                    </div>
                </div>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Recipe cost</th>
                                <th>Availability</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="7" class="owner-table-empty">No menu items yet. Add your first one above.</td></tr>
                            <?php else: ?>
                                <?php foreach ($items as $it):
                                    $isActive = (int)$it['is_active'] === 1;
                                    $dineIn = (int)$it['available_dine_in'] === 1;
                                    $takeout = (int)$it['available_takeout'] === 1;
                                    $packagingNotRequired = (int)$it['packaging_not_required'] === 1;
                                    $hasPackagingRule = (int)$it['has_packaging_rule'] === 1;
                                    // Informational only -- never blocks a sale. Shown only for a
                                    // takeout-enabled item that has neither a packaging rule NOR
                                    // been explicitly marked as not needing one (e.g. canned drinks),
                                    // so it never nags about items that genuinely need no packaging.
                                    $needsPackagingReview = $takeout && !$packagingNotRequired && !$hasPackagingRule;
                                    $searchKey = strtolower($it['item_name'] . ' ' . $it['menu_code'] . ' ' . ($it['category_name'] ?? ''));
                                    $hasCostData = $it['food_cost_percentage_dine_in'] !== null;
                                    $foodCostDisplay = $hasCostData
                                        ? '&#8369;' . number_format((float)$it['dine_in_cost'], 2)
                                        : '&mdash;';
                                    $itemData = [
                                        'item_id'            => $it['item_id'],
                                        'menu_code'           => $it['menu_code'],
                                        'category_id'          => $it['category_id'],
                                        'category_name'         => $it['category_name'],
                                        'item_name'               => $it['item_name'],
                                        'description'               => $it['description'],
                                        'image_url'                       => $it['image_url'],
                                        'selling_price'                   => number_format((float)$it['selling_price'], 2, '.', ''),
                                        'is_vat_exempt'                     => (int)$it['is_vat_exempt'] === 1 ? 1 : 0,
                                        'is_available'                        => (int)$it['is_available'] === 1 ? 1 : 0,
                                        'available_dine_in'                      => $dineIn ? 1 : 0,
                                        'available_takeout'                        => $takeout ? 1 : 0,
                                        'packaging_not_required'                     => $packagingNotRequired ? 1 : 0,
                                        'is_active'                                  => $isActive ? 1 : 0,
                                    ];
                                ?>
                                <tr data-name="<?= htmlspecialchars($searchKey) ?>" data-category="<?= (int)($it['category_id'] ?? 0) ?>" data-status="<?= $isActive ? 'active' : 'inactive' ?>">
                                    <td>
                                        <div class="mm-item-cell">
                                            <?php if ($it['image_url']): ?>
                                                <img class="mm-thumb" src="<?= htmlspecialchars($ownerBase . $it['image_url']) ?>" alt="">
                                            <?php else: ?>
                                                <span class="mm-thumb-placeholder"><i class="ph ph-image" aria-hidden="true"></i></span>
                                            <?php endif; ?>
                                            <div>
                                                <div class="mm-item-name"><?= htmlspecialchars($it['item_name']) ?></div>
                                                <div class="mm-item-code"><?= htmlspecialchars($it['menu_code']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= $it['category_name'] !== null ? htmlspecialchars($it['category_name']) : '&mdash;' ?></td>
                                    <td>&#8369;<?= number_format((float)$it['selling_price'], 2) ?></td>
                                    <td>
                                        <?php // Plain figure, matching Selling price beside it. It used to be a
                                              // colour-coded .owner-status-pill, which read as a STATUS -- amber or red
                                              // behind a peso amount looks like a warning about that amount, when the
                                              // colour actually encoded food-cost health, a different number entirely.
                                              // The Costing page is where that health is judged, and it still colours it
                                              // there; this column just reports what the dish costs. ?>
                                        <?php if ($hasCostData): ?>
                                            <a href="costing.php?highlight=<?= (int)$it['item_id'] ?>" style="color:inherit;text-decoration:none;" title="View cost breakdown"><?= $foodCostDisplay ?></a>
                                            <?php if ($it['computed_at']): ?>
                                                <div style="font-size:0.7rem;color:var(--op-ink-faint);margin-top:2px;">as of <?= htmlspecialchars(date('M j', strtotime($it['computed_at']))) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?= $foodCostDisplay ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="mm-channels">
                                            <span class="mm-channel-label <?= $dineIn ? 'is-on' : 'is-off' ?>">Dine-in</span>
                                            <span class="mm-channel-label <?= $takeout ? 'is-on' : 'is-off' ?>">Takeout</span>
                                        </div>
                                        <?php if ($needsPackagingReview): ?>
                                            <a href="recipe_builder.php?item_id=<?= (int)$it['item_id'] ?>" class="owner-status-pill is-warning" style="margin-top:6px;text-decoration:none;" title="Add a packaging rule, or mark this item as not needing one in Edit &rarr; Availability.">
                                                <i class="ph ph-warning" aria-hidden="true"></i> No packaging set
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="owner-status-pill is-active"><i class="ph ph-check" aria-hidden="true"></i> Active</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-inactive">Archived</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <a href="recipe_builder.php?item_id=<?= (int)$it['item_id'] ?>" class="owner-btn owner-btn-secondary owner-btn-sm" aria-label="Recipe &amp; packaging">
                                                Recipe
                                            </a>
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-preview-item" data-preview-item="<?= (int)$it['item_id'] ?>" aria-label="Preview item">
                                                <i class="ph ph-eye" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-item"
                                                aria-label="Edit item" data-item='<?= htmlspecialchars(json_encode($itemData), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="POST" action="menu_item_toggle_status.php" style="display:inline;" <?php if ($isActive): ?>data-confirm="Archive <?= htmlspecialchars($it['item_name']) ?>? It will stay in history but won't be selectable on the POS."<?php endif; ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                                                <?php if ($isActive): ?>
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Archive item">
                                                        <i class="ph ph-archive" aria-hidden="true"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Reactivate item">
                                                        <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="itemNoMatchRow" class="owner-table-empty-row" hidden>
                                    <td colspan="7" class="owner-table-empty">No menu items match your filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-pagination" id="itemPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="itemPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="itemPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="itemPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- Add / edit menu item modal -->
<div class="owner-modal-backdrop" id="addItemBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="addItemTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="addItemTitle">Add menu item</h2>
            <button type="button" class="owner-modal-close" id="btnCloseAddItem" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="post" action="menu_item_save.php" id="addItemForm" enctype="multipart/form-data" novalidate>
            <div class="owner-modal-body">

                <?= csrf_field() ?>
                <input type="hidden" name="item_id" id="itemFormItemId" value="">
                <input type="hidden" name="existing_image_url" id="itemFormExistingImage" value="">

                <div class="owner-form-note owner-alert owner-alert-error" id="addItemFormAlert" hidden>
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span>Please fix the highlighted fields before saving.</span>
                </div>

                <!-- Image -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Menu image <span class="owner-form-optional">(optional)</span></h3>
                    <div class="mm-image-upload">
                        <img class="mm-image-preview" id="imagePreviewImg" src="" alt="" hidden>
                        <span class="mm-image-preview-placeholder" id="imagePreviewPlaceholder"><i class="ph ph-image" aria-hidden="true"></i></span>
                        <div>
                            <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" class="owner-input">
                            <span class="owner-form-hint">JPG, PNG, or WEBP. Max 2MB.</span>
                        </div>
                    </div>
                </div>

                <!-- Item details -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Item details</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="menu_code">Menu code <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="text" id="menu_code" name="menu_code" class="owner-input" placeholder="MENU-0001" required>
                            <span class="owner-form-hint">Auto-suggested, but you can change it.</span>
                            <span class="owner-form-error" data-error-for="menu_code"></span>
                        </div>
                        <div class="owner-form-group" data-combobox>
                            <label for="category_search">Category <span class="owner-required" aria-hidden="true">*</span></label>
                            <div class="owner-combobox-control">
                                <input type="text" id="category_search" class="owner-input owner-combobox-input" placeholder="Search category&hellip;" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="category_listbox" data-options='<?= comboboxOptions($categories, "category_id", "category_name") ?>'>
                                <input type="hidden" name="category_id" id="category_id" required>
                                <i class="ph ph-caret-down owner-combobox-caret" aria-hidden="true"></i>
                                <ul class="owner-combobox-panel" id="category_listbox" role="listbox"></ul>
                            </div>
                            
                        </div>
                        <div class="owner-form-group owner-form-group-full">
                            <label for="item_name">Item name <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="text" id="item_name" name="item_name" class="owner-input" placeholder="e.g. Chicken Inasal" required>
                            <span class="owner-form-error" data-error-for="item_name"></span>
                        </div>
                        <div class="owner-form-group owner-form-group-full">
                            <label for="description">Description <span class="owner-form-optional">(optional)</span></label>
                            <textarea id="description" name="description" class="owner-input" rows="2" placeholder="Short description shown to customers&hellip;"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Pricing & tax -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Pricing &amp; tax</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="selling_price">Selling price <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="number" step="0.01" min="0" id="selling_price" name="selling_price" class="owner-input" placeholder="0.00" required>
                            <span class="owner-form-error" data-error-for="selling_price"></span>
                        </div>
                    </div>
                    <label class="owner-checkbox-row" for="is_vat_exempt" style="margin-top:18px;">
                        <input type="checkbox" id="is_vat_exempt" name="is_vat_exempt" value="1" class="owner-checkbox-input">
                        <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                        <span class="owner-checkbox-text">
                            <span class="owner-checkbox-label">VAT exempt</span>
                            <span class="owner-checkbox-desc">Follows the restaurant's active tax settings unless checked.</span>
                        </span>
                    </label>
                </div>

                <!-- Availability -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Availability</h3>
                    <label class="owner-checkbox-row" for="available_dine_in">
                        <input type="checkbox" id="available_dine_in" name="available_dine_in" value="1" class="owner-checkbox-input" checked>
                        <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                        <span class="owner-checkbox-text">
                            <span class="owner-checkbox-label">Available for Dine-in</span>
                        </span>
                    </label>
                    <label class="owner-checkbox-row" for="available_takeout" style="margin-top:10px;">
                        <input type="checkbox" id="available_takeout" name="available_takeout" value="1" class="owner-checkbox-input" checked>
                        <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                        <span class="owner-checkbox-text">
                            <span class="owner-checkbox-label">Available for Takeout</span>
                        </span>
                    </label>
                    <label class="owner-checkbox-row" for="packaging_not_required" id="packagingNotRequiredRow" style="margin-top:10px;margin-left:28px;">
                        <input type="checkbox" id="packaging_not_required" name="packaging_not_required" value="1" class="owner-checkbox-input">
                        <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                        <span class="owner-checkbox-text">
                            <span class="owner-checkbox-label">This item doesn't need takeout packaging</span>
                            <span class="owner-checkbox-desc">Check this for items like canned drinks that go out as-is. Leave unchecked if it should have a packaging rule (Recipe &amp; packaging page) -- unchecked, un-ruled takeout items are flagged for review on this list.</span>
                        </span>
                    </label>
                    <label class="owner-checkbox-row" for="is_available" style="margin-top:10px;">
                        <input type="checkbox" id="is_available" name="is_available" value="1" class="owner-checkbox-input" checked>
                        <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                        <span class="owner-checkbox-text">
                            <span class="owner-checkbox-label">Currently available</span>
                            <span class="owner-checkbox-desc">Uncheck to 86 this item temporarily (e.g. sold out) without archiving it.</span>
                        </span>
                    </label>
                </div>

                <!-- No "Active item" control here on purpose: a new item is always
                     created active, and archiving/restoring an existing one is the
                     Archive action's job (menu_item_toggle_status.php). Having both
                     meant the same state was editable from two places, and saving
                     this form could silently un-archive an item. -->

                <!-- Recipe/Packaging Rules have their own identity only once the item exists -- built on a dedicated page, not crammed into this form. -->
                <div class="owner-form-section" id="recipePlaceholderSection" hidden>
                    <h3 class="owner-form-section-title">Recipe &amp; packaging</h3>
                    <p class="owner-form-hint">
                        <a href="#" id="linkGoToRecipeBuilder">Build this item's recipe &amp; packaging rule <i class="ph ph-arrow-right" aria-hidden="true"></i></a>
                    </p>
                </div>

            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelAddItem">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="addItemSubmitLabel">Save Menu Item</span></button>
            </div>
        </form>
    </div>
</div>

<!-- Manage categories modal -->
<div class="owner-modal-backdrop" id="manageCategoriesBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="manageCategoriesTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="manageCategoriesTitle">Manage categories</h2>
            <button type="button" class="owner-modal-close" id="btnCloseManageCategories" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="owner-modal-body">

            <div class="owner-table-wrap" style="margin-bottom:20px;">
                <table class="owner-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Menu items</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allCategories)): ?>
                            <tr><td colspan="3" class="owner-table-empty">No categories yet. Add your first one below.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allCategories as $c):
                                $catData = [
                                    'category_id'   => $c['category_id'],
                                    'category_name' => $c['category_name'],
                                ];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($c['category_name']) ?></td>
                                <td><?= (int)$c['menu_count'] ?></td>
                                <td>
                                    <div class="owner-table-actions">
                                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-category"
                                            aria-label="Edit category" data-category='<?= htmlspecialchars(json_encode($catData), ENT_QUOTES, 'UTF-8') ?>'>
                                            <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                        </button>
                                        <form method="post" action="menu_category_delete.php" style="display:inline;" data-confirm="Delete &ldquo;<?= htmlspecialchars($c['category_name']) ?>&rdquo;? This can't be undone. Categories still in use by an item can't be deleted until those items are reassigned.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                                            <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete category">
                                                <i class="ph ph-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="menu_category_save.php" id="addCategoryForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="category_id" id="categoryFormCategoryId" value="">
                <h3 class="owner-form-section-title" id="categoryFormTitle">Add category</h3>
                <div class="owner-form-grid">
                    <div class="owner-form-group owner-form-group-full">
                        <label for="new_category_name">Category name <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="text" id="new_category_name" name="category_name" class="owner-input" placeholder="e.g. Main Course" required>
                    </div>
                </div>
                <div style="margin-top:16px;display:flex;gap:8px;">
                    <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="categoryFormSubmitLabel">Add category</span></button>
                    <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelEditCategory" hidden>Cancel edit</button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- Preview modal — one per item, read-only -->
<?php foreach ($items as $it):
    $pid = (int)$it['item_id'];
?>
<div class="owner-modal-backdrop owner-preview-item-modal" id="previewItemBackdrop-<?= $pid ?>">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="previewItemTitle-<?= $pid ?>">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="previewItemTitle-<?= $pid ?>"><?= htmlspecialchars($it['item_name']) ?></h2>
            <button type="button" class="owner-modal-close" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="owner-modal-body">
            <?php if ($it['image_url']): ?>
                <img src="<?= htmlspecialchars($ownerBase . $it['image_url']) ?>" alt="" style="width:100%;max-height:220px;object-fit:cover;border-radius:10px;margin-bottom:16px;">
            <?php endif; ?>
            <div class="owner-form-grid" style="margin-bottom:20px;">
                <div class="owner-form-group">
                    <label><i class="ph ph-tag" aria-hidden="true"></i> Category</label>
                    <div><?= $it['category_name'] !== null ? htmlspecialchars($it['category_name']) : '&mdash;' ?></div>
                </div>
                <div class="owner-form-group">
                    <label><i class="ph ph-currency-circle-dollar" aria-hidden="true"></i> Selling price</label>
                    <div>&#8369;<?= number_format((float)$it['selling_price'], 2) ?></div>
                </div>
                <div class="owner-form-group">
                    <label><i class="ph ph-percent" aria-hidden="true"></i> Tax</label>
                    <div><?= (int)$it['is_vat_exempt'] === 1 ? 'VAT exempt' : 'VATable' ?></div>
                </div>
                <div class="owner-form-group owner-form-group-full">
                    <label><i class="ph ph-note" aria-hidden="true"></i> Description</label>
                    <div><?= $it['description'] !== null && $it['description'] !== '' ? nl2br(htmlspecialchars($it['description'])) : '&mdash;' ?></div>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ((int)$it['available_dine_in'] === 1): ?><span class="owner-status-pill is-active"><i class="ph ph-fork-knife" aria-hidden="true"></i> Dine-in</span><?php endif; ?>
                <?php if ((int)$it['available_takeout'] === 1): ?><span class="owner-status-pill is-active"><i class="ph ph-shopping-bag" aria-hidden="true"></i> Takeout</span><?php endif; ?>
                <?php if ((int)$it['is_available'] !== 1): ?><span class="owner-status-pill is-danger">Currently 86'd</span><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="../assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const suggestedCode = <?= json_encode($suggestedCode) ?>;
    const ownerBase = <?= json_encode($ownerBase) ?>;

    // -- Table filters ---------------------------------------------------
    const search = document.getElementById('itemFilterSearch');
    const categoryFilter = document.getElementById('itemFilterCategory');
    const statusFilter = document.getElementById('itemFilterStatus');
    const clearBtn = document.getElementById('btnClearItemFilters');
    const noMatchRow = document.getElementById('itemNoMatchRow');
    const countEl = document.getElementById('itemCount');
    const rows = Array.from(document.querySelectorAll('.owner-table tbody tr[data-name]'));

    const PAGE_SIZE = 10;
    const pagination = document.getElementById('itemPagination');
    const pagePrev = document.getElementById('itemPagePrev');
    const pageNext = document.getElementById('itemPageNext');
    const pageInfo = document.getElementById('itemPageInfo');
    let currentPage = 1;

    function applyFilters(resetPage) {
        if (resetPage) currentPage = 1;

        const q        = (search ? search.value : '').trim().toLowerCase();
        const category = categoryFilter ? categoryFilter.value : '';
        const status   = statusFilter ? statusFilter.value : '';

        const matched = rows.filter((row) => {
            const matchesSearch   = !q || row.getAttribute('data-name').includes(q);
            const matchesCategory = !category || row.getAttribute('data-category') === category;
            const matchesStatus   = !status || row.getAttribute('data-status') === status;
            return matchesSearch && matchesCategory && matchesStatus;
        });

        const totalPages = Math.max(1, Math.ceil(matched.length / PAGE_SIZE));
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * PAGE_SIZE;
        const matchedSet = new Set(matched.slice(start, start + PAGE_SIZE));

        rows.forEach((row) => { row.style.display = matchedSet.has(row) ? '' : 'none'; });

        if (noMatchRow) noMatchRow.hidden = (rows.length === 0 || matched.length > 0);
        if (countEl) countEl.textContent = matched.length + (matched.length === 1 ? ' item' : ' items');
        if (pagination) pagination.hidden = matched.length === 0 || totalPages <= 1;
        if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        if (pagePrev) pagePrev.disabled = currentPage <= 1;
        if (pageNext) pageNext.disabled = currentPage >= totalPages;
    }

    if (search)         search.addEventListener('input', () => applyFilters(true));
    if (categoryFilter) categoryFilter.addEventListener('change', () => applyFilters(true));
    if (statusFilter)   statusFilter.addEventListener('change', () => applyFilters(true));
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (search) search.value = '';
            if (categoryFilter) categoryFilter.value = '';
            if (statusFilter) statusFilter.value = 'active';
            applyFilters(true);
        });
    }
    if (pagePrev) pagePrev.addEventListener('click', () => { currentPage--; applyFilters(false); });
    if (pageNext) pageNext.addEventListener('click', () => { currentPage++; applyFilters(false); });

    applyFilters(true);

    // -- Add / edit menu item modal ---------------------------------------
    const backdrop = document.getElementById('addItemBackdrop');
    const openBtn = document.getElementById('btnOpenAddItem');
    const closeBtn = document.getElementById('btnCloseAddItem');
    const cancelBtn = document.getElementById('btnCancelAddItem');
    const form = document.getElementById('addItemForm');
    const modalTitle = document.getElementById('addItemTitle');
    const submitLabel = document.getElementById('addItemSubmitLabel');
    const itemIdField = document.getElementById('itemFormItemId');
    const existingImageField = document.getElementById('itemFormExistingImage');
    const recipePlaceholder = document.getElementById('recipePlaceholderSection');
    const imageInput = document.getElementById('image');
    const imagePreviewImg = document.getElementById('imagePreviewImg');
    const imagePreviewPlaceholder = document.getElementById('imagePreviewPlaceholder');
    const availableTakeoutInput = document.getElementById('available_takeout');
    const packagingNotRequiredRow = document.getElementById('packagingNotRequiredRow');
    const packagingNotRequiredInput = document.getElementById('packaging_not_required');

    // "Doesn't need packaging" is meaningless once Takeout itself is off --
    // hidden rather than just left visible-but-irrelevant, matching how this
    // app hides other fields that only apply under a specific condition
    // (e.g. the payroll adjustment period's semi-monthly cutoff selector).
    function syncPackagingRowVisibility() {
        if (!packagingNotRequiredRow || !availableTakeoutInput) return;
        packagingNotRequiredRow.style.display = availableTakeoutInput.checked ? '' : 'none';
    }
    if (availableTakeoutInput) {
        availableTakeoutInput.addEventListener('change', syncPackagingRowVisibility);
    }

    function showImagePreview(src) {
        if (src) {
            imagePreviewImg.src = src;
            imagePreviewImg.hidden = false;
            imagePreviewPlaceholder.hidden = true;
        } else {
            imagePreviewImg.hidden = true;
            imagePreviewPlaceholder.hidden = false;
        }
    }

    if (imageInput) {
        imageInput.addEventListener('change', () => {
            const file = imageInput.files && imageInput.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (e) => showImagePreview(e.target.result);
            reader.readAsDataURL(file);
        });
    }

    function openModal() {
        backdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
        const firstField = form.querySelector('input, select');
        if (firstField) firstField.focus();
    }

    function closeModal() {
        backdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    function resetToAddMode() {
        form.reset();
        itemIdField.value = '';
        existingImageField.value = '';
        document.getElementById('menu_code').value = suggestedCode;
        recipePlaceholder.hidden = true;
        showImagePreview(null);
        syncPackagingRowVisibility();
        document.querySelectorAll('#addItemForm [data-combobox]').forEach((root) => {
            if (root.__comboboxReset) root.__comboboxReset();
        });
        modalTitle.textContent = 'Add menu item';
        submitLabel.textContent = 'Save Menu Item';
    }

    if (openBtn)   openBtn.addEventListener('click', () => { resetToAddMode(); openModal(); });
    if (closeBtn)  closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', () => { form.reset(); closeModal(); });

    if (backdrop) {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) closeModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
    });

    // -- Searchable category combobox (copied from inventory/inventory.php) --
    function initCombobox(root) {
        const input    = root.querySelector('.owner-combobox-input');
        const hidden   = root.querySelector('input[type="hidden"]');
        const panel    = root.querySelector('.owner-combobox-panel');
        if (!input || !hidden || !panel) return;

        let options = [];
        try { options = JSON.parse(input.getAttribute('data-options') || '[]'); } catch (e) { options = []; }
        let activeIndex = -1;

        function filtered(query) {
            const q = query.trim().toLowerCase();
            if (!q) return options;
            return options.filter(o => o.label.toLowerCase().includes(q));
        }

        function render(list) {
            panel.innerHTML = '';
            if (list.length === 0) {
                const li = document.createElement('li');
                li.className = 'owner-combobox-empty';
                li.textContent = 'No matches found';
                panel.appendChild(li);
                return;
            }
            list.forEach((opt) => {
                const li = document.createElement('li');
                li.className = 'owner-combobox-option';
                li.setAttribute('role', 'option');
                li.setAttribute('data-id', opt.id);
                li.textContent = opt.label;
                if (opt.id === hidden.value) li.classList.add('is-selected');
                li.addEventListener('mousedown', (e) => { e.preventDefault(); select(opt); });
                panel.appendChild(li);
            });
        }

        function open() {
            render(filtered(input.value));
            panel.classList.add('is-open');
            input.setAttribute('aria-expanded', 'true');
            activeIndex = -1;
        }

        function close() {
            panel.classList.remove('is-open');
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function select(opt) {
            hidden.value = opt.id;
            input.value = opt.label;
            input.classList.remove('is-invalid');
            clearFieldError(root, input);
            close();
        }

        function highlight(opts) {
            opts.forEach(o => o.classList.remove('is-active'));
            if (opts[activeIndex]) {
                opts[activeIndex].classList.add('is-active');
                opts[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        input.addEventListener('focus', open);
        input.addEventListener('click', open);
        input.addEventListener('input', () => {
            if (hidden.value) { hidden.value = ''; }
            open();
        });

        input.addEventListener('keydown', (e) => {
            const opts = panel.querySelectorAll('.owner-combobox-option');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!panel.classList.contains('is-open')) { open(); return; }
                activeIndex = Math.min(activeIndex + 1, opts.length - 1);
                highlight(opts);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                highlight(opts);
            } else if (e.key === 'Enter') {
                if (panel.classList.contains('is-open') && activeIndex >= 0 && opts[activeIndex]) {
                    e.preventDefault();
                    const id = opts[activeIndex].getAttribute('data-id');
                    const opt = options.find(o => String(o.id) === String(id));
                    if (opt) select(opt);
                }
            } else if (e.key === 'Escape') {
                close();
            }
        });

        document.addEventListener('click', (e) => {
            if (!root.contains(e.target)) close();
        });

        root.__comboboxReset = function () {
            hidden.value = '';
            input.value = '';
            input.classList.remove('is-invalid');
        };

        root.__comboboxSet = function (id, label) {
            if (id === null || id === undefined || id === '') {
                root.__comboboxReset();
                return;
            }
            hidden.value = String(id);
            input.value = label || '';
        };
    }

    document.querySelectorAll('#addItemForm [data-combobox]').forEach(initCombobox);

    // -- Edit item: populate the shared Add/Edit modal ------------------------
    document.querySelectorAll('.owner-btn-edit-item').forEach((btn) => {
        btn.addEventListener('click', () => {
            let item;
            try { item = JSON.parse(btn.getAttribute('data-item')); } catch (e) { return; }

            form.reset();
            itemIdField.value = item.item_id;
            existingImageField.value = item.image_url || '';
            document.getElementById('menu_code').value = item.menu_code || '';
            document.getElementById('item_name').value = item.item_name || '';
            document.getElementById('description').value = item.description || '';
            document.getElementById('selling_price').value = item.selling_price || '';
            document.getElementById('is_vat_exempt').checked = !!item.is_vat_exempt;
            document.getElementById('available_dine_in').checked = !!item.available_dine_in;
            document.getElementById('available_takeout').checked = !!item.available_takeout;
            if (packagingNotRequiredInput) packagingNotRequiredInput.checked = !!item.packaging_not_required;
            syncPackagingRowVisibility();
            document.getElementById('is_available').checked = !!item.is_available;
            // is_active is deliberately not populated or submitted -- archiving is
            // the Archive action's job, not this form's.
            showImagePreview(item.image_url ? ownerBase + item.image_url : null);
            recipePlaceholder.hidden = false;
            const recipeLink = document.getElementById('linkGoToRecipeBuilder');
            if (recipeLink) recipeLink.href = 'recipe_builder.php?item_id=' + item.item_id;

            const categoryRoot = document.getElementById('category_search').closest('[data-combobox]');
            if (categoryRoot && categoryRoot.__comboboxSet) categoryRoot.__comboboxSet(item.category_id, item.category_name);

            modalTitle.textContent = 'Edit menu item';
            submitLabel.textContent = 'Save Changes';
            openModal();
        });
    });

    // -- Inline validation -----------------------------------------------------
    function fieldGroup(elOrRoot) {
        return elOrRoot.classList && elOrRoot.classList.contains('owner-form-group') ? elOrRoot : elOrRoot.closest('.owner-form-group');
    }
    function showFieldError(groupEl, visibleEl, message) {
        if (visibleEl) visibleEl.classList.add('is-invalid');
        const err = groupEl ? groupEl.querySelector('.owner-form-error') : null;
        if (err) err.textContent = message;
    }
    function clearFieldError(groupOrRoot, visibleEl) {
        const groupEl = fieldGroup(groupOrRoot);
        if (visibleEl) visibleEl.classList.remove('is-invalid');
        const err = groupEl ? groupEl.querySelector('.owner-form-error') : null;
        if (err) err.textContent = '';
    }

    if (form) {
        const formAlert = document.getElementById('addItemFormAlert');
        const requiredChecks = [
            { hidden: document.getElementById('menu_code'), visible: document.getElementById('menu_code'), message: 'Menu code is required.' },
            { hidden: document.getElementById('category_id'), visible: document.getElementById('category_search'), message: 'Select a category.' },
            { hidden: document.getElementById('item_name'), visible: document.getElementById('item_name'), message: 'Item name is required.' },
            { hidden: document.getElementById('selling_price'), visible: document.getElementById('selling_price'), message: 'Selling price is required.' },
        ];

        form.addEventListener('submit', (e) => {
            let hasError = false;

            requiredChecks.forEach(({ hidden, visible, message }) => {
                if (!hidden) return;
                const group = fieldGroup(hidden) || fieldGroup(visible);
                clearFieldError(group, visible);
                if (!hidden.value || hidden.value.trim() === '') {
                    showFieldError(group, visible, message);
                    hasError = true;
                }
            });

            const priceEl = document.getElementById('selling_price');
            if (priceEl.value !== '' && parseFloat(priceEl.value) < 0) {
                showFieldError(fieldGroup(priceEl), priceEl, 'Selling price cannot be negative.');
                hasError = true;
            }

            if (hasError) {
                e.preventDefault();
                if (formAlert) formAlert.hidden = false;
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            } else if (formAlert) {
                formAlert.hidden = true;
            }
        });

        form.querySelectorAll('input[type="number"]').forEach((numInput) => {
            numInput.addEventListener('keydown', (e) => {
                if (e.key === '-' || e.key === 'e' || e.key === 'E') e.preventDefault();
            });
        });

        form.addEventListener('reset', () => {
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            form.querySelectorAll('.owner-form-error').forEach(el => { el.textContent = ''; });
            if (formAlert) formAlert.hidden = true;
        });
    }

    <?php if (!empty($_SESSION['_reopen_item_modal'])): unset($_SESSION['_reopen_item_modal']); ?>
    openModal();
    <?php endif; ?>

    // -- Manage categories modal -------------------------------------------
    const catBackdrop = document.getElementById('manageCategoriesBackdrop');
    const catOpenBtn = document.getElementById('btnOpenManageCategories');
    const catOpenLink = document.getElementById('linkManageCategoriesFromForm');
    const catCloseBtn = document.getElementById('btnCloseManageCategories');
    const catForm = document.getElementById('addCategoryForm');
    const catFormTitle = document.getElementById('categoryFormTitle');
    const catSubmitLabel = document.getElementById('categoryFormSubmitLabel');
    const catIdField = document.getElementById('categoryFormCategoryId');
    const catCancelEditBtn = document.getElementById('btnCancelEditCategory');

    function openCatModal() {
        catBackdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closeCatModal() {
        catBackdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }
    function resetCategoryFormToAddMode() {
        catForm.reset();
        catIdField.value = '';
        catFormTitle.textContent = 'Add category';
        catSubmitLabel.textContent = 'Add category';
        catCancelEditBtn.hidden = true;
    }

    if (catOpenBtn) catOpenBtn.addEventListener('click', () => { openCatModal(); });
    if (catOpenLink) {
        catOpenLink.addEventListener('click', (e) => {
            e.preventDefault();
            closeModal();
            openCatModal();
        });
    }
    if (catCloseBtn) catCloseBtn.addEventListener('click', closeCatModal);
    if (catCancelEditBtn) catCancelEditBtn.addEventListener('click', resetCategoryFormToAddMode);

    if (catBackdrop) {
        catBackdrop.addEventListener('click', (e) => {
            if (e.target === catBackdrop) closeCatModal();
        });
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && catBackdrop.classList.contains('is-open')) closeCatModal();
    });

    document.querySelectorAll('.owner-btn-edit-category').forEach((btn) => {
        btn.addEventListener('click', () => {
            let c;
            try { c = JSON.parse(btn.getAttribute('data-category')); } catch (e) { return; }

            catIdField.value = c.category_id;
            document.getElementById('new_category_name').value = c.category_name || '';

            catFormTitle.textContent = 'Edit category';
            catSubmitLabel.textContent = 'Save changes';
            catCancelEditBtn.hidden = false;
        });
    });

    <?php if (!empty($_SESSION['_reopen_category_modal'])): unset($_SESSION['_reopen_category_modal']); ?>
    openCatModal();
    <?php endif; ?>

    // -- Preview modals ----------------------------------------------------
    function openPreview(modal) {
        modal.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closePreview(modal) {
        modal.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    document.querySelectorAll('.owner-btn-preview-item').forEach((btn) => {
        btn.addEventListener('click', () => {
            const modal = document.getElementById('previewItemBackdrop-' + btn.getAttribute('data-preview-item'));
            if (modal) openPreview(modal);
        });
    });

    document.querySelectorAll('.owner-preview-item-modal').forEach((modal) => {
        const closeBtn = modal.querySelector('.owner-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', () => closePreview(modal));
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closePreview(modal);
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.owner-preview-item-modal.is-open').forEach(closePreview);
    });
})();
</script>

<script src="../assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../assets/js/filter-persist.js') ?>"></script>
</body>
</html>
