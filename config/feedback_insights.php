<?php
/**
 * config/feedback_insights.php
 *
 * Aggregate-level AI Insights for owner/sentiment_analysis.php -- mirrors
 * config/inventory_alerts.php's sweepAutoPurchaseOrders() shape exactly:
 * throttled to at most once/day (system_settings.last_feedback_insights_sweep_at),
 * called opportunistically from owner/includes/header.php since this app
 * has no cron. Only ever generates a new feedback_ai_insights row when
 * there's a real minimum sample of analyzed feedback -- a one-shot "insight"
 * drawn from a handful of rows would be a real thesis-defense weak point.
 *
 * ANALYTICS COUNT EVERY REVIEW, PUBLISHED OR NOT. These queries used to filter
 * on moderation_status = 'approved', which quietly made every insight on the
 * dashboard a summary of politely-worded feedback only: a customer angry
 * enough to swear was withheld from public display AND dropped from the topic
 * tallies, the eligibility count, and the comment sample the narrative is
 * written from. Publication is a display decision about what strangers see on
 * a marketing page; it was never meant to decide what the owner is allowed to
 * know. The sentiment IS NOT NULL condition is what actually matters here --
 * it selects rows that have been analysed, which is the real precondition.
 */

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/feedback_moderation.php';

/** Below this many analyzed+approved feedback rows, don't bother generating insights at all. */
const FEEDBACK_INSIGHTS_MIN_SAMPLE = 5;

/** Throttle for the automatic sweep -- the manual "Regenerate now" button bypasses this. */
const FEEDBACK_INSIGHTS_SWEEP_THROTTLE_HOURS = 24;

/**
 * Real per-topic counts among approved+analyzed feedback of the given
 * sentiment -- this is what makes "Most Praised"/"Top Complaint Categories"
 * an honest tally instead of an LLM's one-shot guess.
 *
 * @return array<string,int> topic => count, sorted highest first
 */
/**
 * 'YYYY-MM' -> [start, end] datetime bounds, or null for all-time. One place
 * so every query in this file scopes a month identically -- the page filter,
 * the topic tallies, the eligible-row count and the AI input sample all have
 * to agree, or the panel reports a count it didn't actually analyze.
 */
function feedbackMonthBounds(?string $periodMonth): ?array
{
    if ($periodMonth === null || $periodMonth === '') {
        return null;
    }
    $start = new DateTime($periodMonth . '-01');
    $end   = (clone $start)->modify('last day of this month');
    return [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];
}

function computeFeedbackTopicCounts(PDO $db, string $sentiment, ?string $periodMonth = null): array
{
    $bounds = feedbackMonthBounds($periodMonth);
    $where  = $bounds !== null ? ' AND created_at BETWEEN ? AND ?' : '';

    $stmt = $db->prepare(
        "SELECT topics FROM feedback WHERE sentiment = ? AND topics IS NOT NULL" . $where
    );
    $stmt->execute($bounds !== null ? [$sentiment, $bounds[0], $bounds[1]] : [$sentiment]);

    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $topicsJson) {
        foreach ((json_decode((string)$topicsJson, true) ?: []) as $topic) {
            $counts[$topic] = ($counts[$topic] ?? 0) + 1;
        }
    }
    arsort($counts);

    return $counts;
}

/** How many feedback rows are actually eligible to feed AI Insights (approved + already sentiment-analyzed). */
function analyzedFeedbackCount(PDO $db, ?string $periodMonth = null): int
{
    $bounds = feedbackMonthBounds($periodMonth);
    if ($bounds === null) {
        return (int)$db->query(
            "SELECT COUNT(*) FROM feedback WHERE sentiment IS NOT NULL"
        )->fetchColumn();
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM feedback
         WHERE sentiment IS NOT NULL AND created_at BETWEEN ? AND ?"
    );
    $stmt->execute($bounds);
    return (int)$stmt->fetchColumn();
}

/**
 * The stored insight for a given scope -- the month-scoped row for
 * 'YYYY-MM', or the all-time row when $periodMonth is null/''. Returns null
 * when that scope has never been generated, which the page renders as an
 * honest empty state rather than falling back to a different period's text.
 */
