<?php
/**
 * config/inventory_alerts.php
 *
 * Opportunistic sweeps for the Owner/Manager notification bell (see
 * owner/includes/header.php, manager/includes/header.php) -- this app has
 * no cron/scheduler anywhere except cron/run_sweeps.php's real Windows
 * Scheduled Task tick, so every "check a condition and notify" feature
 * also runs on a real page load, same pattern as
 * customer/includes/reservation_functions.php's notifyUpcomingHoldExpiry()
 * etc. (called from customer/includes/navbar.php on every load).
 *
 * Both sweeps notify via notifyUsersByRole() (config/notifications.php) --
 * a real per-user notifications row per recipient, not a shared activity_logs
 * row, so each role's bell has its own independent read/unread state and its
 * own precise redirect.
 *
 * Recipients are per-alert, not blanket owner+manager. Current-condition
 * stock alerts (out/critical/reorder, batch expiring/expired) go to owner AND
 * manager: both can act on them and both can reach inventory.php. Anything
 * derived from the demand FORECAST -- the predicted-shortage alert, the
 * no-preferred-supplier alert -- and anything about purchase orders is
 * owner-only, because owner/demand_forecast.php and PO approval are gated to
 * the owner role. Sending those to a Manager produced a notification they
 * could not open (managerNotifLink() has no route for them) about a page
 * their role is refused.
 *
 * Two sweeps live here:
 *   - sweepInventoryStockAlerts(): cheap, pure-SQL checks against existing
 *     columns/views. Safe to run on every owner/manager page load.
 *   - sweepAutoPurchaseOrders(): calls the classification+forecast+policy
 *     engine (owner/includes/inventory_policy_functions.php -> the Python
 *     forecast_service) per ingredient. Idempotency and concurrency are
 *     governed by forecast_runs + a MySQL named lock, not a plain
 *     timestamp column (see the function's own docblock) -- must NOT run
 *     concurrently or more than once/day automatically, or it reintroduces
 *     the exact kind of page-load slowdown already fixed for the Demand
 *     Forecast page itself, and risks double-drafting purchase orders.
 *
 * Requires config/database.php's Database class and a live PDO connection
 * to already be available (every caller already does); requires
 * config/notifications.php (notifyUsersByRole()), purchase_orders/includes/
 * po_functions.php, and owner/includes/inventory_policy_functions.php
 * itself below rather than assuming callers already have -- no function
 * name collisions between them (diffed before writing this file).
 */

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../purchase_orders/includes/po_functions.php';
require_once __DIR__ . '/../owner/includes/inventory_policy_functions.php';

/** How many days out a batch counts as "expiring soon" (not yet expired). */
const INVENTORY_EXPIRY_WARNING_DAYS = 3;

/** Re-alert once per day for a still-unresolved condition -- not spammed every click, not silently forgotten either. */
const INVENTORY_ALERT_DEDUP_INTERVAL = '1 DAY';

/** MySQL named lock guarding sweepAutoPurchaseOrders() -- see that function's docblock. */
const AUTO_PO_SWEEP_LOCK_NAME = 'opo_forecast_sweep';

/** A 'running' forecast_runs row older than this is treated as abandoned (the process that owned it died before reaching its own status update) rather than genuinely in progress. */
const AUTO_PO_SWEEP_STALE_RUNNING_MINUTES = 30;

/** How many days an unresolved auto-generated draft PO sits before a reminder fires. */
const DEFAULT_DRAFT_REMINDER_DAYS = 5;

/** How far back ingredient_demand_contributions rows are kept -- see pruneIngredientDemandContributions()'s docblock for why this table specifically. */
const CONTRIBUTIONS_RETENTION_DAYS = 60;

/**
 * Stock-level (out of stock / critical / at reorder level) and batch
 * expiry (expired / expiring soon) alerts, logged to activity_logs so
 * they show up in the same Owner/Manager bell as everything else.
 */
