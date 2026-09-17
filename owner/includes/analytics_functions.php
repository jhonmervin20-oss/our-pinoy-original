<?php
/**
 * owner/includes/analytics_functions.php
 *
 * Backend for the unified Analytics module (owner/analytics.php) -- Sales,
 * Reservations, Inventory, Menu Performance in one page. Consolidates
 * owner/report.php + owner/sales_analytics.php + owner/menu_performance.php
 * (the latter two retired in favor of this module -- see their former
 * *_functions.php files, still required below and reused directly rather
 * than re-derived).
 *
 * Genuinely new logic lives only here: Wastage Cost, Supplier Spend
 * ranking, Inventory Value, Reservation Conversion/Cancellation Rate,
 * Advance Order Adoption, Deposit Collection, Peak Reservation Hours, Time
 * Slot Utilization, Menu Item Revenue/Margin ranking, and the per-tab AI
 * Insights (see the "AI Insights" section for the shared cache/generate
 * runner). Everything else reuses report_functions.php /
 * sales_analytics_functions.php / menu_performance_functions.php /
 * demand_forecast_functions.php / menu_functions.php as-is.
 */

require_once __DIR__ . '/report_functions.php';           // buildReservationSummary(), buildInventoryMovementSummary(), reportPaymentMethodLabel()
require_once __DIR__ . '/sales_analytics_functions.php';  // computeSalesKpiSummary(), salesDeltaMeta(), computeDiscountUsage(), etc. (requires demand_forecast_functions.php + menu_performance_functions.php)
require_once __DIR__ . '/../menu_management/includes/menu_functions.php'; // computeFullCosting(), foodCostHealthClass(), getPricingSettings()
require_once __DIR__ . '/inventory_policy_functions.php'; // demandPatternLabel(), demandPatternBadgeClass()
require_once __DIR__ . '/../../config/openai.php';
require_once __DIR__ . '/../../config/csrf.php'; // csrf_field(), used by analyticsRegenerateFormHtml()

/** Below this many real data points (orders/reservations/transactions/ranked items) in the period, don't bother calling the AI -- a one-shot summary from 1-2 rows is noise, not insight. */
const ANALYTICS_AI_MIN_SAMPLE = 3;

/** How long a cached AI insight stays valid for a given tab+period before a normal (non-forced) view regenerates it. */
const ANALYTICS_AI_CACHE_TTL_HOURS = 6;

// ---------------------------------------------------------------------
// Small shared helpers
// ---------------------------------------------------------------------

/** Formats a database hour (0-23) for owner-facing analytics, e.g. 0 => 12 AM and 13 => 1 PM. */
function formatAnalyticsHour(int $hour): string
{
    if ($hour < 0 || $hour > 23) {
        throw new InvalidArgumentException('Analytics hour must be between 0 and 23.');
    }

    $displayHour = $hour % 12 ?: 12;
    return $displayHour . ($hour < 12 ? ' AM' : ' PM');
}

/**
 * Small, verified set of current operating rules that can make an analytics
 * recommendation more practical. This deliberately excludes customer data,
 * credentials, and implementation details; a configured feature is context,
 * not proof that it was used during the selected reporting period.
 */
function analyticsOperationalContext(PDO $db): array
{
    static $context = null;
    if ($context !== null) {
        return $context;
    }

    $keys = [
        'total_capacity', 'reservation_min_lead_hours', 'reservation_max_advance_days',
        'reservation_hold_minutes', 'reservation_no_show_hours', 'reservation_min_guests',
        'reservation_max_guests', 'reservation_fee_amount', 'advance_order_min_amount',
        'advance_order_deposit_percentage', 'operating_days',
    ];

    try {
        $stmt = $db->prepare(
            'SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('
            . implode(',', array_fill(0, count($keys), '?')) . ')'
        );
        $stmt->execute($keys);
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        error_log('analyticsOperationalContext failed: ' . $e->getMessage());
        return $context = [];
    }

    $weekdayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $operatingDays = array_values(array_filter(array_map(
        fn($day) => $weekdayLabels[(int)$day] ?? null,
        explode(',', (string)($settings['operating_days'] ?? ''))
    )));

    return $context = [
        'online_reservations' => [
            'seating_capacity_pax' => isset($settings['total_capacity']) ? (int)$settings['total_capacity'] : null,
            'guest_count_range' => [
                'minimum' => isset($settings['reservation_min_guests']) ? (int)$settings['reservation_min_guests'] : null,
                'maximum' => isset($settings['reservation_max_guests']) ? (int)$settings['reservation_max_guests'] : null,
            ],
            'minimum_lead_hours' => isset($settings['reservation_min_lead_hours']) ? (int)$settings['reservation_min_lead_hours'] : null,
            'maximum_advance_days' => isset($settings['reservation_max_advance_days']) ? (int)$settings['reservation_max_advance_days'] : null,
            'unpaid_hold_minutes' => isset($settings['reservation_hold_minutes']) ? (int)$settings['reservation_hold_minutes'] : null,
            'no_show_threshold_hours' => isset($settings['reservation_no_show_hours']) ? (int)$settings['reservation_no_show_hours'] : null,
            'reservation_fee_php' => isset($settings['reservation_fee_amount']) ? round((float)$settings['reservation_fee_amount'], 2) : null,
            'operating_days' => $operatingDays,
        ],
        'advance_food_ordering' => [
            // Always on: the Reservation Settings toggle that used to gate this
            // was removed -- advance food ordering is a core feature, not an option.
            'enabled' => true,
            'minimum_order_php' => isset($settings['advance_order_min_amount']) ? round((float)$settings['advance_order_min_amount'], 2) : null,
            'deposit_percentage' => isset($settings['advance_order_deposit_percentage']) ? round((float)$settings['advance_order_deposit_percentage'], 1) : null,
        ],
    ];
}

/** Matches owner/sales_analytics.php's saEmptyState() markup exactly -- kept as its own copy per this app's established per-module convention. */
function anEmptyState(string $icon, string $title, string $message): string
{
    return '<div class="owner-table-empty">'
        . '<i class="ph ' . htmlspecialchars($icon) . '" style="font-size:2rem;color:var(--op-ink-faint);display:block;margin-bottom:10px;" aria-hidden="true"></i>'
        . '<strong style="display:block;margin-bottom:4px;">' . htmlspecialchars($title) . '</strong>'
        . ($message !== '' ? '<span style="color:var(--op-ink-faint);font-size:0.85rem;">' . htmlspecialchars($message) . '</span>' : '')
        . '</div>';
}

/**
 * Pagination footer for a table wrapped in .an-table-pager, whose <table>
 * itself carries data-paginate="N" -- wired up by the one generic JS loop
 * in analytics.php's own script block, same "the next table needs no new
 * code" convention that loop's own docblock already established for charts.
 * Starts hidden; the JS reveals it only once there's more than one page.
 */
function anTablePagerHtml(): string
{
    return '<div class="owner-pagination" hidden>'
        . '<button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" data-page-prev><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>'
        . '<span class="owner-pagination-info" data-page-info>Page 1 of 1</span>'
        . '<button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" data-page-next>Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>'
        . '</div>';
}


/** Cache-bust/lookup key for one tab's AI insight -- just the resolved date range, so "Last 7 Days" today and "Last 7 Days" tomorrow are correctly treated as different periods. */
function analyticsPeriodKey(DateTime $start, DateTime $end): string
{
    return $start->format('Y-m-d') . '_' . $end->format('Y-m-d');
}


/**
 * Turns an export's filter array into something a person can read in the
 * activity feed.
 *
 * These descriptions used to be the raw json_encode() of the filter array, so
 * the Admin dashboard's most prominent widget showed
 * `{"period":"last30","date_from":"2026-08-03",...}` mid-sentence. The filters
 * are worth recording -- they say what the export actually contained -- but as
 * words, not as a serialised PHP array. Empty values are dropped rather than
 * printed as `date_from: ""`.
 */
function describeExportFilters(array $filters): string
{
    $labels = [
        'period'        => 'period',
        'date_from'     => 'from',
        'date_to'       => 'to',
        'department_id' => 'department',
        'position_id'   => 'position',
        'search'        => 'search',
        'status'        => 'status',
        'tab'           => 'tab',
    ];

    $parts = [];
    foreach ($filters as $key => $value) {
        if ($value === null || $value === '' || $value === []) {
            continue;
        }
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        $parts[] = ($labels[$key] ?? $key) . ' ' . $value;
    }

    return $parts ? ' (' . implode(', ', $parts) . ')' : '';
}

/** Export logging, modeled directly on employee_management/includes/performance_monitoring_functions.php's logAttendanceExport() -- the only existing writer of data_export_logs in this app. */
function logAnalyticsExport(PDO $db, int $userId, string $tab, string $format, array $filters, int $rowCount): void
{
    try {
        $db->prepare(
            "INSERT INTO data_export_logs (module, export_format, filters_applied, row_count, exported_by, status)
             VALUES ('Analytics', ?, ?, ?, ?, 'completed')"
        )->execute([$format, json_encode($filters), $rowCount, $userId]);
    } catch (Throwable $e) {
        error_log('logAnalyticsExport (data_export_logs) failed: ' . $e->getMessage());
    }

    try {
        $db->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Analytics', ?, ?, ?)"
        )->execute([
            $userId,
            'Export ' . ucfirst($tab) . ' ' . strtoupper($format),
            "Exported {$rowCount} row(s) from the {$tab} tab" . describeExportFilters($filters),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('logAnalyticsExport (activity_logs) failed: ' . $e->getMessage());
    }
}

// =======================================================================
// Sales tab -- new logic: none. Everything is computeSalesKpiSummary(),
// computeDiscountUsage(), computeOrderTypeRevenueSplit(),
// computePaymentMethodDistributionSnapshot(), computeCategoryRevenueRanked()
// (sales_analytics_functions.php) + buildPeakTimesData(), rollUpByGranularity()
// (menu_performance/demand_forecast_functions.php) + buildFastMovingQuery()
// (demand_forecast_functions.php).
//
// Fast/Slow Moving item lists and the peak-hour/day-of-week BREAKDOWNS are
// still intentionally NOT rendered here -- owner/demand_forecast.php owns those.
//
// The per-category best/worst callouts and the peak day/hour SUMMARY cards did
// move here (see "Sales Highlights" in renderAnalyticsSalesTabHtml): a
// forecasting page was the wrong home for backward-looking sales callouts.
// They live in exactly one place, so the two modules still don't duplicate.
// $topItems and $peakTimes are still computed below purely as AI Insight
// context (salesAiInsight()) -- not for display.
// =======================================================================

// =======================================================================
// TAB DATA BUILDERS
//
// Each tab computes ONLY what the Analytics spec lists for it -- nothing
// more. A tab does not query what it never displays: an analytics page that
// computes figures nobody sees just spends page-load time proving numbers
// to an empty room.
//
// THE SALE DEFINITION used throughout is the system-wide one, and it now lives
// in exactly one place: config/sales_definitions.php's SALE_PREDICATE,
// `payment_status = 'paid' AND order_status <> 'voided'`.
//
// It previously also required order_status='completed', on the reasoning that
// 27 paid-but-open orders were overstating revenue. That reasoning was
// backwards: those orders are REAL SALES taken through the POS, and 'completed'
// is a status this app has never once written, so the filter was quietly
// hiding every genuine sale and showing only seeded demo rows.
// =======================================================================

/**
 * SALES: Revenue, Completed Orders, Average Order Value, Sales Trend,
 * Top-Selling Items, Peak Sales Hours.
 */
function buildSalesTabData(PDO $db, DateTime $start, DateTime $end): array
{
    return [
        'kpi'           => computeSalesKpiSummary($db, $start, $end),
        'revenue_trend' => buildRevenueTrend($db, $start, $end),
        'top_items'     => computeTopSellingItems($db, $start, $end, 10),
        'category_revenue' => computeCategoryRevenueRanked($db, $start, $end),
        'category_best'    => computeBestSellerPerCategory($db, $start, $end),
        'peak_hours'    => buildPeakSalesHours($db, $start, $end),
    ];
}

/**
 * Net revenue (excl. VAT) per calendar day, dense -- the same net_sales_vat_exc
 * figure buildSalesStatement() reports for the whole period (config/
 * sales_definitions.php), broken out by day, so this chart's bars always sum
 * back to the "Net sales (excl. VAT)" KPI card rendered directly above it.
 *
 * Used to plot raw SUM(oi.subtotal) -- gross, pre-discount, VAT-inclusive --
 * under the label "Revenue". That is a real number, but neither KPI card on
 * this same tab means it: for Sept 1-10, 2026 it read P67,340 against a
 * "Net sales" card of P60,108.27 and a "Total billed" card of P67,454 --
 * visibly reconciling to neither. Per-order first, then aggregated by day,
 * for the same fan-out reason buildSalesStatement() computes items_gross
 * that way (a flat query mixing an order_items SUM with an orders-level SUM
 * would multiply discount/VAT by that order's line count).
 */
function buildRevenueTrend(PDO $db, DateTime $start, DateTime $end): array
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

/** Menu items ranked by units sold. Cancelled LINES are excluded, not just cancelled orders -- a voided line never actually sold. */
function computeTopSellingItems(PDO $db, DateTime $start, DateTime $end, int $limit = 10): array
{
    $stmt = $db->prepare(
        "SELECT mi.item_name, mc.category_name,
                SUM(oi.quantity) AS units_sold,
                SUM(oi.subtotal) AS revenue
         FROM order_items oi
         JOIN orders o          ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         JOIN menu_items mi     ON mi.item_id = oi.menu_item_id
         LEFT JOIN menu_categories mc ON mc.category_id = mi.category_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided'
           AND oi.status <> 'cancelled'
           AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
         GROUP BY mi.item_id, mi.item_name, mc.category_name
         ORDER BY units_sold DESC
         LIMIT " . max(1, $limit)
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Completed orders grouped by hour of day, 0-23, every hour present.
 *
 * Hours with no trade are returned as 0 rather than omitted, so the chart
 * shows the real shape of a trading day instead of silently compressing the
 * closed hours out of the axis and implying business is continuous.
 */
function buildPeakSalesHours(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT HOUR(COALESCE(op.paid_at, op.created_at)) AS h,
                COUNT(DISTINCT o.order_id) AS orders,
                SUM(o.total_amount) AS revenue
         FROM orders o
         JOIN order_payments op ON op.order_id = o.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided'
           AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
         GROUP BY h"
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);

    $byHour = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byHour[(int)$r['h']] = ['orders' => (int)$r['orders'], 'revenue' => (float)$r['revenue']];
    }
    $out = [];
    for ($h = 0; $h < 24; $h++) {
        $out[$h] = $byHour[$h] ?? ['orders' => 0, 'revenue' => 0.0];
    }
    return $out;
}

/**
 * RESERVATIONS: Total, Completed, Cancelled, No-Shows, Reservation Trend,
 * Popular Time Slots, Popular Days, No-Show Rate.
 */
function buildReservationsTabData(PDO $db, DateTime $start, DateTime $end): array
{
    return [
        'summary'       => computeReservationStatusSummary($db, $start, $end),
        'trend'         => buildAnalyticsReservationTrend($db, $start, $end),
        'revenue_trend' => buildReservationRevenueTrend($db, $start, $end),
        'popular_slots' => computePopularTimeSlots($db, $start, $end),
        'popular_days'  => computePopularDays($db, $start, $end),
        'no_show_trend' => buildNoShowTrend($db, $start, $end),
        // Built here rather than inside the PDF renderer, which receives only
        // its data array (no PDO, no date range). Shared with the Sales report
        // so both places classify a deposit the same way.
        'deposits'      => buildReservationDepositSummary($db, $start, $end),
    ];
}

/**
 * Counts by reservation status, plus the no-show rate.
 *
 * Pending and cancelled are excluded at the query level, not just from
 * display -- same scope as the staff reservation panel
 * (reservation/reservations.php) and the Reports hub's Reservation tab.
 * Neither ever became a real, revenue-bearing booking (an unpaid hold in
 * progress, or that hold lapsing/being backed out of before payment), so
 * counting them here would inflate "reservation activity" with bookings
 * that were never actually coming.
 *
 * The no-show rate's DENOMINATOR is every tracked reservation that reached
 * a final state (completed + no_show) -- confirmed bookings have not had
 * the opportunity to become a no-show yet, so counting them would drag the
 * rate down purely because future bookings exist.
 */
function computeReservationStatusSummary(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT status, COUNT(*) AS n FROM reservations
         WHERE status IN ('confirmed', 'completed', 'no_show') AND reservation_date BETWEEN ? AND ? GROUP BY status"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $counts = ['confirmed' => 0, 'completed' => 0, 'no_show' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (array_key_exists($r['status'], $counts)) {
            $counts[$r['status']] = (int)$r['n'];
        }
    }

    $total    = array_sum($counts);
    $resolved = $counts['completed'] + $counts['no_show'];

    return $counts + [
        'total'        => $total,
        'resolved'     => $resolved,
        // null, not 0, when nothing has resolved yet -- "no data" and "a 0%
        // no-show rate" are different claims and must not render the same.
        'no_show_rate' => $resolved > 0 ? ($counts['no_show'] / $resolved) * 100 : null,
    ];
}

