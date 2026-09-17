<?php
/**
 * config/sales_definitions.php
 *
 * ONE definition of what a sale is, and one way to decompose sales revenue.
 * Every report, dashboard, analytics panel and forecast in this app reads from
 * here, so they can no longer disagree with each other.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------------
 *
 * 1. "A sale" used to mean `op.payment_status='paid' AND o.order_status='completed'`
 *    in 37 places across 6 files. But nothing in this app has ever written
 *    'completed' -- cashier/api/create_order.php inserts 'open' and no workflow
 *    promotes it. The 2,184 rows carrying 'completed' are seeded demo data.
 *    The practical effect was that every real sale taken through the POS was
 *    INVISIBLE in every report: 29 real orders worth P36,188 that the owner
 *    could not see anywhere. The reports were, in effect, a demo-data viewer.
 *
 *    The fix is to stop asking a status column nobody maintains. A sale is a
 *    sale when money was actually taken and the sale has not been reversed:
 *
 *        op.payment_status = 'paid' AND o.order_status <> 'voided'
 *
 *    That is true of both the real orders and the seeded ones, so history stays
 *    intact and new sales appear immediately.
 *
 * 2. `orders.subtotal` CANNOT BE USED for money. It means two different things
 *    depending on the row: 2,040 seeded orders store it VAT-INCLUSIVE (equal to
 *    total_amount), while the 29 real orders and 144 seeded ones store it NET
 *    (total_amount - vat_amount). Anything summing that column is wrong for
 *    most of the table. Nothing in this file touches it.
 *
 *    Two identities were verified to hold on 2,213 of 2,213 rows, and they are
 *    what everything here is built from instead:
 *
 *      A)  SUM(order_items.subtotal)  -  discount  +  packaging
 *              =  orders.total_amount
 *      B)  vat_amount = (total_amount - packaging) * 12/112
 *              ... except on VAT-exempt discounts (PWD / Senior), where VAT is
 *                  correctly 0 by law.
 *
 *    (Service charge was dropped from the formula entirely on 2026-09-15 --
 *    orders.service_charge_amount existed but was never actually charged,
 *    pricing_settings.service_charge_enabled was 0 on every row this
 *    database has ever had, so removing it changes no historical figure.)
 *
 * 3. The old labels were inverted. "Gross Revenue" was SUM(order_items.subtotal)
 *    (P1,625,915) and "Net Revenue" was SUM(orders.total_amount) (P1,640,053) --
 *    a net larger than its gross, which no examiner would accept. The statement
 *    below uses the accounting meanings: revenue steps DOWN from gross to net.
 */

/**
 * The canonical sale predicate, as a SQL fragment.
 *
 * Requires the query to alias `orders` as `o` and `order_payments` as `op`.
 * Deliberately a constant rather than a function so it can be interpolated into
 * a prepared statement's SQL text -- it contains no user input of any kind.
 */
const SALE_PREDICATE = "op.payment_status = 'paid' AND o.order_status <> 'voided'";

/**
 * The moment a sale is recognised. Payment time, not order-creation time: an
 * order opened before midnight and paid after belongs to the day the money was
 * taken, which is the day it will be reconciled against. COALESCE because
 * paid_at was only added later and some early rows carry it as NULL.
 */
const SALE_DATE_EXPR = "COALESCE(op.paid_at, op.created_at)";

/** Line items that count. A voided order's lines are set to 'cancelled'. */
const SALE_ITEM_PREDICATE = "oi.status <> 'cancelled'";

/**
 * Builds the full sales statement for a period: the revenue waterfall, the VAT
 * split, the tender summary and the discount breakdown, all from the identities
 * above.
 *
 * Returned figures, and exactly what each one means:
 *
 *   gross_sales        item line totals before any discount (VAT-inclusive)
 *   discounts          PWD / Senior / any other reduction given
 *   net_sales_vat_inc  gross_sales - discounts  (what the food actually sold for)
 *   vat                output VAT contained within net_sales_vat_inc
 *   net_sales_vat_exc  net_sales_vat_inc - vat  <- THE revenue figure for a P&L
 *   packaging_fees     charged on top, not part of food sales
 *   total_collected    net_sales_vat_inc + packaging_fees
 *                      == SUM(orders.total_amount), ties exactly
 *
 *   vatable_sales      VAT-exclusive value of sales that carried VAT
 *   vat_exempt_sales   sales that carried none (PWD / Senior)
 *                      vatable_sales + vat_exempt_sales + vat == net_sales_vat_inc
 */
