<?php
/**
 * customer/includes/reservation_functions.php
 *
 * Shared logic for the reservation booking wizard and its AJAX endpoints —
 * the customer-facing sibling of cashier/includes/pos_functions.php. Every
 * reservation business rule (fee, deposit %, guest limits, lead time,
 * capacity, operating days, closed dates) is read from system_settings /
 * reservation_blackouts here, never hardcoded by callers.
 */

require_once __DIR__ . '/../../config/notifications.php';
require_once __DIR__ . '/../../config/paymongo.php'; // getPaymongoClient(), for sweepExpiredReservationHolds()'s pre-cancel reconciliation

/**
 * The DB server's own idea of "now"/"today" — used everywhere this file
 * needs to compare against the current moment, instead of PHP's local
 * clock. This app's environment has PHP's CLI SAPI, PHP's Apache SAPI, and
 * MySQL each configured with a DIFFERENT timezone (a pre-existing
 * misconfiguration, not something this feature introduced) — the
 * reservations triggers (sp_check_reservation_lead_time) enforce their
 * cutoff using MySQL's NOW(), so every PHP-side preview of that same rule
 * must be computed from MySQL's clock too, or the UI could offer a slot
 * the trigger then rejects (or block one it would have accepted).
 */
function getDbNow(PDO $db): array
{
    $row = $db->query('SELECT NOW() AS now, CURDATE() AS today')->fetch(PDO::FETCH_ASSOC);
    return ['now' => $row['now'], 'today' => $row['today']];
}

/**
 * All reservation-related settings, typed and defaulted. `operating_days`
 * is a comma-separated list of ISO weekday numbers (1=Mon..7=Sun) that are
 * OPEN — chosen because PHP's date('N', ...) already returns 1..7 with no
 * conversion needed.
 */
function getReservationSettings(PDO $db): array
{
    $keys = [
        'total_capacity', 'reservation_min_lead_hours', 'reservation_max_advance_days', 'reservation_fee_amount',
        'advance_order_deposit_percentage', 'reservation_min_guests', 'reservation_max_guests',
        'advance_order_min_amount', 'operating_days', 'reservation_hold_minutes',
        'reservation_no_show_hours',
    ];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ({$placeholders})");
    $stmt->execute($keys);

    $raw = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raw[$row['setting_key']] = $row['setting_value'];
    }

    return [
        'total_capacity'                  => (int)($raw['total_capacity'] ?? 80),
        'reservation_min_lead_hours'       => (int)($raw['reservation_min_lead_hours'] ?? 2),
        'reservation_max_advance_days'     => (int)($raw['reservation_max_advance_days'] ?? 90),
        'reservation_fee_amount'           => (float)($raw['reservation_fee_amount'] ?? 0),
        'advance_order_deposit_percentage' => (float)($raw['advance_order_deposit_percentage'] ?? 0),
        'reservation_min_guests'           => (int)($raw['reservation_min_guests'] ?? 1),
        'reservation_max_guests'           => (int)($raw['reservation_max_guests'] ?? 20),
        'advance_order_min_amount'         => (float)($raw['advance_order_min_amount'] ?? 0),
        'operating_days'                   => array_values(array_filter(array_map('intval', explode(',', $raw['operating_days'] ?? '1,2,3,4,5,6,7')))),
        'reservation_hold_minutes'         => (int)($raw['reservation_hold_minutes'] ?? 10),
        'reservation_no_show_hours'        => (int)($raw['reservation_no_show_hours'] ?? 2),
    ];
}

