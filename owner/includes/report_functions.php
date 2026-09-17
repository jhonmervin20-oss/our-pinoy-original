<?php
/**
 * owner/includes/report_functions.php
 *
 * Shared aggregation logic for the Reports hub (owner/report.php) --
 * five tabs: Cashier Remittance, Sales, Inventory, Reservation, Payroll.
 * Every number here comes from a table that already exists and is
 * already the source of truth for its own module; nothing is
 * recomputed differently than the module that owns it (e.g. closed-shift
 * totals are read from cash_balances' own snapshot columns, never
 * re-summed from orders, matching cashier/remittance_report.php's own
 * documented convention).
 */

require_once __DIR__ . '/../../config/sales_definitions.php';        // buildSalesStatement(), buildReservationDepositSummary()
require_once __DIR__ . '/../../cashier/includes/pos_functions.php';   // computeShiftTotals()
require_once __DIR__ . '/../../inventory/includes/inventory_functions.php'; // fmtQty()
require_once __DIR__ . '/../../employee_management/includes/payroll_functions.php'; // buildPayrollRunsQuery(), formatPeso(), payrollRunStatusLabel()
// demand_forecast_functions.php is no longer required here. It was pulled in for
// computeSalesInsights(), getDailyDenseRevenueHistory() and buildFastMovingQuery(),
// all of which fed the Sales trend chart and Top Selling Items table -- both
// removed from this report. Verified before deleting: neither this file nor
// owner/report.php calls any of that file's 42 functions. That drops a
// 1,400-line include (and its own transitive requires) from every report view.

// ---------------------------------------------------------------------
// Period resolution (calendar-aligned: Today / This Week / This Month /
// This Year / Custom -- distinct from demand_forecast's trailing-N-days
// resolvePeriodRange(), which serves a different purpose there).
// ---------------------------------------------------------------------

function resolveReportPeriodRange(string $period, ?string $dateFrom, ?string $dateTo): array
{
    $today = new DateTime('today');

    switch ($period) {
        case 'this_week':
            $dow   = (int)$today->format('N'); // 1=Mon..7=Sun
            $start = (clone $today)->modify('-' . ($dow - 1) . ' days');
            $end   = clone $today;
            break;
        case 'this_month':
            $start = new DateTime($today->format('Y-m-01'));
            $end   = clone $today;
            break;
        case 'this_year':
            $start = new DateTime($today->format('Y') . '-01-01');
            $end   = clone $today;
            break;
        case 'custom':
            $parsedFrom = $dateFrom ? DateTime::createFromFormat('Y-m-d', $dateFrom) : false;
            $parsedTo   = $dateTo ? DateTime::createFromFormat('Y-m-d', $dateTo) : false;
            $start = $parsedFrom ?: new DateTime($today->format('Y-m-01'));
            $end   = $parsedTo ?: clone $today;
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }
            break;
        case 'today':
        default:
            $start = clone $today;
            $end   = clone $today;
            break;
    }

    $start->setTime(0, 0, 0);
    $end->setTime(0, 0, 0);

    return [$start, $end];
}

function reportPeriodLabel(string $period, DateTime $start, DateTime $end): string
{
    $labels = ['today' => 'Today', 'this_week' => 'This Week', 'this_month' => 'This Month', 'this_year' => 'This Year'];
    $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
    $range   = $start->format('M j, Y') . ($sameDay ? '' : ' – ' . $end->format('M j, Y'));

    return ($labels[$period] ?? 'Custom range') . ' (' . $range . ')';
}

function reportPaymentMethodLabel(string $method): string
{
    return match ($method) {
        'cash'             => 'Cash',
        'paymongo_gcash'   => 'GCash',
        'paymongo_checkout' => 'Maya',
        default            => ucfirst(str_replace('_', ' ', $method)),
    };
}

// ---------------------------------------------------------------------
// Tab 1: Cashier Remittance
// ---------------------------------------------------------------------

/**
 * Closed shifts within the period read their KPI numbers from
 * cash_balances' own snapshot columns (total_sales/expected_cash/
 * counted_cash/variance) -- never recomputed, matching
 * cashier/remittance_report.php's own documented convention. Any shift
 * still open right now gets its live totals via computeShiftTotals()
 * instead of reading NULL snapshot columns (bounded -- there's normally
 * at most one open shift per cashier at a time).
 */
function buildCashierRemittanceSummary(PDO $db, DateTime $start, DateTime $end): array
{
    $tsFrom = $start->format('Y-m-d 00:00:00');
    $tsTo   = $end->format('Y-m-d 23:59:59');

    $stmt = $db->prepare(
        "SELECT cs.shift_id, cs.cashier_id, cs.opening_cash, cs.opened_at, cs.closed_at, cs.status,
                cs.total_sales, cs.gcash_sales, cs.expected_cash, cs.counted_cash, cs.variance,
                u.first_name, u.last_name
         FROM cash_balances cs
         JOIN users u ON u.user_id = cs.cashier_id
         WHERE (cs.status = 'closed' AND cs.closed_at BETWEEN ? AND ?)
            OR (cs.status = 'open' AND cs.opened_at BETWEEN ? AND ?)
         ORDER BY COALESCE(cs.closed_at, cs.opened_at) DESC"
    );
    $stmt->execute([$tsFrom, $tsTo, $tsFrom, $tsTo]);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalSales    = 0.0;
    $totalVariance = 0.0;
    $closedCount   = 0;
    $openCount     = 0;
    // The physical money that passed through the drawer, and how much of it was
    // the restaurant's own float. Sales alone do not say this: a shift that took
    // 14,900 had 16,900 in the drawer at close, because the 2,000 float is in
    // there too. Separating the two is what makes the counted figure checkable.
    $cashCounted   = 0.0;
    $totalFloat    = 0.0;
    $unbalanced    = 0;
    // Cash sales are carried per shift as well as summed: without them the
    // expected-cash column cannot be checked by eye, since expected cash is
    // the petty cash fund plus cash sales -- never total sales, which include
    // GCash that never entered the drawer.
    $cashSalesTotal = 0.0;
    $gcashSalesTotal = 0.0;

    foreach ($shifts as &$shift) {
        if ($shift['status'] === 'closed') {
            $cashSales = round((float)$shift['total_sales'] - (float)($shift['gcash_sales'] ?? 0), 2);
            $shift['cash_sales'] = $cashSales;
            $totalSales    += (float)$shift['total_sales'];
            $cashSalesTotal += $cashSales;
            $gcashSalesTotal += (float)($shift['gcash_sales'] ?? 0);
            $totalVariance += (float)($shift['variance'] ?? 0);
            $cashCounted   += (float)($shift['counted_cash'] ?? 0);
            $totalFloat    += (float)$shift['opening_cash'];
            if (abs((float)($shift['variance'] ?? 0)) >= 0.01) {
                $unbalanced++;
            }
            $closedCount++;
        } else {
            $live = computeShiftTotals($db, (int)$shift['shift_id'], (float)$shift['opening_cash']);
            $shift['total_sales']   = $live['total_sales'];
            $shift['gcash_sales']   = $live['by_method']['paymongo_gcash']['total'] ?? 0.0;
            $shift['cash_sales']    = $live['cash_sales'];
            $shift['expected_cash'] = $live['expected_cash'];
            $totalSales += $live['total_sales'];
            $cashSalesTotal += $live['cash_sales'];
            $gcashSalesTotal += $shift['gcash_sales'];
            $openCount++;
        }
    }
    unset($shift);

    $shiftCount = $closedCount + $openCount;

    // GCash is summed from the SAME shifts as every other figure on this tab,
    // not from the period-scoped tender summary the Sales tab uses. A shift is
    // a drawer concept and can run past midnight, so the two scopes disagree by
    // whatever a straddling shift took -- and a remittance tab whose cash and
    // GCash tiles do not add up to its own total sales is exactly the kind of
    // thing that makes a reader distrust the page. The Sales tab remains the
    // canonical, date-scoped revenue report.
    $gcashTxns = 0;
    foreach (buildTenderSummary($db, $start, $end)['rows'] as $tenderRow) {
        if ($tenderRow['method'] === 'paymongo_gcash') {
            $gcashTxns = (int)$tenderRow['txn_count'];
            break;
        }
    }

    return [
        'shifts'              => $shifts,
        'total_sales'         => $totalSales,
        'total_variance'      => $totalVariance,
        'closed_count'        => $closedCount,
        'open_count'          => $openCount,
        'shift_count'         => $shiftCount,
        'cash_counted'        => $cashCounted,
        'cash_sales'          => round($cashSalesTotal, 2),
        'total_float'         => $totalFloat,
        'unbalanced_count'    => $unbalanced,
        'gcash_sales'         => round($gcashSalesTotal, 2),
        'gcash_txn_count'     => $gcashTxns,
    ];
}

// ---------------------------------------------------------------------
// Tab 2: Sales -- the revenue figures come from config/sales_definitions.php
// (one sale definition, one decomposition, shared with the dashboards and
// Analytics); the item rankings still reuse demand_forecast_functions.php's
// existing Sales Insights / Fast Moving building blocks.
//
// getTopPaymentMethod() was removed here: a single "top payment method" told
// an owner almost nothing, and buildTenderSummary() now reports every method
// with its transaction count, amount and share instead.
// ---------------------------------------------------------------------

/**
 * The Sales tab's data, built on config/sales_definitions.php's single sale
 * definition and single revenue decomposition rather than on its own ad-hoc
 * SUMs -- which is what let this tab disagree with the dashboard, with
 * Analytics, and with the cashier's own drawer.
 *
 * 'total_revenue' is deliberately NET SALES EXCLUDING VAT. That is the figure a
 * P&L calls revenue and the one an owner should be judged on: VAT is collected
 * on the government's behalf and passed straight through, so counting it as
 * earnings inflates every margin on the page. The gross and the cash actually
 * collected are both still shown alongside it, so nothing is hidden -- the
 * headline is just the honest one.
 */
function buildSalesSummary(PDO $db, DateTime $start, DateTime $end): array
{
    // Only the statement and the deposits are built now. The daily revenue
    // series (getDailyDenseRevenueHistory) and the top-items ranking
    // (buildFastMovingQuery) were dropped with the trend chart and Top Selling
    // Items table -- three queries per render that nothing displayed any more.
    $statement = buildSalesStatement($db, $start, $end);
    $deposits  = buildReservationDepositSummary($db, $start, $end);

    return [
        'statement'         => $statement,
        'deposits'          => $deposits,
        'total_revenue'     => $statement['net_sales_vat_exc'],
        'total_orders'      => $statement['order_count'],
        'avg_order_value'   => $statement['avg_order_value'],
        // Food & beverage revenue plus the one genuine non-sales income line
        // this business has: deposits forfeited by no-shows and cancellations.
        'total_income'      => round($statement['net_sales_vat_exc'] + $deposits['other_income'], 2),
    ];
}

/** One line of the revenue waterfall. $emphasis: 'sub' (a running subtotal) or 'total'. */
function salesStatementRow(string $label, float $amount, string $emphasis = '', string $note = ''): string
{
    $isSub   = $emphasis === 'sub';
    $isTotal = $emphasis === 'total';
    $weight  = ($isSub || $isTotal) ? '600' : '400';
    $border  = $isSub ? 'border-top:1px solid var(--op-line);' : ($isTotal ? 'border-top:2px solid var(--op-ink);' : '');

    return '<tr style="' . $border . '">'
        . '<td style="font-weight:' . $weight . ';">' . htmlspecialchars($label)
        . ($note !== '' ? '<div style="font-size:0.72rem;font-weight:400;color:var(--op-ink-faint);margin-top:2px;">' . htmlspecialchars($note) . '</div>' : '')
        . '</td>'
        . '<td class="num" style="text-align:right;font-weight:' . $weight . ';white-space:nowrap;">'
        . ($amount < 0 ? '(&#8369;' . number_format(abs($amount), 2) . ')' : '&#8369;' . number_format($amount, 2))
        . '</td></tr>';
}

// ---------------------------------------------------------------------
// Tab 3: Inventory -- movement totals reuse inventory_transactions'
// exact in/out convention from buildInventoryTransactionQuery() (only
// 'stock_in' counts as in; stock_out/adjustment/waste/transfer all count
// as out, matching that query's own running-balance CASE expression).
// ---------------------------------------------------------------------

