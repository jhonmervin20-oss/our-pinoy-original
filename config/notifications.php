<?php
/**
 * config/notifications.php
 *
 * Shared notification-insert helper. Lives in config/ (unlike this app's
 * usual per-module-copy convention for read helpers) because a WRITE needs
 * one single code path -- two near-identical per-module copies of an
 * INSERT could drift out of sync silently, and every module's badge count
 * (customer/includes/navbar.php today) has to agree on what a notification
 * row actually looks like.
 *
 * Requires config/database.php's Database class and a live PDO connection
 * to already be available; doesn't require it itself since every caller
 * already does.
 */

/**
 * Inserts one notification unless an identical one already exists --
 * ($userId, $referenceType, $referenceId) is this function's dedup key, so
 * an opportunistic sweep (see customer/includes/reservation_functions.php's
 * notifyUpcomingHoldExpiry() etc.) can safely call this on every page load
 * without spamming duplicate rows for the same underlying event. Pass
 * $referenceType/$referenceId as null only for a one-off notification that
 * genuinely has nothing to dedup against.
 *
 * Best-effort: a failure here is logged and swallowed, never thrown --
 * a missing notification should never break the request that triggered it
 * (matches this app's existing activity_logs insert convention).
 */
function createNotificationOnce(
    PDO $db,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $dedupInterval = null
): void {
    try {
        if ($referenceType !== null && $referenceId !== null) {
            $existsSql = 'SELECT notification_id FROM notifications
                          WHERE user_id = ? AND reference_type = ? AND reference_id = ?';
            $existsParams = [$userId, $referenceType, $referenceId];
            if ($dedupInterval !== null) {
                // Only ever a hardcoded literal from this codebase's own call sites (e.g. '1 DAY'),
                // never user input -- validated anyway since it's interpolated into raw SQL (MySQL's
                // INTERVAL clause doesn't accept a placeholder for the whole "N UNIT" expression).
                if (!preg_match('/^\d+\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)$/i', $dedupInterval)) {
                    throw new InvalidArgumentException("Invalid dedupInterval: {$dedupInterval}");
                }
                $existsSql .= " AND created_at >= (NOW() - INTERVAL {$dedupInterval})";
            }
            $existsStmt = $db->prepare($existsSql . ' LIMIT 1');
            $existsStmt->execute($existsParams);
            if ($existsStmt->fetch()) {
                return;
            }
        }

        $db->prepare(
            'INSERT INTO notifications (user_id, type, title, message, reference_type, reference_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $type, $title, $message, $referenceType, $referenceId]);
    } catch (Throwable $e) {
        error_log('createNotificationOnce failed: ' . $e->getMessage());
    }
}

/**
 * Fans a single event out to every active user in the given role(s), via
 * createNotificationOnce() above -- one real per-user notifications row per
 * recipient, each independently dedup'd and independently read/unread. This
 * is how "both owner and manager get notified" or "owner only" or "manager
 * only" are expressed: the caller just picks the role list, this does the
 * lookup once instead of every call site repeating its own role-to-user_id
 * query.
 *
 * $dedupInterval mirrors logActivityOnce()'s below -- null dedups forever
 * (for a genuinely one-time event: this specific adjustment, this specific
 * PO, this specific shift), a MySQL INTERVAL literal like '1 DAY' re-alerts
 * once per window for a still-unresolved condition (stock still low, a batch
 * still expiring) instead of notifying exactly once ever for that item/batch
 * id and then staying silent forever even after it recurs.
 */
function notifyUsersByRole(
    PDO $db,
    array $roles,
    string $type,
    string $title,
    string $message,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $dedupInterval = null
): void {
    try {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $db->prepare(
            "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name IN ($placeholders) AND u.is_active = 1"
        );
        $stmt->execute($roles);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            createNotificationOnce($db, (int)$userId, $type, $title, $message, $referenceType, $referenceId, $dedupInterval);
        }
    } catch (PDOException $e) {
        error_log('notifyUsersByRole failed: ' . $e->getMessage());
    }
}

/** A rising unit cost has to clear this bar before it's worth flagging -- a few-centavo drift between receipts shouldn't page anyone. */
const COST_INCREASE_ALERT_THRESHOLD_PERCENT = 10.0;

