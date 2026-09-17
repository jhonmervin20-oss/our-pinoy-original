<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/feedback_insights.php';
require_once __DIR__ . '/../config/feedback_moderation.php';
require_once __DIR__ . '/../owner/includes/feedback_page_functions.php'; // moderationStatusMeta(), feedbackPeriodSql(), renderFeedbackPageSwitch()

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'feedback_moderation';
$pageTitle  = 'Feedback Moderation';

// Five statuses now, not three -- 'masked' publishes with the word starred
// out, 'blocked' is withheld AND escalated. See resolveModerationOutcome().
const MODERATION_PILL_CLASS = [
    'pending' => 'is-warning', 'approved' => 'is-success', 'masked' => 'is-warning',
    'flagged' => 'is-danger',  'blocked'  => 'is-danger',
];
const SENTIMENT_PILL_CLASS  = ['positive' => 'is-success', 'neutral' => 'is-neutral', 'negative' => 'is-danger'];


const FEEDBACK_WITHHELD_STATUSES = ['flagged', 'blocked'];
$rawStatus = $_GET['status'] ?? '';
if (in_array($rawStatus, ['flagged', 'blocked', 'withheld'], true)) {
    $statusFilter = 'withheld';
} elseif (in_array($rawStatus, ['pending', 'approved', 'masked'], true)) {
    $statusFilter = $rawStatus;
} else {
    $statusFilter = '';
}
$sentimentFilter = in_array($_GET['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true) ? $_GET['sentiment'] : '';
$searchFilter    = trim((string)($_GET['search'] ?? ''));
$dateFrom        = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? $_GET['date_from'] : '';
$dateTo          = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? $_GET['date_to'] : '';

$feedbackRows = [];
$totalCount   = 0;
$counts       = ['pending' => 0, 'approved' => 0, 'masked' => 0, 'flagged' => 0, 'blocked' => 0];
$sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
$dbError      = null;


try {
    $pdo = Database::getInstance()->getConnection();

    // All-time headline figures. These deliberately ignore the table's own
    // date range: they are the reference the filtered table is read against,
    // so they need to stay still while the table below moves.

    $countsStmt = $pdo->prepare(
        'SELECT moderation_status, COUNT(*) AS cnt FROM feedback GROUP BY moderation_status'
    );
    $countsStmt->execute();
    foreach ($countsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['moderation_status']] = (int)$row['cnt'];
    }

    $sentCountsStmt = $pdo->prepare(
        'SELECT sentiment, COUNT(*) AS cnt FROM feedback WHERE sentiment IS NOT NULL GROUP BY sentiment'
    );
    $sentCountsStmt->execute();
    foreach ($sentCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sentimentCounts[$row['sentiment']] = (int)$row['cnt'];
    }

    // -- Main Feedback Moderation table, filtered --
    // date_from/date_to (this table's own pre-existing custom range) win
    // when explicitly set; otherwise the page's month filter is the default
    // range; otherwise (neither set) it's all-time, same as before this
    // filter existed.
    $where  = [];
    $params = [];
    if ($statusFilter === 'withheld') {
        $where[] = 'f.moderation_status IN (' . implode(',', array_fill(0, count(FEEDBACK_WITHHELD_STATUSES), '?')) . ')';
        foreach (FEEDBACK_WITHHELD_STATUSES as $withheldStatus) {
            $params[] = $withheldStatus;
        }
    } elseif ($statusFilter !== '') {
        $where[] = 'f.moderation_status = ?';
        $params[] = $statusFilter;
    }
    if ($sentimentFilter !== '') {
        $where[] = 'f.sentiment = ?';
        $params[] = $sentimentFilter;
    }
    if ($searchFilter !== '') {
        $where[] = "CONCAT(u.first_name, ' ', u.last_name) LIKE ?";
        $params[] = '%' . $searchFilter . '%';
    }
    if ($dateFrom !== '' || $dateTo !== '') {
        if ($dateFrom !== '') {
            $where[] = 'f.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'f.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // Every matching row is fetched, and paging happens in the browser -- the
    // same shape inventory.php uses, and the reason its pager never scrolls
    // you anywhere: clicking Next changes nothing but which rows are hidden,
    // so there is no navigation to land at the top of.
    //
    // Safe to load in full because this result set is already narrowed twice
    // over: the month filter defaults to the CURRENT month rather than
    // all-time, and the status/sentiment/search/date filters cut it
    // further. If feedback ever outgrows that, the fix is to put the LIMIT
    // back and page on the server -- not to widen this.
    $stmt = $pdo->prepare(
        "SELECT f.feedback_id, f.comment, f.moderation_status, f.moderation_reason,
                f.moderation_confidence, f.sentiment, f.sentiment_confidence, f.sentiment_summary,
                f.created_at, u.first_name, u.last_name
         FROM feedback f
         JOIN users u ON u.user_id = f.customer_id
         {$whereSql}
         ORDER BY f.created_at DESC"
    );
    $stmt->execute($params);
    $feedbackRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalCount   = count($feedbackRows);
} catch (PDOException $e) {
    $dbError = "Couldn't load feedback data. Please refresh this page.";
}

$totalAnalyzed = array_sum($sentimentCounts);
$sentimentPct = [
    'positive' => $totalAnalyzed > 0 ? round(($sentimentCounts['positive'] / $totalAnalyzed) * 100) : 0,
    'neutral'  => $totalAnalyzed > 0 ? round(($sentimentCounts['neutral'] / $totalAnalyzed) * 100) : 0,
    'negative' => $totalAnalyzed > 0 ? round(($sentimentCounts['negative'] / $totalAnalyzed) * 100) : 0,
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Feedback Moderation | Admin Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
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

            <?php if (isset($_GET['feedback_id'])): ?>
            <div class="owner-alert" style="background:var(--op-canvas);color:var(--op-ink-soft);">
                <i class="ph ph-funnel" aria-hidden="true"></i>
                <span>Showing the feedback from your notification.</span>
                <a href="feedback_moderation.php" class="owner-btn owner-btn-secondary owner-btn-sm" style="margin-left:auto;">Show all</a>
            </div>
            <?php endif; ?>

            <!-- Filters, in their own card, separate from the table they filter. -->
            <div class="owner-card" style="margin-bottom:20px;">
                <form method="get" action="feedback_moderation.php" class="owner-inv-filters" style="margin:0;" data-autosubmit="feedback">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($searchFilter) ?>" placeholder="Search customer name&hellip;" autocomplete="off">
                    </div>
                    <?php /* One option per real status. 'Published' and 'Published masked'
                             are both live on the public site and are kept apart because the
                             difference matters: the second is a review the old model would
                             have thrown away entirely. */ ?>
                    <select name="status" class="owner-select">
                        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All statuses</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Published</option>
                        <option value="masked" <?= $statusFilter === 'masked' ? 'selected' : '' ?>>Masked</option>
                        <option value="withheld" <?= $statusFilter === 'withheld' ? 'selected' : '' ?>>Withheld</option>
                    </select>
                    <select name="sentiment" class="owner-select">
                        <option value="">All sentiments</option>
                        <option value="positive" <?= $sentimentFilter === 'positive' ? 'selected' : '' ?>>Positive</option>
                        <option value="neutral" <?= $sentimentFilter === 'neutral' ? 'selected' : '' ?>>Neutral</option>
                        <option value="negative" <?= $sentimentFilter === 'negative' ? 'selected' : '' ?>>Negative</option>
                    </select>
                    <input type="date" name="date_from" class="owner-input" value="<?= htmlspecialchars($dateFrom) ?>" style="max-width:160px;">
                    <input type="date" name="date_to" class="owner-input" value="<?= htmlspecialchars($dateTo) ?>" style="max-width:160px;">
                    <a href="feedback_moderation.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                </form>
                
            </div>

            <!-- Feedback Moderation table. The id scopes the client-side
                 pager's row query (see the script at the foot of this page) so
                 it can never pick up rows from another table. -->
            <div class="owner-card" id="feedbackTable">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Feedback</th>
                                <th>Sentiment</th>
                                <th>Moderation</th>
                                <th>AI reason</th>
                                <th>Submitted</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($feedbackRows)): ?>
                                <tr><td colspan="7" class="owner-table-empty">No feedback matches these filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ($feedbackRows as $f): ?>
                                <tr data-feedback-id="<?= (int)$f['feedback_id'] ?>"
                                    class="sent-detail-row"
                                    data-customer="<?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?>"
                                    data-comment="<?= htmlspecialchars($f['comment'] ?? '') ?>"
                                    data-sentiment="<?= htmlspecialchars($f['sentiment'] ?? '') ?>"
                                    data-sentiment-confidence="<?= $f['sentiment_confidence'] !== null ? htmlspecialchars(number_format((float)$f['sentiment_confidence'] * 100, 0)) : '' ?>"
                                    data-sentiment-summary="<?= htmlspecialchars($f['sentiment_summary'] ?? '') ?>"
                                    data-moderation-status="<?= htmlspecialchars($f['moderation_status']) ?>"
                                    data-moderation-reason="<?= htmlspecialchars($f['moderation_reason'] ?? '') ?>"
                                    data-moderation-confidence="<?= $f['moderation_confidence'] !== null ? htmlspecialchars(number_format((float)$f['moderation_confidence'] * 100, 0)) : '' ?>"
                                    data-created="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($f['created_at']))) ?>"
                                    style="cursor:pointer;"
                                >
                                    <td><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></td>
                                    <td style="max-width:260px;"><?= $f['comment'] !== null ? htmlspecialchars(mb_strimwidth($f['comment'], 0, 140, '…')) : '<span style="color:var(--op-ink-faint);">— no comment —</span>' ?></td>
                                    <td>
                                        <?php if ($f['sentiment'] !== null): ?>
                                            <span class="owner-status-pill <?= SENTIMENT_PILL_CLASS[$f['sentiment']] ?? 'is-neutral' ?>"><?= htmlspecialchars(ucfirst($f['sentiment'])) ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--op-ink-faint);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php $sm = moderationStatusMeta($f['moderation_status']); ?><span class="owner-status-pill <?= $sm['class'] ?>"><?= htmlspecialchars($sm['label']) ?></span></td>
                                    <td style="max-width:200px;"><?= $f['moderation_reason'] !== null ? htmlspecialchars(mb_strimwidth($f['moderation_reason'], 0, 80, '…')) : '<span style="color:var(--op-ink-faint);">—</span>' ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($f['created_at']))) ?></td>
                                    <td onclick="event.stopPropagation();">
                                        <div class="owner-table-actions">
                                            <?php if ($f['moderation_status'] !== 'approved'): ?>
                                            <form method="post" action="sentiment_analysis_action.php" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="feedback_id" value="<?= (int)$f['feedback_id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="owner-btn owner-btn-success owner-btn-sm" title="Approve">Approve</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($f['moderation_status'] !== 'flagged'): ?>
                                            <form method="post" action="sentiment_analysis_action.php" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="feedback_id" value="<?= (int)$f['feedback_id'] ?>">
                                                <input type="hidden" name="action" value="hide">
                                                <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm" title="Hide from public">Hide</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($f['comment'] !== null): ?>
                                            <form method="post" action="sentiment_analysis_action.php" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="feedback_id" value="<?= (int)$f['feedback_id'] ?>">
                                                <input type="hidden" name="action" value="rerun">
                                                <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Re-run AI analysis" title="Re-run AI analysis">
                                                    <i class="ph ph-arrows-clockwise" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="post" action="sentiment_analysis_action.php" style="display:inline;" data-confirm="Permanently delete this feedback? This can't be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="feedback_id" value="<?= (int)$f['feedback_id'] ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Delete" title="Delete">
                                                    <i class="ph ph-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php /* Same markup and classes as inventory.php's tables, driven by
                         the shared makePager() in assets/js/table-pager.js. Client-side
                         on purpose: Prev/Next only toggle row visibility, so there is
                         no page load and nothing to scroll back from. `hidden` starts
                         set and the pager clears it once it knows the page count. */ ?>
                <div class="owner-pagination" id="fbPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="fbPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="fbPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="fbPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- Feedback detail modal -->
