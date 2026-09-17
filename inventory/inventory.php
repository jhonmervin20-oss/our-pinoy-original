<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/inventory_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage   = 'inventory';
$activeInvTab = 'items';
$pageTitle    = 'Inventory';
$isManager    = Session::hasRole(['manager']);

/**
 * config/database.php exposes a Database singleton, not a bare $pdo.
 * Everything below degrades gracefully to empty arrays if the tables don't
 * exist yet, so this page is safe to drop in before the schema migration
 * has been run on a given environment.
 */
$items      = [];
$batches    = [];
$categories = [];
$suppliers  = [];
$units      = [];
$dbError    = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $categories = $pdo->query(
        "SELECT c.category_id, c.category_name,
                COUNT(i.item_id) AS item_count
         FROM inventory_categories c
         LEFT JOIN inventory_items i ON i.category_id = c.category_id
         GROUP BY c.category_id
         ORDER BY c.category_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Only active suppliers/units are offered as choices on the Add item
    // form — inactive ones stay visible elsewhere (batches, history) but
    // shouldn't be selectable for new items, same rule as categories.
    $suppliers = $pdo->query(
        "SELECT supplier_id, supplier_name
         FROM suppliers
         WHERE is_active = 1
         ORDER BY supplier_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $units = $pdo->query(
        "SELECT unit_id, unit_code, unit_name, unit_type
         FROM unit_of_measures
         WHERE is_active = 1
         ORDER BY unit_type, unit_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Shared with inventory_live.php's poll endpoint (same functions, same
    // queries) so the page's initial render and its live updates can never
    // disagree about what "current" looks like.
    $items   = getInventoryItemsSnapshot($pdo);
    $batches = getInventoryBatchesSnapshot($pdo);
} catch (PDOException $e) {
    $dbError = "Couldn't load live inventory data. Run the schema migration, then refresh this page.";
}

// -- Derived summary numbers ------------------------------------------------
$totalItems     = count($items);
$lowStockItems  = array_filter($items, fn($i) => (float)$i['current_stock'] <= (float)$i['reorder_level']);
$criticalStockItems = array_filter($items, function ($i) {
    if ($i['critical_level'] === null || $i['critical_level'] === '') return false;
    return (float)$i['current_stock'] <= (float)$i['critical_level'];
});
$totalValue     = 0.0;
foreach ($batches as $b) {
    $totalValue += (float)$b['quantity_remaining'] * (float)$b['unit_cost'];
}

$today = new DateTime('today');
$expiringSoon = array_filter($batches, function ($b) use ($today) {
    if (!$b['expiry_date']) return false;
    $diff = $today->diff(new DateTime($b['expiry_date']))->days;
    return (new DateTime($b['expiry_date'])) >= $today && $diff <= 7;
});
$expiredBatches = array_filter($batches, function ($b) use ($today) {
    return $b['expiry_date'] && (new DateTime($b['expiry_date'])) < $today;
});

// Base units are shown as "Kilogram (kg)" so the combobox is searchable by
// either the full name or the abbreviation.
$unitOptions = array_map(
    fn($u) => ['unit_id' => $u['unit_id'], 'label' => $u['unit_name'] . ' (' . $u['unit_code'] . ')'],
    $units
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">

    <?php
    if ($isManager) {
        $managerBase = '../manager/';
        require_once __DIR__ . '/../manager/includes/sidebar.php';
    } else {
        $ownerBase = '../owner/';
        require_once __DIR__ . '/../owner/includes/sidebar.php';
    }
    ?>

    <div class="owner-main">

        <?php
        if ($isManager) {
            require_once __DIR__ . '/../manager/includes/header.php';
        } else {
            require_once __DIR__ . '/../owner/includes/header.php';
        }
        ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <?php require __DIR__ . '/includes/inventory_nav.php'; ?>

            <?php if (!$dbError): ?>
                <div class="owner-alert owner-alert-error" id="invExpiredBanner" style="<?= count($expiredBatches) > 0 ? '' : 'display:none;' ?>">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span id="invExpiredBannerText"><?= count($expiredBatches) ?> batch<?= count($expiredBatches) === 1 ? '' : 'es' ?> past expiry date and still marked with remaining stock. Log these as waste to keep FIFO deductions accurate.</span>
                </div>
            <?php endif; ?>

            <!-- Summary cards -->
            <div class="owner-form-grid" style="margin-bottom:10px;">
                
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Low stock</div>
                        <div class="owner-summary-card-value" id="invSummaryLowStock"><?= count($lowStockItems) ?></div>
                        <div class="owner-summary-card-meta">at or below reorder level</div>
                    </div>
                    <i class="ph ph-warning" style="font-size:1.6rem;color:var(--op-danger);" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Critical stock</div>
                        <div class="owner-summary-card-value" id="invSummaryCritical"><?= count($criticalStockItems) ?></div>
                        <div class="owner-summary-card-meta">at or below critical level</div>
                    </div>
                    <i class="ph ph-siren" style="font-size:1.6rem;color:var(--op-danger);" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Expiring within 7 days</div>
                        <div class="owner-summary-card-value" id="invSummaryExpiring"><?= count($expiringSoon) ?></div>
                    </div>
                    <i class="ph ph-clock-countdown" style="font-size:1.6rem;color:var(--op-gold-light);" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Inventory value</div>
                        <div class="owner-summary-card-value">&#8369;<span id="invSummaryValue"><?= number_format($totalValue, 2) ?></span></div>
                        <div class="owner-summary-card-meta">remaining stock &times; unit cost</div>
                    </div>
                    <i class="ph ph-currency-circle-dollar" style="font-size:1.6rem;color:var(--op-gold);" aria-hidden="true"></i>
                </div>
            </div>

            <!-- Tabs -->
            <div class="owner-tabs">
                <a href="#" class="owner-tabs-link is-active" data-inv-tab="items">Items</a>
                <a href="#" class="owner-tabs-link" data-inv-tab="batches">Batches</a>
            </div>

            <!-- Items panel: filters card + table card. Both carry data-inv-panel
                 so the tab toggle shows and hides them together. -->
            <div class="owner-card owner-inv-panel" data-inv-panel="items" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="invFilterSearch" placeholder="Search items&hellip;" autocomplete="off">
                    </div>
                    <select id="invFilterCategory" class="owner-select">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="invFilterStatus" class="owner-select">
                        <option value="">All statuses</option>
                        <option value="out_of_stock">Out of stock</option>
                        <option value="critical">Critical</option>
                        <option value="low">Low stock</option>
                        <option value="ok">In stock</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnClearInvFilters">Clear</button>
                    <div style="margin-left:auto;display:flex;gap:10px;">
                        <button type="button" class="owner-btn owner-btn-secondary" id="btnOpenManageCategories">
                            <i class="ph ph-tag" aria-hidden="true"></i> Manage categories
                        </button>
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddItem">
                            <i class="ph ph-plus-circle" aria-hidden="true"></i> Add item
                        </button>
                    </div>
                </div>
            </div>

            <div class="owner-card owner-inv-panel" data-inv-panel="items" id="inv-panel-items">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Current stock</th>
                                <th>Reorder level</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No inventory items yet. Add your first one from the &ldquo;Add item&rdquo; tab.</td></tr>
                            <?php else: ?>
                                <?php foreach ($items as $it):
                                    $stock        = (float)$it['current_stock'];
                                    $reorder      = (float)$it['reorder_level'];
                                    $hasCritical  = $it['critical_level'] !== null && $it['critical_level'] !== '';
                                    $critical     = $hasCritical ? (float)$it['critical_level'] : null;
                                    $isItemActive = (int)$it['is_active'] === 1;

                                    // Status comes straight from the inventory_stock_status VIEW
                                    // (single source of truth -- also queryable directly from the
                                    // DB by any future report, not just re-derived here in PHP).
                                    $statusKey    = $it['stock_status'];

                                    $barCeiling   = max($reorder * 2, 0.0001);
                                    $pct          = min(100, ($stock / $barCeiling) * 100);
                                    $barClass     = match ($statusKey) {
                                        'out_of_stock', 'critical' => ' is-critical',
                                        'low'                      => ' is-low',
                                        default                    => '',
                                    };

                                    $itemData = inventoryItemRowData($it);
                                ?>
                                <tr data-item-id="<?= (int)$it['item_id'] ?>" data-item-name="<?= htmlspecialchars(strtolower($it['item_name'])) ?>" data-category="<?= (int)($it['category_id'] ?? 0) ?>" data-status="<?= $statusKey ?>">
                                    <td><?= htmlspecialchars($it['item_name']) ?></td>
                                    <td><?= $it['category_name'] !== null ? htmlspecialchars($it['category_name']) : '&mdash;' ?></td>
                                    <td>
                                        <div class="owner-cell-stack">
                                            <strong class="inv-stock-text"><?= fmtQty($stock) ?> <?= htmlspecialchars($it['unit_code'] ?? '') ?></strong>
                                            <div class="owner-stock-bar">
                                                <div class="owner-stock-bar-fill inv-stock-bar<?= $barClass ?>" style="width:<?= (int)$pct ?>%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="owner-cell-stack">
                                            <strong><?= fmtQty($reorder) ?> <?= htmlspecialchars($it['unit_code'] ?? '') ?></strong>
                                            <?php if ($hasCritical): ?><small>Critical: <?= fmtQty($critical) ?> <?= htmlspecialchars($it['unit_code'] ?? '') ?></small><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($statusKey === 'inactive'): ?>
                                            <span class="owner-status-pill inv-item-status-pill is-inactive">Inactive</span>
                                        <?php elseif ($statusKey === 'out_of_stock'): ?>
                                            <span class="owner-status-pill inv-item-status-pill is-critical">Out of stock</span>
                                        <?php elseif ($statusKey === 'critical'): ?>
                                            <span class="owner-status-pill inv-item-status-pill is-critical">Critical</span>
                                        <?php elseif ($statusKey === 'low'): ?>
                                            <span class="owner-status-pill inv-item-status-pill is-danger">Low stock</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill inv-item-status-pill is-success">In stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-item"
                                                aria-label="Edit item" data-item='<?= rowJson($itemData) ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="POST" action="inventory_toggle_status.php" style="display:inline;" <?php if ($isItemActive): ?>data-confirm="Deactivate <?= htmlspecialchars($it['item_name']) ?>? It will stay in history but won't be selectable in purchasing or recipes."<?php endif; ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                                                <?php if ($isItemActive): ?>
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Deactivate item">
                                                        <i class="ph ph-archive" aria-hidden="true"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Activate item">
                                                        <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="invNoMatchRow" class="owner-table-empty-row" hidden>
                                    <td colspan="7" class="owner-table-empty">No items match your filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-pagination" id="invItemsPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="invItemsPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="invItemsPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="invItemsPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

            <!-- Batches panel: filters card + table card, both data-inv-panel tagged. -->
            <div class="owner-card owner-inv-panel" data-inv-panel="batches" style="display:none;margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="invBatchFilterSearch" placeholder="Search item, batch # or supplier&hellip;" autocomplete="off">
                    </div>
                    <select id="invBatchFilterStatus" class="owner-select">
                        <option value="">All statuses</option>
                        <option value="expired">Expired</option>
                        <option value="expiring">Expiring soon (&le;7d)</option>
                        <option value="fresh">Fresh</option>
                        <option value="none">No expiry set</option>
                    </select>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnClearBatchFilters">Clear</button>
                </div>
            </div>

            <div class="owner-card owner-inv-panel" data-inv-panel="batches" id="inv-panel-batches" style="display:none;">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Batch #</th>
                                <th>Remaining</th>
                                <th>Unit cost</th>
                                <th>Supplier</th>
                                <th>Expiry date</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($batches)): ?>
                                <tr><td colspan="8" class="owner-table-empty">No batches on hand. Batches are created automatically when a purchase order is received.</td></tr>
                            <?php else: ?>
                                <?php foreach ($batches as $b):
                                    $expiry  = $b['expiry_date'];
                                    $status  = ['label' => 'No expiry set', 'class' => 'is-active'];
                                    $expiryStatusKey = 'none';
                                    if ($expiry) {
                                        $days = $today->diff(new DateTime($expiry))->days;
                                        $isPast = (new DateTime($expiry)) < $today;
                                        if ($isPast) {
                                            $status = ['label' => 'Expired', 'class' => 'is-danger'];
                                            $expiryStatusKey = 'expired';
                                        } elseif ($days <= 7) {
                                            $status = ['label' => "Expires in {$days}d", 'class' => 'is-warning'];
                                            $expiryStatusKey = 'expiring';
                                        } else {
                                            $status = ['label' => 'Fresh', 'class' => 'is-success'];
                                            $expiryStatusKey = 'fresh';
                                        }
                                    }
                                ?>
                                <?php // Item, batch number and supplier in one lowercased haystack:
                                      // all three are on screen, so any of them is a reasonable
                                      // thing to type. Mirrors the Items tab's data-item-name. ?>
                                <tr data-batch-id="<?= (int)$b['batch_id'] ?>" data-expiry-status="<?= $expiryStatusKey ?>"
                                    data-batch-search="<?= htmlspecialchars(strtolower(trim(
                                        $b['item_name'] . ' ' . ($b['batch_number'] ?? '') . ' ' . ($b['supplier_name'] ?? '')
                                    ))) ?>">
                                    <td><?= htmlspecialchars($b['item_name']) ?></td>
                                    <td><?= $b['batch_number'] !== null ? htmlspecialchars($b['batch_number']) : '&mdash;' ?></td>
                                    <td class="inv-batch-remaining"><?= fmtQty($b['quantity_remaining']) ?> <?= htmlspecialchars($b['unit_code'] ?? '') ?></td>
                                    <td class="inv-batch-unitcost">&#8369;<?= number_format((float)$b['unit_cost'], 2) ?></td>
                                    <td><?= $b['supplier_name'] !== null ? htmlspecialchars($b['supplier_name']) : '&mdash;' ?></td>
                                    <td><?= $expiry ? htmlspecialchars(date('M j, Y', strtotime($expiry))) : '&mdash;' ?></td>
                                    <td><span class="owner-status-pill inv-batch-status-pill <?= $status['class'] ?>"><?= htmlspecialchars($status['label']) ?></span></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-view-batch"
                                                    data-batch-id="<?= (int)$b['batch_id'] ?>"
                                                    aria-label="View batch <?= htmlspecialchars($b['batch_number'] ?? ('#' . $b['batch_id'])) ?>">
                                                <i class="ph ph-eye" aria-hidden="true"></i>
                                            </button>
