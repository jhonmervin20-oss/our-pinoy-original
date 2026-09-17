<?php
/**
 * config/forecast_auto_po.php
 *
 * What happens AFTER the forecasting pipeline runs.
 *
 * `forecasting/run.py` is the whole brain: it forecasts, explodes recipes and
 * decides, per ingredient, whether to order. It writes those decisions to
 * `reorder_suggestions` and stops there. This file is the two things that have
 * to happen next, and neither of them makes a decision of its own:
 *
 *   1. applyForecastReorderLevels()  -- copy the run's lead-time-demand
 *      figure onto `inventory_items` as reference data. `reorder_level`
 *      itself is the owner's own input now (set on the Inventory item
 *      form) and is never written here -- see that function's docblock.
 *   2. draftPurchaseOrdersFromForecastRun() -- turn the run's `drafted` rows
 *      into real draft purchase orders, grouped by supplier.
 *
 * This REPLACES config/inventory_alerts.php::sweepAutoPurchaseOrders(), which
 * was the old engine: it did its own forecasting through prophet_client.php,
 * wrote its own forecast_runs row, and drafted POs from that. Two engines
 * writing the same tables meant whichever ran last won, and the old one ran on
 * every owner/manager page load. It is no longer called from anywhere.
 *
 * The PO-shaped conventions here are deliberately unchanged from that old
 * sweep -- supplier grouping, expected delivery from the slowest lead time in
 * the batch, `is_auto_generated = 1`, owner-only notification, one transaction
 * per supplier -- because those were correct. Only the source of the numbers
 * changed.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../purchase_orders/includes/po_functions.php';

/**
 * Copies the run's lead-time-demand figure onto `inventory_items` -- purely
 * a reference number now, never a threshold.
 *
 * Both `reorder_level` and `critical_level` used to be overwritten here too,
 * computed fresh every run. That directly fought the Inventory item form,
 * where both fields are required, owner-typed inputs (same form,
 * `inventory/inventory.php`) already validated against each other
 * client-side and by the live `chk_critical_le_reorder` CHECK constraint --
 * an owner who typed "Beef: 8 kg" had it silently replaced by the next
 * nightly sweep. `safety_stock_qty` already got this exact fix a while ago
 * (it is the manager's input, the run only reads it); `reorder_level` and
 * `critical_level` get it here. The reorder decision itself
 * (`forecasting/stages/s6_reorder.py`) now reads `reorder_level` straight
 * off the item, never derives it, so there is nothing left for this
 * function to compute a replacement for.
 *
 * Only rows the run actually evaluated with real demand are touched. An
 * ingredient in no recipe forecasts zero, and writing a zero lead-time
 * figure over a real one would misstate it -- so its existing value is left
 * exactly as it is.
 */
function applyForecastReorderLevels(PDO $db, int $runId): array
{
    $rows = $db->prepare(
        "SELECT rs.item_id, rs.lead_time_demand_qty, u.unit_type
           FROM reorder_suggestions rs
           JOIN inventory_items ii  ON ii.item_id = rs.item_id
           JOIN unit_of_measures u  ON u.unit_id  = ii.base_unit_id
          WHERE rs.run_id = ?
            AND rs.decision IN ('drafted', 'no_action')
            AND rs.lead_time_demand_qty IS NOT NULL"
    );
    $rows->execute([$runId]);

    $upd = $db->prepare(
        "UPDATE inventory_items SET lead_time_demand_qty = ? WHERE item_id = ?"
    );

    $applied = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Countable things get a whole-unit figure -- continuous decimals on
        // a "days of pieces" reference number aren't actionable either.
        $isCount = strtolower((string)($r['unit_type'] ?? '')) === 'count';
        $upd->execute([
            $isCount ? ceil((float)$r['lead_time_demand_qty']) : round((float)$r['lead_time_demand_qty'], 3),
            (int)$r['item_id'],
        ]);
        $applied++;
    }

    $skipped = (int)$db->query(
        "SELECT COUNT(*) FROM inventory_items WHERE is_active = 1"
    )->fetchColumn() - $applied;

    return ['applied' => $applied, 'left_manual' => max(0, $skipped)];
}

