<?php
/**
 * scripts/backfill_feedback_moderation.php
 *
 * Re-scores feedback written before multi-attribute moderation existed.
 *
 * Two kinds of row need it, and the second is the important one:
 *
 *   1. Rows with no moderation_scores/toxicity_severity at all -- they predate
 *      the columns, so the dashboard can only show them as "unscored". Rows
 *      scored before an ATTRIBUTE was added count here too: the query looks for
 *      the newest attribute key by name, so adding one to
 *      MODERATION_ATTRIBUTES and re-running this brings older rows up to the
 *      current model instead of leaving a silently mixed-vintage dataset.
 *   2. Rows that were FLAGGED under the old single-boolean model. Those never
 *      had sentiment computed, because the old pipeline skipped sentiment
 *      analysis for anything it flagged. Every angry customer in the database
 *      is therefore invisible to the sentiment reporting, which is the whole
 *      reason the rewrite happened. Backfilling is what actually recovers that
 *      history rather than only fixing it going forward.
 *
 * Safe to re-run: it only touches rows still missing scores, so a second run
 * after a partial failure picks up exactly what's left and spends nothing on
 * the rest. Each row is committed on its own -- a failure at row 7 keeps rows
 * 1-6.
 *
 * Usage:  php scripts/backfill_feedback_moderation.php [--dry-run] [--all] [--limit=N]
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/openai.php';
require_once __DIR__ . '/../config/feedback_moderation.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$dryRun = in_array('--dry-run', $argv, true);
// --all re-scores every commented row, not just stale ones. Needed when the
// POLICY changes rather than the attribute set: the stored scores are still
// current, but the status derived from them is not.
$forceAll = in_array('--all', $argv, true);
$limit  = 500;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int)$m[1]);
    }
}

$pdo    = Database::getInstance()->getConnection();
$openai = getOpenAiClient();
if ($openai === null) {
    exit("OpenAI is not configured (OPENAI_API_KEY missing). Nothing to do.\n");
}

// "Stale" means scored before some attribute existed. Built from
// MODERATION_ATTRIBUTES rather than naming one, so adding an attribute
// automatically makes older rows eligible on the next run -- otherwise the
// table quietly ends up mixed-vintage, with some rows scored on seven
// dimensions and some on eight, and no way to tell which from the dashboard.
$staleChecks = [];
foreach (array_keys(MODERATION_ATTRIBUTES) as $attributeKey) {
    $staleChecks[] = 'moderation_scores NOT LIKE ' . $pdo->quote('%"' . $attributeKey . '"%');
}
$staleSql = '(' . implode(' OR ', $staleChecks) . ')';
$forceSql = $forceAll ? '1=1 OR ' : '';

// A bare star rating has no text to score and is already 'approved' -- it must
// not be dragged into this and charged an API call for an empty string.
$rows = $pdo->query(
    "SELECT feedback_id, rating, comment, moderation_status, sentiment
     FROM feedback
     WHERE comment IS NOT NULL AND TRIM(comment) <> ''
       AND ({$forceSql}moderation_scores IS NULL OR toxicity_severity IS NULL OR sentiment IS NULL
            OR {$staleSql})
     ORDER BY feedback_id ASC
     LIMIT {$limit}"
)->fetchAll(PDO::FETCH_ASSOC);

printf("%d row(s) to backfill%s\n\n", count($rows), $dryRun ? '  [DRY RUN — no writes]' : '');
if (!$rows) {
    exit("Nothing to do.\n");
}

$update = $pdo->prepare(
    "UPDATE feedback SET
        moderation_status = ?, moderation_reason = ?, flagged_terms = ?,
        moderation_scores = ?, toxicity_severity = ?, moderation_confidence = ?,
        moderated_at = NOW(), ai_provider = ?, ai_model = ?,
        sentiment = ?, sentiment_confidence = ?, sentiment_summary = ?, topics = ?,
        sentiment_analyzed_at = NOW()
     WHERE feedback_id = ?"
);

$done = 0;
$failed = 0;
foreach ($rows as $row) {
    $id      = (int)$row['feedback_id'];
    $comment = (string)$row['comment'];

    try {
        $moderation = moderateFeedback($openai, $comment);
        $sentimentResult = analyzeFeedbackSentiment($openai, $comment, (int)$row['rating']);
        $outcome = resolveModerationOutcome(
            $moderation['scores'],
            commentSurvivesMasking($comment, $moderation['flagged_terms'])
        );

        printf(
            "#%-4d %-38s  %-9s -> %-9s  %-8s  %s\n",
            $id,
            '"' . mb_strimwidth($comment, 0, 34, '…') . '"',
            $row['moderation_status'],
            $outcome['status'],
            $outcome['severity'],
            ($row['sentiment'] ?? '(none)') . ' -> ' . $sentimentResult['sentiment']
        );

        if (!$dryRun) {
            $update->execute([
                $outcome['status'], $moderation['reason'], json_encode($moderation['flagged_terms']),
                json_encode($moderation['scores']), $outcome['severity'], $outcome['confidence'],
                AI_PROVIDER_NAME, AI_MODEL_NAME,
                $sentimentResult['sentiment'], $sentimentResult['confidence'],
                $sentimentResult['summary'], json_encode($sentimentResult['topics']),
                $id,
            ]);
        }
        $done++;
    } catch (Throwable $e) {
        // Left untouched, so a later run retries exactly this row.
        printf("#%-4d FAILED: %s\n", $id, $e->getMessage());
        $failed++;
    }
}

printf("\n%d backfilled, %d failed.%s\n", $done, $failed, $dryRun ? '  (dry run — nothing written)' : '');
