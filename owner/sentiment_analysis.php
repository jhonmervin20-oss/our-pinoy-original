<?php
/**
 * owner/sentiment_analysis.php
 *
 * HOW CUSTOMERS FEEL. One of two pages customer feedback is split across:
 *
 *   this page                what customers think   positive / neutral / negative
 *   feedback_moderation.php  what may be published  published / masked / withheld / blocked
 *
 * They were one page, and combining them taught the wrong lesson. The
 * moderation engine scores toxicity and sentiment as INDEPENDENT axes (see
 * config/feedback_moderation.php) precisely because a rude review is not a bad
 * review -- "putangina ang sarap ng pagkain" is five-star praise. A single
 * dashboard putting profanity counts beside satisfaction counts quietly
 * implies the opposite, and they are read at different rhythms anyway:
 * sentiment is a weekly "how are we doing", moderation is a daily queue.
 *
 * So nothing here filters on moderation status. Every analysed review counts
 * toward these numbers whether or not it was fit to publish -- a customer
 * angry enough to swear is the one whose opinion matters most, and dropping
 * them was the exact blind spot the rewrite closed.
 *
 * No "Reservation" column anywhere here -- feedback is a general testimonial,
 * not tied to a specific visit.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/feedback_insights.php';
require_once __DIR__ . '/../config/feedback_moderation.php';
require_once __DIR__ . '/includes/feedback_page_functions.php'; // feedbackPeriodSql(), renderFeedbackPageSwitch()

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'sentiment_analysis';
$pageTitle  = 'Sentiment Analysis';
$ownerBase  = '';

// Five statuses now, not three -- 'masked' publishes with the word starred
// out, 'blocked' is withheld AND escalated. See resolveModerationOutcome().
const MODERATION_PILL_CLASS = [
    'pending' => 'is-warning', 'approved' => 'is-success', 'masked' => 'is-warning',
    'flagged' => 'is-danger',  'blocked'  => 'is-danger',
];
const SENTIMENT_PILL_CLASS  = ['positive' => 'is-success', 'neutral' => 'is-neutral', 'negative' => 'is-danger'];

// -- Page-wide month filter. Defaults to the current month when ?month
// isn't given at all (or is invalid) -- pass ?month=all explicitly to see
// everything. $monthFilter itself stays '' for "all time" internally; only
// the raw $_GET value distinguishes "not specified" from "explicitly all".
// When set, this scopes every section INCLUDING the AI Insights panel --
// its topic tallies are live per-period queries and its written summary is
// looked up by period_month, so a month with no feedback shows an empty
// panel rather than another period's text. The only exception is the
// Monthly Sentiment Trend chart, whose entire purpose is comparing across
// months (filtering it to one month would defeat that).
$monthParam = $_GET['month'] ?? null;
if ($monthParam === 'all') {
    $monthFilter = '';
} elseif ($monthParam !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthFilter = $monthParam;
} else {
    $monthFilter = date('Y-m');
}
$monthRangeFrom = null;
$monthRangeTo   = null;
if ($monthFilter !== '') {
    $monthStart = new DateTime($monthFilter . '-01');
    $monthEnd   = (clone $monthStart)->modify('last day of this month');
    $monthRangeFrom = $monthStart->format('Y-m-d 00:00:00');
    $monthRangeTo   = $monthEnd->format('Y-m-d 23:59:59');
}

// -- Filters for the Feedback Moderation table (all server-side $_GET, same shape as the other owner list pages' filter forms) --
$sentimentFilter = in_array($_GET['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true) ? $_GET['sentiment'] : '';
$searchFilter    = trim((string)($_GET['search'] ?? ''));
$dateFrom        = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? $_GET['date_from'] : '';
$dateTo          = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? $_GET['date_to'] : '';

$counts       = ['pending' => 0, 'approved' => 0, 'masked' => 0, 'flagged' => 0, 'blocked' => 0];
$sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
$positiveTopics = [];
$neutralTopics  = [];
$negativeTopics = [];
$recentFeedback = [];
$totalFeedback  = 0;
$monthlyTrend = [];
$trendIsDaily = false;
$dbError      = null;

/**
 * Builds a query string carrying forward the current filters (from $_GET,
 * a superglobal -- always in scope, unlike a plain outer variable), with
 * $overrides replacing specific keys (e.g. a new page number, or clearing
 * the status filter). Used by every filter chip/pagination link on this
 * page so navigating never silently drops the filters already applied.
 */