<?php // Recorded in place rather than by jumping to the Wastage or
                                                  // Adjustments module. Both of those pages open the very same
                                                  // form in a modal on arrival, so the redirect only ever cost a
                                                  // page load and the loss of your place in this table. The data
                                                  // each modal needs travels on the button. ?>
                                            <button type="button" class="owner-btn owner-btn-danger owner-btn-sm js-batch-waste"
                                                    data-batch-id="<?= (int)$b['batch_id'] ?>"
                                                    data-item-id="<?= (int)$b['item_id'] ?>"
                                                    data-item-name="<?= htmlspecialchars($b['item_name']) ?>"
                                                    data-batch-number="<?= htmlspecialchars($b['batch_number'] ?? ('#' . $b['batch_id'])) ?>"
                                                    data-remaining="<?= (float)$b['quantity_remaining'] ?>"
                                                    data-unit="<?= htmlspecialchars($b['unit_code'] ?? '') ?>">Waste</button>
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm js-batch-adjust"
                                                    data-batch-id="<?= (int)$b['batch_id'] ?>"
                                                    data-item-id="<?= (int)$b['item_id'] ?>"
                                                    data-item-name="<?= htmlspecialchars($b['item_name']) ?>"
                                                    data-batch-number="<?= htmlspecialchars($b['batch_number'] ?? ('#' . $b['batch_id'])) ?>"
                                                    data-remaining="<?= (float)$b['quantity_remaining'] ?>"
                                                    data-unit="<?= htmlspecialchars($b['unit_code'] ?? '') ?>">Adjust</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-pagination" id="invBatchesPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="invBatchesPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="invBatchesPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="invBatchesPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- Add / edit item modal -->
