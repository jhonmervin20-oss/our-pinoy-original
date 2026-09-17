<?php
/**
 * owner/includes/dashboard_functions.php
 *
 * Data layer for owner/dashboard.php -- a true operational dashboard
 * (live KPIs, trend charts, stock/sales widgets, recent activity), not a
 * module launcher (that's what the sidebar is for). Every widget reuses
 * the exact same filter/status/query logic its source module already
 * uses -- getDailyDenseRevenueHistory()/buildFastMovingQuery()/
 * computeForecastSummary() from demand_forecast_functions.php,
 * buildReservationTrend() from menu_performance_functions.php, buildInventoryPolicyReport() from
 * inventory_policy_functions.php, computeSalesKpiSummary()/
 * computeCategoryRevenueRanked() from sales_analytics_functions.php, the
 * same inventory_stock_status view report_functions.php reads -- so a
 * number or chart here always matches what you'd see after clicking
 * through to that module (via the sidebar, not a dashboard tile).
 *
 * Two different time scopes are in play, deliberately:
 *  - The KPI strip, the 4 trend charts, and Best Sellers all respect the
 *    dashboard's own global date filter (Today/Yesterday/Last 7 days/
 *    Last 30 days/This month/Last month) -- these are the "how are we
 *    doing" historical/comparative section.
 *  - Today's Reservations, Today's Orders, Draft POs, and Receiving POs
 *    are always live/current-state ("what needs my attention right now"),
 *    independent of the filter -- same as the original single-page
 *    dashboard's design intent.
 *  - AI Forecast and Purchase Recommendations are forward-looking
 *    (tomorrow's forecast, what to buy next), so they're also independent
 *    of the historical filter -- always computed over the same trailing
 *    30-day window demand_forecast.php itself defaults to.
 *
 * No fabricated metrics: there is no "confidence %" anywhere in this
 * codebase's forecasting engine (see computeForecastProphet()'s return
 * shape in demand_forecast_functions.php -- predicted/gated_reason only),
 * so the AI Forecast widget only shows the green "AI Forecast" badge when
 * Prophet actually produced a number, and an honest "unavailable" note
 * otherwise, instead of inventing a confidence figure.
 */

require_once __DIR__ . '/../../config/inventory_alerts.php'; // sweepInventoryStockAlerts()
require_once __DIR__ . '/../../config/feedback_insights.php'; // sweepFeedbackInsights()
require_once __DIR__ . '/demand_forecast_functions.php'; // resolvePeriodRange(), previousPeriodRange(), getDailyDenseRevenueHistory(), buildFastMovingQuery(), computeForecastSummary(), aiForecastBadgeHtml()
require_once __DIR__ . '/inventory_policy_functions.php'; // buildInventoryPolicyReport(), demandPatternLabel()/BadgeClass()
require_once __DIR__ . '/menu_performance_functions.php'; // buildReservationTrend()
require_once __DIR__ . '/sales_analytics_functions.php'; // computeSalesKpiSummary(), salesDeltaMeta(), computeCategoryRevenueRanked()
require_once __DIR__ . '/../../orders/includes/order_functions.php'; // orderTypeLabel()

/** Matches owner/includes/report_functions.php's reportKpiCard() markup exactly -- kept as its own copy per this app's established per-module convention. Does NOT escape $meta, since callers may pass pre-built HTML (e.g. a colored delta span). */
function dashboardKpiCard(string $label, string $value, string $meta = '', string $icon = 'ph-chart-bar'): string
{
    return '<div class="owner-summary-card"><div style="flex:1;min-width:0;">'
        . '<div class="owner-summary-card-label">' . htmlspecialchars($label) . '</div>'
        . '<div class="owner-summary-card-value">' . $value . '</div>'
        . ($meta !== '' ? '<div class="owner-summary-card-meta">' . $meta . '</div>' : '')
        . '</div><i class="ph ' . htmlspecialchars($icon) . '" style="font-size:1.6rem;color:var(--op-gold);flex-shrink:0;" aria-hidden="true"></i></div>';
}