/** Active time slots, ordered by start time. */
function getActiveTimeSlots(PDO $db): array
{
    return $db->query(
        "SELECT slot_id, slot_label, start_time, end_time FROM time_slots WHERE is_active = 1 ORDER BY start_time"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** One-off closed dates (holidays etc.) within a date range. */
function getBlackoutDates(PDO $db, string $fromDate, string $toDate): array
{
    $stmt = $db->prepare(
        "SELECT blackout_date, reason FROM reservation_blackouts WHERE blackout_date BETWEEN ? AND ? ORDER BY blackout_date"
    );
    $stmt->execute([$fromDate, $toDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Releases holds that were never paid within the configured hold window.
 * Guarded on BOTH reservations.status='pending' AND
 * reservation_payments.payment_status='pending' in one atomic statement —
 * guarding on reservation status alone would risk cancelling a reservation
 * whose payment posted in the same instant this sweep runs. The write to
 * payment_status is what makes the guard false for any later attempt, so
 * double-decrementing booked_capacity is structurally impossible.
 */
/**
 * Before cancelling an expired pending hold, reconciles it against PayMongo
 * directly for any row that actually reached a checkout session -- the
 * webhook (reservation_paymongo_webhook.php) can only ever fire in
 * production (see its own docblock: no public HTTPS endpoint from
 * localhost/ngrok unless that URL is registered with PayMongo), so a
 * customer who genuinely paid but whose browser never made it back to
 * reservation_confirm.php would otherwise sit at payment_status='pending'
 * forever and get silently cancelled here -- charged, but no reservation.
 * finalizeReservationPayment() is the same idempotent confirm both of those
 * paths already use, so a row rescued here can never be double-confirmed.
 *
 * This runs on a very hot path (called from most reservation-adjacent page
 * loads, not just a cron), so it must stay cheap in the common case: a real
 * PayMongo call only happens for rows that are BOTH already past the hold
 * window AND actually have a checkout session on file, which in practice is
 * 0 rows almost all the time. A PayMongo outage or misconfiguration must not
 * break the page that triggered this -- any failure here just falls through
 * to the same cancel behavior this function always had.
 */
function sweepExpiredReservationHolds(PDO $db, int $holdMinutes): void
{
    $candidates = $db->prepare(
        "SELECT rp.payment_id, rp.paymongo_reference_number
         FROM reservations r
         JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
         WHERE r.status = 'pending'
           AND rp.payment_status = 'pending'
           AND r.created_at < (NOW() - INTERVAL ? MINUTE)"
    );
    $candidates->execute([$holdMinutes]);
    $rows = $candidates->fetchAll(PDO::FETCH_ASSOC);

    $client = null;
    foreach ($rows as $row) {
        if (empty($row['paymongo_reference_number'])) {
            continue; // never reached a checkout session -- nothing to reconcile
        }
        try {
            $client = $client ?? getPaymongoClient($db);
            if ($client === null) {
                break; // PayMongo not configured -- every remaining row falls through to cancel, same as before
            }
            $session = $client->retrieveCheckoutSession($row['paymongo_reference_number']);
            if ($session['has_paid_payment']) {
                finalizeReservationPayment($db, (int)$row['payment_id'], $session['payment_intent_id'], null);
            }
        } catch (Throwable $e) {
            error_log('sweepExpiredReservationHolds: PayMongo reconciliation failed for payment_id ' . $row['payment_id'] . ': ' . $e->getMessage());
            // Falls through to cancel below, same as this function's behavior before this check existed.
        }
    }

    // Any row rescued above is no longer payment_status='pending', so this
    // WHERE clause naturally excludes it without needing to track which ones
    // were rescued.
    $stmt = $db->prepare(
        "UPDATE reservations r
         JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
         JOIN slot_availability sa ON sa.reservation_date = r.reservation_date AND sa.slot_id = r.slot_id
         SET r.status = 'cancelled',
             rp.payment_status = 'failed',
             sa.booked_capacity = GREATEST(0, sa.booked_capacity - r.number_of_guests)
         WHERE r.status = 'pending'
           AND rp.payment_status = 'pending'
           AND r.created_at < (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->execute([$holdMinutes]);
}

/**
 * Auto-flags no-shows: a 'confirmed' reservation whose slot start time plus
 * the configured grace period (reservation_no_show_hours) has passed, with
 * no order ever linked to it. orders.reservation_id is how staff already
 * apply a reservation's paid credit at POS (cashier/api/lookup_reservation.php,
 * cashier/api/create_order.php) — every reservation carries paid credit
 * (fee or deposit) worth redeeming, so a linked order is a reliable "this
 * customer arrived" signal that already exists in the checkout flow, not a
 * new one invented just for this sweep. Uses MySQL's own NOW(), like
 * sweepExpiredReservationHolds, for the same PHP/MySQL timezone-mismatch
 * reason.
 *
 * SELECT-then-UPDATE rather than the single bulk UPDATE this used to be. The
 * bulk form could not name the rows it changed, so the sweep flipped people to
 * no_show without ever telling them. Reading the candidates first is what
 * makes a per-customer notification possible at all.
 *
 * Each row is then updated individually and still guarded on
 * status = 'confirmed', so the row is only claimed once even though this sweep
 * runs from four different places (three customer pages and the cron) and two
 * can overlap. rowCount() === 1 means THIS call is the one that flipped it, and
 * only that call notifies -- no duplicate, no notification for a row someone
 * else changed in between. createNotificationOnce() dedupes as well, but the
 * guard is what makes the claim correct rather than merely tidy.
 *
 * The extra queries are bounded by how many reservations actually time out in
 * one sweep, which is a handful at most -- not by the size of the table.
 */
function sweepNoShowReservations(PDO $db, int $noShowHours): void
{
    $candidates = $db->prepare(
        "SELECT r.reservation_id, r.customer_id, r.reservation_number
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         LEFT JOIN orders o ON o.reservation_id = r.reservation_id
         WHERE r.status = 'confirmed'
           AND o.order_id IS NULL
           AND TIMESTAMP(r.reservation_date, ts.start_time) < (NOW() - INTERVAL ? HOUR)"
    );
    $candidates->execute([$noShowHours]);
    $rows = $candidates->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return;
    }

    $claim = $db->prepare(
        "UPDATE reservations SET status = 'no_show' WHERE reservation_id = ? AND status = 'confirmed'"
    );
    foreach ($rows as $row) {
        $claim->execute([(int)$row['reservation_id']]);
        if ($claim->rowCount() === 1) {
            notifyReservationNoShow(
                $db, (int)$row['customer_id'], (int)$row['reservation_id'], (string)$row['reservation_number']
            );
        }
    }
}

/* ---------------------------------------------------------------------- */
/* Customer notifications                                                  */
/*                                                                          */
/* Two kinds, both writing through config/notifications.php's              */
/* createNotificationOnce():                                                */
/*   - Event-triggered (notifyReservationConfirmed/Completed): called      */
/*     directly, once, at the exact moment the status actually changes.    */
/*   - Opportunistic sweeps (notifyUpcomingHoldExpiry/TodayReservations/    */
/*     UpcomingNoShow): there's no cron/task-scheduler in this app, so      */
/*     these are called from customer/includes/navbar.php on every         */
/*     customer page load, scoped to just that logged-in customer's own    */
/*     rows (cheap) — real, correct notifications, but only as timely as   */
/*     the customer's own next page load. A registered cron hitting the    */
/*     same functions on a schedule would close that gap; not built here.  */
/* ---------------------------------------------------------------------- */

function notifyReservationConfirmed(PDO $db, int $customerId, int $reservationId, string $reservationNumber): void
{
    createNotificationOnce(
        $db, $customerId, 'reservation',
        'Reservation confirmed!',
        "Your reservation {$reservationNumber} is confirmed. We'll see you soon!",
        'reservation_confirmed', $reservationId
    );
}

/**
 * Tells a customer their reservation was actually marked a no-show.
 *
 * A customer loses their table and the credit they already paid, and the
 * first sign of it used to be the status having quietly changed the next
 * time they happened to look -- this is the courtesy notice for the outcome
 * that actually costs them something. The only path that sets
 * status='no_show' is the sweep below -- the staff reservation panel's
 * manual "Mark No-show" override, which used to call this too, was removed.
 *
 * Deliberately states what happened rather than apologising for it -- the
 * status is a fact, and the customer needs to know it firmly enough to follow
 * up with staff if they believe it is wrong.
 */
function notifyReservationNoShow(PDO $db, int $customerId, int $reservationId, string $reservationNumber): void
{
    createNotificationOnce(
        $db, $customerId, 'reservation',
        // Short on purpose. The card shows the title and body run together, so
        // the old copy read "...marked a no-show We didn't see you for..." and
        // then said "no-show" a third time. The customer needs two facts --
        // the table is gone, and they can dispute it -- not a paragraph.
        'Reservation marked as no-show',
        "{$reservationNumber} because you did not arrive. "
        . "Contact us if this is a mistake.",
        'reservation_no_show', $reservationId
    );

    // Same in-app-only gap as the notification above had before it existed:
    // the confirmation is emailed (sendReservationConfirmationEmail(), so it
    // survives after the guest closes the confirmation tab), but this outcome
    // -- losing the table AND the deposit -- wasn't. Called from inside this
    // function so it can't double-send on a later page load re-running the
    // sweep. Best-effort like every other notify*() here -- a mail failure
    // must never surface as a failed status change.
    require_once __DIR__ . '/reservation_email.php';
    sendReservationNoShowEmail($db, $reservationId);
}

/**
 * Warns a customer, once per reservation, that their unpaid hold is close
 * to auto-release -- fires once NOW() is within $warnWithinMinutes of the
 * hold's actual expiry (created_at + $holdMinutes), matching
 * sweepExpiredReservationHolds()'s own expiry math exactly so this always
 * warns before that sweep would silently release the same hold.
 */
function notifyUpcomingHoldExpiry(PDO $db, int $customerId, int $holdMinutes, int $warnWithinMinutes = 3): void
{
    $thresholdMinutes = max(0, $holdMinutes - $warnWithinMinutes);
    $stmt = $db->prepare(
        "SELECT r.reservation_id, r.reservation_number
         FROM reservations r
         JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
         WHERE r.customer_id = ? AND r.status = 'pending' AND rp.payment_status = 'pending'
           AND r.created_at <= (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->execute([$customerId, $thresholdMinutes]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        createNotificationOnce(
            $db, $customerId, 'reservation',
            'Your reservation hold is about to expire',
            "Complete payment for {$row['reservation_number']} soon, or your held table will be released automatically.",
            'reservation_hold_expiring', (int)$row['reservation_id']
        );
    }
}

/** Once-a-day-of reminder for each of a customer's confirmed reservations dated today. */
function notifyTodayReservations(PDO $db, int $customerId): void
{
    $stmt = $db->prepare(
        "SELECT r.reservation_id, r.reservation_number, ts.slot_label
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         WHERE r.customer_id = ? AND r.status = 'confirmed' AND r.reservation_date = CURDATE()"
    );
    $stmt->execute([$customerId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        createNotificationOnce(
            $db, $customerId, 'reservation',
            'Your reservation is today!',
            "Don't forget — {$row['reservation_number']} today at {$row['slot_label']}.",
            'reservation_today', (int)$row['reservation_id']
        );
    }
}

/**
 * Warns a customer, once per reservation, when they're approaching
 * sweepNoShowReservations()'s own cutoff (slot start + $noShowHours) with
 * no order linked yet -- same LEFT JOIN orders / IS NULL "have they
 * arrived" signal that sweep uses, so this always warns before that sweep
 * would silently mark the same reservation a no-show. Fires once
 * $warnWithinFraction of the grace window remains (default: the back half
 * of it).
 *
 * $customerId null means EVERY customer, which is how cron/run_sweeps.php
 * calls it. That is not a convenience: the warning used to run only from
 * customer/includes/navbar.php, so it needed the customer to open a page
 * during the grace window, while sweepNoShowReservations() marks them from
 * cron on a schedule regardless. A customer who booked and closed the tab got
 * marked a no-show having never been warned -- of 15 no-shows in this
 * database, only 3 were ever warned. Same trigger for both halves closes that.
 */
function notifyUpcomingNoShow(PDO $db, ?int $customerId, int $noShowHours, float $warnWithinFraction = 0.5): void
{
    $warnMinutes = max(1, (int)round($noShowHours * 60 * $warnWithinFraction));
    $scopeSql    = $customerId !== null ? 'r.customer_id = ? AND ' : '';
    $params      = $customerId !== null ? [$customerId] : [];

    $stmt = $db->prepare(
        "SELECT r.reservation_id, r.customer_id, r.reservation_number, ts.slot_label
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         LEFT JOIN orders o ON o.reservation_id = r.reservation_id
         WHERE {$scopeSql}r.status = 'confirmed' AND o.order_id IS NULL
           AND TIMESTAMP(r.reservation_date, ts.start_time) + INTERVAL ? HOUR <= (NOW() + INTERVAL ? MINUTE)
           AND TIMESTAMP(r.reservation_date, ts.start_time) + INTERVAL ? HOUR > NOW()"
    );
    $stmt->execute(array_merge($params, [$noShowHours, $warnMinutes, $noShowHours]));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        createNotificationOnce(
            // Read off the row, not the argument -- the all-customers call has
            // no single id to use.
            $db, (int)$row['customer_id'], 'reservation',
            // Same shape as notifyReservationNoShow(): the card runs title into
            // body, so a title ending in "no-show" followed by "We haven't seen
            // you" read as one broken sentence.
            'Reservation at risk of no-show',
            "{$row['reservation_number']} ({$row['slot_label']}) — please check in soon to keep your table.",
            'reservation_no_show_warning', (int)$row['reservation_id']
        );
    }
}

/**
 * Releases one specific hold immediately (customer-cancelled, or a
 * post-commit failure like PayMongo being unreachable) — the same
 * three-statement release the opportunistic sweep does, just scoped to one
 * reservation instead of "every stale row". Guarded on both
 * status='pending' and payment_status='pending' so calling this twice (or
 * racing the sweep) is a harmless no-op the second time.
 */
function releaseReservationHold(PDO $db, int $reservationId, int $paymentId, string $date, string $slotId, int $guests): void
{
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ? AND status = 'pending'")
            ->execute([$reservationId]);
        $db->prepare("UPDATE reservation_payments SET payment_status = 'failed' WHERE payment_id = ? AND payment_status = 'pending'")
            ->execute([$paymentId]);
        $db->prepare("UPDATE slot_availability SET booked_capacity = GREATEST(0, booked_capacity - ?) WHERE reservation_date = ? AND slot_id = ?")
            ->execute([$guests, $date, $slotId]);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('releaseReservationHold failed: ' . $e->getMessage());
    }
}

/**
 * Per-slot capacity + lead-time state for one date. `slot_availability` is
 * an optional per-(date,slot) override; a missing row defaults the ceiling
 * to `total_capacity`. Lead-time math mirrors sp_check_reservation_lead_time
 * exactly so the UI never offers a slot the DB trigger would then reject —
 * both computed from $dbNow (getDbNow()'s MySQL-sourced clock), never
 * PHP's local clock, since this environment's PHP and MySQL timezones
 * don't agree.
 */
function getSlotAvailabilityForDate(PDO $db, string $date, array $activeSlots, array $settings, array $dbNow): array
{
    $stmt = $db->prepare(
        "SELECT slot_id, max_capacity, booked_capacity FROM slot_availability WHERE reservation_date = ?"
    );
    $stmt->execute([$date]);
    $overrides = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overrides[(int)$row['slot_id']] = $row;
    }

    $leadCutoff = (new DateTime($dbNow['now']))->modify('+' . $settings['reservation_min_lead_hours'] . ' hours');

    $result = [];
    foreach ($activeSlots as $slot) {
        $slotId    = (int)$slot['slot_id'];
        $override  = $overrides[$slotId] ?? null;
        $maxCap    = $override !== null ? (int)$override['max_capacity'] : $settings['total_capacity'];
        $bookedCap = $override !== null ? (int)$override['booked_capacity'] : 0;
        $remaining = max(0, $maxCap - $bookedCap);

        $slotDateTime    = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $slot['start_time']);
        $leadTimeBlocked = $slotDateTime !== false && $slotDateTime < $leadCutoff;

        $result[] = [
            'slot_id'           => $slotId,
            'slot_label'        => $slot['slot_label'],
            'start_time'        => $slot['start_time'],
            'end_time'          => $slot['end_time'],
            'max_capacity'      => $maxCap,
            'booked_capacity'   => $bookedCap,
            'remaining'         => $remaining,
            'lead_time_blocked' => $leadTimeBlocked,
            'is_full'           => $remaining <= 0,
        ];
    }

    return $result;
}

/**
 * Per-day closed/availability summary for a whole month, for calendar
 * rendering. Bulk-queries slot_availability + blackouts once each (no
 * N+1) and loops the days in PHP.
 */
function getMonthAvailabilitySummary(PDO $db, string $yearMonth, array $activeSlots, array $settings, array $dbNow): array
{
    $firstDay = $yearMonth . '-01';
    $lastDay  = date('Y-m-t', strtotime($firstDay));

    $stmt = $db->prepare(
        "SELECT reservation_date, slot_id, max_capacity, booked_capacity
         FROM slot_availability WHERE reservation_date BETWEEN ? AND ?"
    );
    $stmt->execute([$firstDay, $lastDay]);
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDate[$row['reservation_date']][(int)$row['slot_id']] = $row;
    }

    $blackouts = array_column(getBlackoutDates($db, $firstDay, $lastDay), 'reason', 'blackout_date');

    $today       = $dbNow['today'];
    $maxDate     = (new DateTime($today))->modify('+' . $settings['reservation_max_advance_days'] . ' days')->format('Y-m-d');
    $activeSlotIds = array_map(fn($s) => (int)$s['slot_id'], $activeSlots);

    $result = [];
    $period = new DatePeriod(new DateTime($firstDay), new DateInterval('P1D'), (new DateTime($lastDay))->modify('+1 day'));
    foreach ($period as $day) {
        $dateStr = $day->format('Y-m-d');
        $weekday = (int)$day->format('N');

        $isPast          = $dateStr < $today;
        $isTooFarAhead    = $dateStr > $maxDate;
        $isBlackout       = array_key_exists($dateStr, $blackouts);
        $isClosedWeekday  = !in_array($weekday, $settings['operating_days'], true);
        $closed           = $isPast || $isTooFarAhead || $isBlackout || $isClosedWeekday;

        $hasAvailability = false;
        if (!$closed) {
            foreach ($activeSlotIds as $slotId) {
                $override  = $byDate[$dateStr][$slotId] ?? null;
                $maxCap    = $override !== null ? (int)$override['max_capacity'] : $settings['total_capacity'];
                $bookedCap = $override !== null ? (int)$override['booked_capacity'] : 0;
                if ($maxCap - $bookedCap > 0) {
                    $hasAvailability = true;
                    break;
                }
            }
        }

        $result[$dateStr] = [
            'closed'           => $closed,
            'is_past'          => $isPast,
            'is_too_far_ahead' => $isTooFarAhead,
            'is_blackout'      => $isBlackout,
            'has_availability' => $hasAvailability,
        ];
    }

    return $result;
}

/**
 * NO advance order -> flat reservation fee. WITH advance order -> deposit %
 * of the advance-order total. Never both, matching the spec's payment
 * branching exactly.
 */
function computeReservationPaymentDue(array $settings, float $advanceOrderTotal): array
{
    if ($advanceOrderTotal > 0) {
        $depositPct = $settings['advance_order_deposit_percentage'];
        return [
            'purpose'            => 'advance_order_deposit',
            'amount_due'         => round($advanceOrderTotal * $depositPct / 100, 2),
            'deposit_percentage' => $depositPct,
        ];
    }

    return [
        'purpose'            => 'reservation_fee',
        'amount_due'         => $settings['reservation_fee_amount'],
        'deposit_percentage' => null,
    ];
}

/**
 * Creates a pending reservation hold -- capacity-locked, validated against
 * every real business rule (date range, guest limits, operating days,
 * blackouts, lead time via the DB trigger, one-live-hold-per-customer,
 * advance-order minimum) -- exactly the transaction
 * customer/api/reservation_checkout.php used to run inline. Extracted here
 * so a second caller (the chatbot's booking tool, customer/includes/
 * chatbot_functions.php) can create a hold through the *same* tested code
 * path instead of a second copy of this business-critical logic drifting
 * out of sync. Does NOT create the PayMongo checkout session -- that's a
 * network call that must happen outside any DB transaction, left to the
 * caller (see createReservationCheckoutSession()).
 *
 * $cartLines is the same shape reservation_checkout.php already builds
 * (item_id/item_name/quantity/notes/unit_price/subtotal, re-priced from the
 * DB) -- pass [] for a plain reservation with no advance order.
 *
 * Returns ['error' => string] on any validation/capacity failure (never
 * throws for an expected business-rule rejection), or ['reservation_id',
 * 'reservation_number', 'payment_id', 'payment_due', 'slot_label',
 * 'advance_order_total'] on success.
 */
function createReservationHoldCore(PDO $db, int $customerId, string $reservationDate, int $slotId, int $guests, array $cartLines): array
{
    $settings = getReservationSettings($db);
    $dbNow = getDbNow($db);

    if ($reservationDate < $dbNow['today']) {
        return ['error' => 'Please select a valid reservation date.'];
    }
    $maxDate = (new DateTime($dbNow['today']))->modify('+' . $settings['reservation_max_advance_days'] . ' days')->format('Y-m-d');
    if ($reservationDate > $maxDate) {
        return ['error' => "Reservations can only be made up to {$settings['reservation_max_advance_days']} days in advance."];
    }
    if ($guests < $settings['reservation_min_guests']) {
        return ['error' => "Minimum reservation is {$settings['reservation_min_guests']} guests."];
    }
    if ($guests > $settings['reservation_max_guests']) {
        return ['error' => "Maximum reservation is {$settings['reservation_max_guests']} guests."];
    }

    $weekday = (int)date('N', strtotime($reservationDate));
    if (!in_array($weekday, $settings['operating_days'], true)) {
        return ['error' => 'The restaurant is closed on that day. Please choose another date.'];
    }
    $blackouts = getBlackoutDates($db, $reservationDate, $reservationDate);
    if (!empty($blackouts)) {
        return ['error' => 'The restaurant is closed on that date. Please choose another date.'];
    }

    $slotStmt = $db->prepare('SELECT slot_id, slot_label, start_time FROM time_slots WHERE slot_id = ? AND is_active = 1');
    $slotStmt->execute([$slotId]);
    $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) {
        return ['error' => "That time slot doesn't exist or is no longer active."];
    }

    $advanceOrderTotal = array_sum(array_column($cartLines, 'subtotal'));
    if ($advanceOrderTotal > 0 && $advanceOrderTotal < $settings['advance_order_min_amount']) {
        return ['error' => 'Minimum advance order amount is ₱' . number_format($settings['advance_order_min_amount'], 2) . '.'];
    }

    sweepExpiredReservationHolds($db, $settings['reservation_hold_minutes']);

    // One live hold per customer at a time -- closes an easy
    // self-inflicted double-charge path (two tabs, two holds; or now, the
    // wizard and the chatbot both starting one).
    $existingHoldStmt = $db->prepare(
        "SELECT r.reservation_id FROM reservations r
         JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
         WHERE r.customer_id = ? AND r.status = 'pending' AND rp.payment_status = 'pending'
           AND r.created_at > (NOW() - INTERVAL ? MINUTE)
         LIMIT 1"
    );
    $existingHoldStmt->execute([$customerId, $settings['reservation_hold_minutes']]);
    if ($existingHoldStmt->fetch()) {
        return ['error' => 'You already have a reservation being processed. Please complete or wait for it to expire before starting another.'];
    }

    $db->beginTransaction();

    $db->prepare(
        'INSERT IGNORE INTO slot_availability (reservation_date, slot_id, max_capacity, booked_capacity) VALUES (?, ?, ?, 0)'
    )->execute([$reservationDate, $slotId, $settings['total_capacity']]);

    $lockStmt = $db->prepare(
        'SELECT slot_availability_id, max_capacity, booked_capacity FROM slot_availability WHERE reservation_date = ? AND slot_id = ? FOR UPDATE'
    );
    $lockStmt->execute([$reservationDate, $slotId]);
    $availabilityRow = $lockStmt->fetch(PDO::FETCH_ASSOC);

    if (!$availabilityRow) {
        $db->rollBack();
        return ['error' => "That time slot doesn't exist or is no longer active."];
    }

    $remaining = (int)$availabilityRow['max_capacity'] - (int)$availabilityRow['booked_capacity'];
    if ($remaining < $guests) {
        $db->rollBack();
        return ['error' => "Only {$remaining} seats remain for this time slot."];
    }

    $db->prepare('UPDATE slot_availability SET booked_capacity = booked_capacity + ? WHERE slot_availability_id = ?')
        ->execute([$guests, $availabilityRow['slot_availability_id']]);

    $reservationId = null;
    $reservationNumber = null;
    $attempts = 0;
    while ($reservationId === null) {
        $attempts++;
        $candidateNumber = generateReservationNumber($db);
        try {
            $insRes = $db->prepare(
                "INSERT INTO reservations (reservation_number, customer_id, reservation_date, slot_id, number_of_guests, status, payment_type)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?)"
            );
            $insRes->execute([
                $candidateNumber, $customerId, $reservationDate, $slotId, $guests,
                $advanceOrderTotal > 0 ? 'advance_order_deposit' : 'reservation_fee',
            ]);
            $reservationId = (int)$db->lastInsertId();
            $reservationNumber = $candidateNumber;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && $attempts < 3) {
                continue;
            }
            if ($e->getCode() === '45000') {
                $db->rollBack();
                return ['error' => mapReservationTriggerError($e, $settings)];
            }
            throw $e;
        }
    }

    $paymentDue = computeReservationPaymentDue($settings, $advanceOrderTotal);
    $insPayment = $db->prepare(
        "INSERT INTO reservation_payments (reservation_id, payment_purpose, deposit_percentage, amount_due, payment_method, payment_status)
         VALUES (?, ?, ?, ?, 'paymongo_checkout', 'pending')"
    );
    $insPayment->execute([$reservationId, $paymentDue['purpose'], $paymentDue['deposit_percentage'], $paymentDue['amount_due']]);
    $paymentId = (int)$db->lastInsertId();

    if (!empty($cartLines)) {
        $insItem = $db->prepare(
            'INSERT INTO reservation_advance_orders (reservation_id, menu_item_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($cartLines as $line) {
            $insItem->execute([$reservationId, $line['item_id'], $line['quantity'], $line['unit_price'], $line['subtotal']]);
        }

        try {
            $callStmt = $db->prepare('CALL sp_check_advance_order_minimum(?)');
            $callStmt->execute([$reservationId]);
            $callStmt->closeCursor();
        } catch (PDOException $e) {
            $db->rollBack();
            return ['error' => mapReservationTriggerError($e, $settings)];
        }
    }

    $db->commit();

    try {
        $db->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Reservations', 'Create reservation hold', ?, ?)"
        )->execute([
            $customerId,
            "{$reservationNumber}: {$guests} guests on {$reservationDate} ({$slot['slot_label']})",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    return [
        'reservation_id'      => $reservationId,
        'reservation_number'  => $reservationNumber,
        'payment_id'          => $paymentId,
        'payment_due'         => $paymentDue,
        'slot_label'          => $slot['slot_label'],
        'advance_order_total' => $advanceOrderTotal,
    ];
}

/**
 * Builds the PayMongo Checkout Session line item(s) for a reservation
 * payment and creates the session. Shared by the initial booking checkout
 * and the "Continue Payment" resume flow on the reservation details page —
 * both must build the exact same request shape for a given payment, so it
 * lives here once instead of two inline copies drifting apart.
 */
function createReservationCheckoutSession(
    PaymongoClient $paymongo,
    string $reservationNumber,
    int $reservationId,
    int $paymentId,
    string $purpose,
    ?string $depositPercentage,
    float $amountDue,
    int $guests,
    string $reservationDate,
    string $slotLabel,
    float $advanceOrderTotal,
    ?string $customerEmail
): array {
    if ($purpose === 'reservation_fee') {
        $lineItems = [[
            'name'        => 'Reservation Fee',
            'amount'      => $amountDue,
            'quantity'    => 1,
            'description' => "Table for {$guests} on {$reservationDate}, {$slotLabel}",
        ]];
    } else {
        $lineItems = [[
            'name'        => "Advance Order Deposit ({$depositPercentage}%)",
            'amount'      => $amountDue,
            'quantity'    => 1,
            'description' => 'Deposit toward advance order of ₱' . number_format($advanceOrderTotal, 2),
        ]];
    }

    return $paymongo->createCheckoutSession(
        $lineItems,
        ['gcash'],
        appUrl('customer/reservation_confirm.php?reservation_number=' . urlencode($reservationNumber)),
        appUrl('customer/reservation_cancel_return.php?reservation_number=' . urlencode($reservationNumber)),
        "Reservation {$reservationNumber}",
        $reservationNumber,
        ['reservation_id' => $reservationId, 'reservation_number' => $reservationNumber, 'payment_id' => $paymentId],
        $customerEmail
    );
}

/**
 * RS-{year}-{seq}, identical shape to generateOrderNumber() in
 * cashier/includes/pos_functions.php. Callers must catch a duplicate-key
 * error (SQLSTATE 23000) on insert and retry with a fresh number.
 */
function generateReservationNumber(PDO $db): string
{
    $prefix = 'RS-' . date('Y') . '-';
    $stmt = $db->prepare('SELECT MAX(CAST(SUBSTRING(reservation_number, ?) AS UNSIGNED)) FROM reservations WHERE reservation_number LIKE ?');
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $next = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Translates the SIGNAL text from the reservations triggers
 * (sp_check_reservation_lead_time, sp_check_reservation_min_guests) and
 * sp_check_advance_order_minimum into friendly copy.
 */
function mapReservationTriggerError(PDOException $e, ?array $settings = null): string
{
    $message = $e->getMessage();

    if (str_contains($message, 'minimum lead time')) {
        // Quote the actual requirement when the caller has the settings to
        // hand. Telling someone they missed a minimum without saying what it
        // is leaves them guessing how much later to try -- and this fires at
        // the moment they are trying to pay, which is the worst place to be
        // vague. The number is only omitted when it genuinely isn't available.
        $hours = isset($settings['reservation_min_lead_hours'])
            ? (int)$settings['reservation_min_lead_hours']
            : null;
        return $hours !== null
            ? "Reservations need at least {$hours} hour" . ($hours === 1 ? '' : 's') . ' notice, and this slot is now inside that window. Please choose a later time.'
            : 'This time slot no longer meets the minimum lead time for new reservations. Please choose a later time slot.';
    }
    if (str_contains($message, 'minimum guest count')) {
        $min = isset($settings['reservation_min_guests']) ? (int)$settings['reservation_min_guests'] : null;
        return $min !== null
            ? "Reservations are for {$min} guests or more."
            : "Your guest count doesn't meet the minimum required for a reservation.";
    }
    if (str_contains($message, 'minimum amount allowed')) {
        return 'Your advance order total is below the minimum amount allowed.';
    }

    return 'Something went wrong validating your reservation. Please try again.';
}

/**
 * Shared idempotent finalizer — called by both the synchronous
 * reservation_confirm.php return page AND the (production-only) PayMongo
 * webhook. The compare-and-swap UPDATE (WHERE payment_status='pending') is
 * the atomicity boundary: whichever caller's UPDATE actually matches a row
 * did the real work (status flip + activity log); the other reads back the
 * already-finalized state and renders the same result. No SELECT ... FOR
 * UPDATE needed first — InnoDB's row locking on the UPDATE itself is the
 * compare-and-swap.
 */
function finalizeReservationPayment(PDO $db, int $paymentId, ?string $paymongoPaymentIntentId, ?string $paymongoMethod): array
{
    // Set only by the caller that actually won the status flip, and read after
    // the commit -- see the mail note at the bottom of this function.
    $confirmedReservationId = null;

    $db->beginTransaction();

    $upd = $db->prepare(
        "UPDATE reservation_payments
         SET payment_status = 'paid', amount_paid = amount_due, paid_at = NOW(),
             paymongo_payment_intent_id = COALESCE(?, paymongo_payment_intent_id),
             payment_method = COALESCE(?, payment_method)
         WHERE payment_id = ? AND payment_status = 'pending'"
    );
    $upd->execute([$paymongoPaymentIntentId, $paymongoMethod, $paymentId]);
    $wonRace = $upd->rowCount() === 1;

    if ($wonRace) {
        $db->prepare(
            "UPDATE reservations r
             JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
             SET r.status = 'confirmed'
             WHERE rp.payment_id = ? AND r.status = 'pending'"
        )->execute([$paymentId]);

        $infoStmt = $db->prepare(
            "SELECT r.reservation_id, r.reservation_number, r.customer_id
             FROM reservation_payments rp JOIN reservations r ON r.reservation_id = rp.reservation_id
             WHERE rp.payment_id = ?"
        );
        $infoStmt->execute([$paymentId]);
        $info = $infoStmt->fetch(PDO::FETCH_ASSOC);

        if ($info) {
            try {
                $db->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Reservations', 'Payment confirmed', ?, ?)"
                )->execute([
                    $info['customer_id'],
                    "Payment confirmed for {$info['reservation_number']}",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort.
            }

            // Covers both this function's callers (the synchronous success
            // page AND the PayMongo webhook) with one wired point, since
            // both funnel through here.
            notifyReservationConfirmed($db, (int)$info['customer_id'], (int)$info['reservation_id'], $info['reservation_number']);

            $confirmedReservationId = (int)$info['reservation_id'];
        }
    }

    $db->commit();

    // The confirmation e-ticket goes out AFTER the commit, deliberately.
    //
    // Sending inside the transaction would hold the reservation's row locks
    // open for the length of an SMTP conversation with Gmail -- seconds, on a
    // bad connection -- and a mail failure would then roll back a payment the
    // guest has already made. Out here the booking is durable first and the
    // email is a consequence of it.
    //
    // $wonRace makes this exactly-once without any extra bookkeeping: both the
    // success page and the PayMongo webhook call this function, and PayMongo
    // retries webhooks, but only the one caller whose UPDATE matched a pending
    // row sets $confirmedReservationId. Everyone else falls straight through,
    // so no guest is emailed the same ticket twice.
    if ($confirmedReservationId !== null) {
        try {
            require_once __DIR__ . '/reservation_email.php';
            sendReservationConfirmationEmail($db, $confirmedReservationId);
        } catch (Throwable $e) {
            // Best-effort: the table is booked and paid for either way, and
            // the guest still has the on-screen e-ticket and the in-app
            // notification. A dead SMTP server must not read as a failed
            // reservation.
            error_log('Reservation confirmation email failed: ' . $e->getMessage());
        }
    }

    $stateStmt = $db->prepare(
        "SELECT r.reservation_id, r.reservation_number, r.status AS reservation_status,
                rp.payment_status, rp.amount_due, rp.amount_paid
         FROM reservation_payments rp JOIN reservations r ON r.reservation_id = rp.reservation_id
         WHERE rp.payment_id = ?"
    );
    $stateStmt->execute([$paymentId]);

    return $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Advance-order line items for a reservation, for the receipt/summary display. */
function getReservationAdvanceOrderItems(PDO $db, int $reservationId): array
{
    $stmt = $db->prepare(
        "SELECT rao.advance_order_id, rao.menu_item_id, rao.quantity, rao.unit_price, rao.subtotal, mi.item_name
         FROM reservation_advance_orders rao
         JOIN menu_items mi ON mi.item_id = rao.menu_item_id
         WHERE rao.reservation_id = ?
         ORDER BY rao.advance_order_id"
    );
    $stmt->execute([$reservationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * "60 Mins" from a time_slots row's start_time/end_time (both TIME strings,
 * e.g. "17:00:00"). Used on the e-ticket — the schema has no dedicated
 * duration column, but start/end time already implies one.
 */
function formatSlotDuration(string $startTime, string $endTime): string
{
    $start = DateTime::createFromFormat('H:i:s', $startTime);
    $end   = DateTime::createFromFormat('H:i:s', $endTime);
    $minutes = (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    return $minutes . ' Mins';
}

/**
 * Display label + badge color class for a reservation's raw status, shared
 * between the customer reservations list/modal and (potentially) any future
 * staff-facing reservation views.
 */
function reservationStatusMeta(string $status): array
{
    return match ($status) {
        'pending'   => ['label' => 'Pending Payment', 'class' => 'is-warning'],
        'confirmed' => ['label' => 'Confirmed', 'class' => 'is-success'],
        'seated'    => ['label' => 'Seated', 'class' => 'is-info'],
        'completed' => ['label' => 'Completed', 'class' => 'is-neutral'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'is-danger'],
        'no_show'   => ['label' => 'No-show', 'class' => 'is-danger'],
        default     => ['label' => ucfirst($status), 'class' => 'is-neutral'],
    };
}

/**
 * Display label + badge color class for a reservation_payments row's raw
 * payment_status, shared between the staff reservation panel's detail modal
 * and the printable confirmation document.
 */
function reservationPaymentStatusMeta(string $status): array
{
    return match ($status) {
        'paid'    => ['label' => 'Paid', 'class' => 'is-success'],
        'partial' => ['label' => 'Partially Paid', 'class' => 'is-info'],
        'pending' => ['label' => 'Pending', 'class' => 'is-warning'],
        'failed'  => ['label' => 'Failed', 'class' => 'is-danger'],
        // reservation_payments has no 'voided' value -- this one comes from
        // order_payments, because the owner/manager dashboards reuse this helper
        // for their "today's orders" lists. Without it an approved void fell
        // through to the default and read as "Unpaid", which is a different
        // (and wrong) claim: the money WAS collected, then reversed.
        'voided'  => ['label' => 'Voided', 'class' => 'is-danger'],
        default   => ['label' => 'Unpaid', 'class' => 'is-neutral'],
    };
}

/**
 * Friendly label for a reservation_payments.payment_method value. Stays the
 * generic 'paymongo_checkout' placeholder until an actual payment method is
 * chosen at checkout time, so callers should only display this once
 * payment_status is 'paid' (see finalizeReservationPayment()).
 */
function reservationPaymentMethodLabel(string $method): string
{
    return match ($method) {
        'cash'              => 'Cash',
        'paymongo_gcash'    => 'GCash',
        'paymongo_checkout' => 'Online Checkout',
        default             => ucfirst(str_replace('_', ' ', $method)),
    };
}

/**
 * Customer-themed equivalent of config/flash.php's flash_render() — that
 * one hardcodes owner-alert* classes which don't exist in customer-app.css
 * (a deliberately separate, lighter design system). Same flash_consume()
 * data, ca-alert* markup instead.
 */
function ca_flash_render(): string
{
    $flash = flash_consume();
    if (!$flash) {
        return '';
    }

    $type    = $flash['type'] === 'success' ? 'success' : 'error';
    $icon    = $type === 'success' ? 'ph-check-circle' : 'ph-warning-circle';
    $message = htmlspecialchars($flash['message']);

    return <<<HTML
        <div class="ca-alert ca-alert-{$type}">
            <i class="ph {$icon}" aria-hidden="true"></i>
            <span>{$message}</span>
        </div>
        HTML;
}

/**
 * Builds an absolute URL for PayMongo's success_url/cancel_url. Derived
 * from SCRIPT_NAME rather than a hardcoded base path so it doesn't break
 * outside this exact dev install path — no APP_URL constant exists
 * anywhere in this codebase to rely on instead.
 */
function appUrl(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    // Behind a TLS-terminating tunnel or proxy (ngrok, which this project is
    // tested through on a real phone), the browser's connection is HTTPS but
    // the request reaching Apache is plain HTTP, so $_SERVER['HTTPS'] is
    // unset. Building an http:// return URL from that sends PayMongo a
    // downgraded callback for a payment that started on https. The proxy
    // states the original scheme in X-Forwarded-Proto; honour it.
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if ($forwardedProto !== '') {
        // May be a comma-separated chain ("https, http") -- the client-facing
        // hop is the first entry.
        $first = strtolower(trim(explode(',', $forwardedProto)[0]));
        if ($first === 'https' || $first === 'http') {
            $scheme = $first;
        }
    }

    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptName  = $_SERVER['SCRIPT_NAME'] ?? '';
    $projectRoot = preg_replace('#/customer/.*$#', '', $scriptName);

    // This project's document root contains spaces ("Our Pinoy Original"), and
    // a raw space is not legal in a URL -- PayMongo has been accepting it, but
    // it is not obliged to. Percent-encode each segment while leaving the
    // separators alone. rawurlencode (%20) rather than urlencode (+), since +
    // means a literal plus in a path, not a space.
    $projectRoot = implode('/', array_map('rawurlencode', explode('/', $projectRoot)));

    return $scheme . '://' . $host . $projectRoot . '/' . ltrim($path, '/');
}
