<?php
/**
 * owner/analytics_pdf.php
 *
 * No param -- in-browser preview of one tab. ?download=1 -- streams the
 * same document as a real .pdf via Dompdf. Both paths render through
 * renderAnalyticsPdfDocumentHtml() so the preview and the download are
 * identical, same convention as owner/sales_analytics_pdf.php.
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

$tabData = match ($tab) {
    'sales'            => buildSalesTabData($pdo, $rangeStart, $rangeEnd),
    'reservations'     => buildReservationsTabData($pdo, $rangeStart, $rangeEnd),
    'inventory'        => buildInventoryTabData($pdo, $rangeStart, $rangeEnd),
};

$restaurantName = $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
)->fetchColumn() ?: 'OPO! Our Pinoy Original';

$queryString = http_build_query(array_filter([
    'period' => $period, 'date_from' => $dateFrom, 'date_to' => $dateTo,
], fn($v) => $v !== ''));

$isDownload = ($_GET['download'] ?? '') === '1';

if ($isDownload) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $html = renderAnalyticsPdfDocumentHtml($tab, $tabData, $restaurantName, periodLabel($period, $rangeStart, $rangeEnd), false, $queryString);

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="analytics_' . $tab . '_' . date('Y-m-d') . '.pdf"');
    echo $dompdf->output();

    logAnalyticsExport($pdo, Session::getUserId(), $tab, 'pdf', ['period' => $period, 'date_from' => $dateFrom, 'date_to' => $dateTo], 1);
    exit;
}

echo renderAnalyticsPdfDocumentHtml($tab, $tabData, $restaurantName, periodLabel($period, $rangeStart, $rangeEnd), true, $queryString);
