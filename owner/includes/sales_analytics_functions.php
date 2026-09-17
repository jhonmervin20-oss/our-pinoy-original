<?php
/**
 * owner/includes/sales_analytics_functions.php
 *
 * Sales Analytics dashboard (owner/sales_analytics.php) -- first of the
 * 4-dashboard "Restaurant Data Analytics" spec, built to genuinely add
 * only what doesn't already exist. Requires demand_forecast_functions.php
 * and menu_performance_functions.php directly (same owner/includes/
 * directory -- this app's established convention is to cross-require
 * within owner/includes/ itself, e.g. menu_performance_functions.php
 * already requires demand_forecast_functions.php; copies are only used
 * to avoid cross-TOP-LEVEL-module requires like owner/ <-> employee_management/).
 *
 * Reused as-is from those two files (not re-derived here): resolvePeriodRange(),
 * previousPeriodRange(), periodLabel(), rollUpByGranularity(),
 * getDailyDenseRevenueHistory(), buildPeakTimesData(), buildCategoryRevenueTrend(),
 * buildFastMovingQuery()/attachGrowthMetrics()/fetchItemQuantitiesForRange(),
 * buildSlowMovingQuery()/isSlowMoving(), mpKpiCard(), mpPaymentMethodLabel().
 *
 * Revenue terminology now comes from config/sales_definitions.php and follows
 * the accounting meanings, where each step DOWN the statement is smaller than
 * the last:
 *
 *     Gross sales        item line totals, before any discount
 *   - Discounts
 *   = Net sales (incl. VAT)
 *   - Output VAT         collected for the BIR, not earnings
 *   = NET SALES (excl. VAT)   <- "revenue" on a P&L, and this page's headline
 *   + Packaging + VAT
 *   = Total billed       == SUM(orders.total_amount)
 *
 * This replaces an earlier convention in which "Net Revenue"
 * (SUM(orders.total_amount)) was LARGER than "Gross Revenue"
 * (SUM(order_items.subtotal)), because "net" actually meant the VAT-inclusive
 * amount billed. Nothing on this page reads orders.subtotal any more either:
 * that column is VAT-inclusive on 2,040 seeded rows and net on the rest, so
 * summing it was wrong for most of the table.
 */

require_once __DIR__ . '/../../config/sales_definitions.php'; // SALE_PREDICATE, buildSalesStatement()
require_once __DIR__ . '/demand_forecast_functions.php';
require_once __DIR__ . '/menu_performance_functions.php';

// ---------------------------------------------------------------------
// KPI summary
// ---------------------------------------------------------------------

/**
 * Headline sales figures for a period, delegating the whole revenue
 * decomposition to config/sales_definitions.php's buildSalesStatement().
 *
 * This used to run its own two SUMs and label the results "gross_revenue" and
 * "net_revenue" -- with net (SUM of order totals) coming out LARGER than gross
 * (SUM of line subtotals), because "net" was really the VAT-inclusive amount
 * billed. Revenue that grows as you net things off it is not a figure anyone
 * can defend, so the meanings are now the accounting ones and every surface
 * reading this gets the same numbers the Sales report prints.
 */
function computeSalesKpiSummary(PDO $db, DateTime $start, DateTime $end): array
{
    $s = buildSalesStatement($db, $start, $end);

    $itemStmt = $db->prepare(
        "SELECT COALESCE(SUM(oi.quantity), 0) AS total_items_sold
         FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         WHERE " . SALE_PREDICATE . " AND " . SALE_ITEM_PREDICATE . "
           AND " . SALE_DATE_EXPR . " BETWEEN ? AND ?"
    );
    $itemStmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    $totalItemsSold = (float)$itemStmt->fetchColumn();

    $totalOrders = $s['order_count'];

    return [
        'total_orders'      => $totalOrders,
        'total_items_sold'  => $totalItemsSold,
        'avg_basket_size'   => $totalOrders > 0 ? $totalItemsSold / $totalOrders : 0.0,

        // The statement, in accounting order.
        'gross_sales'       => $s['gross_sales'],
        'discounts'         => $s['discounts'],
        'net_sales_vat_inc' => $s['net_sales_vat_inc'],
        'vat'               => $s['vat'],
        'net_sales_vat_exc' => $s['net_sales_vat_exc'],
        'total_collected'   => $s['total_collected'],
        'vatable_sales'     => $s['vatable_sales'],
        'vat_exempt_sales'  => $s['vat_exempt_sales'],

        // Average order value = what a customer actually paid, so it is taken
        // from the amount billed rather than from pre-discount menu value.
        'aov'               => $totalOrders > 0 ? $s['total_collected'] / $totalOrders : 0.0,

        // Legacy key names, kept so no caller silently reads a missing index --
        // but pointed at the CORRECT figures now. 'net_revenue' is the P&L
        // revenue line (excludes VAT); it used to be the VAT-inclusive total.
        'gross_revenue'     => $s['gross_sales'],
        'net_revenue'       => $s['net_sales_vat_exc'],

        'statement'         => $s,
    ];
}