<div class="owner-modal-backdrop" id="addItemBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="addItemTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="addItemTitle">Add inventory item</h2>
            <button type="button" class="owner-modal-close" id="btnCloseAddItem" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="post" action="inventory_save.php" id="addItemForm" novalidate>
            <div class="owner-modal-body">

                <?= csrf_field() ?>
                <input type="hidden" name="item_id" id="itemFormItemId" value="">
                <?php // Which page of the table you were on. inventory_save.php
                      // reflects it back in the redirect, so saving an edit
                      // returns you where you were instead of page 1. ?>
                <input type="hidden" name="return_page" id="itemFormReturnPage" value="1">

                <div class="owner-form-note owner-alert owner-alert-error" id="addItemFormAlert" hidden>
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span>Please fix the highlighted fields before saving.</span>
                </div>

                <!-- Item details -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Item details</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group owner-form-group-full">
                            <label for="item_name">Item name <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="text" id="item_name" name="item_name" class="owner-input" placeholder="e.g. Chicken thigh" required>
                            <span class="owner-form-error" data-error-for="item_name"></span>
                        </div>

                        <div class="owner-form-group" data-combobox>
                            <label for="category_search">Category <span class="owner-required" aria-hidden="true">*</span></label>
                            <div class="owner-combobox-control">
                                <input type="text" id="category_search" class="owner-input owner-combobox-input" placeholder="Search category&hellip;" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="category_listbox" data-options='<?= inventoryComboboxOptions($categories, "category_id", "category_name") ?>'>
                                <input type="hidden" name="category_id" id="category_id" required>
                                <i class="ph ph-caret-down owner-combobox-caret" aria-hidden="true"></i>
                                <ul class="owner-combobox-panel" id="category_listbox" role="listbox"></ul>
                            </div>
                              </div>

                        <div class="owner-form-group" data-combobox>
                            <label for="base_unit_search">Base unit of measure <span class="owner-required" aria-hidden="true">*</span></label>
                            <div class="owner-combobox-control">
                                <input type="text" id="base_unit_search" class="owner-input owner-combobox-input" placeholder="Search unit&hellip;" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="base_unit_listbox" data-options='<?= inventoryComboboxOptions($unitOptions, "unit_id", "label") ?>'>
                                <input type="hidden" name="base_unit_id" id="base_unit_id" required>
                                <i class="ph ph-caret-down owner-combobox-caret" aria-hidden="true"></i>
                                <ul class="owner-combobox-panel" id="base_unit_listbox" role="listbox"></ul>
                            </div>
                            <span class="owner-form-hint">
                                      <span class="owner-form-error" data-error-for="base_unit_id"></span>
                        </div>
                    </div>
                </div>

                <!-- Stock levels -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Stock levels</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="critical_level">Critical level <span class="owner-form-optional">(optional)</span></label>
                            <input type="number" step="0.001" min="0" id="critical_level" name="critical_level" class="owner-input" placeholder="0.000">
                           
                            <span class="owner-form-error" data-error-for="critical_level"></span>
                        </div>

                        <div class="owner-form-group">
                            <label for="reorder_level">Reorder level <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="number" step="0.001" min="0" id="reorder_level" name="reorder_level" class="owner-input" placeholder="0.000" required>
                            
                            <span class="owner-form-error" data-error-for="reorder_level"></span>
                        </div>
                    </div>
                </div>

                <!-- Purchasing -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Purchasing</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group" data-combobox>
                            <label for="supplier_search">Preferred supplier <span class="owner-form-optional">(optional)</span></label>
                            <div class="owner-combobox-control">
                                <input type="text" id="supplier_search" class="owner-input owner-combobox-input" placeholder="Search supplier&hellip;" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="supplier_listbox" data-options='<?= inventoryComboboxOptions($suppliers, "supplier_id", "supplier_name") ?>'>
                                <input type="hidden" name="preferred_supplier_id" id="preferred_supplier_id">
                                <button type="button" class="owner-combobox-clear" data-combobox-clear aria-label="Clear preferred supplier" hidden><i class="ph ph-x" aria-hidden="true"></i></button>
                                <i class="ph ph-caret-down owner-combobox-caret" aria-hidden="true"></i>
                                <ul class="owner-combobox-panel" id="supplier_listbox" role="listbox"></ul>
                            </div>
                        </div>

                        <div class="owner-form-group">
                            <label for="lead_time_days">Lead time (days) <span class="owner-form-optional">(optional)</span></label>
                            <input type="number" step="1" min="0" id="lead_time_days" name="lead_time_days" class="owner-input" placeholder="e.g. 3">
                            <span class="owner-form-hint">How many days the supplier needs to deliver.</span>
                            <span class="owner-form-error" data-error-for="lead_time_days"></span>
                        </div>

                        <?php // Safety stock is the manager's call, not the system's. It used
                              // to be derived from a global "safety stock days" setting and
                              // written back over this column on every forecast run, so
                              // nobody could set it per ingredient. Now it is typed here and
                              // the run reads it. ?>
                        <div class="owner-form-group">
                            <label for="safety_stock_qty">Safety stock <span class="owner-form-optional">(optional)</span></label>
                            <input type="number" step="0.001" min="0" id="safety_stock_qty" name="safety_stock_qty" class="owner-input" placeholder="e.g. 5">
                            <span class="owner-form-hint">Extra quantity to keep on hand as a buffer, in this item's base unit.</span>
                            <span class="owner-form-error" data-error-for="safety_stock_qty"></span>
                        </div>

                    </div>
                </div>

                <!-- Status -->
                <div class="owner-form-section" hidden>
                    <h3 class="owner-form-section-title">Status</h3>
                    <label class="owner-checkbox-row" for="is_active">
                        <input type="checkbox" id="is_active" name="is_active" value="1" class="owner-checkbox-input" checked>
                        <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                        <span class="owner-checkbox-text">
                            <span class="owner-checkbox-label">Active item</span>
                            <span class="owner-checkbox-desc">Inactive items cannot be selected in purchasing or recipes but remain in history.</span>
                        </span>
                    </label>
                </div>

            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelAddItem">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="addItemSubmitLabel">Save Inventory Item</span></button>
            </div>
        </form>
    </div>
