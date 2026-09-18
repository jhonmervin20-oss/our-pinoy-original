<?php
/**
 * owner/demand_forecast.php
 *
 * Demand Forecasting -- READ ONLY.
 *
 * Nine blocks, one control. The horizon selector at the top applies to every
 * block below it, so nothing on this screen can be showing a different window
 * from anything else. The only button is "Re-run forecast", which is an action,
 * not a setting -- every knob that changes how the system behaves lives in
 * Settings > Purchasing & Forecasting, behind its own permission.
 *
 * Reads what forecasting/ wrote. Computes no forecast of its own.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/forecast_view_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

$defaultHorizon = (int)($pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'forecast_default_horizon'"
)->fetchColumn() ?: 7);

// Re-run forecast shells out to forecasting/run.py, a local Python pipeline
// -- present on a machine that's set it up (e.g. XAMPP dev), absent on a
// plain PHP host like Hostinger with no Python install. Same check
// forecast_rerun.php itself makes before running; done here too so the
// button isn't shown somewhere it can only ever fail.
$forecastPipelineAvailable = is_file(forecastPythonPath()) && is_file(dirname(__DIR__) . '/forecasting/run.py');

$horizon = (int)($_GET['h'] ?? $defaultHorizon);
if (!in_array($horizon, FORECAST_HORIZONS, true)) {
    $horizon = $defaultHorizon;
}

$search      = trim((string)($_GET['q'] ?? ''));
$supplierRaw = trim((string)($_GET['supplier_id'] ?? ''));
$supplierId  = $supplierRaw !== '' && ctype_digit($supplierRaw) ? (int)$supplierRaw : null;

$run = getLatestForecastRun($pdo);
$runId = $run ? (int)$run['run_id'] : null;

$orderForecast = $runId ? getOrderForecast($pdo, $runId, $horizon) : [];
$actuals       = getRecentActualOrders($pdo, 28);
$menuDemand    = $runId ? getMenuDemand($pdo, $runId, $horizon, $search) : [];
// How much of each ingredient the forecast expects to be consumed over the
// horizon selected above. Deliberately NOT the purchase plan: this answers
// "how much pork will we get through in the next 30 days?" -- a planning and
// budgeting figure -- while the plan answers "do I need to order pork today?"
// over the supplier's lead time. Keeping them apart is what lets the horizon
// buttons mean something here without dragging a 30-day window into the
// reorder rule, where it would buy a month of fresh fish at once.
$ingredientDemand = $runId ? getIngredientDemandOverHorizon($pdo, $runId, $horizon) : [];
$plan          = $runId ? getPurchasePlan($pdo, $runId, $horizon, $search, $supplierId) : [];
$accuracy      = $runId ? getForecastAccuracy($pdo, $runId) : [];
$health        = getDataHealth($pdo, $run);

$suppliers = $pdo->query(
    "SELECT supplier_id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name"
)->fetchAll(PDO::FETCH_ASSOC);

// ---- summary tiles ---------------------------------------------------------
$unitsPerOrder  = (float)($run['params']['s3_menu_split']['units_per_order'] ?? 1);
$totalServings  = array_sum(array_column($orderForecast, 'servings'));

// Prophet's own daily order forecast, keyed by date. This is the model's
// output, not a figure recovered from servings -- the pipeline stores per-dish
// servings, so without this the order count has to be recovered by dividing by
// units-per-order. That derivation is faithful, but it is a round trip, and it
// cannot carry a prediction interval. Falls back to the derivation per row, so
// a run whose params predate this key still renders.
$components  = getForecastComponents($run, $horizon);
$modelOrders = [];
foreach (($components['daily_orders'] ?? []) as $d) {
    $modelOrders[$d['date']] = $d;
}

$ordersForecast = 0.0;
foreach ($orderForecast as $f) {
    $ordersForecast += isset($modelOrders[$f['forecast_date']])
        ? (float)$modelOrders[$f['forecast_date']]['yhat']
        : ($unitsPerOrder > 0 ? (float)$f['servings'] / $unitsPerOrder : 0);
}

$busiest = null;
foreach ($orderForecast as $d) {
    if ($busiest === null || (float)$d['servings'] > (float)$busiest['servings']) {
        $busiest = $d;
    }
}

// Holidays inside the window this page is showing. Stage 1 removes holidays
// from TRAINING, which protects the weekday factors, but nothing after it knows
// they exist -- so a holiday in the horizon is forecast as an ordinary day of
// that weekday. Stating that is the honest output; silently plotting a normal
// Sunday for All Saints' Day and letting a purchase plan be built on it is not.
$windowHolidays = $orderForecast
    ? getHolidaysInForecastWindow(
        $pdo,
        (string)$orderForecast[0]['forecast_date'],
        (string)end($orderForecast)['forecast_date']
      )
    : [];
// end() moves the array pointer; the page loops this array again below.
reset($orderForecast);
$acc7        = $accuracy[7] ?? null;
$accHorizon  = $accuracy[$horizon] ?? null;
// Unscored is not the same as "lost to the baseline". A run that skipped the
// backtest still forecast with Prophet, so saying "Trailing average" there is
// simply untrue -- report what ran, and say separately whether it has been
// scored yet.
$modelInUse = ($acc7 !== null && (int)$acc7['beats_baseline'] === 0)
    ? 'Trailing average'
    : 'Prophet';
$toOrder     = array_values(array_filter($plan, fn($r) => !empty($r['live_triggered']) && (float)$r['live_suggested'] > 0));
$runsOut     = array_values(array_filter($plan, fn($r) => !empty($r['runs_out_on'])));

$activePage = 'demand_forecast';
$pageTitle  = 'Demand Forecasting';
$ownerBase  = '';

/** Keeps the horizon and filters on every link out of this page. */
function dfLink(array $over = []): string
{
    $base = array_filter([
        'h'           => $_GET['h'] ?? null,
        'q'           => $_GET['q'] ?? null,
        'supplier_id' => $_GET['supplier_id'] ?? null,
    ], fn($v) => $v !== null && $v !== '');
    return 'demand_forecast.php?' . http_build_query(array_merge($base, $over));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demand Forecasting | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
    /* A segmented control, not a pill -- 999px made it read as a tag rather than
       a set of buttons you switch between. The house 8px matches every other
       control on the panel. */
    .df-horizon{display:inline-flex;border:1px solid var(--op-border);border-radius:var(--op-radius-sm);overflow:hidden;background:var(--op-surface);}
    .df-horizon a{padding:7px 16px;font-size:0.85rem;color:var(--op-ink-soft);text-decoration:none;border-right:1px solid var(--op-border);}
    .df-horizon a:last-child{border-right:0;}
    .df-horizon a.is-on{background:var(--op-gold);color:#fff;font-weight:600;}
    /* House status cards carry their own 20px bottom margin for standalone
       use; inside this grid the gap already spaces them, so it is cleared to
       stop a doubled gutter between wrapped rows. */
    /* .owner-card carries no margin of its own -- every gap in this panel comes
       from `.owner-card + .owner-card{margin-top:20px}`. Sitting a non-card grid
       between two cards breaks that chain, so this restates the same 20px on
       both sides rather than inheriting a 0px gutter above and 20px below. */
    .df-kpis{margin:20px 0;}
    .df-kpis .owner-summary-card{margin-bottom:0;}
    .df-kpis .owner-summary-card .ph{font-size:1.6rem;color:var(--op-gold);flex-shrink:0;}
    .df-kpi-meta{font-size:0.72rem;color:var(--op-ink-faint);margin-top:4px;line-height:1.45;}
    /* Sits under .owner-card-head, whose own margin-bottom is only 4px -- built
       for a section title followed directly by content, not by a banner. */
    .df-caution-page{margin:16px 0 18px;padding:12px 16px;}
    .df-eyebrow{font-size:0.7rem;font-weight:600;text-transform:uppercase;letter-spacing:0.12em;
        color:var(--op-ink-faint);margin-bottom:14px;}
    /* Menu demand and Ingredient demand answer the same question at two levels,
       over the same horizon, so they sit side by side. The grid's own gap does
       the spacing -- `.owner-card + .owner-card{margin-top:20px}` would add a
       second gutter to the right-hand card, so it is cleared here. */
    /* margin on BOTH sides: the next card's previous sibling is this div, not an
       .owner-card, so owner-panel.css's `.owner-card + .owner-card{margin-top:20px}`
       never fires and the block below would sit flush against the pair. */
    .df-pair{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0;
        align-items:stretch;}
    /* Both cards stretch to the taller one, and each lays its own contents out
       as a column so the table takes the slack and the pager stays pinned to the
       bottom edge. Without this the shorter card floats and the two pagers sit
       at different heights, which is what made the pair look misaligned. */
    /* min-width:0 on both, or the tables inside win the argument about how wide
       this page is. A grid item (the card) and a flex item (the table wrap)
       both default to min-width:auto, which means "never shrink below your own
       min-content". The forecast tables are ~1200px of min-content, so on a
       390px phone the card refused its 358px track and laid out at 403px --
       30px of the whole PAGE scrolling sideways, while .owner-table-wrap's own
       overflow-x:auto sat there doing nothing, because an element that never
       shrinks never has anything to scroll. With these, the card fits its
       track and the table scrolls inside the card, which is what the wrapper
       was always for. */
    .df-pair > .owner-card{display:flex;flex-direction:column;min-width:0;}
    /* This page's <style> sits at the top of <head>; owner-panel.css is linked
       further down, so at EQUAL specificity the stylesheet wins on source order.
       `.owner-card + .owner-card{margin-top:20px}` is 0-2-0, and so was a plain
       `.df-pair > .owner-card` -- which is why the right-hand card sat 20px lower
       than the left one. Three classes (0-3-0) settles it without !important. */
    .df-pair > .owner-card,
    .df-pair > .owner-card + .owner-card{margin-top:0;}
    .df-pair .owner-table-wrap{flex:1 1 auto;min-width:0;}
    .df-pair .owner-pagination{margin-top:auto;padding-top:14px;}
    /* Card headers are one and two lines respectively, so the first table row
       would otherwise start at a different height in each card. */
    .df-pair .owner-card-head{min-height:52px;margin-bottom:10px;}
    /* Three numeric columns in half a screen is where these tables stop being
       readable, so they stack well before the panel's usual mobile breakpoint. */
    @media (max-width:1200px){
        .df-pair{grid-template-columns:1fr;}
        .df-pair .owner-card-head{min-height:0;}
    }
    /* The two numbers the rule actually compares, highlighted together on any
       row that tripped -- so "why did this one order?" is answerable at a
       glance instead of by reading across nine columns. */
    .df-hit{background:var(--op-gold-soft);}
    .df-late{color:var(--op-danger);}
    .df-caution{padding:10px 12px;margin-bottom:14px;border-radius:var(--op-radius-sm);
        background:var(--op-canvas);
        font-size:0.82rem;color:var(--op-ink-soft);line-height:1.6;}
    .df-sub{font-size:0.72rem;color:var(--op-ink-faint);line-height:1.4;}
    .df-num{text-align:right;white-space:nowrap;}
    /* owner-panel.css sets `.owner-table thead th{text-align:left}`, which is
       MORE specific than a bare .df-num -- so the headings sat left while their
       own numbers sat right. Matched specificity here rather than !important. */
    .owner-table thead th.df-num{text-align:right;}
    /* The breakdown under a figure is explanatory, not a column of its own; let
       it wrap instead of forcing the column as wide as the longest sentence. */
    .df-num .df-sub{white-space:normal;}
    .df-plain{font-size:0.84rem;color:var(--op-ink-soft);line-height:1.65;max-width:840px;margin:0 0 14px;}
    .df-chart-wrap{position:relative;height:260px;width:100%;}
    .df-chart{display:block;}
    .df-legend{display:flex;gap:16px;justify-content:center;font-size:0.75rem;color:var(--op-ink-soft);margin-top:8px;}
    .df-legend i{display:inline-block;width:14px;height:3px;vertical-align:middle;margin-right:5px;}
    .df-health{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;}
    .df-health div{font-size:0.82rem;color:var(--op-ink-soft);}
    .df-health strong{display:block;color:var(--op-ink);font-size:0.95rem;}
    /* A <tr>'s own display beats the native [hidden] attribute, so say it. */
    tr[hidden]{display:none !important;}
</style>
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="owner-main">
        <?php require_once __DIR__ . '/includes/header.php'; ?>

        <main class="owner-content">
            <?= flash_render() ?>

            <?php if ($run === null): ?>
                <div class="owner-card">
                    <h2 class="owner-card-title">No forecast yet</h2>
                    <p class="owner-card-subtitle">
                        <?php if ($forecastPipelineAvailable): ?>
                            Nothing has been forecast. Run <code>php forecasting/run.py</code>, or press
                            Re-run forecast once a run exists.
                        <?php else: ?>
                            Nothing has been forecast yet on this server.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>

            <!-- ============ 1. Horizon selector + the one action ============ -->
            <div class="owner-card">
                <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;justify-content:space-between;">
                    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                        <div class="df-horizon">
                            <?php foreach (FORECAST_HORIZONS as $h): ?>
                                <a href="<?= htmlspecialchars(dfLink(['h' => $h])) ?>" class="<?= $h === $horizon ? 'is-on' : '' ?>">Next <?= $h ?> days</a>
                            <?php endforeach; ?>
                        </div>
                        <form method="get" style="display:flex;gap:8px;align-items:center;">
                            <input type="hidden" name="h" value="<?= $horizon ?>">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="owner-input" placeholder="Search dish or ingredient&hellip;" style="min-width:220px;">
                            <select name="supplier_id" class="owner-select">
                                <option value="">All suppliers</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= (int)$s['supplier_id'] ?>" <?= $supplierId === (int)$s['supplier_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-funnel" aria-hidden="true"></i> Filter</button>
                        </form>
                    </div>
                    <?php if ($forecastPipelineAvailable): ?>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <form method="post" action="forecast_rerun.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="h" value="<?= $horizon ?>">
                            <button type="submit" class="owner-btn owner-btn-primary">
                                <i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Re-run forecast
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($caution = forecastHorizonCaution($horizon)): ?>
                    <div class="df-caution" style="margin-top:14px;margin-bottom:0;"><?= htmlspecialchars($caution) ?></div>
                <?php endif; ?>
            </div>

            <!-- ============ 2. Status cards ============ -->
            <?php
                // Standalone above the panels, using the house .owner-summary-card
                // that report.php and the settings pages already use, rather than
                // a bespoke tile boxed inside one card. These summarise the whole
                // page, so they should not look like they belong to one section.
            ?>
            <div class="owner-form-grid df-kpis">
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Orders forecast</div>
                        <div class="owner-summary-card-value"><?= number_format($ordersForecast) ?></div>
                        <?php // "whole" is load-bearing. Servings are ceilinged to whole
                              // portions per dish per day, so dividing servings by orders
                              // gives ~6.1 rather than the 5.02 units-per-order the split
                              // actually uses. Without the word, the two numbers on this
                              // card look like they contradict each other. ?>
                        <div class="df-kpi-meta">about <?= number_format($totalServings) ?> whole servings to prep</div>
                    </div>
                    <i class="ph ph-receipt" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Busiest day</div>
                        <div class="owner-summary-card-value"><?= $busiest ? htmlspecialchars(date('D', strtotime($busiest['forecast_date']))) : '&mdash;' ?></div>
                        <div class="df-kpi-meta"><?= $busiest ? htmlspecialchars(date('j M', strtotime($busiest['forecast_date']))) : 'No forecast yet' ?></div>
                    </div>
                    <i class="ph ph-calendar-check" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Model in use</div>
                        <div class="owner-summary-card-value"><?= htmlspecialchars($modelInUse) ?></div>
                       
                    </div>
                    <i class="ph ph-brain" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">To order today</div>
                        <div class="owner-summary-card-value"><?= count($toOrder) ?></div>
                        <?php // "if nothing is ordered" is the whole point. The balance
                              // projection adds only stock already on an open PO, so this
                              // count is a do-nothing scenario, not a backlog. Without it
                              // the card reads "5 to order, 39 running out" and looks like
                              // the system is missing 34 items -- when in fact each is
                              // ordered as its own supplier's lead time comes round. ?>
                        <div class="df-kpi-meta">
                            <?= count($runsOut) ?> would run out within <?= $horizon ?> days if nothing is ordered
                        </div>
                    </div>
                    <i class="ph <?= count($toOrder) > 0 ? 'ph-shopping-cart' : 'ph-check-circle' ?>" aria-hidden="true"></i>
                </div>
            </div>

            <div class="owner-card">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Next <?= $horizon ?> days</h2>
                        <span class="owner-card-subtitle">
                            <?php if ($orderForecast): ?>
                                <?= htmlspecialchars(date('D j M', strtotime($orderForecast[0]['forecast_date']))) ?>
                                &ndash;
                                <?= htmlspecialchars(date('D j M Y', strtotime(end($orderForecast)['forecast_date']))) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <?php if (($health['extrapolated_days'] ?? 0) > 2): ?>
                    <?php
                        // Inside the forecast card now: it qualifies the numbers
                        // this card itself plots, and the chart immediately below
                        // is what shows the extrapolated stretch.
                    ?>
                    <div class="df-caution df-caution-page">
                        <strong>Forecasting <?= (int)$health['extrapolated_days'] ?> days beyond the last recorded sales.</strong>
                        Trading was last recorded continuously to
                        <?= htmlspecialchars(date('j M Y', strtotime((string)$health['training_end']))) ?>,
                        so every figure on this page projects forward from a pattern that has not been checked since.
                        Record sales daily and the gap closes on its own.
                    </div>
                <?php endif; ?>

                <?php if ($windowHolidays): ?>
                    <?php
                        // Deliberately not an error state. The forecast is not
                        // wrong -- it is a baseline forecast, and this says so.
                    ?>
                    <div class="df-caution df-caution-page">
                        <strong>
                            <?= count($windowHolidays) === 1 ? 'A public holiday falls' : count($windowHolidays) . ' public holidays fall' ?>
                            inside this window.
                        </strong>
                        <?php foreach ($windowHolidays as $h): ?>
                            <div style="margin-top:6px;">
                                <?= htmlspecialchars(date('D j M', strtotime((string)$h['holiday_date']))) ?>
                                &mdash; <?= htmlspecialchars((string)$h['holiday_name']) ?>
                                <span style="color:var(--op-ink-faint);">(<?= htmlspecialchars((string)$h['holiday_type']) ?> holiday)</span>
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-top:8px;">
                            Shown as ordinary days &mdash; no holiday adjustment is applied. Holidays are excluded
                            from training so they cannot distort the weekday pattern, and there is not yet enough
                            history to measure what a holiday does to demand. Review these days before ordering
                            against them.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ============ 3. Sales forecast chart ============ -->
                <?php
                    $chartLabels = [];
                    $chartActual = [];
                    $chartFc     = [];
                    $chartLo     = [];
                    $chartHi     = [];
                    foreach ($actuals as $a) {
                        $chartLabels[] = date('j M', strtotime($a['d']));
                        $chartActual[] = (int)$a['orders'];
                        $chartFc[] = null; $chartLo[] = null; $chartHi[] = null;
                    }
                    foreach ($orderForecast as $f) {
                        $chartLabels[] = date('j M', strtotime($f['forecast_date']));
                        $chartActual[] = null;
                        // Prefer the model's own numbers; fall back to the
                        // servings derivation for a run stored before
                        // daily_orders was kept.
                        $m = $modelOrders[$f['forecast_date']] ?? null;
                        if ($m !== null) {
                            $chartFc[] = round((float)$m['yhat'], 1);
                            $chartLo[] = round((float)$m['lower'], 1);
                            $chartHi[] = round((float)$m['upper'], 1);
                        } else {
                            $chartFc[] = $unitsPerOrder > 0 ? round($f['servings'] / $unitsPerOrder, 1) : null;
                            $chartLo[] = $unitsPerOrder > 0 ? round($f['servings_lower'] / $unitsPerOrder, 1) : null;
                            $chartHi[] = $unitsPerOrder > 0 ? round($f['servings_upper'] / $unitsPerOrder, 1) : null;
                        }
                    }
                ?>
                <div class="df-eyebrow">Orders per day &mdash; last 28 days, then the next <?= $horizon ?></div>
                <div class="df-chart-wrap"><canvas id="dfChart" class="df-chart"></canvas></div>
                <div class="df-legend">
                    <span><i style="background:var(--op-gold);"></i> Actual</span>
                    <span><i style="background:#3f7ea6;"></i> Forecast</span>
                    <span><i style="background:#cfe0ea;height:10px;"></i> 80% range</span>
                </div>
            </div>

            <?php // Two answers to the same forecast, side by side: which DISHES it
                  // expects to sell, and which INGREDIENTS those dishes consume.
                  // Both follow the horizon buttons, so they always describe the
                  // same window -- which is exactly why they belong next to each
                  // other rather than a scroll apart. ?>
            <div class="df-pair">
            <!-- ============ 4. Menu demand ============ -->
            <div class="owner-card">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Menu demand</h2>
                        <span class="owner-card-subtitle">Servings forecast per dish over the next <?= $horizon ?> days</span>
                    </div>
                </div>
                
                <div class="owner-table-wrap">
                    <table class="owner-table" id="dfMenuTable">
                        <thead><tr><th>Dish</th><th>Category</th><th class="df-num">Servings forecast</th></tr></thead>
                        <tbody>
                        <?php foreach ($menuDemand as $m): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($m['item_name']) ?></strong></td>
                                <td><?= $m['category_name'] !== null ? htmlspecialchars($m['category_name']) : '&mdash;' ?></td>
                                <?php // Servings are ceilinged to whole portions upstream, so a
                                      // trailing ".0" is noise. Decided per value rather than
                                      // hardcoded to 0 decimals: turning
                                      // forecast_round_servings_up off makes these fractional
                                      // again, and this keeps the column honest either way. ?>
                                <?php $sv = (float)$m['servings']; ?>
                                <td class="df-num"><?= number_format($sv, fmod($sv, 1.0) == 0.0 ? 0 : 1) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                    <div class="owner-pagination" id="menuPagination" hidden>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="menuPrev">
                            <i class="ph ph-caret-left" aria-hidden="true"></i> Prev
                        </button>
                        <span class="owner-pagination-info" id="menuInfo">Page 1 of 1</span>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="menuNext">
                            Next <i class="ph ph-caret-right" aria-hidden="true"></i>
                        </button>
                    </div>
            </div>

            <!-- ============ 5. Ingredient demand over the horizon ============ -->
            <div class="owner-card">
                <div class="owner-card-head">
                    <div>
                        <h2 class="owner-card-title">Ingredient demand</h2>
                        <span class="owner-card-subtitle">
                            What the kitchen is expected to get through over the next <?= $horizon ?> days
                        </span>
                    </div>
                </div>
                <?php // A planning figure, not a buying instruction. It follows the
                      // horizon buttons; the purchase plan below does NOT, because
                      // purchasing keys off each supplier's lead time. Two different
                      // questions, two different windows -- and the estimated cost is
                      // here because "18 kg of pork" only becomes a budget when it
                      // carries a peso figure. ?>
                <?php if (!$ingredientDemand): ?>
                    <div class="owner-alert owner-alert-error" style="background:var(--op-gold-soft);color:var(--op-ink-soft);border-color:var(--op-border);">
                        <i class="ph ph-info" aria-hidden="true"></i>
                        <span>No ingredient demand recorded for this run.</span>
                    </div>
                <?php else: ?>
                    <?php $ingTotal = array_sum(array_column($ingredientDemand, 'est_cost')); ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table" id="dfIngTable">
                            <thead>
                                <tr>
                                    <th>Ingredient</th>
                                    <th class="df-num">Expected use</th>
                                    <th class="df-num">Estimated cost</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($ingredientDemand as $ing): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ing['item_name']) ?></strong></td>
                                    <td class="df-num">
                                        <?= number_format((float)$ing['qty'], 2) ?>
                                        <?= htmlspecialchars($ing['unit_code']) ?>
                                    </td>
                                    <td class="df-num">
                                        <?php // Priced at the last purchase cost, so a row with no
                                              // recorded purchase shows a dash rather than a
                                              // confident zero. ?>
                                        <?= (float)$ing['est_cost'] > 0
                                            ? '&#8369;' . number_format((float)$ing['est_cost'], 2)
                                            : '&mdash;' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <?php // Only the money. The row count and the window are already
                                          // stated by the pager and the card subtitle, so repeating them
                                          // here was three labels competing with the one figure that
                                          // matters -- what this horizon is expected to cost. ?>
                                    <th colspan="2"></th>
                                    <th class="df-num">&#8369;<?= number_format($ingTotal, 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="owner-pagination" id="ingPagination" hidden>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="ingPrev">
                            <i class="ph ph-caret-left" aria-hidden="true"></i> Prev
                        </button>
                        <span class="owner-pagination-info" id="ingInfo">Page 1 of 1</span>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="ingNext">
                            Next <i class="ph ph-caret-right" aria-hidden="true"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            </div>


            <!-- ============ 6. Purchase plan ============ -->
            <div class="owner-card">
                <div class="owner-card-head">
                    <div>
                        <?php // Named for the decision, not the data, now that
                              // Ingredient demand is its own card above -- two
                              // cards both titled "Ingredient demand" made the
                              // horizon-vs-lead-time distinction impossible to see. ?>
                        <h2 class="owner-card-title">Purchase plan</h2>
                        <?php if (!empty($plan)):
                            $planDecisionHorizon = (int)($plan[0]['decision_horizon'] ?? 7);
                        ?>
                        <span class="owner-card-subtitle">
                            <strong><?= $planDecisionHorizon ?>-day planning horizon
                            &middot; <?= htmlspecialchars(date('M j', strtotime($plan[0]['cover_from']))) ?>&ndash;<?= htmlspecialchars(date('j, Y', strtotime($plan[0]['cover_to']))) ?></strong>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$plan): ?>
                    <div class="owner-alert owner-alert-error" style="background:var(--op-gold-soft);color:var(--op-ink-soft);border-color:var(--op-border);">
                        <i class="ph ph-info" aria-hidden="true"></i>
                        <span>No ingredients matched this filter.</span>
                    </div>
                <?php else: ?>
                    <div class="owner-table-wrap">
                        <table class="owner-table" id="dfPlanTable">
                            <thead>
                                <tr>
                                    <th>Ingredient</th>
                                    <th class="df-num">Current Stock</th>
                                    <th class="df-num">Reorder Level</th>
                                    <th class="df-num"><?= $horizon ?>-Day Forecast</th>
                                    <th class="df-num">Safety Stock</th>
                                    <th class="df-num">Incoming</th>
                                    <th class="df-num">Projected Stock</th>
                                    <th class="df-num">Suggested Purchase</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($plan as $row):
                                [$label, $cls] = planStatus($row);
                                $u        = htmlspecialchars($row['unit_code']);
                                $short    = !empty($row['live_triggered']);
                                $onOrder  = (float)$row['live_on_order'];
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                                        <?php // Supplier and lead time read as one fact -- who brings this,
                                              // and how long they take. Lead time is reference information
                                              // now (replenishment timing), not what the reorder level is
                                              // built from. ?>
                                        <div class="df-sub">
                                            <?= $row['supplier_name'] !== null ? htmlspecialchars($row['supplier_name']) : 'no supplier' ?>
                                            &middot; <?= (int)$row['lead_time_days'] ?>d lead
                                        </div>
                                        <?php // The purchase order already covering this ingredient, named on
                                              // the row. Without it a row reading "Sufficient Stock" because a
                                              // delivery is inbound looks identical to one that is genuinely
                                              // well stocked -- and with no Action column there is nothing else
                                              // pointing at the PO. arrival_note carries the overdue wording
                                              // too, which is the case that matters most: an order weeks late
                                              // is not really incoming stock. ?>
                                        <?php if (!empty($row['arrival_note'])): ?>
                                            <div class="df-sub<?= !empty($row['overdue_po']) ? ' df-late' : '' ?>">
                                                <i class="ph ph-truck" aria-hidden="true"></i>
                                                <?= htmlspecialchars($row['arrival_note']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="df-num">
                                        <?= number_format((float)$row['on_hand_qty'], 2) ?> <?= $u ?>
                                    </td>

                                    <?php // The item's OWN configured value -- no formula shown underneath
                                          // it, because it isn't derived from one. What it means and where
                                          // it's edited is explained once, below the table. ?>
                                    <td class="df-num<?= $short ? ' df-hit' : '' ?>">
                                        <?= number_format((float)$row['live_reorder_level'], 2) ?> <?= $u ?>
                                    </td>

                                    <td class="df-num">
                                        <?= number_format((float)$row['live_horizon_demand'], 2) ?> <?= $u ?>
                                    </td>
                                    <td class="df-num"><?= number_format((float)$row['live_safety'], 2) ?> <?= $u ?></td>

                                    <td class="df-num">
                                        <?php if ($onOrder > 0): ?>
                                            <?= number_format($onOrder, 2) ?> <?= $u ?>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>

                                    <?php // What is left at the end of the selected view -- follows the
                                          // horizon toggle above, unlike Reorder Level/Suggested Purchase/
                                          // Status which never move. ?>
                                    <td class="df-num<?= $short ? ' df-hit' : '' ?>" style="<?= (float)$row['live_projected'] < 0 ? 'color:var(--op-danger);font-weight:600;' : '' ?>">
                                        <?= number_format((float)$row['live_projected'], 2) ?> <?= $u ?>
                                    </td>

                                    <td class="df-num">
                                        <?php if ((float)$row['live_suggested'] > 0): ?>
                                            <strong><?= number_format((float)$row['live_suggested'], 2) ?> <?= $u ?></strong>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>

                                    <td><span class="owner-status-pill <?= $cls ?>"><?= htmlspecialchars($label) ?></span></td>

                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="owner-pagination" id="planPagination" hidden>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="planPrev">
                            <i class="ph ph-caret-left" aria-hidden="true"></i> Prev
                        </button>
                        <span class="owner-pagination-info" id="planInfo">Page 1 of 1</span>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="planNext">
                            Next <i class="ph ph-caret-right" aria-hidden="true"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>


            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    // Client-side paging. Both tables are already filtered server-side by the
    // toolbar, so there is nothing to re-query -- rows are simply hidden.
    function paginate(tableId, prefix, pageSize) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        var pager = document.getElementById(prefix + 'Pagination');
        var prev  = document.getElementById(prefix + 'Prev');
        var next  = document.getElementById(prefix + 'Next');
        var info  = document.getElementById(prefix + 'Info');
        var page  = 1;

        function render() {
            var pages = Math.max(1, Math.ceil(rows.length / pageSize));
            if (page > pages) page = pages;
            var from = (page - 1) * pageSize;
            rows.forEach(function (row, i) {
                row.hidden = !(i >= from && i < from + pageSize);
            });
            if (pager) pager.hidden = pages <= 1;
            if (info) {
                info.textContent = 'Page ' + page + ' of ' + pages +
                    ' \u00b7 ' + rows.length + (rows.length === 1 ? ' row' : ' rows');
            }
            if (prev) prev.disabled = page <= 1;
            if (next) next.disabled = page >= pages;
        }

        if (prev) prev.addEventListener('click', function () { if (page > 1) { page--; render(); } });
        if (next) next.addEventListener('click', function () {
            if (page < Math.ceil(rows.length / pageSize)) { page++; render(); }
        });
        render();
    }

    paginate('dfPlanTable', 'plan', 5);
    paginate('dfMenuTable', 'menu', 5);
    paginate('dfIngTable', 'ing', 5);
})();