/**
 * Turns a completed run's `drafted` suggestions into draft purchase orders.
 *
 * One PO per supplier per run. Idempotent by construction: a suggestion that
 * already carries a `po_id` is skipped, so calling this twice for the same run
 * cannot double-order.
 *
 * There is no supplier-minimum rule. `po_min_order_amount` used to hold a
 * batch worth less than a flat 500 pesos, unless a line in it was critical.
 * It was removed on 2026-09-01 because nothing backed it: `suppliers` has no
 * minimum-order column, neither of the two real suppliers records one, and the
 * number was a single global figure applied to both. Its own critical override
 * also meant it stood down in exactly the cases it would have applied to --
 * small orders are usually the urgent ones. A rule nobody can point at a
 * source for is worse than no rule.
 */
function draftPurchaseOrdersFromForecastRun(PDO $db, int $runId): array
{
    $lock = (int)$db->query("SELECT GET_LOCK('opo_forecast_auto_po', 30)")->fetchColumn();
    if ($lock !== 1) {
        return ['status' => 'busy', 'created' => 0];
    }

    try {
        return draftPurchaseOrdersFromForecastRunUnlocked($db, $runId);
    } finally {
        $db->query("SELECT RELEASE_LOCK('opo_forecast_auto_po')");
    }
}