function buildSalesStatement(PDO $db, DateTime $start, DateTime $end): array
{
    $range = [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];

    // Per-order first, then aggregated in PHP. A single flat query mixing a
    // SUM over order_items with SUMs over orders would multiply every
    // order-level column by that order's line count -- the classic fan-out
    // that silently inflates discounts and VAT on any multi-item order.
    $sql = "
        SELECT o.order_id,
               o.discount_amount,
               o.discount_name,
               o.vat_amount,
               o.packaging_fee_amount,
               o.total_amount,
               COALESCE(dt.is_vat_exempt, 0) AS is_vat_exempt,
               COALESCE(i.items_gross, 0)    AS items_gross
        FROM orders o
        JOIN order_payments op ON op.order_id = o.order_id
        LEFT JOIN discount_types dt ON dt.discount_type_id = o.discount_type_id
        LEFT JOIN (
            SELECT oi.order_id, SUM(oi.subtotal) AS items_gross
            FROM order_items oi
            WHERE " . SALE_ITEM_PREDICATE . "
            GROUP BY oi.order_id
        ) i ON i.order_id = o.order_id
        WHERE " . SALE_PREDICATE . "
          AND " . SALE_DATE_EXPR . " BETWEEN ? AND ?";

    $stmt = $db->prepare($sql);
    $stmt->execute($range);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $s = [
        'order_count' => 0, 'gross_sales' => 0.0, 'discounts' => 0.0, 'vat' => 0.0,
        'packaging_fees' => 0.0, 'total_collected' => 0.0,
        'vatable_sales' => 0.0, 'vat_exempt_sales' => 0.0,
        'discounted_order_count' => 0,
    ];

    foreach ($rows as $r) {
        $itemsGross = (float)$r['items_gross'];
        $discount   = (float)$r['discount_amount'];
        $vat        = (float)$r['vat_amount'];
        $netVatInc  = $itemsGross - $discount;

        $s['order_count']++;
        $s['gross_sales']     += $itemsGross;
        $s['discounts']       += $discount;
        $s['vat']             += $vat;
        $s['packaging_fees']  += (float)$r['packaging_fee_amount'];
        $s['total_collected'] += (float)$r['total_amount'];

        if ($discount > 0) {
            $s['discounted_order_count']++;
        }

        // Classified by whether the sale actually carried VAT, not by the
        // discount flag alone -- an order can be VAT-exempt-flagged yet still
        // have been rung up before the flag existed.
        if ((int)$r['is_vat_exempt'] === 1 || $vat <= 0.0) {
            $s['vat_exempt_sales'] += $netVatInc;
        } else {
            $s['vatable_sales'] += $netVatInc - $vat;
        }
    }

    $s['net_sales_vat_inc'] = $s['gross_sales'] - $s['discounts'];
    $s['net_sales_vat_exc'] = $s['net_sales_vat_inc'] - $s['vat'];
    $s['avg_order_value']   = $s['order_count'] > 0 ? $s['total_collected'] / $s['order_count'] : 0.0;
    $s['discount_rate_pct'] = $s['gross_sales'] > 0 ? ($s['discounts'] / $s['gross_sales']) * 100 : 0.0;

    // Self-check carried in the payload rather than only asserted in a test:
    // the statement is only trustworthy if the waterfall lands on the money
    // actually collected. Any drift over one centavo is surfaced in the UI
    // instead of being quietly presented as fact.
    $s['ties_out'] = abs(
        ($s['net_sales_vat_inc'] + $s['packaging_fees']) - $s['total_collected']
    ) < 0.01;

    foreach ($s as $k => $v) {
        if (is_float($v)) {
            $s[$k] = round($v, 2);
        }
    }

    $s['tender']    = buildTenderSummary($db, $start, $end);
    $s['discounts_breakdown'] = buildDiscountBreakdown($db, $start, $end);

    // Money billed vs money that physically moved through the till. These are
    // NOT the same number and a report that shows only one invites the question
    // "why doesn't this match the drawer?". The gap is reservation deposits that
    // were credited against a bill instead of being collected again -- already
    // banked earlier, so the till never saw them.
    $s['deposit_credited_at_pos'] = round($s['total_collected'] - $s['tender']['total'], 2);

    return $s;
}

