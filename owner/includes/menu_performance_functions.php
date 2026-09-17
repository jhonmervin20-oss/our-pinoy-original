<?php
/**
 * owner/includes/menu_performance_functions.php
 *
 * Data Analytics dashboard (owner/menu_performance.php) -- fills what was a
 * dead nav link ("Data analytics" in owner/includes/sidebar.php already
 * pointed here). Deliberately does NOT re-show owner/report.php's existing
 * revenue-over-time chart or owner/demand_forecast.php's point-in-time
 * Fast/Slow/ABC classifications -- every section here is either a genuinely
 * new angle (peak times, order composition, reservation trend, category
 * trend, payment mix trend) or the same metric report.php only shows as a
 * single static number, turned into a real trend.
 *
 * Reuses demand_forecast_functions.php's denseDailyMap()/rollUpByGranularity()
 * (day-by-day PHP DateTime bucketing, not SQL DATE_FORMAT/YEARWEEK, to avoid
 * ISO week-boundary edge cases) and its existing getDailyDenseRevenueHistory()/
 * getDailyDenseOrderCountHistory() rather than re-deriving "what counts as a
 * completed sale" a second time -- same payment_status = 'paid' definition
 * used everywhere else in this app.
 *
 * "Repeat Customers" is deliberately the one small, honestly-scoped section
 * here rather than a full customer-segmentation suite -- confirmed directly
 * that only ~23% of orders carry a customer_id (most are walk-in/counter
 * sales with no account), so a bigger customer-analytics section would
 * mostly be fabricated noise on this data.
 */

require_once __DIR__ . '/demand_forecast_functions.php';

/** Small KPI card, matching owner/includes/report_functions.php's reportKpiCard() markup exactly but kept as its own copy (per-module convention) rather than a cross-module require. */
function mpKpiCard(string $label, string $value, string $meta = '', string $icon = 'ph-chart-bar'): string
{
    return '<div class="owner-summary-card"><div>'
        . '<div class="owner-summary-card-label">' . htmlspecialchars($label) . '</div>'
        . '<div class="owner-summary-card-value">' . $value . '</div>'
        . ($meta !== '' ? '<div class="df-kpi-meta">' . htmlspecialchars($meta) . '</div>' : '')
        . '</div><i class="ph ' . htmlspecialchars($icon) . '" style="font-size:1.6rem;color:var(--op-gold);" aria-hidden="true"></i></div>';
}

/** Sparse day=>count map for orders matching one extra SQL condition (dine_in vs takeout, walk-in vs reservation-linked, one payment method, etc.). */
function getDailyDenseOrderCountByCondition(PDO $db, DateTime $start, DateTime $end, string $extraWhereSql, array $extraParams = []): array
{
    $stmt = $db->prepare(
        "SELECT DATE(COALESCE(op.paid_at, op.created_at)) AS d, COUNT(DISTINCT o.order_id) AS cnt
         FROM orders o
         JOIN order_payments op ON op.order_id = o.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided'
           AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) <= ?
           AND {$extraWhereSql}
         GROUP BY d"
    );
    $stmt->execute(array_merge([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')], $extraParams));

    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sparse[$row['d']] = (float)$row['cnt'];
    }
    return denseDailyMap($sparse, $start, $end);
}

/** Merges several bucket=>value series (same bucket keys, from rollUpByGranularity()) into one bucket=>[series_name=>value] grid, e.g. for stacked charts. */
function mergeSeriesIntoGrid(array $namedSeries): array
{
    if (empty($namedSeries)) {
        return [];
    }
    $buckets = array_keys(reset($namedSeries));
    $grid = [];
    foreach ($buckets as $bucket) {
        foreach ($namedSeries as $name => $series) {
            $grid[$bucket][$name] = $series[$bucket] ?? 0;
        }
    }
    return $grid;
}

// ---------------------------------------------------------------------
// 1. Order Volume & Value Trend
// ---------------------------------------------------------------------

/** Order count + revenue + AOV per bucket -- the count/AOV angle report.php's Sales tab doesn't show (it only charts revenue). */
function buildOrderVolumeTrend(PDO $db, string $granularity, DateTime $start, DateTime $end): array
{
    $revenue = rollUpByGranularity(getDailyDenseRevenueHistory($db, $start, $end), $granularity);
    $orders  = rollUpByGranularity(getDailyDenseOrderCountHistory($db, $start, $end), $granularity);

    $result = [];
    foreach ($orders as $bucket => $count) {
        $rev = $revenue[$bucket] ?? 0.0;
        $result[$bucket] = [
            'order_count'     => $count,
            'revenue'         => $rev,
            'avg_order_value' => $count > 0 ? $rev / $count : 0.0,
        ];
    }
    return $result;
}

// ---------------------------------------------------------------------
// 2. Peak Times -- real pattern (day-of-week / hour-of-day), not bucketed by the period's granularity
// ---------------------------------------------------------------------

function buildPeakTimesData(PDO $db, DateTime $start, DateTime $end): array
{
    $dayLabels = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
    $byDayOfWeek = array_fill_keys($dayLabels, 0);

    $dowStmt = $db->prepare(
        "SELECT DAYOFWEEK(COALESCE(op.paid_at, op.created_at)) AS dow, COUNT(DISTINCT o.order_id) AS cnt
         FROM orders o JOIN order_payments op ON op.order_id = o.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided'
           AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) <= ?
         GROUP BY dow"
    );
    $dowStmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    foreach ($dowStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDayOfWeek[$dayLabels[(int)$row['dow']]] = (int)$row['cnt'];
    }

    $byHour = array_fill(0, 24, 0);
    $hourStmt = $db->prepare(
        "SELECT HOUR(COALESCE(op.paid_at, op.created_at)) AS hr, COUNT(DISTINCT o.order_id) AS cnt
         FROM orders o JOIN order_payments op ON op.order_id = o.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided'
           AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) <= ?
         GROUP BY hr"
    );
    $hourStmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    foreach ($hourStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byHour[(int)$row['hr']] = (int)$row['cnt'];
    }

    return ['by_day_of_week' => $byDayOfWeek, 'by_hour' => $byHour];
}