function getFeedbackInsightForPeriod(PDO $db, ?string $periodMonth): ?array
{
    if ($periodMonth === null || $periodMonth === '') {
        $stmt = $db->query(
            "SELECT * FROM feedback_ai_insights WHERE period_month IS NULL ORDER BY insight_id DESC LIMIT 1"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT * FROM feedback_ai_insights WHERE period_month = ? ORDER BY insight_id DESC LIMIT 1"
        );
        $stmt->execute([$periodMonth]);
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Does the real work: real topic tallies + a sample of recent comments in,
 * one OpenAI call out, a new feedback_ai_insights row persisted. Shared by
 * the automatic sweep below and owner/sentiment_analysis_action.php's
 * manual "regenerate_insights" action -- $generatedBy is null for the
 * former, the owner's user_id for the latter.
 *
 * @throws \Throwable on a network failure or unparseable AI response --
 *   the caller decides how to surface that (sweep: log and skip; manual
 *   action: flash an error).
 */
function regenerateFeedbackInsights(PDO $db, OpenAiClient $openai, ?int $generatedBy, ?string $periodMonth = null): array
{
    $periodMonth = ($periodMonth === '') ? null : $periodMonth;
    $bounds = feedbackMonthBounds($periodMonth);

    $positiveTopics = computeFeedbackTopicCounts($db, 'positive', $periodMonth);
    $neutralTopics  = computeFeedbackTopicCounts($db, 'neutral',  $periodMonth);
    $negativeTopics = computeFeedbackTopicCounts($db, 'negative', $periodMonth);

    // The comment sample fed to the model is scoped the same way, so a
    // month's summary is written from that month's comments only. Each
    // comment carries its OWN sentiment -- without this the model was
    // handed one flat pile of text for "texture" and had no way to know
    // which comments actually belonged to the group it was writing about,
    // so a neutral summary could describe complaints borrowed from the
    // negative comments in the same sample.
    if ($bounds === null) {
        $recentStmt = $db->query(
            "SELECT comment, sentiment FROM feedback
             WHERE comment IS NOT NULL AND sentiment IS NOT NULL
             ORDER BY created_at DESC LIMIT 20"
        );
    } else {
        $recentStmt = $db->prepare(
            "SELECT comment, sentiment FROM feedback
             WHERE comment IS NOT NULL AND sentiment IS NOT NULL
               AND created_at BETWEEN ? AND ?
             ORDER BY created_at DESC LIMIT 20"
        );
        $recentStmt->execute($bounds);
    }
    $recentComments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    $result = generateFeedbackInsights($openai, $positiveTopics, $neutralTopics, $negativeTopics, $recentComments);

    // summary + recommendations are still written in their original shape --
    // owner/sentiment_analysis_pdf.php reads exactly those two and must keep
    // working. The new columns are additional, not a replacement.
    $db->prepare(
        "INSERT INTO feedback_ai_insights
            (period_month, overall_mood, summary, sentiment_insights,
             recommendations, recommended_actions, based_on_count, generated_at, generated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
    )->execute([
        $periodMonth,
        $result['overall_mood'],
        $result['summary'],
        json_encode($result['sentiment_insights']),
        json_encode($result['recommendations']),
        json_encode($result['recommended_actions']),
        analyzedFeedbackCount($db, $periodMonth),
        $generatedBy,
    ]);

    return $result;
}

/**
 * Opportunistic sweep, called from owner/includes/header.php alongside the
 * inventory/payroll sweeps. Skips (cheaply) unless: the throttle window has
 * passed, there's a real minimum sample, AND at least one feedback row has
 * been analyzed since the last insights row -- so a quiet week never
 * triggers a pointless regeneration with identical input.
 */
function sweepFeedbackInsights(PDO $db): void
{
    $lastRun = $db->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'last_feedback_insights_sweep_at'"
    )->fetchColumn();
    if (!empty($lastRun)) {
        $elapsedHours = (time() - strtotime($lastRun)) / 3600;
        if ($elapsedHours < FEEDBACK_INSIGHTS_SWEEP_THROTTLE_HOURS) {
            return;
        }
    }

    if (analyzedFeedbackCount($db) < FEEDBACK_INSIGHTS_MIN_SAMPLE) {
        return;
    }

    $lastInsightAt = $db->query(
        "SELECT generated_at FROM feedback_ai_insights WHERE period_month IS NULL ORDER BY insight_id DESC LIMIT 1"
    )->fetchColumn();

    if ($lastInsightAt) {
        $newSinceStmt = $db->prepare(
            "SELECT COUNT(*) FROM feedback WHERE sentiment_analyzed_at > ?"
        );
        $newSinceStmt->execute([$lastInsightAt]);
        if ((int)$newSinceStmt->fetchColumn() === 0) {
            return;
        }
    }

    // Mark as run now, before the (OpenAI-calling) work below, so a second
    // request arriving while this is still running doesn't also kick off a
    // duplicate sweep -- same convention as sweepAutoPurchaseOrders().
    $db->prepare(
        "UPDATE system_settings SET setting_value = NOW() WHERE setting_key = 'last_feedback_insights_sweep_at'"
    )->execute();

    $openai = getOpenAiClient();
    if ($openai === null) {
        return;
    }

    try {
        regenerateFeedbackInsights($db, $openai, null);
    } catch (Throwable $e) {
        error_log('sweepFeedbackInsights failed: ' . $e->getMessage());
    }
}