</div>

<!-- Manage categories modal -->
<!-- Batch detail (Batches tab) -- body is fetched from batch_fragment.php -->
<div class="owner-modal-backdrop" id="batchModalBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="batchModalTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="batchModalTitle">Batch details</h2>
            <button type="button" class="owner-modal-close" id="btnCloseBatchModal" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="owner-modal-body" id="batchModalBody"></div>
        <div class="owner-modal-footer">
            <button type="button" class="owner-btn owner-btn-secondary" id="btnCloseBatchModalFooter">Close</button>
        </div>
    </div>
</div>

<?php // Record wastage without leaving the Batches tab. Posts to the same
      // handler inventory_wastage.php uses, with return_to bringing you back
      // here rather than to the Wastage module. ?>
<div class="owner-modal-backdrop" id="wasteBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="wasteTitle">
        <form method="post" action="inventory_wastage_save.php">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="inventory">
            <input type="hidden" name="item_id" id="wasteItemId">
            <input type="hidden" name="batch_id" id="wasteBatchId">
            <div class="owner-modal-header">
                <h2 class="owner-modal-title" id="wasteTitle">Record wastage</h2>
                <button type="button" class="owner-modal-close" data-close-waste aria-label="Close">
                    <i class="ph ph-x" aria-hidden="true"></i>
                </button>
            </div>
            <div class="owner-modal-body">
                <p class="owner-form-hint" id="wasteContext" style="margin-top:0;"></p>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="wasteQuantity">Quantity</label>
                        <input type="number" step="0.001" min="0.001" id="wasteQuantity" name="quantity" class="owner-input" required>
                        <span class="owner-form-hint" id="wasteMaxHint"></span>
                    </div>
                    <div class="owner-form-group">
                        <label for="wasteDate">Date</label>
                        <input type="date" id="wasteDate" name="wastage_date" class="owner-input" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="wasteReason">Reason</label>
                        <select id="wasteReason" name="reason" class="owner-input" required>
                            <option value="expired">Expired</option>
                            <option value="spoiled">Spoiled</option>
                            <option value="burned">Burned</option>
                            <option value="overcooked">Overcooked</option>
                            <option value="dropped">Dropped</option>
                            <option value="customer_complaint">Customer complaint</option>
                            <option value="quality_issue">Quality issue</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="wasteRemarks">Remarks <span class="owner-form-optional">(optional)</span></label>
                        <input type="text" id="wasteRemarks" name="remarks" class="owner-input" maxlength="255">
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" data-close-waste>Cancel</button>
                <button type="submit" class="owner-btn owner-btn-danger">Record wastage</button>
            </div>
        </form>
    </div>
</div>

<?php // Same idea for a stock adjustment. Direction is a real choice here --
      // a physical count can go either way -- so it is a field, not a hidden
      // 'decrease' the way the old link hardcoded it. ?>
<div class="owner-modal-backdrop" id="adjustBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="adjustTitle">
        <form method="post" action="inventory_adjustment_save.php">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="inventory">
            <input type="hidden" name="item_id" id="adjustItemId">
            <input type="hidden" name="batch_id" id="adjustBatchId">
            <div class="owner-modal-header">
                <h2 class="owner-modal-title" id="adjustTitle">Adjust stock</h2>
                <button type="button" class="owner-modal-close" data-close-adjust aria-label="Close">
                    <i class="ph ph-x" aria-hidden="true"></i>
                </button>
            </div>
            <div class="owner-modal-body">
                <p class="owner-form-hint" id="adjustContext" style="margin-top:0;"></p>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="adjustDirection">Direction</label>
                        <select id="adjustDirection" name="direction" class="owner-input" required>
                            <option value="decrease">Decrease</option>
                            <option value="increase">Increase</option>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="adjustQuantity">Quantity</label>
                        <input type="number" step="0.001" min="0.001" id="adjustQuantity" name="quantity" class="owner-input" required>
                        <span class="owner-form-hint" id="adjustMaxHint"></span>
                    </div>
                    <div class="owner-form-group">
                        <label for="adjustDate">Date</label>
                        <input type="date" id="adjustDate" name="adjustment_date" class="owner-input" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="owner-form-group">
                        <label for="adjustReason">Reason</label>
                        <select id="adjustReason" name="reason" class="owner-input" required>
                            <option value="physical_count">Physical count</option>
                            <option value="damaged">Damaged</option>
                            <option value="lost">Lost</option>
                            <option value="theft">Theft</option>
                            <option value="correction">Correction</option>
                            <option value="supplier_correction">Supplier correction</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="owner-form-group">
                        <label for="adjustRemarks">Remarks <span class="owner-form-optional">(optional)</span></label>
                        <input type="text" id="adjustRemarks" name="remarks" class="owner-input" maxlength="255">
                    </div>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" data-close-adjust>Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary">Save adjustment</button>
            </div>
        </form>
    </div>