// ---------------------------------------------------------------------
// 3. Order Composition Trend -- dine-in/takeout, walk-in/reservation-linked
// ---------------------------------------------------------------------

function buildOrderCompositionTrend(PDO $db, string $granularity, DateTime $start, DateTime $end): array
{
    $type = mergeSeriesIntoGrid([
        'dine_in' => rollUpByGranularity(getDailyDenseOrderCountByCondition($db, $start, $end, "o.order_type = 'dine_in'"), $granularity),
        'takeout' => rollUpByGranularity(getDailyDenseOrderCountByCondition($db, $start, $end, "o.order_type = 'takeout'"), $granularity),
    ]);
    $source = mergeSeriesIntoGrid([
        'walk_in'     => rollUpByGranularity(getDailyDenseOrderCountByCondition($db, $start, $end, 'o.reservation_id IS NULL'), $granularity),
        'reservation' => rollUpByGranularity(getDailyDenseOrderCountByCondition($db, $start, $end, 'o.reservation_id IS NOT NULL'), $granularity),
    ]);

    return ['by_type' => $type, 'by_source' => $source];
}

// ---------------------------------------------------------------------
// 4. Reservation Trend -- the biggest real gap: no trend view exists anywhere today
// ---------------------------------------------------------------------

/** Sparse day=>count map for reservations, keyed by reservation_date (when the visit happens, not when it was booked) matching one extra SQL condition. */
function getDailyDenseReservationCountByCondition(PDO $db, DateTime $start, DateTime $end, string $extraWhereSql = '1=1', array $extraParams = []): array
{
    $stmt = $db->prepare(
        "SELECT reservation_date AS d, COUNT(*) AS cnt
         FROM reservations
         WHERE reservation_date >= ? AND reservation_date <= ? AND {$extraWhereSql}
         GROUP BY d"
    );
    $stmt->execute(array_merge([$start->format('Y-m-d'), $end->format('Y-m-d')], $extraParams));

    $sparse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sparse[$row['d']] = (float)$row['cnt'];
    }
    return denseDailyMap($sparse, $start, $end);
}

function buildReservationTrend(PDO $db, string $granularity, DateTime $start, DateTime $end): array
{
    $volume = rollUpByGranularity(getDailyDenseReservationCountByCondition($db, $start, $end), $granularity);

    $statuses = ['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'];
    $statusSeries = [];
    foreach ($statuses as $status) {
        $statusSeries[$status] = rollUpByGranularity(
            getDailyDenseReservationCountByCondition($db, $start, $end, 'status = ?', [$status]),
            $granularity
        );
    }

    return [
        'volume'      => $volume,
        'by_status'   => mergeSeriesIntoGrid($statusSeries),
    ];
}

// ---------------------------------------------------------------------
// 5. Category Performance Trend
// ---------------------------------------------------------------------

