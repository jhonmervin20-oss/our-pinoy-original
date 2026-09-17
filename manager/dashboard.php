<?php
/**
 * manager/dashboard.php
 *
 * Landing page for the Manager panel -- a true operational dashboard, not
 * a module launcher (that's what manager/includes/sidebar.php is for).
 * Live KPIs, trend charts, stock/payroll widgets, and recent activity, so
 * the manager can read the day's operational status the moment they log
 * in. Alerts stay in the topbar bell (header.php) rather than being
 * duplicated as a dashboard panel. Every widget reuses the exact query/
 * chart conventions its source module already established (see
 * manager/includes/dashboard_functions.php's module docblock) -- same
 * Chart.js version/palette as owner/analytics.php, same revenue/peak-
 * hours queries Sales already uses, same inventory_stock_status view
 * Inventory/Report already read, same payroll_runs status query
 * employee_management/runs.php already uses.
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
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'dashboard';
$pageTitle  = 'Dashboard';

$firstName = trim(explode(' ', Session::getFullName() ?: 'there')[0]);
$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$attendanceTrendPeriodOptions = [
    'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'last90' => 'Last 90 days',
];
$attendanceTrendPeriod = in_array($_GET['attendance_period'] ?? '', array_keys($attendanceTrendPeriodOptions), true)
    ? $_GET['attendance_period'] : 'last30';

$data    = [];
$dbError = null;

try {
    $pdo  = Database::getInstance()->getConnection();
    $data = buildManagerDashboardData($pdo, $attendanceTrendPeriod);
} catch (PDOException $e) {
    $dbError = "Couldn't load live dashboard data. Please refresh this page.";
    error_log('manager/dashboard.php failed: ' . $e->getMessage());
}

// Keys must mirror getTodayOrdersSummary()'s full return shape -- the KPI tile
// below reads net_sales and billed, so a fallback missing them warns and prints
// an empty peso figure on exactly the request where the DB was already unhappy.
$todayOrders  = $data['today_orders'] ?? ['count' => 0, 'billed' => 0.0, 'vat' => 0.0, 'net_sales' => 0.0, 'revenue' => 0.0];
$todayRes     = $data['today_reservations'] ?? ['total' => 0, 'pending' => 0];
$stock        = $data['inventory_attention'] ?? ['critical' => 0, 'low' => 0, 'out_of_stock' => 0, 'total' => 0];
$payrollRuns  = $data['payroll_runs'] ?? ['status_counts' => ['draft' => 0, 'pending_approval' => 0, 'approved' => 0, 'released' => 0], 'latest_run' => null];
$payrollRunsAwaiting = $data['payroll_runs_awaiting'] ?? [];
$receivingPos = $data['receiving_pos'] ?? [];
$todayReservationsList = $data['today_reservations_list'] ?? [];
$todayOrdersList = $data['today_orders_list'] ?? [];
$weeklyAttendanceTrend = $data['weekly_attendance_trend'] ?? [];

$stockUrgentStatus = $stock['critical'] > 0 ? 'critical' : ($stock['low'] > 0 ? 'low' : 'critical');
$todayIso = date('Y-m-d');
$runsAwaiting = $payrollRuns['status_counts']['pending_approval'];
$weeklyTrendHasData  = !empty($weeklyAttendanceTrend) && array_sum(array_map(fn($row) => $row['present'] + $row['late'] + $row['absent'], $weeklyAttendanceTrend)) > 0;

// Plain "Attendance Trend" -- see the matching fix (and its full reasoning)
// in employee_management/performance_monitoring.php. Same $attendanceTrendPeriod
// this card's own dropdown already resolved $data from, so this can't drift
// from what getWeeklyAttendanceTrend() actually queried -- just re-derived
// locally (cheap, no DB I/O) rather than widening buildManagerDashboardData()'s
// return shape to carry it.
$trendCardTitle = 'Attendance Trend';
[$attendanceRangeStart, $attendanceRangeEnd] = resolveAttendanceReportRange($attendanceTrendPeriod, null, null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Manager Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
    .dash-greeting{ margin:0 0 20px; }
    .dash-greeting h1{ font-size:1.5rem; margin:0 0 2px; }
    .dash-greeting p{ margin:0; color:var(--op-ink-soft); font-size:0.9rem; }
    .dash-tile{ text-decoration:none; display:block; height:100%; }
    /* .owner-summary-card centres its text block vertically, so a card with an
       extra meta line sits its number on a different baseline than its
       neighbours -- measured 227 / 237 / 237 / 245px across these four. Top
       aligning makes every label start at the same y, which puts every number
       on the same line and lets the extra line grow downwards instead.
       margin-bottom:0 because the grid gap below already does that spacing --
       with it, the strip sat 40px above the next row where every other section
       gap is 20px. */
    .dash-tile .owner-summary-card{
        height:100%; box-sizing:border-box;
        align-items:flex-start;
        margin-bottom:0;
    }
    /* Every widget in the two-column area is the same fixed height, so the grid
       reads as one clean matrix rather than four boxes of four different sizes.
       One number tunes the whole area. Content taller than the box scrolls
       inside it instead of stretching its row -- a payroll queue with eight
       runs in it must not make its neighbour eight rows tall. */
    :root{ --dash-widget-h: 340px; }
    .dash-two-col{ display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px; }
    .dash-two-col > .owner-card{
        height: var(--dash-widget-h);
        box-sizing:border-box;
        display:flex; flex-direction:column;
        /* owner-panel.css gives `.owner-card + .owner-card` a 20px margin-top
           for stacked cards. Inside this grid that hits the SECOND card of each
           row, dropping the right-hand column 20px below the left one -- same
           height, but never level. The grid gap already does this spacing. */
        margin-top:0;
    }
    /* The heading stays put; only what follows it scrolls. Without min-height:0
       a flex child refuses to shrink below its content, so the box would grow
       and the fixed height would do nothing. */
    .dash-two-col > .owner-card > .owner-card-head{ flex:0 0 auto; }
    .dash-two-col > .owner-card > .dash-widget-body{
        flex:1 1 auto; min-height:0; min-width:0; overflow-y:auto;
        display:flex; flex-direction:column;
    }
    .dash-chart-wrap{ position:relative; height:216px; margin-top:12px; }
    /* Inside a fixed-height widget the chart takes whatever the heading leaves
       instead of claiming a fixed height of its own: at around 1000px the
       subtitle wraps to a second line and a fixed 216px chart overflowed its
       widget by 8px, putting a scrollbar on a graph. */
    .dash-two-col .dash-widget-body > .dash-chart-wrap{ flex:1 1 auto; height:auto; min-height:0; }
    .dash-empty{ padding:30px 16px; text-align:center; }
    .dash-empty i{ font-size:1.8rem; color:var(--op-ink-faint); display:block; margin-bottom:8px; }
    .dash-empty span{ color:var(--op-ink-faint); font-size:0.85rem; }
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
    @media (max-width: 900px){ .dash-two-col{ grid-template-columns:minmax(0,1fr); } }
    .dash-chart-period{ width:auto; padding:4px 26px 4px 8px; font-size:0.76rem; flex-shrink:0; }
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

            <div class="dash-greeting">
                <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?>.</h1>
                <p><?= htmlspecialchars(date('l, F j, Y')) ?></p>
            </div>

            <!-- KPI strip -->
            <div class="owner-form-grid">
                <a class="dash-tile" href="../orders/orders.php?payment_status=paid&date_from=<?= $todayIso ?>&date_to=<?= $todayIso ?>">
                    <?php // Net of VAT, matching the owner dashboard and the Sales report;
                          // the VAT-inclusive figure customers were billed sits underneath. ?>
                    <?= dashboardKpiCard(
                        'Today\'s net sales',
                        '&#8369;' . number_format($todayOrders['net_sales'], 2),
                        $todayOrders['count'] . ' paid order' . ($todayOrders['count'] === 1 ? '' : 's')
                            . '<br><span style="color:var(--op-ink-faint);">&#8369;' . number_format($todayOrders['billed'], 2) . ' billed incl. VAT</span>',
                        'ph-currency-circle-dollar'
                    ) ?>
                </a>
                <a class="dash-tile" href="../reservation/reservations.php">
                    <?= dashboardKpiCard('Today\'s reservations', number_format($todayRes['total']), $todayRes['pending'] > 0 ? $todayRes['pending'] . ' pending' : 'None pending', 'ph-calendar-check') ?>
                </a>
                <a class="dash-tile" href="../inventory/inventory.php?status=<?= $stockUrgentStatus ?>">
                    <?= dashboardKpiCard('Stock needs attention', number_format($stock['total']), $stock['total'] > 0 ? $stock['critical'] . ' critical, ' . $stock['low'] . ' low, ' . $stock['out_of_stock'] . ' out' : 'Stock levels healthy', $stock['total'] > 0 ? 'ph-warning' : 'ph-check-circle') ?>
                </a>
                <a class="dash-tile" href="../employee_management/runs.php?status=pending_approval">
                    <?= dashboardKpiCard('Payroll approvals', number_format($runsAwaiting), $runsAwaiting > 0 ? 'Click to review' : 'Nothing awaiting approval', 'ph-money') ?>
                </a>
            </div>

            <!-- Weekly attendance trend + Payroll run status -->
            <div class="dash-two-col">
                <div class="owner-card">
                    <div class="owner-card-head">
                        <div>
                            <h2 class="owner-card-title"><?= htmlspecialchars($trendCardTitle) ?></h2>
                        </div>
                        <select name="attendance_period" form="dashChartFilters" class="owner-select dash-chart-period" onchange="this.form.submit()" aria-label="Chart period">
                            <?php foreach ($attendanceTrendPeriodOptions as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $attendanceTrendPeriod === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="dash-widget-body">
                    <?php if (!$weeklyTrendHasData): ?>
                        <div class="dash-empty"><i class="ph ph-chart-bar" aria-hidden="true"></i><span>No attendance records yet for this period.</span></div>
                    <?php else: ?>
                        <div class="dash-chart-wrap"><canvas id="dashWeeklyAttendanceChart"></canvas></div>
                    <?php endif; ?>
                    </div>
                </div>
                <div class="owner-card">
                    <div class="owner-card-head">
                        <div>
                            <h2 class="owner-card-title">Payroll Runs Awaiting Approval</h2>
                            <span class="owner-card-subtitle">Needs your review</span>
                        </div>
                        <a href="../employee_management/runs.php?status=pending_approval" class="owner-btn owner-btn-secondary owner-btn-sm">View all</a>
                    </div>
                    <div class="dash-widget-body">
                    <?php if (empty($payrollRunsAwaiting)): ?>
                        <div class="dash-empty"><i class="ph ph-check-circle" aria-hidden="true"></i><span>Nothing awaiting approval.</span></div>
                    <?php else: ?>
                        <?php foreach ($payrollRunsAwaiting as $run): ?>
                            <div class="dash-list-row dash-row-link" onclick="window.location.href='../employee_management/run_detail.php?id=<?= (int)$run['payroll_run_id'] ?>'">
                                <div class="dash-list-main">
                                    <strong><?= htmlspecialchars($run['run_number']) ?></strong>
                                    <div class="dash-list-sub"><?= htmlspecialchars(date('M j', strtotime($run['cutoff_period_start']))) ?>&ndash;<?= htmlspecialchars(date('M j, Y', strtotime($run['cutoff_period_end']))) ?> &middot; Payout <?= htmlspecialchars(date('M j, Y', strtotime($run['payout_date']))) ?></div>
                                </div>
                                <div class="dash-list-value">&#8369;<?= number_format((float)$run['total_net_pay'], 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Today's Reservations + Today's Orders -->
            <div class="dash-two-col">
                <div class="owner-card">
                    <div class="owner-card-head">
                        <div><h2 class="owner-card-title">Today's Reservations</h2></div>
                        <a href="../reservation/reservations.php" class="owner-btn owner-btn-secondary owner-btn-sm">View all</a>
                    </div>
                    <div class="dash-widget-body">
                    <?php if (empty($todayReservationsList)): ?>
                        <div class="dash-empty"><i class="ph ph-calendar-check" aria-hidden="true"></i><span>No reservations today.</span></div>
                    <?php else: ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table">
                            <thead><tr><th>Customer</th><th>Time</th><th class="num">Party</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($todayReservationsList as $r): $meta = reservationStatusMeta($r['status']); ?>
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
                </div>

                <div class="owner-card">
                    <div class="owner-card-head">
                        <div><h2 class="owner-card-title">Today's Orders</h2></div>
                        <a href="../orders/orders.php?date_from=<?= $todayIso ?>&date_to=<?= $todayIso ?>" class="owner-btn owner-btn-secondary owner-btn-sm">View all</a>
                    </div>
                    <div class="dash-widget-body">
                    <?php if (empty($todayOrdersList)): ?>
                        <div class="dash-empty"><i class="ph ph-receipt" aria-hidden="true"></i><span>No orders today.</span></div>
                    <?php else: ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table">
                            <thead><tr><th>Order #</th><th>Time</th><th>Customer</th><th>Type</th><th>Status</th><th class="num">Total</th></tr></thead>
                            <tbody>
                            <?php foreach ($todayOrdersList as $o): $pMeta = reservationPaymentStatusMeta($o['payment_status'] ?? 'pending'); ?>
                                <tr class="dash-row-link" onclick="window.location.href='../orders/orders.php?search=<?= urlencode($o['order_number']) ?>'">
                                    <td><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                                    <td><?= htmlspecialchars(date('g:i A', strtotime($o['created_at']))) ?></td>
                                    <td><?= $o['customer_first_name'] !== null ? htmlspecialchars($o['customer_first_name'] . ' ' . $o['customer_last_name']) : 'Walk-in' ?></td>
                                    <td><?= htmlspecialchars(orderTypeLabel($o['order_type'])) ?></td>
                                    <td><span class="owner-status-pill <?= $pMeta['class'] ?>"><?= htmlspecialchars($pMeta['label']) ?></span></td>
                                    <td class="num">&#8369;<?= number_format((float)$o['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php /* Deliveries to receive.
                     The "Draft POs" card that used to sit beside this one is gone: drafts
                     are the Owner's alone now, and clicking one only bounced the Manager
                     off purchase_order_view.php's own guard. Receiving is the Manager's
                     entire role in this module, so it gets the full width and its rows
                     link straight to the receiving screen instead of the view page. */ ?>
            <div class="owner-card" style="margin-top:20px;">
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
                        <div class="dash-list-row dash-row-link" onclick="window.location.href='../purchase_orders/purchase_order_receive.php?id=<?= (int)$po['po_id'] ?>'">
                            <div class="dash-list-main">
                                <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                                <div class="dash-list-sub"><?= htmlspecialchars($po['supplier_name']) ?> &middot; Expected <?= $po['expected_delivery_date'] !== null ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '&mdash;' ?></div>
                            </div>
                            <span class="owner-status-pill <?= poOrderStatusPillClass($po['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $po['status']))) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>



<script>
(function () {
    <?php if ($weeklyTrendHasData): ?>
    (function () {
        var ctx = document.getElementById('dashWeeklyAttendanceChart');
        if (!ctx || !window.Chart) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($k) => attendanceWeekBucketLabel($k, $attendanceRangeStart, $attendanceRangeEnd), array_keys($weeklyAttendanceTrend))) ?>,
                datasets: [
                    { label: 'Present', data: <?= json_encode(array_column($weeklyAttendanceTrend, 'present')) ?>, backgroundColor: '#4caf7d' },
                    { label: 'Late', data: <?= json_encode(array_column($weeklyAttendanceTrend, 'late')) ?>, backgroundColor: '#d1a13a' },
                    { label: 'Absent', data: <?= json_encode(array_column($weeklyAttendanceTrend, 'absent')) ?>, backgroundColor: '#c0564a' },
                    { label: 'Leave', data: <?= json_encode(array_column($weeklyAttendanceTrend, 'leave')) ?>, backgroundColor: '#7e93c9' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var value = context.parsed.y;
                                return context.dataset.label + ': ' + value + (value === 1 ? ' day' : ' days');
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Days' } }
                }
            }
        });
    })();
    <?php endif; ?>

})();
</script>

</body>
</html>
