<?php
/**
 * owner/menu_management/recipe_save.php
 *
 * Save one menu item's recipe: upserts the recipes row (yield/waste),
 * then replaces all of its menu_item_ingredients rows
 * wholesale (delete-then-reinsert within one transaction -- simpler and
 * safe here since there's no other table that references
 * menu_item_ingredients.recipe_id directly). Logs recipe_cost_history
 * with reason='recipe_edit' on success.
 *
 * Business rule enforced here: only inventory items OUTSIDE the
 * "Packaging" category are eligible ingredients (packaging never belongs
 * in a recipe) -- checked server-side, never trusting the <select> alone.
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

$ingredientItemIds  = $_POST['ingredient_item_id'] ?? [];
$ingredientQuantities = $_POST['ingredient_quantity'] ?? [];
$ingredientUnitIds     = $_POST['ingredient_unit_id'] ?? [];

$errors = [];

$rowCount = count($ingredientItemIds);
$cleanIngredients = [];

if (!$errors) {
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
                 WHERE i.is_active = 1 AND c.category_name != 'Packaging'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $validUnitIds = $pdo->query('SELECT unit_id FROM unit_of_measures WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);

            for ($i = 0; $i < $rowCount; $i++) {
                $rowItemId = trim((string)($ingredientItemIds[$i] ?? ''));
                $rowQty    = trim((string)($ingredientQuantities[$i] ?? ''));
                $rowUnitId = trim((string)($ingredientUnitIds[$i] ?? ''));

                if ($rowItemId === '' && $rowQty === '') {
                    continue; // blank row, skip silently
                }
                if (!ctype_digit($rowItemId) || !in_array((int)$rowItemId, $eligibleIds, true)) {
                    $errors[] = 'One of the selected ingredients is not a valid, active ingredient item.';
                    break;
                }
                if ($rowQty === '' || !is_numeric($rowQty) || (float)$rowQty <= 0) {
                    $errors[] = 'Every ingredient needs a quantity greater than 0.';
                    break;
                }
                if (!ctype_digit($rowUnitId) || !in_array((int)$rowUnitId, $validUnitIds, true)) {
                    $errors[] = 'One of the selected units is invalid.';
                    break;
                }

                $cleanIngredients[] = [
                    'item_id' => (int)$rowItemId,
                    'qty'     => round((float)$rowQty, 3),
                    'unit_id' => (int)$rowUnitId,
                ];
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();

            $existing = $pdo->prepare('SELECT recipe_id FROM recipes WHERE menu_item_id = ?');
            $existing->execute([$itemId]);
            $recipeId = $existing->fetchColumn();

            if ($recipeId) {
                $pdo->prepare(
                    'UPDATE recipes SET version = version + 1 WHERE menu_item_id = ?'
                )->execute([$itemId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO recipes (menu_item_id) VALUES (?)'
                )->execute([$itemId]);
            }

            $pdo->prepare('DELETE FROM menu_item_ingredients WHERE menu_item_id = ?')->execute([$itemId]);

            $insertIngredient = $pdo->prepare(
                'INSERT INTO menu_item_ingredients (menu_item_id, inventory_item_id, quantity_required, recipe_unit_id)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($cleanIngredients as $ing) {
                $insertIngredient->execute([$itemId, $ing['item_id'], $ing['qty'], $ing['unit_id']]);
            }

            logCostHistory($pdo, $itemId, 'recipe_edit');

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Menu Management', 'Update recipe', ?, ?)"
                )->execute([
                    Session::getUserId(),
                    "Updated recipe for menu_item_id {$itemId} (" . count($cleanIngredients) . ' ingredients)',
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort.
            }

            $pdo->commit();
            flash_set('success', 'Recipe saved.');
        }
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('recipe_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this recipe. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
}

header('Location: ' . $redirectTo);
exit;
