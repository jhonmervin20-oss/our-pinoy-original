<?php
/**
 * owner/menu_management/menu_item_toggle_status.php
 *
 * Flip a menu item's is_active flag ("Archive"/"Reactivate"). Mirrors
 * owner/supplier_toggle_status.php.
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

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare('SELECT item_name, is_active FROM menu_items WHERE item_id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        flash_set('error', 'That menu item no longer exists.');
    } else {
        $newStatus = (int)$item['is_active'] === 1 ? 0 : 1;

        $pdo->prepare('UPDATE menu_items SET is_active = ? WHERE item_id = ?')
            ->execute([$newStatus, $itemId]);

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Menu Management', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $newStatus === 1 ? 'Activate menu item' : 'Archive menu item',
                ($newStatus === 1 ? 'Activated ' : 'Archived ') . $item['item_name'],
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }

        flash_set('success', $newStatus === 1 ? 'Menu item activated.' : 'Menu item archived.');
    }
} catch (PDOException $e) {
    error_log('menu_item_toggle_status.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating this menu item. Please try again.');
}

header('Location: menu_items.php');
exit;