/** "+12.3% vs previous period" / "-4.0% vs previous period" -- relative % change, for revenue/count-style KPIs (unlike attendance's point-based delta, these aren't already percentages). */
function salesDeltaMeta(float $current, float $previous): string
{
    if ($previous <= 0) {
        return $current > 0
            ? '<span style="color:var(--op-success);"><i class="ph ph-sparkle" aria-hidden="true"></i> New this period</span>'
            : 'No comparable data for the previous period';
    }
    $deltaPct = (($current - $previous) / $previous) * 100;
    $tone = $deltaPct >= 0 ? 'var(--op-success)' : 'var(--op-danger)';
    $icon = $deltaPct >= 0 ? 'ph-trend-up' : 'ph-trend-down';
    return '<span style="color:' . $tone . ';"><i class="ph ' . $icon . '" aria-hidden="true"></i> '
        . ($deltaPct >= 0 ? '+' : '') . number_format($deltaPct, 1) . '%</span> vs previous period';
}

// ---------------------------------------------------------------------
// Discount usage
// ---------------------------------------------------------------------

function computeDiscountUsage(PDO $db, DateTime $start, DateTime $end): array
{
    $range = [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];

    $totalStmt = $db->prepare(
        "SELECT COUNT(*) AS total_orders,
                SUM(CASE WHEN o.discount_amount > 0 THEN 1 ELSE 0 END) AS discounted_count,
                COALESCE(SUM(o.discount_amount), 0) AS total_discount_amount
         FROM orders o JOIN order_payments op ON op.order_id = o.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided' AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?"
    );
    $totalStmt->execute($range);
    $totals = $totalStmt->fetch(PDO::FETCH_ASSOC);

    $breakdownStmt = $db->prepare(
        "SELECT o.discount_name, COUNT(*) AS cnt, SUM(o.discount_amount) AS total_amount
         FROM orders o JOIN order_payments op ON op.order_id = o.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided' AND o.discount_amount > 0
           AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
         GROUP BY o.discount_name ORDER BY total_amount DESC"
    );
    $breakdownStmt->execute($range);

    $totalOrders     = (int)$totals['total_orders'];
    $discountedCount = (int)$totals['discounted_count'];

    return [
        'total_orders'          => $totalOrders,
        'discounted_count'      => $discountedCount,
        'discounted_pct'        => $totalOrders > 0 ? ($discountedCount / $totalOrders) * 100 : 0.0,
        'total_discount_amount' => (float)$totals['total_discount_amount'],
        'breakdown'             => $breakdownStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

// ---------------------------------------------------------------------
// Dine-in vs takeout revenue split
// ---------------------------------------------------------------------

function computeOrderTypeRevenueSplit(PDO $db, DateTime $start, DateTime $end): array
{
    $range = [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];
    $stmt = $db->prepare(
        "SELECT o.order_type, COALESCE(SUM(oi.subtotal), 0) AS revenue, COUNT(DISTINCT o.order_id) AS order_count
         FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided' AND oi.status != 'cancelled'
           AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
         GROUP BY o.order_type"
    );
    $stmt->execute($range);

    $result = ['dine_in' => ['revenue' => 0.0, 'order_count' => 0], 'takeout' => ['revenue' => 0.0, 'order_count' => 0]];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['order_type']] = ['revenue' => (float)$row['revenue'], 'order_count' => (int)$row['order_count']];
    }

    $total = $result['dine_in']['revenue'] + $result['takeout']['revenue'];
    $result['dine_in']['pct']   = $total > 0 ? ($result['dine_in']['revenue'] / $total) * 100 : 0.0;
    $result['takeout']['pct']   = $total > 0 ? ($result['takeout']['revenue'] / $total) * 100 : 0.0;

    return $result;
}