/** Generate POs while the public wrapper holds the single-run lock. */
function draftPurchaseOrdersFromForecastRunUnlocked(PDO $db, int $runId): array
{
    $enabled = $db->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'auto_po_enabled'"
    )->fetchColumn();

    if (empty($enabled)) {
        return ['status' => 'disabled', 'created' => 0];
    }

    $stmt = $db->prepare(
        "SELECT rs.suggestion_id, rs.item_id, rs.final_purchase_qty, rs.urgency,
                i.preferred_supplier_id AS supplier_id, i.is_vat_exempt,
                i.last_purchase_cost, i.lead_time_days, i.item_name
           FROM reorder_suggestions rs
           JOIN inventory_items i ON i.item_id = rs.item_id
          WHERE rs.run_id = :run_id
            AND rs.decision = 'drafted'
            AND rs.po_id IS NULL
            AND rs.final_purchase_qty > 0
            AND i.preferred_supplier_id IS NOT NULL
            /* Don't re-order something that is already sitting on an OPEN draft
               for the SAME supplier, and only while that draft is still recent.

               The previous version had none of those qualifiers -- any draft,
               any age, any supplier, containing the item at any quantity,
               blocked it forever. One draft nobody got round to approving
               silently stopped that ingredient being re-ordered for good, which
               is the opposite of what a replenishment system should do when a
               human stops responding. The reminder setting
               (auto_po_draft_reminder_days) exists precisely because a draft is
               expected to be acted on quickly; past that window the draft is
               stale and must not keep suppressing new orders.

               Supplier-scoped because a draft with supplier A says nothing
               about whether supplier B should be asked for the same item. */
            AND NOT EXISTS (
                    SELECT 1
                      FROM purchase_order_items draft_poi
                      JOIN purchase_orders draft_po ON draft_po.po_id = draft_poi.po_id
                     WHERE draft_poi.item_id = rs.item_id
                       AND draft_po.status = 'draft'
                       AND draft_po.supplier_id = i.preferred_supplier_id
                       AND draft_po.created_at >= (NOW() - INTERVAL :draft_stale_days DAY)
            )
          ORDER BY i.preferred_supplier_id, i.item_name"
    );
    /* How long a draft keeps suppressing re-orders for its items. Reuses the
       reminder window the owner already set: a draft is "still being dealt
       with" for exactly as long as the system is willing to wait before
       nagging about it. Floored at 1 day so a 0 can never disable the guard
       entirely and let the sweep raise a duplicate draft every night. */
    $draftStaleDays = max(1, (int)($db->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'auto_po_draft_reminder_days'"
    )->fetchColumn() ?: 1));

    // For the remarks text below -- the forecast's own demand window, not
    // reorder_suggestions.coverage_days (which is a per-item figure that
    // varies ingredient to ingredient and would not summarise into one
    // honest sentence for a whole multi-item order).
    $forecastHorizonDays = max(1, (int)($db->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'forecast_horizon_days'"
    )->fetchColumn() ?: 7));

    // All-named placeholders: PDO refuses a statement that mixes ? with :name.
    $stmt->bindValue(':run_id', $runId, PDO::PARAM_INT);
    $stmt->bindValue(':draft_stale_days', $draftStaleDays, PDO::PARAM_INT);
    $stmt->execute();
    $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($suggestions)) {
        return ['status' => 'nothing_to_order', 'created' => 0];
    }

    // Whoever the PO is "created by". Same resolution the old sweep used: the
    // first active admin, standing in for the system itself.
    $systemUserId = (int)$db->query(
        "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE r.role_name = 'admin' AND u.is_active = 1 ORDER BY u.user_id LIMIT 1"
    )->fetchColumn();

    if ($systemUserId <= 0) {
        return ['status' => 'no_system_user', 'created' => 0];
    }

    $taxSettings = poGetActiveTaxSettings($db);

    $bySupplier = [];
    foreach ($suggestions as $s) {
        $bySupplier[(int)$s['supplier_id']][] = $s;
    }

    $created = [];

    foreach ($bySupplier as $supplierId => $recs) {
        $lines = [];
        $maxLeadTimeDays = null;

        foreach ($recs as $rec) {
            $qty = round((float)$rec['final_purchase_qty'], 3);
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'suggestion_id' => (int)$rec['suggestion_id'],
                'item_id'       => (int)$rec['item_id'],
                'quantity'      => $qty,
                'unit_cost'     => (float)($rec['last_purchase_cost'] ?? 0),
                'is_vat_exempt' => (bool)$rec['is_vat_exempt'],
            ];
            // The PO isn't fully delivered until its SLOWEST line arrives, so
            // the expected date keys off the max lead time across the batch.
            $lead = (int)($rec['lead_time_days'] ?? 0);
            if ($lead > 0 && ($maxLeadTimeDays === null || $lead > $maxLeadTimeDays)) {
                $maxLeadTimeDays = $lead;
            }
        }

        if (empty($lines)) {
            continue;
        }

        // Batch value. Also gates the draft -- see the minimum check below.
        $valueLines = array_map(
            fn($l) => ['line_gross' => round($l['quantity'] * $l['unit_cost'], 2), 'is_vat_exempt' => $l['is_vat_exempt']],
            $lines
        );
        $batchTotal = computePOTotals($valueLines, $taxSettings)['total_amount'];

        // -- Supersede stale drafts for the same supplier, don't stack
        // beside them --------------------------------------------------
        //
        // The guard above already lets a fresh draft through once an old
        // one for the same item+supplier passes auto_po_draft_reminder_days
        // -- by design, so one forgotten draft can't permanently block
        // re-ordering (see the guard's own comment). But letting it through
        // while leaving the stale draft open created a second problem: two
        // open, unapproved drafts for the same ingredient and supplier,
        // neither one telling a reviewer the other exists. An owner who
        // approves both over-orders for real -- confirmed against live
        // data, not hypothetical: JM STORE ended up with Beef, Pork and 15
        // others each sitting on two separate open drafts.
        //
        // This run's $lines is a fresh, complete decision for every item at
        // this supplier, so a stale draft's line for an item NOT in $lines
        // this time means that item's situation already changed (no longer
        // short), not that the line is still valid. Cancelling the whole
        // stale PO rather than patching it line by line keeps this simple
        // and auditable: one fresh draft, one cancelled predecessor, a
        // remarks trail explaining why.
        $staleCutoff = date('Y-m-d H:i:s', strtotime('-' . $draftStaleDays . ' days'));
        $lineItemIds = array_column($lines, 'item_id');
        $idPlaceholders = implode(',', array_fill(0, count($lineItemIds), '?'));
        $staleStmt = $db->prepare(
            "SELECT DISTINCT po.po_id, po.po_number
               FROM purchase_orders po
               JOIN purchase_order_items poi ON poi.po_id = po.po_id
              WHERE po.supplier_id = ?
                AND po.status = 'draft'
                AND po.created_at < ?
                AND poi.item_id IN ({$idPlaceholders})"
        );
        $staleStmt->execute(array_merge([$supplierId, $staleCutoff], $lineItemIds));
        $superseded = $staleStmt->fetchAll(PDO::FETCH_ASSOC);

        // States what this order actually DOES, not just where it came from
        // -- "restocks N ingredients, sized for the next X days of demand"
        // is the sentence an owner or supplier actually needs to read it and
        // understand the order without opening the app.
        $itemCount = count($lines);
        $remarks = 'Auto-generated from demand forecast run #' . $runId . ': restocks '
            . $itemCount . ' ingredient' . ($itemCount === 1 ? '' : 's')
            . ' at or below its reorder point, sized to cover the next ' . $forecastHorizonDays . ' days of expected demand.';
        if (!empty($superseded)) {
            $remarks .= ' Supersedes stale draft(s): '
                . implode(', ', array_column($superseded, 'po_number')) . '.';
        }

        try {
            $db->beginTransaction();

            foreach ($superseded as $stale) {
                $db->prepare(
                    "UPDATE purchase_orders SET status = 'cancelled' WHERE po_id = ? AND status = 'draft'"
                )->execute([$stale['po_id']]);
                try {
                    $db->prepare(
                        "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                         VALUES (?, 'Purchase Orders', 'Cancel purchase order', ?, ?)"
                    )->execute([
                        $systemUserId,
                        'Cancel purchase order: ' . $stale['po_number']
                            . ' (superseded by a fresher auto-generated draft for the same supplier)',
                        'forecast-auto-po',
                    ]);
                } catch (PDOException $e) {
                    // Activity logging is best-effort, same convention as
                    // purchase_order_status.php's own manual cancel action.
                }
            }

            // Left NULL when no line carries a lead time, rather than
            // inventing a date the data doesn't support.
            $expectedDelivery = $maxLeadTimeDays !== null
                ? date('Y-m-d', strtotime("+{$maxLeadTimeDays} days"))
                : null;

            $poLines = array_map(
                fn($l) => ['item_id' => $l['item_id'], 'quantity' => $l['quantity'],
                           'unit_cost' => $l['unit_cost'], 'is_vat_exempt' => $l['is_vat_exempt']],
                $lines
            );

            $po = createPurchaseOrderCore(
                $db, (int)$supplierId, date('Y-m-d'), $expectedDelivery,
                $poLines, $systemUserId, $remarks, true
            );

            $link = $db->prepare("UPDATE reorder_suggestions SET po_id = ? WHERE suggestion_id = ?");
            foreach ($lines as $l) {
                $link->execute([$po['po_id'], $l['suggestion_id']]);
            }

            // Every automatically drafted order waits for a person -- the
            // automation never approves its own order. That used to be
            // configurable up to a spending ceiling; the setting was removed
            // (2026-09-11) because nobody ever set one, so this was already
            // the only behaviour anyone had actually seen.
            notifyUsersByRole(
                $db, ['owner'], 'procurement', 'Purchase order auto-created',
                "Auto-created draft purchase order {$po['po_number']} (" . count($lines) . ' item'
                    . (count($lines) === 1 ? '' : 's') . ", total \u{20b1}" . number_format($batchTotal, 2)
                    . ') from demand forecasting -- review and approve.'
                    . (!empty($superseded)
                        ? ' Cancelled ' . count($superseded) . ' stale draft'
                            . (count($superseded) === 1 ? '' : 's') . ' it replaces ('
                            . implode(', ', array_column($superseded, 'po_number')) . ').'
                        : ''),
                'purchase_order_auto_created', $po['po_id']
            );

            $db->commit();
            $created[] = ['po_id' => $po['po_id'], 'po_number' => $po['po_number'],
                          'lines' => count($lines), 'total' => $batchTotal,
                          'superseded' => array_column($superseded, 'po_number')];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('draftPurchaseOrdersFromForecastRun failed for supplier ' . $supplierId . ': ' . $e->getMessage());
            // The suggestion rows stay as they are, po_id still NULL, so the
            // next run picks them up again rather than losing the intent.
            notifyUsersByRole(
                $db, ['owner'], 'procurement', 'Purchase order generation failed',
                'Auto purchase order generation failed for one supplier -- check the error log. It will be retried on the next run.',
                'purchase_order_auto_failed', null
            );
        }
    }

    return [
        'status'  => 'ok',
        'created' => count($created),
        'pos'     => $created,
    ];
}

