<?php
/**
 * inventory/category_save.php
 *
 * Create a new inventory category from the "Manage categories" modal on
 * inventory.php. There's no edit mode — categories are add-only from this
 * form; renaming/editing can be added later if needed.
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

$categoryName = trim((string)($_POST['category_name'] ?? ''));

if ($categoryName === '' || mb_strlen($categoryName) > 100) {
    flash_set('error', 'Please enter a category name (up to 100 characters).');
    header('Location: inventory.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $dupCheck = $pdo->prepare('SELECT category_id FROM inventory_categories WHERE category_name = ? LIMIT 1');
    $dupCheck->execute([$categoryName]);
    if ($dupCheck->fetch()) {
        flash_set('error', 'A category with that name already exists.');
        header('Location: inventory.php');
        exit;
    }

    $pdo->prepare('INSERT INTO inventory_categories (category_name) VALUES (?)')
        ->execute([$categoryName]);
    $categoryId = $pdo->lastInsertId();

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Inventory', 'Create category', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Create category: {$categoryName} (category_id {$categoryId})",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort — never block the actual save on it.
    }

    flash_set('success', "Category \"{$categoryName}\" added.");
} catch (PDOException $e) {
    error_log('category_save.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong saving this category. Please try again.');
}

header('Location: inventory.php');
exit;