// ---------------------------------------------------------------------
// Payment method distribution (plain %-share snapshot, not a trend)
// ---------------------------------------------------------------------

function computePaymentMethodDistributionSnapshot(PDO $db, DateTime $start, DateTime $end): array
{
    $range = [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];
    $stmt = $db->prepare(
        "SELECT op.payment_method, COUNT(*) AS cnt, SUM(op.amount) AS total_amount
         FROM order_payments op
         JOIN orders o ON o.order_id = op.order_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided' AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
         GROUP BY op.payment_method ORDER BY cnt DESC"
    );
    $stmt->execute($range);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalCount = array_sum(array_column($rows, 'cnt'));

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'method'       => $row['payment_method'],
            'label'        => mpPaymentMethodLabel($row['payment_method']),
            'count'        => (int)$row['cnt'],
            'total_amount' => (float)$row['total_amount'],
            'pct'          => $totalCount > 0 ? ((int)$row['cnt'] / $totalCount) * 100 : 0.0,
        ];
    }
    return $result;
}

// ---------------------------------------------------------------------
// Revenue by category -- ranked table (Reports section), not a repeat of
// menu_performance.php's existing Category Performance Trend chart.
// ---------------------------------------------------------------------

/**
 * The single best-selling item within each category for the period, keyed by
 * category_name so it can be merged straight onto computeCategoryRevenueRanked()'s
 * rows. "Best selling" here is by QUANTITY sold -- that is what "best seller"
 * means to a kitchen -- with the item's revenue carried alongside so the two
 * measures can be read together (a category's top seller by volume is not
 * always its biggest earner).
 *
 * Added when the Menu Performance tab was retired: its per-category item view
 * was the one thing there that the Sales tab didn't already cover.
 */
function computeBestSellerPerCategory(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT c.category_name, mi.item_name,
                SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS revenue
         FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         JOIN menu_items mi ON mi.item_id = oi.menu_item_id
         LEFT JOIN menu_categories c ON c.category_id = mi.category_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided' AND oi.status != 'cancelled'
           AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
         GROUP BY c.category_id, c.category_name, mi.item_id, mi.item_name
         ORDER BY c.category_name, qty DESC"
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);

    // Ordered by qty DESC within each category, so the first row per category
    // is its winner -- no window function needed (MariaDB 10.4 here).
    $best = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['category_name'] ?? 'Uncategorized';
        if (!isset($best[$key])) {
            $best[$key] = [
                'item_name' => $row['item_name'],
                'qty'       => (float)$row['qty'],
                'revenue'   => (float)$row['revenue'],
            ];
        }
    }
    return $best;
}

function computeCategoryRevenueRanked(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT c.category_name, COALESCE(SUM(oi.subtotal), 0) AS revenue, COALESCE(SUM(oi.quantity), 0) AS qty
         FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         JOIN order_payments op ON op.order_id = o.order_id
         JOIN menu_items mi ON mi.item_id = oi.menu_item_id
         LEFT JOIN menu_categories c ON c.category_id = mi.category_id
         WHERE op.payment_status = 'paid' AND o.order_status <> 'voided' AND oi.status != 'cancelled'
           AND COALESCE(op.paid_at, op.created_at) BETWEEN ? AND ?
         GROUP BY c.category_id, c.category_name
         ORDER BY revenue DESC"
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRevenue = array_sum(array_column($rows, 'revenue'));
    foreach ($rows as &$row) {
        $row['pct'] = $totalRevenue > 0 ? ((float)$row['revenue'] / $totalRevenue) * 100 : 0.0;
    }
    unset($row);
    return $rows;
}

// ---------------------------------------------------------------------
// Printed summary report (sales_analytics_pdf.php)
// ---------------------------------------------------------------------

