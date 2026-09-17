<?php
/**
 * owner/sentiment_analysis_action.php
 *
 * One dispatcher for every row action on sentiment_analysis.php --
 * approve / hide / delete / rerun -- via a POST `action` field, matching
 * this app's existing single-file-per-related-action-set convention (e.g.
 * purchase_orders/purchase_order_status.php). `regenerate_insights` is the
 * one dashboard-wide action here (no feedback_id -- it recomputes the AI
 * Insights panel, not a single row).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/openai.php';
require_once __DIR__ . '/../config/feedback_moderation.php';
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feedback_moderation.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: feedback_moderation.php');
    exit;
}

const ROW_ACTIONS = ['approve', 'hide', 'delete', 'rerun'];
const VALID_ACTIONS = [...ROW_ACTIONS, 'regenerate_insights', 'refresh_analysis'];

$feedbackId = trim((string)($_POST['feedback_id'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));

if (!in_array($action, VALID_ACTIONS, true)) {
    flash_set('error', 'Invalid request.');
    header('Location: feedback_moderation.php');
    exit;
}
if (in_array($action, ROW_ACTIONS, true) && ($feedbackId === '' || !ctype_digit($feedbackId))) {
    flash_set('error', 'Invalid request.');
    header('Location: feedback_moderation.php');
    exit;
}

$ownerName = Session::getFullName() ?: 'Owner';
$ownerUserId = Session::getUserId();

try {
    $pdo = Database::getInstance()->getConnection();

    /* "Refresh analysis" on the sentiment page's filter card.
       Re-runs analyzeFeedbackSentiment() over every commented row in the
       selected period and rewrites ONLY the sentiment-side columns.
       moderateFeedback() is deliberately NOT re-run: it decides published vs
       withheld, and silently flipping a row's visibility while the owner
       thought they were refreshing analytics would be a real surprise. */
    if ($action === 'refresh_analysis') {
        $monthRaw    = (string)($_POST['month'] ?? 'all');
        $periodMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthRaw) ? $monthRaw : null;
        $backTo      = 'sentiment_analysis.php?month=' . urlencode($periodMonth ?? 'all');

        $openai = getOpenAiClient();
        if ($openai === null) {
            flash_set('error', "OpenAI isn't configured — ask the restaurant to add an API key first.");
            header('Location: ' . $backTo);
            exit;
        }

        $bounds = feedbackMonthBounds($periodMonth);
        $sql    = "SELECT feedback_id, comment FROM feedback
                   WHERE comment IS NOT NULL AND TRIM(comment) <> ''"
                . ($bounds !== null ? ' AND created_at BETWEEN ? AND ?' : '')
                . ' ORDER BY feedback_id';
        $listStmt = $pdo->prepare($sql);
        $listStmt->execute($bounds ?? []);
        $targets = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        $upd = $pdo->prepare(
            "UPDATE feedback SET sentiment = ?, sentiment_confidence = ?, urgency = ?,
                sentiment_summary = ?, recommendation = ?, topics = ?, sentiment_analyzed_at = NOW()
             WHERE feedback_id = ?"
        );

        $done = 0; $failed = 0;
        foreach ($targets as $t) {
            try {
                // Vocabulary re-read per row so labels settled earlier in this
                // run are visible to the rows after it, which is what lets the
                // topic names converge instead of drifting apart again.
                $s = analyzeFeedbackSentiment($openai, $t['comment'], existingFeedbackTopics($pdo));
            } catch (Throwable $e) {
                error_log('refresh_analysis failed on feedback ' . $t['feedback_id'] . ': ' . $e->getMessage());
                $failed++;
                continue;
            }
            $upd->execute([
                $s['sentiment'], $s['confidence'], $s['urgency'], $s['summary'],
                $s['recommendation'], json_encode($s['topics']), $t['feedback_id'],
            ]);
            $done++;
        }

        if ($done > 0) {
            flash_set('success', "Re-analysed {$done} feedback item" . ($done === 1 ? '' : 's')
                . ($failed > 0 ? " — {$failed} could not be reached and kept their previous analysis." : '.'));
        } else {
            flash_set('error', $targets ? 'Could not reach the AI service. Nothing was changed.' : 'No feedback with comments in this period.');
        }

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Feedback', 'Refresh AI analysis', ?, ?)"
            )->execute([
                $ownerUserId, "Re-analysed {$done} feedback item(s) by {$ownerName}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }

        header('Location: ' . $backTo);
        exit;
    }

    if ($action === 'regenerate_insights') {
        // Regenerate for whichever period the owner is looking at, and send
        // them back to it -- otherwise "Regenerate now" on a month view would
        // silently rewrite the all-time snapshot instead.
        $monthRaw    = (string)($_POST['month'] ?? 'all');
        $periodMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthRaw) ? $monthRaw : null;
        // Insights belong to the sentiment page, so this one action returns
        // there while every moderation action below returns to the moderation
        // page. Same file, two destinations, because they serve two pages.
        $backTo      = 'sentiment_analysis.php?month=' . urlencode($periodMonth ?? 'all');

        if (analyzedFeedbackCount($pdo, $periodMonth) < FEEDBACK_INSIGHTS_MIN_SAMPLE) {
            flash_set('error', 'Not enough analyzed feedback in that period yet to generate insights (need at least ' . FEEDBACK_INSIGHTS_MIN_SAMPLE . ').');
            header('Location: ' . $backTo);
            exit;
        }

        $openai = getOpenAiClient();
        if ($openai === null) {
            flash_set('error', "OpenAI isn't configured — ask the restaurant to add an API key first.");
            header('Location: ' . $backTo);
            exit;
        }

        try {
            regenerateFeedbackInsights($pdo, $openai, $ownerUserId, $periodMonth);
            flash_set('success', 'AI Insights regenerated.');

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Feedback', 'Regenerate AI insights', ?, ?)"
                )->execute([
                    $ownerUserId, "Manually regenerated AI Insights by {$ownerName}",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort.
            }
        } catch (Throwable $e) {
            error_log('sentiment_analysis_action.php regenerate_insights failed: ' . $e->getMessage());
            flash_set('error', "AI Insights couldn't be regenerated right now. Please try again.");
        }

        // $backTo, NOT feedback_moderation.php. The two early exits above
        // already returned to the sentiment page, so a regenerate that FAILED
        // stayed put while one that SUCCEEDED threw the owner onto a different
        // page -- and the success flash ("AI Insights regenerated") then
        // appeared over a table that does not show insights at all.
        header('Location: ' . $backTo);
        exit;
    }

    $stmt = $pdo->prepare('SELECT feedback_id, comment, moderation_status FROM feedback WHERE feedback_id = ?');
    $stmt->execute([$feedbackId]);
    $feedback = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$feedback) {
        flash_set('error', 'That feedback no longer exists.');
        header('Location: feedback_moderation.php');
        exit;
    }

    $activityAction = null;
    $activityDescription = null;

    switch ($action) {
        case 'approve':
            $pdo->prepare("UPDATE feedback SET moderation_status = 'approved', moderated_at = NOW() WHERE feedback_id = ?")
                ->execute([$feedbackId]);
            $activityAction = 'Approve feedback';
            $activityDescription = "Manually approved feedback #{$feedbackId} by {$ownerName}";
            flash_set('success', 'Feedback approved and now visible to customers.');
            break;

        case 'hide':
            $pdo->prepare(
                "UPDATE feedback SET moderation_status = 'flagged', moderation_reason = ?, moderated_at = NOW() WHERE feedback_id = ?"
            )->execute(["Manually hidden by {$ownerName}", $feedbackId]);
            $activityAction = 'Hide feedback';
            $activityDescription = "Manually hid feedback #{$feedbackId} by {$ownerName}";
            flash_set('success', 'Feedback hidden from public view.');
            break;

        case 'delete':
            $pdo->prepare('DELETE FROM feedback WHERE feedback_id = ?')->execute([$feedbackId]);
            $activityAction = 'Delete feedback';
            $activityDescription = "Deleted feedback #{$feedbackId} by {$ownerName}";
            flash_set('success', 'Feedback deleted.');
            break;

        case 'rerun':
            if ($feedback['comment'] === null || trim((string)$feedback['comment']) === '') {
                flash_set('error', 'There is no comment text to analyze on this feedback.');
                break;
            }

            $openai = getOpenAiClient();
            if ($openai === null) {
                flash_set('error', "OpenAI isn't configured — ask the restaurant to add an API key before re-running AI analysis.");
                break;
            }

            try {
                // Mirrors customer/feedback.php's submission pipeline exactly:
                // score, analyse sentiment UNCONDITIONALLY, then decide. This
                // block used to do the opposite -- on a flag it wrote
                // sentiment = NULL, actively erasing the analysis. Re-running
                // moderation on an angry review would blank the very data the
                // dashboard needs, so a re-analyse could silently make the
                // reporting worse than before it ran.
                $moderation = moderateFeedback($openai, $feedback['comment']);

                $sentimentResult = null;
                try {
                    // Live topic vocabulary passed in, so a re-analyse settles on
                    // a label already in use rather than coining a new synonym.
                    $sentimentResult = analyzeFeedbackSentiment($openai, $feedback['comment'], existingFeedbackTopics($pdo));
                } catch (Throwable $se) {
                    error_log('Re-analyze sentiment failed: ' . $se->getMessage());
                }

                $outcome = resolveModerationOutcome(
                    $moderation['scores'],
                    commentSurvivesMasking($feedback['comment'], $moderation['flagged_terms'])
                );

                if ($sentimentResult !== null) {
                    $pdo->prepare(
                        "UPDATE feedback SET moderation_status = ?, moderation_reason = ?, flagged_terms = ?,
                            moderation_scores = ?, toxicity_severity = ?, moderation_confidence = ?,
                            moderated_at = NOW(), ai_provider = ?, ai_model = ?,
                            sentiment = ?, sentiment_confidence = ?, urgency = ?, sentiment_summary = ?, recommendation = ?, topics = ?, sentiment_analyzed_at = NOW()
                         WHERE feedback_id = ?"
                    )->execute([
                        $outcome['status'], $moderation['reason'], json_encode($moderation['flagged_terms']),
                        json_encode($moderation['scores']), $outcome['severity'], $outcome['confidence'],
                        AI_PROVIDER_NAME, AI_MODEL_NAME,
                        $sentimentResult['sentiment'], $sentimentResult['confidence'], $sentimentResult['urgency'],
                        $sentimentResult['summary'], $sentimentResult['recommendation'],
                        json_encode($sentimentResult['topics']),
                        $feedbackId,
                    ]);
                } else {
                    // Sentiment call failed. Write the moderation half and
                    // LEAVE the existing sentiment columns alone -- stale
                    // analysis is worth more than none, and overwriting them
                    // with NULL is the exact data loss described above.
                    $pdo->prepare(
                        "UPDATE feedback SET moderation_status = ?, moderation_reason = ?, flagged_terms = ?,
                            moderation_scores = ?, toxicity_severity = ?, moderation_confidence = ?,
                            moderated_at = NOW(), ai_provider = ?, ai_model = ?
                         WHERE feedback_id = ?"
                    )->execute([
                        $outcome['status'], $moderation['reason'], json_encode($moderation['flagged_terms']),
                        json_encode($moderation['scores']), $outcome['severity'], $outcome['confidence'],
                        AI_PROVIDER_NAME, AI_MODEL_NAME, $feedbackId,
                    ]);
                }

                $statusMeta = moderationStatusMeta($outcome['status']);
                flash_set('success', 'Re-analyzed: ' . strtolower($statusMeta['label'])
                    . ' (' . severityMeta($outcome['severity'])['label'] . ' language'
                    . ($sentimentResult ? ', ' . $sentimentResult['sentiment'] . ' sentiment' : '') . ').');

                // Escalate on severity, matching the submission path -- a
                // masked swear in a five-star review is not an incident.
                if (in_array($outcome['status'], ['flagged', 'blocked'], true)) {
                    require_once __DIR__ . '/../config/notifications.php';
                    notifyUsersByRole(
                        $pdo, ['owner'], 'feedback',
                        $outcome['severity'] === 'severe' ? 'Severe feedback blocked — please review' : 'Feedback withheld for review',
                        "A re-run of AI moderation withheld feedback #{$feedbackId}: {$moderation['reason']}",
                        'feedback_flagged', (int)$feedbackId
                    );
                }

                $activityAction = 'Re-run AI analysis';
                $activityDescription = "Re-ran AI moderation/sentiment on feedback #{$feedbackId} by {$ownerName}";
            } catch (Throwable $e) {
                error_log('sentiment_analysis_action.php rerun failed: ' . $e->getMessage());
                $pdo->prepare("UPDATE feedback SET moderation_status = 'pending', moderated_at = NOW() WHERE feedback_id = ?")
                    ->execute([$feedbackId]);
                flash_set('error', "The AI re-run didn't complete — feedback left as pending. Please try again.");
            }
            break;
    }

    if ($activityAction !== null) {
        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Feedback', ?, ?, ?)"
            )->execute([
                $ownerUserId, $activityAction, $activityDescription,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }
    }
} catch (PDOException $e) {
    error_log('sentiment_analysis_action.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong. Please try again.');
}

header('Location: feedback_moderation.php');
exit;