function sweepInventoryStockAlerts(PDO $db): void
{
    $stockStmt = $db->query(
        "SELECT ii.item_id, ii.item_name, iss.current_stock, iss.stock_status, u.unit_code
         FROM inventory_stock_status iss
         JOIN inventory_items ii ON ii.item_id = iss.item_id
         JOIN unit_of_measures u ON u.unit_id = ii.base_unit_id
         WHERE ii.is_active = 1 AND iss.stock_status IN ('out_of_stock', 'critical', 'low')"
    );

    foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemId = (int)$row['item_id'];
        $stockLabel = poFmtQtyPlain($row['current_stock']) . ' ' . $row['unit_code'];

        switch ($row['stock_status']) {
            case 'out_of_stock':
                notifyUsersByRole(
                    $db, ['owner', 'manager'], 'inventory', 'Out of stock',
                    "{$row['item_name']} is out of stock.",
                    'inventory_stock_out', $itemId, INVENTORY_ALERT_DEDUP_INTERVAL
                );
                break;
            case 'critical':
                notifyUsersByRole(
                    $db, ['owner', 'manager'], 'inventory', 'Critical stock level',
                    "{$row['item_name']} is at a critical stock level ({$stockLabel} remaining).",
                    'inventory_stock_critical', $itemId, INVENTORY_ALERT_DEDUP_INTERVAL
                );
                break;
            case 'low':
                notifyUsersByRole(
                    $db, ['owner', 'manager'], 'inventory', 'Reorder level reached',
                    "{$row['item_name']} has reached its reorder level ({$stockLabel} remaining).",
                    'inventory_stock_reorder', $itemId, INVENTORY_ALERT_DEDUP_INTERVAL
                );
                break;
        }
    }

    $expiryStmt = $db->prepare(
        "SELECT b.batch_id, b.item_id, b.batch_number, b.expiry_date, ii.item_name
         FROM inventory_batches b
         JOIN inventory_items ii ON ii.item_id = b.item_id
         WHERE b.quantity_remaining > 0 AND ii.is_active = 1
           AND b.expiry_date IS NOT NULL
           AND b.expiry_date <= (CURDATE() + INTERVAL ? DAY)"
    );
    $expiryStmt->execute([INVENTORY_EXPIRY_WARNING_DAYS]);

    $today = date('Y-m-d');
    foreach ($expiryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $batchId = (int)$row['batch_id'];
        $expiryLabel = date('M j, Y', strtotime($row['expiry_date']));

        if ($row['expiry_date'] <= $today) {
            notifyUsersByRole(
                $db, ['owner', 'manager'], 'inventory', 'Batch expired',
                "Batch {$row['batch_number']} of {$row['item_name']} expired on {$expiryLabel}.",
                'inventory_batch_expired', $batchId, INVENTORY_ALERT_DEDUP_INTERVAL
            );
        } else {
            notifyUsersByRole(
                $db, ['owner', 'manager'], 'inventory', 'Batch expiring soon',
                "Batch {$row['batch_number']} of {$row['item_name']} expires on {$expiryLabel}.",
                'inventory_batch_expiring', $batchId, INVENTORY_ALERT_DEDUP_INTERVAL
            );
        }
    }
}

/**
 * Reminds about auto-generated draft POs that have sat
 * unresolved past system_settings.auto_po_draft_reminder_days. This
 * exists because getIngredientAvailableStock() now nets EVERY open
 * commitment (any non-received/cancelled PO) as
 * "already coming" with no time window -- correct for closing the
 * double-order hole a narrower netting rule used to leave open, but it
 * means an unresolved draft now suppresses reordering for that ingredient
 * indefinitely until a human acts on it. This is the required safety
 * valve for that (see reorder_suggestions.suppressed_by_open_commitment
 * for the other half -- making the suppression visible on the dashboard,
 * not just via this notification). Deliberately NOT auto-cancellation --
 * this app's standing principle is "always draft, a human
 * decides," so an automated cancellation would be a new kind of
 * automated destructive action this codebase doesn't otherwise take.
 */
function sweepStaleCommitmentReminders(PDO $db): void
{
    $reminderDaysStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_po_draft_reminder_days'");
    $reminderDays = max(1, (int)($reminderDaysStmt->fetchColumn() ?: DEFAULT_DRAFT_REMINDER_DAYS));

    $staleDraftsStmt = $db->prepare(
        "SELECT po_id, po_number, DATEDIFF(CURDATE(), DATE(created_at)) AS age_days
         FROM purchase_orders
         WHERE is_auto_generated = 1 AND status = 'draft' AND created_at <= (NOW() - INTERVAL ? DAY)"
    );
    $staleDraftsStmt->execute([$reminderDays]);
    foreach ($staleDraftsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Owner only: an auto-generated PO is a draft, and drafts are the
        // Owner's alone since the purchasing/receiving split. Sending this to a
        // Manager pointed them at a PO their own guard now refuses to open.
        notifyUsersByRole(
            $db, ['owner'], 'procurement', 'Auto-generated draft PO still unresolved',
            "Draft purchase order {$row['po_number']} was auto-created {$row['age_days']} days ago and hasn't been reviewed yet -- approve or cancel it so reordering isn't silently suppressed for its items.",
            'purchase_order_auto_draft_stale', (int)$row['po_id'], INVENTORY_ALERT_DEDUP_INTERVAL
        );
    }

}

