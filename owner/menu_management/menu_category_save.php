<?php
/**
 * owner/menu_management/menu_category_save.php
 *
 * Create or update a menu category.
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

$categoryId   = trim((string)($_POST['category_id'] ?? ''));
$isEdit       = $categoryId !== '' && ctype_digit($categoryId);
$categoryName = trim($_POST['category_name'] ?? '');

$errors = [];

if ($categoryName === '' || mb_strlen($categoryName) > 100) {
    $errors[] = 'Please enter a category name (up to 100 characters).';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $dupCheck = $isEdit
            ? $pdo->prepare('SELECT category_id FROM menu_categories WHERE category_name = ? AND category_id != ?')
            : $pdo->prepare('SELECT category_id FROM menu_categories WHERE category_name = ?');
        $isEdit ? $dupCheck->execute([$categoryName, $categoryId]) : $dupCheck->execute([$categoryName]);

        if ($dupCheck->fetch()) {
            $errors[] = 'Another category already uses this name.';
        } else {
            if ($isEdit) {
                $stmt = $pdo->prepare(
                    'UPDATE menu_categories SET category_name = ? WHERE category_id = ?'
                );
                $stmt->execute([
                    $categoryName,
                    $categoryId,
                ]);
                $logAction = 'Update menu category';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO menu_categories (category_name) VALUES (?)'
                );
                $stmt->execute([
                    $categoryName,
                ]);
                $categoryId = $pdo->lastInsertId();
                $logAction  = 'Create menu category';
            }

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Menu Management', ?, ?, ?)"
                )->execute([
                    Session::getUserId(),
                    $logAction,
                    "{$logAction}: {$categoryName} (category_id {$categoryId})",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort.
            }

            flash_set('success', $isEdit ? 'Category updated.' : 'Category created.');
        }
    } catch (PDOException $e) {
        error_log('menu_category_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this category. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_category_modal'] = true;
}

header('Location: menu_items.php');
exit;