function buildInventoryMovementSummary(PDO $db, DateTime $start, DateTime $end): array
{
    $tsFrom = $start->format('Y-m-d 00:00:00');
    $tsTo   = $end->format('Y-m-d 23:59:59');

    $stmt = $db->prepare(
        // 'void_return' rows are transaction_type='stock_in' (this app's
        // convention for an increase-direction correction), but they are not
        // stock RECEIVED -- counting them under a card labelled "Stock received"
        // would report purchases that never happened. They net against qty_out
        // instead, because that is what they actually undo: a voided sale did
        // not consume anything, and "Stock deducted / sold-used" has to say so.
        "SELECT
            SUM(CASE WHEN transaction_type = 'stock_in' AND COALESCE(reference_type, '') <> 'void_return' THEN quantity ELSE 0 END) AS qty_in,
            SUM(CASE WHEN transaction_type = 'stock_out' THEN quantity
                     WHEN reference_type = 'void_return' THEN -quantity
                     ELSE 0 END) AS qty_out,
            SUM(CASE WHEN transaction_type = 'waste' THEN quantity ELSE 0 END) AS qty_waste,
            SUM(CASE WHEN transaction_type = 'adjustment' THEN quantity ELSE 0 END) AS qty_adjusted,
            COUNT(*) AS txn_count
         FROM inventory_transactions
         WHERE created_at >= ? AND created_at <= ?"
    );
    $stmt->execute([$tsFrom, $tsTo]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);


    $statusStmt = $db->query(
        "SELECT stock_status, COUNT(*) AS cnt FROM inventory_stock_status GROUP BY stock_status"
    );
    $stockStatusCounts = [];
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stockStatusCounts[$row['stock_status']] = (int)$row['cnt'];
    }
    $needsReorder = ($stockStatusCounts['critical'] ?? 0) + ($stockStatusCounts['low'] ?? 0) + ($stockStatusCounts['out_of_stock'] ?? 0);

    // No per-transaction list is fetched here any more -- the "Recent stock
    // movement" table it fed was removed from this tab (it duplicated
    // inventory/inventory_transactions.php). That also drops a 200-row query
    // from every render of this report.
    return [
        'qty_in'              => (float)($totals['qty_in'] ?? 0),
        'qty_out'             => (float)($totals['qty_out'] ?? 0),
        'qty_waste'           => (float)($totals['qty_waste'] ?? 0),
        'qty_adjusted'        => (float)($totals['qty_adjusted'] ?? 0),
        'txn_count'           => (int)($totals['txn_count'] ?? 0),
        'stock_status_counts' => $stockStatusCounts,
        'needs_reorder_count' => $needsReorder,
        'reconciliation'      => buildInventoryReconciliationTable($db, $start, $end),
        // Drives the "n/a" footnote under the reconciliation table, so the
        // renderer doesn't have to reach for its own DB handle.
        'balance_known_from'  => ledgerReliableFrom($db),
    ];
}

/**
 * Per-ingredient Opening / Stock In / Used / Waste / Adjustments / Closing.
 * Reuses the app's own existing signed running-balance convention (already
 * trusted elsewhere -- see inventory_functions.php's window function):
 * transaction_type='stock_in' -> +quantity, everything else -> -quantity.
 *
 * That convention already handles both adjustment directions correctly,
 * because increase-direction adjustments are logged as transaction_type=
 * 'stock_in' (reference_type='adjustment'), not as transaction_type=
 * 'adjustment' -- only decrease-direction adjustments use that type
 * (confirmed against live stock_adjustments/inventory_transactions data,
 * not assumed). So "Stock In" vs. "Adjustments" is split by reference_type
 * (purchase_order/initial_stock vs. adjustment), not transaction_type.
 *
 * An approved order void writes reference_type='void_return' rows (also
 * transaction_type='stock_in', same convention) and those net against USED
 * rather than getting a column of their own. Used is the right home because a
 * void is precisely the un-consuming of a sale, and it is the only placement
 * that keeps the row arithmetic intact:
 *
 *   Opening + StockIn - Used - Waste + Adjustments = Closing
 *
 * still holds exactly, since void_return contributes +q to net_during and -q to
 * Used. Putting it in Stock In would have claimed a purchase that never
 * happened; giving it its own column would have widened an already-wide table
 * for a number that belongs in the consumption line anyway. A period that voids
 * more than it sold can show a negative Used -- that is honest, and
 * reconQtyCell() already renders signed values.
 */
function buildInventoryReconciliationTable(PDO $db, DateTime $start, DateTime $end): array
{
    $tsStart = $start->format('Y-m-d 00:00:00');
    $tsEnd   = $end->format('Y-m-d 23:59:59');

    // Opening/Closing are anchored to inventory_batches (real stock on hand)
    // and walked BACKWARDS through the ledger, rather than replayed forwards
    // from it. The forward replay was wrong: ~24,163 units were consumed
    // before per-transaction logging existed, so summing the ledger from the
    // beginning of time overstated Closing by that amount on 51 of 52 items
    // (Chicken read 233.3 kg against 12.55 kg actually in its batches).
    //
    //   closing = stock_now - (net ledger movement strictly AFTER the period)
    //   opening = closing    - (net ledger movement DURING the period)
    //
    // Every input here is real: batch quantities and logged transactions.
    // Nothing is back-filled or invented, and the per-period Stock In / Used /
    // Waste / Adjustments columns keep their existing meaning untouched --
    // only the two running-balance columns change. A period ending today now
    // closes at exactly the stock the inventory page shows.
    $stmt = $db->prepare(
        "SELECT ii.item_id, ii.item_name, ic.category_name, u.unit_code,
            COALESCE(bs.stock_now, 0) AS stock_now,
            SUM(CASE WHEN it.created_at BETWEEN ? AND ? AND it.transaction_type = 'stock_in' AND it.reference_type IN ('purchase_order', 'initial_stock')
                THEN it.quantity ELSE 0 END) AS stock_in,
            SUM(CASE WHEN it.created_at BETWEEN ? AND ?
                THEN (CASE WHEN it.transaction_type = 'stock_out' THEN it.quantity
                           WHEN it.reference_type = 'void_return' THEN -it.quantity
                           ELSE 0 END) ELSE 0 END) AS used,
            SUM(CASE WHEN it.created_at BETWEEN ? AND ? AND it.transaction_type = 'waste'
                THEN it.quantity ELSE 0 END) AS waste,
            SUM(CASE WHEN it.created_at BETWEEN ? AND ? AND it.reference_type = 'adjustment'
                THEN (CASE WHEN it.transaction_type = 'stock_in' THEN it.quantity ELSE -it.quantity END) ELSE 0 END) AS adjustments,
            SUM(CASE WHEN it.created_at BETWEEN ? AND ?
                THEN (CASE WHEN it.transaction_type = 'stock_in' THEN it.quantity ELSE -it.quantity END) ELSE 0 END) AS net_during,
            SUM(CASE WHEN it.created_at > ?
                THEN (CASE WHEN it.transaction_type = 'stock_in' THEN it.quantity ELSE -it.quantity END) ELSE 0 END) AS net_after
         FROM inventory_items ii
         JOIN unit_of_measures u ON u.unit_id = ii.base_unit_id
         LEFT JOIN inventory_categories ic ON ic.category_id = ii.category_id
         LEFT JOIN inventory_transactions it ON it.item_id = ii.item_id
         LEFT JOIN (
             SELECT item_id, SUM(quantity_remaining) AS stock_now
             FROM inventory_batches GROUP BY item_id
         ) bs ON bs.item_id = ii.item_id
         WHERE ii.is_active = 1
         GROUP BY ii.item_id, ii.item_name, ic.category_name, u.unit_code, bs.stock_now
         ORDER BY ii.item_name"
    );
    $stmt->execute([$tsStart, $tsEnd, $tsStart, $tsEnd, $tsStart, $tsEnd, $tsStart, $tsEnd, $tsStart, $tsEnd, $tsEnd]);

    // Per-transaction consumption logging only began once the first non-
    // stock_in row was written; roughly 24,163 units were used before that and
    // were never recorded. Walking the balance across that boundary would
    // invent numbers (it produced negative stock for pre-cutover months), so
    // a balance is reported only where every movement up to it is on record:
    //   closing  needs the window [period end .. now]   fully logged
    //   opening  needs the window [period start .. now] fully logged
    // Outside that, the value is null and the UI shows "n/a" rather than a
    // figure nobody can stand behind.
    $reliableFrom = ledgerReliableFrom($db);
    $openingKnown = $reliableFrom !== null && $tsStart >= $reliableFrom;
    $closingKnown = $reliableFrom !== null && $tsEnd   >= $reliableFrom;

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        foreach (['stock_now', 'stock_in', 'used', 'waste', 'adjustments', 'net_during', 'net_after'] as $col) {
            $row[$col] = (float)$row[$col];
        }
        $closing = round($row['stock_now'] - $row['net_after'], 3);
        $row['closing'] = $closingKnown ? $closing : null;
        $row['opening'] = $openingKnown ? round($closing - $row['net_during'], 3) : null;
        unset($row['stock_now'], $row['net_during'], $row['net_after']);
    }
    unset($row);

    return $rows;
}

/**
 * One reconciliation-table cell. Three distinct states that must not be
 * conflated -- note PHP's loose comparison treats null == 0.0 as true, so the
 * null check has to come first and be strict:
 *   null  -> "n/a", the ledger cannot support a balance for this boundary
 *   0.0   -> "--",  genuinely no movement
 *   other -> the quantity, with a leading + for positive adjustments
 */
function reconQtyCell(?float $value, string $unitCode, bool $signed = false): string
{
    if ($value === null) {
        return '<span class="owner-ink-faint" title="No stock ledger before per-transaction logging began">n/a</span>';
    }
    if ($value == 0.0) {
        return '&mdash;';
    }
    $prefix = ($signed && $value > 0) ? '+' : '';
    return $prefix . fmtQty($value) . ' ' . htmlspecialchars($unitCode);
}

// ledgerReliableFrom() moved to inventory/includes/inventory_functions.php --
// inventory_transactions.php's running-balance column needed the exact same
// reliability cutover this reconciliation table already used, so it's now the
// shared home for both. Already in scope here via the require at the top of
// this file.

// ---------------------------------------------------------------------
// Tab 4: Reservation
// ---------------------------------------------------------------------

/**
 * Pending and cancelled reservations are the customer's side of the
 * booking flow (an unpaid hold in progress, or that hold lapsing / being
 * backed out of before payment) -- they never became real, revenue-bearing
 * bookings, so this report -- like the staff reservation panel
 * (reservation/reservations.php) -- only tracks the three statuses that
 * did: confirmed (coming), completed (showed up), no_show (didn't).
 */
const REPORT_RESERVATION_TRACKED_STATUSES = "'confirmed', 'completed', 'no_show'";

function buildReservationSummary(PDO $db, DateTime $start, DateTime $end): array
{
    $dateFrom = $start->format('Y-m-d');
    $dateTo   = $end->format('Y-m-d');

    $statusStmt = $db->prepare(
        "SELECT status, COUNT(*) AS cnt FROM reservations
         WHERE status IN (" . REPORT_RESERVATION_TRACKED_STATUSES . ") AND reservation_date BETWEEN ? AND ?
         GROUP BY status"
    );
    $statusStmt->execute([$dateFrom, $dateTo]);
    $byStatus = [];
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byStatus[$row['status']] = (int)$row['cnt'];
    }

    $totalBookings = array_sum($byStatus);
    $completed     = $byStatus['completed'] ?? 0;
    $noShow        = $byStatus['no_show'] ?? 0;

    // Denominator: reservations that actually reached their date one way
    // or another (completed or no-show).
    $noShowEligible = $completed + $noShow;
    $noShowRate     = $noShowEligible > 0 ? ($noShow / $noShowEligible) * 100 : null;

    // Scoped the same way: an advance order attached to a reservation that
    // never converted (still pending, or lapsed/cancelled before payment)
    // was never collected and is not revenue.
    $advanceStmt = $db->prepare(
        "SELECT COALESCE(SUM(rao.subtotal), 0) AS revenue, COUNT(DISTINCT rao.reservation_id) AS reservation_count
         FROM reservation_advance_orders rao
         JOIN reservations r ON r.reservation_id = rao.reservation_id
         WHERE r.status IN (" . REPORT_RESERVATION_TRACKED_STATUSES . ") AND r.reservation_date BETWEEN ? AND ?"
    );
    $advanceStmt->execute([$dateFrom, $dateTo]);
    $advance = $advanceStmt->fetch(PDO::FETCH_ASSOC);

    $listStmt = $db->prepare(
        "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.number_of_guests, r.status,
                ts.slot_label, ts.start_time,
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         LEFT JOIN users c ON c.user_id = r.customer_id
         WHERE r.status IN (" . REPORT_RESERVATION_TRACKED_STATUSES . ") AND r.reservation_date BETWEEN ? AND ?
         ORDER BY r.reservation_date DESC, ts.start_time DESC
         LIMIT 200"
    );
    $listStmt->execute([$dateFrom, $dateTo]);
    $reservations = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'by_status'             => $byStatus,
        'total_bookings'        => $totalBookings,
        'completed'             => $completed,
        'no_show'               => $noShow,
        'no_show_rate'          => $noShowRate,
        'advance_order_revenue' => (float)$advance['revenue'],
        'reservations'          => $reservations,
    ];
}