function sentQs(array $overrides = []): string
{
    global $monthFilter;
    $base = [
        // Carries the *resolved* month (with the 'all' sentinel for "all
        // time"), not the raw $_GET value -- so every link stays pinned to
        // whatever the page is currently showing, including the default
        // current-month, instead of re-deriving "today" on each click.
        'month'     => $monthFilter !== '' ? $monthFilter : 'all',
        'status'    => $_GET['status'] ?? '',
        'sentiment' => $_GET['sentiment'] ?? '',
        'search'    => $_GET['search'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to'   => $_GET['date_to'] ?? '',
        'page'      => $_GET['page'] ?? '',
    ];
    $params = array_merge($base, $overrides);
    if ((int)($params['page'] ?? 0) <= 1) {
        unset($params['page']);
    }
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    return http_build_query($params);
}

try {
    $pdo = Database::getInstance()->getConnection();

    // Period WHERE fragment + params, reused by every section below that's
    // meant to be month-scoped. Blank when $monthFilter isn't set, so every
    // query below degrades exactly to its original all-time form.
    $periodSql    = $monthFilter !== '' ? ' AND f.created_at BETWEEN ? AND ?' : '';
    $periodParams = $monthFilter !== '' ? [$monthRangeFrom, $monthRangeTo] : [];

    $countsStmt = $pdo->prepare(
        "SELECT moderation_status, COUNT(*) AS cnt FROM feedback f WHERE 1=1{$periodSql} GROUP BY moderation_status"
    );
    $countsStmt->execute($periodParams);
    foreach ($countsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['moderation_status']] = (int)$row['cnt'];
    }

    $sentCountsStmt = $pdo->prepare(
        "SELECT sentiment, COUNT(*) AS cnt FROM feedback f WHERE sentiment IS NOT NULL{$periodSql} GROUP BY sentiment"
    );
    $sentCountsStmt->execute($periodParams);
    foreach ($sentCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sentimentCounts[$row['sentiment']] = (int)$row['cnt'];
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM feedback f WHERE 1=1{$periodSql}");
    $totalStmt->execute($periodParams);
    $totalFeedback = (int)$totalStmt->fetchColumn();

    // Every commented review in the period, newest first -- paged in the
    // browser by the shared makeTablePager(), same as inventory.php's tables.
    // Client-side because Prev/Next then only toggle row visibility: no page
    // load, so the reader never gets thrown back to the top of a long
    // dashboard mid-scroll.
    //
    // Safe to load whole because the month filter already bounds it, and
    // defaults to the CURRENT month rather than all time. If feedback outgrows
    // that, the fix is a server-side LIMIT here, not a bigger fetch.
    //
    // Read-only on purpose -- this page reports how customers feel; deciding
    // what gets published belongs to feedback_moderation.php, and approve/
    // withhold buttons in both places would give one decision two homes.
    $recentStmt = $pdo->prepare(
        "SELECT f.feedback_id, f.comment, f.sentiment, f.sentiment_summary,
                f.recommendation, f.topics, f.moderation_status, f.flagged_terms, f.created_at,
                u.first_name, u.last_name
         FROM feedback f
         JOIN users u ON u.user_id = f.customer_id
         WHERE f.comment IS NOT NULL AND TRIM(f.comment) <> ''{$periodSql}
         ORDER BY f.created_at DESC"
    );
    $recentStmt->execute($periodParams);
    $recentFeedback = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // "Top complaint categories" card, next to the period-scoped Sentiment
    // Distribution chart. Kept as its own query rather than reusing
    // computeFeedbackTopicCounts() below because this one counts only
    // negative topics for this card's own ranking; both are period-scoped
    // the same way.
    // NOT restricted to published feedback. A complaint does not stop being a
    // complaint because the customer swore while making it -- restricting this
    // to 'approved' meant the complaint ranking was built from politely-worded
    // reviews only, which is exactly the blind spot this rewrite exists to
    // close. Publication is a display decision; this is an analytics question.
    $topComplaintStmt = $pdo->prepare(
        "SELECT topics FROM feedback f
         WHERE sentiment = 'negative' AND topics IS NOT NULL{$periodSql}"
    );
    $topComplaintStmt->execute($periodParams);
    $topComplaintTopics = [];
    foreach ($topComplaintStmt->fetchAll(PDO::FETCH_COLUMN) as $topicsJson) {
        foreach ((json_decode((string)$topicsJson, true) ?: []) as $topic) {
            $topComplaintTopics[$topic] = ($topComplaintTopics[$topic] ?? 0) + 1;
        }
    }
    arsort($topComplaintTopics);

    // All-time by design -- see the doc comment on $monthFilter above.
    // The whole AI Insights panel now follows the month filter: the topic
    // tallies are live per-period queries, and the written summary is the
    // insight generated FOR that period (period_month), never another
    // period's text shown under a filter it doesn't match.
    $positiveTopics = computeFeedbackTopicCounts($pdo, 'positive', $monthFilter);
    $neutralTopics  = computeFeedbackTopicCounts($pdo, 'neutral',  $monthFilter);
    $negativeTopics = computeFeedbackTopicCounts($pdo, 'negative', $monthFilter);
    // The insights panel is gone from this page, so neither the eligibility
    // count nor the stored narrative is read here any more. Both are still
    // produced by the daily sweep and still printed by sentiment_analysis_pdf.php.

    // Trend follows the month filter by changing GRANULARITY rather than by
    // narrowing the same query. Filtering monthly buckets to one month leaves a
    // single point, which is a dot, not a trend -- so a selected month is
    // plotted DAY BY DAY within itself, and all-time stays month by month.
    // Either way the chart answers "which way is this going" over the period
    // actually being looked at.
    $trendIsDaily = $monthFilter !== '';
    $trendFormat  = $trendIsDaily ? '%Y-%m-%d' : '%Y-%m';

    if ($trendIsDaily) {
        $trendStmt = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '{$trendFormat}') AS bucket, sentiment, COUNT(*) AS cnt
             FROM feedback
             WHERE sentiment IS NOT NULL AND created_at BETWEEN ? AND ?
             GROUP BY bucket, sentiment ORDER BY bucket ASC"
        );
        $trendStmt->execute([$monthRangeFrom, $monthRangeTo]);
    } else {
        $trendStmt = $pdo->query(
            "SELECT DATE_FORMAT(created_at, '{$trendFormat}') AS bucket, sentiment, COUNT(*) AS cnt
             FROM feedback WHERE sentiment IS NOT NULL
             GROUP BY bucket, sentiment ORDER BY bucket ASC"
        );
    }
    $observed = [];
    foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $observed[$row['bucket']][$row['sentiment']] = (int)$row['cnt'];
    }

    // Fill the gaps between the first and last bucket that actually holds
    // feedback. A line chart spaces its points EVENLY, so plotting only the
    // buckets with data draws Jul 30 and Aug 18 side by side and reads as a
    // steady slide over two days rather than a gap of nearly three weeks. The
    // zeros are true -- no feedback arrived -- and the shape is only honest
    // once they are there.
    if (!empty($observed)) {
        $keys  = array_keys($observed);
        $first = reset($keys);
        $last  = end($keys);
        $step  = $trendIsDaily ? '+1 day' : '+1 month';
        $cursor = new DateTime($trendIsDaily ? $first : $first . '-01');
        $end    = new DateTime($trendIsDaily ? $last : $last . '-01');
        $fmt    = $trendIsDaily ? 'Y-m-d' : 'Y-m';
        // Bounded so a bad date can never spin here: a month has at most 31
        // days, and the all-time view is capped at ten years of months.
        $guard  = $trendIsDaily ? 40 : 130;
        while ($cursor <= $end && $guard-- > 0) {
            $key = $cursor->format($fmt);
            $monthlyTrend[$key] = [
                'positive' => (int)($observed[$key]['positive'] ?? 0),
                'neutral'  => (int)($observed[$key]['neutral']  ?? 0),
                'negative' => (int)($observed[$key]['negative'] ?? 0),
            ];
            $cursor->modify($step);
        }
    }


} catch (PDOException $e) {
    $dbError = "Couldn't load feedback data. Please refresh this page.";
}