<div class="owner-modal-backdrop" id="sentDetailBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="sentDetailTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="sentDetailTitle">Feedback detail</h2>
            <button type="button" class="owner-modal-close" id="btnCloseSentDetail" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body">
            <div class="owner-form-group"><label>Customer</label><div class="owner-input" id="sdCustomer" style="background:var(--op-canvas);"></div></div>
            <div class="owner-form-group"><label>Comment</label><div class="owner-input" id="sdComment" style="background:var(--op-canvas);min-height:60px;height:auto;white-space:pre-wrap;"></div></div>
            <div class="owner-form-group"><label>AI sentiment</label><div class="owner-input" id="sdSentiment" style="background:var(--op-canvas);"></div></div>
            <div class="owner-form-group"><label>AI summary</label><div class="owner-input" id="sdSentimentSummary" style="background:var(--op-canvas);"></div></div>
            <div class="owner-form-group"><label>Moderation status</label><div class="owner-input" id="sdModeration" style="background:var(--op-canvas);"></div></div>
            <div class="owner-form-group"><label>AI reason</label><div class="owner-input" id="sdReason" style="background:var(--op-canvas);"></div></div>
            <div class="owner-form-group"><label>Submitted</label><div class="owner-input" id="sdCreated" style="background:var(--op-canvas);"></div></div>
        </div>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script src="../owner/assets/js/table-pager.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/table-pager.js') ?>"></script>