/**
 * Everything that follows a completed pipeline run, in order.
 *
 * Called by owner/forecast_rerun.php (after the button) and cron/run_sweeps.php
 * (after the nightly run). Safe to call twice on the same run.
 */
function applyForecastRunOutputs(PDO $db, int $runId): array
{
    $levels = applyForecastReorderLevels($db, $runId);
    $pos    = draftPurchaseOrdersFromForecastRun($db, $runId);
    return ['reorder_levels' => $levels, 'purchase_orders' => $pos];
}

/**
 * The nightly job: run the pipeline, then apply its outputs.
 *
 * Gated the same way the old sweep was -- `auto_po_sweep_hour`, and once per
 * calendar day unless triggered by hand -- so a five-minute cron tick does not
 * refit Prophet 288 times a day.
 */
function sweepForecastPipeline(PDO $db, bool $isManualTrigger = false): array
{
    $settings = $db->query(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('auto_po_enabled', 'auto_po_sweep_hour')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    if (empty($settings['auto_po_enabled'])) {
        return ['status' => 'disabled'];
    }

    if (!$isManualTrigger) {
        $sweepHour = max(0, min(23, (int)($settings['auto_po_sweep_hour'] ?? 2)));
        if ((int)date('G') < $sweepHour) {
            return ['status' => 'before_sweep_hour'];
        }
        $ranToday = (int)$db->query(
            "SELECT COUNT(*) FROM forecast_runs
              WHERE run_type = 'ingredient_policy_sweep'
                AND status = 'completed'
                AND DATE(started_at) = CURDATE()"
        )->fetchColumn();
        if ($ranToday > 0) {
            return ['status' => 'already_ran_today'];
        }
    }

    $root   = dirname(__DIR__);
    $python = forecastPythonPath();
    $script = $root . '/forecasting/run.py';

    if (!is_file($python) || !is_file($script)) {
        error_log('sweepForecastPipeline: forecasting service not installed');
        return ['status' => 'not_installed'];
    }

    $output = [];
    $exit   = 0;
    exec(sprintf('%s %s 2>&1', escapeshellarg($python), escapeshellarg($script)), $output, $exit);

    if ($exit !== 0) {
        error_log('sweepForecastPipeline: run.py failed -- ' . implode("\n", array_slice($output, -12)));
        notifyUsersByRole(
            $db, ['owner'], 'procurement', 'Demand forecast run failed',
            'The nightly demand forecast could not complete, so no purchase orders were drafted. The previous forecast is still shown.',
            'forecast_run_failed', null
        );
        return ['status' => 'run_failed'];
    }

    $runId = (int)$db->query(
        "SELECT run_id FROM forecast_runs
          WHERE run_type = 'ingredient_policy_sweep' AND status = 'completed'
          ORDER BY run_id DESC LIMIT 1"
    )->fetchColumn();

    if ($runId <= 0) {
        return ['status' => 'no_completed_run'];
    }

    return ['status' => 'ok', 'run_id' => $runId] + applyForecastRunOutputs($db, $runId);
}

/**
 * Keeps the last `FORECAST_RUN_RETENTION` runs and deletes the rest.
 *
 * Each run writes roughly 4,400 contribution rows, 1,500 ingredient rows and
 * 1,400 menu rows. Nothing expired them once the old engine's own housekeeping
 * was disconnected, so the forecast tables grew by ~7,300 rows per run with no
 * ceiling.
 *
 * A run that produced a purchase order is never deleted, however old. Its
 * suggestion rows are the provenance of that PO -- the record of what the
 * forecast saw when it decided to buy -- and `reorder_suggestions.po_id` is the
 * only link between the two. Losing it would leave a purchase order in the
 * system that nothing can explain.
 */
const FORECAST_RUN_RETENTION = 14;

function pruneForecastRunHistory(PDO $db): int
{
    $keep = $db->query(
        "SELECT run_id FROM forecast_runs ORDER BY run_id DESC LIMIT " . FORECAST_RUN_RETENTION
    )->fetchAll(PDO::FETCH_COLUMN);

    // Runs that own a PO are pinned regardless of age.
    $pinned = $db->query(
        "SELECT DISTINCT run_id FROM reorder_suggestions WHERE po_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);

    $keep = array_unique(array_map('intval', array_merge($keep, $pinned)));
    if (empty($keep)) {
        return 0;
    }
    $list = implode(',', $keep);

    $doomed = (int)$db->query("SELECT COUNT(*) FROM forecast_runs WHERE run_id NOT IN ({$list})")->fetchColumn();
    if ($doomed === 0) {
        return 0;
    }

    foreach (['forecast_accuracy', 'reorder_suggestions', 'ingredient_demand_forecast',
              'ingredient_demand_contributions', 'menu_item_demand_forecast'] as $table) {
        $db->exec("DELETE FROM {$table} WHERE run_id NOT IN ({$list})");
    }
    $db->exec("DELETE FROM forecast_runs WHERE run_id NOT IN ({$list})");

    return $doomed;
}
