<?php
/**
 * owner/dashboard.php
 *
 * Landing page for the Owner panel -- a true operational dashboard, not a
 * module launcher (that's what owner/includes/sidebar.php is for). The KPI
 * strip stays on a Today snapshot -- these are meant to read as live,
 * current-day figures, not a period a manager has to interpret. Each of the
 * 4 trend charts carries its own small period dropdown in its card header
 * (defaulting to Last 7 days) instead of one shared global filter -- a
 * chart can be widened to spot a slower trend without dragging the other
 * three, or the KPI strip, along with it. Today's Reservations, Today's
 * Orders, Draft POs, and Receiving POs stay live/current-state regardless
 * (a live worklist, not a historical report); AI Forecast and Purchase
 * Recommendations are forward-looking, so they run over their own fixed
 * trailing-30-day window. See owner/includes/dashboard_functions.php's
 * module docblock for the full rationale and exactly which query each
 * widget reuses from its source module.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/dashboard_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'dashboard';
$pageTitle  = 'Dashboard';

$firstName = trim(explode(' ', Session::getFullName() ?: 'there')[0]);
$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// The KPI strip stays on a Today snapshot -- "Today's reservations" and the
// like are meant to read as live, current-day figures, and a week-over-week
// swing on "Net sales" reads as an alarming anomaly rather than the useful
// signal it is meant to be. Only the 4 trend charts default to Last 7 days.
$period = 'today';

// Each trend chart gets its own independent period, defaulting to Last 7
// days -- see the module docblock above for why this isn't one shared filter.
$trendPeriodOptions = [
    'today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 days',
    'last30' => 'Last 30 days', 'this_month' => 'This month', 'last_month' => 'Last month',
];
$validTrendPeriods = array_keys($trendPeriodOptions);
$readTrendPeriod = function (string $getKey) use ($validTrendPeriods): string {
    return in_array($_GET[$getKey] ?? '', $validTrendPeriods, true) ? $_GET[$getKey] : 'last7';
};
$salesPeriod = $readTrendPeriod('sales_period');
$resPeriod   = $readTrendPeriod('res_period');
$stockPeriod = $readTrendPeriod('stock_period');
$wastePeriod = $readTrendPeriod('waste_period');

$trendPeriodSelect = function (string $name, string $current) use ($trendPeriodOptions): string {
    $html = '<select name="' . $name . '" form="dashChartFilters" class="owner-select dash-chart-period" '
        . 'onchange="this.form.submit()" aria-label="Chart period">';
    foreach ($trendPeriodOptions as $key => $label) {
        $html .= '<option value="' . $key . '"' . ($current === $key ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
    return $html . '</select>';
};

$data    = [];
$dbError = null;

try {
    $pdo  = Database::getInstance()->getConnection();
    $data = buildOwnerDashboardData($pdo, $period, null, null);

    [$salesStart, $salesEnd] = resolvePeriodRange($salesPeriod, null, null);
    [$resStart, $resEnd]     = resolvePeriodRange($resPeriod, null, null);
    [$stockStart, $stockEnd] = resolvePeriodRange($stockPeriod, null, null);
    [$wasteStart, $wasteEnd] = resolvePeriodRange($wastePeriod, null, null);

    $revenueTrendOverride     = getNetRevenueTrendSeries($pdo, $salesStart, $salesEnd);
    $reservationTrendOverride = buildReservationTrend($pdo, 'day', $resStart, $resEnd)['volume'];
    $stockUsageTrendOverride  = getStockUsageTrendSeries($pdo, $stockStart, $stockEnd);
    $wastageTrendOverride     = getWastageTrendSeries($pdo, $wasteStart, $wasteEnd);
} catch (PDOException $e) {
    $dbError = "Couldn't load live dashboard data. Please refresh this page.";
    error_log('owner/dashboard.php failed: ' . $e->getMessage());
}

$rangeStart = $data['range_start'] ?? new DateTime('today');
$rangeEnd   = $data['range_end'] ?? new DateTime('today');

// Keys must mirror getRevenueKpi()'s full return shape -- the KPI tile below also
// reads total_collected for the "billed incl. VAT" line under the headline.
$revenueKpi    = $data['revenue_kpi'] ?? ['net_revenue' => 0.0, 'total_collected' => 0.0, 'total_orders' => 0, 'delta_html' => ''];
$stock         = $data['inventory_attention'] ?? ['critical' => 0, 'low' => 0, 'out_of_stock' => 0, 'total' => 0];
$reservationKpi = $data['reservation_kpi'] ?? ['total' => 0, 'confirmed' => 0, 'pending' => 0];
$receivingKpi  = $data['receiving_kpi'] ?? ['total' => 0, 'pending' => 0, 'completed' => 0];

// Each trend now reads from its own card's period selection above, falling
// back to the shared (Last 7 days) build if that per-chart query failed.
$revenueTrend     = $revenueTrendOverride     ?? $data['revenue_trend'] ?? [];
$reservationTrend = $reservationTrendOverride ?? $data['reservation_trend'] ?? [];
$stockUsageTrend  = $stockUsageTrendOverride  ?? $data['stock_usage_trend'] ?? [];
$wastageTrend     = $wastageTrendOverride     ?? $data['wastage_trend'] ?? [];

$todayReservations = $data['today_reservations'] ?? [];
$todayOrders      = $data['today_orders'] ?? [];
$draftPos         = $data['draft_pos'] ?? [];
$receivingPos     = $data['receiving_pos'] ?? [];

$stockUrgentStatus = $stock['critical'] > 0 ? 'critical' : ($stock['low'] > 0 ? 'low' : 'critical');

$revenueTrendHasData     = array_sum($revenueTrend) > 0;
$reservationTrendHasData = array_sum($reservationTrend) > 0;
$stockUsageTrendHasData  = array_sum($stockUsageTrend) > 0;
$wastageTrendHasData     = array_sum($wastageTrend) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
    .dash-top-row{ display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
    .dash-greeting{ margin:0; }
    .dash-greeting h1{ font-size:1.5rem; margin:0 0 2px; }
    .dash-greeting p{ margin:0; color:var(--op-ink-soft); font-size:0.9rem; }
    .dash-chart-period{ width:auto; padding:4px 26px 4px 8px; font-size:0.76rem; flex-shrink:0; }
    .dash-tile{ text-decoration:none; display:block; height:100%; }
    .dash-tile .owner-summary-card{ height:100%; box-sizing:border-box; }
    .dash-two-col{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:20px; margin-top:20px; }
    /* owner-panel.css stacks adjacent cards with margin-top:20px, which
       doubles against this grid's own gap and pushed every right-hand
       card 20px below its left neighbour. */
    .dash-two-col > .owner-card + .owner-card{ margin-top:0; }
    .dash-chart-wrap{ position:relative; height:240px; margin-top:12px; }
    .dash-forecast-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:16px 20px; margin-top:14px; }
    .dash-forecast-item-label{ font-size:0.76rem; color:var(--op-ink-soft); }
    .dash-forecast-item-value{ font-size:1.15rem; font-weight:700; margin-top:2px; }
    .dash-forecast-item-sub{ font-size:0.72rem; color:var(--op-ink-faint); margin-top:3px; line-height:1.45; }
    .dash-forecast-note{ font-size:0.76rem; color:var(--op-ink-faint); margin-top:14px; }
    .dash-empty{ padding:30px 16px; text-align:center; }
    .dash-empty i{ font-size:1.8rem; color:var(--op-ink-faint); display:block; margin-bottom:8px; }
    .dash-empty span{ color:var(--op-ink-faint); font-size:0.85rem; }
    .dash-item-cell{ display:flex; align-items:center; gap:10px; }
    .dash-item-thumb{ width:32px; height:32px; border-radius:8px; object-fit:cover; background:var(--op-border-soft); flex-shrink:0; }
    .dash-item-thumb-fallback{ width:32px; height:32px; border-radius:8px; background:var(--op-border-soft); display:flex; align-items:center; justify-content:center; color:var(--op-ink-faint); flex-shrink:0; }
    /* Clickable dashboard rows. A row that navigates has to look like it
       does -- pointer plus a hover tint, so it reads as interactive before
       the click rather than after. */
    .dash-row-link{ cursor:pointer; transition:background 0.12s ease; }
    .dash-row-link:hover{ background:var(--op-gold-soft); }
    .dash-list-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 0; border-bottom:1px solid var(--op-border-soft); }
    .dash-list-row:last-child{ border-bottom:none; }
    .dash-list-main strong{ display:block; font-size:0.88rem; }
    .dash-list-sub{ font-size:0.76rem; color:var(--op-ink-faint); margin-top:2px; }
    .dash-list-value{ text-align:right; font-size:0.85rem; white-space:nowrap; }
    @media (max-width: 900px){ .dash-two-col{ grid-template-columns:minmax(0,1fr); } .dash-chart-wrap{ height:220px; } }
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/includes/header.php'; ?>

        <main class="owner-content">

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <form method="get" id="dashChartFilters"></form>

            <div class="dash-top-row">
                <div class="dash-greeting">
                    <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?>.</h1>
                    <p><?= htmlspecialchars(date('l, F j, Y')) ?></p>
                </div>
            </div>

            <!-- ================= Row 1: KPI strip ================= -->
            <div class="owner-form-grid">
                <a class="dash-tile" href="../orders/orders.php?payment_status=paid&date_from=<?= $rangeStart->format('Y-m-d') ?>&date_to=<?= $rangeEnd->format('Y-m-d') ?>">
                    <?php // Net of VAT: the same revenue line the Sales report leads with.
                          // Total billed is spelled out underneath because this tile links
                          // through to the order list, which shows VAT-inclusive totals. ?>
                    <?= dashboardKpiCard(
                        'Net sales (excl. VAT)',
                        '&#8369;' . number_format($revenueKpi['net_revenue'], 2),
                        $revenueKpi['delta_html'] . '<br><span style="color:var(--op-ink-faint);">&#8369;'
                            . number_format($revenueKpi['total_collected'], 2) . ' billed incl. VAT</span>',
                        'ph-currency-circle-dollar'
                    ) ?>
                </a>
                <a class="dash-tile" href="../inventory/inventory.php?status=<?= $stockUrgentStatus ?>">
                    <?= dashboardKpiCard('Stock needs attention', number_format($stock['total']), $stock['total'] > 0 ? $stock['critical'] . ' critical, ' . $stock['low'] . ' low, ' . $stock['out_of_stock'] . ' out' : 'Stock levels healthy', $stock['total'] > 0 ? 'ph-warning' : 'ph-check-circle') ?>
                </a>
                <a class="dash-tile" href="../reservation/reservations.php?all_dates=1">
                    <?= dashboardKpiCard("Today's reservations", number_format($reservationKpi['total']), 'Confirmed: ' . $reservationKpi['confirmed'] . ', Pending: ' . $reservationKpi['pending'], 'ph-calendar-check') ?>
                </a>
                <a class="dash-tile" href="../purchase_orders/purchase_orders.php">
                    <?= dashboardKpiCard('Receiving / Purchase', number_format($receivingKpi['total']), 'Pending: ' . $receivingKpi['pending'] . ', Completed: ' . $receivingKpi['completed'], 'ph-truck') ?>
                </a>
            </div>

            <!-- ================= Row 2: Sales Trend + Reservation Trend ================= -->
            <div class="dash-two-col">
                <div class="owner-card">
                    <div class="owner-card-head"><div><h2 class="owner-card-title">Sales Trend</h2><span class="owner-card-subtitle">Revenue per day</span></div><?= $trendPeriodSelect('sales_period', $salesPeriod) ?></div>
                    <?php if (!$revenueTrendHasData): ?>
                        <div class="dash-empty"><i class="ph ph-chart-line" aria-hidden="true"></i><span>No sales in this period.</span></div>
                    <?php else: ?>
                        <div class="dash-chart-wrap"><canvas id="dashSalesTrendChart"></canvas></div>
                    <?php endif; ?>
                </div>
                <div class="owner-card">
                    <div class="owner-card-head"><div><h2 class="owner-card-title">Reservation Trend</h2><span class="owner-card-subtitle">Bookings per day</span></div><?= $trendPeriodSelect('res_period', $resPeriod) ?></div>
                    <?php if (!$reservationTrendHasData): ?>
                        <div class="dash-empty"><i class="ph ph-calendar-x" aria-hidden="true"></i><span>No reservations in this period.</span></div>
                    <?php else: ?>
                        <div class="dash-chart-wrap"><canvas id="dashResTrendChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ================= Row 3: Stock Usage Trend + Waste Trend ================= -->
            <div class="dash-two-col">
                <div class="owner-card">
                    <div class="owner-card-head"><div><h2 class="owner-card-title">Stock Usage Trend</h2><span class="owner-card-subtitle">Cost of ingredients used per day, from completed sales</span></div><?= $trendPeriodSelect('stock_period', $stockPeriod) ?></div>
                    <?php if (!$stockUsageTrendHasData): ?>
                        <div class="dash-empty"><i class="ph ph-chart-line-down" aria-hidden="true"></i><span>No recorded consumption in this period.</span></div>
                    <?php else: ?>
                        <div class="dash-chart-wrap"><canvas id="dashStockUsageChart"></canvas></div>
                    <?php endif; ?>
                </div>
                <div class="owner-card">
                    <div class="owner-card-head"><div><h2 class="owner-card-title">Waste Trend</h2><span class="owner-card-subtitle">Cost of stock written off per day</span></div><?= $trendPeriodSelect('waste_period', $wastePeriod) ?></div>
                    <?php if (!$wastageTrendHasData): ?>
                        <div class="dash-empty"><i class="ph ph-trash" aria-hidden="true"></i><span>No wastage recorded in this period.</span></div>
                    <?php else: ?>
                        <div class="dash-chart-wrap"><canvas id="dashWasteChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ================= Row 5: Today's Reservations + Today's Orders ================= -->
            <div class="dash-two-col">
                <div class="owner-card">
                    <div class="owner-card-head">
                        <div><h2 class="owner-card-title">Today's Reservations</h2></div>
                        <a href="../reservation/reservations.php" class="owner-btn owner-btn-secondary owner-btn-sm">View all</a>
                    </div>
                    <?php if (empty($todayReservations)): ?>
                        <div class="dash-empty"><i class="ph ph-calendar-check" aria-hidden="true"></i><span>No reservations today.</span></div>
                    <?php else: ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table">
                            <thead><tr><th>Customer</th><th>Time</th><th class="num">Party</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($todayReservations as $r): $meta = reservationStatusMeta($r['status']); ?>
                                <tr class="dash-row-link" onclick="window.location.href='../reservation/reservations.php?q=<?= urlencode($r['reservation_number']) ?>'">
                                    <td><strong><?= htmlspecialchars($r['customer_name'] ?? 'Guest') ?></strong></td>
                                    <td><?= htmlspecialchars(date('g:i A', strtotime($r['start_time']))) ?></td>
                                    <td class="num"><?= (int)$r['number_of_guests'] ?></td>
                                    <td><span class="owner-status-pill <?= $meta['class'] ?>"><?= htmlspecialchars($meta['label']) ?></span></td>
                                     </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="owner-card">
                    <div class="owner-card-head">
                        <div><h2 class="owner-card-title">Today's Orders</h2></div>
                        <a href="../orders/orders.php?date_from=<?= date('Y-m-d') ?>&date_to=<?= date('Y-m-d') ?>" class="owner-btn owner-btn-secondary owner-btn-sm">View all</a>
                    </div>
                    <?php if (empty($todayOrders)): ?>
                        <div class="dash-empty"><i class="ph ph-receipt" aria-hidden="true"></i><span>No orders today.</span></div>
                    <?php else: ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table">
                            <thead><tr><th>Order #</th><th>Time</th><th>Customer</th><th>Type</th><th class="num">Total</th></tr></thead>
                            <tbody>
                            <?php foreach ($todayOrders as $o): ?>
                                <tr class="dash-row-link" onclick="window.location.href='../orders/orders.php?search=<?= urlencode($o['order_number']) ?>'">
                                    <td><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                                    <td><?= htmlspecialchars(date('g:i A', strtotime($o['created_at']))) ?></td>
                                    <td><?= $o['customer_first_name'] !== null ? htmlspecialchars($o['customer_first_name'] . ' ' . $o['customer_last_name']) : 'Walk-in' ?></td>
                                    <td><?= htmlspecialchars(orderTypeLabel($o['order_type'])) ?></td>
                                    <td class="num">&#8369;<?= number_format((float)$o['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ================= Row 6: Draft POs + Receiving POs ================= -->
            <div class="dash-two-col">
                <div class="owner-card">
                    <div class="owner-card-head">
                        <div><h2 class="owner-card-title">Draft POs</h2></div>
                        <a href="../purchase_orders/purchase_orders.php" class="owner-btn owner-btn-secondary owner-btn-sm">View all</a>
                    </div>
                    <?php if (empty($draftPos)): ?>
                        <div class="dash-empty"><i class="ph ph-file-dashed" aria-hidden="true"></i><span>No draft purchase orders.</span></div>
                    <?php else: ?>
                        <?php foreach ($draftPos as $po): ?>
                            <div class="dash-list-row dash-row-link" onclick="window.location.href='../purchase_orders/purchase_order_view.php?id=<?= (int)$po['po_id'] ?>'">
                                <div class="dash-list-main">
                                    <strong><?= htmlspecialchars($po['po_number']) ?><?= $po['is_auto_generated'] ? ' <span class="owner-status-pill is-info">Auto</span>' : '' ?></strong>
                                    <div class="dash-list-sub"><?= htmlspecialchars($po['supplier_name']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($po['order_date']))) ?></div>
                                </div>
                                <div class="dash-list-value">&#8369;<?= number_format((float)$po['total_amount'], 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="owner-card">
                    <div class="owner-card-head">
                        <div>
                            <h2 class="owner-card-title">Deliveries to receive</h2>
                            <span class="owner-card-subtitle"><?= count($receivingPos) ?> awaiting delivery</span>
                        </div>
                        <a href="../purchase_orders/purchase_orders.php" class="owner-btn owner-btn-secondary owner-btn-sm">View all</a>
                    </div>
                    <?php if (empty($receivingPos)): ?>
                        <div class="dash-empty"><i class="ph ph-truck" aria-hidden="true"></i><span>Nothing currently incoming.</span></div>
                    <?php else: ?>
                        <?php foreach ($receivingPos as $po): ?>
                            <div class="dash-list-row dash-row-link" onclick="window.location.href='../purchase_orders/purchase_order_view.php?id=<?= (int)$po['po_id'] ?>'">
                                <div class="dash-list-main">
                                    <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                                    <div class="dash-list-sub"><?= htmlspecialchars($po['supplier_name']) ?> &middot; Expected <?= $po['expected_delivery_date'] !== null ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '&mdash;' ?></div>
                                </div>
                                <span class="owner-status-pill <?= poOrderStatusPillClass($po['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $po['status']))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
