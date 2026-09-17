<?php
/**
 * owner/includes/feedback_page_functions.php
 *
 * Shared by the two pages customer feedback is now split across:
 *
 *   sentiment_analysis.php   how customers FEEL      positive / neutral / negative
 *   feedback_moderation.php  what may be PUBLISHED   published / masked / withheld / blocked
 *
 * The split follows the data model rather than being a layout preference. The
 * moderation engine scores toxicity and sentiment as independent axes (see
 * config/feedback_moderation.php), and a single page reporting both invited
 * exactly the confusion the engine was rewritten to remove -- that a rude
 * review must be a bad review. They answer different questions, for different
 * decisions, and are read at different times: sentiment is a weekly "how are we
 * doing", moderation is a daily queue.
 *
 * What lives here is only the machinery both genuinely share -- the period
 * filter and the query-string handling. Two pages that each parse ?month
 * their own way drift within a release, and then the same filter silently
 * means different things depending on which tab you are on.
 */

require_once __DIR__ . '/../../config/feedback_moderation.php';

/** Sentiment pill classes, shared so the same word is never two colours. */
const FEEDBACK_SENTIMENT_PILL = [
    'positive' => 'is-success',
    'neutral'  => 'is-neutral',
    'negative' => 'is-danger',
];

/** Moderation status pill classes. Five states -- see resolveModerationOutcome(). */
const FEEDBACK_STATUS_PILL = [
    'pending'  => 'is-warning',
    'approved' => 'is-success',
    'masked'   => 'is-warning',
    'flagged'  => 'is-danger',
    'blocked'  => 'is-danger',
];

/**
 * Resolves ?month into the internal filter value.
 *
 * Defaults to the CURRENT month when the parameter is absent or malformed;
 * only an explicit ?month=all means all time. Returns '' for all-time, which
 * is what feedbackPeriodSql() treats as "no period condition".
 */
function feedbackMonthFilter(?string $monthParam): string
{
    if ($monthParam === 'all') {
        return '';
    }
    if ($monthParam !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
        return $monthParam;
    }
    return date('Y-m');
}

/**
 * The period WHERE fragment and its bound parameters.
 *
 * Returns a fragment that is safe to concatenate after an existing condition
 * (it always begins with " AND ..." or is empty), so callers can write
 * "WHERE 1=1{$periodSql}" without caring whether a filter is active.
 *
 * @return array{0:string, 1:array<int,string>}
 */
function feedbackPeriodSql(string $monthFilter, string $alias = 'f'): array
{
    if ($monthFilter === '') {
        return ['', []];
    }
    return [" AND DATE_FORMAT({$alias}.created_at, '%Y-%m') = ?", [$monthFilter]];
}

/**
 * Builds a query string for one of the feedback pages, preserving the filters
 * that page actually uses and dropping empties so URLs stay readable.
 *
 * $base is the current filter state; $overrides wins. Page 1 is dropped
 * because "?page=1" and no page parameter are the same view, and having two
 * URLs for one view breaks the browser's own back/forward sense of place.
 */
function feedbackQs(array $base, array $overrides = []): string
{
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

/**
 * The month filter card, identical on both pages.
 *
 * $hiddenFilters are the page's own filters, carried through as hidden inputs
 * so changing the month doesn't silently clear the status or search a
 * moderator had already applied.
 */
function renderFeedbackMonthFilter(string $page, string $monthFilter, array $hiddenFilters = [], string $trailingNote = ''): void
{
    ?>
    <div class="owner-card" style="margin-bottom:20px;">
        <form method="get" action="<?= htmlspecialchars($page) ?>" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:14px 20px;">
            <?php foreach ($hiddenFilters as $name => $value): ?>
                <input type="hidden" name="<?= htmlspecialchars((string)$name) ?>" value="<?= htmlspecialchars((string)$value) ?>">
            <?php endforeach; ?>
            <label for="monthFilterInput" style="font-weight:600;font-size:0.85rem;">Month</label>
            <input type="month" id="monthFilterInput" name="month" class="owner-input" style="max-width:170px;" value="<?= htmlspecialchars($monthFilter) ?>">
            <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">Apply</button>
            <?php if ($monthFilter !== ''): ?>
                <a href="<?= htmlspecialchars($page) ?>?<?= htmlspecialchars(feedbackQs($hiddenFilters, ['month' => 'all', 'page' => ''])) ?>" class="owner-btn owner-btn-secondary owner-btn-sm">All time</a>
            <?php endif; ?>
            <span style="margin-left:auto;color:var(--op-ink-faint);font-size:0.82rem;">
                Showing: <strong><?= $monthFilter !== '' ? htmlspecialchars(date('F Y', strtotime($monthFilter . '-01'))) : 'All time' ?></strong>
                <?= $trailingNote !== '' ? ' &middot; ' . htmlspecialchars($trailingNote) : '' ?>
            </span>
        </form>
    </div>
    <?php
}

/**
 * The cross-link between the two pages.
 *
 * Deliberately explicit about what the OTHER page answers, not just its name.
 * Splitting one page into two is only an improvement if each one tells you
 * where the question it doesn't answer went; otherwise an owner looking for
 * the flagged queue simply concludes it was deleted.
 */
function renderFeedbackPageSwitch(string $currentPage): void
{
    $isSentiment = $currentPage === 'sentiment_analysis';
    $href  = $isSentiment ? 'feedback_moderation.php' : 'sentiment_analysis.php';
    $icon  = $isSentiment ? 'ph-shield-check' : 'ph-chart-line-up';
    $label = $isSentiment ? 'Feedback moderation' : 'Sentiment analysis';
    $blurb = $isSentiment
        ? 'Review what customers wrote and control what gets published.'
        : 'See how customers feel and what they are talking about.';
    ?>
    <a class="fb-page-switch" href="<?= htmlspecialchars($href) ?>">
        <i class="ph <?= $icon ?>" aria-hidden="true"></i>
        <span class="fb-page-switch-text">
            <strong><?= htmlspecialchars($label) ?></strong>
            <span><?= htmlspecialchars($blurb) ?></span>
        </span>
        <i class="ph ph-arrow-right fb-page-switch-go" aria-hidden="true"></i>
    </a>
    <?php
}