/**
 * Prunes ingredient_demand_contributions rows older than
 * CONTRIBUTIONS_RETENTION_DAYS. This is the ONLY audit table pruned --
 * forecast_runs and reorder_suggestions are never pruned (their volume is
 * trivial, roughly 52 reorder_suggestions rows/day; deleting them would
 * cascade-orphan any purchase_orders they explain, the exact failure this
 * whole audit trail exists to prevent). Contributions is different: it's
 * bounded-but-still-the-bulkiest table by design (only triggered items,
 * only the last 14 days of training history per persistIngredientDemandContributions(),
 * but still meaningfully larger than the other two), and it's purely
 * explanatory ("what's historically driven this forecast") rather than
 * evidence a real committed artifact depends on.
 */
function pruneIngredientDemandContributions(PDO $db): void
{
    $db->prepare("DELETE FROM ingredient_demand_contributions WHERE consumption_date < (CURDATE() - INTERVAL ? DAY)")
        ->execute([CONTRIBUTIONS_RETENTION_DAYS]);
}

/**
 * Demand-forecast-driven auto purchase order creation -- a single min-max
 * (order-up-to) policy engine, not a configurable "trigger method"/urgency
 * system (see owner/includes/inventory_policy_functions.php). The trigger
 * is unconditional: available_stock <= reorder_level, where reorder_level
 * is itself Prophet's own forecast upper bound (plus the known-demand
 * overlay) over the item's lead time.
 *
 * Idempotency/concurrency (project plan Fix #5): both the automatic
 * scheduled sweep (cron/run_sweeps.php, ticking every 5 min, and every
 * owner/manager page load) and a human-triggered "Retry now" click call
 * THIS SAME function with $isManualTrigger distinguishing the two. Both
 * acquire the same MySQL named lock first -- only the lock holder may
 * act, so a manual retry can never race a genuinely in-progress automatic
 * run. Once the lock is held:
 *   - No forecast_runs row for today's idempotency_key -> start a fresh run.
 *   - A row exists with status completed/partial:
 *       - automatic caller -> already ran today (cleanly or degraded);
 *         do NOT auto-retry (avoids hammering Python for hours if it's
 *         down); release the lock, return.
 *       - manual caller -> allowed; this is where a 'partial' retry
 *         lives -- exactly the case that matters, since 'partial' means
 *         the AI service was unreachable while evaluating some items,
 *         which is exactly when you'd want a fresh attempt once it's
 *         back up, but the automatic sweep deliberately won't do this on
 *         its own.
 *   - A row exists with status 'failed', or 'running' but stale
 *     (AUTO_PO_SWEEP_STALE_RUNNING_MINUTES old -- the process that owned
 *     it died before reaching its own status update; the lock itself is
 *     per-connection and MySQL releases it automatically on a dead
 *     connection, so a stale 'running' row can coexist with a free
 *     lock) -> retry: resolve the "committed" item set (items with a
 *     real po_id from the failed attempt) and delete
 *     everything else for that run_id across all three evidence tables
 *     identically, then reprocess everything not in the committed set.
 *     A committed item's entire evidence set is immutable; nothing else is.
 *   - A row exists with status 'running' and recent -> genuinely in
 *     progress (should be rare given the lock already gates this);
 *     release the lock, return.
 *
 * No fallback model, ever: when the AI service is unreachable for a
 * given item, that item is gated with an honest message
 * (computeInventoryPolicyForItem()'s existing behavior, unchanged) --
 * never given a substitute forecast. status='partial' on forecast_runs
 * means exactly this happened for at least one item this run, not that a
 * weaker model ran in Prophet's place.
 *
 * Writes one reorder_suggestions row per active item EVERY run, including
 * no-action/suppressed/gated ones -- "every active item is accounted for
 * in some bucket" should be provable by querying this table, not trusted
 * from a `continue` nobody re-reads.
 *
 * Value gate (project plan decision, ₱5,000/supplier/day default):
 * requires a preferred_supplier_id (nothing to address a PO
 * to otherwise -- flagged via notification for items missing one). A
 * supplier-grouped batch below the gate goes straight to a draft PO,
 * large auto-computed commitment through the existing human-review
 * approval flow rather than auto-drafting it.
 *
 * Returns a summary array the caller can use for a flash message
 * (owner/forecast_retry.php) or just ignore (the opportunistic
 * page-load/cron callers).
 */