// =======================================================================
// KPI strip -- all 4 respect the global date filter except stock (a
// point-in-time snapshot has no meaningful date range).
// =======================================================================

/**
 * Revenue KPI for the selected period, with a delta vs. the equal-length
 * previous period.
 *
 * The headline is NET SALES EXCLUDING VAT -- the P&L revenue line, and the same
 * figure the Sales report leads with, so the dashboard and the report can never
 * show an owner two different "revenue" numbers again. `total_collected` comes
 * back alongside it because the tile links through to the order list, which
 * shows VAT-inclusive order totals; without it the click-through looks like a
 * discrepancy.
 */
function getRevenueKpi(PDO $db, DateTime $start, DateTime $end, DateTime $prevStart, DateTime $prevEnd): array
{
    $kpi     = computeSalesKpiSummary($db, $start, $end);
    $prevKpi = computeSalesKpiSummary($db, $prevStart, $prevEnd);

    return [
        'net_revenue'     => $kpi['net_sales_vat_exc'],
        'total_collected' => $kpi['total_collected'],
        'total_orders'    => $kpi['total_orders'],
        'delta_html'      => salesDeltaMeta($kpi['net_sales_vat_exc'], $prevKpi['net_sales_vat_exc']),
    ];
}

/**
 * Net revenue (excl. VAT) per calendar day, dense -- so the "Sales Trend"
 * chart's bars always sum back to getRevenueKpi()'s "Net sales (excl. VAT)"
 * tile shown right above it.
 *
 * Deliberately NOT getDailyDenseRevenueHistory() (demand_forecast_functions.php),
 * even though that function's own name and this file's module docblock once
 * pointed the dashboard at it as the "reusable home" for this chart. That
 * function sums SUM(oi.subtotal) -- gross, pre-discount, VAT-inclusive -- and
 * uses a business-day cutoff boundary tuned for Prophet's training data, not
 * the plain calendar-day boundary buildSalesStatement() uses. It is exactly
 * right for forecasting (demand_forecast_functions.php's own callers, and
 * menu_performance_functions.php's buildOrderVolumeTrend()) and must stay
 * untouched for them, but reusing it here made this same tile disagree with
 * its own KPI card the same way analytics_functions.php's buildRevenueTrend()
 * did before it was fixed -- both were cross-verified against EACH OTHER
 * (both gross), never against the net figure either KPI card actually shows.
 * This is a local copy of that fix, same "if you change the originals,
 * change these too" convention as getStockUsageTrendSeries()/
 * getWastageTrendSeries() below.
 */
function getNetRevenueTrendSeries(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT DATE(" . SALE_DATE_EXPR . ") AS d,
                o.discount_amount, o.vat_amount,
                COALESCE(i.items_gross, 0) AS items_gross
         FROM orders o
         JOIN order_payments op ON op.order_id = o.order_id
         LEFT JOIN (
             SELECT oi.order_id, SUM(oi.subtotal) AS items_gross
             FROM order_items oi
             WHERE " . SALE_ITEM_PREDICATE . "
             GROUP BY oi.order_id
         ) i ON i.order_id = o.order_id
         WHERE " . SALE_PREDICATE . "
           AND " . SALE_DATE_EXPR . " BETWEEN ? AND ?"
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);

    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $net = (float)$r['items_gross'] - (float)$r['discount_amount'] - (float)$r['vat_amount'];
        $sparse[$r['d']] = ($sparse[$r['d']] ?? 0.0) + $net;
    }
    foreach ($sparse as $d => $v) {
        $sparse[$d] = round($v, 2);
    }

    return denseDailyMap($sparse, $start, $end);
}

/** Low/critical/out-of-stock item counts, same inventory_stock_status view report_functions.php reads. Not period-filtered -- current stock has no date range. */
function getInventoryStockAttention(PDO $db): array
{
    $counts = ['critical' => 0, 'low' => 0, 'out_of_stock' => 0];
    $stmt = $db->query(
        "SELECT stock_status, COUNT(*) AS cnt FROM inventory_stock_status
         WHERE stock_status IN ('critical', 'low', 'out_of_stock') GROUP BY stock_status"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['stock_status']] = (int)$row['cnt'];
    }
    $counts['total'] = array_sum($counts);
    return $counts;
}

