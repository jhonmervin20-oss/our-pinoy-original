<?php
/**
 * owner/menu_management/menu_item_price_save.php
 *
 * "Update selling price" action from the Pricing card on
 * recipe_builder.php -- writes the new price to menu_items.selling_price
 * and logs one price_history row (old -> new). Never writes to
 * menu_item_costing itself (that's costing_recalculate.php's job; a price
 * change alone doesn't require a full recipe recompute).
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/flash.php';

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

$itemId = trim((string)($_POST['item_id'] ?? ''));
if ($itemId === '' || !ctype_digit($itemId)) {
    flash_set('error', 'Invalid menu item.');
    header('Location: menu_items.php');
    exit;
}
$itemId = (int)$itemId;
$redirectTo = 'recipe_builder.php?item_id=' . $itemId;

$newPrice = trim((string)($_POST['new_price'] ?? ''));

if ($newPrice === '' || !is_numeric($newPrice) || (float)$newPrice < 0) {
    flash_set('error', 'Invalid price.');
    header('Location: ' . $redirectTo);
    exit;
}
$newPrice = round((float)$newPrice, 2);

try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT item_name, selling_price FROM menu_items WHERE item_id = ? FOR UPDATE');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $pdo->rollBack();
        flash_set('error', 'That menu item no longer exists.');
        header('Location: menu_items.php');
        exit;
    }

    $oldPrice = (float)$item['selling_price'];

    $pdo->prepare('UPDATE menu_items SET selling_price = ? WHERE item_id = ?')->execute([$newPrice, $itemId]);

    $pdo->prepare(
        'INSERT INTO price_history (menu_item_id, old_price, new_price, changed_by, reason) VALUES (?, ?, ?, ?, ?)'
    )->execute([$itemId, $oldPrice, $newPrice, Session::getUserId(), 'Updated from Pricing suggestion']);

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Menu Management', 'Update selling price', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Updated {$item['item_name']} selling price: {$oldPrice} -> {$newPrice}",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    $pdo->commit();
    flash_set('success', 'Selling price updated.');
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('menu_item_price_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating the selling price. Please try again.');
}

header('Location: ' . $redirectTo);
exit;