</div>

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
                            <th>Items</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr><td colspan="3" class="owner-table-empty">No categories yet. Add your first one below.</td></tr>
                        <?php else: ?>
                            <?php foreach ($categories as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['category_name']) ?></td>
                                <td><?= (int)$c['item_count'] ?></td>
                                <td>
                                    <div class="owner-table-actions">
                                        <form method="post" action="category_delete.php" style="display:inline;"
                                              data-confirm="Delete this category? This can't be undone. Categories still in use by an item can't be deleted until those items are reassigned.">
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

            <form method="post" action="category_save.php">
                <?= csrf_field() ?>
                <div class="owner-form-grid">
                    <div class="owner-form-group">
                        <label for="new_category_name">New category name</label>
                        <input type="text" id="new_category_name" name="category_name" class="owner-input" placeholder="e.g. Packaging" required>
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-plus-circle" aria-hidden="true"></i> Add category</button>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    // -- Tabs (Items / Batches) ------------------------------------------
    const tabs = document.querySelectorAll('[data-inv-tab]');
    // Each tab now owns TWO cards -- its filters card and its table card -- so the
    // toggle works off the shared data-inv-panel attribute rather than a single id.
    const panelCards = document.querySelectorAll('[data-inv-panel]');

    function activateInvTab(key) {
        tabs.forEach(t => t.classList.toggle('is-active', t.getAttribute('data-inv-tab') === key));
        panelCards.forEach((el) => {
            el.style.display = (el.getAttribute('data-inv-panel') === key) ? '' : 'none';
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            activateInvTab(tab.getAttribute('data-inv-tab'));
        });
    });

    // Deep links from an inventory/expiry notification (config/inventory_alerts.php
    // + owner|manager|admin's includes/header.php notif-link functions) --
    // item_id/status target the Items tab, batch_id/expiry_status the Batches tab.
    const invUrlParams = new URLSearchParams(window.location.search);
    const requestedItemId = invUrlParams.get('item_id');
    const requestedStatus = invUrlParams.get('status');
    const requestedBatchId = invUrlParams.get('batch_id');
    const requestedExpiryStatus = invUrlParams.get('expiry_status');

    const requestedInvTab = invUrlParams.get('tab');
    if (requestedInvTab === 'batches' || requestedBatchId) activateInvTab('batches');

    // -- Pagination (shared helper -- Items applies a match filter on top,
    // Batches just paginates every row). ---------------------------------
    // `param` names a query-string key the current page is mirrored into, so
    // the page survives a reload. Editing an item posts to inventory_save.php
    // and comes back on a fresh request, which used to drop you on page 1 --
    // pagination lives only in this closure, so a reload reset it. Filtering
    // still resets to page 1 (resetPage), because the row you were looking at
    // may not be in the new result set at all.
    function makePager({ rows, pageSize, container, prevBtn, nextBtn, info, matchFn, onUpdate, param }) {
        const startAt = param ? parseInt(new URLSearchParams(location.search).get(param) || '1', 10) : 1;
        let currentPage = Number.isFinite(startAt) && startAt > 0 ? startAt : 1;

        // replaceState, not pushState: paging is not a navigation step, and
        // stacking history entries would make Back walk the pages one by one.
        function rememberPage() {
            if (!param) return;
            const url = new URL(location.href);
            if (currentPage > 1) { url.searchParams.set(param, currentPage); }
            else { url.searchParams.delete(param); }
            history.replaceState(null, '', url);
        }

        // The very first render is the page setting itself up, not the user
        // changing a filter -- so it must NOT reset to page 1, or the page
        // restored from the URL is thrown away before anything is drawn. Every
        // update after it resets as normal.
        let firstRun = true;
        function update(resetPage) {
            if (resetPage && !firstRun) currentPage = 1;
            firstRun = false;
            const matched = matchFn ? rows.filter(matchFn) : rows.slice();
            const totalPages = Math.max(1, Math.ceil(matched.length / pageSize));
            if (currentPage > totalPages) currentPage = totalPages;
            const start = (currentPage - 1) * pageSize;
            const matchedSet = new Set(matched.slice(start, start + pageSize));
            rows.forEach((row) => { row.style.display = matchedSet.has(row) ? '' : 'none'; });
            if (container) container.hidden = matched.length === 0 || totalPages <= 1;
            if (info) info.textContent = `Page ${currentPage} of ${totalPages}`;
            if (prevBtn) prevBtn.disabled = currentPage <= 1;
            if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
            if (onUpdate) onUpdate(matched.length);
            rememberPage();
        }
        if (prevBtn) prevBtn.addEventListener('click', () => { currentPage--; update(false); });
        if (nextBtn) nextBtn.addEventListener('click', () => { currentPage++; update(false); });
        return { update, page: () => currentPage };
    }

    // -- Items table filters -------------------------------------------------
    const invSearch     = document.getElementById('invFilterSearch');
    const invCategory   = document.getElementById('invFilterCategory');
    const invStatus     = document.getElementById('invFilterStatus');
    const invClearBtn   = document.getElementById('btnClearInvFilters');
    const invNoMatchRow = document.getElementById('invNoMatchRow');
    const invItemCount  = document.getElementById('invItemCount');
    const invRows       = Array.from(document.querySelectorAll('#inv-panel-items tbody tr[data-item-name]'));

    const invItemsPager = makePager({
        rows: invRows,
        pageSize: 10,
        container: document.getElementById('invItemsPagination'),
        prevBtn: document.getElementById('invItemsPagePrev'),
        nextBtn: document.getElementById('invItemsPageNext'),
        info: document.getElementById('invItemsPageInfo'),
        param: 'ip',
        matchFn: (row) => {
            // A specific item_id in the URL (from an inventory notification)
            // always wins -- shows exactly that one item, ignoring every other input.
            if (requestedItemId) return row.getAttribute('data-item-id') === requestedItemId;
            const q        = (invSearch ? invSearch.value : '').trim().toLowerCase();
            const category = invCategory ? invCategory.value : '';
            const status   = invStatus ? invStatus.value : '';
            const matchesSearch   = !q || row.getAttribute('data-item-name').includes(q);
            const matchesCategory = !category || row.getAttribute('data-category') === category;
            // Inactive items are archived -- hidden by default and whenever a
            // stock-level status is picked, only shown when "Inactive" is
            // explicitly selected. Keeps the dropdown's existing stock-status
            // options untouched while still defaulting the list to Active.
            const matchesStatus   = status === 'inactive'
                ? row.getAttribute('data-status') === 'inactive'
                : row.getAttribute('data-status') !== 'inactive' && (!status || row.getAttribute('data-status') === status);
            return matchesSearch && matchesCategory && matchesStatus;
        },
        onUpdate: (visibleCount) => {
            if (invNoMatchRow) invNoMatchRow.hidden = (invRows.length === 0 || visibleCount > 0);
            if (invItemCount) invItemCount.textContent = visibleCount + (visibleCount === 1 ? ' item' : ' items');
        },
    });

    function applyInvFilters() { invItemsPager.update(true); }

    if (invSearch)   invSearch.addEventListener('input', applyInvFilters);
    if (invCategory) invCategory.addEventListener('change', applyInvFilters);
    if (invStatus)   invStatus.addEventListener('change', applyInvFilters);
    if (invClearBtn) {
        invClearBtn.addEventListener('click', () => {
            if (invSearch)   invSearch.value = '';
            if (invCategory) invCategory.value = '';
            if (invStatus)   invStatus.value = '';
            applyInvFilters();
        });
    }
    if (requestedStatus && invStatus && !requestedItemId) invStatus.value = requestedStatus;
    invItemsPager.update(true);

    // -- Batches table filters + pagination -----------------------------------
    const invBatchSearch   = document.getElementById('invBatchFilterSearch');
    const invBatchStatus   = document.getElementById('invBatchFilterStatus');
    const invBatchClearBtn = document.getElementById('btnClearBatchFilters');
    const invBatchesRows   = Array.from(document.querySelectorAll('#inv-panel-batches tbody tr[data-batch-id]'));

    const invBatchesPager = makePager({
        rows: invBatchesRows,
        pageSize: 10,
        container: document.getElementById('invBatchesPagination'),
        prevBtn: document.getElementById('invBatchesPagePrev'),
        nextBtn: document.getElementById('invBatchesPageNext'),
        info: document.getElementById('invBatchesPageInfo'),
        param: 'bp',
        matchFn: (row) => {
            // A specific batch_id in the URL (from an expiry notification)
            // always wins -- shows exactly that one batch, ignoring the status filter.
            if (requestedBatchId) return row.getAttribute('data-batch-id') === requestedBatchId;
            // Set live by pollInventory() below when another user's sale/
            // adjustment/wastage empties this batch after page load -- the
            // initial query already excludes quantity_remaining = 0 batches,
            // so a depleted one should drop out of view the same way.
            if (row.dataset.depleted === '1') return false;
            const q      = (invBatchSearch ? invBatchSearch.value : '').trim().toLowerCase();
            const status = invBatchStatus ? invBatchStatus.value : '';
            const matchesSearch = !q || (row.getAttribute('data-batch-search') || '').includes(q);
            const matchesStatus = !status || row.getAttribute('data-expiry-status') === status;
            return matchesSearch && matchesStatus;
        },
    });

    function applyBatchFilters() { invBatchesPager.update(true); }

    if (invBatchSearch)   invBatchSearch.addEventListener('input', applyBatchFilters);
    if (invBatchStatus)   invBatchStatus.addEventListener('change', applyBatchFilters);
    if (invBatchClearBtn) {
        invBatchClearBtn.addEventListener('click', () => {
            if (invBatchSearch) invBatchSearch.value = '';
            if (invBatchStatus) invBatchStatus.value = '';
            applyBatchFilters();
        });
    }
    if (requestedExpiryStatus && invBatchStatus && !requestedBatchId) invBatchStatus.value = requestedExpiryStatus;
    invBatchesPager.update(true);

    // -- Add / edit item modal ----------------------------------------------
    const backdrop   = document.getElementById('addItemBackdrop');
    const openBtn    = document.getElementById('btnOpenAddItem');
    const closeBtn   = document.getElementById('btnCloseAddItem');
    const cancelBtn  = document.getElementById('btnCancelAddItem');
    const form       = document.getElementById('addItemForm');
    const modalTitle = document.getElementById('addItemTitle');
    const submitLabel = document.getElementById('addItemSubmitLabel');
    const itemIdField = document.getElementById('itemFormItemId');

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
        modalTitle.textContent = 'Add inventory item';
        submitLabel.textContent = 'Save Inventory Item';
        document.querySelectorAll('#addItemForm [data-combobox]').forEach((root) => {
            if (root.__comboboxReset) root.__comboboxReset();
        });
    }

    if (openBtn)   openBtn.addEventListener('click', () => { resetToAddMode(); openModal(); });
    if (closeBtn)  closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', () => { form.reset(); closeModal(); });

    // Click on the dark overlay (but not inside the dialog) closes it
    if (backdrop) {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) closeModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
    });

    // -- Add/edit item form: searchable comboboxes -------------------------------
    // Category / Base unit / Preferred supplier are all "type to filter"
    // dropdowns. Each wraps a visible text input (search + display) and a
    // hidden input that actually gets submitted with the form.
    function initCombobox(root) {
        const input    = root.querySelector('.owner-combobox-input');
        const hidden   = root.querySelector('input[type="hidden"]');
        const panel    = root.querySelector('.owner-combobox-panel');
        const clearBtn = root.querySelector('[data-combobox-clear]');
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
            if (clearBtn) clearBtn.hidden = false;
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
            if (hidden.value) { hidden.value = ''; if (clearBtn) clearBtn.hidden = true; }
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

        if (clearBtn) {
            clearBtn.hidden = !hidden.value;
            clearBtn.addEventListener('click', (e) => {
                e.preventDefault();
                hidden.value = '';
                input.value = '';
                clearBtn.hidden = true;
                input.focus();
            });
        }

        root.__comboboxReset = function () {
            hidden.value = '';
            input.value = '';
            input.classList.remove('is-invalid');
            if (clearBtn) clearBtn.hidden = true;
        };

        // Directly set both the hidden id and the visible label — used when
        // pre-filling the form for Edit, where typing-to-search doesn't apply.
        root.__comboboxSet = function (id, label) {
            if (id === null || id === undefined || id === '') {
                root.__comboboxReset();
                return;
            }
            hidden.value = String(id);
            input.value = label || '';
            if (clearBtn) clearBtn.hidden = false;
        };
    }

    document.querySelectorAll('#addItemForm [data-combobox]').forEach(initCombobox);

    // -- Edit item: populate the shared Add/Edit modal from a row's data-item --
    // Stamp the page the table is showing onto the form, so a save can come
    // back to it. Read at click time rather than at load, because paging does
    // not reload the page.
    function stampReturnPage() {
        const f = document.getElementById('itemFormReturnPage');
        if (f && invItemsPager) f.value = invItemsPager.page();
    }
    document.querySelectorAll('.owner-btn-edit-item').forEach((b) => b.addEventListener('click', stampReturnPage));
    const btnAddItemEl = document.getElementById('btnAddItem');
    if (btnAddItemEl) btnAddItemEl.addEventListener('click', stampReturnPage);

    document.querySelectorAll('.owner-btn-edit-item').forEach((btn) => {
        btn.addEventListener('click', () => {
            let item;
            try { item = JSON.parse(btn.getAttribute('data-item')); } catch (e) { return; }

            form.reset();
            itemIdField.value = item.item_id;
            document.getElementById('item_name').value      = item.item_name || '';
            document.getElementById('critical_level').value = item.critical_level || '';
            document.getElementById('reorder_level').value  = item.reorder_level || '';
            document.getElementById('lead_time_days').value = item.lead_time_days || '';
            document.getElementById('safety_stock_qty').value = item.safety_stock_qty || '';
            document.getElementById('is_active').checked    = !!item.is_active;

            const categoryRoot = document.getElementById('category_search').closest('[data-combobox]');
            if (categoryRoot && categoryRoot.__comboboxSet) categoryRoot.__comboboxSet(item.category_id, item.category_name);

            const unitRoot = document.getElementById('base_unit_search').closest('[data-combobox]');
            if (unitRoot && unitRoot.__comboboxSet) unitRoot.__comboboxSet(item.base_unit_id, item.unit_label);

            const supplierRoot = document.getElementById('supplier_search').closest('[data-combobox]');
            if (supplierRoot && supplierRoot.__comboboxSet) supplierRoot.__comboboxSet(item.preferred_supplier_id, item.preferred_supplier_name);

            modalTitle.textContent = 'Edit inventory item';
            submitLabel.textContent = 'Save Changes';
            openModal();
        });
    });

    // -- Add/edit item form: inline validation ------------------------------------
    function fieldGroup(el) {
        return el.closest('.owner-form-group');
    }

    function showFieldError(groupEl, visibleEl, message) {
        if (visibleEl) visibleEl.classList.add('is-invalid');
        const err = groupEl ? groupEl.querySelector('.owner-form-error') : null;
        if (err) err.textContent = message;
    }

    function clearFieldError(groupOrRoot, visibleEl) {
        const groupEl = groupOrRoot.classList && groupOrRoot.classList.contains('owner-form-group')
            ? groupOrRoot
            : fieldGroup(groupOrRoot);
        if (visibleEl) visibleEl.classList.remove('is-invalid');
        const err = groupEl ? groupEl.querySelector('.owner-form-error') : null;
        if (err) err.textContent = '';
    }

    if (form) {
        const addItemAlert = document.getElementById('addItemFormAlert');

        // Required-field checks. Combobox fields validate the hidden input's
        // value but report/focus on the visible search input next to it.
        const requiredChecks = [
            { hidden: document.getElementById('item_name'),   visible: document.getElementById('item_name'),      message: 'Item name is required.' },
            { hidden: document.getElementById('category_id'), visible: document.getElementById('category_search'), message: 'Select a category.' },
            { hidden: document.getElementById('base_unit_id'),visible: document.getElementById('base_unit_search'),message: 'Select a base unit of measure.' },
            { hidden: document.getElementById('reorder_level'),visible: document.getElementById('reorder_level'), message: 'Enter a reorder level.' },
        ];

        // Negative values are blocked at the keyboard, but this is the
        // belt-and-suspenders check run on submit.
        const nonNegativeFields = [
            document.getElementById('critical_level'),
            document.getElementById('reorder_level'),
            document.getElementById('safety_stock_qty'),
        ];

        // Critical level must stay <= reorder level when both are filled in.
        // Critical level is optional, so the check only runs when both sides
        // have a value.
        const criticalEl = document.getElementById('critical_level');
        const reorderEl  = document.getElementById('reorder_level');

        function checkStockLevelOrder() {
            let ok = true;

            if (criticalEl && reorderEl && criticalEl.value !== '' && reorderEl.value !== '') {
                const group = fieldGroup(criticalEl);
                if (parseFloat(criticalEl.value) > parseFloat(reorderEl.value)) {
                    showFieldError(group, criticalEl, 'Critical level should be lower than (or equal to) the reorder level.');
                    ok = false;
                }
            }

            return ok;
        }

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

            nonNegativeFields.forEach((el) => {
                if (!el) return;
                const group = fieldGroup(el);
                if (el.value !== '' && parseFloat(el.value) < 0) {
                    showFieldError(group, el, 'This value cannot be negative.');
                    hasError = true;
                }
            });

            [criticalEl].forEach((el) => {
                if (el) clearFieldError(fieldGroup(el), el);
            });
            if (!checkStockLevelOrder()) hasError = true;

            if (hasError) {
                e.preventDefault();
                if (addItemAlert) addItemAlert.hidden = false;
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            } else if (addItemAlert) {
                addItemAlert.hidden = true;
            }
        });

        // Prevent typing negative numbers / exponent notation in numeric fields.
        form.querySelectorAll('input[type="number"]').forEach((numInput) => {
            numInput.addEventListener('keydown', (e) => {
                if (e.key === '-' || e.key === 'e' || e.key === 'E') e.preventDefault();
            });
            numInput.addEventListener('input', () => {
                if (numInput.value !== '' && parseFloat(numInput.value) < 0) {
                    numInput.value = '';
                }
            });
        });

        form.addEventListener('reset', () => {
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            form.querySelectorAll('.owner-form-error').forEach(el => { el.textContent = ''; });
            if (addItemAlert) addItemAlert.hidden = true;
        });
    }

    // -- Manage categories modal -------------------------------------------
    const catBackdrop  = document.getElementById('manageCategoriesBackdrop');
    const catOpenBtn   = document.getElementById('btnOpenManageCategories');
    const catOpenLink  = document.getElementById('linkManageCategoriesFromForm');
    const catCloseBtn  = document.getElementById('btnCloseManageCategories');

    function openCatModal(e) {
        if (e) e.preventDefault();
        catBackdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }

    function closeCatModal() {
        catBackdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    if (catOpenBtn)  catOpenBtn.addEventListener('click', openCatModal);
    if (catOpenLink) catOpenLink.addEventListener('click', openCatModal);
    if (catCloseBtn) catCloseBtn.addEventListener('click', closeCatModal);

    if (catBackdrop) {
        catBackdrop.addEventListener('click', (e) => {
            if (e.target === catBackdrop) closeCatModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && catBackdrop.classList.contains('is-open')) closeCatModal();
    });

    // ---- Batch detail (Batches tab) ---------------------------------------
    // Body is fetched per click rather than pre-rendered: the movement history
    // it exists to show runs to thousands of rows across the on-hand batches.
    const batchBackdrop = document.getElementById('batchModalBackdrop');
    const batchBody     = document.getElementById('batchModalBody');

    function closeBatchModal() {
        batchBackdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    document.querySelectorAll('.owner-btn-view-batch').forEach((btn) => {
        btn.addEventListener('click', () => {
            batchBody.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>';
            batchBackdrop.classList.add('is-open');
            document.body.classList.add('owner-modal-open');

            fetch('batch_fragment.php?id=' + btn.getAttribute('data-batch-id'))
                .then((res) => { if (!res.ok) throw new Error(res.status); return res.text(); })
                .then((html) => { batchBody.innerHTML = html; })
                .catch(() => {
                    batchBody.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-danger);">Couldn\'t load this batch.</div>';
                });
        });
    });

    /* ---- Waste / Adjust, recorded from the batch row -------------------------
       Both modals are plain forms posting to the existing save handlers, so
       every validation, permission check and ledger write stays exactly where
       it was -- this only removes the page jump. The quantity cap is a hint
       plus a max attribute; the server still enforces it, because a max
       attribute is a convenience, not a control. */
    function wireBatchActionModal(backdropId, btnClass, closeAttr, fields) {
        var backdrop = document.getElementById(backdropId);
        if (!backdrop) return;

        function open(btn) {
            var remaining = parseFloat(btn.getAttribute('data-remaining')) || 0;
            var unit = btn.getAttribute('data-unit') || '';
            document.getElementById(fields.itemId).value  = btn.getAttribute('data-item-id');
            document.getElementById(fields.batchId).value = btn.getAttribute('data-batch-id');
            document.getElementById(fields.context).textContent =
                btn.getAttribute('data-item-name') + ' — batch ' + btn.getAttribute('data-batch-number');

            var qty = document.getElementById(fields.qty);
            qty.value = '';
            qty.max = remaining > 0 ? remaining : '';
            document.getElementById(fields.maxHint).textContent =
                remaining > 0 ? ('On this batch: ' + remaining + ' ' + unit) : 'This batch is empty.';

            backdrop.classList.add('is-open');
            document.body.classList.add('owner-modal-open');
            qty.focus();
        }
        function close() {
            backdrop.classList.remove('is-open');
            document.body.classList.remove('owner-modal-open');
        }

        document.querySelectorAll('.' + btnClass).forEach(function (b) {
            b.addEventListener('click', function () { open(b); });
        });
        backdrop.querySelectorAll('[' + closeAttr + ']').forEach(function (b) {
            b.addEventListener('click', close);
        });
        backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && backdrop.classList.contains('is-open')) close();
        });
    }

    wireBatchActionModal('wasteBackdrop', 'js-batch-waste', 'data-close-waste', {
        itemId: 'wasteItemId', batchId: 'wasteBatchId', context: 'wasteContext',
        qty: 'wasteQuantity', maxHint: 'wasteMaxHint'
    });
    wireBatchActionModal('adjustBackdrop', 'js-batch-adjust', 'data-close-adjust', {
        itemId: 'adjustItemId', batchId: 'adjustBatchId', context: 'adjustContext',
        qty: 'adjustQuantity', maxHint: 'adjustMaxHint'
    });

    /* An increase is not capped by what is on the batch -- you are adding. */
    (function () {
        var dir = document.getElementById('adjustDirection');
        var qty = document.getElementById('adjustQuantity');
        var hint = document.getElementById('adjustMaxHint');
        if (!dir || !qty) return;
        var capped = '';
        dir.addEventListener('change', function () {
            if (dir.value === 'increase') { capped = qty.max; qty.max = ''; hint.dataset.was = hint.textContent; hint.textContent = 'Adding to this batch.'; }
            else if (capped !== '') { qty.max = capped; if (hint.dataset.was) hint.textContent = hint.dataset.was; }
        });
    })();

    document.getElementById('btnCloseBatchModal').addEventListener('click', closeBatchModal);
    document.getElementById('btnCloseBatchModalFooter').addEventListener('click', closeBatchModal);
    batchBackdrop.addEventListener('click', (e) => { if (e.target === batchBackdrop) closeBatchModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && batchBackdrop.classList.contains('is-open')) closeBatchModal();
    });

    // ---- Live updates (polling) -------------------------------------------
    // This app has no WebSocket/SSE infrastructure, so a short-interval poll
    // is the mechanism -- same convention as the notification bell in
    // owner|manager's header.php (15s, paused while the tab is hidden). Only
    // EXISTING rows' live figures (stock, status, batch remaining) are kept
    // fresh this way; a brand new item or a just-archived one still needs a
    // real page reload to appear/disappear -- who exists changes far less
    // often than what they currently have on hand, and is one reload away
    // regardless. Filters, pagination, scroll position and any open modal
    // are never touched by a poll.
    const INV_POLL_INTERVAL_MS = 15000;
    let invPollTimer = null;

    const INV_ITEM_STATUS_MAP = {
        inactive:     { cls: 'is-inactive', text: 'Inactive' },
        out_of_stock: { cls: 'is-critical', text: 'Out of stock' },
        critical:     { cls: 'is-critical', text: 'Critical' },
        low:          { cls: 'is-danger',   text: 'Low stock' },
    };
    function invItemStatusInfo(status) {
        return INV_ITEM_STATUS_MAP[status] || { cls: 'is-success', text: 'In stock' };
    }
    function invItemBarClass(status) {
        if (status === 'out_of_stock' || status === 'critical') return ' is-critical';
        if (status === 'low') return ' is-low';
        return '';
    }

    function pollInventory() {
        fetch('inventory_live.php', { credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : null)
            .then((data) => {
                if (!data || data.error) return;

                (data.items || []).forEach((item) => {
                    const row = document.querySelector('#inv-panel-items tbody tr[data-item-id="' + item.item_id + '"]');
                    if (!row) return; // added by someone else after this page loaded -- needs a reload to appear

                    const stockText = row.querySelector('.inv-stock-text');
                    if (stockText) stockText.textContent = item.stock_display;
                    const bar = row.querySelector('.inv-stock-bar');
                    if (bar) {
                        bar.style.width = item.bar_pct + '%';
                        bar.className = 'owner-stock-bar-fill inv-stock-bar' + invItemBarClass(item.stock_status);
                    }
                    const pill = row.querySelector('.inv-item-status-pill');
                    if (pill) {
                        const info = invItemStatusInfo(item.stock_status);
                        pill.className = 'owner-status-pill inv-item-status-pill ' + info.cls;
                        pill.textContent = info.text;
                    }
                    row.setAttribute('data-status', item.stock_status);

                    // So a click on Edit right after a poll opens with current
                    // values instead of whatever was true at page load.
                    const editBtn = row.querySelector('.owner-btn-edit-item');
                    if (editBtn) editBtn.setAttribute('data-item', JSON.stringify(item.row_data));
                });
                invItemsPager.update(false);

                // getInventoryBatchesSnapshot() (same query the initial page load
                // uses) excludes quantity_remaining = 0 outright -- a batch that
                // hits zero doesn't arrive here WITH remaining_raw 0, it simply
                // stops being in the response at all. So depletion is detected by
                // absence, not by a value: any batch_id already on the page that
                // this poll no longer mentions has been fully depleted since.
                const batchIdsSeen = new Set();
                (data.batches || []).forEach((batch) => {
                    batchIdsSeen.add(String(batch.batch_id));
                    const row = document.querySelector('#inv-panel-batches tbody tr[data-batch-id="' + batch.batch_id + '"]');
                    if (!row) return; // a batch received after this page loaded -- needs a reload to appear

                    const remainingCell = row.querySelector('.inv-batch-remaining');
                    if (remainingCell) remainingCell.textContent = batch.remaining_display;
                    const unitCostCell = row.querySelector('.inv-batch-unitcost');
                    if (unitCostCell) unitCostCell.textContent = '₱' + batch.unit_cost_display;
                    const pill = row.querySelector('.inv-batch-status-pill');
                    if (pill) {
                        pill.className = 'owner-status-pill inv-batch-status-pill ' + batch.status_class;
                        pill.textContent = batch.status_label;
                    }
                    row.setAttribute('data-expiry-status', batch.expiry_status);
                    row.dataset.depleted = '0';

                    // Keeps the Waste/Adjust modals' "max quantity" hint honest --
                    // both read data-remaining fresh at click time, not at page load.
                    row.querySelectorAll('.js-batch-waste, .js-batch-adjust').forEach((btn) => {
                        btn.setAttribute('data-remaining', batch.remaining_raw);
                    });
                });
                invBatchesRows.forEach((row) => {
                    const bid = row.getAttribute('data-batch-id');
                    if (!batchIdsSeen.has(bid)) row.dataset.depleted = '1';
                });
                invBatchesPager.update(false);

                if (data.summary) {
                    const s = data.summary;
                    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
                    setText('invSummaryLowStock', s.low_stock);
                    setText('invSummaryCritical', s.critical_stock);
                    setText('invSummaryExpiring', s.expiring_soon);
                    setText('invSummaryValue', s.total_value);

                    const banner = document.getElementById('invExpiredBanner');
                    const bannerText = document.getElementById('invExpiredBannerText');
                    if (banner && bannerText) {
                        if (s.expired > 0) {
                            bannerText.textContent = s.expired + (s.expired === 1 ? ' batch' : ' batches')
                                + ' past expiry date and still marked with remaining stock. Log these as waste to keep FIFO deductions accurate.';
                            banner.style.display = '';
                        } else {
                            banner.style.display = 'none';
                        }
                    }
                }
            })
            .catch(() => { /* a missed poll just tries again next interval */ });
    }

    function startInvPolling() {
        if (invPollTimer) return;
        pollInventory();
        invPollTimer = setInterval(pollInventory, INV_POLL_INTERVAL_MS);
    }
    function stopInvPolling() {
        if (!invPollTimer) return;
        clearInterval(invPollTimer);
        invPollTimer = null;
    }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopInvPolling(); else startInvPolling();
    });
    if (!document.hidden) startInvPolling();
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
</body>
</html>
