<?php
/**
 * admin/sentiment_analysis_action.php
 *
 * Row-action dispatcher for admin/feedback_moderation.php -- approve / hide /
 * delete / rerun. A trimmed copy of owner/sentiment_analysis_action.php:
 * admin has no sentiment_analysis.php, so the `regenerate_insights` action
 * (and everything it needs) is left out rather than carried over unused.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/openai.php';
require_once __DIR__ . '/../config/feedback_moderation.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['admin'])) {
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

$feedbackId = trim((string)($_POST['feedback_id'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));

if (!in_array($action, ROW_ACTIONS, true)) {
    flash_set('error', 'Invalid request.');
    header('Location: feedback_moderation.php');
    exit;
}
if ($feedbackId === '' || !ctype_digit($feedbackId)) {
    flash_set('error', 'Invalid request.');
    header('Location: feedback_moderation.php');
    exit;
}

$actorName   = Session::getFullName() ?: 'Admin';
$actorUserId = Session::getUserId();

try {
    $pdo = Database::getInstance()->getConnection();

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
            $activityDescription = "Manually approved feedback #{$feedbackId} by {$actorName}";
            flash_set('success', 'Feedback approved and now visible to customers.');
            break;

        case 'hide':
            $pdo->prepare(
                "UPDATE feedback SET moderation_status = 'flagged', moderation_reason = ?, moderated_at = NOW() WHERE feedback_id = ?"
            )->execute(["Manually hidden by {$actorName}", $feedbackId]);
            $activityAction = 'Hide feedback';
            $activityDescription = "Manually hid feedback #{$feedbackId} by {$actorName}";
            flash_set('success', 'Feedback hidden from public view.');
            break;

        case 'delete':
            $pdo->prepare('DELETE FROM feedback WHERE feedback_id = ?')->execute([$feedbackId]);
            $activityAction = 'Delete feedback';
            $activityDescription = "Deleted feedback #{$feedbackId} by {$actorName}";
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
                // Mirrors owner/sentiment_analysis_action.php's rerun exactly:
                // score, analyse sentiment UNCONDITIONALLY, then decide -- a
                // re-run must never blank out an existing sentiment read just
                // because moderation alone was re-scored.
                $moderation = moderateFeedback($openai, $feedback['comment']);

                $sentimentResult = null;
                try {
                    // Live topic vocabulary passed in so a re-analyse settles on a
                    // label already in use rather than coining a new synonym --
                    // matches owner/sentiment_analysis_action.php.
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
                            sentiment = ?, sentiment_confidence = ?, sentiment_summary = ?, topics = ?, sentiment_analyzed_at = NOW()
                         WHERE feedback_id = ?"
                    )->execute([
                        $outcome['status'], $moderation['reason'], json_encode($moderation['flagged_terms']),
                        json_encode($moderation['scores']), $outcome['severity'], $outcome['confidence'],
                        AI_PROVIDER_NAME, AI_MODEL_NAME,
                        $sentimentResult['sentiment'], $sentimentResult['confidence'], $sentimentResult['summary'],
                        json_encode($sentimentResult['topics']),
                        $feedbackId,
                    ]);
                } else {
                    // Sentiment call failed. Write the moderation half and
                    // LEAVE the existing sentiment columns alone -- stale
                    // analysis is worth more than none.
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
                $activityDescription = "Re-ran AI moderation/sentiment on feedback #{$feedbackId} by {$actorName}";
            } catch (Throwable $e) {
                error_log('admin/sentiment_analysis_action.php rerun failed: ' . $e->getMessage());
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
                $actorUserId, $activityAction, $activityDescription,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }
    }
} catch (PDOException $e) {
    error_log('admin/sentiment_analysis_action.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong. Please try again.');
}

header('Location: feedback_moderation.php');
exit;
