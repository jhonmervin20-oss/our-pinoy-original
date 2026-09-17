<?php
/**
 * owner/menu_management/packaging_rule_save.php
 *
 * Save one menu item's packaging rule. If the submitted item list is
 * empty, the packaging_rules row itself is deleted (ON DELETE CASCADE
 * clears any leftover packaging_rule_items) -- "no items" and "no rule"
 * are the same thing (packaging cost = 0), so there's no reason to keep
 * an empty rule row around. Logs recipe_cost_history with
 * reason='packaging_edit' on success.
 *
 * Business rule enforced here: only inventory items INSIDE the
 * "Packaging" category are eligible (mirrors recipe_save.php's inverse
 * check) -- checked server-side, never trusting the <select> alone.
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

$itemId = trim((string)($_POST['item_id'] ?? ''));
if ($itemId === '' || !ctype_digit($itemId)) {
    flash_set('error', 'Invalid menu item.');
    header('Location: menu_items.php');
    exit;
}
$itemId = (int)$itemId;
$redirectTo = 'recipe_builder.php?item_id=' . $itemId;

$packagingItemIds  = $_POST['packaging_item_id'] ?? [];
$packagingQuantities = $_POST['packaging_quantity'] ?? [];
$packagingUnitIds      = $_POST['packaging_unit_id'] ?? [];

$errors = [];
$rowCount = count($packagingItemIds);
$cleanItems = [];

try {
    $pdo = Database::getInstance()->getConnection();

    $itemCheck = $pdo->prepare('SELECT item_id FROM menu_items WHERE item_id = ?');
    $itemCheck->execute([$itemId]);
    if (!$itemCheck->fetch()) {
        $errors[] = 'That menu item no longer exists.';
    }

    if (!$errors && $rowCount > 0) {
        $eligibleIds = $pdo->query(
            "SELECT i.item_id FROM inventory_items i
             JOIN inventory_categories c ON c.category_id = i.category_id
             WHERE i.is_active = 1 AND c.category_name = 'Packaging'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $validUnitIds = $pdo->query('SELECT unit_id FROM unit_of_measures WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);

        for ($i = 0; $i < $rowCount; $i++) {
            $rowItemId = trim((string)($packagingItemIds[$i] ?? ''));
            $rowQty    = trim((string)($packagingQuantities[$i] ?? ''));
            $rowUnitId = trim((string)($packagingUnitIds[$i] ?? ''));

            if ($rowItemId === '' && $rowQty === '') {
                continue;
            }
            if (!ctype_digit($rowItemId) || !in_array((int)$rowItemId, $eligibleIds, true)) {
                $errors[] = 'One of the selected packaging items is not a valid, active Packaging-category item.';
                break;
            }
            if ($rowQty === '' || !is_numeric($rowQty) || (float)$rowQty <= 0) {
                $errors[] = 'Every packaging line needs a quantity greater than 0.';
                break;
            }
            if (!ctype_digit($rowUnitId) || !in_array((int)$rowUnitId, $validUnitIds, true)) {
                $errors[] = 'One of the selected units is invalid.';
                break;
            }

            $cleanItems[] = [
                'item_id' => (int)$rowItemId,
                'qty'     => round((float)$rowQty, 3),
                'unit_id' => (int)$rowUnitId,
            ];
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();

        $existing = $pdo->prepare('SELECT rule_id FROM packaging_rules WHERE menu_item_id = ?');
        $existing->execute([$itemId]);
        $ruleId = $existing->fetchColumn();

        if (empty($cleanItems)) {
            if ($ruleId) {
                $pdo->prepare('DELETE FROM packaging_rules WHERE rule_id = ?')->execute([$ruleId]);
            }
        } else {
            if (!$ruleId) {
                $pdo->prepare('INSERT INTO packaging_rules (menu_item_id) VALUES (?)')->execute([$itemId]);
                $ruleId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM packaging_rule_items WHERE rule_id = ?')->execute([$ruleId]);

            $insertItem = $pdo->prepare(
                'INSERT INTO packaging_rule_items (rule_id, inventory_item_id, quantity, unit_id) VALUES (?, ?, ?, ?)'
            );
            foreach ($cleanItems as $item) {
                $insertItem->execute([$ruleId, $item['item_id'], $item['qty'], $item['unit_id']]);
            }
        }

        logCostHistory($pdo, $itemId, 'packaging_edit');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Menu Management', 'Update packaging rule', ?, ?)"
            )->execute([
                Session::getUserId(),
                "Updated packaging rule for menu_item_id {$itemId} (" . count($cleanItems) . ' items)',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }

        $pdo->commit();
        flash_set('success', 'Packaging rule saved.');
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('packaging_rule_save.php failed: ' . $e->getMessage());
    $errors[] = 'Something went wrong saving this packaging rule. Please try again.';
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
}

header('Location: ' . $redirectTo);
exit;
