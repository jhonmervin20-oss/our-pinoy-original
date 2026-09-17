<?php
/**
 * inventory/category_delete.php
 *
 * Deletes an inventory category. category_id is NOT NULL on
 * inventory_items with a restricting foreign key, so a category still
 * assigned to any item can't actually be deleted — checked explicitly
 * here first so that shows up as a friendly message instead of a raw
 * SQL error.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inventory.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: inventory.php');
    exit;
}

$categoryId = trim((string)($_POST['category_id'] ?? ''));

if ($categoryId === '' || !ctype_digit($categoryId)) {
    flash_set('error', 'Invalid category.');
    header('Location: inventory.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare('SELECT category_name FROM inventory_categories WHERE category_id = ?');
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$category) {
        flash_set('error', 'That category no longer exists.');
        header('Location: inventory.php');
        exit;
    }

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE category_id = ?');
    $inUse->execute([$categoryId]);
    $itemCount = (int)$inUse->fetchColumn();

    if ($itemCount > 0) {
        flash_set('error', "Can't delete \"{$category['category_name']}\" — {$itemCount} item" . ($itemCount === 1 ? '' : 's') . ' still use it. Reassign ' . ($itemCount === 1 ? 'it' : 'them') . ' to a different category first.');
        header('Location: inventory.php');
        exit;
    }

    $pdo->prepare('DELETE FROM inventory_categories WHERE category_id = ?')->execute([$categoryId]);

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Inventory', 'Delete category', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Delete category: {$category['category_name']} (category_id {$categoryId})",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    flash_set('success', "Category \"{$category['category_name']}\" deleted.");
} catch (PDOException $e) {
    error_log('category_delete.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong deleting this category. Please try again.');
}

header('Location: inventory.php');
exit;