/** Reservations per calendar day, dense across the range, tracked statuses only (see computeReservationStatusSummary()). Prefixed 'Analytics' because menu_performance_functions.php -- required transitively by this file -- already defines buildReservationTrend(); the shared lib keeps the plain name. */
function buildAnalyticsReservationTrend(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT reservation_date AS d, COUNT(*) AS n FROM reservations
         WHERE status IN ('confirmed', 'completed', 'no_show') AND reservation_date BETWEEN ? AND ? GROUP BY d"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sparse[$r['d']] = (float)$r['n'];
    }
    return denseDailyMap($sparse, $start, $end);
}

/**
 * Reservation-related money actually collected per day, split by what it
 * paid for: the reservation_fee (holds the table) vs an advance order
 * (deposit + any later balance top-up for pre-selected food). Anchored on
 * reservation_date rather than paid_at so it lines up day-for-day with the
 * Reservation trend and No-show trend beside it -- all three charts read as
 * one timeline.
 *
 * Every status is included here, even cancelled/no_show (unlike
 * computeReservationStatusSummary()'s booking counts): a forfeited deposit
 * is still real money the restaurant collected, so excluding it would
 * understate what this chart is actually reporting on.
 *
 * This is money that moved through the reservation system, not a restaurant
 * sale -- an advance-order payment is a deposit toward food the guest is
 * eventually served, and that visit's order is counted separately in the
 * Sales tab. Never add this chart's totals to a Sales tab figure.
 */
function buildReservationRevenueTrend(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT r.reservation_date AS d,
                SUM(CASE WHEN rp.payment_purpose = 'reservation_fee' THEN rp.amount_paid ELSE 0 END) AS fee_amount,
                SUM(CASE WHEN rp.payment_purpose IN ('advance_order_deposit', 'balance_payment') THEN rp.amount_paid ELSE 0 END) AS advance_amount
         FROM reservation_payments rp
         JOIN reservations r ON r.reservation_id = rp.reservation_id
         WHERE rp.payment_status = 'paid' AND r.reservation_date BETWEEN ? AND ?
         GROUP BY d"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $fees = [];
    $advances = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fees[$r['d']]     = (float)$r['fee_amount'];
        $advances[$r['d']] = (float)$r['advance_amount'];
    }
    return [
        'fees'     => denseDailyMap($fees, $start, $end),
        'advances' => denseDailyMap($advances, $start, $end),
    ];
}

/**
 * No-shows per calendar day, dense across the range.
 *
 * Its own query rather than a filter over buildAnalyticsReservationTrend():
 * that one counts every booking regardless of status, and a no-show line has
 * to be able to sit at zero on a day that still took bookings. A gap here is
 * a real zero -- a day with no no-show genuinely had none.
 */
function buildNoShowTrend(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT reservation_date AS d, COUNT(*) AS n FROM reservations
         WHERE status = 'no_show' AND reservation_date BETWEEN ? AND ? GROUP BY d"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sparse[$r['d']] = (float)$r['n'];
    }
    return denseDailyMap($sparse, $start, $end);
}

/**
 * Reservations grouped by time slot, tracked statuses only (see
 * computeReservationStatusSummary()). Every ACTIVE slot appears, including
 * ones nobody booked -- "nobody books 10am" is a finding, not a row to hide.
 *
 * Also carries no_shows/resolved per slot, not just the booking count -- the
 * chart itself only plots bookings, but the AI insight and this table's own
 * tooltip both need to say WHICH slot concentrates the no-shows, and that
 * needs a real per-slot split rather than the flat overall rate applied
 * uniformly.
 */
function computePopularTimeSlots(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT ts.slot_id, ts.slot_label, ts.start_time,
                COUNT(r.reservation_id) AS n,
                COALESCE(SUM(r.number_of_guests), 0) AS guests,
                COUNT(CASE WHEN r.status = 'no_show' THEN 1 END) AS no_shows,
                COUNT(CASE WHEN r.status IN ('completed', 'no_show') THEN 1 END) AS resolved
         FROM time_slots ts
         LEFT JOIN reservations r
                ON r.slot_id = ts.slot_id
               AND r.status IN ('confirmed', 'completed', 'no_show')
               AND r.reservation_date BETWEEN ? AND ?
         WHERE ts.is_active = 1
         GROUP BY ts.slot_id, ts.slot_label, ts.start_time
         ORDER BY ts.start_time"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Reservations grouped by day of week, tracked statuses only (see computeReservationStatusSummary()), Monday-first, all seven present. Also carries no_shows/resolved per day, same reason as computePopularTimeSlots(). */
function computePopularDays(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT DAYOFWEEK(reservation_date) AS dow, COUNT(*) AS n,
                COUNT(CASE WHEN status = 'no_show' THEN 1 END) AS no_shows,
                COUNT(CASE WHEN status IN ('completed', 'no_show') THEN 1 END) AS resolved
         FROM reservations WHERE status IN ('confirmed', 'completed', 'no_show') AND reservation_date BETWEEN ? AND ? GROUP BY dow"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    // MySQL DAYOFWEEK(): 1=Sunday..7=Saturday. Presented Monday-first.
    $byDow = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byDow[(int)$r['dow']] = $r;
    }
    $order = [2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday', 1 => 'Sunday'];
    $out = [];
    foreach ($order as $dow => $label) {
        $row = $byDow[$dow] ?? null;
        $out[] = [
            'day'      => $label,
            'n'        => (int)($row['n'] ?? 0),
            'no_shows' => (int)($row['no_shows'] ?? 0),
            'resolved' => (int)($row['resolved'] ?? 0),
        ];
    }
    return $out;
}

/** Point-in-time snapshot (current stock x FIFO unit cost), not period-filtered -- "value" has no meaningful date range, same as inventory_stock_status. */
function computeInventoryValue(PDO $db): float
{
    return (float)($db->query("SELECT COALESCE(SUM(quantity_remaining * unit_cost), 0) FROM inventory_batches")->fetchColumn() ?: 0);
}

/** Purchase-order spend per day. Currently called by nothing -- kept because it is a small, correct helper and the PDF/report path may still want it. (The owner dashboard used to carry its own copy of this, but its Purchase Trend chart was replaced by Waste Trend on 2026-08-20 and that copy is gone.) */
function buildPurchaseTrend(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT order_date AS d, SUM(total_amount) AS amt FROM purchase_orders
         WHERE status IN ('ordered','partially_received','received') AND order_date BETWEEN ? AND ?
         GROUP BY d"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sparse[$row['d']] = (float)$row['amt'];
    }
    return denseDailyMap($sparse, $start, $end);
}

/**
 * INVENTORY: Current Stock, Low-Stock Items, Stock Usage Trend,
 * Most Consumed Ingredients, Stockout Events, Inventory Value.
 */
function buildInventoryTabData(PDO $db, DateTime $start, DateTime $end): array
{
    return [
        'stock_summary'  => computeStockSummary($db),
        'inventory_value' => computeInventoryValue($db),
        'low_stock'      => computeLowStockItems($db),
        'low_stock_count' => countLowStockItems($db),
        'usage_trend'    => buildStockUsageTrend($db, $start, $end),
        'most_consumed'  => computeMostConsumedIngredients($db, $start, $end, 10),
        'movement_types' => computeMovementTypeBreakdown($db),
        'stockouts'      => computeCurrentStockouts($db),
        'wastage_trend'     => buildWastageTrend($db, $start, $end),
        'wastage_by_reason' => computeWastageByReason($db, $start, $end),
    ];
}

