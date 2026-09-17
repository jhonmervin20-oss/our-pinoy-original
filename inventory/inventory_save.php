<?php
/**
 * inventory/inventory_save.php
 *
 * Create or update an inventory item. Edit mode is triggered by a
 * non-empty `item_id` field. Shared by the Owner and Manager panels
 * (same as inventory.php itself).
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

/**
 * Which page of the Items table to come back to.
 *
 * The table paginates in the browser, so a plain redirect to inventory.php
 * always landed on page 1 -- edit something on page 3 and you lost your place.
 * Bounded and cast to an int: this is echoed into a Location header.
 */
$returnPage = (int)($_POST['return_page'] ?? 1);
$backTo = 'inventory.php' . ($returnPage > 1 ? '?ip=' . $returnPage : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $backTo);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $backTo);
    exit;
}

$itemId          = trim((string)($_POST['item_id'] ?? ''));
$isEdit          = $itemId !== '' && ctype_digit($itemId);
$itemName        = trim($_POST['item_name'] ?? '');
$categoryId      = trim((string)($_POST['category_id'] ?? ''));
$baseUnitId      = trim((string)($_POST['base_unit_id'] ?? ''));
$criticalLevel   = trim((string)($_POST['critical_level'] ?? ''));
$reorderLevel    = trim((string)($_POST['reorder_level'] ?? ''));
$supplierId      = trim((string)($_POST['preferred_supplier_id'] ?? ''));
$leadTimeDays    = trim((string)($_POST['lead_time_days'] ?? ''));
$safetyStockQty  = trim((string)($_POST['safety_stock_qty'] ?? ''));

$isActive        = isset($_POST['is_active']) ? 1 : 0;

$errors = [];

if ($itemName === '' || mb_strlen($itemName) > 150) {
    $errors[] = 'Please enter an item name (up to 150 characters).';
}
if ($categoryId === '' || !ctype_digit($categoryId)) {
    $errors[] = 'Please select a category.';
}
if ($baseUnitId === '' || !ctype_digit($baseUnitId)) {
    $errors[] = 'Please select a base unit of measure.';
}
if ($reorderLevel === '' || !is_numeric($reorderLevel) || (float)$reorderLevel < 0) {
    $errors[] = 'Please enter a valid reorder level (0 or more).';
}
if ($criticalLevel !== '' && (!is_numeric($criticalLevel) || (float)$criticalLevel < 0)) {
    $errors[] = 'Critical level must be 0 or more.';
}
if ($supplierId !== '' && !ctype_digit($supplierId)) {
    $errors[] = 'Invalid preferred supplier.';
}
if ($safetyStockQty !== '' && (!is_numeric($safetyStockQty) || (float)$safetyStockQty < 0)) {
    $errors[] = 'Safety stock must be a number of 0 or more.';
}
if ($leadTimeDays !== '' && !ctype_digit($leadTimeDays)) {
    $errors[] = 'Lead time must be a whole number of days (0 or more).';
}

// Server-side re-check of the same ordering the client-side JS enforces —
// never trust JS alone.
if (!$errors) {
    if ($criticalLevel !== '' && $reorderLevel !== '' && (float)$criticalLevel > (float)$reorderLevel) {
        $errors[] = 'Critical level should be lower than (or equal to) the reorder level.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $catCheck = $pdo->prepare('SELECT category_id FROM inventory_categories WHERE category_id = ?');
        $catCheck->execute([$categoryId]);
        if (!$catCheck->fetch()) {
            $errors[] = 'That category no longer exists.';
        }

        $unitCheck = $pdo->prepare('SELECT unit_id FROM unit_of_measures WHERE unit_id = ?');
        $unitCheck->execute([$baseUnitId]);
        if (!$unitCheck->fetch()) {
            $errors[] = 'That unit of measure no longer exists.';
        }

        if ($supplierId !== '') {
            $supCheck = $pdo->prepare('SELECT supplier_id FROM suppliers WHERE supplier_id = ?');
            $supCheck->execute([$supplierId]);
            if (!$supCheck->fetch()) {
                $errors[] = 'That supplier no longer exists.';
            }
        }

        if (!$errors) {
            $criticalValue = $criticalLevel !== '' ? $criticalLevel : null;
            $supplierValue = $supplierId !== '' ? $supplierId : null;
            $leadTimeValue = $leadTimeDays !== '' ? $leadTimeDays : null;
            $safetyValue   = $safetyStockQty !== '' ? round((float)$safetyStockQty, 3) : null;

            if ($isEdit) {
                $stmt = $pdo->prepare(
                    "UPDATE inventory_items SET
                        item_name = ?, category_id = ?, base_unit_id = ?,
                        critical_level = ?, reorder_level = ?,
                        preferred_supplier_id = ?, is_active = ?,
                        lead_time_days = ?, safety_stock_qty = ?
                     WHERE item_id = ?"
                );
                $stmt->execute([
                    $itemName, $categoryId, $baseUnitId,
                    $criticalValue, $reorderLevel,
                    $supplierValue, $isActive,
                    $leadTimeValue, $safetyValue,
                    $itemId,
                ]);
                $logAction = 'Update item';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO inventory_items
                        (item_name, category_id, base_unit_id, critical_level, reorder_level,
                         preferred_supplier_id, is_active,
                         lead_time_days, safety_stock_qty)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $itemName, $categoryId, $baseUnitId, $criticalValue, $reorderLevel,
                    $supplierValue, $isActive,
                    $leadTimeValue, $safetyValue,
                ]);
                $itemId    = $pdo->lastInsertId();
                $logAction = 'Create item';
            }

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Inventory', ?, ?, ?)"
                )->execute([
                    Session::getUserId(),
                    $logAction,
                    "{$logAction}: {$itemName} (item_id {$itemId})",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }

            flash_set('success', $isEdit ? 'Item updated.' : 'Item created.');
        }
    } catch (PDOException $e) {
        error_log('inventory_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this item. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
}

header('Location: ' . $backTo);
exit;
