<?php
/**
 * owner/menu_management/menu_item_save.php
 *
 * Create or update a menu item, including image upload. Mirrors
 * owner/supplier_save.php's shape; image handling follows
 * the standard move_uploaded_file() pattern (the only other
 * upload precedent in this codebase).
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: menu_items.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: menu_items.php');
    exit;
}

$itemId            = trim((string)($_POST['item_id'] ?? ''));
$isEdit            = $itemId !== '' && ctype_digit($itemId);
$menuCode          = trim($_POST['menu_code'] ?? '');
$categoryId        = trim((string)($_POST['category_id'] ?? ''));
$itemName          = trim($_POST['item_name'] ?? '');
$description       = trim($_POST['description'] ?? '');
$sellingPrice      = trim((string)($_POST['selling_price'] ?? ''));
$isVatExempt       = isset($_POST['is_vat_exempt']) ? 1 : 0;
$availableDineIn   = isset($_POST['available_dine_in']) ? 1 : 0;
$availableTakeout  = isset($_POST['available_takeout']) ? 1 : 0;
$packagingNotRequired = isset($_POST['packaging_not_required']) ? 1 : 0;
$isAvailable       = isset($_POST['is_available']) ? 1 : 0;
$existingImageUrl  = trim((string)($_POST['existing_image_url'] ?? ''));

// is_active is deliberately NOT read from this form. A new item is always
// created active, and an existing item's archived state belongs to the Archive
// action (menu_item_toggle_status.php) alone -- when both could set it, saving
// an unrelated edit on an archived item silently un-archived it.

$errors = [];

if ($menuCode === '' || mb_strlen($menuCode) > 30) {
    $errors[] = 'Please enter a menu code (up to 30 characters).';
}
if ($categoryId === '' || !ctype_digit($categoryId)) {
    $errors[] = 'Please select a category.';
}
if ($itemName === '' || mb_strlen($itemName) > 150) {
    $errors[] = 'Please enter an item name (up to 150 characters).';
}
if ($sellingPrice === '' || !is_numeric($sellingPrice) || (float)$sellingPrice < 0) {
    $errors[] = 'Please enter a valid selling price (0 or more).';
}

$imageUrl = $existingImageUrl !== '' ? $existingImageUrl : null;

if (!$errors) {
    try {
        $imageUrl = handleMenuImageUpload() ?? $imageUrl;
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $catCheck = $pdo->prepare('SELECT category_id FROM menu_categories WHERE category_id = ?');
        $catCheck->execute([$categoryId]);
        if (!$catCheck->fetch()) {
            $errors[] = 'That category no longer exists.';
        }

        $dupCheck = $isEdit
            ? $pdo->prepare('SELECT item_id FROM menu_items WHERE menu_code = ? AND item_id != ?')
            : $pdo->prepare('SELECT item_id FROM menu_items WHERE menu_code = ?');
        $isEdit ? $dupCheck->execute([$menuCode, $itemId]) : $dupCheck->execute([$menuCode]);
        if ($dupCheck->fetch()) {
            $errors[] = 'Another menu item already uses this code.';
        }

        if (!$errors) {
            $descriptionValue = $description !== '' ? $description : null;
            $sellingPriceValue = round((float)$sellingPrice, 2);

            if ($isEdit) {
                // is_active is intentionally absent from this UPDATE -- see the
                // note by the POST reads above.
                $stmt = $pdo->prepare(
                    "UPDATE menu_items SET
                        menu_code = ?, category_id = ?, item_name = ?, description = ?,
                        image_url = ?, selling_price = ?, is_vat_exempt = ?, is_available = ?,
                        available_dine_in = ?, available_takeout = ?, packaging_not_required = ?
                     WHERE item_id = ?"
                );
                $stmt->execute([
                    $menuCode, $categoryId, $itemName, $descriptionValue,
                    $imageUrl, $sellingPriceValue, $isVatExempt, $isAvailable,
                    $availableDineIn, $availableTakeout, $packagingNotRequired,
                    $itemId,
                ]);
                $logAction = 'Update menu item';
            } else {
                // New items are always active; the column's own DEFAULT 1 covers it.
                $stmt = $pdo->prepare(
                    "INSERT INTO menu_items
                        (menu_code, category_id, item_name, description, image_url,
                         selling_price, is_vat_exempt, is_available, available_dine_in, available_takeout, packaging_not_required)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $menuCode, $categoryId, $itemName, $descriptionValue, $imageUrl,
                    $sellingPriceValue, $isVatExempt, $isAvailable, $availableDineIn, $availableTakeout, $packagingNotRequired,
                ]);
                $itemId    = $pdo->lastInsertId();
                $logAction = 'Create menu item';
            }

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Menu Management', ?, ?, ?)"
                )->execute([
                    Session::getUserId(),
                    $logAction,
                    "{$logAction}: {$itemName} (item_id {$itemId})",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort.
            }

            flash_set('success', $isEdit ? 'Menu item updated.' : 'Menu item created.');
        }
    } catch (PDOException $e) {
        error_log('menu_item_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this menu item. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_item_modal'] = true;
}

header('Location: menu_items.php');
exit;