/** Headline counts from the live inventory_stock_status view. */
function computeStockSummary(PDO $db): array
{
    $rows = $db->query(
        "SELECT ss.stock_status, COUNT(*) AS n
         FROM inventory_stock_status ss
         JOIN inventory_items ii ON ii.item_id = ss.item_id
         WHERE ii.is_active = 1
         GROUP BY ss.stock_status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $total = (int)$db->query("SELECT COUNT(*) FROM inventory_items WHERE is_active = 1")->fetchColumn();

    return [
        'total_items' => $total,
        'ok'          => (int)($rows['ok'] ?? 0),
        'low'         => (int)($rows['low'] ?? 0),
        'critical'    => (int)($rows['critical'] ?? 0),
        'out'         => (int)($rows['out'] ?? 0),
    ];
}

/** Items at or under their reorder level, worst first. */
function computeLowStockItems(PDO $db, int $limit = 15): array
{
    $stmt = $db->prepare(
        "SELECT ii.item_name, u.unit_code, ss.current_stock, ss.stock_status,
                ii.reorder_level, ii.critical_level
         FROM inventory_stock_status ss
         JOIN inventory_items ii ON ii.item_id = ss.item_id
         LEFT JOIN unit_of_measures u ON u.unit_id = ii.base_unit_id
         WHERE ii.is_active = 1
           AND ii.reorder_level IS NOT NULL
           AND ss.current_stock <= ii.reorder_level
         ORDER BY (ss.current_stock - ii.reorder_level) ASC
         LIMIT " . max(1, $limit)
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every active item's movement classification -- fast / medium / slow mover
 * -- straight from inventory_items.demand_pattern, the same column the
 * ingredient-policy nightly sweep (inventory_policy_functions.php) already
 * writes for reorder-point math. Not recomputed here, and not scoped to
 * $start/$end: it is the sweep's current, ongoing read on each item's
 * consumption pattern, refreshed daily regardless of what period this report
 * is viewing -- same "sweep computes, page displays" reasoning as
 * getPersistedInventoryPolicyReport()'s own docblock. Sorted slow-first: an
 * owner opening this card is almost always here to spot what to act on, not
 * to admire what is already fast-moving.
 *
 * $slowReason is intentionally not looked up (see the persisted-report
 * function's own docblock for why) -- demandPatternLabel() falls back to a
 * plain "Slow mover" rather than distinguishing a genuinely slow seller from
 * one still too new to have a trustworthy pattern, matching the one other
 * place in the app that already shows this label today.
 */
function computeMovementTypeBreakdown(PDO $db): array
{
    $stmt = $db->query(
        "SELECT ii.item_id, ii.item_name, ic.category_name, ii.demand_pattern
         FROM inventory_items ii
         LEFT JOIN inventory_categories ic ON ic.category_id = ii.category_id
         WHERE ii.is_active = 1
         ORDER BY FIELD(ii.demand_pattern, 'slow', 'medium', 'fast'), ii.item_name"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['fast' => 0, 'medium' => 0, 'slow' => 0, 'unclassified' => 0];
    foreach ($rows as $r) {
        $counts[$r['demand_pattern'] ?? 'unclassified']++;
    }

    return ['items' => $rows, 'counts' => $counts];
}

/**
 * How many items are at or under their reorder level, UNCAPPED.
 *
 * Deliberately its own query rather than count($low_stock): that list is
 * LIMITed for display, so counting it reported the cap (15) as though it
 * were the real total whenever more items than that were below reorder --
 * which read as "15 items need attention" while 29 actually did. The WHERE
 * clause here is a verbatim copy of computeLowStockItems()'s, so the KPI
 * and the table underneath it can never describe different sets.
 */
function countLowStockItems(PDO $db): int
{
    return (int)$db->query(
        "SELECT COUNT(*)
         FROM inventory_stock_status ss
         JOIN inventory_items ii ON ii.item_id = ss.item_id
         WHERE ii.is_active = 1
           AND ii.reorder_level IS NOT NULL
           AND ss.current_stock <= ii.reorder_level"
    )->fetchColumn();
}

/**
 * Consumption per day across the range.
 *
 * DATED BY THE LINKED ORDER, NOT BY created_at. inventory_transactions was
 * backfilled: 15,761 of 16,216 stock_out rows carry a created_at of
 * 2026-08-05, the day the backfill ran. Charting by created_at produces one
 * enormous spike and a flat line either side -- an artefact of when the rows
 * were written, not of when anything was eaten.
 *
 * 16,139 of those rows reference an order_item, so the true consumption date
 * is recoverable from the order's payment. The ~66 packaging_deduction rows
 * have no order line and are therefore absent from this trend -- excluded
 * deliberately rather than dated wrongly.
 */
/**
 * Stock consumption per calendar day, valued in PESOS -- never in quantity.
 *
 * Ingredients are drawn from batches in a genuine mix of units (kg, L, pcs
 * all appear on any given day), so summing raw `quantity` adds kilograms to
 * pieces to litres -- a number with no real unit, which is exactly why this
 * chart had no honest unit label to give it. Cost is the one common
 * denominator, same reasoning and same batch-unit_cost valuation as
 * buildWastageTrend() uses just below.
 */
function buildStockUsageTrend(PDO $db, DateTime $start, DateTime $end): array
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
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sparse[$r['d']] = (float)$r['cost'];
    }
    return denseDailyMap($sparse, $start, $end);
}

/**
 * Wastage per calendar day, valued in PESOS -- never in quantity.
 *
 * Quantity cannot be summed across items here: wastage_records mixes units
 * (a 300 kg row and a 20 pcs row are both just "quantity"), so a quantity
 * total would be an arithmetic fiction. Cost is the only common denominator,
 * and it is the figure an owner actually decides on. Valued at the wasted
 * batch's own unit_cost via batch_id, so a batch bought cheap is not written
 * off at today's price.
 */
function buildWastageTrend(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT w.wastage_date AS d, SUM(w.quantity * b.unit_cost) AS cost
         FROM wastage_records w
         JOIN inventory_batches b ON b.batch_id = w.batch_id
         WHERE w.wastage_date BETWEEN ? AND ? GROUP BY d"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sparse[$r['d']] = (float)$r['cost'];
    }
    return denseDailyMap($sparse, $start, $end);
}

/** Wastage cost grouped by reason, biggest first. Cost, not quantity, for the same mixed-unit reason as buildWastageTrend(). */
function computeWastageByReason(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT w.reason, SUM(w.quantity * b.unit_cost) AS cost, COUNT(*) AS n
         FROM wastage_records w
         JOIN inventory_batches b ON b.batch_id = w.batch_id
         WHERE w.wastage_date BETWEEN ? AND ? GROUP BY w.reason ORDER BY cost DESC"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Display label for a wastage reason.
 *
 * A local copy of inventory/inventory_wastage.php's WASTAGE_REASON_LABELS
 * rather than a shared include, matching this app's per-module convention --
 * and deliberately a function, not a same-named const, so requiring both
 * files together can never collide. See the cross-module collision note in
 * this module's history.
 */
function anWastageReasonLabel(string $reason): string
{
    return [
        'expired'            => 'Expired',
        'spoiled'            => 'Spoiled',
        'burned'             => 'Burned',
        'overcooked'         => 'Overcooked',
        'dropped'            => 'Dropped',
        'customer_complaint' => 'Customer complaint',
        'quality_issue'      => 'Quality issue',
        'other'              => 'Other',
    ][$reason] ?? ucfirst(str_replace('_', ' ', $reason));
}

/** Ingredients ranked by quantity consumed, dated the same way as the usage trend. */
function computeMostConsumedIngredients(PDO $db, DateTime $start, DateTime $end, int $limit = 10): array
{
    $stmt = $db->prepare(
        "SELECT ii.item_name, u.unit_code, SUM(it.quantity) AS qty
         FROM inventory_transactions it
         JOIN order_items oi    ON oi.order_item_id = it.reference_id
         JOIN orders o          ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         JOIN inventory_items ii ON ii.item_id = it.item_id
         LEFT JOIN unit_of_measures u ON u.unit_id = ii.base_unit_id
         WHERE it.transaction_type = 'stock_out'
           AND it.reference_type = 'order_item'
           AND op.payment_status = 'paid' AND o.order_status <> 'voided'
           AND COALESCE(op.paid_at, o.created_at) BETWEEN ? AND ?
         GROUP BY ii.item_id, ii.item_name, u.unit_code
         ORDER BY qty DESC
         LIMIT " . max(1, $limit)
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * CURRENT stockouts only -- items sitting at or below zero right now.
 *
 * HISTORICAL stockout events are NOT available and must not be implied. There
 * is no stock-level snapshot table anywhere in this schema:
 * inventory_stock_status is a live view with no history, so "item X ran out on
 * date Y" cannot be established without replaying every batch movement, and
 * the ledger's backfill makes that replay unreliable. Returning current
 * stockouts is the honest subset of what the tree asks for; inventing dated
 * events from a present-day quantity would be fabrication.
 */
function computeCurrentStockouts(PDO $db): array
{
    return $db->query(
        "SELECT ii.item_name, u.unit_code, ss.current_stock
         FROM inventory_stock_status ss
         JOIN inventory_items ii ON ii.item_id = ss.item_id
         LEFT JOIN unit_of_measures u ON u.unit_id = ii.base_unit_id
         WHERE ii.is_active = 1 AND ss.current_stock <= 0
         ORDER BY ii.item_name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// =======================================================================
// TAB RENDERERS
//
// Each renderer emits exactly the items the Analytics tree lists for its tab
// and nothing else. Charts are declared as JSON in a data-chart attribute and
// wired up by one loop in analytics.php, so a chart can be added or removed
// here without touching the page's script block.
//
// Every "no data" path renders anEmptyState() rather than an empty chart: a
// blank axis reads as "zero business", which is a different claim from "we
// have nothing recorded for this period".
// =======================================================================

/** One KPI tile. $meta is trusted HTML (delta chips); $label/$value are escaped. */
function anKpiCard(string $label, string $value, string $meta = '', string $icon = 'ph-chart-bar'): string
{
    return '<div class="owner-summary-card"><div>'
        . '<div class="owner-summary-card-label">' . htmlspecialchars($label) . '</div>'
        . '<div class="owner-summary-card-value">' . $value . '</div>'
        . ($meta !== '' ? '<div class="df-kpi-meta">' . $meta . '</div>' : '')
        . '</div><i class="ph ' . htmlspecialchars($icon) . '" style="font-size:1.6rem;color:var(--op-gold);" aria-hidden="true"></i></div>';
}

/**
 * A titled card wrapping a canvas the page's chart loop will populate.
 *
 * $format is an optional value-formatting hook ('peso', 'peso_share',
 * 'count_share') read by that loop. It exists because Chart.js tick and
 * tooltip formatters are FUNCTIONS, and this module deliberately ships chart
 * configs as JSON in a data-chart attribute -- functions do not survive
 * json_encode/JSON.parse. Naming the format instead keeps the "renderers own
 * their charts, the loop is generic" split intact: a new chart declares what
 * its numbers are, not how to draw them.
 *
 * $tall widens the chart's box for donuts, which need the height their legend
 * takes up back.
 */
function anChartCard(string $title, string $subtitle, string $canvasId, array $config, string $format = '', bool $tall = false, array $notes = []): string
{
    return '<div class="owner-card"><div class="owner-card-head"><div>'
        . '<h2 class="owner-card-title">' . htmlspecialchars($title) . '</h2>'
        . ($subtitle !== '' ? '<span class="owner-card-subtitle">' . htmlspecialchars($subtitle) . '</span>' : '')
        . '</div></div><div class="an-chart-wrap' . ($tall ? ' an-chart-wrap-tall' : '') . '"><canvas id="' . htmlspecialchars($canvasId) . '" '
        . ($format !== '' ? 'data-chart-format="' . htmlspecialchars($format) . '" ' : '')
        . (!empty($notes) ? "data-chart-notes='" . htmlspecialchars(json_encode(array_values($notes)), ENT_QUOTES) . "' " : '')
        . "data-chart='" . htmlspecialchars(json_encode($config), ENT_QUOTES) . "'></canvas></div></div>";
}

const AN_GOLD = '#9c7734';
const AN_BLUE = '#3f7ea6';

/**
 * Loss/leakage series colour -- wasted stock, no-shows. One meaning, one hue,
 * across every tab, so "red line = money walking out" needs learning once.
 * This is the panel's own --op-danger, and it clears 3:1 on the card surface,
 * which the softer status oranges do not.
 */
const AN_LOSS = '#cf5a44';

/**
 * Categorical slots for wastage reasons, in a FIXED order -- slot per reason,
 * never per rank, so filtering to a shorter period cannot repaint the reasons
 * that survive. Validated as a set on the light card surface: lightness band,
 * chroma floor, adjacent CVD separation (worst 9.1 protan) and normal-vision
 * separation (worst 19.6) all pass. Three of the eight sit under 3:1 contrast,
 * which is why every chart drawn from this map ships a visible legend rather
 * than relying on hue alone.
 */
const AN_WASTE_REASON_COLORS = [
    'expired'            => '#2a78d6',
    'spoiled'            => '#eb6834',
    'burned'             => '#1baf7a',
    'overcooked'         => '#eda100',
    'dropped'            => '#e87ba4',
    'customer_complaint' => '#008300',
    'quality_issue'      => '#4a3aa7',
    'other'              => '#e34948',
];

/**
 * Reservation status colours. These are STATUS, not identity: the three
 * resolved outcomes carry the reserved good/serious/critical meanings, and
 * the in-flight states take neutral hues so they never read as a verdict.
 * Fixed per status for the same no-repaint-on-filter reason as above, and
 * always rendered with a labelled legend -- colour never carries the meaning
 * on its own.
 */
const AN_RES_STATUS_COLORS = [
    'pending'   => '#fab219',
    'confirmed' => '#2a78d6',
    'seated'    => '#4a3aa7',
    'completed' => '#0ca30c',
    'cancelled' => '#ec835a',
    'no_show'   => '#d03b3b',
];

const AN_RES_STATUS_LABELS = [
    'pending'   => 'Pending',
    'confirmed' => 'Confirmed',
    'seated'    => 'Seated',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show'   => 'No-show',
];

function renderAnalyticsSalesTabHtml(array $d, string $period, DateTime $start, DateTime $end): string
{
    $k = $d['kpi'];

    // "Completed orders" was a misnomer inherited from the old
    // order_status='completed' filter -- it counted seeded rows and excluded
    // every real POS sale. These are simply PAID orders.
    $html = '<div class="owner-form-grid">'
        . anKpiCard('Net sales (excl. VAT)', '&#8369;' . number_format($k['net_sales_vat_exc'], 2), 'after discounts and VAT', 'ph-currency-circle-dollar')
        . anKpiCard('Total billed', '&#8369;' . number_format($k['total_collected'], 2), 'incl. VAT and fees', 'ph-cash-register')
        . anKpiCard('Paid orders', number_format($k['total_orders']), '', 'ph-receipt')
        . anKpiCard('Average order value', '&#8369;' . number_format($k['aov'], 2), '', 'ph-scales')
        . '</div>';

    // -- Sales trend ----------------------------------------------------
    $trend = $d['revenue_trend'];
    if (array_sum($trend) <= 0) {
        $html .= '<div class="owner-card">' . anEmptyState('ph-chart-line', 'No sales in this period.', 'Pick a wider date range to see the trend.') . '</div>';
    } else {
        $html .= anChartCard('Sales trend', 'Revenue per day', 'anSalesTrend', [
            'type' => 'line',
            'data' => [
                'labels'   => array_keys($trend),
                'datasets' => [[
                    'label' => 'Revenue', 'data' => array_values($trend),
                    'borderColor' => AN_GOLD, 'backgroundColor' => 'rgba(156,119,52,0.10)',
                    'fill' => true, 'tension' => 0.3, 'pointRadius' => 2,
                ]],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => false]],
                'scales'  => ['y' => ['beginAtZero' => true], 'x' => ['ticks' => ['maxTicksLimit' => 12, 'maxRotation' => 0]]],
            ],
        ]);
    }

    // -- Top items + peak hours side by side -----------------------------
    $html .= '<div class="an-two-col">';

    $top = $d['top_items'];
    $html .= '<div class="owner-card"><div class="owner-card-head"><div>'
        . '<h2 class="owner-card-title">Top-selling items</h2>'
        . '<span class="owner-card-subtitle">Ranked by units sold</span></div></div>';
    if (empty($top)) {
        $html .= anEmptyState('ph-bowl-food', 'No items sold in this period.', '');
    } else {
        // "Gross revenue", not "Revenue" -- this is SUM(oi.subtotal) per item,
        // pre-discount and VAT-inclusive. Order-level discounts have no
        // per-line allocation in this schema, so an item's true net share
        // can't be computed; labeling it plainly avoids it being read as
        // reconciling with the tab's "Net sales" KPI card.
        // Not paginated like this tab's other tables -- this one is paired
        // against a two-chart stack (Peak sales hours + Sales by category),
        // not a same-shaped sibling, so capping it to a handful of rows just
        // left a large dead gap under the pager to match the taller stack.
        // Showing the full ranked list fills that space with real content.
        $html .= '<div style="overflow-x:auto;"><table class="owner-table"><thead><tr>'
            . '<th>#</th><th>Item</th><th>Units</th><th>Gross revenue</th></tr></thead><tbody>';
        foreach ($top as $i => $r) {
            $html .= '<tr><td>' . ($i + 1) . '</td>'
                . '<td><strong>' . htmlspecialchars($r['item_name']) . '</strong>'
                . '<div style="font-size:0.72rem;color:var(--op-ink-faint);">' . htmlspecialchars($r['category_name'] ?? '') . '</div></td>'
                . '<td>' . number_format((float)$r['units_sold']) . '</td>'
                . '<td>&#8369;' . number_format((float)$r['revenue'], 2) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';

    // Right column stacks two cards. Appending a third child to .an-two-col
    // would drop it under the tall items table instead of into the gap beside
    // it, which is the empty space this fills.
    $html .= '<div class="an-stack">';

    $hours = $d['peak_hours'];
    $totalOrders = array_sum(array_column($hours, 'orders'));
    if ($totalOrders <= 0) {
        $html .= '<div class="owner-card">' . anEmptyState('ph-clock', 'No orders in this period.', '') . '</div>';
    } else {
        $busiest = null;
        $busiestOrders = -1;
        foreach ($hours as $hour => $row) {
            if ((int)$row['orders'] > $busiestOrders) {
                $busiest = (int)$hour;
                $busiestOrders = (int)$row['orders'];
            }
        }
        $labels = [];
        foreach (array_keys($hours) as $h) {
            $labels[] = formatAnalyticsHour((int)$h);
        }
        $html .= anChartCard('Peak sales hours',
            $busiest !== null ? 'Busiest hour: ' . formatAnalyticsHour((int)$busiest) : 'Orders by hour of day',
            'anPeakHours', [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Orders', 'data' => array_values(array_column($hours, 'orders')),
                    'backgroundColor' => AN_GOLD, 'borderRadius' => 3,
                ]],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => false]],
                'scales'  => ['y' => ['beginAtZero' => true], 'x' => ['ticks' => ['maxTicksLimit' => 12, 'maxRotation' => 0]]],
            ],
        ]);
    }

    // -- Sales by category ------------------------------------------------
    // One hue for every bar: categories are nominal, so shading them by size
    // would double-encode bar length as colour and say nothing new.
    $cats = $d['category_revenue'];
    if (empty($cats)) {
        $html .= '<div class="owner-card">' . anEmptyState('ph-squares-four', 'No category revenue in this period.', '') . '</div>';
    } else {
        $best  = $d['category_best'];
        $notes = [];
        foreach ($cats as $row) {
            $name = $row['category_name'] ?? 'Uncategorized';
            $line = number_format((float)$row['qty']) . ' units sold';
            if (isset($best[$name])) {
                $line .= ' | Best seller: ' . $best[$name]['item_name'] . ' (' . number_format($best[$name]['qty']) . ')';
            }
            $notes[] = $line;
        }
        $html .= anChartCard('Sales by category', 'Gross revenue per menu category (pre-discount)', 'anSalesCategory', [
            'type' => 'bar',
            'data' => [
                'labels'   => array_map(fn($r) => $r['category_name'] ?? 'Uncategorized', $cats),
                'datasets' => [[
                    'label' => 'Gross revenue', 'data' => array_map(fn($r) => round((float)$r['revenue'], 2), $cats),
                    'backgroundColor' => AN_GOLD, 'borderRadius' => 4,
                ]],
            ],
            'options' => [
                'indexAxis' => 'y',
                'responsive' => true, 'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => false]],
                'scales'  => ['x' => ['beginAtZero' => true]],
            ],
        ], 'peso_share', false, $notes);
    }

    $html .= '</div>';

    $html .= '</div>';
    return $html;
}

function renderAnalyticsReservationsTabHtml(array $d, string $period, DateTime $start, DateTime $end): string
{
    $s = $d['summary'];

    $noShowText = $s['no_show_rate'] === null
        ? '&mdash;'
        : number_format($s['no_show_rate'], 1) . '%';

    $html = '<div class="owner-form-grid">'
        . anKpiCard('Total reservations', number_format($s['total']), '', 'ph-calendar-check')
        . anKpiCard('Confirmed', number_format($s['confirmed']), 'Paid, awaiting arrival', 'ph-clock-countdown')
        . anKpiCard('Completed', number_format($s['completed']), '', 'ph-check-circle')
        . anKpiCard('No-shows', number_format($s['no_show']), '', 'ph-user-minus')
        . anKpiCard('No-show rate', $noShowText,
            $s['no_show_rate'] === null
                ? 'No resolved bookings yet'
                : 'of ' . number_format($s['resolved']) . ' resolved bookings',
            'ph-percent')
        . '</div>';

    // -- Reservation trend + Reservation revenue trend, side by side -------
    $html .= '<div class="an-two-col">';

    $trend = $d['trend'];
    if (array_sum($trend) <= 0) {
        $html .= '<div class="owner-card">' . anEmptyState('ph-calendar-x', 'No reservations in this period.', '') . '</div>';
    } else {
        $html .= anChartCard('Reservation trend', 'Bookings per day', 'anResTrend', [
            'type' => 'line',
            'data' => [
                'labels'   => array_keys($trend),
                'datasets' => [[
                    'label' => 'Reservations', 'data' => array_values($trend),
                    'borderColor' => AN_BLUE, 'backgroundColor' => 'rgba(63,126,166,0.10)',
                    'fill' => true, 'tension' => 0.3, 'pointRadius' => 2,
                ]],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => false]],
                'scales'  => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]], 'x' => ['ticks' => ['maxTicksLimit' => 12, 'maxRotation' => 0]]],
            ],
        ]);
    }

    // Reservation fees vs advance-order payments, stacked so the two funding
    // sources reconcile visually into one collected-per-day total -- see
    // buildReservationRevenueTrend()'s docblock for why this is scoped to
    // reservation-system money only, not restaurant sales.
    $revenue = $d['revenue_trend'];
    $feeTotal = array_sum($revenue['fees']);
    $advTotal = array_sum($revenue['advances']);
    if ($feeTotal + $advTotal <= 0) {
        $html .= '<div class="owner-card"><div class="owner-card-head"><div>'
            . '<h2 class="owner-card-title">Reservation revenue trend</h2>'
            . '<span class="owner-card-subtitle">Reservation fees vs advance orders collected per day</span></div></div>'
            . anEmptyState('ph-coins', 'No reservation payments collected in this period.', '')
            . '</div>';
    } else {
        $html .= anChartCard('Reservation revenue trend', 'Reservation fees vs advance orders collected per day', 'anResRevenueTrend', [
            'type' => 'bar',
            'data' => [
                'labels'   => array_keys($revenue['fees']),
                'datasets' => [
                    [
                        'label' => 'Reservation fees', 'data' => array_map(fn($v) => round($v, 2), array_values($revenue['fees'])),
                        'backgroundColor' => AN_GOLD, 'borderRadius' => 3, 'stack' => 'rev',
                    ],
                    [
                        'label' => 'Advance orders', 'data' => array_map(fn($v) => round($v, 2), array_values($revenue['advances'])),
                        'backgroundColor' => AN_BLUE, 'borderRadius' => 3, 'stack' => 'rev',
                    ],
                ],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => true, 'position' => 'bottom',
                    'labels' => ['usePointStyle' => true, 'pointStyle' => 'circle', 'boxWidth' => 8, 'padding' => 10]]],
                'scales'  => [
                    'y' => ['beginAtZero' => true, 'stacked' => true],
                    'x' => ['stacked' => true, 'ticks' => ['maxTicksLimit' => 12, 'maxRotation' => 0]],
                ],
            ],
        ], 'peso');
    }

    $html .= '</div>';

    // -- Popular slots + popular days ------------------------------------
    // Both bars only plot the booking count, but a no-show concentration by
    // slot/day is exactly the kind of thing an owner (and the AI insight
    // below) needs to be able to point at -- carried in the tooltip's
    // afterLabel rather than a second series, same pattern the Sales tab's
    // category chart already uses for its best-seller note.
    $noShowNote = function (array $r): string {
        $resolved = (int)($r['resolved'] ?? 0);
        $noShows  = (int)($r['no_shows'] ?? 0);
        if ($resolved === 0) {
            return 'No resolved bookings yet';
        }
        if ($noShows === 0) {
            return 'No no-shows';
        }
        return $noShows . ' no-show' . ($noShows === 1 ? '' : 's') . ' (' . number_format(($noShows / $resolved) * 100, 1) . '% of ' . number_format($resolved) . ' resolved)';
    };

    $html .= '<div class="an-two-col">';

    $slots = $d['popular_slots'];
    $html .= anChartCard('Popular time slots', 'Bookings per slot', 'anResSlots', [
        'type' => 'bar',
        'data' => [
            'labels'   => array_map(fn($r) => $r['slot_label'], $slots),
            'datasets' => [[
                'label' => 'Reservations', 'data' => array_map(fn($r) => (int)$r['n'], $slots),
                'backgroundColor' => AN_BLUE, 'borderRadius' => 3,
            ]],
        ],
        'options' => [
            'indexAxis' => 'y',
            'responsive' => true, 'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
            'scales'  => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ],
    ], '', false, array_map($noShowNote, $slots));

    $days = $d['popular_days'];
    $html .= anChartCard('Popular days', 'Bookings by day of week', 'anResDays', [
        'type' => 'bar',
        'data' => [
            'labels'   => array_map(fn($r) => substr($r['day'], 0, 3), $days),
            'datasets' => [[
                'label' => 'Reservations', 'data' => array_map(fn($r) => (int)$r['n'], $days),
                'backgroundColor' => AN_GOLD, 'borderRadius' => 3,
            ]],
        ],
        'options' => [
            'responsive' => true, 'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
            'scales'  => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ],
    ], '', false, array_map($noShowNote, $days));

    $html .= '</div>';

    // -- No-show trend + status mix ---------------------------------------
    $html .= '<div class="an-two-col">';

    $nsTrend = $d['no_show_trend'];
    if (array_sum($nsTrend) <= 0) {
        $html .= '<div class="owner-card"><div class="owner-card-head"><div>'
            . '<h2 class="owner-card-title">No-show trend</h2>'
            . '<span class="owner-card-subtitle">No-shows per day</span></div></div>'
            . anEmptyState('ph-user-check', 'No no-shows in this period.', 'Every booking that resolved was honoured or cancelled.')
            . '</div>';
    } else {
        $html .= anChartCard('No-show trend', 'No-shows per day', 'anResNoShowTrend', [
            'type' => 'line',
            'data' => [
                'labels'   => array_keys($nsTrend),
                'datasets' => [[
                    'label' => 'No-shows', 'data' => array_values($nsTrend),
                    'borderColor' => AN_LOSS, 'backgroundColor' => 'rgba(207,90,68,0.10)',
                    'fill' => true, 'tension' => 0.3, 'pointRadius' => 2, 'borderWidth' => 2,
                ]],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => false]],
                'scales'  => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]], 'x' => ['ticks' => ['maxTicksLimit' => 12, 'maxRotation' => 0]]],
            ],
        ]);
    }

    // Only statuses that actually occurred get a slice -- a 0% wedge is
    // invisible anyway and only lengthens the legend. Colour is keyed to the
    // status, never to its size, so a quieter period cannot repaint them.
    $slice = [];
    foreach (AN_RES_STATUS_COLORS as $key => $colour) {
        if ((int)($s[$key] ?? 0) > 0) {
            $slice[$key] = (int)$s[$key];
        }
    }
    if (empty($slice)) {
        $html .= '<div class="owner-card"><div class="owner-card-head"><div>'
            . '<h2 class="owner-card-title">Reservations by status</h2>'
            . '<span class="owner-card-subtitle">Share of all bookings</span></div></div>'
            . anEmptyState('ph-chart-donut', 'No reservations in this period.', '')
            . '</div>';
    } else {
        $html .= anChartCard('Reservations by status', 'Share of all ' . number_format($s['total']) . ' bookings', 'anResStatusMix', [
            'type' => 'doughnut',
            'data' => [
                'labels'   => array_map(fn($k) => AN_RES_STATUS_LABELS[$k], array_keys($slice)),
                'datasets' => [[
                    'data' => array_values($slice),
                    'backgroundColor' => array_map(fn($k) => AN_RES_STATUS_COLORS[$k], array_keys($slice)),
                    'borderColor' => '#ffffff', 'borderWidth' => 2,
                ]],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false, 'cutout' => '58%',
                'plugins' => ['legend' => ['display' => true, 'position' => 'right',
                    'labels' => ['usePointStyle' => true, 'pointStyle' => 'circle', 'boxWidth' => 8, 'padding' => 12]]],
            ],
        ], 'count_share', true);
    }

    $html .= '</div>';
    return $html;
}