/** Reservation status breakdown for the selected period, same bucket shape reservation/reservations.php's own inline $bucketCounts uses. */
function getReservationKpi(PDO $db, DateTime $start, DateTime $end): array
{
    $buckets = ['pending' => 0, 'confirmed' => 0, 'seated' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
    $stmt = $db->prepare("SELECT status, COUNT(*) AS cnt FROM reservations WHERE reservation_date BETWEEN ? AND ? GROUP BY status");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $buckets[$row['status']] = (int)$row['cnt'];
    }
    $buckets['total'] = array_sum($buckets);
    return $buckets;
}

/** Purchase order activity for the selected period (by order_date), excluding drafts/cancellations -- "pending" = anything still in the approve/order/receive pipeline, "completed" = fully received. */
function getReceivingKpi(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT status, COUNT(*) AS cnt FROM purchase_orders
         WHERE order_date BETWEEN ? AND ? AND status NOT IN ('draft', 'cancelled')
         GROUP BY status"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $pending = 0;
    $completed = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['status'] === 'received') {
            $completed += (int)$row['cnt'];
        } else {
            $pending += (int)$row['cnt'];
        }
    }
    return ['total' => $pending + $completed, 'pending' => $pending, 'completed' => $completed];
}

// =======================================================================
// Dashboard charts -- the same four trends the Analytics module shows:
// Sales, Reservations, Stock usage and Waste. Deliberately the SAME
// SERIES, not merely similar ones: the owner reads both pages and a
// figure that moves between them reads as a bug.
//
// One of the four already has a reusable home the dashboard can reach:
//   - Reservation trend -> buildReservationTrend()['volume'] (menu_performance_functions.php)
// Verified day-for-day against analytics_functions.php's
// buildAnalyticsReservationTrend() over last30 -- identical totals
// (31 bookings), zero differing days.
//
// Sales trend used to reuse getDailyDenseRevenueHistory()
// (demand_forecast_functions.php) the same way, cross-verified against
// analytics_functions.php's old buildRevenueTrend() -- but both of THOSE
// summed gross, pre-discount revenue, never the net figure either page's
// own KPI card actually shows. See getNetRevenueTrendSeries()'s own
// docblock above: it is now a local net-revenue copy instead, matching
// analytics_functions.php's corrected buildRevenueTrend().
//
// The remaining three (sales, stock usage, waste) live only in
// analytics_functions.php, whose OpenAI and menu-costing dependency chain
// is far too heavy to pull into a dashboard for one query each, so they are
// light local copies below -- the same per-module convention
// sales_analytics_functions.php's docblock describes. If you change the
// originals, change these too.
// =======================================================================

/**
 * Local copy of analytics_functions.php's buildStockUsageTrend().
 *
 * The order-based dating is load-bearing, not incidental: inventory_transactions
 * was backfilled and most stock_out rows carry the backfill date in created_at,
 * so charting by created_at draws one huge artificial spike. Dating by the
 * linked order's payment is the only honest reading. Packaging-deduction rows
 * have no order line and are therefore absent rather than dated wrongly.
 *
 * Valued in PESOS, never in quantity -- ingredients are drawn from batches in
 * a mix of units (kg, L, pcs), so summing raw `quantity` across a day adds
 * kilograms to pieces to litres, a number with no real unit. Cost is the one
 * common denominator, same reasoning and same batch-unit_cost valuation as
 * buildWastageTrend()/getWastageTrendSeries() use for the same reason.
 */