(function () {
    // Same hues and mark specs as the Analytics tabs these four charts are
    // taken from -- gold for revenue, blue for volume series, and the shared
    // "money walking out" red for waste. A trend the owner recognises on one
    // page should not change colour on the other.
    var GOLD = '#9c7734', BLUE = '#3f7ea6', LOSS = '#cf5a44';

    var peso = function (n) {
        return '₱' + Number(n).toLocaleString('en-PH');
    };

    // "2026-09-08" reads as a system value, not a date a reader recognises at
    // a glance -- every label here is a dense daily Y-m-d key, so this turns
    // it into "Sep 8" for the axis and the tooltip title alike.
    var dateLabel = function (s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (!m) return s;
        var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };

    // One shared config for all four: they are the same kind of chart (a dense
    // daily series), so they get one shape rather than four hand-tuned ones.
    function trendChart(canvasId, label, labels, values, colour, fillRgba, opts) {
        var el = document.getElementById(canvasId);
        if (!el || !window.Chart) return;
        opts = opts || {};
        labels = labels.map(dateLabel);
        var yTicks = {};
        if (opts.integer) yTicks.precision = 0;
        if (opts.money)   yTicks.callback = peso;
        new Chart(el, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: label, data: values,
                    borderColor: colour, backgroundColor: fillRgba,
                    borderWidth: 2, tension: 0.3, pointRadius: 2, fill: true
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) {
                        return ' ' + (opts.money ? peso(Number(ctx.parsed.y).toFixed(2))
                                                 : Number(ctx.parsed.y).toLocaleString('en-PH'));
                    } } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: yTicks },
                    // Flat, capped ticks instead of the old rotated labels --
                    // money ticks are wide and these cards are half-width.
                    x: { ticks: { maxTicksLimit: opts.money ? 6 : 8, maxRotation: 0 } }
                }
            }
        });
    }

    <?php if ($revenueTrendHasData): ?>
    trendChart('dashSalesTrendChart', 'Revenue',
        <?= json_encode(array_keys($revenueTrend)) ?>, <?= json_encode(array_values($revenueTrend)) ?>,
        GOLD, 'rgba(156,119,52,0.10)', { money: true });
    <?php endif; ?>

    <?php if ($reservationTrendHasData): ?>
    trendChart('dashResTrendChart', 'Reservations',
        <?= json_encode(array_keys($reservationTrend)) ?>, <?= json_encode(array_values($reservationTrend)) ?>,
        BLUE, 'rgba(63,126,166,0.10)', { integer: true });
    <?php endif; ?>

    <?php if ($stockUsageTrendHasData): ?>
    trendChart('dashStockUsageChart', 'Cost of stock used',
        <?= json_encode(array_keys($stockUsageTrend)) ?>, <?= json_encode(array_map(fn($v) => round((float)$v, 2), array_values($stockUsageTrend))) ?>,
        BLUE, 'rgba(63,126,166,0.10)', { money: true });
    <?php endif; ?>

    <?php if ($wastageTrendHasData): ?>
    trendChart('dashWasteChart', 'Wastage cost',
        <?= json_encode(array_keys($wastageTrend)) ?>, <?= json_encode(array_map(fn($v) => round((float)$v, 2), array_values($wastageTrend))) ?>,
        LOSS, 'rgba(207,90,68,0.10)', { money: true });
    <?php endif; ?>
})();
</script>

</body>
</html>
