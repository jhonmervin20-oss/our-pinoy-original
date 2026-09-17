<?php
/**
 * customer/feedback.php
 *
 * Customer feedback — a general submission form (a written comment; star
 * ratings were removed 2026-09-17 and feedback is words only now, always
 * unlinked from any specific reservation — this is a testimonial,
 * not a per-visit complaint form), the customer's own feedback history,
 * and a public feed of what other customers have said. Handles its own
 * POST like owner/settings/reservation_settings.php does — a plain form
 * submit, no AJAX round trip needed.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/openai.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/feedback_moderation.php';
require_once __DIR__ . '/includes/reservation_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['customer'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'feedbacks';
$customerId = Session::getUserId();

$pdo = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        flash_set('error', 'Your session expired. Please try again.');
        header('Location: feedback.php');
        exit;
    }

    $comment = trim((string)($_POST['comment'] ?? ''));

    // The comment is REQUIRED now. It used to be optional because a bare star
    // rating was still a submission; with stars gone, an empty comment is an
    // empty row -- nothing to publish, nothing to moderate, nothing to analyse.
    if ($comment === '') {
        flash_set('error', 'Please write your feedback before submitting.');
        header('Location: feedback.php');
        exit;
    }

    if (mb_strlen($comment) > 1000) {
        $comment = mb_substr($comment, 0, 1000);
    }

    // AI pipeline, three separate concerns in a fixed order. The comment is
    // guaranteed non-empty by the guard above, so all three always run.
    //
    //   1. Score toxicity across MODERATION_ATTRIBUTES.
    //   2. Analyse sentiment. UNCONDITIONALLY -- this is the fix. Sentiment
    //      used to run only inside the "passed moderation" branch, so every
    //      profane comment in this database has sentiment = NULL and never
    //      reached the owner's analytics. A customer angry enough to swear is
    //      the one whose opinion the owner most needs counted.
    //   3. Decide publication from the toxicity scores alone
    //      (resolveModerationOutcome()). Sentiment is NOT an input -- what a
    //      customer thought of the meal has no bearing on whether their
    //      language is safe to publish. It is analytics, and step 2 exists to
    //      feed the dashboard, not to gate step 3.
    //
    // Each model call is caught separately. A moderation failure must not cost
    // us the sentiment, and a sentiment failure must not cost us the safety
    // check -- collapsing both into one try/catch would let either wipe out
    // the other. Any failure falls back to 'pending' rather than ever assuming
    // approved, and the submission is never blocked or lost either way.
    $moderationStatus     = 'pending';
    $moderationReason     = null;
    $flaggedTerms         = null;
    $moderationScores     = null;
    $toxicitySeverity     = null;
    $moderationConfidence = null;
    $moderatedAt          = null;
    $aiProvider           = null;
    $aiModel              = null;
    $sentiment            = null;
    $sentimentConfidence  = null;
    $sentimentUrgency     = null;
    $sentimentSummary     = null;
    $recommendation       = null;
    $topics               = null;
    $sentimentAnalyzedAt  = null;

    // No "empty comment" branch any more -- the guard at the top of the POST
    // handler rejects those, so by here there is always something to score.
    {
        $openai = getOpenAiClient();
        if ($openai !== null) {
            $scores = null;

            try {
                $moderation       = moderateFeedback($openai, $comment);
                $scores           = $moderation['scores'];
                $moderationReason = $moderation['reason'];
                $flaggedTerms     = json_encode($moderation['flagged_terms']);
                $moderationScores = json_encode($scores);
                $moderatedAt      = date('Y-m-d H:i:s');
                $aiProvider       = AI_PROVIDER_NAME;
                $aiModel          = AI_MODEL_NAME;
            } catch (Throwable $e) {
                error_log('Feedback AI moderation failed: ' . $e->getMessage());
            }

            try {
                // The topic labels already in use are passed in so this comment
                // reuses one instead of coining a synonym -- see
                // existingFeedbackTopics(). Without it, "Waiting Time" and
                // "Service Speed" accumulate as two separate counts of one
                // real problem.
                $sentimentResult     = analyzeFeedbackSentiment($openai, $comment, existingFeedbackTopics($pdo));
                $sentiment           = $sentimentResult['sentiment'];
                $sentimentConfidence = $sentimentResult['confidence'];
                $sentimentUrgency    = $sentimentResult['urgency'];
                $sentimentSummary    = $sentimentResult['summary'];
                $recommendation      = $sentimentResult['recommendation'];
                $topics              = json_encode($sentimentResult['topics']);
                $sentimentAnalyzedAt = date('Y-m-d H:i:s');
            } catch (Throwable $e) {
                error_log('Feedback AI sentiment failed: ' . $e->getMessage());
            }

            // Only decide when we actually have scores. Without them the row
            // stays 'pending' -- unscored is not the same as safe, and the
            // owner's queue surfaces pending rows for exactly this reason.
            if ($scores !== null) {
                // Readability, not sentiment, is the second input now: a mild
                // comment publishes unless masking would leave nothing to read.
                $outcome = resolveModerationOutcome(
                    $scores,
                    commentSurvivesMasking($comment, $moderation['flagged_terms'] ?? [])
                );
                $moderationStatus     = $outcome['status'];
                $toxicitySeverity     = $outcome['severity'];
                $moderationConfidence = $outcome['confidence'];
            }
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO feedback (customer_id, comment, moderation_status, moderation_reason, flagged_terms,
            moderation_scores, toxicity_severity,
            moderation_confidence, moderated_at, ai_provider, ai_model,
            sentiment, sentiment_confidence, urgency, sentiment_summary, recommendation, topics, sentiment_analyzed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $customerId, $comment,
        $moderationStatus, $moderationReason, $flaggedTerms,
        $moderationScores, $toxicitySeverity,
        $moderationConfidence, $moderatedAt, $aiProvider, $aiModel,
        $sentiment, $sentimentConfidence, $sentimentUrgency, $sentimentSummary, $recommendation, $topics, $sentimentAnalyzedAt,
    ]);

    // Escalation follows SEVERITY, not the bare fact that something was
    // caught. A masked swear word in an otherwise warm review is not an incident and
    // must not page the owner -- doing that for every "putangina ang sarap!"
    // is how a real threat ends up buried in noise nobody reads any more.
    if (in_array($moderationStatus, ['flagged', 'blocked'], true)) {
        $feedbackId = (int)$pdo->lastInsertId();
        $isSevere   = $toxicitySeverity === 'severe';
        notifyUsersByRole(
            $pdo, ['owner'], 'feedback',
            $isSevere ? 'Severe feedback blocked — please review' : 'Feedback withheld for review',
            ($isSevere
                ? 'A submission was blocked for threatening, hateful or explicit content'
                : 'A submission was withheld from public display by AI moderation')
            . ($moderationReason ? ": {$moderationReason}" : '.'),
            'feedback_flagged', $feedbackId
        );
    }

    flash_set('success', 'Thanks for your feedback!');
    header('Location: feedback.php');
    exit;
}

$history = $pdo->prepare(
    "SELECT f.feedback_id, f.comment, f.created_at,
            f.moderation_status, f.flagged_terms
     FROM feedback f
     WHERE f.customer_id = ?
     ORDER BY f.created_at DESC"
);
$history->execute([$customerId]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

// The customer's own initial, for the avatar on their history cards. Their
// name isn't repeated on those cards (it's their own page), so this is the
// only thing the row needs from users.
$me = $pdo->prepare('SELECT first_name FROM users WHERE user_id = ?');
$me->execute([$customerId]);
$myInitial = mb_strtoupper(mb_substr((string)($me->fetchColumn() ?: '?'), 0, 1));

// PUBLISHABLE ONLY -- see isPubliclyVisibleStatus(). 'approved' is clean;
// 'masked' is mild profanity in an otherwise positive or neutral review, shown
// with the word starred out, because a swear used as an intensifier is still a
// real review and binning it loses genuine praise. 'flagged' and 'blocked'
// never appear here: those are aimed at somebody, and masking one leaves a
// a card reading "****" that tells this customer only that a stranger
// swore. They stay visible to the person who WROTE them (the history query
// above), masked and labelled, so they know it landed and why it isn't public.
// 'pending' is excluded for the older reason: no verdict yet.
$others = $pdo->prepare(
    "SELECT f.feedback_id, f.comment, f.created_at,
            f.moderation_status, f.flagged_terms,
            u.first_name, u.last_name
     FROM feedback f
     JOIN users u ON u.user_id = f.customer_id
     WHERE f.customer_id != ? AND f.moderation_status IN ('approved', 'masked')
     ORDER BY f.created_at DESC
     LIMIT 20"
);
$others->execute([$customerId]);
$others = $others->fetchAll(PDO::FETCH_ASSOC);

/**
 * Shared card markup for both "Your feedback" and "What others are saying".
 *
 * $isOwn marks the author's own history, and it decides how a flagged row is
 * treated. Only two cases reach here now that the others-feed selects approved
 * rows only:
 *
 *   own + flagged  -- show the REAL text with a "not shown publicly" badge.
 *     Masking someone's own words back at them explains nothing: they wrote
 *     the sentence, they can see it was censored, and they still don't know
 *     the review is being withheld. Showing it plainly, labelled, is the only
 *     version that tells them what actually happened.
 *   anything else  -- unchanged.
 *
 * The masking branch is kept for a flagged row arriving on any OTHER path, as
 * the same defence-in-depth index.php keeps: raw profanity must never reach a
 * customer who didn't write it, even if a query upstream is loosened later.
 * feedback.comment itself is never modified -- owner/feedback_moderation.php
 * reads it directly and is the one place the real text is always shown.
 */