$totalAnalyzed = array_sum($sentimentCounts);

// $recommendations / $recommendedActions were decoded here for the "Recommended
// actions" panel, which this page no longer renders -- each feedback row now
// carries its own recommendation instead. Both COLUMNS are still written by
// generateFeedbackInsights(), and sentiment_analysis_pdf.php still reads
// `recommendations` for the printed report, so nothing was dropped at the data
// end; only this page stopped decoding values it no longer shows.

// Needs attention = anything a customer cannot see. Shown here as a pointer to
// the moderation page, not as something actionable on this one.
$needsAttention = (int)$counts['pending'] + (int)$counts['flagged'] + (int)$counts['blocked'];
$topicTotals    = ['positive' => $positiveTopics, 'neutral' => $neutralTopics, 'negative' => $negativeTopics];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Sentiment Analysis | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
<style>
    /* The AI's per-comment recommendation, sitting under the comment it is
       about. Scoped here rather than added to owner-panel.css, which exists as
       five un-synced copies in this project -- one page's new rule does not
       justify touching all five. */
    /* Date over time, so the column stays narrow instead of one long line. */
    .sa-date-cell{ white-space: nowrap; vertical-align: top; font-size: 0.82rem; }
    .sa-date-cell span{ display: block; color: var(--op-ink-faint); font-size: 0.74rem; }

    /* The comment is the widest thing on the row and the reason the row exists,
       so it gets room and wraps; the author sits under it in faint type. */
    .sa-comment-cell{
        white-space: normal;
        vertical-align: top;
        min-width: 220px;
        max-width: 340px;
        line-height: 1.45;
    }
    .sa-comment-author{
        display: block;
        margin-top: 4px;
        font-size: 0.72rem;
        color: var(--op-ink-faint);
    }

    /* Prose, not a value -- it has to wrap. */
    .sa-rec-cell{
        white-space: normal;
        vertical-align: top;
        min-width: 220px;
        max-width: 360px;
        font-size: 0.8rem;
        line-height: 1.45;
        color: var(--op-ink-soft);
    }
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/includes/header.php'; ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            

            <!-- Month filter -- scopes the headline cards, charts (except the
                 cross-month trend), flagged feedback, and (as a
                 default) the moderation table below. -->
            <?php // The card is a flex row holding TWO forms: the month filter is a
                  // GET, the refresh is a POST, and a submit button only ever submits
                  // the form it sits in -- so they cannot be merged. ?>
            <div class="owner-card" style="margin-bottom:20px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:14px 20px;">
                <?php // Only as wide as its own controls. It used to be flex:1 with the
                      // "Showing:" caption inside it on margin-left:auto, which made the
                      // form claim the whole row and pushed Refresh onto a second line. ?>
                <form method="get" action="sentiment_analysis.php" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
                    <input type="hidden" name="sentiment" value="<?= htmlspecialchars($sentimentFilter) ?>">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($searchFilter) ?>">
                    <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                    <label for="monthFilterInput" style="font-weight:600;font-size:0.85rem;">Month</label>
                    <input type="month" id="monthFilterInput" name="month" class="owner-input" style="max-width:170px;" value="<?= htmlspecialchars($monthFilter) ?>">
                    <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">Apply</button>
                    <?php if ($monthFilter !== ''): ?>
                        <a href="sentiment_analysis.php?<?= htmlspecialchars(sentQs(['month' => 'all', 'page' => ''])) ?>" class="owner-btn owner-btn-secondary owner-btn-sm">All time</a>
                    <?php endif; ?>
                </form>

                <?php // Re-runs the AI on the feedback currently in view: sentiment,
                      // topics, urgency and the recommendation. It deliberately does
                      // NOT re-run moderation -- that decides published vs withheld,
                      // and flipping a row's visibility is a different decision from
                      // refreshing its analysis. One API call per comment, so the
                      // button says how many and asks first. ?>
                <form method="post" action="sentiment_analysis_action.php"
                      onsubmit="return confirm('Re-run AI analysis on <?= (int)$totalFeedback ?> feedback item<?= $totalFeedback === 1 ? '' : 's' ?>? This calls the AI once per comment and overwrites their sentiment, category and recommendation. Published/withheld status is not touched.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refresh_analysis">
                    <input type="hidden" name="month" value="<?= htmlspecialchars($monthFilter !== '' ? $monthFilter : 'all') ?>">
                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm"<?= $totalFeedback === 0 ? ' disabled' : '' ?>>
                        <i class="ph ph-arrows-clockwise" aria-hidden="true"></i> Refresh analysis
                    </button>
                </form>

                <?php // Last item in the card, not inside the month form, so margin-left:auto
                      // pushes it against the right edge of the CARD rather than squeezing
                      // the buttons. min-width lets it drop to its own line on a phone
                      // instead of crushing the controls beside it. ?>
                <span style="margin-left:auto;color:var(--op-ink-faint);font-size:0.82rem;min-width:min(100%,260px);">
                    Showing: <strong><?= $monthFilter !== '' ? htmlspecialchars(date('F Y', strtotime($monthFilter . '-01'))) : 'All time' ?></strong>
                    &middot; the monthly trend chart below always covers all months, so periods can be compared.
                </span>
            </div>

            <!-- ================= Headline metrics =================
                 Six cards covering the two questions this page answers: how
                 satisfied are customers, and how confident can we be in that
                 answer. "Needs attention" is the exception -- it is a pointer
                 to the moderation page, included because a satisfaction figure
                 computed while reviews sit unscored deserves that caveat next
                 to it rather than three scrolls away. -->