<script>
(function () {
    // Deep link from a "feedback flagged" notification -- shows exactly that
    // one row, same "URL param wins" convention as every other
    // notification-linked list page in this app.
    const requestedFeedbackId = new URLSearchParams(window.location.search).get('feedback_id');

    // Client-side pager, same helper and markup as inventory.php's tables.
    // Prev/Next only toggle row visibility, so paging never reloads the page
    // and never moves you away from the table.
    //
    // The deep link goes through matchFn rather than setting row.style.display
    // itself: both would be writing the same property, and whichever ran last
    // would win. Expressing it as a filter means the pager stays the only
    // thing deciding what is visible, and the page count reflects the deep
    // link too instead of claiming pages that cannot be reached.
    const fbRows = Array.from(document.querySelectorAll('#feedbackTable tbody tr[data-feedback-id]'));
    const fbPager = window.makeTablePager({
        rows: fbRows,
        pageSize: 5,
        container: document.getElementById('fbPagination'),
        prevBtn: document.getElementById('fbPagePrev'),
        nextBtn: document.getElementById('fbPageNext'),
        info: document.getElementById('fbPageInfo'),
        matchFn: (row) => !requestedFeedbackId
            || row.getAttribute('data-feedback-id') === requestedFeedbackId,
        onUpdate: (visibleCount) => {
            const label = document.getElementById('fbCount');
            if (label) label.textContent = visibleCount + (visibleCount === 1 ? ' feedback' : ' feedbacks');
        },
    });
    fbPager.update(true);

    // Feedback detail modal -- populated entirely from the clicked row's
    // own data-* attributes, no extra fetch needed.
    const backdrop = document.getElementById('sentDetailBackdrop');
    const closeBtn = document.getElementById('btnCloseSentDetail');
    function closeModal() { backdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); }
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal(); });

    document.querySelectorAll('tr.sent-detail-row').forEach((row) => {
        row.addEventListener('click', () => {
            document.getElementById('sdCustomer').textContent = row.dataset.customer || '—';
            document.getElementById('sdComment').textContent = row.dataset.comment || '(no comment)';
            const sentiment = row.dataset.sentiment;
            document.getElementById('sdSentiment').textContent = sentiment ? sentiment.charAt(0).toUpperCase() + sentiment.slice(1) + (row.dataset.sentimentConfidence ? ' (' + row.dataset.sentimentConfidence + '% confidence)' : '') : 'Not analyzed';
            document.getElementById('sdSentimentSummary').textContent = row.dataset.sentimentSummary || '—';
            const modStatus = row.dataset.moderationStatus || '';
            document.getElementById('sdModeration').textContent = modStatus ? modStatus.charAt(0).toUpperCase() + modStatus.slice(1) + (row.dataset.moderationConfidence ? ' (' + row.dataset.moderationConfidence + '% confidence)' : '') : '—';
            document.getElementById('sdReason').textContent = row.dataset.moderationReason || '—';
            document.getElementById('sdCreated').textContent = row.dataset.created || '—';
            backdrop.classList.add('is-open');
            document.body.classList.add('owner-modal-open');
        });
    });

})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
<script src="../owner/assets/js/filter-autosubmit.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-autosubmit.js') ?>"></script>
</body>
</html>