function renderSalesAnalyticsReportHtml(array $data, bool $includeActions): string
{
    ob_start();
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<?php if ($includeActions): ?><meta name="viewport" content="width=device-width, initial-scale=1.0"><?php endif; ?>
<title>Sales Analytics Report</title>
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
    <div class="muted">Sales Analytics Report &mdash; <?= htmlspecialchars($data['period_label']) ?></div>
    <div class="divider"></div>

    <h2>KPI Summary</h2>
    <table class="doc-table">
        <tr><td>Gross revenue</td><td class="num">&#8369;<?= number_format($data['kpi']['gross_revenue'], 2) ?></td></tr>
        <tr><td>Net revenue</td><td class="num">&#8369;<?= number_format($data['kpi']['net_revenue'], 2) ?></td></tr>
        <tr><td>Total orders</td><td class="num"><?= number_format($data['kpi']['total_orders']) ?></td></tr>
        <tr><td>Average order value</td><td class="num">&#8369;<?= number_format($data['kpi']['aov'], 2) ?></td></tr>
        <tr><td>Average basket size</td><td class="num"><?= number_format($data['kpi']['avg_basket_size'], 2) ?></td></tr>
    </table>

    <h2>Dine-in vs Takeout Revenue</h2>
    <table class="doc-table">
        <thead><tr><th>Type</th><th class="num">Revenue</th><th class="num">Orders</th><th class="num">Share</th></tr></thead>
        <tbody>
            <tr><td>Dine-in</td><td class="num">&#8369;<?= number_format($data['type_split']['dine_in']['revenue'], 2) ?></td><td class="num"><?= $data['type_split']['dine_in']['order_count'] ?></td><td class="num"><?= number_format($data['type_split']['dine_in']['pct'], 1) ?>%</td></tr>
            <tr><td>Takeout</td><td class="num">&#8369;<?= number_format($data['type_split']['takeout']['revenue'], 2) ?></td><td class="num"><?= $data['type_split']['takeout']['order_count'] ?></td><td class="num"><?= number_format($data['type_split']['takeout']['pct'], 1) ?>%</td></tr>
        </tbody>
    </table>

    <h2>Revenue by Category</h2>
    <?php if (empty($data['category_ranked'])): ?>
        <div class="empty-note">No category revenue in this period.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Category</th><th class="num">Units</th><th class="num">Revenue</th><th class="num">Share</th></tr></thead>
            <tbody>
            <?php foreach ($data['category_ranked'] as $row): ?>
                <tr><td><?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?></td><td class="num"><?= number_format($row['qty']) ?></td><td class="num">&#8369;<?= number_format($row['revenue'], 2) ?></td><td class="num"><?= number_format($row['pct'], 1) ?>%</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Top Selling Items</h2>
    <?php if (empty($data['fast_moving'])): ?>
        <div class="empty-note">No sales in this period.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>#</th><th>Item</th><th>Category</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($data['fast_moving'], 0, 15) as $i => $row): ?>
                <tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($row['item_name']) ?></td><td><?= htmlspecialchars($row['category_name'] ?? '') ?></td><td class="num"><?= number_format($row['total_qty']) ?></td><td class="num">&#8369;<?= number_format($row['total_revenue'], 2) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Discount Usage</h2>
    <table class="doc-table">
        <tr><td>Total discount amount</td><td class="num">&#8369;<?= number_format($data['discount_usage']['total_discount_amount'], 2) ?></td></tr>
        <tr><td>Orders discounted</td><td class="num"><?= $data['discount_usage']['discounted_count'] ?> of <?= $data['discount_usage']['total_orders'] ?> (<?= number_format($data['discount_usage']['discounted_pct'], 1) ?>%)</td></tr>
    </table>

    <h2>Payment Method Distribution</h2>
    <?php if (empty($data['payment_dist'])): ?>
        <div class="empty-note">No paid orders in this period.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead><tr><th>Method</th><th class="num">Orders</th><th class="num">Amount</th><th class="num">Share</th></tr></thead>
            <tbody>
            <?php foreach ($data['payment_dist'] as $row): ?>
                <tr><td><?= htmlspecialchars($row['label']) ?></td><td class="num"><?= number_format($row['count']) ?></td><td class="num">&#8369;<?= number_format($row['total_amount'], 2) ?></td><td class="num"><?= number_format($row['pct'], 1) ?>%</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="footer">Generated <?= htmlspecialchars(date('M j, Y g:i A')) ?> &mdash; <?= htmlspecialchars($data['restaurant_name']) ?></div>
</div>

<?php if ($includeActions): ?>
<div class="pdf-actions">
    <button type="button" class="primary" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>
    <a href="?<?= htmlspecialchars($data['query_string']) ?>&download=1"><i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF</a>
    <a href="sales_analytics.php?<?= htmlspecialchars($data['query_string']) ?>"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back</a>
</div>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}