function buildCategoryRevenueTrend(PDO $db, string $granularity, DateTime $start, DateTime $end): array
{
    $categories = $db->query("SELECT category_id, category_name FROM menu_categories ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($categories)) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT DATE(COALESCE(op.paid_at, op.created_at)) AS d, SUM(oi.subtotal) AS revenue
         FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         JOIN menu_items mi ON mi.item_id = oi.menu_item_id
         WHERE mi.category_id = ? AND op.payment_status = 'paid' AND o.order_status <> 'voided' AND oi.status != 'cancelled'
           AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) <= ?
         GROUP BY d"
    );

    $series = [];
    foreach ($categories as $cat) {
        $stmt->execute([$cat['category_id'], $start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
        $sparse = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sparse[$row['d']] = (float)$row['revenue'];
        }
        $series[$cat['category_name']] = rollUpByGranularity(denseDailyMap($sparse, $start, $end), $granularity);
    }

    return mergeSeriesIntoGrid($series);
}

// ---------------------------------------------------------------------
// 6. Payment Method Mix Trend
// ---------------------------------------------------------------------

function buildPaymentMethodTrend(PDO $db, string $granularity, DateTime $start, DateTime $end): array
{
    // Voided orders excluded here too, same as every other query in this file --
    // a voided order's payment row stays 'paid' (voiding is tracked entirely on
    // orders.order_status, not reversed on order_payments), so this needs its
    // own join to orders rather than reading order_payments alone.
    $methods = $db->query(
        "SELECT DISTINCT op.payment_method
         FROM order_payments op JOIN orders o ON o.order_id = op.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (empty($methods)) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT DATE(COALESCE(op.paid_at, op.created_at)) AS d, COUNT(*) AS cnt
         FROM order_payments op
         JOIN orders o ON o.order_id = op.order_id
         WHERE op.payment_method = ? AND op.payment_status = 'paid' AND o.order_status <> 'voided'
           AND COALESCE(op.paid_at, op.created_at) >= ? AND COALESCE(op.paid_at, op.created_at) <= ?
         GROUP BY d"
    );

    $series = [];
    foreach ($methods as $method) {
        $stmt->execute([$method, $start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
        $sparse = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sparse[$row['d']] = (float)$row['cnt'];
        }
        $series[$method] = rollUpByGranularity(denseDailyMap($sparse, $start, $end), $granularity);
    }

    return mergeSeriesIntoGrid($series);
}

/** Human label for order_payments.payment_method values. */
function mpPaymentMethodLabel(string $method): string
{
    return match ($method) {
        'cash'             => 'Cash',
        'paymongo_gcash'   => 'GCash',
        default            => ucfirst($method),
    };
}

// ---------------------------------------------------------------------
// 7. Repeat Customers -- honestly scoped, not a full segmentation suite
// ---------------------------------------------------------------------

function computeRepeatCustomerStats(PDO $db, DateTime $start, DateTime $end): array
{
    $rangeParams = [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];

    $totalOrders = (int)(function () use ($db, $rangeParams) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM orders o JOIN order_payments op ON op.order_id = o.order_id
             WHERE op.payment_status = 'paid' AND o.order_status <> 'voided'
               AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?"
        );
        $stmt->execute($rangeParams);
        return $stmt->fetchColumn();
    })();

    $ordersWithCustomer = (int)(function () use ($db, $rangeParams) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM orders o JOIN order_payments op ON op.order_id = o.order_id
             WHERE op.payment_status = 'paid' AND o.order_status <> 'voided' AND o.customer_id IS NOT NULL
               AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?"
        );
        $stmt->execute($rangeParams);
        return $stmt->fetchColumn();
    })();

    $repeatCustomerCount = (int)(function () use ($db, $rangeParams) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT o.customer_id FROM orders o JOIN order_payments op ON op.order_id = o.order_id
                WHERE op.payment_status = 'paid' AND o.order_status <> 'voided' AND o.customer_id IS NOT NULL
                  AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
                GROUP BY o.customer_id HAVING COUNT(*) >= 2
            ) t"
        );
        $stmt->execute($rangeParams);
        return $stmt->fetchColumn();
    })();

    return [
        'total_orders'                 => $totalOrders,
        'orders_with_customer'         => $ordersWithCustomer,
        'orders_without_customer_pct'  => $totalOrders > 0 ? round((($totalOrders - $ordersWithCustomer) / $totalOrders) * 100) : 0,
        'repeat_customer_count'        => $repeatCustomerCount,
    ];
}

// ---------------------------------------------------------------------
// 8. Printed summary report (menu_performance_pdf.php) -- ranked/summed
// totals rather than a per-bucket dump, since Dompdf can't run the
// interactive Chart.js charts and a 90-row daily table isn't "a summary".
// ---------------------------------------------------------------------

