<?php
/**
 * owner/analytics_insight.php
 *
 * Lazy AI-insight fetch for owner/analytics.php -- called client-side the
 * first time a non-default tab is activated (the default tab's insight is
 * already rendered synchronously by analytics.php itself). Read-mostly:
 * cache-or-generate via runAnalyticsAiInsight(), same idempotent-GET
 * convention already established by header.php's opportunistic sweeps
 * (sweepInventoryStockAlerts() etc. also run on every GET in this app).
 * Never mutates anything the user would consider "their" data -- only
 * ever inserts a cached AI summary row.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/analytics_functions.php';

Session::start();

header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['owner'])) {
    http_response_code(403);
    echo json_encode(['html' => '<p class="an-ai-empty">Not authorized.</p>']);
    exit;
}

$validPeriods = ['today', 'yesterday', 'last7', 'last30', 'this_month', 'last_month', 'custom'];
$validTabs    = ['sales', 'reservations', 'inventory'];

$tab      = in_array($_GET['tab'] ?? '', $validTabs, true) ? $_GET['tab'] : 'sales';
$period   = in_array($_GET['period'] ?? '', $validPeriods, true) ? $_GET['period'] : 'last30';
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));

[$rangeStart, $rangeEnd] = resolvePeriodRange($period, $dateFrom ?: null, $dateTo ?: null);

try {
    $pdo = Database::getInstance()->getConnection();

    $tabData = match ($tab) {
        'sales'            => buildSalesTabData($pdo, $rangeStart, $rangeEnd),
        'reservations'     => buildReservationsTabData($pdo, $rangeStart, $rangeEnd),
        'inventory'        => buildInventoryTabData($pdo, $rangeStart, $rangeEnd),
    };

    $openai  = getOpenAiClient();
    $insight = computeTabAiInsight($pdo, $openai, $tab, $tabData, $rangeStart, $rangeEnd, false, null);

    echo json_encode(['html' => renderAnalyticsAiInsightBodyHtml($insight)]);
} catch (Throwable $e) {
    error_log('analytics_insight.php failed: ' . $e->getMessage());
    echo json_encode(['html' => '<p class="an-ai-empty">Insight unavailable right now.</p>']);
}