function getStockUsageTrendSeries(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT DATE(COALESCE(op.paid_at, o.created_at)) AS d, SUM(it.quantity * b.unit_cost) AS cost
         FROM inventory_transactions it
         JOIN order_items oi     ON oi.order_item_id = it.reference_id
         JOIN orders o           ON o.order_id = oi.order_id
         JOIN order_payments op  ON op.order_id = o.order_id
         JOIN inventory_batches b ON b.batch_id = it.batch_id
         WHERE it.transaction_type = 'stock_out'
           AND it.reference_type = 'order_item'
           AND op.payment_status = 'paid' AND o.order_status <> 'voided'
           AND COALESCE(op.paid_at, o.created_at) BETWEEN ? AND ?
         GROUP BY d ORDER BY d"
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sparse[$row['d']] = (float)$row['cost'];
    }
    return denseDailyMap($sparse, $start, $end);
}

/**
 * Local copy of analytics_functions.php's buildWastageTrend().
 *
 * Valued in PESOS, never quantity: wastage_records mixes units across items
 * (a 300 kg row and a 20 pcs row are both just `quantity`), so a quantity
 * total would be adding kilos to pieces. Each row is valued at its own
 * batch's unit_cost.
 */
function getWastageTrendSeries(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT w.wastage_date AS d, SUM(w.quantity * b.unit_cost) AS cost
         FROM wastage_records w
         JOIN inventory_batches b ON b.batch_id = w.batch_id
         WHERE w.wastage_date BETWEEN ? AND ? GROUP BY d"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sparse[$row['d']] = (float)$row['cost'];
    }
    return denseDailyMap($sparse, $start, $end);
}

/** Top selling items for the selected period, reusing buildFastMovingQuery() exactly as Sales/Analytics/Report already do. */
function getBestSellingItems(PDO $db, DateTime $start, DateTime $end, int $limit = 8): array
{
    $filters = ['date_from' => $start->format('Y-m-d 00:00:00'), 'date_to' => $end->format('Y-m-d 23:59:59'), 'category_id' => null, 'search' => null];
    [$sql, $params] = buildFastMovingQuery($filters);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_slice($stmt->fetchAll(PDO::FETCH_ASSOC), 0, $limit);
}

// =======================================================================
// Live "right now" widgets -- always today/current-state, independent of
// the global date filter (see module docblock).
// =======================================================================