function renderMenuPerformanceReportHtml(array $data, bool $includeActions): string
{
    ob_start();
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<?php if ($includeActions): ?><meta name="viewport" content="width=device-width, initial-scale=1.0"><?php endif; ?>
<title>Data Analytics Report</title>
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
    <h1><?= htmlspecialchars($data['restaurant_name']) ?></h1>
    <div class="muted">Data Analytics Report &mdash; <?= htmlspecialchars($data['period_label']) ?></div>
    <div class="divider"></div>

    <h2>Overview</h2>
    <table class="doc-table">
        <tr><td>Total orders</td><td class="num"><?= number_format($data['total_orders']) ?></td></tr>
        <tr><td>Total revenue</td><td class="num">&#8369;<?= number_format($data['total_revenue'], 2) ?></td></tr>
        <tr><td>Average order value</td><td class="num">&#8369;<?= number_format($data['avg_order_value'], 2) ?></td></tr>
        <tr><td>Total reservations</td><td class="num"><?= number_format($data['total_reservations']) ?></td></tr>
    </table>

    <h2>Peak Times</h2>
    <?php $topDow = $data['top_day_of_week']; $topHour = $data['top_hour']; ?>
    <table class="doc-table">
        <tr><td>Busiest day of week</td><td class="num"><?= $topDow !== null ? htmlspecialchars($topDow['label']) . ' (' . number_format($topDow['count']) . ' orders)' : 'Not enough data' ?></td></tr>
        <tr><td>Busiest hour</td><td class="num"><?= $topHour !== null ? htmlspecialchars($topHour['label']) . ' (' . number_format($topHour['count']) . ' orders)' : 'Not enough data' ?></td></tr>
    </table>

    <h2>Order Composition</h2>
    <?php if ($data['total_orders'] === 0): ?>
        <div class="empty-note">No paid orders in this period.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Breakdown</th><th class="num">Orders</th></tr></thead>
            <tbody>
                <tr><td>Dine-in</td><td class="num"><?= number_format($data['composition_totals']['dine_in']) ?></td></tr>
                <tr><td>Takeout</td><td class="num"><?= number_format($data['composition_totals']['takeout']) ?></td></tr>
                <tr><td>Walk-in</td><td class="num"><?= number_format($data['composition_totals']['walk_in']) ?></td></tr>
                <tr><td>Reservation-linked</td><td class="num"><?= number_format($data['composition_totals']['reservation']) ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Reservation Summary</h2>
    <?php if ($data['total_reservations'] === 0): ?>
        <div class="empty-note">No reservations in this period.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Status</th><th class="num">Count</th></tr></thead>
            <tbody>
            <?php foreach ($data['reservation_status_totals'] as $status => $count): ?>
                <tr><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $status))) ?></td><td class="num"><?= number_format($count) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <table class="doc-table">
            <thead><tr><th>Source</th><th class="num">Count</th></tr></thead>
            <tbody>
            <?php foreach ($data['reservation_source_totals'] as $source => $count): ?>
                <tr><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $source))) ?></td><td class="num"><?= number_format($count) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Category Revenue Ranking</h2>
    <?php if (empty($data['category_totals'])): ?>
        <div class="empty-note">No category revenue in this period.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>#</th><th>Category</th><th class="num">Revenue</th></tr></thead>
            <tbody>
            <?php foreach ($data['category_totals'] as $i => $row): ?>
                <tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($row['name']) ?></td><td class="num">&#8369;<?= number_format($row['revenue'], 2) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Payment Method Mix</h2>
    <?php if (empty($data['payment_totals'])): ?>
        <div class="empty-note">No paid orders in this period.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Method</th><th class="num">Orders</th></tr></thead>
            <tbody>
            <?php foreach ($data['payment_totals'] as $row): ?>
                <tr><td><?= htmlspecialchars($row['label']) ?></td><td class="num"><?= number_format($row['count']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Repeat Customers</h2>
    <table class="doc-table">
        <tr><td>Orders with identified customer</td><td class="num"><?= number_format($data['repeat_stats']['orders_with_customer']) ?> of <?= number_format($data['repeat_stats']['total_orders']) ?></td></tr>
        <tr><td>Repeat customers (2+ orders)</td><td class="num"><?= number_format($data['repeat_stats']['repeat_customer_count']) ?></td></tr>
        <tr><td>Orders without a customer identity</td><td class="num"><?= (int)$data['repeat_stats']['orders_without_customer_pct'] ?>%</td></tr>
    </table>

    <div class="footer">Generated <?= htmlspecialchars(date('M j, Y g:i A')) ?> &mdash; <?= htmlspecialchars($data['restaurant_name']) ?></div>
</div>

<?php if ($includeActions): ?>
<div class="pdf-actions">
    <button type="button" class="primary" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>
    <a href="?<?= htmlspecialchars($data['query_string']) ?>&download=1"><i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF</a>
    <a href="menu_performance.php?<?= htmlspecialchars($data['query_string']) ?>"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back</a>
</div>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}