<?php // The house .owner-summary-card, the same one report.php, the dashboards
      // and Demand Forecasting use -- this page was the only owner screen with a
      // card of its own design, so its status row read as belonging to a
      // different system. The sentiment colours stay on the value and the icon:
      // green/amber/red here encode meaning, not decoration, and losing them
      // would cost more than the consistency gains. ?>
            <div class="owner-form-grid sa-kpis">
                <?php // No "Needs action" KPI card here by request. Urgency still
                      // shows per row, beside each comment's sentiment, which is where
                      // it can actually be acted on. ?>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Total feedback</div>
                        <div class="owner-summary-card-value"><?= (int)$totalFeedback ?></div>
                    </div>
                    <i class="ph ph-chats-circle" aria-hidden="true"></i>
                </div>

                <?php
                $sentimentCards = [
                    ['positive', 'Positive', 'ph-smiley',     '#4c9a63'],
                    ['neutral',  'Neutral',  'ph-smiley-meh', '#9c7734'],
                    ['negative', 'Negative', 'ph-smiley-sad', '#b3483f'],
                ];
                foreach ($sentimentCards as [$key, $label, $icon, $colour]):
                    $n = (int)$sentimentCounts[$key];
                ?>
                    <div class="owner-summary-card">
                        <div>
                            <div class="owner-summary-card-label"><?= $label ?></div>
                            <?php /* The count, not a percentage. A share over a
                                     handful of reviews overstates its own precision:
                                     "33%" reads as a measurement when it is one
                                     review out of three. */ ?>
                            <div class="owner-summary-card-value" style="color:<?= $colour ?>;"><?= $n ?></div>
                        </div>
                        <i class="ph <?= $icon ?>" style="color:<?= $colour ?>;" aria-hidden="true"></i>
                    </div>
                <?php endforeach; ?>

                <?php // The only card that navigates -- it points at the moderation
                      // page rather than reporting something this page can act on. ?>
               
            </div>

            <!-- ================= AI insights, split by sentiment =================
                 One blended paragraph over every review averages a delighted
                 customer with an angry one and describes neither -- it reliably
                 produced "customers have mixed opinions about the food quality",
                 which is true of every restaurant and actionable for none. Each
                 group now speaks for itself and carries its own next move.

                 The topic chips are NOT model output: they are live GROUP BY
                 counts over feedback.topics, so a number on this card can never
                 be something the AI made up. -->
            <?php // The "AI insights by sentiment" panel and the "Recommended actions"
                  // panel both lived here. Aggregate advice written once a month could
                  // be pasted onto any month; each feedback row now carries its own
                  // recommendation, which is the thing an owner can actually act on.
                  // feedback_ai_insights and its generation are LEFT IN PLACE --
                  // sentiment_analysis_pdf.php still prints the written summary. ?>

            <!-- ================= Analytics band =================
                 Distribution, trend and complaint ranking read together: the
                 first says what the balance is, the second whether it is
                 moving, the third what is driving it. Splitting them across
                 the page meant scrolling between three halves of one answer. -->
            <div class="sa-analytics-row">
                <div class="owner-card">
                    <div class="owner-card-head"><h2 class="owner-card-title">Sentiment distribution</h2></div>
                    <?php if ($totalAnalyzed === 0): ?>
                        <div class="sa-empty sa-empty-sm">
                            <i class="ph ph-chart-donut" aria-hidden="true"></i>
                            <span>No analyzed feedback in this period.</span>
                        </div>
                    <?php else: ?>
                        <div class="sa-chart"><canvas id="sentimentDoughnut"></canvas></div>
                        <div class="sa-chart-total">Total: <strong><?= (int)$totalAnalyzed ?></strong> analyzed review<?= $totalAnalyzed === 1 ? '' : 's' ?></div>
                    <?php endif; ?>
                </div>

                <div class="owner-card">
                    <div class="owner-card-head">
                        <h2 class="owner-card-title">Sentiment trend</h2>
                        <span class="owner-card-subtitle">
                            <?= $trendIsDaily
                                ? 'Day by day through ' . htmlspecialchars((new DateTime($monthFilter . '-01'))->format('F Y'))
                                : 'Month by month, all time' ?>
                        </span>
                    </div>
                    <?php
                    // A trend needs at least two points to BE a trend. A single
                    // point is a dot the eye reads as a flat line, which is a
                    // claim about direction the data cannot support.
                    $trendUnit = $trendIsDaily ? 'day' : 'month';
                    ?>
                    <?php if (count($monthlyTrend) < 2): ?>
                        <div class="sa-empty sa-empty-sm">
                            <i class="ph ph-chart-line-up" aria-hidden="true"></i>
                            <strong>Not enough data yet</strong>
                            <span>Feedback on at least two <?= $trendUnit ?>s is needed before a direction means anything<?= $trendIsDaily ? ' within one month' : '' ?>.</span>
                            <span class="sa-empty-note">
                                <?= count($monthlyTrend) ?> <?= $trendUnit ?><?= count($monthlyTrend) === 1 ? '' : 's' ?> of data
                                <?php if ($trendIsDaily): ?>&middot; switch to All time to compare months<?php endif; ?>
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="sa-chart"><canvas id="sentimentTrend"></canvas></div>
                    <?php endif; ?>
                </div>

                <div class="owner-card">
                    <div class="owner-card-head">
                        <h2 class="owner-card-title">Top complaint categories</h2>
                        <span class="owner-card-subtitle">Share of negative reviews</span>
                    </div>
                    <?php if (empty($topComplaintTopics)): ?>
                        <div class="sa-empty sa-empty-sm">
                            <i class="ph ph-thumbs-up" aria-hidden="true"></i>
                            <span>No negative feedback analyzed<?= $monthFilter !== '' ? ' this month' : '' ?>.</span>
                        </div>
                    <?php else: ?>
                        <div class="sa-complaints">
                            <?php
                            $complaintTotal = array_sum($topComplaintTopics);
                            $rank = 0;
                            foreach (array_slice($topComplaintTopics, 0, 5, true) as $topic => $cnt):
                                $rank++;
                                $pct = $complaintTotal > 0 ? (int)round(($cnt / $complaintTotal) * 100) : 0;
                            ?>
                                <div class="sa-complaint">
                                    <div class="sa-complaint-head">
                                        <span><?= $rank ?>. <?= htmlspecialchars($topic) ?></span>
                                        <span class="sa-complaint-pct"><?= (int)$cnt ?> (<?= $pct ?>%)</span>
                                    </div>
                                    <div class="sa-complaint-bar"><span style="width:<?= max(2, $pct) ?>%;"></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ================= Recent feedback =================
                 READ-ONLY on purpose. This page reports how customers feel;
                 deciding what gets published belongs to feedback_moderation.php.
                 Putting approve/withhold controls in both places would give the
                 same decision two homes and no single answer to who made it. -->
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Recent feedback</h2>
                        <?php /* Live count, kept truthful by the pager's onUpdate --
                                 a static number would disagree with the table the
                                 moment anything filtered it. */ ?>
                        <span class="owner-card-subtitle" id="saFeedbackCount"><?= count($recentFeedback) ?> with a written feedbacks</span>
                    </div>
                </div>
                <?php if (empty($recentFeedback)): ?>
                    <div class="sa-empty sa-empty-sm">
                        <i class="ph ph-chat-dots" aria-hidden="true"></i>
                        <span>No written feedback in this period.</span>
                    </div>
                <?php else: ?>
                    <div class="owner-table-wrap" id="saFeedbackTable">
                        <table class="owner-table">
                            <thead><tr><th>Date</th><th>Customer Feedback</th><th>Sentiment</th><th>Category</th><th>Recommended Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($recentFeedback as $f):
                                    $comment = (string)$f['comment'];
                                    $topics = json_decode((string)($f['topics'] ?? '[]'), true) ?: [];
                                ?>
                                <tr data-feedback-id="<?= (int)$f['feedback_id'] ?>">
                                    <td class="sa-date-cell">
                                        <?= htmlspecialchars(date('M j, Y', strtotime($f['created_at']))) ?>
                                        <span><?= htmlspecialchars(date('g:i A', strtotime($f['created_at']))) ?></span>
                                    </td>
                                    <?php // The customer's name rides under their words rather than taking a
                                          // column of its own: several recommendations say "contact this
                                          // customer", so who said it has to stay reachable. ?>
                                    <td class="sa-comment-cell">
                                        <?= htmlspecialchars($comment) ?>
                                        <span class="sa-comment-author"><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></span>
                                    </td>
                                    <?php // Sentiment only. The Urgent / Follow up badges that used to sit
                                          // under this pill were removed by request. feedback.urgency is
                                          // still analysed and stored, and the printed report still counts
                                          // it -- it just no longer appears in this table. ?>
                                    <td>
                                        <?php if (!empty($f['sentiment'])): ?>
                                            <span class="owner-status-pill <?= SENTIMENT_PILL_CLASS[$f['sentiment']] ?? 'is-neutral' ?>"><?= htmlspecialchars(ucfirst($f['sentiment'])) ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--op-ink-faint);">Not analyzed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($topics): ?>
                                            <?php foreach ($topics as $t): ?>
                                                <span class="sa-topic-chip is-plain"><?= htmlspecialchars((string)$t) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color:var(--op-ink-faint);" title="This comment carries a sentiment but names no specific concern.">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sa-rec-cell">
                                        <?php if (!empty($f['recommendation'])): ?>
                                            <?= htmlspecialchars($f['recommendation']) ?>
                                        <?php else: ?>
                                            <span style="color:var(--op-ink-faint);" title="Not analysed yet — re-run analysis on this row to generate one.">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php /* Same markup and classes as inventory.php's pagers, driven by
                             the shared makeTablePager(). `hidden` starts set and the
                             pager clears it once it knows the page count. */ ?>
                    <div class="owner-pagination" id="saFeedbackPagination" hidden>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="saFeedbackPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                        <span class="owner-pagination-info" id="saFeedbackPageInfo">Page 1 of 1</span>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="saFeedbackPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                    </div>
                <?php endif; ?>
            </div>

        </main>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="assets/js/table-pager.js?v=<?= filemtime(__DIR__ . '/assets/js/table-pager.js') ?>"></script>
