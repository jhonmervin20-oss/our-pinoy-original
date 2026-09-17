<?php
/**
 * owner/report.php
 *
 * Reports hub -- five tabs (Cashier Remittance, Sales, Inventory,
 * Reservation, Payroll), one shared period filter (Today/This Week/
 * This Month/This Year/Custom), each tab printable via ?print=1&tab=.
 *
 * All five tabs are computed on every load (not just the active one) so
 * tab-switching is instant client-side JS, matching the pattern already
 * established in admin/users.php and reservation/reservations.php --
 * only the period filter causes a real page reload, since that's what
 * actually changes the underlying SQL.
 *
 * Every number is read from -- or built directly on top of -- the same
 * tables/functions each source module already treats as its own source
 * of truth (cash_balances' own snapshot columns, payroll_runs' own
 * precomputed totals, etc.) -- see owner/includes/report_functions.php's
 * module docblock.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/report_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'report';
$pageTitle  = 'Reports';

$validPeriods = ['today', 'this_week', 'this_month', 'this_year', 'custom'];
$validTabs    = ['sales', 'cashier', 'inventory', 'reservation', 'payroll'];

// Opens on Today. An unrecognised ?period= falls back here too, so a stale
// link lands on a defined period rather than an empty range.
$period   = in_array($_GET['period'] ?? '', $validPeriods, true) ? $_GET['period'] : 'today';
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));
$activeTab = in_array($_GET['tab'] ?? '', $validTabs, true) ? $_GET['tab'] : 'sales';
$isPrint   = ($_GET['print'] ?? '') === '1';

$shiftIdRaw = trim((string)($_GET['shift_id'] ?? ''));
$shiftId    = ($shiftIdRaw !== '' && ctype_digit($shiftIdRaw)) ? (int)$shiftIdRaw : null;
$isShiftFragment = ($_GET['shift_fragment'] ?? '') === '1';

// The Cashier Remittance tab's "View" action -- fetched into a modal via
// this same page's own ?shift_fragment=1 mode (mirrors cashier/
// remittance_report.php's own ?fragment=1 convention). A completely
// separate concern from $shiftId's OTHER job below (pinning the report
// period to a notification's shift date), so it's handled and exited
// here, before any of that period/tab-data-building logic runs.
if ($isShiftFragment) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $shiftRow = null;
        if ($shiftId !== null) {
            $shiftStmt = $pdo->prepare(
                "SELECT cs.*, u.first_name, u.last_name
                 FROM cash_balances cs JOIN users u ON u.user_id = cs.cashier_id
                 WHERE cs.shift_id = ?"
            );
            $shiftStmt->execute([$shiftId]);
            $shiftRow = $shiftStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$shiftRow) {
            http_response_code(404);
            echo 'Shift not found.';
            exit;
        }

        $liveTotals = computeShiftTotals($pdo, $shiftId, (float)$shiftRow['opening_cash']);
        header('Content-Type: text/html; charset=UTF-8');
        echo renderCashierShiftDetailFragmentHtml($shiftRow, $liveTotals);
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Could not load this shift.';
    }
    exit;
}

/* The Payroll tab's "Summary report" action, fetched into a modal -- the same
   ?fragment=1 convention the Cashier tab above uses.

   Every payable run used to render its full statement inline, which was fine
   for one run and unreadable for a month of them. The table now carries the
   summary figures and this serves the detail for one run on demand.
   buildPayrollSummary() is reused rather than re-querying, so the modal can
   never disagree with the row that opened it. */
$payrollRunIdRaw   = trim((string)($_GET['run_id'] ?? ''));
$payrollRunId      = ($payrollRunIdRaw !== '' && ctype_digit($payrollRunIdRaw)) ? (int)$payrollRunIdRaw : null;
$isPayrollFragment = ($_GET['payroll_fragment'] ?? '') === '1';

