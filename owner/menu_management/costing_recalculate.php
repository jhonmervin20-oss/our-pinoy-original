<?php
/**
 * owner/menu_management/costing_recalculate.php
 *
 * Persist the live-computed costing snapshot into menu_item_costing (the
 * cache other pages read, e.g. the Food cost % column on menu_items.php)
 * and log one recipe_cost_history row with reason='manual_recalc' -- but
 * only for items whose cost actually moved, since logCostHistory() now
 * suppresses a history row identical to the item's previous one. The flash
 * message reports the real changed-item count rather than a flat
 * "recalculated", so clicking the button on unchanged data is visibly a
 * no-op instead of silently growing the history table.
 * item_id=all recalculates every active menu item in one transaction.
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
    header('Location: costing.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: costing.php');
    exit;
}

$itemIdParam = trim((string)($_POST['item_id'] ?? ''));

if ($itemIdParam === '') {
    flash_set('error', 'Invalid menu item.');
    header('Location: costing.php');
    exit;
}

/**
 * Refreshes the menu_item_costing cache row and logs history only if the
 * cost actually moved. The cache row is rewritten unconditionally (its
 * computed_at is what drives costing.php's "stale" badge, so a confirmed
 * no-change recalculation still has to clear that), while logCostHistory()
 * suppresses the history row when the numbers are identical.
 *
 * @return bool true if this item's cost genuinely changed.
 */
function persistCosting(PDO $pdo, int $itemId): bool
{
    $c = computeFullCosting($pdo, $itemId);
    persistMenuItemCosting($pdo, $itemId, $c, Session::getUserId());

    return logCostHistory($pdo, $itemId, 'manual_recalc');
}

$redirectItemId = null; // set below for a single-item recalc, so the redirect can jump back to its page/row instead of always landing on page 1

try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->beginTransaction();

    $changedCount = 0;

    if ($itemIdParam === 'all') {
        $itemIds = $pdo->query('SELECT item_id FROM menu_items WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($itemIds as $id) {
            if (persistCosting($pdo, (int)$id)) {
                $changedCount++;
            }
        }
        $count = count($itemIds);
        $logDescription = "Recalculated costing for all {$count} active menu items ({$changedCount} changed)";
    } elseif (ctype_digit($itemIdParam)) {
        $itemId = (int)$itemIdParam;
        $itemCheck = $pdo->prepare('SELECT item_id FROM menu_items WHERE item_id = ?');
        $itemCheck->execute([$itemId]);
        if (!$itemCheck->fetch()) {
            $pdo->rollBack();
            flash_set('error', 'That menu item no longer exists.');
            header('Location: costing.php');
            exit;
        }
        $changedCount = persistCosting($pdo, $itemId) ? 1 : 0;
        $logDescription = "Recalculated costing for menu_item_id {$itemId}" . ($changedCount ? ' (cost changed)' : ' (no change)');
        $redirectItemId = $itemId;
    } else {
        $pdo->rollBack();
        flash_set('error', 'Invalid menu item.');
        header('Location: costing.php');
        exit;
    }

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Menu Management', 'Recalculate costing', ?, ?)"
        )->execute([
            Session::getUserId(),
            $logDescription,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    $pdo->commit();
    // Says whether anything actually moved, so a no-op recalculation reads as
    // a confirmation rather than implying a change that never happened.
    flash_set('success', $changedCount === 0
        ? 'Costing recalculated - costs are unchanged, so no history was added.'
        : 'Costing recalculated. ' . $changedCount . ' item' . ($changedCount === 1 ? '' : 's') . ' changed.');
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('costing_recalculate.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong recalculating costing. Please try again.');
}

// Single-item recalc jumps back to that item's page/row (reuses the same
// ?highlight= deep link menu_items.php's Food Cost % column already uses)
// instead of always landing back on page 1 -- "recalculate all" has no
// single row to return to, so it stays on page 1 as before.
header('Location: costing.php' . ($redirectItemId !== null ? '?highlight=' . $redirectItemId : ''));
exit;
