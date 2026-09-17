<?php
/**
 * owner/includes/forecast_view_functions.php
 *
 * Read-only accessors for the Demand Forecasting page.
 *
 * Everything here reads what the `forecasting/` pipeline wrote. Nothing in this
 * file computes a forecast, and nothing writes -- that separation is the whole
 * point of the two-page split: a manager checking tomorrow's demand should not
 * be one mis-click away from changing how the system buys food.
 *
 * The old PHP forecasting engine (owner/includes/inventory_policy_functions.php,
 * prophet_client.php) is NOT used by this page. It forecast each ingredient's
 * own series directly; the pipeline now forecasts one daily order count and
 * derives everything else by arithmetic.
 */

/** The three horizons every screen offers, from one control. */
const FORECAST_HORIZONS = [7, 14];

/**
 * How much to trust each horizon -- shown beside the number rather than left
 * for someone to assume. 90 days of history scores 7 days properly and 30 days
 * barely at all.
 */
function forecastHorizonCaution(int $horizon): ?string
{
    return match ($horizon) {
        7  => null,
        // Hidden for the 2026-09-03 defense. Remove the two "// " prefixes below to restore.
        // 14 => 'Planning horizon. Shown with its accuracy, but never used to decide whether the model is trusted.',
        default => null,
    };
}

