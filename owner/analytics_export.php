<?php
/**
 * owner/analytics_export.php
 *
 * Streams one tab of the Analytics module as a multi-section CSV (BOM +
 * fputcsv, blank-row-separated sections), same convention as
 * owner/sales_analytics_export.php / owner/menu_performance_export.php.
 * `?tab=` picks which tab's sections to emit.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/analytics_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$validPeriods = ['today', 'yesterday', 'last7', 'last30', 'this_month', 'last_month', 'custom'];
$validTabs    = ['sales', 'reservations', 'inventory'];

$tab      = in_array($_GET['tab'] ?? '', $validTabs, true) ? $_GET['tab'] : 'sales';
$period   = in_array($_GET['period'] ?? '', $validPeriods, true) ? $_GET['period'] : 'last30';
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));

[$rangeStart, $rangeEnd] = resolvePeriodRange($period, $dateFrom ?: null, $dateTo ?: null);

$pdo = Database::getInstance()->getConnection();

$d = match ($tab) {
    'sales'            => buildSalesTabData($pdo, $rangeStart, $rangeEnd),
    'reservations'     => buildReservationsTabData($pdo, $rangeStart, $rangeEnd),
    'inventory'        => buildInventoryTabData($pdo, $rangeStart, $rangeEnd),
};

$tabLabels = ['sales' => 'Sales', 'reservations' => 'Reservations', 'inventory' => 'Inventory'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="analytics_' . $tab . '_' . date('Y-m-d_His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, [$tabLabels[$tab] . ' Analytics', periodLabel($period, $rangeStart, $rangeEnd)]);
fputcsv($out, []);

$rowCount = 0;

if ($tab === 'sales') {
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Gross Sales', number_format($d['kpi']['gross_sales'], 2, '.', '')]);
    fputcsv($out, ['Discounts', number_format($d['kpi']['discounts'], 2, '.', '')]);
    fputcsv($out, ['Net Sales (incl. VAT)', number_format($d['kpi']['net_sales_vat_inc'], 2, '.', '')]);
    fputcsv($out, ['Output VAT', number_format($d['kpi']['vat'], 2, '.', '')]);
    fputcsv($out, ['Net Sales (excl. VAT)', number_format($d['kpi']['net_sales_vat_exc'], 2, '.', '')]);
    fputcsv($out, ['VATable Sales', number_format($d['kpi']['vatable_sales'], 2, '.', '')]);
    fputcsv($out, ['VAT-Exempt Sales', number_format($d['kpi']['vat_exempt_sales'], 2, '.', '')]);
    fputcsv($out, ['Total Billed', number_format($d['kpi']['total_collected'], 2, '.', '')]);
    fputcsv($out, ['Paid Orders', $d['kpi']['total_orders']]);
    fputcsv($out, ['Average Order Value', number_format($d['kpi']['aov'], 2, '.', '')]);
    fputcsv($out, []);

    fputcsv($out, ['Sales Trend']);
    fputcsv($out, ['Date', 'Revenue']);
    foreach ($d['revenue_trend'] as $date => $amt) {
        fputcsv($out, [$date, number_format((float)$amt, 2, '.', '')]);
        $rowCount++;
    }
    fputcsv($out, []);

    fputcsv($out, ['Top-Selling Items']);
    fputcsv($out, ['Rank', 'Item', 'Category', 'Units Sold', 'Revenue']);
    foreach ($d['top_items'] as $i => $row) {
        fputcsv($out, [
            $i + 1, $row['item_name'], $row['category_name'] ?? '',
            number_format((float)$row['units_sold'], 2, '.', ''),
            number_format((float)$row['revenue'], 2, '.', ''),
        ]);
        $rowCount++;
    }
    fputcsv($out, []);

    fputcsv($out, ['Peak Sales Hours']);
    fputcsv($out, ['Hour', 'Orders', 'Revenue']);
    foreach ($d['peak_hours'] as $h => $row) {
        // Hours with no trade are exported too: a gap in the CSV would let a
        // reader assume the restaurant traded evenly all day.
        fputcsv($out, [
            formatAnalyticsHour((int)$h),
            $row['orders'],
            number_format((float)$row['revenue'], 2, '.', ''),
        ]);
        $rowCount++;
    }
} elseif ($tab === 'reservations') {
    $s = $d['summary'];
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Total Reservations', $s['total']]);
    fputcsv($out, ['Confirmed', $s['confirmed']]);
    fputcsv($out, ['Completed', $s['completed']]);
    fputcsv($out, ['No-Shows', $s['no_show']]);
    fputcsv($out, ['Resolved Bookings', $s['resolved']]);
    // 'N/A', not '0%': no resolved bookings means the rate is unknown, which
    // is a different statement from a zero no-show rate.
    fputcsv($out, ['No-Show Rate', $s['no_show_rate'] === null ? 'N/A' : number_format($s['no_show_rate'], 1, '.', '') . '%']);
    fputcsv($out, []);

    fputcsv($out, ['Reservation Trend']);
    fputcsv($out, ['Date', 'Reservations']);
    foreach ($d['trend'] as $date => $n) {
        fputcsv($out, [$date, (int)$n]);
        $rowCount++;
    }
    fputcsv($out, []);

    // No-shows/rate columns match what each bar's tooltip already shows
    // on screen -- a printed export must never carry less than the page.
    fputcsv($out, ['Popular Time Slots']);
    fputcsv($out, ['Slot', 'Reservations', 'Guests', 'No-Shows', 'No-Show Rate']);
    foreach ($d['popular_slots'] as $row) {
        $resolved = (int)($row['resolved'] ?? 0);
        $noShows  = (int)($row['no_shows'] ?? 0);
        fputcsv($out, [
            $row['slot_label'], (int)$row['n'], (int)$row['guests'], $noShows,
            $resolved > 0 ? number_format(($noShows / $resolved) * 100, 1, '.', '') . '%' : 'N/A',
        ]);
        $rowCount++;
    }
    fputcsv($out, []);

    fputcsv($out, ['Popular Days']);
    fputcsv($out, ['Day', 'Reservations', 'No-Shows', 'No-Show Rate']);
    foreach ($d['popular_days'] as $row) {
        $resolved = (int)($row['resolved'] ?? 0);
        $noShows  = (int)($row['no_shows'] ?? 0);
        fputcsv($out, [
            $row['day'], (int)$row['n'], $noShows,
            $resolved > 0 ? number_format(($noShows / $resolved) * 100, 1, '.', '') . '%' : 'N/A',
        ]);
        $rowCount++;
    }
} elseif ($tab === 'inventory') {
    $s = $d['stock_summary'];
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Current Stock Items', $s['total_items']]);
    fputcsv($out, ['Low-Stock Items', (int)$d['low_stock_count']]);
    fputcsv($out, ['Stockout Items (current)', count($d['stockouts'])]);
    fputcsv($out, ['Inventory Value', number_format($d['inventory_value'], 2, '.', '')]);
    fputcsv($out, []);

    // Valued in pesos, not quantity -- see buildStockUsageTrend()'s docblock:
    // a day's consumption mixes kg/L/pcs across items, so a quantity total
    // has no real unit to report it in.
    fputcsv($out, ['Stock Usage Trend']);
    fputcsv($out, ['Date', 'Cost of Stock Used (PHP)']);
    foreach ($d['usage_trend'] as $date => $cost) {
        fputcsv($out, [$date, number_format((float)$cost, 2, '.', '')]);
        $rowCount++;
    }
    fputcsv($out, []);

    fputcsv($out, ['Most Consumed Ingredients']);
    fputcsv($out, ['Ingredient', 'Quantity', 'Unit']);
    foreach ($d['most_consumed'] as $row) {
        fputcsv($out, [$row['item_name'], number_format((float)$row['qty'], 3, '.', ''), $row['unit_code'] ?? '']);
        $rowCount++;
    }
    fputcsv($out, []);

    fputcsv($out, [count($d['low_stock']) < (int)$d['low_stock_count']
        ? 'Low-Stock Items (worst ' . count($d['low_stock']) . ' of ' . (int)$d['low_stock_count'] . ')'
        : 'Low-Stock Items']);
    fputcsv($out, ['Item', 'On Hand', 'Reorder Level', 'Unit', 'Status']);
    foreach ($d['low_stock'] as $row) {
        fputcsv($out, [
            $row['item_name'],
            number_format((float)$row['current_stock'], 3, '.', ''),
            number_format((float)$row['reorder_level'], 3, '.', ''),
            $row['unit_code'] ?? '',
            $row['stock_status'],
        ]);
        $rowCount++;
    }
    fputcsv($out, []);

    // Current stockouts only. This system stores no historical stock levels,
    // so a dated "stockout events" list cannot be produced -- the header says
    // so rather than leaving a reader to assume the dates are missing.
    fputcsv($out, ['Stockout Items (current only - historical stockout dates are not recorded)']);
    fputcsv($out, ['Item', 'On Hand', 'Unit']);
    foreach ($d['stockouts'] as $row) {
        fputcsv($out, [$row['item_name'], number_format((float)$row['current_stock'], 3, '.', ''), $row['unit_code'] ?? '']);
        $rowCount++;
    }
}

fclose($out);

logAnalyticsExport($pdo, Session::getUserId(), $tab, 'csv', ['period' => $period, 'date_from' => $dateFrom, 'date_to' => $dateTo], $rowCount);
exit;