function sweepAutoPurchaseOrders(PDO $db, bool $isManualTrigger = false, ?int $triggeredByUserId = null): array
{
    $settingsStmt = $db->query(
        "SELECT setting_key, setting_value FROM system_settings
         WHERE setting_key IN ('auto_po_enabled', 'forecast_horizon_days', 'shortage_notification_horizon_days',
             'auto_po_sweep_hour', 'auto_po_forecast_window_days')"
    );
    $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (empty($settings['auto_po_enabled'])) {
        return ['status' => 'disabled'];
    }

    if (!$isManualTrigger) {
        $sweepHour = max(0, min(23, (int)($settings['auto_po_sweep_hour'] ?? 2)));
        if ((int)date('G') < $sweepHour) {
            return ['status' => 'before_sweep_hour'];
        }
    }

    $lockAcquired = (bool)$db->query("SELECT GET_LOCK('" . AUTO_PO_SWEEP_LOCK_NAME . "', 0)")->fetchColumn();
    if (!$lockAcquired) {
        return ['status' => 'lock_busy'];
    }

    try {
        $idempotencyKey = 'ingredient_policy_sweep:' . date('Y-m-d');
        $runStmt = $db->prepare("SELECT run_id, status, started_at FROM forecast_runs WHERE idempotency_key = ?");
        $runStmt->execute([$idempotencyKey]);
        $existingRun = $runStmt->fetch(PDO::FETCH_ASSOC);

        $runId = null;
        $isRetry = false;

        if ($existingRun === false) {
            $ins = $db->prepare(
                "INSERT INTO forecast_runs (run_type, status, idempotency_key, started_by) VALUES ('ingredient_policy_sweep', 'running', ?, ?)"
            );
            $ins->execute([$idempotencyKey, $isManualTrigger ? $triggeredByUserId : null]);
            $runId = (int)$db->lastInsertId();
        } else {
            $status = $existingRun['status'];
            $runId  = (int)$existingRun['run_id'];
            $ageMinutes = (time() - strtotime($existingRun['started_at'])) / 60;

            if (!$isManualTrigger && in_array($status, ['completed', 'partial'], true)) {
                $db->query("SELECT RELEASE_LOCK('" . AUTO_PO_SWEEP_LOCK_NAME . "')");
                return ['status' => 'already_ran_today'];
            }
            if ($status === 'running' && $ageMinutes <= AUTO_PO_SWEEP_STALE_RUNNING_MINUTES) {
                $db->query("SELECT RELEASE_LOCK('" . AUTO_PO_SWEEP_LOCK_NAME . "')");
                return ['status' => 'in_progress'];
            }
            // Manual retry of completed/partial, OR failed, OR stale/abandoned running -- all retry paths.
            $isRetry = true;
            $db->prepare("UPDATE forecast_runs SET status = 'running', started_at = NOW(), started_by = ?, error_message = NULL WHERE run_id = ?")
                ->execute([$isManualTrigger ? $triggeredByUserId : null, $runId]);
        }

        $committedItemIds = [];
        if ($isRetry) {
            $committedStmt = $db->prepare(
                "SELECT item_id FROM reorder_suggestions WHERE run_id = ? AND po_id IS NOT NULL"
            );
            $committedStmt->execute([$runId]);
            $committedItemIds = array_map('intval', $committedStmt->fetchAll(PDO::FETCH_COLUMN));

            $notCommittedClause = empty($committedItemIds) ? '' : (' AND item_id NOT IN (' . implode(',', array_fill(0, count($committedItemIds), '?')) . ')');
            $paramsWithRun = array_merge([$runId], $committedItemIds);

            $db->prepare("DELETE FROM ingredient_demand_forecast WHERE run_id = ?{$notCommittedClause}")->execute($paramsWithRun);
            $db->prepare("DELETE FROM ingredient_demand_contributions WHERE run_id = ?{$notCommittedClause}")->execute($paramsWithRun);
            $db->prepare("DELETE FROM reorder_suggestions WHERE run_id = ? AND po_id IS NULL")->execute([$runId]);
        }

        $summary = sweepAutoPurchaseOrdersRun($db, $runId, $settings, $committedItemIds);

        // 'failed', not 'partial': if the AI service was unreachable the sweep
        // aborted before evaluating anything, so there is no partial result to
        // report -- calling that 'partial' overstated what happened.
        // A gated sweep is 'failed', not 'partial': it aborts before evaluating
        // anything, so there is no partial result to report.
        //
        // Exception: a retry of a run that ALREADY produced committed purchase
        // orders must not erase that fact. Those rows are preserved above
        // (the DELETE spares po_id IS NOT NULL), so the run really did succeed
        // earlier -- reporting it as failed because a later manual retry found
        // the service down would misrepresent the day's outcome.
        $alreadyCommitted = (int)$db->query(
            'SELECT COUNT(*) FROM reorder_suggestions WHERE run_id = ' . (int)$runId . ' AND po_id IS NOT NULL'
        )->fetchColumn();

        if (!empty($summary['service_down'])) {
            $finalStatus = $alreadyCommitted > 0 ? 'partial' : 'failed';
        } else {
            $finalStatus = $summary['service_unavailable_count'] > 0 ? 'partial' : 'completed';
        }
        $db->prepare("UPDATE forecast_runs SET status = ?, finished_at = NOW() WHERE run_id = ?")->execute([$finalStatus, $runId]);

        sweepStaleCommitmentReminders($db);
        pruneIngredientDemandContributions($db);

        $db->query("SELECT RELEASE_LOCK('" . AUTO_PO_SWEEP_LOCK_NAME . "')");

        return array_merge(['status' => $finalStatus, 'run_id' => $runId], $summary);
    } catch (Throwable $e) {
        error_log('sweepAutoPurchaseOrders failed: ' . $e->getMessage());
        if (isset($runId) && $runId !== null) {
            $db->prepare("UPDATE forecast_runs SET status = 'failed', finished_at = NOW(), error_message = ? WHERE run_id = ?")
                ->execute([substr($e->getMessage(), 0, 65535), $runId]);
        }
        $db->query("SELECT RELEASE_LOCK('" . AUTO_PO_SWEEP_LOCK_NAME . "')");
        return ['status' => 'failed', 'run_id' => $runId ?? null];
    }
}