if ($isPayrollFragment) {
    if ($payrollRunId === null) {
        http_response_code(400);
        echo 'Missing run id.';
        exit;
    }
    try {
        $pdo = Database::getInstance()->getConnection();

        // Widened to the run's own payout date so the lookup does not depend on
        // whatever period the page happened to be showing.
        $dateStmt = $pdo->prepare("SELECT payout_date FROM payroll_runs WHERE payroll_run_id = ?");
        $dateStmt->execute([$payrollRunId]);
        $payoutDate = $dateStmt->fetchColumn();

        if (!$payoutDate) {
            http_response_code(404);
            echo 'Payroll run not found.';
            exit;
        }

        $day     = new DateTime($payoutDate);
        $summary = buildPayrollSummary($pdo, $day, $day);

        $run = null;
        foreach ($summary['payable_runs'] as $candidate) {
            if ((int)$candidate['payroll_run_id'] === $payrollRunId) { $run = $candidate; break; }
        }

        if ($run === null) {
            http_response_code(404);
            echo 'That payroll run has no payout to summarise.';
            exit;
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo renderPayrollRunSummaryHtml(
            $run,
            $summary['composition'][$payrollRunId] ?? null,
            $summary['statutory'][$payrollRunId] ?? [],
            false
        );
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Could not load this payroll run.';
    }
    exit;
}

[$rangeStart, $rangeEnd] = resolveReportPeriodRange($period, $dateFrom ?: null, $dateTo ?: null);

$dbError = null;
$cashierData     = ['shifts' => [], 'total_sales' => 0, 'total_variance' => 0, 'closed_count' => 0,
                    'open_count' => 0, 'shift_count' => 0, 'cash_counted' => 0, 'cash_sales' => 0,
                    'total_float' => 0, 'unbalanced_count' => 0, 'gcash_sales' => 0, 'gcash_txn_count' => 0];
// Shaped exactly like buildSalesSummary()'s return so the renderer can never
// hit a missing key when the DB read below fails -- an empty report is fine, a
// fatal on the error path is not.
$salesData       = [
    'statement' => [
        'order_count' => 0, 'gross_sales' => 0.0, 'discounts' => 0.0, 'vat' => 0.0,
        'packaging_fees' => 0.0, 'total_collected' => 0.0,
        'vatable_sales' => 0.0, 'vat_exempt_sales' => 0.0, 'discounted_order_count' => 0,
        'net_sales_vat_inc' => 0.0, 'net_sales_vat_exc' => 0.0, 'avg_order_value' => 0.0,
        'discount_rate_pct' => 0.0, 'ties_out' => true, 'deposit_credited_at_pos' => 0.0,
        'tender' => ['rows' => [], 'total' => 0.0], 'discounts_breakdown' => [],
    ],
    'deposits' => [
        'redeemed' => 0.0, 'forfeited' => 0.0, 'held' => 0.0, 'collected' => 0.0,
        'redeemed_count' => 0, 'forfeited_count' => 0, 'held_count' => 0, 'other_income' => 0.0,
    ],
    'total_revenue' => 0, 'total_orders' => 0, 'avg_order_value' => 0, 'total_income' => 0,
];
$inventoryData   = ['qty_in' => 0, 'qty_out' => 0, 'qty_waste' => 0, 'qty_adjusted' => 0, 'txn_count' => 0, 'stock_status_counts' => [], 'needs_reorder_count' => 0, 'reconciliation' => [], 'balance_known_from' => null];
$reservationData = ['by_status' => [], 'total_bookings' => 0, 'completed' => 0, 'no_show' => 0, 'no_show_rate' => null, 'advance_order_revenue' => 0, 'reservations' => []];
$payrollData     = ['runs' => [], 'run_count' => 0, 'total_gross_pay' => 0, 'total_deductions' => 0, 'total_net_pay' => 0, 'total_employees_paid' => 0];
$restaurantName  = 'OPO! Our Pinoy Original';

try {
    $pdo = Database::getInstance()->getConnection();

    // A notification's shift_id always wins over the period filter -- the
    // shift being reported might not fall inside "This Month" (or whatever
    // period happens to be selected), so pin the range to that shift's own
    // date and jump straight to the Cashier tab, same "URL param wins"
    // convention as admin/activity_logs.php's ?log_id= deep link.
    if ($shiftId !== null) {
        $shiftDateStmt = $pdo->prepare('SELECT DATE(COALESCE(closed_at, opened_at)) FROM cash_balances WHERE shift_id = ?');
        $shiftDateStmt->execute([$shiftId]);
        $shiftDate = $shiftDateStmt->fetchColumn();
        if ($shiftDate !== false) {
            $period    = 'custom';
            $dateFrom  = $shiftDate;
            $dateTo    = $shiftDate;
            $activeTab = 'cashier';
            [$rangeStart, $rangeEnd] = resolveReportPeriodRange('custom', $dateFrom, $dateTo);
        } else {
            $shiftId = null; // Stale/deleted shift_id -- fall back to a normal, unfiltered report view.
        }
    }

    $cashierData     = buildCashierRemittanceSummary($pdo, $rangeStart, $rangeEnd);
    $salesData       = buildSalesSummary($pdo, $rangeStart, $rangeEnd);
    $inventoryData   = buildInventoryMovementSummary($pdo, $rangeStart, $rangeEnd);
    $reservationData = buildReservationSummary($pdo, $rangeStart, $rangeEnd);
    $payrollData     = buildPayrollSummary($pdo, $rangeStart, $rangeEnd);

    $restaurantName = $pdo->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
    )->fetchColumn() ?: $restaurantName;
} catch (PDOException $e) {
    $dbError = "Couldn't load report data. Please refresh this page.";
    error_log('report.php failed: ' . $e->getMessage());
}