// ---------------------------------------------------------------------
// Tab 5: Payroll -- aggregates payroll_runs' own precomputed totals
// (never re-sums individual payslips, matching how runs.php's own KPI
// cards are built). Owner-level visibility into payroll is new (every
// employee_management/*.php page today gates on 'manager' only) -- see the module's
// project memory for why report.php is still owner/admin-gated.
// ---------------------------------------------------------------------

/**
 * Run states whose money is real.
 *
 * `released` has been paid. `approved` is signed off and committed, so it
 * belongs in a period total the same way an approved invoice does. Everything
 * else -- draft, processing, pending_approval, cancelled -- is either not yet a
 * decision or an abandoned one, and must not be counted as pay.
 */
const PAYROLL_PAYABLE_STATUSES = ['approved', 'released'];

function buildPayrollSummary(PDO $db, DateTime $start, DateTime $end): array
{
    $dateFrom = $start->format('Y-m-d');
    $dateTo   = $end->format('Y-m-d');

    $stmt = $db->prepare(
        "SELECT payroll_run_id, run_number, pay_frequency, cutoff_period_start, cutoff_period_end, payout_date, status,
                total_employees, total_gross_pay, total_deductions, total_net_pay
         FROM payroll_runs
         WHERE payout_date BETWEEN ? AND ?
         ORDER BY payout_date DESC"
    );
    $stmt->execute([$dateFrom, $dateTo]);
    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The table lists every run in the period, whatever its state -- "what is in
    // flight" is a real question and the Status column answers it.
    //
    // The TOTALS may not. They were summing all six statuses, so a CANCELLED run
    // counted toward "Total net pay" and its headcount toward "employee-payout(s)"
    // -- money that was never paid, reported as paid. A draft nobody approved did
    // the same. Only `approved` and `released` represent a payout that is
    // actually committed, so only those are summed.
    $payable = array_values(array_filter(
        $runs,
        fn($r) => in_array($r['status'], PAYROLL_PAYABLE_STATUSES, true)
    ));

    /* Per-run detail.
     *
     * The Owner cannot open employee_management/run_detail.php -- that page,
     * runs.php and payslip.php are all hasRole(['manager']) -- so this report
     * tab is the Owner's ONLY view of payroll. Four period totals and a seven
     * column list were not enough to answer "what did I pay, and what do I owe
     * the government", so each run also carries:
     *
     *   - how its gross was composed (basic / OT / holiday / rest day / night
     *     differential / leave), read from payslips, which stores each of those
     *     as its own column;
     *   - the statutory split, and each item's EMPLOYER share, which is real
     *     cost that appears in neither gross nor net pay. The Owner is the one
     *     liable for remitting these, so a single "Total deductions" lump was
     *     the least useful possible shape for it.
     *
     * Only payable runs are detailed: a draft or cancelled run has no payout to
     * account for, and pulling its payslips would invite reading them as money
     * owed. Their rows still appear in the list above, greyed, as they did.
     */
    $payableIds = array_map(fn($r) => (int)$r['payroll_run_id'], $payable);

    $composition = [];
    $statutory   = [];

    if ($payableIds) {
        $in = implode(',', array_fill(0, count($payableIds), '?'));

        $compStmt = $db->prepare(
            "SELECT payroll_run_id,
                    COALESCE(SUM(basic_pay), 0)              AS basic_pay,
                    COALESCE(SUM(overtime_pay), 0)           AS overtime_pay,
                    COALESCE(SUM(holiday_pay), 0)            AS holiday_pay,
                    COALESCE(SUM(rest_day_pay), 0)           AS rest_day_pay,
                    COALESCE(SUM(night_differential_pay), 0) AS night_differential_pay,
                    COALESCE(SUM(leave_pay), 0)              AS leave_pay,
                    COALESCE(SUM(late_deduction), 0)         AS late_deduction,
                    COALESCE(SUM(undertime_deduction), 0)    AS undertime_deduction,
                    COALESCE(SUM(absence_deduction), 0)      AS absence_deduction,
                    COUNT(*)                                 AS payslip_count
             FROM payslips
             WHERE payroll_run_id IN ($in)
             GROUP BY payroll_run_id"
        );
        $compStmt->execute($payableIds);
        foreach ($compStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $composition[(int)$row['payroll_run_id']] = $row;
        }

        // Grouped by run AND type. `category` separates what is remitted to a
        // government agency from loans and everything else, because only the
        // first group carries a filing deadline.
        $dedStmt = $db->prepare(
            "SELECT ps.payroll_run_id, dt.code, dt.name, dt.category,
                    COALESCE(SUM(pd.employee_amount), 0) AS employee_amount,
                    COALESCE(SUM(pd.employer_amount), 0) AS employer_amount
             FROM payslip_deductions pd
             JOIN payslips ps               ON ps.payslip_id = pd.payslip_id
             JOIN payroll_deduction_types dt ON dt.deduction_type_id = pd.deduction_type_id
             WHERE ps.payroll_run_id IN ($in)
             GROUP BY ps.payroll_run_id, dt.deduction_type_id
             ORDER BY dt.display_order, dt.name"
        );
        $dedStmt->execute($payableIds);
        foreach ($dedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statutory[(int)$row['payroll_run_id']][] = $row;
        }
    }

    // Employer share is summed across every run so the period tiles can report
    // total payroll COST -- gross plus employer share -- which is what actually
    // leaves the business, and is strictly larger than the gross figure alone.
    $employerShare = 0.0;
    foreach ($statutory as $rows) {
        foreach ($rows as $row) {
            $employerShare += (float)$row['employer_amount'];
        }
    }

    $totalGross = array_sum(array_column($payable, 'total_gross_pay'));

    return [
        'runs'                 => $runs,
        'run_count'            => count($runs),
        // Counted separately so the tab can say WHY the totals are lower than the
        // table, rather than leaving a reader to spot the discrepancy themselves.
        'payable_count'        => count($payable),
        'excluded_count'       => count($runs) - count($payable),
        'total_gross_pay'      => $totalGross,
        'total_deductions'     => array_sum(array_column($payable, 'total_deductions')),
        'total_net_pay'        => array_sum(array_column($payable, 'total_net_pay')),
        'total_employees_paid' => array_sum(array_column($payable, 'total_employees')),
        'payable_runs'         => $payable,
        'composition'          => $composition,
        'statutory'            => $statutory,
        'employer_share'       => round($employerShare, 2),
        'total_payroll_cost'   => round($totalGross + $employerShare, 2),
    ];
}

// ---------------------------------------------------------------------
// Per-tab render functions -- one shared function per tab, used both by
// the live tabbed view and the ?print=1 chrome-free view, matching the
// "one render function, two callers" convention already established by
// renderRemittanceDocumentHtml() / renderPayslipCardHtml().
// Charts are omitted in print.
// ---------------------------------------------------------------------

function reportKpiCard(string $label, string $value, string $meta = '', string $icon = 'ph-chart-bar'): string
{
    return '<div class="owner-summary-card"><div>'
        . '<div class="owner-summary-card-label">' . htmlspecialchars($label) . '</div>'
        . '<div class="owner-summary-card-value">' . $value . '</div>'
        . ($meta !== '' ? '<div class="df-kpi-meta">' . $meta . '</div>' : '')
        . '</div>'
        // Payment-method cards pass '' -- render no icon element at all rather
        // than an empty <i>, which would still take up its own slot in the card.
        . ($icon !== '' ? '<i class="ph ' . htmlspecialchars($icon) . '" style="font-size:1.6rem;color:var(--op-gold);" aria-hidden="true"></i>' : '')
        . '</div>';
}

/**
 * One titled section card, matching how owner/analytics.php builds its
 * panels: every chart and every table gets its own .owner-card with a real
 * title/subtitle head instead of floating loose under a small uppercase
 * heading. Adjacent cards are spaced by owner-panel.css's own
 * `.owner-card + .owner-card { margin-top: 20px }`, so nothing here sets
 * margins of its own.
 */
function reportSectionCard(string $title, string $subtitle, string $body): string
{
    return '<div class="owner-card rp-section">'
        . '<div class="owner-card-head"><div>'
        . '<h2 class="owner-card-title">' . htmlspecialchars($title) . '</h2>'
        . ($subtitle !== '' ? '<span class="owner-card-subtitle">' . htmlspecialchars($subtitle) . '</span>' : '')
        . '</div></div>'
        . $body
        . '</div>';
}