/** Today's reservations, same JOIN shape report_functions.php's buildReservationSummary() list uses -- customer name, time slot, party size, status. */
function getTodayReservationsList(PDO $db, int $limit = 8): array
{
    $stmt = $db->prepare(
        "SELECT r.reservation_id, r.reservation_number, r.number_of_guests, r.status,
                ts.slot_label, ts.start_time,
                CONCAT(u.first_name, ' ', u.last_name) AS customer_name
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         LEFT JOIN users u ON u.user_id = r.customer_id
         WHERE r.reservation_date = CURDATE()
         ORDER BY ts.start_time ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Today's orders, same JOIN shape orders/includes/order_functions.php's
 * buildOrdersQuery() uses. payment_status (pending/paid/failed) is the
 * real status field here -- orders.order_status is deliberately not used
 * (see that file's docblock: every row is permanently 'open', no kitchen
 * workflow writes anything else, so it would just show "Open" forever).
 */
function getTodayOrdersList(PDO $db, int $limit = 8): array
{
    $stmt = $db->prepare(
        "SELECT o.order_id, o.order_number, o.order_type, o.total_amount, o.created_at,
                cust.first_name AS customer_first_name, cust.last_name AS customer_last_name,
                op.payment_status
         FROM orders o
         LEFT JOIN users cust ON cust.user_id = o.customer_id
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE DATE(o.created_at) = CURDATE()
         ORDER BY o.created_at DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Current draft POs -- these are always "live", not period-filtered (a worklist of what still needs review/approval, same as purchase_orders.php's own list). */
function getDraftPurchaseOrders(PDO $db, int $limit = 5): array
{
    $stmt = $db->prepare(
        "SELECT po.po_id, po.po_number, po.order_date, po.total_amount, po.is_auto_generated, s.supplier_name
         FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id
         WHERE po.status = 'draft'
         ORDER BY po.order_date DESC, po.po_id DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** POs currently ordered/partially received -- what's incoming and needs receiving. */
function getReceivingPurchaseOrders(PDO $db, int $limit = 5): array
{
    $stmt = $db->prepare(
        "SELECT po.po_id, po.po_number, po.status, po.expected_delivery_date, po.total_amount, s.supplier_name
         FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id
         WHERE po.status IN ('ordered', 'partially_received')
         ORDER BY po.expected_delivery_date IS NULL, po.expected_delivery_date ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =======================================================================
// Forward-looking widgets -- always the same trailing-30-day window
// demand_forecast.php itself defaults to, independent of the dashboard's
// historical filter (forecasting "what's next" doesn't change based on
// which historical range you're currently eyeballing).
// =======================================================================

/**
 * Tomorrow's forecast. No fabricated "confidence %" -- see module
 * docblock. "Top category"/"busiest hour" are real trailing-7-day
 * performance, not literally forecasted per-category (this app doesn't
 * compute category-level forecasts, only per-item and per-ingredient), so
 * they're labeled accordingly in the view rather than implied as AI output.
 */
function getAiForecastWidgetData(PDO $db): array
{
    $end   = new DateTime('today');
    $start = (clone $end)->modify('-29 days');
    $summary = computeForecastSummary($db, 'daily', $start, $end);

    // computeForecastSummary() returns the CUMULATIVE total across
    // forecast_horizon_days, so the widget must present it as an N-day
    // figure. It previously carried 'forecast_date' => tomorrow and the
    // dashboard rendered "Forecast for <tomorrow>" above a 7-day total --
    // a 7x overstatement of a single day's expected revenue.
    $horizonDays  = max(1, (int)($summary['forecast_horizon_days'] ?? 7));
    $forecastFrom = new DateTime('today');
    $forecastTo   = (clone $forecastFrom)->modify('+' . ($horizonDays - 1) . ' days');

    // Reorder / estimated-spend figures, so this widget shows the same five
    // cards as Demand Forecasting's own Forecast Summary rather than a
    // different subset. Read from the last completed sweep
    // (getPersistedInventoryPolicyReport) -- NOT recomputed. Rebuilding the
    // policy report here would mean one Prophet HTTP call per active
    // ingredient on every dashboard load, the exact ~31s regression already
    // fixed for getPurchaseRecommendationsWidget() below.
    //
    // Unfiltered on purpose: Demand Forecasting scopes these two cards to its
    // active search/supplier filter, but the dashboard has no such filter, so
    // here they are always the whole catalog.
    $policyRows  = getPersistedInventoryPolicyReport($db);
    $triggered   = array_values(array_filter($policyRows, fn($r) => $r['triggered']));
    $covered     = array_values(array_filter(
        $policyRows, fn($r) => !$r['triggered'] && !empty($r['suppressed_by_open_commitment'])
    ));

    $estimatedPurchaseAmount = null;
    $estimatedPurchaseHasGap = false;
    if (!empty($triggered)) {
        $ids  = array_column($triggered, 'item_id');
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $cost = $db->prepare("SELECT item_id, last_purchase_cost FROM inventory_items WHERE item_id IN ({$ph})");
        $cost->execute($ids);
        $costByItemId = array_column($cost->fetchAll(PDO::FETCH_ASSOC), 'last_purchase_cost', 'item_id');

        $estimatedPurchaseAmount = 0.0;
        foreach ($triggered as $rec) {
            if ($rec['suggested_qty'] === null) {
                continue;
            }
            $unitCost = $costByItemId[$rec['item_id']] ?? null;
            if ($unitCost === null) {
                // Never purchased before, so there's no cost basis. Excluded
                // from the total and disclosed, rather than guessed at.
                $estimatedPurchaseHasGap = true;
                continue;
            }
            $estimatedPurchaseAmount += $rec['suggested_qty'] * (float)$unitCost;
        }
    }

    return [
        'horizon_days'    => $horizonDays,
        'forecast_from'   => $forecastFrom,
        'forecast_to'     => $forecastTo,
        'summary'         => $summary,
        // Derived by computeForecastSummary() itself (revenue = orders x AOV),
        // so read it rather than dividing back out and risking a mismatch.
        'avg_order_value' => $summary['avg_order_value'] ?? null,
        'reorder_item_count'        => count($triggered),
        'covered_item_count'        => count($covered),
        'estimated_purchase_amount' => $estimatedPurchaseAmount,
        'estimated_purchase_gap'    => $estimatedPurchaseHasGap,
        'training_from'   => $start,
        'training_to'     => $end,
    ];
}

/** Ingredients currently triggered (projected stock at/under each item's own configured reorder level), most-under-reorder first (buildInventoryPolicyReport() already sorts that way) -- only rows with a real suggested quantity > 0, since "no order needed" rows aren't actionable on a summary widget. Full picture (incl. why a row is/isn't classified) lives on Demand Forecasting. */
function getPurchaseRecommendationsWidget(PDO $db, int $limit = 8): array
{
    // Reads the last completed sweep instead of recomputing. This widget
    // used to call buildInventoryPolicyReport(), i.e. one Prophet HTTP call
    // per active ingredient (~31.5s for 51 items) on every dashboard load,
    // to re-derive numbers the sweep had already persisted.
    $recommendations = getPersistedInventoryPolicyReport($db);

    $needed = array_values(array_filter($recommendations, fn($r) => $r['triggered'] && $r['suggested_qty'] !== null && $r['suggested_qty'] > 0));
    return array_slice($needed, 0, $limit);
}

/**
 * Assembles everything owner/dashboard.php renders. Runs the same
 * sweeps owner/includes/header.php runs *before* reading
 * notifications -- header.php's own sweep call happens later in render
 * order (inside the HTML body, after this function has already run), so
 * without re-running them here the widgets below would read stale data.
 * Each sweep is self-throttled/deduped, so running it twice in one
 * request (once here, once from header.php moments later) is a harmless
 * no-op the second time.
 */
function buildOwnerDashboardData(PDO $db, string $period, ?string $dateFrom, ?string $dateTo): array
{
    try { sweepInventoryStockAlerts($db); } catch (Throwable $e) { error_log('dashboard sweepInventoryStockAlerts failed: ' . $e->getMessage()); }
    try { sweepFeedbackInsights($db); } catch (Throwable $e) { error_log('dashboard sweepFeedbackInsights failed: ' . $e->getMessage()); }

    require_once __DIR__ . '/../../customer/includes/reservation_functions.php'; // sweepExpiredReservationHolds(), sweepNoShowReservations(), getReservationSettings()
    $resSettings = getReservationSettings($db);
    sweepExpiredReservationHolds($db, (int)$resSettings['reservation_hold_minutes']);
    sweepNoShowReservations($db, (int)$resSettings['reservation_no_show_hours']);

    [$rangeStart, $rangeEnd] = resolvePeriodRange($period, $dateFrom, $dateTo);
    [$prevStart, $prevEnd]   = previousPeriodRange($rangeStart, $rangeEnd);

    return [
        'period'      => $period,
        'range_start' => $rangeStart,
        'range_end'   => $rangeEnd,

        'revenue_kpi'         => getRevenueKpi($db, $rangeStart, $rangeEnd, $prevStart, $prevEnd),
        'inventory_attention' => getInventoryStockAttention($db),
        'reservation_kpi'     => getReservationKpi($db, $rangeStart, $rangeEnd),
        'receiving_kpi'       => getReceivingKpi($db, $rangeStart, $rangeEnd),

        'revenue_trend'          => getNetRevenueTrendSeries($db, $rangeStart, $rangeEnd),
        'reservation_trend'      => buildReservationTrend($db, 'day', $rangeStart, $rangeEnd)['volume'],
        'stock_usage_trend'      => getStockUsageTrendSeries($db, $rangeStart, $rangeEnd),
        'wastage_trend'          => getWastageTrendSeries($db, $rangeStart, $rangeEnd),


        'today_reservations' => getTodayReservationsList($db),
        'today_orders'       => getTodayOrdersList($db),
        'draft_pos'          => getDraftPurchaseOrders($db),
        'receiving_pos'      => getReceivingPurchaseOrders($db),

    ];
}
