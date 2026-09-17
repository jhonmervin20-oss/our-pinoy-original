<?php
/**
 * customer/api/reservation_availability.php
 *
 * AJAX GET endpoint — one of this app's two deliberate exceptions to its
 * otherwise-universal POST-redirect-GET convention (the other being
 * cashier/pos.js). Only availability genuinely needs a live round-trip:
 * another customer could book the selected slot while this one is still
 * browsing, and the wizard polls this endpoint periodically for exactly
 * that reason.
 *
 * ?month=YYYY-MM -> per-day closed/availability summary (calendar)
 * ?date=YYYY-MM-DD -> per-slot remaining capacity + lead-time state
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../includes/reservation_functions.php';

Session::start();

header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['customer'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in to check availability.']);
    exit;
}

$pdo = Database::getInstance()->getConnection();

try {
    $settings = getReservationSettings($pdo);
    sweepExpiredReservationHolds($pdo, $settings['reservation_hold_minutes']);

    $activeSlots = getActiveTimeSlots($pdo);
    $dbNow = getDbNow($pdo);

    $month = trim((string)($_GET['month'] ?? ''));
    $date  = trim((string)($_GET['date'] ?? ''));

    if ($date !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date.']);
            exit;
        }

        $weekday = (int)date('N', strtotime($date));
        $blackouts = getBlackoutDates($pdo, $date, $date);
        $maxDate = (new DateTime($dbNow['today']))->modify('+' . $settings['reservation_max_advance_days'] . ' days')->format('Y-m-d');
        $isPast = $date < $dbNow['today'];
        $isTooFarAhead = $date > $maxDate;
        $isClosedWeekday = !in_array($weekday, $settings['operating_days'], true);
        $isClosed = $isPast || $isTooFarAhead || !empty($blackouts) || $isClosedWeekday;

        $reason = null;
        if ($isPast) { $reason = 'past'; }
        elseif ($isTooFarAhead) { $reason = 'too_far_ahead'; }
        elseif (!empty($blackouts)) { $reason = 'blackout'; }
        elseif ($isClosedWeekday) { $reason = 'closed_weekday'; }

        $slots = $isClosed ? [] : getSlotAvailabilityForDate($pdo, $date, $activeSlots, $settings, $dbNow);

        echo json_encode([
            'date'    => $date,
            'closed'  => $isClosed,
            'reason'  => $reason,
            // Sent so the slot grid can name the actual requirement instead of
            // saying "minimum lead time requirement", which tells a customer a
            // rule exists without telling them what it is -- leaving them to
            // guess how much later to try.
            'min_lead_hours' => (int)$settings['reservation_min_lead_hours'],
            'slots'   => $slots,
        ]);
        exit;
    }

    if ($month !== '') {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid month.']);
            exit;
        }

        echo json_encode([
            'month' => $month,
            'days'  => getMonthAvailabilitySummary($pdo, $month, $activeSlots, $settings, $dbNow),
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Provide either ?month= or ?date=.']);
} catch (Throwable $e) {
    error_log('reservation_availability.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong loading availability. Please try again.']);
}