function renderCashierTabHtml(array $data): string
{
    ob_start(); ?>
    <?php // The four tiles follow the same order as the cashier's own Cash
          // Balance screen -- total, then the two tenders that make it up, then
          // the variance -- so an owner and a cashier reading their separate
          // screens are reading the same four figures in the same order. ?>
    <div class="owner-form-grid">
        <?= reportKpiCard('Total sales', '&#8369;' . number_format($data['total_sales'], 2), $data['shift_count'] . ' shift(s), cash + GCash', 'ph-currency-circle-dollar') ?>
        <?= reportKpiCard('Cash sales', '&#8369;' . number_format($data['cash_sales'], 2), 'Cash payments only', 'ph-money') ?>
        <?= reportKpiCard(
                'GCash sales',
                '&#8369;' . number_format($data['gcash_sales'], 2),
                $data['gcash_sales'] > 0
                    ? 'Never enters the drawer'
                    : 'No GCash payments in this period',
                'ph-device-mobile'
            ) ?>
        <?= reportKpiCard(
                'Variance',
                ($data['total_variance'] > 0 ? '+' : ($data['total_variance'] < 0 ? '&minus;' : '')) . '&#8369;' . number_format(abs($data['total_variance']), 2),
                $data['total_variance'] == 0
                    ? 'Balanced'
                    : ($data['unbalanced_count'] . ' of ' . $data['closed_count'] . ' closed shift(s) off'),
                abs($data['total_variance']) > 0 ? 'ph-warning' : 'ph-scales'
            ) ?>
    </div>
    <div class="owner-card rp-section">
        <div class="owner-card-head"><div>
            <?php // No formula line under this title: the columns run left to right
                  // in the order they are computed, so the arithmetic reads off the
                  // row itself. ?>
            <h2 class="owner-card-title">Cashier Remittance</h2>
        </div></div>
    <?php if (empty($data['shifts'])): ?>
        <div class="owner-table-empty">No cashier shifts in this period.</div>
    <?php else: ?>
    <div class="owner-table-wrap">
        <table class="owner-table">
            <thead><tr>
                <th>Cashier</th><th>Opened</th><th>Closed</th>
                <th class="num">Petty Cash Fund</th><th class="num">Cash Sales</th><th class="num">GCash Sales</th>
                <th class="num">Total Sales</th><th class="num">Expected Cash</th><th class="num">Cash on Hand</th>
                <th class="num">Variance</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($data['shifts'] as $s): ?>
                <?php
                    $variance = $s['variance'] === null ? null : (float)$s['variance'];
                    $varianceText = $variance === null
                        ? '&mdash;'
                        : ($variance == 0
                            ? '&#8369;0.00 Balanced'
                            : ($variance > 0
                                ? '+&#8369;' . number_format($variance, 2) . ' Over'
                                : '&minus;&#8369;' . number_format(abs($variance), 2) . ' Short'));
                ?>
                <tr data-shift-id="<?= (int)$s['shift_id'] ?>">
                    <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                    <td><?= htmlspecialchars(date('M j, g:i A', strtotime($s['opened_at']))) ?></td>
                    <td><?= $s['closed_at'] ? htmlspecialchars(date('M j, g:i A', strtotime($s['closed_at']))) : '<span class="owner-status-pill is-info">Still open</span>' ?></td>
                    <td class="num">&#8369;<?= number_format((float)$s['opening_cash'], 2) ?></td>
                    <td class="num">&#8369;<?= number_format((float)($s['cash_sales'] ?? 0), 2) ?></td>
                    <td class="num">&#8369;<?= number_format((float)($s['gcash_sales'] ?? 0), 2) ?></td>
                    <td class="num">&#8369;<?= number_format((float)$s['total_sales'], 2) ?></td>
                    <td class="num"><?= $s['expected_cash'] !== null ? '&#8369;' . number_format((float)$s['expected_cash'], 2) : '&mdash;' ?></td>
                    <td class="num"><?= $s['counted_cash'] !== null ? '&#8369;' . number_format((float)$s['counted_cash'], 2) : '&mdash;' ?></td>
                    <td class="num"><?= $varianceText ?></td>
                    <td><button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-view-shift" data-shift-id="<?= (int)$s['shift_id'] ?>" aria-label="View shift"><i class="ph ph-eye" aria-hidden="true"></i></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * The Cashier Remittance tab's "View" action -- one shift's full detail
 * (Summary / Orders / Payment Breakdown / Notes), fetched into a modal.
 * Same underlying data and Cash/GCash-sales-broken-out-before-Expected-
 * Cash layout as cashier/remittance_report.php's own
 * renderShiftDetailFragmentHtml(), just rebuilt with owner-panel.css's own
 * components (reportKpiCard()/.owner-form-grid, .owner-table,
 * .owner-tabs-link repurposed as in-modal tabs the same way
 * cashier/remittance_report.php repurposes .pos-category-pills) rather
 * than sharing markup across two completely different design systems.
 */
function renderCashierShiftDetailFragmentHtml(array $shift, array $totals): string
{
    $isClosed     = $shift['status'] === 'closed';
    $totalSales   = $isClosed ? (float)$shift['total_sales'] : $totals['total_sales'];
    $expectedCash = $isClosed ? (float)$shift['expected_cash'] : $totals['expected_cash'];
    $countedCash  = $isClosed ? (float)$shift['counted_cash'] : null;
    $variance     = $isClosed ? (float)$shift['variance'] : null;
    $cashierName  = trim($shift['first_name'] . ' ' . $shift['last_name']);

    // Broken out explicitly, same reasoning as cashier/remittance_report.php
    // and its print document -- GCash never touches the physical drawer,
    // so Expected Cash shouldn't read as though it should equal Total Sales.
    $cashSales  = $totals['by_method']['cash']['total'] ?? 0.0;
    $gcashSales = $totals['by_method']['paymongo_gcash']['total'] ?? 0.0;

    $varianceLabel = $variance === null ? '&mdash;' : ($variance == 0 ? 'Balanced' : (($variance > 0 ? '+' : '&minus;') . '&#8369;' . number_format(abs($variance), 2)));
    $varianceMeta  = $variance === null ? 'Not yet counted' : ($variance == 0 ? 'Exact match' : ($variance > 0 ? 'Over expected' : 'Short of expected'));

    $sectionLabel = 'font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--op-ink-faint);margin-bottom:8px;';

    ob_start();
    ?>
    <div class="owner-tabs" id="shiftDetailTabs" style="margin-bottom:16px;">
        <a href="#" class="owner-tabs-link is-active" data-shift-tab="summary">Summary</a>
        <a href="#" class="owner-tabs-link" data-shift-tab="orders">Orders</a>
        <a href="#" class="owner-tabs-link" data-shift-tab="payments">Payment Breakdown</a>
        <a href="#" class="owner-tabs-link" data-shift-tab="notes">Notes</a>
    </div>

    <div data-shift-panel="summary">
        <div style="<?= $sectionLabel ?>">Sales this shift</div>
        <div class="owner-summary-card-group">
            <div>
                <div class="owner-summary-card-label">Petty cash fund</div>
                <div class="owner-summary-card-value">&#8369;<?= number_format((float)$shift['opening_cash'], 2) ?></div>
            </div>
            <div>
                <div class="owner-summary-card-label">Cash sales</div>
                <div class="owner-summary-card-value">&#8369;<?= number_format($cashSales, 2) ?></div>
            </div>
            <div>
                <div class="owner-summary-card-label">GCash sales</div>
                <div class="owner-summary-card-value">&#8369;<?= number_format($gcashSales, 2) ?></div>
            </div>
            <div>
                <div class="owner-summary-card-label">Total sales (all methods)</div>
                <div class="owner-summary-card-value">&#8369;<?= number_format($totalSales, 2) ?></div>
                <div class="owner-summary-card-meta"><?= $totals['total_orders'] ?> order<?= $totals['total_orders'] === 1 ? '' : 's' ?></div>
            </div>
        </div>

        <div style="<?= $sectionLabel ?>">Cash drawer reconciliation</div>
        <div class="owner-summary-card-group">
            <div>
                <div class="owner-summary-card-label">Expected cash</div>
                <div class="owner-summary-card-value">&#8369;<?= number_format($expectedCash, 2) ?></div>
                <div class="owner-summary-card-meta">Petty cash fund + cash sales</div>
            </div>
            <div>
                <div class="owner-summary-card-label">Cash on hand</div>
                <div class="owner-summary-card-value"><?= $countedCash === null ? '&mdash;' : '&#8369;' . number_format($countedCash, 2) ?></div>
            </div>
            <div>
                <div class="owner-summary-card-label">Variance</div>
                <div class="owner-summary-card-value"><?= $varianceLabel ?></div>
                <div class="owner-summary-card-meta"><?= $varianceMeta ?></div>
            </div>
        </div>

        <div class="owner-card" style="margin-top:16px;">
            <div class="owner-card-head"><h2 class="owner-card-title">Details</h2></div>
            <div class="owner-table-wrap">
                <table class="owner-table">
                    <tbody>
                        <tr><td>Shift number</td><td>#<?= (int)$shift['shift_id'] ?></td></tr>
                        <tr><td>Cashier</td><td><?= htmlspecialchars($cashierName) ?></td></tr>
                        <tr><td>Opened</td><td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($shift['opened_at']))) ?></td></tr>
                        <tr><td>Closed</td><td><?= $shift['closed_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($shift['closed_at']))) : '&mdash;' ?></td></tr>
                        <tr><td>Orders processed</td><td><?= $totals['total_orders'] ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div data-shift-panel="orders" style="display:none;">
        <div class="owner-table-wrap">
            <table class="owner-table">
                <thead><tr><th>Order #</th><th>Time</th><th>Type</th><th>Method</th><th class="num">Amount</th></tr></thead>
                <tbody>
                    <?php if (empty($totals['orders'])): ?>
                        <tr><td colspan="5" class="owner-table-empty">No orders recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($totals['orders'] as $o): ?>
                            <tr>
                                <td><?= htmlspecialchars($o['order_number']) ?></td>
                                <td><?= htmlspecialchars(date('g:i A', strtotime($o['created_at']))) ?></td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $o['order_type']))) ?></td>
                                <td><?= htmlspecialchars(paymentMethodLabel($o['payment_method'])) ?></td>
                                <td class="num">&#8369;<?= number_format((float)$o['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div data-shift-panel="payments" style="display:none;">
        <div class="owner-table-wrap">
            <table class="owner-table">
                <thead><tr><th>Method</th><th class="num">Orders</th><th class="num">Amount</th><th class="num">%</th></tr></thead>
                <tbody>
                    <?php if ($totals['total_orders'] === 0): ?>
                        <tr><td colspan="4" class="owner-table-empty">No transactions recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($totals['by_method'] as $method => $data): ?>
                            <?php $pct = $totalSales > 0 ? round(($data['total'] / $totalSales) * 100, 1) : 0.0; ?>
                            <tr>
                                <td><?= htmlspecialchars(paymentMethodLabel($method)) ?></td>
                                <td class="num"><?= (int)$data['count'] ?></td>
                                <td class="num">&#8369;<?= number_format($data['total'], 2) ?></td>
                                <td class="num"><?= number_format($pct, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div data-shift-panel="notes" style="display:none;">
        <div class="owner-card">
            <div class="owner-card-head"><h2 class="owner-card-title">Closing notes</h2></div>
            <?php if (!empty($shift['closing_notes'])): ?>
                <p style="margin:0;color:var(--op-ink-soft);font-size:0.88rem;"><?= nl2br(htmlspecialchars($shift['closing_notes'])) ?></p>
            <?php else: ?>
                <p style="margin:0;color:var(--op-ink-faint);font-size:0.85rem;">No notes recorded.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderSalesTabHtml(array $data, bool $withChart = true): string
{
    $s   = $data['statement'];
    $dep = $data['deposits'];

    ob_start(); ?>
    <div class="owner-form-grid">
        <?= reportKpiCard('Net sales (excl. VAT)', '&#8369;' . number_format($s['net_sales_vat_exc'], 2), 'the revenue figure for a P&amp;L', 'ph-currency-circle-dollar') ?>
        <?= reportKpiCard('Total collected', '&#8369;' . number_format($s['total_collected'], 2), 'billed incl. VAT &amp; fees', 'ph-cash-register') ?>
        <?= reportKpiCard('Orders', number_format($s['order_count']), $s['discounted_order_count'] > 0 ? number_format($s['discounted_order_count']) . ' with a discount' : 'no discounts in this period', 'ph-receipt') ?>
        <?= reportKpiCard('Avg order value', '&#8369;' . number_format($s['avg_order_value'], 2), 'per paid order', 'ph-calculator') ?>
    </div>

    <?php if (!$s['ties_out']): ?>
        <div class="owner-alert owner-alert-error" style="margin-top:14px;">
            <i class="ph ph-warning-circle" aria-hidden="true"></i>
            <span><strong>This statement does not balance.</strong> Net sales plus packaging should equal
            total collected. Treat these figures as unverified and check for orders whose line items disagree with their total.</span>
        </div>
    <?php endif; ?>

    <?php // The centrepiece: one statement that walks from what was ordered to
          // what was banked, with every reduction visible in between. Built so a
          // reader can follow the money without having to trust a single KPI. ?>
    <div class="owner-card rp-section">
        <div class="owner-card-head"><div>
            <h2 class="owner-card-title">Sales statement</h2>
        </div></div>
        <div class="owner-table-wrap">
            <table class="owner-table">
                <tbody>
                    <?= salesStatementRow('Gross sales', $s['gross_sales']) ?>
                    <?= salesStatementRow('Less: discounts', -$s['discounts']) ?>
                    <?= salesStatementRow('Net sales (incl. VAT)', $s['net_sales_vat_inc'], 'sub') ?>
                    <?= salesStatementRow('Less: output VAT', -$s['vat']) ?>
                    <?= salesStatementRow('NET SALES (excl. VAT)', $s['net_sales_vat_exc'], 'total') ?>
                    <?= salesStatementRow('Add: packaging fees', $s['packaging_fees']) ?>
                    <?= salesStatementRow('Add: VAT (returned above)', $s['vat']) ?>
                    <?= salesStatementRow('TOTAL BILLED TO CUSTOMERS', $s['total_collected'], 'total') ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="owner-form-grid" style="margin-top:0;">
        <?php // VAT summary -- VATable vs VAT-exempt is a legally meaningful split
              // in the Philippines (PWD and Senior Citizen sales are VAT-exempt by
              // law), and it is the first thing anyone reviewing a restaurant's
              // books asks for. Zero-rated is not shown: nothing in this schema can
              // classify a sale that way, and printing a permanent 0.00 would imply
              // it had been assessed. ?>
        <div class="owner-card rp-section">
            <div class="owner-card-head"><div>
                <h2 class="owner-card-title">VAT summary</h2>
            </div></div>
            <div class="owner-table-wrap">
                <table class="owner-table">
                    <tbody>
                        <?= salesStatementRow('VATable sales (net)', $s['vatable_sales']) ?>
                        <?= salesStatementRow('Output VAT (12%)', $s['vat']) ?>
                        <?= salesStatementRow('VAT-exempt sales', $s['vat_exempt_sales'], '', 'PWD / Senior Citizen') ?>
                        <?= salesStatementRow('Net sales (incl. VAT)', $s['net_sales_vat_inc'], 'total') ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php // Tender summary. Reports what physically moved, which is what gets
              // reconciled against a drawer and a GCash statement. ?>
        <div class="owner-card rp-section">
            <div class="owner-card-head"><div>
                <h2 class="owner-card-title">Payment methods</h2>
            </div></div>
            <?php if (empty($s['tender']['rows'])): ?>
                <div class="owner-table-empty">No payments in this period.</div>
            <?php else: ?>
            <div class="owner-table-wrap">
                <table class="owner-table">
                    <thead><tr><th>Method</th><th>Transactions</th><th style="text-align:right;">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($s['tender']['rows'] as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['label']) ?></td>
                            <td><?= number_format($t['txn_count']) ?></td>
                            <td class="num" style="text-align:right;">&#8369;<?= number_format($t['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="border-top:2px solid var(--op-ink);">
                            <td style="font-weight:600;">Received at the till</td>
                            <td></td>
                            <td class="num" style="text-align:right;font-weight:600;">&#8369;<?= number_format($s['tender']['total'], 2) ?></td>
                        </tr>
                        <?php if (abs($s['deposit_credited_at_pos']) > 0.005): ?>
                        <tr>
                            <td>Paid earlier by reservation deposit
                                <div style="font-size:0.72rem;color:var(--op-ink-faint);margin-top:2px;">Already banked before the visit, so the till never saw it</div>
                            </td>
                            <td></td>
                            <td class="num" style="text-align:right;">&#8369;<?= number_format($s['deposit_credited_at_pos'], 2) ?></td>
                        </tr>
                        <tr style="border-top:1px solid var(--op-line);">
                            <td style="font-weight:600;">Total billed</td>
                            <td></td>
                            <td class="num" style="text-align:right;font-weight:600;">&#8369;<?= number_format($s['total_collected'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="owner-form-grid" style="margin-top:0;">
        <?php // Discounts given, by type. ?>
        <div class="owner-card rp-section">
            <div class="owner-card-head"><div>
                <h2 class="owner-card-title">Discounts</h2>
            </div></div>
            <?php if (empty($s['discounts_breakdown'])): ?>
                <div class="owner-table-empty">No discounts were given in this period.</div>
            <?php else: ?>
            <div class="owner-table-wrap">
                <table class="owner-table">
                    <thead><tr><th>Discount</th><th>Orders</th><th style="text-align:right;">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($s['discounts_breakdown'] as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['discount_name']) ?>
                                <?php if ($d['is_vat_exempt']): ?><span class="owner-status-pill is-info" style="margin-left:6px;">VAT-exempt</span><?php endif; ?>
                            </td>
                            <td><?= number_format($d['order_count']) ?></td>
                            <td class="num" style="text-align:right;">&#8369;<?= number_format($d['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="border-top:2px solid var(--op-ink);">
                            <td style="font-weight:600;">Total</td>
                            <td style="font-weight:600;"><?= number_format($s['discounted_order_count']) ?></td>
                            <td class="num" style="text-align:right;font-weight:600;">&#8369;<?= number_format($s['discounts'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php // Reservation deposits. Three fates, kept strictly apart -- conflating
              // them is how a restaurant either double-counts revenue or forgets it
              // owes someone a meal. ?>
        <div class="owner-card rp-section">
            <div class="owner-card-head"><div>
                <h2 class="owner-card-title">Reservation deposits</h2>
            </div></div>
            <div class="owner-table-wrap">
                <table class="owner-table">
                    <tbody>
                        <?= salesStatementRow('Redeemed against a bill', $dep['redeemed'], '', $dep['redeemed_count'] . ' reservation(s) — already counted inside sales above, not added again') ?>
                        <?= salesStatementRow('Forfeited (no-show / cancelled)', $dep['forfeited'], '', $dep['forfeited_count'] . ' reservation(s) — non-refundable per the booking terms, so this is income') ?>
                        <?= salesStatementRow('Still held', $dep['held'], '', $dep['held_count'] . ' upcoming reservation(s) — a liability, not revenue') ?>
                        <?= salesStatementRow('Total deposits collected', $dep['collected'], 'total') ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:12px 16px;border-top:1px solid var(--op-line);display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
                <span style="font-weight:600;">Total income (net sales + forfeited deposits)</span>
                <span style="font-weight:700;font-size:1.05rem;white-space:nowrap;">&#8369;<?= number_format($data['total_income'], 2) ?></span>
            </div>
        </div>
    </div>
    <?php
    // The Sales trend chart and Top Selling Items table used to sit here and
    // were removed on request. Both already have a better home on
    // owner/analytics.php (a real trend chart with period comparison, and a
    // top-items table with growth metrics), so repeating them here only made
    // this tab longer without telling the reader anything new. What is left is
    // what only this tab does: reconcile the money.
    //
    // $withChart is kept in the signature because renderReportPrintBody() and
    // report.php both still pass it for the other tabs' renderers to stay
    // uniform; nothing on this tab draws a chart any more.
    return ob_get_clean();
}

function renderInventoryTabHtml(array $data): string
{
    ob_start(); ?>
    <?php /* No KPI card row -- same call the print doc already made (see
             renderReportPrintDocumentHtml()'s 'inventory' case): every one of
             those 4 numbers is just a column total already sitting in the
             Stock reconciliation table below, so the cards only ever
             repeated it. */ ?>
    <div class="owner-card rp-section">
        <div class="owner-card-head"><div>
            <h2 class="owner-card-title">Stock reconciliation</h2>
        </div></div>
    <?php if (empty($data['reconciliation'])): ?>
        <div class="owner-table-empty">No active inventory items yet.</div>
    <?php else: ?>
    <?php
        // NB: don't write ($row['opening'] ?? 0) === null here -- ?? coalesces
        // on null, which is the very value being tested, so it can never be true.
        $firstRecon    = $data['reconciliation'][0];
        $balanceUnknown = $firstRecon['opening'] === null || $firstRecon['closing'] === null;
    ?>
    <div class="owner-table-wrap">
        <table class="owner-table">
            <thead><tr><th>Item</th><th>Category</th><th>Opening</th><th>Stock In</th><th>Used</th><th>Waste</th><th>Adjustments</th><th>Closing</th></tr></thead>
            <tbody>
            <?php foreach ($data['reconciliation'] as $item): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                    <td><?= $item['category_name'] !== null ? htmlspecialchars($item['category_name']) : '&mdash;' ?></td>
                    <td><?= reconQtyCell($item['opening'], $item['unit_code']) ?></td>
                    <td><?= reconQtyCell($item['stock_in'], $item['unit_code']) ?></td>
                    <td><?= reconQtyCell($item['used'], $item['unit_code']) ?></td>
                    <td><?= reconQtyCell($item['waste'], $item['unit_code']) ?></td>
                    <td><?= reconQtyCell($item['adjustments'], $item['unit_code'], true) ?></td>
                    <td><strong><?= reconQtyCell($item['closing'], $item['unit_code']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($balanceUnknown && !empty($data['balance_known_from'])): ?>
        <p class="owner-form-hint" style="margin-top:10px;">
            Opening and closing show <strong>n/a</strong> for periods reaching back before
            <?= htmlspecialchars(date('M j, Y', strtotime($data['balance_known_from']))) ?>,
            when per-transaction stock logging began &mdash; a running balance across that point
            would be guesswork. Stock In, Used, Waste and Adjustments are exact for every period.
        </p>
    <?php endif; ?>
    <?php endif; ?>
    </div>
    <?php
    // The per-transaction "Recent stock movement" list was removed from this
    // report: it duplicated inventory/inventory_transactions.php, which owns
    // that view and has real filtering, pagination and CSV export. This tab
    // stays at the summary/reconciliation level.
    return ob_get_clean();
}

function renderReservationTabHtml(array $data): string
{
    ob_start(); ?>
    <div class="owner-form-grid">
        <?= reportKpiCard('Total bookings', (string)$data['total_bookings'], '', 'ph-calendar-check') ?>
        <?= reportKpiCard('Completed', (string)$data['completed'], '', 'ph-check-circle') ?>
        <?= reportKpiCard('No-show rate', $data['no_show_rate'] !== null ? number_format($data['no_show_rate'], 1) . '%' : '&mdash;', $data['no_show_rate'] !== null ? $data['no_show'] . ' no-show(s)' : 'No completed/no-show reservations yet', 'ph-user-circle-minus') ?>
        <?= reportKpiCard('Advance order revenue', '&#8369;' . number_format($data['advance_order_revenue'], 2), '', 'ph-fork-knife') ?>
    </div>
    <div class="owner-card rp-section">
        <div class="owner-card-head"><div>
            <h2 class="owner-card-title">Reservations</h2>
        </div></div>
    <?php if (empty($data['reservations'])): ?>
        <div class="owner-table-empty">No reservations in this period.</div>
    <?php else: ?>
    <div class="owner-table-wrap">
        <table class="owner-table">
            <thead><tr><th>Reservation #</th><th>Customer</th><th>Date</th><th>Time</th><th>Guests</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($data['reservations'] as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['reservation_number']) ?></td>
                    <td><?= htmlspecialchars($r['customer_name'] ?? 'Walk-in') ?></td>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($r['reservation_date']))) ?></td>
                    <td><?= htmlspecialchars($r['slot_label']) ?></td>
                    <td><?= (int)$r['number_of_guests'] ?></td>
                    <td><span class="owner-status-pill"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------------------
// Professional print / PDF document -- a completely self-contained
// template (own @page/font/table styling, no dependency on
// owner-panel.css) matching the same "doc" style already established by
// renderPurchaseOrderDocumentHtml() / renderPayslipCardHtml().
// Deliberately does NOT reuse
// owner-panel.css: that stylesheet's @media print rule (`body *{
// visibility:hidden }`, scoped to printing an open .owner-modal-backdrop)
// blanks out any page that isn't a modal -- which is exactly what made
// the plain print view render empty. This template is immune to that
// because it never loads owner-panel.css at all, and reads better on
// paper besides (bordered tables, a real letterhead, no dashboard chrome).
// ---------------------------------------------------------------------

/** Label/value pairs rendered as a compact 2-column summary table. */
function renderPrintKpiTable(array $pairs): string
{
    $rows = '';
    foreach ($pairs as $label => $value) {
        $rows .= '<tr><td>' . htmlspecialchars($label) . '</td><td class="num"><strong>' . $value . '</strong></td></tr>';
    }
    return '<table class="doc-table doc-kpi-table">' . $rows . '</table>';
}

function renderReportPrintBody(string $tab, array $data): string
{
    switch ($tab) {
        case 'sales':
            $s   = $data['statement'];
            $dep = $data['deposits'];

            $html = renderPrintKpiTable([
                'Net sales (excl. VAT)' => '&#8369;' . number_format($s['net_sales_vat_exc'], 2),
                'Total billed'          => '&#8369;' . number_format($s['total_collected'], 2),
                'Orders'                => number_format($s['order_count']),
                'Avg order value'       => '&#8369;' . number_format($s['avg_order_value'], 2),
            ]);

            // The printed copy carries the whole statement, not just the KPIs.
            // A printout that shows only headline figures is exactly the kind of
            // document that invites "where did that number come from?".
            //
            // Layout: the statement runs full width (it is the document's spine
            // and reads top-to-bottom as a waterfall), then VAT Summary and
            // Payment Methods sit side by side beneath it -- both are short and
            // narrow, so stacking them wasted half a page each.
            $html .= '<h2>Sales Statement</h2>';
            $html .= '<table class="doc-table">'
                . '<tr><td>Gross sales</td><td class="num">&#8369;' . number_format($s['gross_sales'], 2) . '</td></tr>'
                . '<tr><td>Less: discounts</td><td class="num">(&#8369;' . number_format($s['discounts'], 2) . ')</td></tr>'
                . '<tr><td><strong>Net sales (incl. VAT)</strong></td><td class="num"><strong>&#8369;' . number_format($s['net_sales_vat_inc'], 2) . '</strong></td></tr>'
                . '<tr><td>Less: output VAT</td><td class="num">(&#8369;' . number_format($s['vat'], 2) . ')</td></tr>'
                . '<tr><td><strong>NET SALES (excl. VAT)</strong></td><td class="num"><strong>&#8369;' . number_format($s['net_sales_vat_exc'], 2) . '</strong></td></tr>'
                . '<tr><td>Add: packaging fees</td><td class="num">&#8369;' . number_format($s['packaging_fees'], 2) . '</td></tr>'
                . '<tr><td>Add: VAT</td><td class="num">&#8369;' . number_format($s['vat'], 2) . '</td></tr>'
                . '<tr><td><strong>TOTAL BILLED</strong></td><td class="num"><strong>&#8369;' . number_format($s['total_collected'], 2) . '</strong></td></tr>'
                . '</table>';

            // Both column headings get margin-top:0 inline so the two columns
            // start on the same baseline. Inline rather than a :first-child rule
            // because Dompdf's support for that selector is unreliable.
            $vatCol = '<h2 style="margin-top:0;">VAT Summary</h2>';
            $vatCol .= '<table class="doc-table">'
                . '<tr><td>VATable sales (net)</td><td class="num">&#8369;' . number_format($s['vatable_sales'], 2) . '</td></tr>'
                . '<tr><td>Output VAT (12%)</td><td class="num">&#8369;' . number_format($s['vat'], 2) . '</td></tr>'
                . '<tr><td>VAT-exempt sales (PWD / Senior)</td><td class="num">&#8369;' . number_format($s['vat_exempt_sales'], 2) . '</td></tr>'
                . '</table>';

            $payCol = '<h2 style="margin-top:0;">Payment Methods</h2>';
            if (empty($s['tender']['rows'])) {
                $payCol .= '<div class="empty-note">No payments in this period.</div>';
            } else {
                // "Txns" rather than "Transactions": this table sits in a
                // half-width column, and the longer word wrapped its header onto
                // two lines and squeezed the amount column.
                $payCol .= '<table class="doc-table"><thead><tr><th>Method</th><th class="num">Txns</th><th class="num">Amount</th><th class="num">Share</th></tr></thead><tbody>';
                foreach ($s['tender']['rows'] as $t) {
                    $payCol .= '<tr><td>' . htmlspecialchars($t['label']) . '</td><td class="num">' . number_format($t['txn_count'])
                        . '</td><td class="num">&#8369;' . number_format($t['amount'], 2) . '</td><td class="num">' . number_format($t['pct'], 1) . '%</td></tr>';
                }
                $payCol .= '<tr><td><strong>Received at the till</strong></td><td class="num"></td><td class="num"><strong>&#8369;'
                    . number_format($s['tender']['total'], 2) . '</strong></td><td class="num"></td></tr>';
                if (abs($s['deposit_credited_at_pos']) > 0.005) {
                    $payCol .= '<tr><td>Paid earlier by reservation deposit</td><td class="num"></td><td class="num">&#8369;'
                        . number_format($s['deposit_credited_at_pos'], 2) . '</td><td class="num"></td></tr>';
                }
                $payCol .= '</tbody></table>';
            }

            $html .= '<table class="doc-cols"><tr>'
                . '<td class="doc-col-left">' . $vatCol . '</td>'
                . '<td class="doc-col-right">' . $payCol . '</td>'
                . '</tr></table>';

            $html .= '<h2>Discounts</h2>';
            if (empty($s['discounts_breakdown'])) {
                $html .= '<div class="empty-note">No discounts were given in this period.</div>';
            } else {
                $html .= '<table class="doc-table"><thead><tr><th>Discount</th><th class="num">Orders</th><th class="num">Amount</th></tr></thead><tbody>';
                foreach ($s['discounts_breakdown'] as $d) {
                    $html .= '<tr><td>' . htmlspecialchars($d['discount_name']) . ($d['is_vat_exempt'] ? ' (VAT-exempt)' : '')
                        . '</td><td class="num">' . number_format($d['order_count']) . '</td><td class="num">&#8369;' . number_format($d['amount'], 2) . '</td></tr>';
                }
                $html .= '<tr><td><strong>Total</strong></td><td class="num"><strong>' . number_format($s['discounted_order_count'])
                    . '</strong></td><td class="num"><strong>&#8369;' . number_format($s['discounts'], 2) . '</strong></td></tr></tbody></table>';
            }

            $html .= '<h2>Reservation Deposits</h2>';
            $html .= '<table class="doc-table">'
                . '<tr><td>Redeemed against a bill (already in sales above)</td><td class="num">&#8369;' . number_format($dep['redeemed'], 2) . '</td></tr>'
                . '<tr><td>Forfeited &mdash; no-show / cancelled (income)</td><td class="num">&#8369;' . number_format($dep['forfeited'], 2) . '</td></tr>'
                . '<tr><td>Still held (liability, not revenue)</td><td class="num">&#8369;' . number_format($dep['held'], 2) . '</td></tr>'
                . '<tr><td><strong>Total income (net sales + forfeited deposits)</strong></td><td class="num"><strong>&#8369;'
                . number_format($data['total_income'], 2) . '</strong></td></tr>'
                . '</table>';

            // Top Selling Items was removed from this tab (and from its printed
            // copy) on request -- owner/analytics.php already ranks items, with
            // growth metrics this report never had. The printed Sales report is
            // now purely the financial statement.
            return $html;

        case 'cashier':
            $html = renderPrintKpiTable([
                'Total sales'          => '&#8369;' . number_format($data['total_sales'], 2),
                'Cash sales'           => '&#8369;' . number_format($data['cash_sales'], 2),
                'GCash sales'          => '&#8369;' . number_format($data['gcash_sales'], 2),
                'Shifts'               => $data['shift_count'] . ' (' . $data['closed_count'] . ' closed, ' . $data['open_count'] . ' open)',
                'Cash on hand counted' => '&#8369;' . number_format($data['cash_counted'], 2)
                                           . ' (incl. &#8369;' . number_format($data['total_float'], 2) . ' petty cash fund)',
                'Shifts not balancing' => $data['unbalanced_count'] . ' of ' . $data['closed_count'],
                'Variance'             => ($data['total_variance'] >= 0 ? '+' : '&minus;') . '&#8369;' . number_format(abs($data['total_variance']), 2),
            ]);
            $html .= '<h2>Shift History</h2>';
            if (empty($data['shifts'])) {
                $html .= '<div class="empty-note">No cashier shifts in this period.</div>';
            } else {
                $html .= '<table class="doc-table"><thead><tr><th>Cashier</th><th>Opened</th><th>Closed</th><th class="num">Petty Cash Fund</th><th class="num">Cash Sales</th><th class="num">GCash Sales</th><th class="num">Total Sales</th><th class="num">Expected Cash</th><th class="num">Cash on Hand</th><th class="num">Variance</th></tr></thead><tbody>';
                foreach ($data['shifts'] as $s) {
                    $variance = $s['variance'] === null ? null : (float)$s['variance'];
                    $varianceText = $variance === null
                        ? '&mdash;'
                        : ($variance == 0
                            ? '&#8369;0.00 Balanced'
                            : ($variance > 0
                                ? '+&#8369;' . number_format($variance, 2) . ' Over'
                                : '&minus;&#8369;' . number_format(abs($variance), 2) . ' Short'));
                    $html .= '<tr><td>' . htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) . '</td><td>' . htmlspecialchars(date('M j, g:i A', strtotime($s['opened_at']))) . '</td><td>' . ($s['closed_at'] ? htmlspecialchars(date('M j, g:i A', strtotime($s['closed_at']))) : 'Still open') . '</td><td class="num">&#8369;' . number_format((float)$s['opening_cash'], 2) . '</td><td class="num">&#8369;' . number_format((float)($s['cash_sales'] ?? 0), 2) . '</td><td class="num">&#8369;' . number_format((float)($s['gcash_sales'] ?? 0), 2) . '</td><td class="num">&#8369;' . number_format((float)$s['total_sales'], 2) . '</td><td class="num">' . ($s['expected_cash'] !== null ? '&#8369;' . number_format((float)$s['expected_cash'], 2) : '&mdash;') . '</td><td class="num">' . ($s['counted_cash'] !== null ? '&#8369;' . number_format((float)$s['counted_cash'], 2) : '&mdash;') . '</td><td class="num">' . $varianceText . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            return $html;

        case 'inventory':
            // No KPI summary table here -- unlike the other 4 tabs, every one of
            // these 4 numbers is just a sum of a column already in the
            // reconciliation table below, so it only ever repeated it.
            $html = '<h2>Stock Reconciliation</h2>';
            if (empty($data['reconciliation'])) {
                $html .= '<div class="empty-note">No active inventory items yet.</div>';
            } else {
                $html .= '<table class="doc-table"><thead><tr><th>Item</th><th>Category</th><th class="num">Opening</th><th class="num">Stock In</th><th class="num">Used</th><th class="num">Waste</th><th class="num">Adjustments</th><th class="num">Closing</th></tr></thead><tbody>';
                foreach ($data['reconciliation'] as $item) {
                    $cell = fn(?float $v, bool $signed = false) => reconQtyCell($v, $item['unit_code'], $signed);
                    $html .= '<tr><td>' . htmlspecialchars($item['item_name']) . '</td><td>' . ($item['category_name'] !== null ? htmlspecialchars($item['category_name']) : '&mdash;') . '</td><td class="num">' . $cell($item['opening']) . '</td><td class="num">' . $cell($item['stock_in']) . '</td><td class="num">' . $cell($item['used']) . '</td><td class="num">' . $cell($item['waste']) . '</td><td class="num">' . $cell($item['adjustments'], true) . '</td><td class="num"><strong>' . $cell($item['closing']) . '</strong></td></tr>';
                }
                $html .= '</tbody></table>';
                $firstRecon = $data['reconciliation'][0];
                if (!empty($data['balance_known_from'])
                    && ($firstRecon['opening'] === null || $firstRecon['closing'] === null)) {
                    $html .= '<div class="empty-note">Opening and closing show n/a for periods reaching back before '
                        . htmlspecialchars(date('M j, Y', strtotime($data['balance_known_from'])))
                        . ', when per-transaction stock logging began. Stock In, Used, Waste and Adjustments are exact for every period.</div>';
                }
            }
            return $html;

        case 'reservation':
            $html = renderPrintKpiTable([
                'Total bookings'         => (string)$data['total_bookings'],
                'Completed'              => (string)$data['completed'],
                'No-show rate'           => $data['no_show_rate'] !== null ? number_format($data['no_show_rate'], 1) . '%' : '&mdash;',
                'Advance order revenue'  => '&#8369;' . number_format($data['advance_order_revenue'], 2),
            ]);
            $html .= '<h2>Reservations</h2>';
            if (empty($data['reservations'])) {
                $html .= '<div class="empty-note">No reservations in this period.</div>';
            } else {
                $html .= '<table class="doc-table"><thead><tr><th>Reservation #</th><th>Customer</th><th>Date</th><th>Time</th><th class="num">Guests</th><th>Status</th></tr></thead><tbody>';
                foreach ($data['reservations'] as $r) {
                    $html .= '<tr><td>' . htmlspecialchars($r['reservation_number']) . '</td><td>' . htmlspecialchars($r['customer_name'] ?? 'Walk-in') . '</td><td>' . htmlspecialchars(date('M j, Y', strtotime($r['reservation_date']))) . '</td><td>' . htmlspecialchars($r['slot_label']) . '</td><td class="num">' . (int)$r['number_of_guests'] . '</td><td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $r['status']))) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            return $html;

        case 'payroll':
            $peso = '&#8369;';
            $html = renderPrintKpiTable([
                'Payroll runs'       => (string)$data['run_count'],
                'Total gross pay'    => $peso . number_format($data['total_gross_pay'], 2),
                'Total net pay'      => $peso . number_format($data['total_net_pay'], 2),
                'Total payroll cost' => $peso . number_format($data['total_payroll_cost'] ?? $data['total_gross_pay'], 2),
            ]);

            $html .= '<h2>Payroll Runs</h2>';
            if (empty($data['runs'])) {
                $html .= '<div class="empty-note">No payroll runs paid out in this period.</div>';
                return $html;
            }

            // Same columns the on-screen table carries, so the printout is the
            // document behind that view rather than a reduced version of it.
            $html .= '<table class="doc-table"><thead><tr><th>Run #</th><th>Cutoff Period</th><th>Payout Date</th><th>Status</th><th class="num">Employees</th><th class="num">Gross Pay</th><th class="num">Deductions</th><th class="num">Employer Share</th><th class="num">Net Pay</th></tr></thead><tbody>';
            foreach ($data['runs'] as $r) {
                $rid     = (int)$r['payroll_run_id'];
                $erRows  = $data['statutory'][$rid] ?? null;
                $erShare = $erRows === null ? null : array_sum(array_map(fn($d) => (float)$d['employer_amount'], $erRows));
                $html .= '<tr><td>' . htmlspecialchars($r['run_number']) . '</td><td>'
                    . htmlspecialchars(date('M j', strtotime($r['cutoff_period_start']))) . ' &ndash; ' . htmlspecialchars(date('M j, Y', strtotime($r['cutoff_period_end']))) . '</td><td>'
                    . htmlspecialchars(date('M j, Y', strtotime($r['payout_date']))) . '</td><td>'
                    . htmlspecialchars(ucfirst(str_replace('_', ' ', $r['status']))) . '</td><td class="num">'
                    . (int)$r['total_employees'] . '</td><td class="num">' . $peso . number_format($r['total_gross_pay'], 2)
                    . '</td><td class="num">' . $peso . number_format($r['total_deductions'], 2)
                    . '</td><td class="num">' . ($erShare === null ? '&mdash;' : $peso . number_format($erShare, 2))
                    . '</td><td class="num">' . $peso . number_format($r['total_net_pay'], 2) . '</td></tr>';
            }
            $html .= '</tbody></table>';

            /* Then the actual summary report for each payable run -- the same
               statement the "Summary report" button shows on screen, rendered in
               doc-table markup because Dompdf has no CSS variables and none of
               the panel classes exist in the print stylesheet. Printing the tab
               therefore produces every run's summary, not just the index. */
            foreach (($data['payable_runs'] ?? []) as $run) {
                $rid  = (int)$run['payroll_run_id'];
                $comp = $data['composition'][$rid] ?? null;
                $deds = $data['statutory'][$rid] ?? [];

                $govt  = array_values(array_filter($deds, fn($d) => $d['category'] === 'government_mandatory'));
                $other = array_values(array_filter($deds, fn($d) => $d['category'] !== 'government_mandatory'));

                $govtEmployee = array_sum(array_map(fn($d) => (float)$d['employee_amount'], $govt));
                $govtEmployer = array_sum(array_map(fn($d) => (float)$d['employer_amount'], $govt));
                $otherTotal   = array_sum(array_map(fn($d) => (float)$d['employee_amount'], $other));
                $lateAbs      = $comp ? ((float)$comp['late_deduction'] + (float)$comp['undertime_deduction'] + (float)$comp['absence_deduction']) : 0.0;
                $residual     = round((float)$run['total_deductions'] - $govtEmployee - $otherTotal - $lateAbs, 2);

                $row = fn(string $label, float $amt, bool $bold = false) =>
                    '<tr><td' . ($bold ? ' style="font-weight:700;"' : '') . '>' . htmlspecialchars($label) . '</td>'
                    . '<td class="num"' . ($bold ? ' style="font-weight:700;"' : '') . '>'
                    . ($amt < 0 ? '(' . $peso . number_format(abs($amt), 2) . ')' : $peso . number_format($amt, 2))
                    . '</td></tr>';

                $html .= '<h2>Summary Report &mdash; ' . htmlspecialchars($run['run_number']) . '</h2>';
                $html .= '<div class="empty-note" style="margin-bottom:6px;">'
                    . htmlspecialchars(date('M j', strtotime($run['cutoff_period_start']))) . ' &ndash; '
                    . htmlspecialchars(date('M j, Y', strtotime($run['cutoff_period_end'])))
                    . ' &middot; paid ' . htmlspecialchars(date('M j, Y', strtotime($run['payout_date'])))
                    . ' &middot; ' . (int)$run['total_employees'] . ' employee(s)</div>';

                $html .= '<table class="doc-table"><tbody>';
                if ($comp !== null) {
                    $html .= $row('Basic pay', (float)$comp['basic_pay']);
                    foreach ([
                        'Overtime pay'       => 'overtime_pay',
                        'Holiday pay'        => 'holiday_pay',
                        'Rest day pay'       => 'rest_day_pay',
                        'Night differential' => 'night_differential_pay',
                        'Leave pay'          => 'leave_pay',
                    ] as $label => $col) {
                        if ((float)$comp[$col] > 0) { $html .= $row($label, (float)$comp[$col]); }
                    }
                }
                $html .= $row('GROSS PAY', (float)$run['total_gross_pay'], true);
                foreach ($govt as $d)  { $html .= $row('Less: ' . $d['name'], -(float)$d['employee_amount']); }
                foreach ($other as $d) { $html .= $row('Less: ' . $d['name'], -(float)$d['employee_amount']); }
                if ($comp !== null && (float)$comp['late_deduction'] > 0)      { $html .= $row('Less: late deduction', -(float)$comp['late_deduction']); }
                if ($comp !== null && (float)$comp['undertime_deduction'] > 0) { $html .= $row('Less: undertime deduction', -(float)$comp['undertime_deduction']); }
                if ($comp !== null && (float)$comp['absence_deduction'] > 0)   { $html .= $row('Less: absences', -(float)$comp['absence_deduction']); }
                if (abs($residual) >= 0.01)                                   { $html .= $row('Less: withholding tax and other', -$residual); }
                $html .= $row('NET PAY', (float)$run['total_net_pay'], true);
                if ($govtEmployer > 0) {
                    $html .= $row('Add: employer share', $govtEmployer);
                    $html .= $row('TOTAL COST TO THE BUSINESS', (float)$run['total_gross_pay'] + $govtEmployer, true);
                }
                $html .= '</tbody></table>';

                if ($govt) {
                    $html .= '<table class="doc-table"><thead><tr><th>Government remittance</th><th class="num">Employee share</th><th class="num">Employer share</th><th class="num">Total remittable</th></tr></thead><tbody>';
                    foreach ($govt as $d) {
                        $html .= '<tr><td>' . htmlspecialchars($d['name']) . '</td><td class="num">' . $peso . number_format((float)$d['employee_amount'], 2)
                            . '</td><td class="num">' . $peso . number_format((float)$d['employer_amount'], 2)
                            . '</td><td class="num">' . $peso . number_format((float)$d['employee_amount'] + (float)$d['employer_amount'], 2) . '</td></tr>';
                    }
                    $html .= '<tr><td style="font-weight:700;">Total</td><td class="num" style="font-weight:700;">' . $peso . number_format($govtEmployee, 2)
                        . '</td><td class="num" style="font-weight:700;">' . $peso . number_format($govtEmployer, 2)
                        . '</td><td class="num" style="font-weight:700;">' . $peso . number_format($govtEmployee + $govtEmployer, 2) . '</td></tr>';
                    $html .= '</tbody></table>';
                }
            }
            return $html;

        default:
            return '';
    }
}

/**
 * The shared print/PDF template. $includeActions=true (in-browser
 * preview) shows a Print/Download/Back bar and a canvas background,
 * matching purchase_order_pdf.php's exact convention; false (Dompdf
 * pass) renders the bare printable document only.
 */
function renderReportPrintDocumentHtml(string $tab, string $tabLabel, array $data, string $restaurantName, string $periodLabel, bool $includeActions, string $printUrl = '', string $downloadUrl = '', string $backUrl = ''): string
{
    ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<?php if ($includeActions): ?><meta name="viewport" content="width=device-width, initial-scale=1.0"><?php endif; ?>
<title><?= htmlspecialchars($tabLabel) ?> Report | <?= htmlspecialchars($restaurantName) ?></title>
<style>
    @page{ size:A4; margin:15mm; }
    body{ font-family:"DejaVu Sans", Helvetica, Arial, sans-serif; color:#000; font-size:12px; margin:0; padding:0; background:<?= $includeActions ? '#f6f3ec' : '#fff' ?>; }
    <?php // The 32px inset is only for the on-screen preview, where .doc is a
          // "sheet of paper" floating on a canvas background. In the generated
          // PDF the @page rule above already provides a 15mm margin, so keeping
          // the padding doubled it -- costing ~64pt of vertical space and
          // pushing a single orphaned table row plus the footer onto a second
          // sheet even on a two-order report. ?>
    .doc{ max-width:820px; margin:0 auto; padding:<?= $includeActions ? '32px' : '0' ?>; background:#fff; <?= $includeActions ? 'border:1px solid #ddd; margin-top:24px; margin-bottom:24px;' : '' ?> }
    .doc-header{ width:100%; border-collapse:collapse; margin-bottom:14px; }
    .doc-header td{ vertical-align:top; padding:0; }
    h1{ font-size:20px; margin:0 0 4px; }
    h2{ font-size:14px; margin:22px 0 8px; border-bottom:1px solid #000; padding-bottom:4px; }
    .muted{ color:#555; }
    .divider{ border-top:1px solid #000; margin:14px 0; }
    table.doc-table{ width:100%; border-collapse:collapse; margin-top:6px; }
    table.doc-table th, table.doc-table td{ border:1px solid #000; padding:6px 8px; font-size:11px; text-align:left; }
    table.doc-table th{ background:#eee; }
    table.doc-table td.num, table.doc-table th.num{ text-align:right; }
    table.doc-kpi-table{ width:auto; min-width:340px; }
    table.doc-kpi-table td{ font-size:12px; }
    /* Two-column print layout. A nested TABLE rather than flexbox or grid:
       Dompdf (v3.1) implements neither, and a table is the one construct it
       lays out identically in the browser preview and the generated PDF.
       The outer cells carry no border of their own -- the bordered rule above
       is scoped to table.doc-table, which these only ever contain. */
    /* The 22px top margin lives on the CONTAINER, not on the two headings
       inside it. Those keep margin-top:0 so they stay on a common baseline --
       giving them their own top margin instead would work too, but any later
       divergence between the two would knock the columns out of alignment.
       22px matches the standard h2 spacing used everywhere else in this doc. */
    table.doc-cols{ width:100%; border-collapse:collapse; margin:22px 0 0; }
    /* The gutter padding lives ONLY on the two column classes. An earlier
       `table.doc-cols > tbody > tr > td{ padding:0 }` reset silently won over
       `td.doc-col-left{ padding-right:9px }` -- three element selectors plus a
       class outweigh one element plus a class, regardless of source order --
       so the two tables rendered flush against each other with no gap at all.
       Widths are uneven on purpose: the left column holds a 2-column table and
       the right holds 4, so an even split cramped the right and stretched the
       left. */
    td.doc-col-left, td.doc-col-right{ vertical-align:top; border:none; }
    td.doc-col-left{ width:42%; padding:0 10px 0 0; }
    td.doc-col-right{ width:58%; padding:0 0 0 10px; }
    .empty-note{ font-size:11px; color:#555; font-style:italic; margin:6px 0; }
    .footer{ margin-top:24px; font-size:10px; color:#555; }
    .status-badge{ display:inline-block; border:1px solid #000; padding:2px 10px; font-size:11px; font-weight:bold; }
    <?php if ($includeActions): ?>
    .pdf-actions{ max-width:820px; margin:14px auto 24px; display:flex; justify-content:center; gap:10px; }
    .pdf-actions button, .pdf-actions a{ display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:8px; border:1px solid #ddd; background:#fff; color:#000; font-family:sans-serif; font-size:0.85rem; font-weight:600; cursor:pointer; text-decoration:none; }
    .pdf-actions .primary{ background:#9c7734; border-color:#9c7734; color:#fff; }
    @media print{ .pdf-actions{ display:none !important; } body{ background:#fff; } .doc{ border:none; margin-top:0; } }
    <?php endif; ?>
</style>
</head>
<body>
<div class="doc">
    <table class="doc-header">
        <tr>
            <td>
                <h1><?= htmlspecialchars($restaurantName) ?></h1>
                <div class="muted"><?= htmlspecialchars($tabLabel) ?> Report</div>
            </td>
            <td style="text-align:right;">
                <div><strong><?= htmlspecialchars($periodLabel) ?></strong></div>
                <div class="muted" style="margin-top:4px;">Printed <?= htmlspecialchars(date('M j, Y g:i A')) ?></div>
            </td>
        </tr>
    </table>
    <div class="divider"></div>

    <?= renderReportPrintBody($tab, $data) ?>

    <div class="footer">Generated <?= htmlspecialchars(date('M j, Y g:i A')) ?> &mdash; <?= htmlspecialchars($restaurantName) ?></div>
</div>

<?php if ($includeActions): ?>
<div class="pdf-actions">
    <button type="button" class="primary" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>
    <?php if ($downloadUrl !== ''): ?><a href="<?= htmlspecialchars($downloadUrl) ?>"><i class="ph ph-file-pdf" aria-hidden="true"></i> Download PDF</a><?php endif; ?>
    <?php if ($backUrl !== ''): ?><a href="<?= htmlspecialchars($backUrl) ?>"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back</a><?php endif; ?>
</div>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<?php endif; ?>

</body>
</html>
    <?php
    return ob_get_clean();
}

function renderPayrollTabHtml(array $data): string
{
    ob_start(); ?>
    <?php
        // The tiles count approved/released runs only; the table below lists
        // everything. Stating that on the tiles themselves is what stops the two
        // looking like they disagree.
        $excluded = (int)($data['excluded_count'] ?? 0);
        $countMeta = $excluded > 0
            ? $excluded . ' not counted (draft or cancelled)'
            : '';
    ?>
    <div class="owner-form-grid">
        <?= reportKpiCard('Payroll runs paid', (string)($data['payable_count'] ?? $data['run_count']), $countMeta, 'ph-files') ?>
        <?= reportKpiCard('Total gross pay', '&#8369;' . number_format($data['total_gross_pay'], 2), $data['total_employees_paid'] . ' employee-payout(s)', 'ph-currency-circle-dollar') ?>
        <?= reportKpiCard('Total net pay', '&#8369;' . number_format($data['total_net_pay'], 2), 'what employees received', 'ph-hand-coins') ?>
        <?php // Gross plus employer share -- the money that actually leaves the
              // business. Neither gross nor net says this: the employer half of
              // SSS/PhilHealth/Pag-IBIG is paid on top of gross and appears in
              // neither figure, so every previous tile understated the real cost. ?>
        <?= reportKpiCard(
                'Total payroll cost',
                '&#8369;' . number_format($data['total_payroll_cost'] ?? $data['total_gross_pay'], 2),
                ($data['employer_share'] ?? 0) > 0
                    ? 'incl. &#8369;' . number_format($data['employer_share'], 2) . ' employer share'
                    : 'gross &plus; employer share',
                'ph-buildings'
            ) ?>
    </div>
    <div class="owner-card rp-section">
        <div class="owner-card-head"><div>
            <h2 class="owner-card-title">Payroll runs</h2>
        </div></div>
    <?php if (empty($data['runs'])): ?>
        <div class="owner-table-empty">No payroll runs paid out in this period.</div>
    <?php else: ?>
    <div class="owner-table-wrap">
        <table class="owner-table">
            <?php // The columns ARE the summary report's own figures, so the table
                  // answers the common questions without opening anything: what it
                  // cost, what was withheld, what the employer owes on top, and what
                  // staff received. The button opens the full statement for one run. ?>
            <thead><tr>
                <th>Run #</th><th>Cutoff Period</th><th>Payout Date</th><th>Status</th>
                <th style="text-align:right;">Employees</th>
                <th style="text-align:right;">Gross Pay</th>
                <th style="text-align:right;">Deductions</th>
                <th style="text-align:right;">Employer Share</th>
                <th style="text-align:right;">Net Pay</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($data['runs'] as $r): ?>
                <?php
                    $counted = in_array($r['status'], PAYROLL_PAYABLE_STATUSES, true);
                    $rid     = (int)$r['payroll_run_id'];
                    // Employer share only exists for runs we actually detailed --
                    // draft/cancelled runs are not summed anywhere, so an em dash
                    // is honest here where a 0.00 would read as "nothing owed".
                    $erShare = null;
                    if ($counted && isset($data['statutory'][$rid])) {
                        $erShare = array_sum(array_map(fn($d) => (float)$d['employer_amount'], $data['statutory'][$rid]));
                    }
                ?>
                <tr<?= $counted ? '' : ' style="opacity:.62;"' ?>>
                    <td><strong><?= htmlspecialchars($r['run_number']) ?></strong></td>
                    <td><?= htmlspecialchars(date('M j', strtotime($r['cutoff_period_start']))) ?> &ndash; <?= htmlspecialchars(date('M j, Y', strtotime($r['cutoff_period_end']))) ?></td>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($r['payout_date']))) ?></td>
                    <td>
                        <span class="owner-status-pill"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['status']))) ?></span>
                        <?php // Named on the row as well as in the subtitle: someone reading
                              // the table alone should not have to work out why the column
                              // does not add up to the tile above it. ?>
                        <?= $counted ? '' : '<div style="font-size:0.7rem;color:var(--op-ink-faint);margin-top:3px;">not counted in totals</div>' ?>
                    </td>
                    <td class="num" style="text-align:right;"><?= (int)$r['total_employees'] ?></td>
                    <td class="num" style="text-align:right;">&#8369;<?= number_format($r['total_gross_pay'], 2) ?></td>
                    <td class="num" style="text-align:right;">&#8369;<?= number_format($r['total_deductions'], 2) ?></td>
                    <td class="num" style="text-align:right;"><?= $erShare === null ? '&mdash;' : '&#8369;' . number_format($erShare, 2) ?></td>
                    <td class="num" style="text-align:right;font-weight:600;">&#8369;<?= number_format($r['total_net_pay'], 2) ?></td>
                    <td>
                        <?php if ($counted): ?>
                        <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-payroll-summary"
                                data-run-id="<?= $rid ?>" data-run-number="<?= htmlspecialchars($r['run_number']) ?>">Summary report</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    </div>

    <?php
    return ob_get_clean();
}

/**
 * One payroll run's summary report: the statement, plus its government
 * remittance table.
 *
 * Pulled out of renderPayrollTabHtml() so three callers share one definition
 * of what a run's summary IS -- the tab's "Summary report" modal
 * (report.php?payroll_fragment=1), the printable document, and any future
 * export. Previously every payable run rendered expanded inline, which was
 * unreadable the moment a period held more than two or three runs.
 *
 * $asCard wraps it in the usual .owner-card rp-section with a heading; the
 * modal passes false because the modal is already the frame.
 */
function renderPayrollRunSummaryHtml(array $run, ?array $comp, array $deds, bool $asCard = true): string
{
    ob_start(); ?>
    <?php
        $govt  = array_values(array_filter($deds, fn($d) => $d['category'] === 'government_mandatory'));
        $other = array_values(array_filter($deds, fn($d) => $d['category'] !== 'government_mandatory'));

        $govtEmployee = array_sum(array_map(fn($d) => (float)$d['employee_amount'], $govt));
        $govtEmployer = array_sum(array_map(fn($d) => (float)$d['employer_amount'], $govt));
        $otherTotal   = array_sum(array_map(fn($d) => (float)$d['employee_amount'], $other));

        // Whatever total_deductions holds that the itemised rows do not account
        // for -- withholding tax is computed onto the payslip itself rather than
        // as a payslip_deductions row, and late/absence sit in their own
        // columns. Shown as one honest residual line instead of being silently
        // dropped, so the statement still reconciles to net pay.
        $itemised  = $govtEmployee + $otherTotal;
        $lateAbs   = $comp ? ((float)$comp['late_deduction'] + (float)$comp['undertime_deduction'] + (float)$comp['absence_deduction']) : 0.0;
        $residual  = round((float)$run['total_deductions'] - $itemised - $lateAbs, 2);
    ?>
    <?php if ($asCard): ?><div class="owner-card rp-section"><?php else: ?><div><?php endif; ?>
        <div class="owner-card-head"><div>
            <?php // The modal header already names the run, so repeating it here
                  // just prints the run number twice. The meta line below stays --
                  // the cutoff, payout date and headcount are not shown anywhere else. ?>
            <?php if ($asCard): ?><h2 class="owner-card-title"><?= htmlspecialchars($run['run_number']) ?></h2><?php endif; ?>
            <span class="owner-card-subtitle">
                <?= htmlspecialchars(date('M j', strtotime($run['cutoff_period_start']))) ?>
                &ndash; <?= htmlspecialchars(date('M j, Y', strtotime($run['cutoff_period_end']))) ?>
                &middot; paid <?= htmlspecialchars(date('M j, Y', strtotime($run['payout_date']))) ?>
                &middot; <?= (int)$run['total_employees'] ?> employee(s)
                &middot; <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $run['status']))) ?>
            </span>
        </div></div>

        <div class="owner-table-wrap">
            <table class="owner-table">
                <tbody>
                    <?php if ($comp !== null): ?>
                        <?= salesStatementRow('Basic pay', (float)$comp['basic_pay'], '', 'Rate for days actually worked') ?>
                        <?php if ((float)$comp['overtime_pay'] > 0): ?>
                            <?= salesStatementRow('Overtime pay', (float)$comp['overtime_pay'], '', 'Approved overtime only') ?>
                        <?php endif; ?>
                        <?php if ((float)$comp['holiday_pay'] > 0): ?>
                            <?= salesStatementRow('Holiday pay', (float)$comp['holiday_pay']) ?>
                        <?php endif; ?>
                        <?php if ((float)$comp['rest_day_pay'] > 0): ?>
                            <?= salesStatementRow('Rest day pay', (float)$comp['rest_day_pay']) ?>
                        <?php endif; ?>
                        <?php if ((float)$comp['night_differential_pay'] > 0): ?>
                        <?php // Literal en dash, not &ndash;: salesStatementRow() escapes
                              // the note, so an entity would print as its own source. ?>
                            <?= salesStatementRow('Night differential', (float)$comp['night_differential_pay'], '', '10% premium, 10PM–6AM') ?>
                        <?php endif; ?>
                        <?php if ((float)$comp['leave_pay'] > 0): ?>
                            <?= salesStatementRow('Leave pay', (float)$comp['leave_pay'], '', 'Paid leave taken in this cutoff') ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?= salesStatementRow('GROSS PAY', (float)$run['total_gross_pay'], 'sub') ?>

                    <?php foreach ($govt as $d): ?>
                        <?= salesStatementRow(
                                'Less: ' . $d['name'],
                                -(float)$d['employee_amount'],
                                '',
                                (float)$d['employer_amount'] > 0
                                    ? 'Employee share; employer adds ' . html_entity_decode('&#8369;') . number_format((float)$d['employer_amount'], 2)
                                    : 'Employee share'
                            ) ?>
                    <?php endforeach; ?>
                    <?php foreach ($other as $d): ?>
                        <?= salesStatementRow('Less: ' . $d['name'], -(float)$d['employee_amount']) ?>
                    <?php endforeach; ?>
                    <?php if ($comp !== null && (float)$comp['late_deduction'] > 0): ?>
                        <?= salesStatementRow('Less: late deduction', -(float)$comp['late_deduction']) ?>
                    <?php endif; ?>
                    <?php if ($comp !== null && (float)$comp['undertime_deduction'] > 0): ?>
                        <?= salesStatementRow('Less: undertime deduction', -(float)$comp['undertime_deduction']) ?>
                    <?php endif; ?>
                    <?php if ($comp !== null && (float)$comp['absence_deduction'] > 0): ?>
                        <?= salesStatementRow('Less: absences', -(float)$comp['absence_deduction']) ?>
                    <?php endif; ?>
                    <?php if (abs($residual) >= 0.01): ?>
                        <?= salesStatementRow('Less: withholding tax and other', -$residual, '', 'Computed on the payslip, not itemised as a deduction line') ?>
                    <?php endif; ?>

                    <?= salesStatementRow('NET PAY', (float)$run['total_net_pay'], 'total', 'What employees actually received') ?>

                    <?php // Employer share is not a deduction -- it never touches the
                          // employee's pay. It is listed after net pay so the statement
                          // above still reconciles, and the true cost is still stated. ?>
                    <?php if ($govtEmployer > 0): ?>
                        <?= salesStatementRow('Add: employer share', $govtEmployer, '', 'SSS / PhilHealth / Pag-IBIG counterpart, paid on top of gross') ?>
                        <?= salesStatementRow('TOTAL COST TO THE BUSINESS', (float)$run['total_gross_pay'] + $govtEmployer, 'total') ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php // Named separately from the statement because these carry filing
              // deadlines -- the Owner is the one liable for remitting them. ?>
        <?php if ($govt): ?>
        <div class="owner-card-head" style="margin-top:18px;"><div>
            <h2 class="owner-card-title" style="font-size:0.9rem;">Government remittances</h2>
            <span class="owner-card-subtitle">Employee share withheld plus the employer counterpart &mdash; both owed by the business</span>
        </div></div>
        <div class="owner-table-wrap">
            <table class="owner-table">
                <thead><tr>
                    <th>Agency</th>
                    <th style="text-align:right;">Employee share</th>
                    <th style="text-align:right;">Employer share</th>
                    <th style="text-align:right;">Total remittable</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($govt as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['name']) ?></td>
                        <td class="num" style="text-align:right;">&#8369;<?= number_format((float)$d['employee_amount'], 2) ?></td>
                        <td class="num" style="text-align:right;">&#8369;<?= number_format((float)$d['employer_amount'], 2) ?></td>
                        <td class="num" style="text-align:right;font-weight:600;">&#8369;<?= number_format((float)$d['employee_amount'] + (float)$d['employer_amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="border-top:2px solid var(--op-ink);">
                        <td style="font-weight:600;">Total</td>
                        <td class="num" style="text-align:right;font-weight:600;">&#8369;<?= number_format($govtEmployee, 2) ?></td>
                        <td class="num" style="text-align:right;font-weight:600;">&#8369;<?= number_format($govtEmployer, 2) ?></td>
                        <td class="num" style="text-align:right;font-weight:600;">&#8369;<?= number_format($govtEmployee + $govtEmployer, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