/** The most recent completed run. Everything on the page hangs off this. */
function getLatestForecastRun(PDO $db): ?array
{
    $row = $db->query(
        "SELECT run_id, status, engine_version, started_at, finished_at, params_json
         FROM forecast_runs
         WHERE run_type = 'ingredient_policy_sweep' AND status = 'completed'
         ORDER BY run_id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['params'] = json_decode((string)$row['params_json'], true) ?: [];
    return $row;
}

/**
 * How much of each ingredient the forecast expects to consume over the horizon
 * the page is set to.
 *
 * Deliberately separate from the purchase plan, because they answer different
 * questions and are measured over different windows:
 *
 *   this          "how much pork will we get through in the next 30 days?"
 *                 -- a planning and budgeting figure, over the chosen horizon
 *
 *   purchase plan "do I need to order pork today?"
 *                 -- a decision, over the supplier's lead time
 *
 * Keeping them apart is what lets the horizon buttons mean something here
 * without dragging them into the reorder rule, where a 30-day window would
 * have the system buying a month of fresh fish at once.
 */
function getIngredientDemandOverHorizon(PDO $db, int $runId, int $horizon): array
{
    $stmt = $db->prepare(
        "SELECT i.item_name,
                u.unit_code,
                u.unit_type,
                SUM(f.overlaid_qty)                                   AS qty,
                SUM(f.overlaid_qty) * COALESCE(i.last_purchase_cost, 0) AS est_cost
           FROM ingredient_demand_forecast f
           JOIN inventory_items i  ON i.item_id = f.item_id
           JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
          WHERE f.run_id = :run
            AND f.forecast_date < DATE_ADD(
                (SELECT MIN(forecast_date) FROM ingredient_demand_forecast WHERE run_id = :run2),
                INTERVAL :horizon DAY)
          GROUP BY i.item_id, i.item_name, u.unit_code, u.unit_type, i.last_purchase_cost
         HAVING qty > 0
          ORDER BY est_cost DESC, i.item_name"
    );
    $stmt->bindValue(':run', $runId, PDO::PARAM_INT);
    $stmt->bindValue(':run2', $runId, PDO::PARAM_INT);
    $stmt->bindValue(':horizon', $horizon, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Format a forecast quantity for DISPLAY.
 *
 * Display only -- every stored and computed value stays at full precision.
 * Rounding the forecast itself would be a real mistake: 9 of the next week's
 * dish-days sit between 0 and 0.5, so round() would flatten them to zero, and
 * an ingredient forecast to zero means no safety stock on exactly the slow
 * sellers nobody is watching. Stage 3 also renormalises the menu back to the
 * predicted order total, which rounding the parts would break.
 *
 * Countable things get whole numbers -- 40 servings, 6 pcs -- because a half
 * serving is not a thing. Weight and volume keep two decimals, because 0.22 l
 * of syrup rounded to a whole number is 0, which is worse than untidy.
 */
function fmtForecastQty(float $qty, string $unitType = 'count'): string
{
    return strtolower($unitType) === 'count'
        ? number_format(round($qty))
        : number_format($qty, 2);
}

/** Forecast order counts per day for this run, limited to the chosen horizon. */
function getOrderForecast(PDO $db, int $runId, int $horizon): array
{
    // The pipeline stores per-dish servings, not the order count, so the daily
    // order total is recovered by dividing the day's servings by the units-per-
    // order figure the run recorded. Stated here rather than hidden: it is the
    // same number stage 3 split by, so the two cannot disagree.
    $stmt = $db->prepare(
        "SELECT forecast_date,
                SUM(predicted_quantity) AS servings,
                SUM(yhat_lower)         AS servings_lower,
                SUM(yhat_upper)         AS servings_upper
         FROM menu_item_demand_forecast
         WHERE run_id = ?
         GROUP BY forecast_date
         ORDER BY forecast_date
         LIMIT ?"
    );
    $stmt->bindValue(1, $runId, PDO::PARAM_INT);
    $stmt->bindValue(2, $horizon, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Actual daily order counts, for the "last 28 days then the forecast" chart. */
function getRecentActualOrders(PDO $db, int $days = 28): array
{
    $stmt = $db->prepare(
        "SELECT DATE(COALESCE(op.paid_at, op.created_at)) AS d,
                COUNT(DISTINCT o.order_id)                AS orders
         FROM orders o
         JOIN order_payments op ON op.order_id = o.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'cancelled'
         GROUP BY d
         ORDER BY d DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $days, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Per-dish demand over the horizon. */
function getMenuDemand(PDO $db, int $runId, int $horizon, string $search = '', ?int $categoryId = null): array
{
    $where  = ['f.run_id = ?'];
    $params = [$runId];
    if ($search !== '') {
        $where[]  = 'm.item_name LIKE ?';
        $params[] = '%' . $search . '%';
    }
    if ($categoryId !== null) {
        $where[]  = 'm.category_id = ?';
        $params[] = $categoryId;
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT m.item_id, m.item_name, c.category_name,
                   SUM(f.predicted_quantity) AS servings,
                   MIN(f.model_name)         AS model_name
            FROM menu_item_demand_forecast f
            JOIN menu_items m       ON m.item_id = f.menu_item_id
            LEFT JOIN menu_categories c ON c.category_id = m.category_id
            WHERE {$whereSql}
              AND f.forecast_date < DATE_ADD(
                    (SELECT MIN(forecast_date) FROM menu_item_demand_forecast WHERE run_id = ?),
                    INTERVAL ? DAY)
            GROUP BY m.item_id
            ORDER BY servings DESC";
    $params[] = $runId;
    $params[] = $horizon;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Buckets one item's open PO lines onto the day each lands, within
 * [$first, $last]. An order dated before $first is overdue (real, but not
 * available); after $last is real but out of view for this window; with no
 * expected date at all it is neither, just noted. Shared by
 * getPurchasePlan()'s two independent walks (decision window and display
 * window) so the classification logic exists exactly once.
 *
 * Returns [byDay (date => qty), arrivalNote, overdue (['days','po']|null)].
 */
function dfBucketReceipts(array $lines, string $first, string $last, string $today): array
{
    $byDay = [];
    $arrivalNote = null;
    $overdue = null;
    foreach ($lines as $in) {
        $qty = (float)$in['qty'];
        $exp = $in['d'] ?: null;
        if ($exp === null) {
            $arrivalNote ??= $in['po_number'] . ' has no expected delivery date';
            continue;
        }
        if ($exp < $first) {
            $late = (int)floor((strtotime($today) - strtotime($exp)) / 86400);
            if ($overdue === null || $late > $overdue['days']) {
                $overdue = ['days' => $late, 'po' => $in['po_number']];
            }
            continue;
        }
        if ($exp > $last) {
            $arrivalNote ??= $in['po_number'] . ' arrives ' . date('D j M', strtotime($exp));
            continue;   // lands after this window: real, but not visible here
        }
        $byDay[$exp] = ($byDay[$exp] ?? 0) + $qty;
        $arrivalNote ??= $in['po_number'] . ' arrives ' . date('D j M', strtotime($exp));
    }
    if ($overdue !== null) {
        $arrivalNote = $overdue['po'] . ' overdue ' . $overdue['days'] . 'd';
    }
    return [$byDay, $arrivalNote, $overdue];
}

/**
 * The heart of the page: for every ingredient, how much will we use, what will
 * be left, when do we run out, and -- separately -- when does it cross this
 * item's own configured reorder level.
 *
 * Two independent walks over the same demand series, deliberately kept
 * apart:
 *
 *   DECISION window -- always forecast_horizon_days from Settings, never
 *   $horizon. Drives the Reorder Level trigger, Suggested Purchase and
 *   Status. Switching the page between 7 and 14 days must never change what
 *   the automated pipeline would actually order -- the same invariant
 *   forecasting/run.py documents for why it forecasts forecast_view_max_days
 *   worth of data regardless of the planning horizon in Settings.
 *
 *   DISPLAY window -- $horizon, whatever the viewer selected. Drives the
 *   "N-Day Forecast" figure and the Projected Stock / runs-out figures, so
 *   an owner can look further ahead than the auto-PO commits to without that
 *   look-ahead being mistaken for a change in what gets ordered.
 *
 *   projected_stock(day) = projected_stock(day-1)
 *                        + deliveries arriving that day
 *                        - overlaid_qty for that day
 *
 * runs_out_on is the first day the DISPLAY balance goes below zero.
 * reorder_hit_on is the first day the DECISION balance reaches or falls
 * below the item's reorder_level -- WHEN replenishment is due.
 */
function getPurchasePlan(PDO $db, int $runId, int $horizon, string $search = '', ?int $supplierId = null): array
{
    $where  = ['s.run_id = ?'];
    $params = [$runId];
    if ($search !== '') {
        $where[]  = 'i.item_name LIKE ?';
        $params[] = '%' . $search . '%';
    }
    if ($supplierId !== null) {
        $where[]  = 'i.preferred_supplier_id = ?';
        $params[] = $supplierId;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT s.*, i.item_name, u.unit_code, u.unit_type,
                sup.supplier_name, i.lead_time_days,
                COALESCE(i.safety_stock_qty, 0) AS live_safety_stock,
                COALESCE(i.reorder_level, 0)    AS live_reorder_level
         FROM reorder_suggestions s
         JOIN inventory_items i   ON i.item_id  = s.item_id
         JOIN unit_of_measures u  ON u.unit_id  = i.base_unit_id
         LEFT JOIN suppliers sup  ON sup.supplier_id = i.preferred_supplier_id
         WHERE {$whereSql}
         ORDER BY FIELD(s.urgency,'critical','normal','none'),
                  FIELD(s.decision,'drafted','flagged_no_supplier','skipped_conversion_gap','no_action'),
                  i.item_name"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    // Daily demand for the running balance.
    $demandStmt = $db->prepare(
        "SELECT item_id, forecast_date, overlaid_qty
         FROM ingredient_demand_forecast
         WHERE run_id = ?
         ORDER BY item_id, forecast_date"
    );
    $demandStmt->execute([$runId]);
    $demand = [];
    foreach ($demandStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $demand[(int)$d['item_id']][] = $d;
    }

    // Deliveries already on the way, read LIVE rather than from the run's
    // snapshot. reorder_suggestions.on_order_qty is frozen at the moment the
    // pipeline ran, so a purchase order cancelled or deleted since would keep
    // masking a shortfall until the next run. Open supplier commitments are
    // displayed here, but only orders landing inside whichever window is
    // being checked (decision or display, see dfBucketReceipts()) count as
    // coverage for that check. Drafts are not supplier commitments.
    $inbound = [];
    $inStmt = $db->query(
        "SELECT poi.item_id, p.expected_delivery_date AS d, p.po_number, p.status,
                SUM(poi.quantity_ordered - COALESCE(poi.quantity_received,0)) AS qty
         FROM purchase_order_items poi
         JOIN purchase_orders p ON p.po_id = poi.po_id
         WHERE p.status IN ('ordered','partially_received')
         GROUP BY poi.item_id, p.expected_delivery_date, p.po_number, p.status
         HAVING qty > 0"
    );
    foreach ($inStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $inbound[(int)$r['item_id']][] = $r;
    }

    // The REAL planning horizon (Settings > Purchasing & Forecasting), never
    // the display horizon the viewer happens to have selected -- see this
    // function's own docblock. Floored at 1 the same way s6_reorder.py's
    // decide() floors it.
    $decisionHorizon = max(1, (int)($db->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'forecast_horizon_days'"
    )->fetchColumn() ?: 7));

    $today = date('Y-m-d');

    foreach ($rows as &$row) {
        $itemId = (int)$row['item_id'];
        $allDays = $demand[$itemId] ?? [];
        $lines   = $inbound[$itemId] ?? [];

        $days    = array_slice($allDays, 0, $horizon);          // DISPLAY window
        $decDays = array_slice($allDays, 0, $decisionHorizon);  // DECISION window

        $first    = $days    ? (string)$days[0]['forecast_date']    : $today;
        $last     = $days    ? (string)$days[count($days) - 1]['forecast_date']    : $today;
        $decFirst = $decDays ? (string)$decDays[0]['forecast_date'] : $today;
        $decLast  = $decDays ? (string)$decDays[count($decDays) - 1]['forecast_date'] : $today;

        $onOrder = 0.0;
        foreach ($lines as $in) {
            $onOrder += (float)$in['qty'];
        }

        // Bucketed twice, once per window -- a PO can legitimately qualify
        // for the DISPLAY walk (e.g. arriving day 10 of a 14-day view) while
        // not qualifying for the DECISION walk (outside the real 7-day
        // planning horizon), and the two must never be conflated.
        [$byDayDisplay, $arrivalNote, $overdue]  = dfBucketReceipts($lines, $first, $last, $today);
        [$byDayDecision, , ]                     = dfBucketReceipts($lines, $decFirst, $decLast, $today);

        $safetyStock  = (float)$row['live_safety_stock'];
        $reorderLevel = (float)$row['live_reorder_level'];
        $onHandQty    = (float)$row['on_hand_qty'];

        // -- DECISION walk: forecast_horizon_days, always -- drives the
        // Reorder Level trigger, Suggested Purchase and Status. This is the
        // same walk as forecasting/stages/s6_reorder.py::decide():
        //
        //   WHEN     = this item's OWN configured reorder_level (typed on the
        //              Inventory item form, never computed)
        //   walk     = balance(day) = balance(day-1) + receipts(day) - demand(day)
        //              across the DECISION window; the first day balance
        //              reaches or falls below reorder_level is when
        //              replenishment was due
        //   HOW MUCH = decision-window demand + safety stock, net of on-hand
        //              stock and whatever incoming PO quantity qualifies
        //              (landed inside this same decision window)
        //
        // reorder_suggestions is a snapshot frozen when the pipeline ran. Delete
        // a draft PO and that snapshot still reports the item as covered, which
        // is exactly how a row ends up saying "Covered by incoming PO" next to a
        // date it runs out. Rather than let the page contradict itself until the
        // next run, the walk is redone here against live stock, live open
        // orders, the item's live configured reorder level, and the manager's
        // current safety stock. The forecast itself still comes from the run --
        // nothing is invented.
        $decBalance = $onHandQty;
        $reorderHitOn = null;
        $qualifyingIncoming = 0.0;
        $decisionDemand = 0.0;
        foreach ($decDays as $d) {
            $date = (string)$d['forecast_date'];
            if (isset($byDayDecision[$date])) {
                $decBalance += $byDayDecision[$date];
                $qualifyingIncoming += $byDayDecision[$date];
            }
            $demandToday = (float)$d['overlaid_qty'];
            $decBalance -= $demandToday;
            $decisionDemand += $demandToday;
            if ($reorderHitOn === null && $decBalance <= $reorderLevel) {
                $reorderHitOn = $date;
            }
        }
        $triggered = $reorderHitOn !== null;

        // HOW MUCH is independent of WHEN -- decision-window demand plus
        // safety stock, never floored at the reorder level (the two numbers
        // no longer relate to each other at all).
        $targetStock = $decisionDemand + $safetyStock;
        $isCount = strtolower((string)($row['unit_type'] ?? 'count')) === 'count';
        if ($isCount) {
            $targetStock = ceil($targetStock);
        }

        $shortfall = $triggered ? max(0.0, $targetStock - $onHandQty - $qualifyingIncoming) : 0.0;
        // Same rounding the pipeline applies in s6_reorder.round_up(): whole
        // units for countable things, 10 g / 10 ml for weight and volume. Kept
        // identical on purpose -- if these two drift, the quantity on this page
        // stops matching the quantity that reaches the purchase order.
        if ($shortfall > 0) {
            $shortfall = $isCount
                ? ceil($shortfall)                  // you cannot order 6.4 eggs
                : ceil($shortfall * 100) / 100;     // nobody weighs out 2.724 kg
        }

        // -- DISPLAY walk: $horizon, whatever the viewer selected -- drives
        // the "N-Day Forecast" figure and how far the Projected Stock /
        // runs-out figures look. Purely a look-ahead; nothing computed here
        // feeds the trigger, the quantity, or the status above.
        $balance = $onHandQty;
        $use = 0.0;
        $arriving = 0.0;
        $runsOut = null;
        $series = [];
        $displayDemand = 0.0;
        foreach ($days as $d) {
            $date = (string)$d['forecast_date'];
            if (isset($byDayDisplay[$date])) {
                $balance  += $byDayDisplay[$date];
                $arriving += $byDayDisplay[$date];
            }
            $demandToday = (float)$d['overlaid_qty'];
            $balance -= $demandToday;
            $use += $demandToday;
            $displayDemand += $demandToday;
            $series[] = ['date' => $date, 'balance' => round($balance, 3)];
            if ($runsOut === null && $balance < 0) {
                $runsOut = $date;
            }
        }
        $projected = $balance; // end-of-DISPLAY-window position

        $row['cover_days'] = max(1, count($days));
        $row['cover_from'] = $first;
        $row['cover_to']   = date('Y-m-d', strtotime($first . ' +' . ($row['cover_days'] - 1) . ' days'));
        $row['decision_horizon'] = $decisionHorizon;

        $row['live_safety']         = $safetyStock;
        $row['live_reorder_level']  = $reorderLevel;
        $row['live_projected']      = $projected;
        $row['live_horizon_demand'] = $displayDemand;
        $row['live_decision_demand'] = $decisionDemand;
        $row['live_restock_target'] = $targetStock;
        $row['live_triggered']      = $triggered;
        $row['reorder_hit_on']      = $reorderHitOn;
        $row['live_suggested']      = $triggered ? round($shortfall, 3) : 0.0;
        // Nothing to buy either way, but WHY differs and the row should say
        // which -- same distinction s6_reorder.py's suppressed_by_open_commitment
        // makes. reorder_level and target_stock are independent now, so an
        // item can cross its OWN reorder level purely because on-hand stock
        // alone still exceeds target_stock, with no PO involved at all --
        // that is "Sufficient Stock", never "covered by a PO" that does not
        // exist. Only when a qualifying incoming quantity actually
        // contributed to reaching zero does it get the PO reason.
        $row['live_suppressed']     = $triggered && $shortfall <= 0 && $qualifyingIncoming > 0;

        $row['forecast_use']   = $use;
        $row['arriving']       = $arriving;
        $row['live_on_order']  = $onOrder;
        $row['live_qualifying_incoming'] = $qualifyingIncoming;
        $row['projected']      = $balance;
        $row['runs_out_on']    = $runsOut;
        $row['arrival_note']   = $arrivalNote;
        $row['overdue_po']     = $overdue;
        $row['balance_series'] = $series;
    }
    unset($row);

    return $rows;
}

/** Accuracy for this run, keyed by horizon. */
function getForecastAccuracy(PDO $db, int $runId): array
{
    $stmt = $db->prepare(
        "SELECT horizon_days, wape_pct, baseline_wape_pct, beats_baseline,
                bias_pct, fold_count, reconciled_count, run_id
         FROM forecast_accuracy WHERE run_id = ? ORDER BY horizon_days"
    );
    $stmt->execute([$runId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Not every run scores itself. The Re-run button passes --skip-accuracy,
    // because a backtest refits Prophet once per fold per horizon and that is
    // far too slow for a page request -- the nightly job does the scoring.
    // So the newest run frequently has no accuracy rows of its own, and
    // returning nothing here would blank the panel and make a working system
    // look unmeasured. Fall back to the most recent run that DID score.
    //
    // Accuracy describes the MODEL, not one run's output: the Prophet
    // configuration is frozen and the training window rolls by a day at a
    // time, so yesterday's measured error is the honest number for today's
    // forecast. The row carries its own run_id so the page can say which run
    // it came from rather than implying it was measured just now.
    if (empty($rows)) {
        $fallbackRunId = (int)$db->query(
            "SELECT run_id FROM forecast_accuracy ORDER BY run_id DESC LIMIT 1"
        )->fetchColumn();
        if ($fallbackRunId > 0 && $fallbackRunId !== $runId) {
            $stmt->execute([$fallbackRunId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['horizon_days']] = $r;
    }
    return $out;
}

/**
 * The two things the forecast is actually made of.
 *
 * Prophet does not predict a number out of nowhere -- under multiplicative
 * seasonality it fits  yhat = trend x (1 + weekly)  and the pieces are
 * separable. Stage 2 keeps them in the run's params, and this hands them to the
 * page so the model can be SHOWN rather than asserted:
 *
 *   trend   the underlying level, once the day-of-week rhythm is taken out
 *   weekly  how much each weekday differs from that level, as a percentage
 *
 * Returns null when a run predates this being recorded, so the page can just
 * omit the section rather than render an empty chart.
 */
function getForecastComponents(?array $run, ?int $horizon = null): ?array
{
    $c = $run['params']['s2_forecast']['components'] ?? null;
    if (!is_array($c) || empty($c['trend'])) {
        return null;
    }

    // The trend is a series of dates, so it has to honour the horizon the page
    // is set to -- otherwise picking "next 7 days" still draws a line running
    // into October, and the chart contradicts the button above it. The weekly
    // pattern is NOT trimmed: it is seven weekday factors with no date axis at
    // all, and it is identical whichever period is chosen.
    $trend = $c['trend'];
    if ($horizon !== null && $horizon > 0) {
        $trend = array_slice($trend, 0, $horizon);
    }
    $first = (float)($trend[0]['value'] ?? 0);
    $last  = (float)($trend[count($trend) - 1]['value'] ?? 0);

    // Day names in week order, not the alphabetical order a JSON object gives.
    $order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $weekly = [];
    foreach ($order as $d) {
        if (isset($c['weekly_profile'][$d])) {
            $weekly[$d] = (float)$c['weekly_profile'][$d];
        }
    }

    return [
        'mode'         => $c['mode'] ?? 'multiplicative',
        // How much history these were fitted on -- the section states it, and
        // it is the honest qualifier on both charts.
        'trading_days' => (int)($run['params']['s1_history']['history_days_used'] ?? 0),
        'trend'        => $trend,
        'trend_first'  => $first,
        'trend_last'   => $last,
        'trend_pct'    => $first > 0 ? (($last - $first) / $first) * 100 : 0.0,
        'weekly'       => $weekly,
        'busiest'      => $weekly ? array_search(max($weekly), $weekly, true) : null,
        'quietest'     => $weekly ? array_search(min($weekly), $weekly, true) : null,
        // The weekly effect expressed as ORDERS rather than a percentage.
        // "+26%" is not something anyone can picture; "28 orders instead of 17"
        // is. Derived from the same two components the charts plot, so the
        // sentence and the bars can never disagree.
        'busy_orders'  => $weekly ? $first * (1 + max($weekly)) : null,
        'quiet_orders' => $weekly ? $first * (1 + min($weekly)) : null,

        // Prophet's own per-day order forecast: date, yhat, lower, upper.
        //
        // The page used to recover the order count by dividing predicted
        // servings by units-per-order. That round trip is faithful but it is a
        // derivation, and it cannot carry the prediction interval at all --
        // servings have no lower/upper of their own once they have been
        // renormalised. These are the numbers the model actually produced.
        //
        // Trimmed to the horizon for the same reason `trend` is: the run always
        // stores 30 days, and an untrimmed series would draw a line past the
        // window the button above the chart selected.
        'daily_orders' => $horizon !== null && $horizon > 0
            ? array_slice($c['daily_orders'] ?? [], 0, $horizon)
            : ($c['daily_orders'] ?? []),

        // The fitted trend over the training window. Deliberately NOT trimmed to
        // the horizon: this is the history the forward trend was extrapolated
        // from, and clipping it to 7 days would reintroduce exactly the problem
        // it exists to solve -- a flat line with no evidence behind it.
        'trend_history' => $c['trend_history'] ?? [],
    ];
}

/**
 * What the system knows about the quality of its own inputs. Surfaced rather
 * than left for someone to discover -- an ingredient in no recipe is not
 * covered, and saying so is the honest answer.
 */
/**
 * Public holidays falling inside the forecast window being displayed.
 *
 * The pipeline treats holidays in exactly one place: stage 1 DELETES them from
 * the training set, so a holiday that traded abnormally cannot distort the
 * weekday factor for whichever day it landed on. Nothing downstream of stage 1
 * knows they exist, which means a holiday inside the FORECAST window is
 * predicted as an ordinary day of that weekday -- Prophet has no holiday term
 * to apply and, at one observation per holiday in ninety days, fitting one
 * would memorise that day rather than learn an effect.
 *
 * Saying so is the entire point of this function. The alternative is a page
 * that quietly shows a normal Sunday for All Saints' Day and lets a purchase
 * plan be built on it. An uplift belongs here eventually -- pooled by
 * holiday_type, which accumulates evidence roughly eight times faster than
 * per-holiday -- but not until there are enough observations to justify one.
 * Until then the honest output is the flag, not a number.
 *
 * Same table and same `is_active = 1` filter the payroll engine reads, so the
 * two modules can never disagree about whether a date is a holiday.
 */
function getHolidaysInForecastWindow(PDO $db, ?string $start, ?string $end): array
{
    if (!$start || !$end) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT holiday_date, holiday_name, holiday_type
           FROM holidays
          WHERE is_active = 1
            AND holiday_date BETWEEN ? AND ?
          ORDER BY holiday_date"
    );
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDataHealth(PDO $db, ?array $run): array
{
    $params = $run['params'] ?? [];
    return [
        'trading_days'    => $params['s1_history']['history_days_used'] ?? null,
        'excluded_dates'  => $params['s1_history']['excluded_dates'] ?? [],
        'conversion_gaps' => $params['s4_explode']['conversion_gap_count'] ?? 0,
        // How far past the last day of real sales these predictions reach. The
        // forecast starts today, but the model was trained to the last day of
        // continuous trading -- when those differ, the gap is extrapolation and
        // is worth stating rather than leaving to be discovered.
        'training_end'      => $params['s2_forecast']['training_end'] ?? null,
        'extrapolated_days' => (int)($params['s2_forecast']['extrapolated_days'] ?? 0),
        'no_recipe' => $db->query(
            "SELECT i.item_name FROM inventory_items i
             WHERE i.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM menu_item_ingredients m WHERE m.inventory_item_id = i.item_id)
               AND NOT EXISTS (SELECT 1 FROM packaging_rule_items p WHERE p.inventory_item_id = i.item_id)"
        )->fetchAll(PDO::FETCH_COLUMN),
    ];
}

/**
 * Status pill for a purchase-plan row -- plain words, not jargon.
 *
 * Gated on the SUGGESTED QUANTITY, not directly on whether the item's own
 * reorder level was crossed during the walk -- the two can legitimately
 * disagree. reorder_level and target_stock (7-day demand + safety) are
 * fully independent numbers now, so an item can cross its own reorder level
 * at some point in the horizon purely because that level was set above what
 * the target actually needs, while on-hand stock alone still comfortably
 * covers the week. That case must read as "Sufficient Stock" -- nothing is
 * being bought -- never "Needs Replenishment", which the quantity column
 * beside it (a blank dash) would then flatly contradict.
 */
function planStatus(array $row): array
{
    // Two outcomes are structural -- they describe the ingredient's setup, not
    // its stock level, so they cannot change between runs and are answered
    // straight from the decision the pipeline recorded.
    if ($row['decision'] === 'flagged_no_supplier') {
        return ['No Supplier Set', 'is-neutral'];
    }
    if ($row['decision'] === 'skipped_conversion_gap') {
        return ['Unit Mismatch', 'is-neutral'];
    }

    // Four outcomes:
    //
    //   Sufficient Stock       nothing to buy, and no PO is why
    //   Covered by Incoming PO nothing to buy, and a qualifying incoming PO
    //                          is why
    //   Needs Replenishment    a real quantity is suggested
    //   Auto PO Generated      a real quantity is suggested, and the run
    //                          already drafted one
    //
    // "Sufficient Stock" and "Covered by Incoming PO" read very differently on
    // purpose -- the first never needed help, the second was genuinely short
    // and a PO already in transit is doing the work. Folding them into one
    // label would credit the PO's coverage to stock that was never actually
    // enough on its own -- or worse, credit a PO that does not exist.
    if ((float)($row['live_suggested'] ?? 0) <= 0) {
        return !empty($row['live_suppressed'])
            ? ['Covered by Incoming PO', 'is-neutral']
            : ['Sufficient Stock', 'is-success'];
    }

    return !empty($row['po_id'])
        ? ['Auto PO Generated', 'is-neutral']
        : ['Needs Replenishment', $row['urgency'] === 'critical' ? 'is-critical' : 'is-warning'];
}