function renderFeedbackCard(array $f, bool $showAuthor = false, ?string $avatarInitial = null, bool $isOwn = false): void
{
    $displayComment = $f['comment'];
    $status         = $f['moderation_status'] ?? null;
    $needsMask      = statusRequiresMasking($status);
    // Only the author is told WHY -- the badge would mean nothing on a
    // stranger's card, and 'masked' rows are published normally, so no
    // explanation is owed for those.
    $showWithheldNote = $isOwn && in_array($status, ['flagged', 'blocked'], true);

    if ($needsMask && !empty($displayComment)) {
        $terms  = json_decode($f['flagged_terms'] ?? '[]', true) ?: [];
        $masked = maskProfanityTerms($displayComment, $terms);
        // Never fall through to showing raw flagged text: if nothing
        // actually got masked -- no captured terms (e.g. a row flagged
        // before flagged_terms existed), or a captured term that didn't
        // match verbatim -- mask the whole comment instead of a partial
        // (or zero) redaction.
        $displayComment = ($masked !== $displayComment) ? $masked : str_repeat('*', min(mb_strlen($displayComment), 60));
    }
    // Purely presentational: lets the masked form of a flagged comment be
    // styled as a deliberate redaction. A row of bare asterisks in the same
    // type as normal text reads as a rendering fault rather than as something
    // the restaurant did on purpose.
    $isMasked = $needsMask && !empty($displayComment);

    $authorName = $showAuthor && !empty($f['first_name'])
        ? $f['first_name'] . ' ' . mb_substr($f['last_name'] ?? '', 0, 1) . '.'
        : null;

    // Others' cards take their initial from the author's name; the customer's
    // own cards get theirs passed in, since those rows don't carry a name.
    $initial = $avatarInitial;
    if ($initial === null && $showAuthor && !empty($f['first_name'])) {
        $initial = mb_strtoupper(mb_substr($f['first_name'], 0, 1));
    }
    ?>
    <div class="ca-feedback-card">
        <div class="ca-feedback-card-head">
            <?php if ($initial !== null): ?>
                <span class="ca-feedback-avatar" aria-hidden="true"><?= htmlspecialchars($initial) ?></span>
            <?php endif; ?>
            <div class="ca-feedback-card-meta">
                <?php if ($authorName !== null): ?>
                    <div class="ca-feedback-author"><?= htmlspecialchars($authorName) ?></div>
                <?php endif; ?>
                <?php // The five-star strip used to sit here. Feedback is words
                      // only now, so the card carries the author and the date. ?>
            </div>
            <span class="ca-feedback-date"><?= htmlspecialchars(date('M j, Y', strtotime($f['created_at']))) ?></span>
        </div>
        <?php if (!empty($displayComment)): ?>
            <p class="ca-feedback-comment<?= $isMasked ? ' is-masked' : '' ?>"><?= nl2br(htmlspecialchars($displayComment)) ?></p>
        <?php endif; ?>
        <?php /* Only ever on the author's own card -- see renderFeedbackCard()'s
                 docblock. Says the review is withheld and why, so a customer
                 whose post never appears publicly isn't left guessing whether
                 it saved at all. */ ?>
        <?php if ($showWithheldNote): ?>
            <p class="ca-feedback-status">
                <i class="ph ph-eye-slash" aria-hidden="true"></i>
                This review was flagged by our moderation check, so it isn't shown publicly.
            </p>
        <?php endif; ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Feedback | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">
<link rel="stylesheet" href="assets/css/feedback.css?v=<?= filemtime(__DIR__ . '/assets/css/feedback.css') ?>">
</head>
<body class="ca-body">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<!-- ca-feedback-page caps the reading width: this page is one narrow form
     plus a single column of short cards, and at the app's full 1280px the
     textarea sat marooned in empty space. -->
<main class="ca-content ca-feedback-page">
    <?= ca_flash_render() ?>

    <section class="ca-feedback-hero">
        <?php // Was ph-star, which now implies a rating the form no longer collects. ?>
        <span class="ca-feedback-hero-icon" aria-hidden="true"><i class="ph ph-chat-teardrop-text"></i></span>
        <div class="ca-feedback-hero-text">
            <h1>Feedback</h1>
            <p>Tell us how we're doing. Your feedback helps us serve you better!</p>
        </div>
    </section>

    <div class="ca-card ca-feedback-form-card">
        <h2 class="ca-card-title ca-feedback-card-title">
            <i class="ph ph-note-pencil" aria-hidden="true"></i> Share Your Feedback
        </h2>
        <p class="ca-feedback-form-sub">We'd love to hear your thoughts, suggestions, or any concerns.</p>
        <form method="POST" action="feedback.php" id="feedbackForm">
            <?= csrf_field() ?>

            <?php // No star picker: feedback is a written comment now, and the
                  // textarea below is the only input. It is REQUIRED -- the
                  // submit button stays disabled until something is typed,
                  // mirroring the server's own guard rather than adding a rule. ?>
            <div class="ca-form-group">
                <textarea id="comment" name="comment" class="ca-textarea ca-feedback-textarea" maxlength="1000" required placeholder="What did you enjoy? Anything we could do better?"></textarea>
                <div class="ca-feedback-counter"><span id="commentCount">0</span>/1000</div>
            </div>

            <button type="submit" class="ca-btn ca-btn-primary" id="feedbackSubmit">
                <i class="ph ph-paper-plane-tilt" aria-hidden="true"></i> Submit Feedback
            </button>
        </form>
    </div>

    <!-- Two panels side by side. Each keeps a fixed height and scrolls its own
         list internally, so the page itself stays put no matter how much
         feedback either side has -- no "View All" hop to a second screen. -->
    <div class="ca-feedback-columns">

        <section class="ca-card ca-feedback-panel">
            <div class="ca-feedback-panel-head">
                <h2><i class="ph ph-clock-counter-clockwise" aria-hidden="true"></i> Your Feedback History</h2>
                <?php if (!empty($history)): ?><span class="ca-feedback-count"><?= count($history) ?></span><?php endif; ?>
            </div>
            <?php if (empty($history)): ?>
                <div class="ca-empty-state">
                    <i class="ph ph-chat-circle-dots" aria-hidden="true"></i>
                    You haven't left any feedback yet.
                </div>
            <?php else: ?>
                <div class="ca-feedback-scroll" tabindex="0" role="region" aria-label="Your feedback history">
                    <?php foreach ($history as $f): renderFeedbackCard($f, false, $myInitial, true); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="ca-card ca-feedback-panel">
            <div class="ca-feedback-panel-head">
                <h2><i class="ph ph-users-three" aria-hidden="true"></i> What Others Are Saying</h2>
                <?php if (!empty($others)): ?><span class="ca-feedback-count"><?= count($others) ?></span><?php endif; ?>
            </div>
            <?php if (empty($others)): ?>
                <div class="ca-empty-state">
                    <i class="ph ph-users-three" aria-hidden="true"></i>
                    No other feedback yet.
                </div>
            <?php else: ?>
                <div class="ca-feedback-scroll" tabindex="0" role="region" aria-label="What others are saying">
                    <?php foreach ($others as $f): renderFeedbackCard($f, true); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<script>
/**
 * Presentation only -- no rule here that the server doesn't already enforce.
 * feedback.php rejects an empty comment and truncates one over 1000 chars;
 * this just shows the customer where they stand before they submit, instead of
 * letting them press the button and bounce back with a flash error.
 *
 * The star-rating half of this is gone with the stars themselves. What it used
 * to gate the submit button on -- "a rating is picked" -- is now "a comment has
 * been typed", which is the server's new requirement.
 */
(function () {
    'use strict';

    const form = document.getElementById('feedbackForm');
    if (!form) return;

    const submit = document.getElementById('feedbackSubmit');
    const comment = document.getElementById('comment');
    const count = document.getElementById('commentCount');

    function syncComment() {
        const text = comment ? comment.value.trim() : '';
        if (submit) submit.disabled = text === '';
        if (comment && count) {
            count.textContent = comment.value.length;
            count.parentElement.classList.toggle('is-near-limit', comment.value.length > 900);
        }
    }

    if (comment) {
        comment.addEventListener('input', syncComment);
    }

    syncComment();
})();
</script>

</body>
</html>