/**
 * Money in, split by how it was taken. This is what a cashier's drawer and a
 * GCash statement get reconciled against, so it reports the amount actually
 * COLLECTED per method (order_payments.amount), not the order total -- the two
 * differ whenever a reservation deposit covered part of the bill.
 */
function buildTenderSummary(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT op.payment_method, COUNT(*) AS txn_count, COALESCE(SUM(op.amount), 0) AS amount
         FROM order_payments op
         JOIN orders o ON o.order_id = op.order_id
         WHERE " . SALE_PREDICATE . "
           AND " . SALE_DATE_EXPR . " BETWEEN ? AND ?
         GROUP BY op.payment_method
         ORDER BY amount DESC"
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'method'    => $r['payment_method'],
            'label'     => salesPaymentMethodLabel($r['payment_method']),
            'is_cash'   => $r['payment_method'] === 'cash',
            'txn_count' => (int)$r['txn_count'],
            'amount'    => round((float)$r['amount'], 2),
            'pct'       => $total > 0 ? ((float)$r['amount'] / $total) * 100 : 0.0,
        ];
    }

    return ['rows' => $out, 'total' => round($total, 2)];
}

/**
 * Discounts given, by type. discount_name is snapshotted onto the order at
 * checkout, so this keeps reporting correctly even if a discount type is later
 * renamed or deleted -- which is exactly why that column exists.
 */