// Deliberately excludes 'tab' -- each panel's own Print link appends its
// own &tab=<panel key> after this, since all 5 panels render on every
// load (see the module docblock) and a panel's key can differ from
// $activeTab (only the initially-requested tab, not whichever one JS has
// switched to client-side).
$currentQueryString = http_build_query(array_filter([
    'period' => $period, 'date_from' => $dateFrom, 'date_to' => $dateTo,
], fn($v) => $v !== ''));

$tabMeta = [
    'sales'       => ['label' => 'Sales'],
    'cashier'     => ['label' => 'Cashier Remittance'],
    'inventory'   => ['label' => 'Inventory'],
    'reservation' => ['label' => 'Reservation'],
    'payroll'     => ['label' => 'Payroll'],
];

$tabData = [
    'sales'       => $salesData,
    'cashier'     => $cashierData,
    'inventory'   => $inventoryData,
    'reservation' => $reservationData,
    'payroll'     => $payrollData,
];

// ---------------------------------------------------------------------
// Printable view -- a self-contained professional document (own
// letterhead, bordered tables, no dashboard chrome), NOT the on-screen
// dashboard styling. Deliberately does not load owner-panel.css -- see
// renderReportPrintDocumentHtml()'s docblock for why (its @media print
// rule, scoped to printing an open modal, otherwise blanks this page
// out entirely). ?download=1 streams the same document as a real PDF
// via Dompdf, matching purchase_order_pdf.php's convention.
// ---------------------------------------------------------------------
if ($isPrint) {
    $isDownload = ($_GET['download'] ?? '') === '1';
    $periodLbl  = reportPeriodLabel($period, $rangeStart, $rangeEnd);
    $printUrl   = 'report.php?print=1&tab=' . $activeTab . '&' . $currentQueryString;

    if ($isDownload) {
        require_once __DIR__ . '/../vendor/autoload.php';

        $html = renderReportPrintDocumentHtml($activeTab, $tabMeta[$activeTab]['label'], $tabData[$activeTab], $restaurantName, $periodLbl, false);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $activeTab . '_report_' . date('Y-m-d') . '.pdf"');
        echo $dompdf->output();
        exit;
    }

    echo renderReportPrintDocumentHtml(
        $activeTab,
        $tabMeta[$activeTab]['label'],
        $tabData[$activeTab],
        $restaurantName,
        $periodLbl,
        true,
        $printUrl,
        $printUrl . '&download=1',
        'report.php?' . http_build_query(array_filter(['period' => $period, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'tab' => $activeTab], fn($v) => $v !== ''))
    );
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
<?php // Chart.js was dropped along with the Sales trend chart -- no tab on this
      // page renders a <canvas> any more, so loading it was a third-party script
      // fetched on every report view for nothing. ?>