/**
 * The actual per-item evaluation + supplier-grouped PO
 * creation, factored out of sweepAutoPurchaseOrders() so that function's
 * job stays "own the lock/idempotency/retry lifecycle" and this one's
 * job stays "do the work for one run_id" -- easier to reason about
 * separately, and this half has no lock/retry concerns of its own.
 */
function sweepAutoPurchaseOrdersRun(PDO $db, int $runId, array $settings, array $committedItemIds): array
{
    $forecastHorizonDays = max(1, (int)($settings['forecast_horizon_days'] ?? 7));
    $shortageHorizonDays = max(1, (int)($settings['shortage_notification_horizon_days'] ?? 7));
    $shortageHorizonDays = min($shortageHorizonDays, $forecastHorizonDays);
    $windowDays  = max(INVENTORY_POLICY_MIN_HISTORY_DAYS, (int)($settings['auto_po_forecast_window_days'] ?? 30));

    $items = getActiveIngredientItems($db);
    $triggeredForPo = [];
    $serviceUnavailableCount = 0;
    $serviceDown = false;   // set when the AI service is unreachable; aborts the sweep
    $itemsEvaluated = 0;
    $itemsTriggered = 0;

    foreach ($items as $item) {
        $itemId = (int)$item['item_id'];
        if (in_array($itemId, $committedItemIds, true)) {
            continue; // already resolved by a prior attempt of this same run -- immutable, never touched
        }
        $itemsEvaluated++;

        $policy = computeInventoryPolicyForItem($db, $item, $windowDays, $forecastHorizonDays);
        $stock  = getIngredientAvailableStock($db, $itemId);

        if ($policy['gated_reason'] !== null && $policy['gated_reason'] === 'AI forecast policy service unavailable right now.') {
            $serviceUnavailableCount++;

            // Stop the whole sweep here rather than walking the remaining
            // ingredients. Previously every item was still evaluated and a
            // 'skipped_gated' row written for each -- 457 of the 1070 rows in
            // reorder_suggestions are that, all with this one reason. Those
            // rows read like 457 considered decisions when in fact the model
            // never ran at all. A gated run must look like a non-run.
            $serviceDown = true;
            break;
        }

        $decision   = 'no_action';
        $urgency    = 'none';
        $gatedNote  = $policy['gated_reason'];
        $suppressed = false;

        if ($policy['gated_reason'] !== null) {
            persistInventoryPolicyResult($db, $policy); // no-op for a gated result (see that function's own guard)
            $decision = $policy['has_conversion_gap'] ? 'skipped_conversion_gap' : 'skipped_gated';
        } else {
            persistInventoryPolicyResult($db, $policy);
            persistIngredientDemandForecast($db, $runId, $policy);

            $reorderLevel  = $policy['policy_reorder_level'];
            $restockTarget = $policy['policy_restock_target'];
            $triggered = $stock['available_stock'] <= $reorderLevel;

            // Forecast-based shortage early warning -- independent of
            // today's trigger, fires whenever a breach is projected
            // within the (shorter-or-equal) notification horizon.
            $breachDay = projectShortageBreachDay($stock['available_stock'], $policy['daily_forecast'], $reorderLevel, $shortageHorizonDays);
            if ($breachDay !== null) {
                // Owner-only. The forecast this is derived from lives on
                // owner/demand_forecast.php, which is gated to the owner role, so
                // a Manager receiving this could not open the page that explains
                // it -- and managerNotifLink() has no route for it either, making
                // it an unclickable dead row in their bell.
                notifyUsersByRole(
                    $db, ['owner'], 'inventory', 'Forecast predicts future shortage',
                    "{$item['item_name']} is forecast to fall below its reorder level within {$breachDay} day" . ($breachDay === 1 ? '' : 's') . ".",
                    'inventory_forecast_shortage', $itemId, INVENTORY_ALERT_DEDUP_INTERVAL
                );
            }

            if ($triggered) {
                $itemsTriggered++;
                $rawQty = max(0.0, $restockTarget - $stock['available_stock']);

                if ($policy['supplier_id'] === null) {
                    $decision  = 'flagged_no_supplier';
                    $urgency   = ($policy['critical_level'] !== null && $stock['on_hand'] <= $policy['critical_level']) ? 'critical' : 'normal';
                    $gatedNote = null;
                    // Owner-only for the same reason: this is raised by the
                    // forecast sweep and its whole point is unblocking auto-PO
                    // generation, which only the owner acts on.
                    notifyUsersByRole(
                        $db, ['owner'], 'inventory', 'Ingredient has no preferred supplier',
                        "{$item['item_name']} needs restocking but has no preferred supplier set -- set one on Suppliers so it can be auto-ordered.",
                        'inventory_no_supplier', $itemId, INVENTORY_ALERT_DEDUP_INTERVAL
                    );
                    persistIngredientDemandContributions($db, $runId, $itemId, (int)$item['base_unit_id'], new DateTime('yesterday'));

                    // Still record what WOULD have been ordered -- cheap to
                    // compute, and tells the owner how much this matters once
                    // they set a supplier. Only unit rounding applies.
                    $previewQty = sizeAutoOrderQuantity($rawQty, $policy['unit_type']);
                    insertReorderSuggestion(
                        $db, $runId, $itemId, $policy, $stock, $decision, $urgency, $gatedNote, false, null,
                        $rawQty, $previewQty, null
                    );
                    continue;
                } else {
                    // Deferred: written after supplier grouping below,
                    // once we know which supplier PO it lands on.
                    $triggeredForPo[$itemId] = array_merge($policy, [
                        'base_unit_id'    => (int)$item['base_unit_id'],
                        'on_hand_qty'     => $stock['on_hand'],
                        'on_order_qty'    => $stock['scheduled_inbound'],
                        'available_stock' => $stock['available_stock'],
                        'raw_po_qty'      => max(0.0, $restockTarget - $stock['available_stock']),
                    ]);
                    continue; // skip the reorder_suggestions write below -- happens after grouping
                }
            } elseif ($stock['on_hand'] <= $reorderLevel) {
                // Suppressed (project plan Fix #9): on-hand alone would
                // trigger, but an open commitment covers the gap. Still
                // correctly "no new action" -- must read differently
                // from genuinely healthy, not identically.
                $suppressed = true;
                $urgency = ($policy['critical_level'] !== null && $stock['on_hand'] <= $policy['critical_level']) ? 'critical' : 'normal';
                $refs = $stock['inbound_po_refs'];
                $gatedNote = 'On-hand alone (' . poFmtQtyPlain($stock['on_hand']) . ' ' . $policy['unit_code'] . ') is at/below reorder level ('
                    . poFmtQtyPlain($reorderLevel) . ' ' . $policy['unit_code'] . '); covered by ' . (!empty($refs) ? implode(', ', $refs) : 'an open commitment') . '.';
            }
        }

        insertReorderSuggestion($db, $runId, $itemId, $policy, $stock, $decision, $urgency, $gatedNote, $suppressed, null);
    }

    // "Ingredient has no preferred supplier" items are already written
    // above (decision=flagged_no_supplier); only real, supplier-having
    // triggers reach here for grouping.
    $bySupplier = [];
    foreach ($triggeredForPo as $rec) {
        $bySupplier[$rec['supplier_id']][] = $rec;
    }

    if (!empty($bySupplier)) {
        $itemIds = array_keys($triggeredForPo);
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $detailStmt = $db->prepare("SELECT item_id, is_vat_exempt, last_purchase_cost, lead_time_days FROM inventory_items WHERE item_id IN ({$placeholders})");
        $detailStmt->execute($itemIds);
        $details = [];
        foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details[(int)$row['item_id']] = $row;
        }

        $systemUserId = (int)$db->query(
            "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name = 'admin' AND u.is_active = 1 ORDER BY u.user_id LIMIT 1"
        )->fetchColumn();

        $taxSettings = poGetActiveTaxSettings($db);

        foreach ($bySupplier as $supplierId => $recs) {
            $lines = [];
            $qtyByItemId = [];
            // The PO isn't fully delivered until its SLOWEST line arrives, so
            // the expected date keys off the max lead time across the batch,
            // not the average or the first item's.
            $maxLeadTimeDays = null;
            foreach ($recs as $rec) {
                $detail = $details[$rec['item_id']] ?? null;
                if ($detail === null) {
                    continue;
                }

                $qty = sizeAutoOrderQuantity($rec['raw_po_qty'], $rec['unit_type']);
                if ($qty <= 0) {
                    continue;
                }

                $unitCost = $detail['last_purchase_cost'] !== null ? (float)$detail['last_purchase_cost'] : 0.0;
                $lines[] = [
                    'item_id'       => $rec['item_id'],
                    'quantity'      => $qty,
                    'unit_cost'     => $unitCost,
                    'is_vat_exempt' => (bool)$detail['is_vat_exempt'],
                ];
                $qtyByItemId[$rec['item_id']] = $qty;

                if ($detail['lead_time_days'] !== null) {
                    $leadDays = (int)$detail['lead_time_days'];
                    if ($leadDays > 0 && ($maxLeadTimeDays === null || $leadDays > $maxLeadTimeDays)) {
                        $maxLeadTimeDays = $leadDays;
                    }
                }
            }
            if (empty($lines) || $systemUserId <= 0) {
                continue;
            }

            $valueLines = array_map(fn($l) => ['line_gross' => round($l['quantity'] * $l['unit_cost'], 2), 'is_vat_exempt' => $l['is_vat_exempt']], $lines);
            $batchTotal = computePOTotals($valueLines, $taxSettings)['total_amount'];

            // Summary line only. The per-ingredient demand-pattern breakdown
            // that used to be appended here ran to one line per item, making
            // the Remarks box on every auto-PO dozens of lines long -- and the
            // same classification is already shown, better, on the Demand
            // Forecasting page itself.
            //
            // States what this order actually DOES, not just where it came
            // from -- "restocks N ingredients, sized for the next X days of
            // demand" is the sentence an owner or supplier actually needs to
            // read it and understand the order without opening the app.
            $itemCount = count($lines);
            $remarks = "Auto-generated from the demand forecast: restocks {$itemCount} ingredient" . ($itemCount === 1 ? '' : 's')
                . " at or below its reorder point, sized to cover the next {$forecastHorizonDays} days of expected demand.";

            try {
                $db->beginTransaction();

                // Expected delivery = order date + the batch's slowest lead
                // time. Left NULL when no line carries one, rather than
                // inventing a date the data doesn't support.
                $expectedDelivery = $maxLeadTimeDays !== null
                    ? date('Y-m-d', strtotime("+{$maxLeadTimeDays} days"))
                    : null;

                $created = createPurchaseOrderCore($db, (int)$supplierId, date('Y-m-d'), $expectedDelivery, $lines, $systemUserId, $remarks, true);
                $poId = $created['po_id'];
                // Owner only -- see the stale-draft notification above: this
                // links to a draft, which a Manager can no longer view.
                notifyUsersByRole(
                    $db, ['owner'], 'procurement', 'Purchase order auto-created',
                    "Auto-created draft purchase order {$created['po_number']} (" . count($lines) . ' item' . (count($lines) === 1 ? '' : 's')
                        . ", total \u{20b1}" . number_format($batchTotal, 2) . ') based on demand forecasting -- review and approve.',
                    'purchase_order_auto_created', $poId
                );

                foreach ($lines as $line) {
                    $itemId = (int)$line['item_id'];
                    $rec = $triggeredForPo[$itemId];
                    $rawQty = $rec['raw_po_qty'];
                    // One stage now: the forecast shortfall, rounded only to a
                    // physically orderable amount. Pack rounding went with the
                    // pack_size column; MOQ/max-cap were removed earlier.
                    $orderQty = sizeAutoOrderQuantity($rawQty, $rec['unit_type']);

                    $stockForItem = ['on_hand' => $rec['on_hand_qty'], 'scheduled_inbound' => $rec['on_order_qty'], 'available_stock' => $rec['available_stock']];
                    insertReorderSuggestion(
                        $db, $runId, $itemId, $rec, $stockForItem,
                        'drafted',
                        'normal', null, false, $poId,
                        $rawQty, $orderQty, $qtyByItemId[$itemId]
                    );
                    persistIngredientDemandContributions($db, $runId, $itemId, $rec['base_unit_id'], new DateTime('yesterday'));
                }

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('sweepAutoPurchaseOrdersRun failed for supplier ' . $supplierId . ': ' . $e->getMessage());
                notifyUsersByRole(
                    $db, ['owner'], 'procurement', 'Purchase order generation failed',
                    'Auto purchase order generation failed for one supplier -- check the error log. It will be retried on the next sweep.',
                    'purchase_order_auto_failed', null
                );
                // Intent recorded even though creation failed, so the item
                // isn't silently invisible -- po_id stays
                // NULL, gated_reason explains what happened.
                foreach ($recs as $rec) {
                    $stockForItem = ['on_hand' => $rec['on_hand_qty'], 'scheduled_inbound' => $rec['on_order_qty'], 'available_stock' => $rec['available_stock']];
                    insertReorderSuggestion(
                        $db, $runId, (int)$rec['item_id'], $rec, $stockForItem, 'no_action', 'normal',
                        'Purchase order creation failed for this supplier batch -- will be retried on the next sweep.',
                        false, null
                    );
                }
            }
        }
    }

    return [
        'items_evaluated'          => $itemsEvaluated,
        'items_triggered'          => $itemsTriggered,
        'service_unavailable_count' => $serviceUnavailableCount,
        'service_down'              => $serviceDown,
    ];
}

