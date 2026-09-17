<?php
/**
 * manager/includes/dashboard_functions.php
 *
 * Data layer for manager/dashboard.php -- a true operational dashboard
 * (live KPIs, trend charts, stock/payroll widgets, alerts, recent
 * activity), not a module launcher (that's what the sidebar is for).
 * Same rationale/reuse conventions as owner/includes/dashboard_functions.php:
 * every widget reuses the exact query its source module already uses.
 * getTodayOrdersSummary()/getTodayReservationBuckets()/getInventoryStock
 * Attention()/getTodayReservationsList()/getTodayOrdersList() are
 * deliberately re-implemented here rather than shared with the owner
 * copy -- small local queries, and this app's established convention is
 * per-module-folder copies for those rather than cross-top-level requires
 * (see owner/includes/sales_analytics_functions.php's own docblock and
 * the cross-module-collision history) -- also, cross-requiring owner's
 * dashboard_functions.php directly would fatal on "Cannot redeclare
 * dashboardKpiCard()" since both files define their own copy of it.
 * buildAttendanceWeeklyTrend() (from
 * employee_management/includes/performance_monitoring_functions.php) is the opposite
 * case -- a canonical, single-source, stateless query builder with no
 * per-panel copy anywhere else in the app, so it's reused directly
 * rather than re-derived.
 */

require_once __DIR__ . '/../../config/sales_definitions.php'; // SALE_PREDICATE, SALE_DATE_EXPR
require_once __DIR__ . '/../../config/inventory_alerts.php';   // sweepInventoryStockAlerts()
require_once __DIR__ . '/../../config/payroll_alerts.php';     // sweepPayrollCutoffAlerts()
require_once __DIR__ . '/../../config/attendance_alerts.php';  // sweepAttendanceThresholdAlerts()
require_once __DIR__ . '/../../orders/includes/order_functions.php'; // orderTypeLabel(), for the Today's Orders widget
require_once __DIR__ . '/../../employee_management/includes/payroll_functions.php'; // payrollRunStatusBadgeClass()/payrollRunStatusLabel(), for the Payroll Runs Awaiting Approval widget
require_once __DIR__ . '/../../employee_management/includes/performance_monitoring_functions.php'; // buildAttendanceWeeklyTrend() -- only requires payroll_functions.php itself, already loaded above

/** Matches reportKpiCard()'s markup exactly -- per-module copy, same convention as owner/includes/dashboard_functions.php's dashboardKpiCard(). Does not escape $meta. */
function dashboardKpiCard(string $label, string $value, string $meta = '', string $icon = 'ph-chart-bar'): string
{
    return '<div class="owner-summary-card"><div style="flex:1;min-width:0;">'
        . '<div class="owner-summary-card-label">' . htmlspecialchars($label) . '</div>'
        . '<div class="owner-summary-card-value">' . $value . '</div>'
        . ($meta !== '' ? '<div class="owner-summary-card-meta">' . $meta . '</div>' : '')
        . '</div><i class="ph ' . htmlspecialchars($icon) . '" style="font-size:1.6rem;color:var(--op-gold);flex-shrink:0;" aria-hidden="true"></i></div>';
}

/**
 * Today's paid orders, using config/sales_definitions.php's SALE_PREDICATE so
 * this tile agrees with the owner's dashboard and the Sales report.
 *
 * Returns BOTH figures because they are genuinely different questions and the
 * tile should not silently pick one: `billed` is VAT-inclusive (what customers
 * were charged, and what the order list shows), `net_sales` strips the VAT the
 * restaurant is only holding for the BIR.
 */
function getTodayOrdersSummary(PDO $db): array
{
    $row = $db->query(
        "SELECT COUNT(DISTINCT o.order_id) AS cnt,
                COALESCE(SUM(o.total_amount), 0) AS billed,
                COALESCE(SUM(o.vat_amount), 0)   AS vat
         FROM orders o JOIN order_payments op ON op.order_id = o.order_id
         WHERE " . SALE_PREDICATE . " AND DATE(" . SALE_DATE_EXPR . ") = CURDATE()"
    )->fetch(PDO::FETCH_ASSOC);

    $billed = (float)$row['billed'];
    $vat    = (float)$row['vat'];

    return [
        'count'     => (int)$row['cnt'],
        'billed'    => round($billed, 2),
        'vat'       => round($vat, 2),
        'net_sales' => round($billed - $vat, 2),
        // Kept so nothing reading the old key breaks; points at the VAT-
        // inclusive figure it always meant.
        'revenue'   => round($billed, 2),
    ];
}