<style>
    .df-kpi-meta{ font-size:0.72rem; color:var(--op-ink-faint); margin-top:4px; }
    .report-period-pills{ display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .report-period-pills .df-custom-dates{ display:flex; gap:8px; align-items:center; min-width:0; }
    .report-period-pills .df-custom-dates[hidden]{ display:none; }
    /* Selecting "Custom" on a phone pushed the page sideways: the two date
       inputs plus their separator and Apply button need ~418px, and a flex row
       does not shrink its items below their intrinsic width. The tables were
       never the problem -- .owner-table-wrap already scrolls them internally --
       this row was. Only bites below the breakpoint, so it is scoped there. */
    @media (max-width: 560px){
        .report-period-pills .df-custom-dates{ flex-wrap:wrap; width:100%; }
        .report-period-pills .df-custom-dates input[type="date"]{ flex:1 1 130px; min-width:0; }
    }
    .report-tab-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    /* Every chart and table is its own titled card now, matching analytics.php.
       The KPI grid above them keeps its own bottom margin, so the first section
       card doesn't need owner-panel.css's card-to-card spacing on top of it. */
    .owner-form-grid + .rp-section{ margin-top: 0; }
    /* Section cards laid out side by side in a grid were coming out ragged:
       owner-panel.css's `.owner-card + .owner-card{ margin-top:20px }` still
       matches every card after the first, and inside a grid that margin applies
       within each card's own cell -- so the top-right card sat 20px below the
       top-left one, and the second row inherited the same stagger. The grid's
       own `gap` is what should be spacing these. Same fix analytics.php already
       applies to .an-two-col / .an-stack. */
    .owner-form-grid > .owner-card + .owner-card{ margin-top: 0; }
    /* .owner-summary-card is flex-wrap:wrap, so a long meta line ("low /
       critical / out of stock, right now") pushed the gold icon onto its own
       row and left that card taller than the three beside it. Scoped to this
       page rather than patched in the shared stylesheet. */
    /* The panels are NOT cards. Each tab lays its KPI grid and section cards
       straight on the canvas, the same way notifications.php and the
       reservation grid do -- a card wrapping cards only boxed the page in.
       This stays a plain container so the tab JS can still show/hide one at
       a time and the .owner-summary-card fixes below stay scoped to it.

       No card-to-card margin reset is needed any more: cards in different
       panels are not siblings, so `.owner-card + .owner-card` never fires
       across them, and within a panel it fires between section cards, which
       is exactly the spacing we want. */
    .owner-report-panel .owner-summary-card{ flex-wrap: nowrap; }
    .owner-report-panel .owner-summary-card > div{ min-width: 0; }
    .owner-report-panel .owner-summary-card > i{ flex-shrink: 0; }
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/includes/header.php'; ?>

        <main class="owner-content">

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <div class="owner-card">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <form method="get" class="report-period-pills" id="reportPeriodForm" style="margin:0;flex:1 1 auto;">
                    <input type="hidden" name="tab" id="reportActiveTabField" value="<?= htmlspecialchars($activeTab) ?>">
                    <?php foreach (['today' => 'Today', 'this_week' => 'This Week', 'this_month' => 'This Month', 'this_year' => 'This Year'] as $key => $label): ?>
                        <button type="submit" name="period" value="<?= $key ?>" class="owner-btn owner-btn-sm <?= $period === $key ? 'owner-btn-primary' : 'owner-btn-secondary' ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                    <button type="submit" name="period" value="custom" class="owner-btn owner-btn-sm <?= $period === 'custom' ? 'owner-btn-primary' : 'owner-btn-secondary' ?>">Custom</button>
                    <div class="df-custom-dates" <?= $period === 'custom' ? '' : 'hidden' ?> id="reportCustomDates">
                        <input type="date" name="date_from" class="owner-input" value="<?= htmlspecialchars($dateFrom ?: $rangeStart->format('Y-m-d')) ?>">
                        <span>&ndash;</span>
                        <input type="date" name="date_to" class="owner-input" value="<?= htmlspecialchars($dateTo ?: $rangeEnd->format('Y-m-d')) ?>">
                        <!-- name/value are required, not decoration: a form submits
                             only the CLICKED submit button's name, so without them
                             Apply posts date_from/date_to with no period at all and
                             $period falls back to the default ('today'), silently
                             discarding the dates the user just picked. -->
                        <button type="submit" name="period" value="custom" class="owner-btn owner-btn-sm owner-btn-primary">Apply</button>
                    </div>
                </form>
                <?php /* One Print button, not five -- it used to be repeated inside
                         every tab panel's own toolbar (all 5 render on every load, see
                         the module docblock). Points at whichever tab is ACTIVE right
                         now; activateTab() below keeps its href in sync when JS
                         switches tabs client-side, since $activeTab here is only the
                         tab the page originally loaded on. */ ?>
                <a class="owner-btn owner-btn-primary owner-btn-sm" id="reportPrintBtn"
                   href="report.php?print=1&amp;tab=<?= htmlspecialchars($activeTab) ?>&amp;<?= htmlspecialchars($currentQueryString) ?>"
                   target="_blank"><i class="ph ph-printer" aria-hidden="true"></i> Print</a>
                </div>
            </div>

            <?php /* Tabs sit on the canvas, not inside the card -- each panel is its
                     own card below them, the same way Inventory and Reservations do
                     it. Boxing the tab strip inside the panel it switches made the
                     card look like it belonged to one tab. */ ?>
            <div class="owner-tabs">
                <?php foreach ($tabMeta as $key => $meta): ?>
                    <a href="#" class="owner-tabs-link<?= $activeTab === $key ? ' is-active' : '' ?>" data-report-tab="<?= $key ?>"><?= htmlspecialchars($meta['label']) ?></a>
                <?php endforeach; ?>
            </div>

                <?php foreach ($tabMeta as $key => $meta): ?>
                <div class="owner-report-panel" id="report-panel-<?= $key ?>" style="<?= $activeTab === $key ? '' : 'display:none;' ?>">
                    <?php if ($key === 'cashier' && $shiftId !== null): ?>
                    <div class="owner-alert" style="background:var(--op-canvas);color:var(--op-ink-soft);">
                        <i class="ph ph-funnel" aria-hidden="true"></i>
                        <span>Showing the shift from your notification.</span>
                        <a href="report.php?tab=cashier" class="owner-btn owner-btn-secondary owner-btn-sm" style="margin-left:auto;">Show all</a>
                    </div>
                    <?php endif; ?>
                    <div class="report-tab-toolbar">
                        <span class="owner-card-subtitle"><?= htmlspecialchars(reportPeriodLabel($period, $rangeStart, $rangeEnd)) ?></span>
                    </div>
                    <?php
                        echo match ($key) {
                            'sales'       => renderSalesTabHtml($tabData[$key]),
                            'cashier'     => renderCashierTabHtml($tabData[$key]),
                            'inventory'   => renderInventoryTabHtml($tabData[$key]),
                            'reservation' => renderReservationTabHtml($tabData[$key]),
                            'payroll'     => renderPayrollTabHtml($tabData[$key]),
                        };
                    ?>
                </div>
                <?php endforeach; ?>

        </main>
    </div>
</div>

<!-- Shift Detail modal (Cashier Remittance tab's "View" action) -- fetched
     from this same page's own ?shift_fragment=1 mode, mirroring how
     orders.php's own order-detail modal works. -->
<!-- Payroll run summary modal (Payroll tab's "Summary report" action) -- fetched
     from this page's own ?payroll_fragment=1 mode, same convention as the
     shift modal below. -->
<div class="owner-modal-backdrop" id="payrollSummaryModalBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="payrollSummaryModalTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="payrollSummaryModalTitle">Payroll summary</h2>
            <button type="button" class="owner-modal-close" id="btnClosePayrollSummaryModal" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <?php // White, not --op-canvas: the canvas IS the page background, so a
              // canvas-filled body made the modal read as a hole in the page
              // rather than a sheet on top of it. It is also what prints. ?>
        <div class="owner-modal-body" id="payrollSummaryModalBody" style="background:var(--op-surface);">
            <div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>
        </div>
        <div class="owner-modal-footer">
            <button type="button" class="owner-btn owner-btn-secondary" id="btnClosePayrollSummaryModalFooter">Close</button>
            <?php // owner-panel.css's @media print hides .owner-modal-footer and
                  // scopes printing to the open modal, so this prints the summary
                  // alone -- no sidebar, no buttons, no page behind it. ?>
            <button type="button" class="owner-btn owner-btn-primary" id="btnPrintPayrollSummary">Print summary</button>
        </div>
    </div>
</div>

<div class="owner-modal-backdrop" id="shiftDetailModalBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="shiftDetailModalTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="shiftDetailModalTitle">Shift</h2>
            <button type="button" class="owner-modal-close" id="btnCloseShiftDetailModal" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body" id="shiftDetailModalBody" style="background:var(--op-canvas);">
            <div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>
        </div>
        <div class="owner-modal-footer">
            <button type="button" class="owner-btn owner-btn-secondary" id="btnCloseShiftDetailModalFooter">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    // -- Tabs (client-side switch, matching admin/users.php's pattern) --
    const tabs = document.querySelectorAll('[data-report-tab]');
    const panels = {
        <?php foreach ($tabMeta as $key => $meta): ?>
        <?= $key ?>: document.getElementById('report-panel-<?= $key ?>'),
        <?php endforeach; ?>
    };
    const activeTabField = document.getElementById('reportActiveTabField');
    const printBtn = document.getElementById('reportPrintBtn');
    // Same query string every per-tab Print link used to build with, minus
    // &tab=... -- that part swaps in below per the tab actually showing.
    const printQueryBase = <?= json_encode($currentQueryString) ?>;

    function activateTab(key) {
        tabs.forEach(t => t.classList.toggle('is-active', t.getAttribute('data-report-tab') === key));
        Object.entries(panels).forEach(([k, el]) => { if (el) el.style.display = (k === key) ? '' : 'none'; });
        if (activeTabField) activeTabField.value = key;
        if (printBtn) printBtn.href = 'report.php?print=1&tab=' + encodeURIComponent(key) + '&' + printQueryBase;
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            activateTab(tab.getAttribute('data-report-tab'));
        });
    });

    // -- Custom date range reveal --
    const customDates = document.getElementById('reportCustomDates');
    document.querySelectorAll('button[name="period"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (customDates) customDates.hidden = btn.value !== 'custom';
        });
    });

    // -- Deep link from the shift discrepancy notification (owner/includes/
    // notification_functions.php's ownerNotifLink()) -- shows exactly that
    // one shift row on the Cashier tab, same "URL param always wins"
    // convention as admin/activity_logs.php's ?log_id= filter. The period
    // is already pinned server-side to that shift's own date (see
    // report.php's PHP), so this just narrows the (usually single-row)
    // table down to the exact shift when more than one cashier closed out
    // that day.
    const requestedShiftId = new URLSearchParams(window.location.search).get('shift_id');
    if (requestedShiftId) {
        document.querySelectorAll('#report-panel-cashier tbody tr[data-shift-id]').forEach((row) => {
            row.style.display = (row.getAttribute('data-shift-id') === requestedShiftId) ? '' : 'none';
        });
    }

    // No tab on this page renders a chart -- the Sales trend moved to
    // owner/analytics.php, which already shows it with period comparison.

    // -- Shift Detail modal (Cashier Remittance tab's "View" eye icon) ------
    // -- Payroll tab: "Summary report" per run ------------------------------
    const paySummaryBackdrop = document.getElementById('payrollSummaryModalBackdrop');
    const paySummaryTitle    = document.getElementById('payrollSummaryModalTitle');
    const paySummaryBody     = document.getElementById('payrollSummaryModalBody');

    function closePaySummaryModal() {
        paySummaryBackdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    document.querySelectorAll('.owner-btn-payroll-summary').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const runId = btn.getAttribute('data-run-id');
            paySummaryTitle.textContent = btn.getAttribute('data-run-number') || 'Payroll summary';
            paySummaryBody.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>';
            paySummaryBackdrop.classList.add('is-open');
            document.body.classList.add('owner-modal-open');

            try {
                const response = await fetch('report.php?payroll_fragment=1&run_id=' + encodeURIComponent(runId));
                if (!response.ok) throw new Error(await response.text() || 'Could not load this payroll run.');
                paySummaryBody.innerHTML = await response.text();
            } catch (err) {
                paySummaryBody.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-danger);">' + err.message + '</div>';
            }
        });
    });

    document.getElementById('btnClosePayrollSummaryModal').addEventListener('click', closePaySummaryModal);
    document.getElementById('btnClosePayrollSummaryModalFooter').addEventListener('click', closePaySummaryModal);
    document.getElementById('btnPrintPayrollSummary').addEventListener('click', () => window.print());
    paySummaryBackdrop.addEventListener('click', (e) => { if (e.target === paySummaryBackdrop) closePaySummaryModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && paySummaryBackdrop.classList.contains('is-open')) closePaySummaryModal();
    });

    const shiftDetailBackdrop = document.getElementById('shiftDetailModalBackdrop');
    const shiftDetailTitle = document.getElementById('shiftDetailModalTitle');
    const shiftDetailBody = document.getElementById('shiftDetailModalBody');

    function openShiftDetailModal() {
        shiftDetailBackdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closeShiftDetailModal() {
        shiftDetailBackdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    function wireShiftDetailTabs() {
        const shiftTabs = shiftDetailBody.querySelectorAll('[data-shift-tab]');
        const shiftPanels = shiftDetailBody.querySelectorAll('[data-shift-panel]');
        shiftTabs.forEach((tab) => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                const key = tab.getAttribute('data-shift-tab');
                shiftTabs.forEach((t) => t.classList.toggle('is-active', t === tab));
                shiftPanels.forEach((p) => { p.style.display = (p.getAttribute('data-shift-panel') === key) ? '' : 'none'; });
            });
        });
    }

    document.querySelectorAll('.owner-btn-view-shift').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const shiftId = btn.getAttribute('data-shift-id');
            shiftDetailTitle.textContent = 'Shift #' + shiftId;
            shiftDetailBody.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-ink-faint);">Loading&hellip;</div>';
            openShiftDetailModal();

            try {
                const response = await fetch('report.php?shift_fragment=1&shift_id=' + shiftId);
                if (!response.ok) throw new Error('Could not load this shift.');
                shiftDetailBody.innerHTML = await response.text();
                wireShiftDetailTabs();
            } catch (err) {
                shiftDetailBody.innerHTML = '<div style="text-align:center;padding:40px;color:var(--op-danger);">' + err.message + '</div>';
            }
        });
    });

    if (shiftDetailBackdrop) {
        document.getElementById('btnCloseShiftDetailModal').addEventListener('click', closeShiftDetailModal);
        document.getElementById('btnCloseShiftDetailModalFooter').addEventListener('click', closeShiftDetailModal);
        shiftDetailBackdrop.addEventListener('click', (e) => { if (e.target === shiftDetailBackdrop) closeShiftDetailModal(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && shiftDetailBackdrop.classList.contains('is-open')) closeShiftDetailModal(); });
    }
})();
</script>

</body>
</html>
