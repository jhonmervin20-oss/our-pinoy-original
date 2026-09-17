<?php
/**
 * customer/menu.php
 *
 * Read-only menu browsing for the logged-in customer app -- search +
 * category filter chips above a responsive card grid.
 *
 * Reuses the exact same card component (.ca-menu-card family, in
 * make-reservation.css) as the booking wizard's advance-order step
 * (make_reservation.php). Pure display -- no stock/availability badges here
 * on purpose (unlike the advance-order step, which needs them since it's an
 * actual ordering flow); this page is just "what's on the menu."
 *
 * Unlike the advance-order step (which re-renders the grid from a JS
 * MENU_CATALOG array, since it also needs an interactive cart), this page
 * has no ordering/cart action -- every card is rendered once, server-side,
 * and search/category filtering is a plain client-side [hidden] toggle
 * (menu.js) matching this page's own established pattern. Only
 * is_active=1 AND is_available=1 items are queried at all (an item the
 * owner has manually taken off sale isn't shown here).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/menu_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['customer'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'menu';

$pdo = Database::getInstance()->getConnection();

$items = $pdo->query(
    "SELECT mi.item_id, mi.item_name, mi.description, mi.image_url, mi.selling_price, mi.created_at,
            mc.category_id, mc.category_name
     FROM menu_items mi
     LEFT JOIN menu_categories mc ON mc.category_id = mi.category_id
     WHERE mi.is_active = 1 AND mi.is_available = 1
     ORDER BY mc.category_name ASC, mi.item_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Categories in order of first appearance among real items (not a fixed
// list) -- same convention make-reservation.js's getMenuCategories() uses.
$categories = [];
$seenCategoryIds = [];
foreach ($items as $item) {
    $catId = $item['category_id'] ?? 0;
    if (!isset($seenCategoryIds[$catId])) {
        $seenCategoryIds[$catId] = true;
        $categories[] = ['id' => $catId, 'name' => $item['category_name'] ?? 'Other'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menu | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">
<link rel="stylesheet" href="assets/css/menu.css?v=<?= filemtime(__DIR__ . '/assets/css/menu.css') ?>">
</head>
<body class="ca-body">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="ca-content">
    <div class="ca-res-page-head">
        <div>
            <h1 class="ca-welcome-title">Menu</h1>
            <p class="ca-welcome-sub">What's cooking at OPO!</p>
        </div>
    </div>

    <?php if (empty($items)): ?>
        <div class="ca-card">
            <div class="ca-empty-state">
                <i class="ph ph-fork-knife" aria-hidden="true"></i>
                The menu isn't available online yet — please check with the restaurant directly.
            </div>
        </div>
    <?php else: ?>
        <div class="ca-menu-toolbar">
            <label class="ca-menu-search">
                <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="menuSearch" placeholder="Search menu&hellip;" autocomplete="off" aria-label="Search menu">
            </label>
            <div class="ca-menu-filter-chips" id="menuChips">
                <button type="button" class="ca-menu-filter-chip is-active" data-filter-category="all">All</button>
                <?php foreach ($categories as $cat): ?>
                    <button type="button" class="ca-menu-filter-chip" data-filter-category="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ca-menu-grid" id="menuGrid">
            <?php foreach ($items as $item): ?>
                <div class="ca-menu-card"
                     data-item-id="<?= (int)$item['item_id'] ?>"
                     data-item-category="<?= (int)($item['category_id'] ?? 0) ?>"
                     data-item-name="<?= htmlspecialchars(mb_strtolower($item['item_name'])) ?>">
                    <div class="ca-menu-card-img-wrap">
                        <?php if ($item['image_url']): ?>
                            <!-- image_url is stored relative to owner/ (menu items are
                                 managed from the owner panel) -- customer/ is a sibling
                                 folder, so it needs the same "../owner/" prefix pos.php
                                 uses to resolve to the real uploaded file. -->
                            <img class="ca-menu-card-img" src="../owner/<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="ca-menu-card-img-placeholder"><i class="ph ph-fork-knife" aria-hidden="true"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="ca-menu-card-body">
                        <div class="ca-menu-card-name"><?= htmlspecialchars($item['item_name']) ?></div>
                        <?php if ($item['description']): ?>
                            <div class="ca-menu-card-desc"><?= htmlspecialchars($item['description']) ?></div>
                        <?php endif; ?>
                        <div class="ca-menu-card-footer">
                            <span class="ca-menu-card-price"><?= '&#8369;' . number_format((float)$item['selling_price'], 2) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="ca-empty-state" id="menuNoMatch" hidden>
            <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
            No items match your search.
        </div>
    <?php endif; ?>
</main>

<script src="assets/js/menu.js?v=<?= filemtime(__DIR__ . '/assets/js/menu.js') ?>"></script>

</body>
</html>