/**
 * Compares a just-recorded unit cost against the item's PREVIOUS
 * last_purchase_cost and notifies owner/manager if it rose by more than
 * COST_INCREASE_ALERT_THRESHOLD_PERCENT. $oldCost must be read by the
 * caller BEFORE inserting the new inventory_batches row -- the live
 * trg_update_last_purchase_cost trigger (AFTER INSERT ON inventory_batches)
 * overwrites inventory_items.last_purchase_cost the instant that insert
 * commits, so by the time this function could re-query it itself, "old"
 * and "new" would already be the same value. Two real call sites wire
 * this in: purchase_orders/purchase_order_receive.php (PO receiving) and
 * inventory/inventory_adjustment_save.php (manual "Increase" adjustments,
 * which also take a unit cost and create a real batch) -- both are the
 * only two places in the app that ever insert into inventory_batches.
 *
 * $oldCost === null (a brand-new item's very first batch) is not an
 * "increase" from anything, so this silently no-ops rather than comparing
 * against zero.
 */
function notifyIfCostIncreased(PDO $db, int $itemId, string $itemName, ?float $oldCost, float $newCost): void
{
    if ($oldCost === null || $oldCost <= 0) {
        return;
    }

    $increasePercent = (($newCost - $oldCost) / $oldCost) * 100;
    if ($increasePercent <= COST_INCREASE_ALERT_THRESHOLD_PERCENT) {
        return;
    }

    notifyUsersByRole(
        $db, ['owner', 'manager'], 'inventory', 'Ingredient cost increased',
        "{$itemName}'s unit cost rose from \u{20b1}" . number_format($oldCost, 2) . " to \u{20b1}" . number_format($newCost, 2)
            . ' (+' . number_format($increasePercent, 1) . '%).',
        'inventory_cost_increase', $itemId, '1 DAY'
    );
}

/**
 * Inserts one activity_logs row unless a matching one already exists --
 * ($referenceType, $referenceId) is the dedup key, same idea as
 * createNotificationOnce() above but for the Owner/Manager/Admin bell
 * (owner/includes/header.php, manager/includes/header.php,
 * admin/includes/header.php), which reads activity_logs directly rather
 * than the customer app's per-user notifications table.
 *
 * $dedupInterval controls how "already exists" is checked:
 *   - null: dedup forever -- for genuinely one-time events (a specific
 *     shift closing, a specific PO being auto-created) that can never
 *     recur for the same reference_id.
 *   - a MySQL INTERVAL expression, e.g. '1 DAY': only counts as a
 *     duplicate if logged within that window, so a still-unresolved
 *     condition (stock still low, batch still expiring) re-alerts once
 *     per window instead of being silently suppressed forever after the
 *     first hit.
 *
 * $userId may be null -- activity_logs.user_id is nullable and both
 * header.php files already fall back to displaying "System" when the
 * joined user is missing, so system-generated rows need no display
 * changes. Same best-effort try/catch-and-log convention as
 * createNotificationOnce() -- a missing log entry should never break the
 * request that triggered it.
 */
function logActivityOnce(
    PDO $db,
    ?int $userId,
    string $module,
    string $action,
    string $description,
    string $referenceType,
    int $referenceId,
    ?string $dedupInterval = null
): void {
    try {
        $existsSql = 'SELECT log_id FROM activity_logs WHERE reference_type = ? AND reference_id = ?';
        $existsParams = [$referenceType, $referenceId];
        if ($dedupInterval !== null) {
            // Only ever a hardcoded literal from this codebase's own call sites (e.g. '1 DAY'),
            // never user input -- validated anyway since it's interpolated into raw SQL (MySQL's
            // INTERVAL clause doesn't accept a placeholder for the whole "N UNIT" expression).
            if (!preg_match('/^\d+\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)$/i', $dedupInterval)) {
                throw new InvalidArgumentException("Invalid dedupInterval: {$dedupInterval}");
            }
            $existsSql .= " AND created_at >= (NOW() - INTERVAL {$dedupInterval})";
        }
        $existsStmt = $db->prepare($existsSql . ' LIMIT 1');
        $existsStmt->execute($existsParams);
        if ($existsStmt->fetch()) {
            return;
        }

        $db->prepare(
            'INSERT INTO activity_logs (user_id, module, action, description, reference_type, reference_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $module, $action, $description, $referenceType, $referenceId]);
    } catch (Throwable $e) {
        error_log('logActivityOnce failed: ' . $e->getMessage());
    }
}