function renderAnalyticsInventoryTabHtml(array $d, string $period, DateTime $start, DateTime $end): string
{
    $s = $d['stock_summary'];
    $lowCount = (int)$d['low_stock_count'];
    $outCount = count($d['stockouts']);

    $html = '<div class="owner-form-grid">'
        . anKpiCard('Current stock items', number_format($s['total_items']), 'Active inventory items', 'ph-package')
        . anKpiCard('Low-stock items', number_format($lowCount), 'At or under reorder level', 'ph-warning')
        . anKpiCard('Stockout items', number_format($outCount),
            $outCount === 0 ? 'Nothing is out of stock' : 'Currently at zero', 'ph-prohibit')
        . anKpiCard('Inventory value', '&#8369;' . number_format($d['inventory_value'], 2), 'Stock on hand at cost', 'ph-vault')
        . '</div>';

    // -- Stock usage trend -----------------------------------------------
    // Valued in pesos, not quantity -- see buildStockUsageTrend()'s docblock:
    // a day's consumption mixes kg/L/pcs across items, so a quantity total
    // has no real unit to label it with. Same 'peso' format as Waste trend
    // just below, which values wastage the same way for the same reason.
    $usage = $d['usage_trend'];
    if (array_sum($usage) <= 0) {
        $html .= '<div class="owner-card">' . anEmptyState('ph-chart-line-down', 'No recorded consumption in this period.', 'Usage is derived from completed sales.') . '</div>';
    } else {
        $html .= anChartCard('Stock usage trend', 'Cost of ingredients used per day, from completed sales', 'anUsageTrend', [
            'type' => 'line',
            'data' => [
                'labels'   => array_keys($usage),
                'datasets' => [[
                    'label' => 'Cost of stock used', 'data' => array_map(fn($v) => round((float)$v, 2), array_values($usage)),
                    'borderColor' => AN_BLUE, 'backgroundColor' => 'rgba(63,126,166,0.10)',
                    'fill' => true, 'tension' => 0.3, 'pointRadius' => 2,
                ]],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => false]],
                // 6, not the usual 12: peso tick labels are wide and squeeze
                // the plot until the date labels below run into each other.
                'scales'  => ['y' => ['beginAtZero' => true], 'x' => ['ticks' => ['maxTicksLimit' => 6, 'maxRotation' => 0]]],
            ],
        ], 'peso');
    }

    // -- Most consumed + low stock ---------------------------------------
    $html .= '<div class="an-two-col">';

    $mc = $d['most_consumed'];
    $html .= '<div class="owner-card"><div class="owner-card-head"><div>'
        . '<h2 class="owner-card-title">Most consumed ingredients</h2>'
        . '<span class="owner-card-subtitle">By quantity used in this period</span></div></div>';
    if (empty($mc)) {
        $html .= anEmptyState('ph-carrot', 'No consumption recorded in this period.', '');
    } else {
        $html .= '<div class="an-table-pager"><div style="overflow-x:auto;"><table class="owner-table" data-paginate="5"><thead><tr>'
            . '<th>Ingredient</th><th>Used</th></tr></thead><tbody>';
        foreach ($mc as $r) {
            $html .= '<tr><td><strong>' . htmlspecialchars($r['item_name']) . '</strong></td>'
                . '<td>' . number_format((float)$r['qty'], 2) . ' ' . htmlspecialchars($r['unit_code'] ?? '') . '</td></tr>';
        }
        $html .= '</tbody></table></div>' . anTablePagerHtml() . '</div>';
    }
    $html .= '</div>';

    // "Low-stock items" doubles as the stockout list too -- an item at zero
    // is, definitionally, at or under its reorder level, so it was already
    // appearing in this table (with an out_of_stock status pill) as well as
    // in the separate "Stockout items" card below, verbatim. Renamed to
    // reflect that broader scope and the standalone stockout card was
    // removed rather than kept as a duplicate of a subset of this one.
    $low = $d['low_stock'];
    $html .= '<div class="owner-card"><div class="owner-card-head"><div>'
        . '<h2 class="owner-card-title">Stock needing attention</h2>'
        . '<span class="owner-card-subtitle">At or under reorder level, including anything out of stock'
        . (count($low) < (int)$d['low_stock_count'] ? ' &mdash; worst ' . count($low) . ' of ' . (int)$d['low_stock_count'] : '')
        . '</span></div></div>';
    if (empty($low)) {
        $html .= anEmptyState('ph-check-circle', 'Nothing needs attention.', 'Every active item is above its reorder level.');
    } else {
        $html .= '<div class="an-table-pager"><div style="overflow-x:auto;"><table class="owner-table" data-paginate="5"><thead><tr>'
            . '<th>Item</th><th>On hand</th><th>Reorder level</th><th>Status</th></tr></thead><tbody>';
        foreach ($low as $r) {
            // out_of_stock is at least as urgent as critical, and no more
            // severe pill style exists -- it shares is-critical rather than
            // reading as merely is-warning, same tier as a plain low item.
            $cls = in_array($r['stock_status'], ['critical', 'out_of_stock'], true) ? 'is-critical' : 'is-warning';
            $html .= '<tr><td><strong>' . htmlspecialchars($r['item_name']) . '</strong></td>'
                . '<td>' . number_format((float)$r['current_stock'], 2) . ' ' . htmlspecialchars($r['unit_code'] ?? '') . '</td>'
                . '<td>' . number_format((float)$r['reorder_level'], 2) . ' ' . htmlspecialchars($r['unit_code'] ?? '') . '</td>'
                . '<td><span class="owner-status-pill ' . $cls . '">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $r['stock_status']))) . '</span></td></tr>';
        }
        $html .= '</tbody></table></div>' . anTablePagerHtml() . '</div>';
    }
    $html .= '</div></div>';

    // -- Stock movement (fast / medium / slow mover) -----------------------
    // Every active item's CURRENT classification, not scoped to the period
    // above -- see computeMovementTypeBreakdown()'s own docblock. This is
    // the sweep's real, already-persisted read used to set reorder points
    // elsewhere; nothing here is a fresh calculation.
    $mv = $d['movement_types'];
    $html .= '<div class="owner-card"><div class="owner-card-head">'
        . '<h2 class="owner-card-title">Stock movement</h2>'
        . '<div style="display:flex;gap:16px;flex-wrap:wrap;font-size:0.86rem;">'
        . '<span><span class="owner-status-pill is-success">' . (int)$mv['counts']['fast'] . '</span> Fast mover' . ($mv['counts']['fast'] === 1 ? '' : 's') . '</span>'
        . '<span><span class="owner-status-pill is-warning">' . (int)$mv['counts']['medium'] . '</span> Medium mover' . ($mv['counts']['medium'] === 1 ? '' : 's') . '</span>'
        . '<span><span class="owner-status-pill is-neutral">' . (int)$mv['counts']['slow'] . '</span> Slow mover' . ($mv['counts']['slow'] === 1 ? '' : 's') . '</span>'
        . ($mv['counts']['unclassified'] > 0 ? '<span><span class="owner-status-pill is-neutral">' . (int)$mv['counts']['unclassified'] . '</span> Not yet classified</span>' : '')
        . '</div></div>';
    if (empty($mv['items'])) {
        $html .= anEmptyState('ph-gauge', 'No active inventory items yet.', '');
    } else {
        $html .= '<div class="an-table-pager"><div style="overflow-x:auto;"><table class="owner-table" data-paginate="5"><thead><tr>'
            . '<th>Item</th><th>Category</th><th>Movement type</th></tr></thead><tbody>';
        foreach ($mv['items'] as $r) {
            $html .= '<tr><td><strong>' . htmlspecialchars($r['item_name']) . '</strong></td>'
                . '<td>' . ($r['category_name'] !== null ? htmlspecialchars($r['category_name']) : '&mdash;') . '</td>'
                . '<td><span class="owner-status-pill ' . demandPatternBadgeClass($r['demand_pattern']) . '">' . htmlspecialchars(demandPatternLabel($r['demand_pattern'])) . '</span></td></tr>';
        }
        $html .= '</tbody></table></div>' . anTablePagerHtml() . '</div>';
    }
    $html .= '</div>';

    // -- Wastage: trend + reason mix ---------------------------------------
    // Both are valued in PESOS. Wastage rows mix units across items, so a
    // quantity total would be adding kilos to pieces; cost is the only
    // figure that can honestly be summed here.
    $wTrend  = $d['wastage_trend'];
    $wReason = $d['wastage_by_reason'];
    $wTotal  = array_sum(array_column($wReason, 'cost'));

    $html .= '<div class="an-two-col">';

    if (array_sum($wTrend) <= 0) {
        $html .= '<div class="owner-card"><div class="owner-card-head"><div>'
            . '<h2 class="owner-card-title">Waste trend</h2>'
            . '<span class="owner-card-subtitle">Cost of stock written off per day</span></div></div>'
            . anEmptyState('ph-trash', 'No wastage recorded in this period.', 'Nothing has been written off between these dates.')
            . '</div>';
    } else {
        $html .= anChartCard('Waste trend', 'Cost of stock written off per day', 'anInvWasteTrend', [
            'type' => 'line',
            'data' => [
                'labels'   => array_keys($wTrend),
                'datasets' => [[
                    'label' => 'Wastage cost', 'data' => array_map(fn($v) => round((float)$v, 2), array_values($wTrend)),
                    'borderColor' => AN_LOSS, 'backgroundColor' => 'rgba(207,90,68,0.10)',
                    'fill' => true, 'tension' => 0.3, 'pointRadius' => 2, 'borderWidth' => 2,
                ]],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => false]],
                // 6, not the usual 12: peso tick labels are wide, and in a
                // half-width card they squeeze the plot until the date labels
                // below run into each other.
                'scales'  => ['y' => ['beginAtZero' => true], 'x' => ['ticks' => ['maxTicksLimit' => 6, 'maxRotation' => 0]]],
            ],
        ], 'peso');
    }

    if (empty($wReason) || $wTotal <= 0) {
        $html .= '<div class="owner-card"><div class="owner-card-head"><div>'
            . '<h2 class="owner-card-title">Waste by reason</h2>'
            . '<span class="owner-card-subtitle">Share of wastage cost</span></div></div>'
            . anEmptyState('ph-chart-donut', 'No wastage recorded in this period.', '')
            . '</div>';
    } else {
        // Past six wedges the ring stops being readable, so the tail folds
        // into the reason enum's own 'other' bucket rather than being hidden.
        $slices = $wReason;
        if (count($slices) > 6) {
            $head = array_slice($slices, 0, 5);
            $tail = array_slice($slices, 5);
            $head[] = ['reason' => 'other', 'cost' => array_sum(array_column($tail, 'cost')), 'n' => array_sum(array_column($tail, 'n'))];
            $slices = $head;
        }
        $html .= anChartCard('Waste by reason', 'Share of ₱' . number_format($wTotal, 2) . ' written off', 'anInvWasteReason', [
            'type' => 'doughnut',
            'data' => [
                'labels'   => array_map(fn($r) => anWastageReasonLabel($r['reason']), $slices),
                'datasets' => [[
                    'data' => array_map(fn($r) => round((float)$r['cost'], 2), $slices),
                    'backgroundColor' => array_map(fn($r) => AN_WASTE_REASON_COLORS[$r['reason']] ?? '#e34948', $slices),
                    'borderColor' => '#ffffff', 'borderWidth' => 2,
                ]],
            ],
            'options' => [
                'responsive' => true, 'maintainAspectRatio' => false, 'cutout' => '58%',
                'plugins' => ['legend' => ['display' => true, 'position' => 'right',
                    'labels' => ['usePointStyle' => true, 'pointStyle' => 'circle', 'boxWidth' => 8, 'padding' => 12]]],
            ],
        ], 'peso_share', true);
    }

    $html .= '</div>';

    return $html;
}