/**
 * Writes one reorder_suggestions row. $policy/$stock are the arrays
 * computeInventoryPolicyForItem()/getIngredientAvailableStock() (or the
 * merged $rec shape built around them above) return -- pulled apart here
 * rather than passed as twenty scalar args.
 */
function insertReorderSuggestion(
    PDO $db, int $runId, int $itemId, array $policy, array $stock,
    string $decision, string $urgency, ?string $gatedReason, bool $suppressed,
    ?int $poId,
    ?float $rawQty = null, ?float $orderQty = null, ?float $finalQty = null
): void {
    $stmt = $db->prepare(
        "INSERT INTO reorder_suggestions
            (run_id, item_id, on_hand_qty, on_order_qty, available_stock,
             lead_time_demand_qty, reorder_level, restock_target, coverage_days,
             raw_suggested_qty, final_suggested_qty, final_purchase_qty,
             has_conversion_gap, suppressed_by_open_commitment, decision, urgency, gated_reason, po_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            on_hand_qty = VALUES(on_hand_qty), on_order_qty = VALUES(on_order_qty), available_stock = VALUES(available_stock),
            lead_time_demand_qty = VALUES(lead_time_demand_qty), reorder_level = VALUES(reorder_level), restock_target = VALUES(restock_target),
            coverage_days = VALUES(coverage_days),
            raw_suggested_qty = VALUES(raw_suggested_qty),
            final_suggested_qty = VALUES(final_suggested_qty), final_purchase_qty = VALUES(final_purchase_qty),
            has_conversion_gap = VALUES(has_conversion_gap), suppressed_by_open_commitment = VALUES(suppressed_by_open_commitment),
            decision = VALUES(decision), urgency = VALUES(urgency), gated_reason = VALUES(gated_reason),
            po_id = VALUES(po_id)"
    );
    $stmt->execute([
        $runId, $itemId,
        round((float)($stock['on_hand'] ?? 0.0), 3), round((float)($stock['scheduled_inbound'] ?? 0.0), 3), round((float)($stock['available_stock'] ?? 0.0), 3),
        $policy['lead_time_demand_qty'] !== null ? round((float)$policy['lead_time_demand_qty'], 3) : null,
        $policy['policy_reorder_level'] !== null ? round((float)$policy['policy_reorder_level'], 3) : null,
        $policy['policy_restock_target'] !== null ? round((float)$policy['policy_restock_target'], 3) : null,
        $policy['coverage_days'] ?? null,
        $rawQty !== null ? round($rawQty, 3) : 0.0,
        $orderQty !== null ? round($orderQty, 3) : 0.0,
        $finalQty !== null ? round($finalQty, 3) : null,
        !empty($policy['has_conversion_gap']) ? 1 : 0,
        $suppressed ? 1 : 0,
        $decision, $urgency, $gatedReason, $poId,
    ]);
}
