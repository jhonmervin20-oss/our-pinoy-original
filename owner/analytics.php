<?php
/**
 * owner/analytics.php
 *
 * Unified Restaurant Analytics -- Sales / Reservations / Inventory / Menu
 * Performance in one module, replacing the former owner/sales_analytics.php
 * and owner/menu_performance.php (retired -- see owner/includes/
 * analytics_functions.php's module docblock for the full consolidation
 * story). owner/report.php is left untouched; its Cashier/Payroll tabs are
 * outside this module's scope and its Sales/Inventory/Reservation tabs
 * still exist there as a separate, simpler view.
 *
 * Tabs are real ?tab= links and only the active one is computed per load,
 * so each tab pays for its own SQL and its own single AI Insight call --
 * nothing is built for a panel the viewer cannot see. That insight is
 * rendered server-side into the AI Insights box that sits directly under
 * the tab strip (below), summarising whatever that tab is showing; it is
 * cache-backed (ANALYTICS_AI_CACHE_TTL_HOURS) so repeat views and period
 * switches inside the window cost nothing.
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

$activePage = 'analytics';
$pageTitle  = 'Analytics';

$validPeriods = ['today', 'yesterday', 'last7', 'last30', 'this_month', 'last_month', 'custom'];
$validTabs    = ['sales', 'reservations', 'inventory'];

$period    = in_array($_GET['period'] ?? '', $validPeriods, true) ? $_GET['period'] : 'last30';
$dateFrom  = trim((string)($_GET['date_from'] ?? ''));
$dateTo    = trim((string)($_GET['date_to'] ?? ''));
$activeTab = in_array($_GET['tab'] ?? '', $validTabs, true) ? $_GET['tab'] : 'sales';

[$rangeStart, $rangeEnd] = resolvePeriodRange($period, $dateFrom ?: null, $dateTo ?: null);

$dbError        = null;
$restaurantName = 'OPO! Our Pinoy Original';
$tabData        = ['sales' => [], 'reservations' => [], 'inventory' => []];
$activeInsight  = null;

try {
    $pdo = Database::getInstance()->getConnection();

    // Only the tab actually being displayed is built. Building all four
    // eagerly cost ~2.5s per page load (Inventory alone 1.53s) for three
    // panels the viewer couldn't see; tabs are real ?tab= links now, so
    // each one pays only for itself.
    $tabData[$activeTab] = match ($activeTab) {
        'sales'            => buildSalesTabData($pdo, $rangeStart, $rangeEnd),
        'reservations'     => buildReservationsTabData($pdo, $rangeStart, $rangeEnd),
        'inventory'        => buildInventoryTabData($pdo, $rangeStart, $rangeEnd),
    };

    $restaurantName = $pdo->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
    )->fetchColumn() ?: $restaurantName;

    $openai = getOpenAiClient();
    $activeInsight = computeTabAiInsight($pdo, $openai, $activeTab, $tabData[$activeTab], $rangeStart, $rangeEnd, false, null);
} catch (PDOException $e) {
    $dbError = "Couldn't load analytics data. Please refresh this page.";
    error_log('analytics.php failed: ' . $e->getMessage());
}

$tabMeta = [
    'sales'            => ['label' => 'Sales'],
    'reservations'     => ['label' => 'Reservations'],
    'inventory'        => ['label' => 'Inventory'],
];

$periodOptions = [
    'today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 Days', 'last30' => 'Last 30 Days',
    'this_month' => 'This Month', 'last_month' => 'Last Month',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
    .df-kpi-meta{ font-size:0.72rem; color:var(--op-ink-faint); margin-top:4px; }
    .an-filter-bar{ display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .an-filter-bar .df-custom-dates{ display:flex; gap:8px; align-items:center; }
    .an-filter-bar .df-custom-dates[hidden]{ display:none; }
    /* The house summary card carries margin-bottom:20px so it can stand alone.
       Inside a grid the gap already does that job, so the margin is left over
       and only shows when the row wraps: the horizontal gutter stays 18px
       while the vertical one becomes 38px, and the block reads lopsided.
       demand_forecast.php clears it the same way for the same reason. */
    .owner-form-grid > .owner-summary-card{ margin-bottom:0; }
    /* ...but that margin was also the only thing separating the card block
       from the section beneath it, so the space moves to the grid, where it
       applies once instead of once per card. */
    .owner-form-grid:has(> .owner-summary-card){ margin-bottom:20px; }

    .an-chart-wrap{ position:relative; height:280px; margin-top:12px; }
    .an-two-col{ display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    .an-stack{ display:flex; flex-direction:column; gap:20px; }
    /* owner-panel.css stacks adjacent cards with margin-top:20px, which
       doubles up against these containers' own gap and knocked the right
       column 20px below the left one. */
    .an-two-col > .owner-card + .owner-card,
    .an-stack > .owner-card + .owner-card{ margin-top:0; }
    /* owner-panel.css's spacing rule is `.owner-card + .owner-card`, which
       only fires when two cards are DIRECT siblings. Every tab here mixes
       lone .owner-card blocks with .an-two-col/.an-stack wrapper rows at the
       top level (card, then a two-column row, then another row, ...), so
       that selector never matched between them -- cards sat flush against
       the row above with no gap at all, not merely a doubled one. This is
       the real fix: every direct child of the panel gets the same 20px
       rhythm regardless of whether it's a lone card or a two-column row. */
    .owner-analytics-panel > * + *{ margin-top:20px; }
    /* Grid and flex items default to min-width:auto, which refuses to shrink
       below their own content. The chart canvases therefore held the width they
       were first laid out at and pushed the whole page sideways on a phone --
       71px of horizontal scroll on a 393px screen. min-width:0 lets the columns
       narrow; Chart.js then resizes each canvas to match its container. */
    .an-two-col > *,
    .an-stack,
    .an-stack > *{ min-width:0; }
    .an-chart-wrap-tall{ height:320px; }
    .an-row-clickable{ cursor:pointer; }
    .an-row-clickable:hover{ background:var(--op-canvas); }
    .an-status-chips{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .an-status-chip{ padding:5px 12px; border-radius:999px; background:var(--op-canvas); color:inherit; text-decoration:none; font-size:0.78rem; display:inline-flex; gap:5px; align-items:center; }
    .an-status-chip:hover{ background:var(--op-border-soft); }
    .an-status-chip span{ font-weight:700; }
    /* AI Insights opens as a right-side drawer, not a centred modal --
       distinct chrome from .owner-modal (shared elsewhere in the app),
       deliberately: a persistent panel that slides alongside the data it's
       commenting on reads as "supporting the screen" rather than
       interrupting it the way a centred dialog does. Motion matches the
       existing mobile sidebar drawer's own convention
       (transform 0.24s var(--op-ease), translateX to 0) just mirrored to
       the right edge and slowed slightly (0.32s) since this panel is much
       wider than that one. */
    .an-ai-drawer-backdrop{
        position:fixed; inset:0; z-index:100;
        background:rgba(20,15,11,0.4);
        opacity:0; visibility:hidden;
        transition:opacity 0.2s var(--op-ease), visibility 0.2s var(--op-ease);
    }
    .an-ai-drawer-backdrop.is-open{ opacity:1; visibility:visible; }

    .an-ai-drawer{
        position:fixed; top:0; right:0; bottom:0;
        width:min(480px, 100vw);
        display:flex; flex-direction:column;
        background:var(--op-surface); box-shadow:var(--op-shadow);
        transform:translateX(100%);
        transition:transform 0.32s var(--op-ease);
    }
    .an-ai-drawer-backdrop.is-open .an-ai-drawer{ transform:translateX(0); }

    /* Two rows, not one cramped one: identity + close always stay top-most
       (the standard drawer/panel convention), which frees the second row --
       the meta facts + Regenerate -- to use the full drawer width instead of
       whatever was left over after the icon, title and two buttons all
       fought for space on a single line. That squeeze was exactly why the
       date range used to wrap mid-parenthesis. */
    .an-ai-drawer-header{
        display:flex; flex-direction:column; gap:12px;
        padding:20px 22px; border-bottom:1px solid var(--op-border); flex-shrink:0;
        background:var(--op-surface);
    }
    .an-ai-drawer-header-top{ display:flex; align-items:center; justify-content:space-between; gap:14px; }
    .an-ai-drawer-header-bottom{ display:flex; align-items:flex-end; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .an-ai-drawer-body{ flex:1; min-height:0; overflow-y:auto; padding:22px; }

    /* Entrance choreography: the summary card and each recommendation row
       fade + rise in, staggered slightly after the panel itself starts
       sliding, rather than all popping in at once the instant the drawer
       stops moving. Re-triggers every open because it's driven by the
       .is-open class itself, not a one-shot animation. */
    .an-ai-drawer-backdrop:not(.is-open) .an-ai-summary-card,
    .an-ai-drawer-backdrop:not(.is-open) .an-ai-recs li{
        opacity:0;
    }
    .an-ai-drawer-backdrop.is-open .an-ai-summary-card{
        animation:anAiRiseIn 0.38s var(--op-ease) both;
        animation-delay:0.08s;
    }
    .an-ai-drawer-backdrop.is-open .an-ai-recs li{
        animation:anAiRiseIn 0.38s var(--op-ease) both;
    }
    .an-ai-drawer-backdrop.is-open .an-ai-recs li:nth-child(1){ animation-delay:0.16s; }
    .an-ai-drawer-backdrop.is-open .an-ai-recs li:nth-child(2){ animation-delay:0.22s; }
    .an-ai-drawer-backdrop.is-open .an-ai-recs li:nth-child(3){ animation-delay:0.28s; }
    .an-ai-drawer-backdrop.is-open .an-ai-recs li:nth-child(4){ animation-delay:0.34s; }
    .an-ai-drawer-backdrop.is-open .an-ai-recs li:nth-child(n+5){ animation-delay:0.38s; }
    @keyframes anAiRiseIn{
        from{ opacity:0; transform:translateY(10px); }
        to{ opacity:1; transform:translateY(0); }
    }
    @media (max-width: 560px){
        .an-ai-drawer{ width:100vw; }
    }

    .an-ai-ident{ display:flex; align-items:center; gap:12px; min-width:0; }
    .an-ai-icon{
        flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
        width:38px; height:38px; border-radius:var(--op-radius-sm);
        background:var(--op-gold); color:#fff; font-size:1.1rem;
    }
    .an-ai-title{ margin:0; font-size:0.95rem; font-weight:700; line-height:1.3; color:var(--op-ink); }
    .an-ai-regenerate{ margin:0; flex:none; }

    /* Each fact its own block line rather than an inline-flex row -- with
       three facts and no more icon prefixes to anchor them, wrapping them
       into a shared row was what produced the mid-parenthesis line break on
       the date range. A column gives every fact its own full-width line to
       wrap ON ITS OWN if it ever needs to, instead of fighting its
       neighbours for space. */
    .an-ai-meta{ display:flex; flex-direction:column; gap:3px; min-width:0; }
    .an-ai-meta-item{ font-size:0.74rem; line-height:1.45; color:var(--op-ink-faint); }

    /* The summary now reads as a distinct callout rather than a bare
       paragraph sitting flush against the modal edges -- a gold accent
       border and a sparkle mark are the same visual language the "AI
       Insights" trigger button already uses, so the box reads as this
       panel's continuation rather than a new, unrelated element. */
    .an-ai-summary-card{
        display:flex; gap:12px; align-items:flex-start;
        background:var(--op-gold-soft); border:1px solid var(--op-border-soft);
        border-left:3px solid var(--op-gold); border-radius:var(--op-radius-sm);
        padding:14px 16px;
    }
    .an-ai-summary{ margin:0; font-size:0.92rem; line-height:1.65; color:var(--op-ink); }
    .an-ai-empty{ margin:0; font-size:0.86rem; color:var(--op-ink-faint); font-style:italic; }

    .an-ai-recs-label{
        margin:18px 0 9px; font-size:0.72rem; font-weight:700; letter-spacing:0.04em;
        text-transform:uppercase; color:var(--op-ink-faint);
    }
    /* Numbered via a counter rather than <ol> markers: the number sits in its
       own gold chip, which a list-style marker cannot be styled into. Each
       row is now its own bordered card (not just text beside a chip) so
       three separate actions read as three separate, weighable items rather
       than one flowing list -- and a hover state makes the row feel like the
       actionable, ownable item it is meant to be, not more prose. */
    .an-ai-recs{ counter-reset:an-rec; margin:0; padding:0; list-style:none; display:flex; flex-direction:column; gap:8px; }
    .an-ai-recs li{
        display:flex; gap:12px; align-items:flex-start; font-size:0.86rem; line-height:1.55; color:var(--op-ink-soft);
        padding:11px 14px; border:1px solid var(--op-border-soft); border-radius:var(--op-radius-sm);
        background:var(--op-surface);
        transition:border-color 0.15s var(--op-ease), background 0.15s var(--op-ease);
    }
    .an-ai-recs li:hover{ border-color:var(--op-gold); background:var(--op-gold-soft); }
    .an-ai-recs li::before{
        counter-increment:an-rec; content:counter(an-rec);
        flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
        width:22px; height:22px; margin-top:1px; border-radius:50%;
        background:var(--op-gold-soft); color:var(--op-gold);
        font-size:0.72rem; font-weight:700;
    }
    .an-ai-recs li:hover::before{ background:#fff; }

    /* A short, honest disclosure rather than presenting AI prose as if it
       carries the same certainty as the numbers on screen -- standard
       practice for a product that surfaces model output next to real data. */
    .an-ai-disclaimer{
        display:flex; align-items:center; gap:6px; margin-top:16px; padding-top:12px;
        border-top:1px solid var(--op-border-soft); font-size:0.72rem; color:var(--op-ink-faint);
    }
    .an-ai-disclaimer i{ font-size:0.85rem; flex-shrink:0; }
    @media (max-width: 900px){ .an-chart-wrap{ height:220px; } .an-chart-wrap-tall{ height:290px; } .an-two-col{ grid-template-columns:1fr; } }
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

            <div class="owner-card">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <form method="get" class="an-filter-bar" id="anFilterForm" style="margin:0;flex:1 1 auto;">
                    <input type="hidden" name="tab" id="anActiveTabField" value="<?= htmlspecialchars($activeTab) ?>">
                    <?php foreach ($periodOptions as $key => $label): ?>
                        <button type="submit" name="period" value="<?= $key ?>" class="owner-btn owner-btn-sm <?= $period === $key ? 'owner-btn-primary' : 'owner-btn-secondary' ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                    <button type="submit" name="period" value="custom" class="owner-btn owner-btn-sm <?= $period === 'custom' ? 'owner-btn-primary' : 'owner-btn-secondary' ?>">Custom</button>
                    <div class="df-custom-dates" id="anCustomDates" <?= $period === 'custom' ? '' : 'hidden' ?>>
                        <input type="date" name="date_from" class="owner-input" value="<?= htmlspecialchars($dateFrom ?: $rangeStart->format('Y-m-d')) ?>">
                        <span>&ndash;</span>
                        <input type="date" name="date_to" class="owner-input" value="<?= htmlspecialchars($dateTo ?: $rangeEnd->format('Y-m-d')) ?>">
                        <!-- name/value are required, not decoration: a form submits
                             only the CLICKED submit button's name, so without them
                             Apply posts date_from/date_to with no period at all and
                             $period falls back to 'last30', silently discarding the
                             dates the user just picked. -->
                        <button type="submit" name="period" value="custom" class="owner-btn owner-btn-sm owner-btn-primary">Apply</button>
                    </div>
                </form>
                <button type="button" class="owner-btn owner-btn-primary owner-btn-sm" id="anAiTrigger" aria-haspopup="dialog" aria-controls="anAiBackdrop">
                    <i class="ph ph-robot" aria-hidden="true"></i> AI Insights
                </button>
                </div>
                <div class="df-kpi-meta" style="margin-top:8px;"><?= htmlspecialchars(periodLabel($period, $rangeStart, $rangeEnd)) ?></div>
            </div>

            <?php
                // AI summary for the tab on screen. Only the active tab is
                // built server-side (see the $tabData match above), so this
                // is always $activeInsight -- rendered straight into the
                // markup rather than injected by JS, so it is present for
                // print/no-JS too. It now lives in an on-demand modal (see
                // #anAiBackdrop below) instead of an always-visible card, so
                // the trigger button needs to know up front whether there is
                // anything to show it -- hence $activeInsight !== null here.
                //
                // The sample label names what based_on_count actually counts
                // per tab, because each tab's AI payload passes a different
                // denominator to runAnalyticsAiInsight(); a bare "N records"
                // would be three different things wearing one label.
                $aiSampleLabel = match ($activeTab) {
                    'sales'        => 'completed orders',
                    'reservations' => 'reservations',
                    'inventory'    => 'active items',
                };
            ?>
            <?php /* Tabs sit on the canvas, not inside the card -- same as the
                     Reports and Inventory pages. */ ?>
            <div class="owner-tabs">
                <?php foreach ($tabMeta as $key => $meta): ?>
                    <?php
                        // Real links, not JS panel-toggling: each tab now
                        // builds only its own data, so switching is a
                        // normal (fast) page load. Carries the current
                        // period filters across.
                        $tabQs = http_build_query(array_filter([
                            'tab' => $key, 'period' => $period,
                            'date_from' => $dateFrom, 'date_to' => $dateTo,
                        ], fn($v) => $v !== ''));
                    ?>
                    <a href="analytics.php?<?= htmlspecialchars($tabQs) ?>" class="owner-tabs-link<?= $activeTab === $key ? ' is-active' : '' ?>"><?= htmlspecialchars($meta['label']) ?></a>
                <?php endforeach; ?>
            </div>

            <div class="an-ai-drawer-backdrop" id="anAiBackdrop">
                <div class="an-ai-drawer" role="dialog" aria-modal="true" aria-labelledby="anAiTitle">
                    <div class="an-ai-drawer-header">
                        <div class="an-ai-drawer-header-top">
                            <div class="an-ai-ident">
                                <span class="an-ai-icon"><i class="ph ph-robot" aria-hidden="true"></i></span>
                                <h2 class="an-ai-title" id="anAiTitle">AI Insights &mdash; <?= htmlspecialchars($tabMeta[$activeTab]['label']) ?></h2>
                            </div>
                            <button type="button" class="owner-modal-close" id="anAiClose" aria-label="Close">
                                <i class="ph ph-x" aria-hidden="true"></i>
                            </button>
                        </div>
                        <?php if ($activeInsight !== null): ?>
                            <div class="an-ai-drawer-header-bottom">
                                <div class="an-ai-meta">
                                    <span class="an-ai-meta-item"><?= htmlspecialchars(periodLabel($period, $rangeStart, $rangeEnd)) ?></span>
                                    <span class="an-ai-meta-item">based on <?= number_format((int)$activeInsight['based_on_count']) ?> <?= $aiSampleLabel ?></span>
                                    <span class="an-ai-meta-item">generated <?= htmlspecialchars(date('M j, Y g:i A', strtotime($activeInsight['generated_at']))) ?></span>
                                </div>
                                <?= analyticsRegenerateFormHtml($activeTab, $period, $rangeStart, $rangeEnd) ?>
                            </div>
                        <?php else: ?>
                            <?= analyticsRegenerateFormHtml($activeTab, $period, $rangeStart, $rangeEnd) ?>
                        <?php endif; ?>
                    </div>
                    <div class="an-ai-drawer-body">
                        <div class="an-ai-body"><?= renderAnalyticsAiInsightBodyHtml($activeInsight) ?></div>
                    </div>
                </div>
            </div>

            <div class="owner-analytics-panel" id="an-panel-<?= $activeTab ?>">
                <?php
                    echo match ($activeTab) {
                        'sales'            => renderAnalyticsSalesTabHtml($tabData[$activeTab], $period, $rangeStart, $rangeEnd),
                        'reservations'     => renderAnalyticsReservationsTabHtml($tabData[$activeTab], $period, $rangeStart, $rangeEnd),
                        'inventory'        => renderAnalyticsInventoryTabHtml($tabData[$activeTab], $period, $rangeStart, $rangeEnd),
                    };
                ?>
            </div>

        </main>
    </div>
</div>

<script src="assets/js/table-pager.js?v=<?= filemtime(__DIR__ . '/assets/js/table-pager.js') ?>"></script>
<script>
(function () {
    const customDates = document.getElementById('anCustomDates');
    document.querySelectorAll('button[name="period"]').forEach((btn) => {
        btn.addEventListener('click', () => { if (customDates) customDates.hidden = btn.value !== 'custom'; });
    });

    // -- AI Insights drawer ---------------------------------------------------
    (function () {
        const backdrop = document.getElementById('anAiBackdrop');
        const trigger = document.getElementById('anAiTrigger');
        const closeBtn = document.getElementById('anAiClose');
        if (!backdrop || !trigger) return;

        function openModal() {
            backdrop.classList.add('is-open');
            document.body.classList.add('owner-modal-open');
        }
        function closeModal() {
            backdrop.classList.remove('is-open');
            document.body.classList.remove('owner-modal-open');
        }

        trigger.addEventListener('click', openModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
        });
    })();

    // ONE generic chart loop. Each renderer emits its own Chart.js config in a
    // data-chart attribute, so charts are added or removed in the renderer
    // that owns them -- this block never needs editing again, and it cannot
    // drift out of step with a tab's data shape the way hand-written
    // per-chart JS did.
    const peso  = (n) => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const count = (n) => Number(n).toLocaleString('en-PH');

    // "2026-09-08" reads as a system value, not a date a reader recognises at
    // a glance. Every dense-daily chart's labels are raw Y-m-d keys -- this
    // turns those into "Sep 8" for the axis and tooltip title alike, and
    // leaves every other label (category names, weekday abbreviations, status
    // labels) untouched since they never match the pattern.
    const dateLabel = (s) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (!m) return s;
        const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };

    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        if (!window.Chart) return;
        try {
            const cfg = JSON.parse(canvas.getAttribute('data-chart'));
            if (cfg.data && Array.isArray(cfg.data.labels)) {
                cfg.data.labels = cfg.data.labels.map(dateLabel);
            }
            const fmt = canvas.getAttribute('data-chart-format') || '';
            let notes = [];
            try { notes = JSON.parse(canvas.getAttribute('data-chart-notes') || '[]'); } catch (err) { notes = []; }

            // Tooltip/tick formatters are FUNCTIONS and cannot survive the JSON
            // round-trip through data-chart, so the renderer names its format
            // (anChartCard()'s $format) and the matching callbacks get attached
            // here. Still one loop -- adding a chart never means editing this.
            if (fmt || notes.length) {
                const valueAxis = cfg.options && cfg.options.indexAxis === 'y' ? 'x' : 'y';
                const share = (ctx) => {
                    const total = ctx.dataset.data.reduce((a, b) => a + Number(b), 0);
                    return total > 0 ? ' (' + ((Number(ctx.parsed) / total) * 100).toFixed(1) + '%)' : '';
                };
                cfg.options = cfg.options || {};
                cfg.options.plugins = cfg.options.plugins || {};
                cfg.options.plugins.tooltip = Object.assign({}, cfg.options.plugins.tooltip, {
                    callbacks: {
                        label: (ctx) => {
                            const v = (ctx.parsed !== null && typeof ctx.parsed === 'object') ? ctx.parsed[valueAxis] : ctx.parsed;
                            if (fmt === 'peso')        return ' ' + peso(v);
                            if (fmt === 'peso_share')  return ' ' + peso(v) + share(ctx);
                            if (fmt === 'count_share') return ' ' + count(v) + share(ctx);
                            return ' ' + count(v);
                        },
                        afterLabel: (ctx) => notes[ctx.dataIndex] || ''
                    }
                });
                // Axis ticks only for plotted axes -- a doughnut has no
                // scales, and inventing one would be a Chart.js warning
                // at best.
                if ((fmt === 'peso' || fmt === 'peso_share') && cfg.type !== 'doughnut' && cfg.type !== 'pie') {
                    cfg.options.scales = cfg.options.scales || {};
                    const axis = Object.assign({}, cfg.options.scales[valueAxis]);
                    axis.ticks = Object.assign({}, axis.ticks, {
                        callback: (v) => '₱' + Number(v).toLocaleString('en-PH')
                    });
                    cfg.options.scales[valueAxis] = axis;
                }
            }
            new Chart(canvas.getContext('2d'), cfg);
        } catch (e) {
            // A malformed config must not take the whole page's scripts down
            // with it -- the tables and KPI cards are still perfectly readable.
            console.error('chart config failed', canvas.id, e);
        }
    });

    // ONE generic table-pager loop, same convention as the chart loop above:
    // any table a renderer wraps in .an-table-pager (data-paginate="N" on
    // the <table> itself) gets Prev/Next wired up here, so the next table
    // added to this module needs no new JS either.
    if (window.makeTablePager) {
        document.querySelectorAll('.an-table-pager').forEach((wrap) => {
            const table = wrap.querySelector('table[data-paginate]');
            const container = wrap.querySelector('.owner-pagination');
            if (!table || !container) return;
            const pageSize = parseInt(table.getAttribute('data-paginate'), 10) || 5;
            window.makeTablePager({
                rows: Array.from(table.querySelectorAll('tbody tr')),
                pageSize,
                container,
                prevBtn: container.querySelector('[data-page-prev]'),
                nextBtn: container.querySelector('[data-page-next]'),
                info: container.querySelector('[data-page-info]'),
            }).update(true);
        });
    }
})();
</script>

</body>
</html>