function computeMenuItemRevenueRanking(PDO $db, DateTime $start, DateTime $end, int $limit = 20): array
{
    $stmt = $db->prepare(
        "SELECT mi.item_id, mi.item_name, mi.image_url, c.category_name,
                SUM(oi.quantity) AS total_qty, SUM(oi.subtotal) AS total_revenue
         FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         JOIN menu_items mi ON mi.item_id = oi.menu_item_id
         LEFT JOIN menu_categories c ON c.category_id = mi.category_id
         WHERE op.payment_status = 'paid' AND oi.status != 'cancelled'
           AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
         GROUP BY mi.item_id, mi.item_name, mi.image_url, c.category_name
         ORDER BY total_revenue DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $start->format('Y-m-d 00:00:00'));
    $stmt->bindValue(2, $end->format('Y-m-d 23:59:59'));
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// =======================================================================
// AI Insights -- one shared cache/generate runner (see runAnalyticsAiInsight())
// used by all 4 tabs, modeled directly on config/feedback_insights.php's
// regenerateFeedbackInsights() (real-data-only prompt, JSON mode, cached
// row persisted, manual regenerate bypasses the cache).
// =======================================================================

function getCachedAnalyticsInsight(PDO $db, string $tab, string $periodKey): ?array
{
    $stmt = $db->prepare(
        "SELECT summary, recommendations, based_on_count, generated_at FROM analytics_ai_insights
         WHERE tab = ? AND period_key = ? AND generated_at >= (NOW() - INTERVAL ? HOUR)
         ORDER BY insight_id DESC LIMIT 1"
    );
    $stmt->execute([$tab, $periodKey, ANALYTICS_AI_CACHE_TTL_HOURS]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'summary' => $row['summary'],
        'recommendations' => json_decode((string)$row['recommendations'], true) ?: [],
        'based_on_count' => (int)$row['based_on_count'],
        'generated_at' => $row['generated_at'],
        'cached' => true,
    ];
}

function saveAnalyticsInsight(PDO $db, string $tab, string $periodKey, array $result, int $basedOnCount, ?int $generatedBy): void
{
    $db->prepare(
        "INSERT INTO analytics_ai_insights (tab, period_key, summary, recommendations, based_on_count, generated_at, generated_by)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)"
    )->execute([$tab, $periodKey, $result['summary'], json_encode($result['recommendations']), $basedOnCount, $generatedBy]);
}

/**
 * Cache-or-generate one tab's AI insight. $payload must already be
 * aggregated, real numbers only (never raw customer rows) -- same "don't
 * invent statistics" contract as generateFeedbackInsights(). Returns null
 * when OpenAI isn't configured, the sample is too small, or the call/parse
 * fails -- callers show a plain "insight unavailable" message, never a
 * fabricated fallback.
 */
function runAnalyticsAiInsight(PDO $db, ?OpenAiClient $openai, string $tab, DateTime $start, DateTime $end, string $systemPrompt, array $payload, int $basedOnCount, bool $forceRegenerate, ?int $generatedBy): ?array
{
    // Folds a short hash of the live reservation/ordering policy
    // (analyticsOperationalContext(), the same settings every tab's prompt
    // is given) into the cache key alongside the period. Without this, an
    // insight that told the owner to "review the ₱100 reservation fee"
    // would keep serving that exact text, unchanged, for up to
    // ANALYTICS_AI_CACHE_TTL_HOURS after the owner raised the fee to ₱150 in
    // Settings -- the cache had no way to know the policy underneath it had
    // moved. Changing any setting this reads now invalidates every tab's
    // cached insight immediately, the next time each is viewed.
    $periodKey = analyticsPeriodKey($start, $end) . ':' . substr(md5(json_encode(analyticsOperationalContext($db))), 0, 10);

    if (!$forceRegenerate) {
        $cached = getCachedAnalyticsInsight($db, $tab, $periodKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    if ($openai === null || $basedOnCount < ANALYTICS_AI_MIN_SAMPLE) {
        return null;
    }

    try {
        $message = $openai->chat(
            [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => json_encode($payload)]],
            [], ['type' => 'json_object']
        );
        $result = json_decode((string)($message['content'] ?? ''), true);
        if (!is_array($result) || !isset($result['summary'])) {
            return null;
        }
    } catch (Throwable $e) {
        error_log("runAnalyticsAiInsight ({$tab}) failed: " . $e->getMessage());
        return null;
    }

    $clean = [
        'summary' => (string)$result['summary'],
        'recommendations' => array_values(array_map('strval', (array)($result['recommendations'] ?? []))),
    ];
    saveAnalyticsInsight($db, $tab, $periodKey, $clean, $basedOnCount, $generatedBy);

    return $clean + ['based_on_count' => $basedOnCount, 'generated_at' => date('Y-m-d H:i:s'), 'cached' => false];
}

const ANALYTICS_AI_SYSTEM_PROMPT_PREFIX = "You are writing the commentary an operations analyst would hand a restaurant owner -- but in the words you would actually say out loud to a busy owner who is not a data person, not the way it would appear in a written report. Register: simple, everyday English. Short sentences. Ordinary words. No business-analyst or data jargon -- never write phrases like 'concentration risk', 'the data indicates', or 'a downward trend was observed'; say what actually happened in plain terms a non-expert reads once and understands. (A term that is itself a label on screen, like 'resolved bookings', stays -- it is naming something real the owner can see, not analyst-speak.) Third person, no filler, no hedging, no exclamation marks, and never address the reader as \"you\". Do not open with a pleasantry, a preamble, or the name of the tab.

GROUNDING -- these are absolute:
- Use only the figures given to you. Never invent, estimate, extrapolate or recompute a statistic, and never state a figure the owner cannot find on the screen in front of them.
- Never name a dish, menu item, category, ingredient, supplier or cuisine that does not appear verbatim in the data. This restaurant serves Filipino food; assume nothing else about what it offers. Where you must refer to products in general, write \"top-selling items\".
- Quote every figure exactly as provided, with its unit. All monetary values are Philippine pesos: always write them with the peso sign, thousands separators and two decimals, exactly as the screen shows them (₱130,820.00 -- never ₱130820, never a dollar sign).
- Never quote a raw field name from the data (peak_hour, no_show_rate, low_stock_items). Write what it means in plain English, the way the screen labels it.
- Where two measures disagree, say so explicitly and say which one you are using.
- If the data is too thin to support a conclusion, say so plainly instead of guessing.
- operational_context contains current, verified configuration from other owner-managed parts of the system. It may make a recommendation more practical, but it is not reporting-period activity: never claim that a configured feature was used, caused a result, or was available at a particular time unless the analytics data says so. Never recommend changing a setting, processing a payment, placing a purchase order, or taking any other system action; recommendations must remain owner decisions.
- Before writing any number into a recommendation (a fee, a time window, a percentage, a threshold), check whether operational_context already contains that exact figure under a matching setting. If it does, the policy is already live -- do not propose 'implementing' or 'adding' it. Either omit that recommendation, or, only if the data shows the problem persisting despite it, note that the existing configured value has not resolved the problem and suggest reviewing it -- never present an existing setting as a new idea.

CONTENT: the owner is already looking at these numbers, so restating them is worthless. Lead with the single most important thing in the data -- something unusual, a gap between two figures, or a change in direction -- and say plainly what it costs or earns them, the way you would explain it face to face. Return ONLY valid JSON in this exact shape: {\"summary\": \"2-3 sentences\", \"recommendations\": [\"recommendation 1\", \"recommendation 2\"]}. Give two or three recommendations, each one plain, direct sentence: name the figure that triggers it and the concrete action to take, something the owner could act on this week -- never generic advice such as 'consider promotions' or 'increase marketing' with no number attached, and never a stiffer or more technical sentence when a simpler one says the same thing.";

/**
 * Per-tab AI insight payloads.
 *
 * THE DIVISION OF LABOUR: PHP computes every figure; the model only writes
 * prose about figures it was handed. It is never asked to rank, total or
 * derive anything -- where a "top" or "peak" is needed it is resolved here in
 * arithmetic and passed in already decided, because a model given two series
 * will eventually name the wrong one as the winner.
 *
 * Only aggregates are sent. No customer names, emails, phone numbers or any
 * other identifying field appears in these payloads.
 */
function salesAiInsight(PDO $db, ?OpenAiClient $openai, array $tabData, DateTime $start, DateTime $end, bool $force, ?int $generatedBy): ?array
{
    $prompt = "You are commenting on a restaurant's Sales tab. Every reporting-period figure below is also on the owner's screen: the revenue / completed orders / average order value cards, the daily revenue trend, the top-selling items table, the orders-by-hour chart, and the revenue-by-category chart.\n\n"
        . "When peak_hour is not null, the summary MUST state its label and order count verbatim rather than ranking the series yourself. revenue_by_category is ordered biggest first; its share_pct values are shares of period revenue, its units_sold and best_seller both appear in that chart's tooltip, and a category's best seller is its top item by UNITS, which is not always its biggest earner.\n\n"
        . ANALYTICS_AI_SYSTEM_PROMPT_PREFIX;

    $hours = $tabData['peak_hours'] ?? [];
    $peakHour = null;
    $peakOrders = 0;
    foreach ($hours as $h => $row) {
        if ((int)$row['orders'] > $peakOrders) {
            $peakOrders = (int)$row['orders'];
            $peakHour   = (int)$h;
        }
    }

    $trend = $tabData['revenue_trend'] ?? [];
    $payload = [
        // Named so the model cannot mistake VAT or discounts for earnings --
        // it previously received one ambiguous "revenue" number that was
        // actually the VAT-inclusive amount billed.
        'kpi' => [
            'net_sales_excluding_vat' => round((float)$tabData['kpi']['net_sales_vat_exc'], 2),
            'total_billed_incl_vat'   => round((float)$tabData['kpi']['total_collected'], 2),
            'discounts_given'         => round((float)$tabData['kpi']['discounts'], 2),
            'output_vat'              => round((float)$tabData['kpi']['vat'], 2),
            'paid_orders'             => (int)$tabData['kpi']['total_orders'],
            'average_order_value'     => round((float)$tabData['kpi']['aov'], 2),
        ],
        'top_items' => array_slice(array_map(
            fn($r) => ['item' => $r['item_name'], 'units_sold' => (float)$r['units_sold'], 'revenue' => round((float)$r['revenue'], 2)],
            $tabData['top_items'] ?? []
        ), 0, 5),
        'peak_hour' => $peakHour === null ? null : ['label' => formatAnalyticsHour($peakHour), 'orders' => $peakOrders],
        'revenue_by_category' => array_map(function ($r) use ($tabData) {
            $name = $r['category_name'] ?? 'Uncategorized';
            $best = $tabData['category_best'][$name] ?? null;
            return [
                'category'          => $name,
                'revenue'           => round((float)$r['revenue'], 2),
                'units_sold'        => (float)$r['qty'],
                'share_pct'         => round((float)$r['pct'], 1),
                'best_seller'       => $best['item_name'] ?? null,
                'best_seller_units' => $best === null ? null : (float)$best['qty'],
            ];
        }, $tabData['category_revenue'] ?? []),
        'days_in_period'   => count($trend),
        'days_with_sales'  => count(array_filter($trend, fn($v) => $v > 0)),
        // Deliberately NOT sending operational_context here -- every field
        // in it (reservation fee, lead time, hold window, no-show grace
        // period, advance-order deposit %) is a Reservations-tab setting.
        // Handing it to Sales let the model connect a busy peak HOUR to
        // reservation LEAD TIME and FEE policy, a real non-sequitur seen
        // live: "increase reservation lead time... review the reservation
        // fee" as a response to 4pm order volume. Sales has no configured
        // policy of its own worth cross-referencing.
    ];

    return runAnalyticsAiInsight($db, $openai, 'sales', $start, $end, $prompt, $payload, (int)$tabData['kpi']['total_orders'], $force, $generatedBy);
}

function reservationsAiInsight(PDO $db, ?OpenAiClient $openai, array $tabData, DateTime $start, DateTime $end, bool $force, ?int $generatedBy): ?array
{
    $prompt = "You are commenting on a restaurant's Reservations tab. This tab only tracks reservations that actually became (or still could become) a real, revenue-bearing booking: confirmed (paid, coming), completed (showed up), and no_show (didn't). Pending holds (unpaid, in progress) and cancelled reservations (a hold that lapsed or was backed out of before payment) are deliberately excluded from every figure below -- not summarised, not shown as zero, just out of scope, because neither one ever became a booking worth reporting on. Never mention pending or cancelled bookings, and never imply this tab covers 'all' reservations in some broader sense than that.\n\n"
        . "Every reporting-period figure below is also on the owner's screen: the total / confirmed / completed / no-show / no-show-rate cards, the daily booking trend, the reservation revenue trend (reservation fees vs advance-order payments actually collected per day, stacked), bookings per time slot, bookings per day of week (each bar's tooltip also shows that slot's or day's own no-show count and rate), the no-show trend, and the status-mix donut.\n\n"
        . "revenue.reservation_fees_collected and revenue.advance_order_payments_collected are the two series behind that revenue trend chart, summed across the whole period; revenue.total_reservation_revenue is their sum. This is money collected through the reservation system itself (PayMongo GCash payments for the fee or an advance food order), not restaurant sales -- an advance-order payment is a deposit toward food the guest is eventually served, and that visit's order is counted separately in the Sales tab. Never call these figures 'restaurant revenue', never add them to or compare them against any Sales tab number, and never recompute the totals yourself -- quote them as given. revenue.peak_revenue_day (null if nothing was collected) names the single day that collected the most; use it rather than reading the chart yourself.\n\n"
        . "no_show_rate is calculated against RESOLVED bookings only (completed + no_show); confirmed bookings are excluded because they have not yet had the chance to become a no-show. Do not recompute it, and do not describe it as a share of ALL bookings -- the donut on screen uses that other denominator.\n\n"
        . "There is no cancellation-rate figure on this tab and none is given to you (cancelled reservations are out of scope entirely, see above). For any share of bookings, quote status_mix.share_of_all_pct verbatim -- those are exactly the donut's wedges, each one a share of ALL tracked bookings (confirmed+completed+no_show combined). Always describe it that way ('X% of all bookings'/'of all N reservations') -- never relabel it as a share of resolved, completed, or any other subset, and never divide one figure by another yourself.\n\n"
        . "bookings_by_slot and bookings_by_day each carry a real per-slot/per-day split: bookings, no_shows, resolved and no_show_rate_pct (null where resolved is 0 -- write 'no resolved bookings yet' for that entry, never 0%). If naming which single slot or day concentrates the no-shows, you MUST use worst_slot_for_no_shows / worst_day_for_no_shows exactly as given rather than ranking the arrays yourself -- both are null whenever no slot/day has at least 3 resolved bookings, a sample floor chosen so one unlucky booking at a rarely-used slot cannot read as a 100% problem; when null, say the data is too thin to identify a specific slot or day rather than naming one anyway.\n\n"
        . "operational_context describes the real, currently configured reservation policy (fee, lead time, unpaid-hold window, no-show grace period, deposit rules, capacity, operating days) -- read it before writing recommendations. When a finding plausibly connects to one of these levers (for example, a concentrated no-show pattern and the current no-show grace period, or thin bookings on a day the restaurant is not even open), name the specific configured value and suggest reviewing it, rather than leaving the finding to sit on its own with no lever attached. This is still bound by the grounding rule below: only suggest reviewing a setting that is actually relevant to what the data just showed, and never invent a setting that is not in operational_context.\n\n"
        . ANALYTICS_AI_SYSTEM_PROMPT_PREFIX;

    $s = $tabData['summary'];

    $slotDayBreakdown = function (array $rows, string $labelField, string $outKey): array {
        return array_map(function ($r) use ($labelField, $outKey) {
            $resolved = (int)($r['resolved'] ?? 0);
            $noShows  = (int)($r['no_shows'] ?? 0);
            return [
                $outKey            => $r[$labelField],
                'bookings'         => (int)$r['n'],
                'no_shows'         => $noShows,
                'resolved'         => $resolved,
                'no_show_rate_pct' => $resolved > 0 ? round(($noShows / $resolved) * 100, 1) : null,
            ];
        }, $rows);
    };
    // Pre-decided here, not left to the model to rank -- same reason
    // salesAiInsight()'s peak_hour and the no_show_trend's worst_day below
    // are resolved in PHP: a model handed several competing series will
    // eventually name the wrong one as the worst.
    $worstForNoShows = function (array $breakdown, int $minResolved = 3): ?array {
        $worst = null;
        foreach ($breakdown as $r) {
            if ($r['resolved'] < $minResolved || $r['no_show_rate_pct'] === null) {
                continue;
            }
            if ($worst === null || $r['no_show_rate_pct'] > $worst['no_show_rate_pct']) {
                $worst = $r;
            }
        }
        return $worst;
    };

    $slotBreakdown = $slotDayBreakdown($tabData['popular_slots'] ?? [], 'slot_label', 'slot');
    $dayBreakdown  = $slotDayBreakdown($tabData['popular_days'] ?? [], 'day', 'day');

    $revenueTrend  = $tabData['revenue_trend'] ?? ['fees' => [], 'advances' => []];
    $feesCollected = round(array_sum($revenueTrend['fees']), 2);
    $advCollected  = round(array_sum($revenueTrend['advances']), 2);
    $peakRevenueDate = null;
    $peakRevenueAmt  = 0.0;
    foreach ($revenueTrend['fees'] as $date => $feeAmt) {
        $dayTotal = $feeAmt + ($revenueTrend['advances'][$date] ?? 0);
        if ($dayTotal > $peakRevenueAmt) {
            $peakRevenueAmt  = $dayTotal;
            $peakRevenueDate = $date;
        }
    }

    $payload = [
        'totals' => [
            'total'     => (int)$s['total'],
            'completed' => (int)$s['completed'],
            'no_shows'  => (int)$s['no_show'],
            'confirmed' => (int)$s['confirmed'],
        ],
        'resolved_bookings' => (int)$s['resolved'],
        // 1 decimal, not 2: the KPI cards print these with number_format(x, 1),
        // so sending 70.97 had the summary quoting a rate the card renders as
        // 71.0% right beside it.
        'no_show_rate'      => $s['no_show_rate'] === null ? null : round($s['no_show_rate'], 1),
        'revenue' => [
            'reservation_fees_collected'        => $feesCollected,
            'advance_order_payments_collected'  => $advCollected,
            'total_reservation_revenue'         => round($feesCollected + $advCollected, 2),
            'peak_revenue_day' => $peakRevenueDate === null ? null : ['date' => $peakRevenueDate, 'amount' => round($peakRevenueAmt, 2)],
        ],
        'bookings_by_slot'  => $slotBreakdown,
        'bookings_by_day'   => $dayBreakdown,
        'worst_slot_for_no_shows' => $worstForNoShows($slotBreakdown),
        'worst_day_for_no_shows'  => $worstForNoShows($dayBreakdown),
        // The donut's own wedges, shares included, so the model never has to
        // divide anything itself -- every percentage it can quote is one the
        // owner can read straight off the ring.
        'status_mix' => (function (array $sum) {
            $total = (int)$sum['total'];
            $out = [];
            foreach (AN_RES_STATUS_LABELS as $key => $label) {
                if ((int)($sum[$key] ?? 0) > 0) {
                    $out[] = [
                        'status'            => $label,
                        'bookings'          => (int)$sum[$key],
                        'share_of_all_pct'  => $total > 0 ? round(((int)$sum[$key] / $total) * 100, 1) : 0.0,
                    ];
                }
            }
            return $out;
        })($s),
        'no_show_trend'     => (function (array $t) {
            $peakDate = null; $peakN = 0;
            foreach ($t as $date => $n) {
                if ($n > $peakN) { $peakN = (int)$n; $peakDate = $date; }
            }
            return [
                'total_no_shows'      => (int)array_sum($t),
                'days_on_which_at_least_one_no_show_occurred' => count(array_filter($t, fn($v) => $v > 0)),
                'worst_day'           => $peakDate === null ? null : ['date' => $peakDate, 'no_shows' => $peakN],
            ];
        })($tabData['no_show_trend'] ?? []),
        'operational_context' => analyticsOperationalContext($db),
    ];

    return runAnalyticsAiInsight($db, $openai, 'reservations', $start, $end, $prompt, $payload, (int)$s['total'], $force, $generatedBy);
}

function inventoryAiInsight(PDO $db, ?OpenAiClient $openai, array $tabData, DateTime $start, DateTime $end, bool $force, ?int $generatedBy): ?array
{
    $prompt = "You are commenting on a restaurant's Inventory tab. Every reporting-period figure below is also on the owner's screen: the stock-items / low-stock / stockout / inventory-value cards, the daily consumption trend, the most-consumed ingredients, the low-stock and stockout tables, the waste trend, and the waste-by-reason donut.\n\n"
        . "This system does not record historical stockout dates. stockout_items lists what is at zero RIGHT NOW. Never state or imply that an item ran out on a particular date, or how long anything has been out.\n\n"
        . "items_at_or_below_reorder is the FULL count and is the same figure shown on the Low-stock KPI card -- use it verbatim when stating how many items need restocking. low_stock_items is only the worst few of that set, so never count its entries and present the result as the total.\n\n"
        . "An item is OUT OF STOCK only if it appears in stockout_items. Anything with a nonzero on_hand still has stock, however small -- describe it as low or below its reorder level, never as being at zero, out, or depleted, and do not round a small on_hand down to zero in your prose.\n\n"
        . "Wastage is valued in PESOS, never in quantity -- the underlying records mix units across items, so a quantity total would be meaningless and must not be stated. wastage.by_reason is exactly the breakdown in the donut on screen. Do not describe wastage as rising or falling unless the figures given actually show that.\n\n"
        . "The daily consumption trend (Stock usage trend on screen) is also valued in PESOS for the same reason -- ingredients are drawn in a genuine mix of units (kg/L/pcs), so a quantity figure for it does not exist and must never be stated or invented.\n\n"
        . "Do not recommend purchase quantities or make purchasing decisions -- that belongs to the separate purchasing module. You may observe that items are below their reorder level.\n\n"
        . ANALYTICS_AI_SYSTEM_PROMPT_PREFIX;

    $usage = $tabData['usage_trend'] ?? [];

    $wasteTotal   = array_sum(array_column($tabData['wastage_by_reason'] ?? [], 'cost'));
    $worstWasteDay = null;
    foreach ($tabData['wastage_trend'] ?? [] as $date => $cost) {
        if ($cost > 0 && ($worstWasteDay === null || $cost > $worstWasteDay['cost'])) {
            $worstWasteDay = ['date' => $date, 'cost' => round((float)$cost, 2)];
        }
    }

    $payload = [
        'stock_summary' => [
            'active_items'    => (int)$tabData['stock_summary']['total_items'],
            'ok'              => (int)$tabData['stock_summary']['ok'],
            'low'             => (int)$tabData['stock_summary']['low'],
            'critical'        => (int)$tabData['stock_summary']['critical'],
        ],
        'inventory_value'  => round((float)$tabData['inventory_value'], 2),
        'items_at_or_below_reorder' => (int)$tabData['low_stock_count'],
        'low_stock_items'  => array_slice(array_map(
            fn($r) => [
                'item' => $r['item_name'],
                'on_hand' => (float)$r['current_stock'],
                'reorder_level' => (float)$r['reorder_level'],
                'status' => $r['stock_status'],
            ],
            $tabData['low_stock'] ?? []
        ), 0, 10),
        'stockout_items'   => array_map(fn($r) => $r['item_name'], $tabData['stockouts'] ?? []),
        'most_consumed'    => array_slice(array_map(
            fn($r) => ['item' => $r['item_name'], 'quantity' => (float)$r['qty'], 'unit' => $r['unit_code']],
            $tabData['most_consumed'] ?? []
        ), 0, 5),
        // total_consumed_in_period and days_with_consumption used to be sent
        // here and are deliberately gone. The first added kilos to pieces
        // across items and was not a real quantity; the second was read back
        // as "the last 15 days" in a live summary, turning a count of active
        // days into a date range. Neither appears on screen as a number.
        'days_in_period'   => count($usage),
        'wastage' => [
            'total_cost' => round((float)$wasteTotal, 2),
            'by_reason'  => array_map(fn($r) => [
                'reason'  => anWastageReasonLabel($r['reason']),
                'cost'    => round((float)$r['cost'], 2),
                'records' => (int)$r['n'],
            ], $tabData['wastage_by_reason'] ?? []),
            'worst_day'  => $worstWasteDay,
        ],
        // Same reason as salesAiInsight(): operational_context is entirely
        // Reservations-tab policy (fee, lead time, hold window, no-show
        // grace period, deposit %) with nothing relevant to stock or
        // wastage, and sending it invites the same kind of cross-domain
        // recommendation caught live on the Sales tab.
    ];

    return runAnalyticsAiInsight($db, $openai, 'inventory', $start, $end, $prompt, $payload, (int)$tabData['stock_summary']['total_items'], $force, $generatedBy);
}


/** Dispatch by tab key -- shared by analytics.php (active tab, synchronous) and analytics_insight.php (any tab, lazy-loaded). */
function computeTabAiInsight(PDO $db, ?OpenAiClient $openai, string $tab, array $tabData, DateTime $start, DateTime $end, bool $force, ?int $generatedBy): ?array
{
    return match ($tab) {
        'sales' => salesAiInsight($db, $openai, $tabData, $start, $end, $force, $generatedBy),
        'reservations' => reservationsAiInsight($db, $openai, $tabData, $start, $end, $force, $generatedBy),
        'inventory' => inventoryAiInsight($db, $openai, $tabData, $start, $end, $force, $generatedBy),
    };
}

/** Renders the AI insight box's body HTML from a computeTabAiInsight() result (or null). Shared by the synchronous initial render and analytics_insight.php's JSON->HTML-on-the-client path -- kept identical so a lazy-loaded tab looks the same as the eagerly-rendered one. */
function renderAnalyticsAiInsightBodyHtml(?array $insight): string
{
    if ($insight === null) {
        return '<p class="an-ai-empty">No insight for this period yet &mdash; use Regenerate to build one.</p>';
    }

    // The summary is the model's read of the period; the recommendations are
    // what it suggests doing about it. They were previously one undifferentiated
    // run of a paragraph and a bullet list, which read as a single blob of AI
    // text. Labelling and numbering the actions separates "here is what
    // happened" from "here is what to do", which is the only part a manager
    // acts on. The summary now sits in its own callout card (gold accent,
    // sparkle mark) so it reads as the panel's headline finding rather than
    // a stray paragraph, and each recommendation is its own bordered row
    // rather than plain text beside a number.
    $html = '<div class="an-ai-summary-card">'
        . '<p class="an-ai-summary">' . htmlspecialchars($insight['summary']) . '</p></div>';

    if (!empty($insight['recommendations'])) {
        $html .= '<div class="an-ai-recs-label">Recommended actions</div>';
        $html .= '<ul class="an-ai-recs">';
        foreach ($insight['recommendations'] as $rec) {
            $html .= '<li><span>' . htmlspecialchars($rec) . '</span></li>';
        }
        $html .= '</ul>';
    }

    $html .= '<div class="an-ai-disclaimer"><i class="ph ph-info" aria-hidden="true"></i>'
        . 'AI-generated from this period\'s real figures. Review before acting on it.</div>';

    return $html;
}

/** The "Regenerate" mini-form under each tab's AI Insights box -- a real POST + redirect to analytics_action.php, same convention as owner/sentiment_analysis_action.php's regenerate_insights action (not a fetch call, so it works identically with/without JS). */
function analyticsRegenerateFormHtml(string $tab, string $period, DateTime $start, DateTime $end): string
{
    return '<form method="post" action="analytics_action.php" class="an-ai-regenerate">'
        . csrf_field()
        . '<input type="hidden" name="action" value="regenerate_insights">'
        . '<input type="hidden" name="tab" value="' . htmlspecialchars($tab) . '">'
        . '<input type="hidden" name="period" value="' . htmlspecialchars($period) . '">'
        . '<input type="hidden" name="date_from" value="' . $start->format('Y-m-d') . '">'
        . '<input type="hidden" name="date_to" value="' . $end->format('Y-m-d') . '">'
        . '<button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Regenerate</button>'
        . '</form>';
}

// =======================================================================
// Shared print/PDF document -- one function, all 4 tabs (mirrors
// owner/report.php's renderReportPrintDocumentHtml() -- one module, many
// tabs -- rather than sales_analytics/menu_performance's one-function-
// per-single-tab-page shape, since Analytics has 4 tabs in one module).
// =======================================================================

function renderAnalyticsPdfDocumentHtml(string $tab, array $d, string $restaurantName, string $periodLabel, bool $includeActions, string $queryString): string
{
    $tabLabels = ['sales' => 'Sales', 'reservations' => 'Reservations', 'inventory' => 'Inventory'];
    ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<?php if ($includeActions): ?><meta name="viewport" content="width=device-width, initial-scale=1.0"><?php endif; ?>
<title><?= htmlspecialchars($tabLabels[$tab]) ?> Analytics Report</title>
<style>
    @page{ size:A4; margin:15mm; }
    body{ font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color:#000; font-size:12px; margin:0; padding:0; background:<?= $includeActions ? '#f6f3ec' : '#fff' ?>; }
    .doc{ max-width:760px; margin:0 auto; padding:32px; background:#fff; <?= $includeActions ? 'border:1px solid #ddd; margin-top:24px;' : '' ?> }
    h1{ font-size:20px; margin:0 0 4px; }
    h2{ font-size:14px; margin:22px 0 8px; border-bottom:1px solid #000; padding-bottom:4px; }
    .muted{ color:#555; }
    .divider{ border-top:1px solid #000; margin:14px 0; }
    table.doc-table{ width:100%; border-collapse:collapse; margin-top:6px; }
    table.doc-table th, table.doc-table td{ border:1px solid #000; padding:5px 7px; font-size:10.5px; text-align:left; }
    table.doc-table th{ background:#eee; }
    table.doc-table td.num, table.doc-table th.num{ text-align:right; }
    .footer{ margin-top:24px; font-size:10px; color:#555; }
    .empty-note{ font-size:11px; color:#555; font-style:italic; margin:6px 0; }
    <?php if ($includeActions): ?>
    .pdf-actions{ max-width:760px; margin:14px auto 24px; display:flex; justify-content:center; gap:10px; }
    .pdf-actions button, .pdf-actions a{ display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:8px; border:1px solid #ddd; background:#fff; color:#000; font-family:sans-serif; font-size:0.85rem; font-weight:600; cursor:pointer; text-decoration:none; }
    .pdf-actions .primary{ background:#9c7734; border-color:#9c7734; color:#fff; }
    @media print{ .pdf-actions{ display:none !important; } body{ background:#fff; } .doc{ border:none; margin-top:0; } }
    <?php endif; ?>
</style>
</head>
<body>
<div class="doc">
    <h1><?= htmlspecialchars($restaurantName) ?></h1>
    <div class="muted"><?= htmlspecialchars($tabLabels[$tab]) ?> Analytics Report &mdash; <?= htmlspecialchars($periodLabel) ?></div>
    <div class="divider"></div>

    <?php if ($tab === 'sales'): ?>
        <h2>Sales Statement</h2>
        <table class="doc-table">
            <tr><td>Gross sales</td><td class="num">&#8369;<?= number_format($d['kpi']['gross_sales'], 2) ?></td></tr>
            <tr><td>Less: discounts</td><td class="num">(&#8369;<?= number_format($d['kpi']['discounts'], 2) ?>)</td></tr>
            <tr><td>Net sales (incl. VAT)</td><td class="num">&#8369;<?= number_format($d['kpi']['net_sales_vat_inc'], 2) ?></td></tr>
            <tr><td>Less: output VAT</td><td class="num">(&#8369;<?= number_format($d['kpi']['vat'], 2) ?>)</td></tr>
            <tr><td><strong>Net sales (excl. VAT)</strong></td><td class="num"><strong>&#8369;<?= number_format($d['kpi']['net_sales_vat_exc'], 2) ?></strong></td></tr>
            <tr><td>Total billed</td><td class="num">&#8369;<?= number_format($d['kpi']['total_collected'], 2) ?></td></tr>
            <tr><td>Paid orders</td><td class="num"><?= number_format($d['kpi']['total_orders']) ?></td></tr>
            <tr><td>Average order value</td><td class="num">&#8369;<?= number_format($d['kpi']['aov'], 2) ?></td></tr>
        </table>

        <?php // Keys corrected: this block read $d['category_ranked'] and
              // $d['best_per_category'], neither of which buildSalesTabData()
              // has ever returned (they are 'category_revenue' and
              // 'category_best'). Because the guard was empty(), a missing key
              // read as "no data" rather than raising anything -- so the PDF
              // silently printed "No category revenue in this period" on every
              // export, however many categories had sold. ?>
        <h2>Revenue by Category</h2>
        <?php if (empty($d['category_revenue'])): ?><div class="empty-note">No category revenue in this period.</div><?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Category</th><th class="num">Revenue</th><th class="num">Share</th><th>Best seller</th><th class="num">Sold</th></tr></thead>
            <tbody><?php foreach ($d['category_revenue'] as $row):
                // The on-screen category chart surfaces this in its tooltip; the
                // export has to carry it too or the PDF says less than the page.
                $best = $d['category_best'][$row['category_name'] ?? ''] ?? null; ?>
                <tr><td><?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?></td><td class="num">&#8369;<?= number_format((float)$row['revenue'], 2) ?></td><td class="num"><?= number_format($row['pct'], 1) ?>%</td><td><?= $best ? htmlspecialchars($best['item_name']) : '&mdash;' ?></td><td class="num"><?= $best ? number_format((float)$best['qty']) : '&mdash;' ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>

        <?php // Was $d['discount_usage'], a key that has never existed -- this
              // section raised an undefined-key warning on every sales PDF. It
              // now reads the statement the rest of the page is built from,
              // which also lets it name each discount instead of printing one
              // lump sum. ?>
        <h2>Discounts</h2>
        <?php $st = $d['kpi']['statement']; ?>
        <?php if (empty($st['discounts_breakdown'])): ?>
            <div class="empty-note">No discounts were given in this period.</div>
        <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Discount</th><th class="num">Orders</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($st['discounts_breakdown'] as $row): ?>
                <tr><td><?= htmlspecialchars($row['discount_name']) ?><?= $row['is_vat_exempt'] ? ' (VAT-exempt)' : '' ?></td><td class="num"><?= number_format($row['order_count']) ?></td><td class="num">&#8369;<?= number_format($row['amount'], 2) ?></td></tr>
            <?php endforeach; ?>
                <tr><td><strong>Total</strong></td><td class="num"><strong><?= number_format($st['discounted_order_count']) ?></strong></td><td class="num"><strong>&#8369;<?= number_format($st['discounts'], 2) ?></strong></td></tr>
            </tbody>
        </table>
        <?php endif; ?>

        <h2>VAT Summary</h2>
        <table class="doc-table">
            <tr><td>VATable sales (net)</td><td class="num">&#8369;<?= number_format($st['vatable_sales'], 2) ?></td></tr>
            <tr><td>Output VAT (12%)</td><td class="num">&#8369;<?= number_format($st['vat'], 2) ?></td></tr>
            <tr><td>VAT-exempt sales (PWD / Senior)</td><td class="num">&#8369;<?= number_format($st['vat_exempt_sales'], 2) ?></td></tr>
        </table>

        <h2>Payment Methods</h2>
        <?php if (empty($st['tender']['rows'])): ?>
            <div class="empty-note">No payments in this period.</div>
        <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Method</th><th class="num">Transactions</th><th class="num">Amount</th><th class="num">Share</th></tr></thead>
            <tbody>
            <?php foreach ($st['tender']['rows'] as $row): ?>
                <tr><td><?= htmlspecialchars($row['label']) ?></td><td class="num"><?= number_format($row['txn_count']) ?></td><td class="num">&#8369;<?= number_format($row['amount'], 2) ?></td><td class="num"><?= number_format($row['pct'], 1) ?>%</td></tr>
            <?php endforeach; ?>
                <tr><td><strong>Received at the till</strong></td><td class="num"></td><td class="num"><strong>&#8369;<?= number_format($st['tender']['total'], 2) ?></strong></td><td class="num"></td></tr>
            </tbody>
        </table>
        <?php endif; ?>

    <?php // This whole section used to read keys buildReservationsTabData() has
          // never returned -- total_bookings, by_status, cancelled_rate,
          // avg_party_size, adoption, deposits -- so every reservations PDF
          // raised six undefined-key warnings and printed blanks. Rewritten
          // against the shape that actually exists. "Average party size" is gone
          // rather than faked: nothing in this tab's payload carries guest
          // counts, and inventing a number for a printed report is worse than
          // omitting one. ?>
    <?php elseif ($tab === 'reservations'): ?>
        <?php $rs = $d['summary']; ?>
        <h2>KPI Summary</h2>
        <table class="doc-table">
            <tr><td>Total reservations</td><td class="num"><?= number_format($rs['total']) ?></td></tr>
            <tr><td>Resolved (completed or no-show)</td><td class="num"><?= number_format($rs['resolved']) ?></td></tr>
            <tr><td>No-show rate</td><td class="num"><?= $rs['no_show_rate'] !== null ? number_format($rs['no_show_rate'], 1) . '%' : 'N/A' ?></td></tr>
        </table>

        <?php /* Pending and cancelled are out of scope for this tab entirely
                 (see computeReservationStatusSummary()) -- 'seated' has never
                 been a real status this app uses. Only the three this tab
                 actually tracks get a row; the others would only ever read 0
                 and imply a distinction this report doesn't make. */ ?>
        <h2>Status Distribution</h2>
        <table class="doc-table">
            <thead><tr><th>Status</th><th class="num">Count</th></tr></thead>
            <tbody><?php foreach (['confirmed', 'completed', 'no_show'] as $status): ?>
                <tr><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $status))) ?></td><td class="num"><?= number_format($rs[$status] ?? 0) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>

        <?php // Real deposit accounting, from the same helper the Sales report
              // uses -- replacing two adoption/collection-rate figures that were
              // never computed anywhere. ?>
        <h2>Deposit Collection</h2>
        <?php $rdep = $d['deposits']; ?>
        <table class="doc-table">
            <tr><td>Redeemed against a bill</td><td class="num">&#8369;<?= number_format($rdep['redeemed'], 2) ?></td></tr>
            <tr><td>Forfeited &mdash; no-show / cancelled (income)</td><td class="num">&#8369;<?= number_format($rdep['forfeited'], 2) ?></td></tr>
            <tr><td>Still held (liability)</td><td class="num">&#8369;<?= number_format($rdep['held'], 2) ?></td></tr>
            <tr><td><strong>Total collected</strong></td><td class="num"><strong>&#8369;<?= number_format($rdep['collected'], 2) ?></strong></td></tr>
        </table>

    <?php // `stock_status_counts` and `wastage` were never returned by
          // buildInventoryTabData() (the real keys are `stock_summary` and
          // `wastage_by_reason`), and `supplier_spend` is not computed on this
          // tab at all -- so the Supplier Spending table always printed its
          // empty state. The section now reports what the tab really has. ?>
    <?php elseif ($tab === 'inventory'): ?>
        <?php
        $ss = $d['stock_summary'];
        $wasteCost = array_sum(array_map(fn($r) => (float)$r['cost'], $d['wastage_by_reason'] ?? []));
        ?>
        <h2>KPI Summary</h2>
        <table class="doc-table">
            <tr><td>Inventory value (as of today)</td><td class="num">&#8369;<?= number_format((float)$d['inventory_value'], 2) ?></td></tr>
            <tr><td>Items tracked</td><td class="num"><?= number_format($ss['total_items'] ?? 0) ?></td></tr>
            <tr><td>Low stock items</td><td class="num"><?= number_format($ss['low'] ?? 0) ?></td></tr>
            <tr><td>Critical stock items</td><td class="num"><?= number_format($ss['critical'] ?? 0) ?></td></tr>
            <tr><td>Out of stock</td><td class="num"><?= number_format($ss['out'] ?? 0) ?></td></tr>
            <tr><td>Wastage cost</td><td class="num">&#8369;<?= number_format($wasteCost, 2) ?></td></tr>
        </table>

        <h2>Wastage by Reason</h2>
        <?php if (empty($d['wastage_by_reason'])): ?><div class="empty-note">No wastage recorded in this period.</div><?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Reason</th><th class="num">Records</th><th class="num">Cost</th></tr></thead>
            <tbody><?php foreach ($d['wastage_by_reason'] as $row): ?>
                <tr><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $row['reason']))) ?></td><td class="num"><?= number_format((int)$row['n']) ?></td><td class="num">&#8369;<?= number_format((float)$row['cost'], 2) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>

        <h2>Most Consumed Ingredients</h2>
        <?php if (empty($d['most_consumed'])): ?><div class="empty-note">No ingredient consumption in this period.</div><?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Ingredient</th><th class="num">Quantity Used</th></tr></thead>
            <tbody><?php foreach (array_slice($d['most_consumed'], 0, 15) as $row): ?>
                <tr><td><?= htmlspecialchars($row['item_name']) ?></td><td class="num"><?= number_format((float)$row['qty'], 3) ?> <?= htmlspecialchars($row['unit_code'] ?? '') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>

        <h2>Fast / Slow Moving Ingredients</h2>
        <table class="doc-table">
            <thead><tr><th>Fast Moving</th><th class="num">Used</th><th>Slow Moving</th><th class="num">Used</th></tr></thead>
            <tbody><?php for ($i = 0; $i < 10; $i++): $f = $d['ingredient_movement']['fast_moving'][$i] ?? null; $s = $d['ingredient_movement']['slow_moving'][$i] ?? null; ?>
                <tr>
                    <td><?= $f ? htmlspecialchars($f['item_name']) : '' ?></td><td class="num"><?= $f ? number_format($f['qty_out'], 1) : '' ?></td>
                    <td><?= $s ? htmlspecialchars($s['item_name']) : '' ?></td><td class="num"><?= $s ? number_format($s['qty_out'], 1) : '' ?></td>
                </tr>
            <?php endfor; ?></tbody>
        </table>

    <?php endif; ?>

    <div class="footer">Generated <?= htmlspecialchars(date('M j, Y g:i A')) ?> &mdash; <?= htmlspecialchars($restaurantName) ?></div>
</div>

<?php if ($includeActions): ?>
<div class="pdf-actions">
    <button type="button" class="primary" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>
    <a href="?tab=<?= htmlspecialchars($tab) ?>&<?= htmlspecialchars($queryString) ?>&download=1"><i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF</a>
    <a href="analytics.php?tab=<?= htmlspecialchars($tab) ?>&<?= htmlspecialchars($queryString) ?>"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back</a>
</div>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}