/** Today's reservations by status, same bucket shape reservation/reservations.php's own inline $bucketCounts uses. Caller must sweep holds/no-shows first. */
function getTodayReservationBuckets(PDO $db): array
{
    $buckets = ['pending' => 0, 'confirmed' => 0, 'seated' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
    $stmt = $db->query("SELECT status, COUNT(*) AS cnt FROM reservations WHERE reservation_date = CURDATE() GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $buckets[$row['status']] = (int)$row['cnt'];
    }
    $buckets['total'] = array_sum($buckets);
    return $buckets;
}

/** Low/critical/out-of-stock breakdown, same inventory_stock_status view report_functions.php reads. */
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

/** Today's reservations, same JOIN shape owner/includes/dashboard_functions.php's getTodayReservationsList() uses -- kept as its own local copy per this file's established per-module-copy convention (see module docblock) rather than a cross-require (which would also collide on dashboardKpiCard()). */
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

/** Today's orders, same local-copy convention as getTodayReservationsList() above. payment_status (pending/paid/failed) is the real status field -- orders.order_status is never used, same reasoning as orders/includes/order_functions.php's docblock. */
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

/**
 * Weekly Present/Late/Absent/Leave trend for the card's own period dropdown
 * on manager/dashboard.php -- reuses resolveAttendanceReportRange() and
 * buildAttendanceWeeklyTrend() from performance_monitoring.php as-is
 * (same period keys, same 'last30' default that page itself defaults to).
 */
function getWeeklyAttendanceTrend(PDO $db, string $period = 'last30'): array
{
    [$start, $end] = resolveAttendanceReportRange($period, null, null);
    return buildAttendanceWeeklyTrend($db, $start, $end, null, null);
}

/** POs currently ordered/partially received -- what's incoming and needs receiving. Same local-copy convention as owner's getReceivingPurchaseOrders(). */
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

/** Payroll run status counts + the most recent run, mirrors runs.php:59-71. */
function getPayrollRunsSummary(PDO $db): array
{
    $statusCounts = ['draft' => 0, 'pending_approval' => 0, 'approved' => 0, 'released' => 0];
    $stmt = $db->query("SELECT status, COUNT(*) AS cnt FROM payroll_runs GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($statusCounts[$row['status']])) {
            $statusCounts[$row['status']] = (int)$row['cnt'];
        }
    }

    $latest = $db->query(
        "SELECT run_number, pay_frequency, cutoff_period_start, cutoff_period_end, payout_date, status, total_net_pay
         FROM payroll_runs ORDER BY payroll_run_id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    return ['status_counts' => $statusCounts, 'latest_run' => $latest ?: null];
}

/** The actual runs sitting in 'pending_approval' right now -- real, actionable rows (run number, period, payout date, net pay) for the Payroll Runs Awaiting Approval widget, not just the KPI count. Mirrors runs.php's own list query/ordering. */
function getPayrollRunsAwaitingApproval(PDO $db, int $limit = 5): array
{
    $stmt = $db->prepare(
        "SELECT payroll_run_id, run_number, pay_frequency, cutoff_period_start, cutoff_period_end, payout_date, total_net_pay
         FROM payroll_runs
         WHERE status = 'pending_approval'
         ORDER BY payout_date ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Assembles everything manager/dashboard.php renders. Runs the same 4
 * sweeps manager/includes/header.php runs (lines 39-50) *before* reading
 * notifications, for the same reason owner's dashboard does -- header.php's
 * own sweep call happens later in render order. Each sweep is self-
 * throttled/deduped, so a second run from header.php moments later is a
 * harmless no-op.
 */
function buildManagerDashboardData(PDO $db, string $attendanceTrendPeriod = 'last30'): array
{
    // These sweeps aren't just for a notifications panel -- they keep the
    // widgets below accurate (e.g. the no-show sweep affects Today's
    // Reservations, the inventory sweep affects Stock levels), so they
    // stay even though the dashboard no longer surfaces a dedicated
    // "Needs your attention" alerts panel itself (the topbar bell already
    // covers that).
    try { sweepInventoryStockAlerts($db); } catch (Throwable $e) { error_log('dashboard sweepInventoryStockAlerts failed: ' . $e->getMessage()); }
    try { sweepPayrollCutoffAlerts($db); } catch (Throwable $e) { error_log('dashboard sweepPayrollCutoffAlerts failed: ' . $e->getMessage()); }
    try { sweepAttendanceThresholdAlerts($db); } catch (Throwable $e) { error_log('dashboard sweepAttendanceThresholdAlerts failed: ' . $e->getMessage()); }

    require_once __DIR__ . '/../../customer/includes/reservation_functions.php'; // sweepExpiredReservationHolds(), sweepNoShowReservations(), getReservationSettings()
    $resSettings = getReservationSettings($db);
    sweepExpiredReservationHolds($db, (int)$resSettings['reservation_hold_minutes']);
    sweepNoShowReservations($db, (int)$resSettings['reservation_no_show_hours']);

    return [
        'today_orders'       => getTodayOrdersSummary($db),
        'today_reservations' => getTodayReservationBuckets($db),
        'inventory_attention' => getInventoryStockAttention($db),
        'payroll_runs'       => getPayrollRunsSummary($db),
        'payroll_runs_awaiting' => getPayrollRunsAwaitingApproval($db),
        'receiving_pos'      => getReceivingPurchaseOrders($db),
        'today_reservations_list' => getTodayReservationsList($db),
        'today_orders_list'  => getTodayOrdersList($db),
        'weekly_attendance_trend' => getWeeklyAttendanceTrend($db, $attendanceTrendPeriod),
    ];
}