<script>
(function () {
    // Recent-feedback pager. Same helper and markup as inventory.php, so
    // Prev/Next only hide and show rows -- no navigation, nothing to scroll
    // back from on a page this tall.
    const saRows = Array.from(document.querySelectorAll('#saFeedbackTable tbody tr[data-feedback-id]'));
    if (saRows.length) {
        window.makeTablePager({
            rows: saRows,
            pageSize: 5,
            container: document.getElementById('saFeedbackPagination'),
            prevBtn: document.getElementById('saFeedbackPagePrev'),
            nextBtn: document.getElementById('saFeedbackPageNext'),
            info: document.getElementById('saFeedbackPageInfo'),
            onUpdate: (visibleCount) => {
                const label = document.getElementById('saFeedbackCount');
                if (label) label.textContent = visibleCount + ' with a written comment';
            },
        }).update(true);
    }

    // Both canvases are now rendered CONDITIONALLY -- an empty period shows an
    // explanatory panel instead. Chart.js throws on a null canvas, and an
    // uncaught throw here would take the rest of this script with it, so each
    // chart is built only if its element actually exists.
    const doughnutEl = document.getElementById('sentimentDoughnut');
    if (doughnutEl) new Chart(doughnutEl, {
        type: 'doughnut',
        data: {
            labels: ['Positive', 'Neutral', 'Negative'],
            datasets: [{
                data: [<?= (int)$sentimentCounts['positive'] ?>, <?= (int)$sentimentCounts['neutral'] ?>, <?= (int)$sentimentCounts['negative'] ?>],
                backgroundColor: ['#4c9a63', '#9c7734', '#b3483f'],
            }],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } },
    });

    // Trend chart. STACKED BARS, not lines.
    //
    // Lines cannot show these numbers. The three series are counts of
    // mutually-exclusive categories, so they share values constantly -- every
    // quiet day puts all three at 0 on top of each other, and August 21 had
    // positive and negative both climbing 0 -> 1 along the identical path, so
    // the negative line was drawn underneath the positive one and vanished. A
    // real review looked like missing data.
    //
    // Stacking makes occlusion structurally impossible, and for discrete counts
    // it also shows the thing a line hides: total volume per period, and how it
    // splits. beginAtZero + integer ticks because half a review does not exist.
    const trendEl = document.getElementById('sentimentTrend');
    const trendData = <?= json_encode($monthlyTrend) ?>;
    const buckets = Object.keys(trendData);
    if (trendEl) new Chart(trendEl, {
        type: 'bar',
        data: {
            labels: buckets,
            datasets: [
                { label: 'Positive', data: buckets.map(b => trendData[b].positive || 0), backgroundColor: '#4c9a63' },
                { label: 'Neutral',  data: buckets.map(b => trendData[b].neutral  || 0), backgroundColor: '#9c7734' },
                { label: 'Negative', data: buckets.map(b => trendData[b].negative || 0), backgroundColor: '#b3483f' },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: {
                    // Chart.js omits zero-value entries from a stacked tooltip by
                    // default, which reads as "no data for that sentiment" rather
                    // than "none that day". Spelling out 0 keeps the tooltip a
                    // complete answer.
                    label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y}`,
                } },
            },
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
            },
        },
    });
})();
</script>

</body>
</html>
