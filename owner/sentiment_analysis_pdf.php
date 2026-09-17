<?php
/**
 * owner/sentiment_analysis_pdf.php
 *
 * Streams a one-page printable summary of the Sentiment Analysis dashboard
 * as a real PDF via Dompdf (mirrors purchase_orders/purchase_order_pdf.php's
 * pattern) -- the cards, AI Insights, and top praise/complaint lists, not
 * the raw feedback table (that's what Export CSV is for).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/feedback_insights.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

// Same month filter as sentiment_analysis.php, scoping the Overview cards
// only (mirrors the dashboard's headline cards) -- Most praised/Most
// reported/AI summary below stay all-time, mirroring the dashboard's AI
// Insights panel, which is tied to feedback_ai_insights' own snapshot.
$monthParam = $_GET['month'] ?? null;
if ($monthParam === 'all') {
    $monthFilter = '';
} elseif ($monthParam !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthFilter = $monthParam;
} else {
    $monthFilter = date('Y-m');
}
$periodSql    = '';
$periodParams = [];
if ($monthFilter !== '') {
    $monthStart = new DateTime($monthFilter . '-01');
    $monthEnd   = (clone $monthStart)->modify('last day of this month');
    $periodSql    = ' AND f.created_at BETWEEN ? AND ?';
    $periodParams = [$monthStart->format('Y-m-d 00:00:00'), $monthEnd->format('Y-m-d 23:59:59')];
}

$restaurantName = $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
)->fetchColumn() ?: 'OPO! Our Pinoy Original';

// Five statuses since multi-attribute moderation. Without 'masked' and
// 'blocked' initialised here the PDF's rates silently under-count: a masked
// review IS published, and a blocked one IS withheld, so omitting them makes
// both headline percentages wrong rather than merely incomplete.
$counts = ['pending' => 0, 'approved' => 0, 'masked' => 0, 'flagged' => 0, 'blocked' => 0];
$countsStmt = $pdo->prepare("SELECT moderation_status, COUNT(*) AS cnt FROM feedback f WHERE 1=1{$periodSql} GROUP BY moderation_status");
$countsStmt->execute($periodParams);
foreach ($countsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $counts[$row['moderation_status']] = (int)$row['cnt'];
}
$sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
$sentCountsStmt = $pdo->prepare("SELECT sentiment, COUNT(*) AS cnt FROM feedback f WHERE sentiment IS NOT NULL{$periodSql} GROUP BY sentiment");
$sentCountsStmt->execute($periodParams);
foreach ($sentCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $sentimentCounts[$row['sentiment']] = (int)$row['cnt'];
}
$totalFeedback = array_sum($counts);
// Published = approved + masked (a masked review is on the public site, just
// with the swear word starred). Withheld = flagged + blocked.
$publishedCount = $counts['approved'] + $counts['masked'];
$withheldCount  = $counts['flagged'] + $counts['blocked'];
$approvalRate = $totalFeedback > 0 ? round(($publishedCount / $totalFeedback) * 100) : 0;
$flaggedRate  = $totalFeedback > 0 ? round(($withheldCount / $totalFeedback) * 100) : 0;
// Replaces the old "Average rating" card -- star ratings were removed from the
// system, and leaving the cell out would break this 4-across card grid. This is
// the more useful figure anyway: how much of the period's feedback actually
// needs somebody to do something.
$needsActionStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM feedback f WHERE f.urgency IN ('urgent','elevated'){$periodSql}"
);
$needsActionStmt->execute($periodParams);
$needsActionCount = (int)$needsActionStmt->fetchColumn();

$positiveTopics = computeFeedbackTopicCounts($pdo, 'positive');
$negativeTopics = computeFeedbackTopicCounts($pdo, 'negative');

$latestInsight = $pdo->query("SELECT * FROM feedback_ai_insights ORDER BY insight_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
$recommendations = $latestInsight['recommendations'] ?? null ? (json_decode($latestInsight['recommendations'], true) ?: []) : [];

function pdfListRows(array $counts): string
{
    $html = '';
    foreach (array_slice($counts, 0, 5, true) as $topic => $cnt) {
        $html .= '<li>' . htmlspecialchars($topic) . ' (' . $cnt . ')</li>';
    }
    return $html ?: '<li style="color:#888;">No data yet</li>';
}

$html = '
<html><head><meta charset="UTF-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2b2118; }
    h1 { font-size: 18px; margin-bottom: 2px; }
    h2 { font-size: 13px; margin: 16px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
    .meta { color: #666; font-size: 9px; margin-bottom: 12px; }
    table.cards { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.cards td { width: 25%; border: 1px solid #ddd; padding: 8px; text-align: center; }
    .card-value { font-size: 16px; font-weight: bold; }
    .card-label { font-size: 9px; color: #666; }
    ul { margin: 4px 0; padding-left: 18px; }
</style>
</head><body>
<h1>' . htmlspecialchars($restaurantName) . ' &mdash; Sentiment Analysis Summary</h1>
<div class="meta">Generated ' . date('M j, Y g:i A') . ' &middot; Overview period: ' . ($monthFilter !== '' ? htmlspecialchars(date('F Y', strtotime($monthFilter . '-01'))) : 'All time') . ' &middot; Most praised/reported and AI summary below are always all-time</div>

<h2>Overview</h2>
<table class="cards">
<tr>
<td><div class="card-value">' . (int)$totalFeedback . '</div><div class="card-label">Total feedback</div></td>
<td><div class="card-value">' . (int)$publishedCount . '</div><div class="card-label">Published</div></td>
<td><div class="card-value">' . (int)$withheldCount . '</div><div class="card-label">Withheld</div></td>
<td><div class="card-value">' . $needsActionCount . '</div><div class="card-label">Needs action</div></td>
</tr>
<tr>
<td><div class="card-value">' . (int)$sentimentCounts['positive'] . '</div><div class="card-label">Positive</div></td>
<td><div class="card-value">' . (int)$sentimentCounts['neutral'] . '</div><div class="card-label">Neutral</div></td>
<td><div class="card-value">' . (int)$sentimentCounts['negative'] . '</div><div class="card-label">Negative</div></td>
<td><div class="card-value">' . $approvalRate . '% / ' . $flaggedRate . '%</div><div class="card-label">Approval / Flagged rate</div></td>
</tr>
</table>

<h2>Most praised</h2>
<ul>' . pdfListRows($positiveTopics) . '</ul>

<h2>Most reported issues</h2>
<ul>' . pdfListRows($negativeTopics) . '</ul>

<h2>AI summary</h2>
<p>' . ($latestInsight ? nl2br(htmlspecialchars($latestInsight['summary'])) : 'No AI summary generated yet.') . '</p>';

if (!empty($recommendations)) {
    $html .= '<h2>Recommended focus</h2><ul>';
    foreach ($recommendations as $rec) {
        $html .= '<li>' . htmlspecialchars($rec) . '</li>';
    }
    $html .= '</ul>';
}

$html .= '</body></html>';

require_once __DIR__ . '/../vendor/autoload.php';

$dompdf = new \Dompdf\Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="sentiment_analysis_' . date('Y-m-d') . '.pdf"');
echo $dompdf->output();
exit;