(function () {
    var el = document.getElementById('dfChart');
    if (!el || typeof Chart === 'undefined') return;

    var labels = <?= json_encode($chartLabels ?? []) ?>;
    var actual = <?= json_encode($chartActual ?? []) ?>;
    var fc     = <?= json_encode($chartFc ?? []) ?>;
    var lo     = <?= json_encode($chartLo ?? []) ?>;
    var hi     = <?= json_encode($chartHi ?? []) ?>;

    new Chart(el.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                // The band is drawn as two stacked-looking areas: the upper bound
                // filled down to the lower bound.
                { label: 'high', data: hi, borderWidth: 0, pointRadius: 0,
                  backgroundColor: 'rgba(63,126,166,0.14)', fill: '+1' },
                { label: 'low',  data: lo, borderWidth: 0, pointRadius: 0, fill: false },
                { label: 'Actual', data: actual, borderColor: '#9a7b3f', borderWidth: 2,
                  pointRadius: 0, tension: 0.3, spanGaps: false },
                { label: 'Forecast', data: fc, borderColor: '#3f7ea6', borderWidth: 2,
                  borderDash: [5, 4], pointRadius: 0, tension: 0.3, spanGaps: false }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: { filter: function (i) { return i.dataset.label !== 'high' && i.dataset.label !== 'low'; } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } },
                x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } }
            }
        }
    });
})();
</script>

</body>
</html>