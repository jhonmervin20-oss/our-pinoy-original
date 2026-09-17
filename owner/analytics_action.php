<?php
/**
 * owner/analytics_action.php
 *
 * Single dispatcher for owner/analytics.php's write actions -- currently
 * just `regenerate_insights` (bypasses the AI insight cache for one tab),
 * matching this app's existing single-file-per-related-action-set
 * convention (e.g. owner/sentiment_analysis_action.php).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: analytics.php');
    exit;
}

$validPeriods = ['today', 'yesterday', 'last7', 'last30', 'this_month', 'last_month', 'custom'];
$validTabs    = ['sales', 'reservations', 'inventory'];
$tab       = in_array($_POST['tab'] ?? '', $validTabs, true) ? $_POST['tab'] : 'sales';
$period    = in_array($_POST['period'] ?? '', $validPeriods, true) ? $_POST['period'] : 'custom';
$dateFrom  = trim((string)($_POST['date_from'] ?? ''));
$dateTo    = trim((string)($_POST['date_to'] ?? ''));
$redirectQs = http_build_query(array_filter([
    'tab' => $tab, 'period' => $period, 'date_from' => $dateFrom, 'date_to' => $dateTo,
], fn($v) => $v !== ''));

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: analytics.php?' . $redirectQs);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
if ($action !== 'regenerate_insights') {
    flash_set('error', 'Invalid request.');
    header('Location: analytics.php?' . $redirectQs);
    exit;
}

$ownerUserId = Session::getUserId();

try {
    $pdo = Database::getInstance()->getConnection();

    [$rangeStart, $rangeEnd] = resolvePeriodRange($period, $dateFrom ?: null, $dateTo ?: null);

    $tabData = match ($tab) {
        'sales'            => buildSalesTabData($pdo, $rangeStart, $rangeEnd),
        'reservations'     => buildReservationsTabData($pdo, $rangeStart, $rangeEnd),
        'inventory'        => buildInventoryTabData($pdo, $rangeStart, $rangeEnd),
    };

    $openai = getOpenAiClient();
    if ($openai === null) {
        flash_set('error', "OpenAI isn't configured — ask the restaurant to add an API key first.");
        header('Location: analytics.php?' . $redirectQs);
        exit;
    }

    $insight = computeTabAiInsight($pdo, $openai, $tab, $tabData, $rangeStart, $rangeEnd, true, $ownerUserId);
    if ($insight === null) {
        flash_set('error', 'Not enough data yet in this period to generate an AI summary.');
    } else {
        flash_set('success', 'AI Insights regenerated.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Analytics', 'Regenerate AI insights', ?, ?)"
            )->execute([
                $ownerUserId, "Manually regenerated {$tab} AI Insights",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }
    }
} catch (Throwable $e) {
    error_log('analytics_action.php regenerate_insights failed: ' . $e->getMessage());
    flash_set('error', "AI Insights couldn't be regenerated right now. Please try again.");
}

header('Location: analytics.php?' . $redirectQs);
exit;