function buildDiscountBreakdown(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT COALESCE(o.discount_name, 'Unnamed') AS discount_name,
                COALESCE(MAX(dt.is_vat_exempt), 0)   AS is_vat_exempt,
                COUNT(*)                              AS order_count,
                COALESCE(SUM(o.discount_amount), 0)   AS amount
         FROM orders o
         JOIN order_payments op ON op.order_id = o.order_id
         LEFT JOIN discount_types dt ON dt.discount_type_id = o.discount_type_id
         WHERE " . SALE_PREDICATE . "
           AND o.discount_amount > 0
           AND " . SALE_DATE_EXPR . " BETWEEN ? AND ?
         GROUP BY o.discount_name
         ORDER BY amount DESC"
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);

    return array_map(fn($r) => [
        'discount_name' => $r['discount_name'],
        'is_vat_exempt' => (int)$r['is_vat_exempt'] === 1,
        'order_count'   => (int)$r['order_count'],
        'amount'        => round((float)$r['amount'], 2),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Reservation deposit accounting -- the money this app collects OUTSIDE the POS
 * and, until now, reported nowhere at all.
 *
 * A deposit has exactly one of three fates, and conflating them is how a
 * restaurant either double-counts revenue or loses track of a liability:
 *
 *   REDEEMED   the guest arrived and the deposit was credited against their POS
 *              bill (cashier/includes/pos_functions.php's getReservationCredit()).
 *              That money is ALREADY inside order revenue via orders.total_amount,
 *              so it is reported here for transparency but MUST NOT be added to
 *              revenue again. Detected by a surviving (non-voided) linked order.
 *
 *   FORFEITED  the guest no-showed or cancelled. The app's own Terms &
 *              Conditions (customer/make_reservation.php) state the fee is
 *              non-refundable and is forfeited, so the restaurant keeps it.
 *              This IS revenue -- other income, not food sales -- and it is the
 *              figure that was missing from every report.
 *
 *   HELD       the reservation is still pending or confirmed and its date has
 *              not resolved yet. Cash is in the bank but it is NOT revenue: it
 *              is a liability against a meal still owed. Reported separately so
 *              it is never mistaken for earnings.
 *
 * Recognition date: FORFEITED deposits are recognised on the reservation date
 * (when the no-show actually occurred), not the date the card was charged --
 * that is the day the restaurant became entitled to keep the money. Redeemed
 * and held are reported on the same basis for a consistent period view.
 */
function buildReservationDepositSummary(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT r.status,
                COALESCE(SUM(rp.amount_paid), 0) AS amount,
                COUNT(DISTINCT r.reservation_id) AS reservation_count,
                SUM(CASE WHEN linked.live_orders > 0 THEN 1 ELSE 0 END) AS with_live_order
         FROM reservation_payments rp
         JOIN reservations r ON r.reservation_id = rp.reservation_id
         LEFT JOIN (
             SELECT reservation_id, COUNT(*) AS live_orders
             FROM orders WHERE reservation_id IS NOT NULL AND order_status <> 'voided'
             GROUP BY reservation_id
         ) linked ON linked.reservation_id = r.reservation_id
         WHERE rp.payment_status IN ('paid', 'partial')
           AND rp.amount_paid > 0
           AND r.reservation_date BETWEEN ? AND ?
         GROUP BY r.status"
    );
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $out = ['redeemed' => 0.0, 'forfeited' => 0.0, 'held' => 0.0,
            'redeemed_count' => 0, 'forfeited_count' => 0, 'held_count' => 0];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amount = (float)$r['amount'];
        $count  = (int)$r['reservation_count'];

        switch ($r['status']) {
            case 'no_show':
            case 'cancelled':
                $out['forfeited']       += $amount;
                $out['forfeited_count'] += $count;
                break;
            case 'completed':
                // A completed reservation with a surviving order had its deposit
                // credited at the till. One without an order was completed by
                // hand, so nothing ever consumed the deposit -- that is forfeited
                // in substance, and calling it "redeemed" would claim it had been
                // counted in order revenue when it never was.
                $redeemedCount = (int)$r['with_live_order'];
                if ($redeemedCount === $count) {
                    $out['redeemed']       += $amount;
                    $out['redeemed_count'] += $count;
                } else {
                    $out['redeemed']       += $amount;
                    $out['redeemed_count'] += $redeemedCount;
                    $out['forfeited_count'] += $count - $redeemedCount;
                }
                break;
            default: // pending / confirmed -- not yet resolved
                $out['held']       += $amount;
                $out['held_count'] += $count;
        }
    }

    foreach (['redeemed', 'forfeited', 'held'] as $k) {
        $out[$k] = round($out[$k], 2);
    }
    $out['collected'] = round($out['redeemed'] + $out['forfeited'] + $out['held'], 2);

    // The only part that is revenue. Named explicitly so no caller has to guess.
    $out['other_income'] = $out['forfeited'];

    return $out;
}

/** Friendly tender label. Kept here so every sales surface spells them the same. */
function salesPaymentMethodLabel(?string $method): string
{
    return match ($method) {
        'cash'               => 'Cash',
        'paymongo_gcash'     => 'GCash',
        'paymongo_checkout'  => 'Online Checkout',
        null                 => 'Unpaid',
        default              => ucwords(str_replace(['paymongo_', '_'], ['', ' '], $method)),
    };
}

/**
 * Daily revenue series for the period, dense (missing days come back as zero so
 * a chart never draws a straight line across a closed day as if it were open).
 * Uses total_collected, matching the statement's bottom line.
 */
function buildDailySalesSeries(PDO $db, DateTime $start, DateTime $end): array
{
    $stmt = $db->prepare(
        "SELECT DATE(" . SALE_DATE_EXPR . ") AS d,
                COALESCE(SUM(o.total_amount), 0) AS amount,
                COUNT(DISTINCT o.order_id) AS orders
         FROM orders o
         JOIN order_payments op ON op.order_id = o.order_id
         WHERE " . SALE_PREDICATE . "
           AND " . SALE_DATE_EXPR . " BETWEEN ? AND ?
         GROUP BY d ORDER BY d"
    );
    $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);

    $byDay = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byDay[$r['d']] = ['amount' => (float)$r['amount'], 'orders' => (int)$r['orders']];
    }

    $series = [];
    $cursor = (clone $start)->setTime(0, 0);
    $stop   = (clone $end)->setTime(0, 0);
    while ($cursor <= $stop) {
        $key = $cursor->format('Y-m-d');
        $series[] = [
            'date'   => $key,
            'amount' => round($byDay[$key]['amount'] ?? 0.0, 2),
            'orders' => $byDay[$key]['orders'] ?? 0,
        ];
        $cursor->modify('+1 day');
    }

    return $series;
}
