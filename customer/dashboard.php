<?php


require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/location.php';   // mapsDirectionsUrl()
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

$activePage   = 'home';
$customerId   = Session::getUserId();
$customerName = Session::getFullName() ?: 'there';
$firstName    = trim(explode(' ', $customerName)[0]);

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$pdo = Database::getInstance()->getConnection();

$noShowHours = getReservationSettings($pdo)['reservation_no_show_hours'];
sweepNoShowReservations($pdo, $noShowHours);

// Branding/contact display info -- kept separate from
// getReservationSettings() (that's booking-rule settings; this is just
// display copy for the hero card).
$brandRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('restaurant_name','restaurant_address')")->fetchAll(PDO::FETCH_KEY_PAIR);
$restaurantName    = trim((string)($brandRows['restaurant_name'] ?? '')) ?: 'OPO! Our Pinoy Original';
$restaurantAddress = trim((string)($brandRows['restaurant_address'] ?? ''));

// Latest payment row per reservation (a reservation can in principle have
// more than one reservation_payments row over its life -- fee, then a
// later balance payment) -- same "latest wins" rule reservation_details.php
// and reservation_confirm.php each apply with their own single-row lookup.
$stmt = $pdo->prepare(
    "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.number_of_guests, r.status,
            ts.slot_label, ts.start_time,
            rp.payment_status
     FROM reservations r
     JOIN time_slots ts ON ts.slot_id = r.slot_id
     LEFT JOIN reservation_payments rp ON rp.payment_id = (
         SELECT rp2.payment_id FROM reservation_payments rp2
         WHERE rp2.reservation_id = r.reservation_id ORDER BY rp2.payment_id DESC LIMIT 1
     )
     WHERE r.customer_id = ?
     ORDER BY r.reservation_date DESC, r.created_at DESC"
);
$stmt->execute([$customerId]);
$allReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bucketCounts = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
$upcoming = [];
foreach ($allReservations as $r) {
    $bucketCounts[$r['status']] = ($bucketCounts[$r['status']] ?? 0) + 1;
    if (in_array($r['status'], ['pending', 'confirmed'], true) && $r['reservation_date'] >= date('Y-m-d')) {
        $upcoming[] = $r;
    }
}
// Soonest date+time first; a pending hold made minutes ago for tomorrow
// still outranks a confirmed booking three weeks out, which is exactly
// what "soonest first" already gives us without special-casing status.
usort($upcoming, fn($a, $b) => [$a['reservation_date'], $a['start_time']] <=> [$b['reservation_date'], $b['start_time']]);
$nextReservation = $upcoming[0] ?? null;

$recentActivity = array_slice($allReservations, 0, 4);
$hasCompletedVisit = $bucketCounts['completed'] > 0;

// Confirmed reservations grouped by date for the calendar widget -- keyed by
// 'YYYY-MM-DD' so the JS can look up a clicked day directly, no linear scan.
$calendarByDate = [];
foreach ($allReservations as $r) {
    if ($r['status'] !== 'confirmed') {
        continue;
    }
    $meta = reservationStatusMeta($r['status']);
    $calendarByDate[$r['reservation_date']][] = [
        'number'  => $r['reservation_number'],
        'status'  => $r['status'],
        'label'   => $meta['label'],
        'class'   => $meta['class'],
        'time'    => date('g:i A', strtotime($r['start_time'])),
        'guests'  => (int)$r['number_of_guests'],
    ];
}

// "Reminder Sent" is a real signal -- notifyTodayReservations() (see
// includes/reservation_functions.php, wired into navbar.php on every page
// load) inserts exactly this notification once a confirmed reservation's
// date arrives. Checking for it here, rather than inventing a fake status,
// is what keeps the timeline below honest.
$reminderSent = false;
if ($nextReservation) {
    $remStmt = $pdo->prepare(
        "SELECT 1 FROM notifications WHERE user_id = ? AND reference_type = 'reservation_today' AND reference_id = ? LIMIT 1"
    );
    $remStmt->execute([$customerId, $nextReservation['reservation_id']]);
    $reminderSent = (bool)$remStmt->fetchColumn();
}

/** Small icon per status so the badge doesn't rely on color alone. */
function dashStatusIcon(string $status): string
{
    return match ($status) {
        'pending'   => 'ph-hourglass',
        'confirmed' => 'ph-check-circle',
        'completed' => 'ph-flag-checkered',
        'cancelled' => 'ph-x-circle',
        'no_show'   => 'ph-user-circle-minus',
        default     => 'ph-info',
    };
}

/** "Starts in 2h 18m" / "Tomorrow" / "In 3 Days" -- date-based, not a raw hour count, so it matches calendar-day language. */
function formatCountdown(string $date, string $time): ?string
{
    $daysUntil = (int)round((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);

    if ($daysUntil > 1) {
        return "In {$daysUntil} Days";
    }
    if ($daysUntil === 1) {
        return 'Tomorrow';
    }

    $target = strtotime("$date $time");
    $diffMin = (int)floor(($target - time()) / 60);
    if ($diffMin <= 0) {
        return null;
    }
    $h = intdiv($diffMin, 60);
    $m = $diffMin % 60;
    return 'Starts in ' . ($h > 0 ? "{$h}h {$m}m" : "{$m}m");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Home | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">
<link rel="stylesheet" href="assets/css/reservations.css?v=<?= filemtime(__DIR__ . '/assets/css/reservations.css') ?>">
<link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body class="ca-body">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="ca-content">
    <div class="ca-dash-head">
        <h1 class="ca-welcome-title"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?>!</h1>
        <p class="ca-welcome-sub"><?= htmlspecialchars(date('l, F j, Y')) ?></p>
    </div>

    <div class="ca-dash-grid">

        <div class="ca-dash-main">

            <?php if ($nextReservation):
                $nrMeta = reservationStatusMeta($nextReservation['status']);
                $nrIcon = dashStatusIcon($nextReservation['status']);
                $isPending = $nextReservation['status'] === 'pending';
                $isToday = $nextReservation['reservation_date'] === date('Y-m-d');
                $countdown = formatCountdown($nextReservation['reservation_date'], $nextReservation['start_time']);
                // Name first, address second -- see config/location.php. Querying the
                // address alone dropped a bare marker instead of the restaurant's pin.
                $mapsUrl = mapsDirectionsUrl($restaurantAddress);
            ?>
                <div class="ca-hero-res<?= $isPending ? ' is-pending' : '' ?>">
                    <div class="ca-hero-res-top">
                        <span class="ca-hero-res-eyebrow">
                            <?= $isPending ? 'Awaiting Payment' : ($isToday ? "Today's Reservation" : 'Upcoming Reservation') ?>
                        </span>
                        <span class="ca-res-badge <?= $nrMeta['class'] ?>"><i class="ph <?= $nrIcon ?>" aria-hidden="true"></i> <?= htmlspecialchars($nrMeta['label']) ?></span>
                    </div>

                    <div class="ca-hero-res-main">
                        <a class="ca-hero-res-date" href="reservation_details.php?reservation_number=<?= urlencode($nextReservation['reservation_number']) ?>">
                            <span class="day"><?= date('d', strtotime($nextReservation['reservation_date'])) ?></span>
                            <span class="mon"><?= date('M', strtotime($nextReservation['reservation_date'])) ?></span>
                        </a>
                        <div class="ca-hero-res-info">
                            <div class="ca-hero-res-number"><?= htmlspecialchars($nextReservation['reservation_number']) ?></div>
                            <?php if ($mapsUrl): ?>
                                <a class="ca-hero-res-restaurant" href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" rel="noopener">
                                    <i class="ph ph-storefront" aria-hidden="true"></i> <?= htmlspecialchars($restaurantName) ?>
                                    &middot; <?= htmlspecialchars($restaurantAddress) ?>
                                </a>
                            <?php else: ?>
                                <div class="ca-hero-res-restaurant">
                                    <i class="ph ph-storefront" aria-hidden="true"></i> <?= htmlspecialchars($restaurantName) ?>
                                </div>
                            <?php endif; ?>
                            <div class="ca-hero-res-meta">
                                <span><i class="ph ph-clock" aria-hidden="true"></i> <?= htmlspecialchars($nextReservation['slot_label']) ?></span>
                                <span><i class="ph ph-users" aria-hidden="true"></i> <?= (int)$nextReservation['number_of_guests'] ?> guest<?= (int)$nextReservation['number_of_guests'] === 1 ? '' : 's' ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($countdown): ?>
                        <div class="ca-hero-res-countdown" id="heroCountdown" data-date="<?= htmlspecialchars($nextReservation['reservation_date']) ?>" data-target="<?= htmlspecialchars($nextReservation['reservation_date'] . 'T' . $nextReservation['start_time']) ?>">
                            <i class="ph ph-hourglass-medium" aria-hidden="true"></i>
                            <span data-countdown-text><?= htmlspecialchars($countdown) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="ca-hero-res-timeline">
                        <?php
                        $steps = [
                            ['label' => 'Reserved', 'icon' => 'ph-bookmark-simple', 'done' => true],
                            ['label' => 'Confirmed', 'icon' => 'ph-check-circle', 'done' => !$isPending],
                            ['label' => 'Reminder Sent', 'icon' => 'ph-bell-ringing', 'done' => $reminderSent],
                            ['label' => 'Completed', 'icon' => 'ph-flag-checkered', 'done' => false],
                        ];
                        $currentIndex = 0;
                        foreach ($steps as $i => $s) { if ($s['done']) $currentIndex = $i; }
                        foreach ($steps as $i => $s):
                            $state = $s['done'] ? 'is-done' : ($i === $currentIndex + 1 ? 'is-current' : '');
                        ?>
                            <div class="ca-timeline-step <?= $state ?>">
                                <span class="ca-timeline-dot"><i class="ph <?= $s['done'] ? 'ph-check' : $s['icon'] ?>" aria-hidden="true"></i></span>
                                <span class="ca-timeline-label"><?= htmlspecialchars($s['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($isPending): ?>
                        <p class="ca-hero-res-warning">
                            <i class="ph ph-warning-circle" aria-hidden="true"></i>
                            This table is on hold until payment is completed — pay soon to keep it.
                        </p>
                    <?php endif; ?>

                    <div class="ca-hero-res-actions">
                        <?php if ($isPending): ?>
                            <a href="reservation_details.php?reservation_number=<?= urlencode($nextReservation['reservation_number']) ?>" class="ca-btn ca-btn-primary ca-btn-lg">
                                <i class="ph ph-credit-card" aria-hidden="true"></i> Complete Payment
                            </a>
                            <form method="POST" action="api/reservation_cancel.php" data-confirm="Cancel this reservation? Your table hold will be released.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="reservation_number" value="<?= htmlspecialchars($nextReservation['reservation_number']) ?>">
                                <button type="submit" class="ca-btn ca-btn-secondary ca-btn-lg">
                                    <i class="ph ph-x-circle" aria-hidden="true"></i> Cancel Reservation
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="reservation_details.php?reservation_number=<?= urlencode($nextReservation['reservation_number']) ?>" class="ca-btn ca-btn-primary ca-btn-lg">
                                <i class="ph ph-ticket" aria-hidden="true"></i> View Ticket
                            </a>
                            <?php if ($mapsUrl): ?>
                                <a href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" rel="noopener" class="ca-btn ca-btn-secondary ca-btn-lg">
                                    <i class="ph ph-navigation-arrow" aria-hidden="true"></i> Get Directions
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="ca-hero-res-empty">
                    <div class="ca-hero-res-empty-icon"><i class="ph ph-calendar" aria-hidden="true"></i></div>
                    <h2>No upcoming reservations</h2>
                    <p>Book your next dining experience with us.</p>
                    <a href="make_reservation.php" class="ca-btn ca-btn-primary ca-btn-lg">
                         Book now
                    </a>
                </div>
            <?php endif; ?>

            <div class="ca-card ca-recent-card">
                <div class="ca-widget-head">
                    <div class="ca-widget-title"><i class="ph ph-calendar-check" aria-hidden="true"></i> Recent Reservations</div>
                    <a href="reservations.php" class="ca-dash-viewall">View All</a>
                </div>

                <?php if (empty($recentActivity)): ?>
                    <div class="ca-empty-state">
                        <i class="ph ph-calendar-x" aria-hidden="true"></i>
                        No reservations yet.
                        <div><a href="make_reservation.php" class="ca-empty-state-link">Book one now <i class="ph ph-arrow-right" aria-hidden="true"></i></a></div>
                    </div>
                <?php else: ?>
                    <div class="ca-res-list">
                        <?php foreach ($recentActivity as $r): $meta = reservationStatusMeta($r['status']); $icon = dashStatusIcon($r['status']); ?>
                            <a href="reservation_details.php?reservation_number=<?= urlencode($r['reservation_number']) ?>" class="ca-res-card">
                                <div class="ca-res-card-date">
                                    <span class="day"><?= date('d', strtotime($r['reservation_date'])) ?></span>
                                    <span class="mon"><?= date('M', strtotime($r['reservation_date'])) ?></span>
                                </div>
                                <div class="ca-res-card-main">
                                    <div class="ca-res-card-title"><?= htmlspecialchars($r['reservation_number']) ?></div>
                                    <div class="ca-res-card-sub">
                                        <span><i class="ph ph-clock" aria-hidden="true"></i><?= htmlspecialchars($r['slot_label']) ?></span>
                                        <span><i class="ph ph-users" aria-hidden="true"></i><?= (int)$r['number_of_guests'] ?> guest<?= (int)$r['number_of_guests'] === 1 ? '' : 's' ?></span>
                                    </div>
                                </div>
                                <div class="ca-res-card-right">
                                    <span class="ca-res-badge <?= $meta['class'] ?>"><i class="ph <?= $icon ?>" aria-hidden="true"></i> <?= htmlspecialchars($meta['label']) ?></span>
                                    <span class="ca-btn ca-btn-secondary ca-btn-sm">View Details</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <a href="feedback.php" class="ca-card ca-feedback-nudge">
                <div class="ca-feedback-nudge-stars" aria-hidden="true">
                    <i class="ph ph-star"></i><i class="ph ph-star"></i><i class="ph ph-star"></i><i class="ph ph-star"></i><i class="ph ph-star"></i>
                </div>
                <div class="ca-feedback-nudge-title">How was your visit?</div>
                <p class="ca-feedback-nudge-sub">Help us improve by sharing your dining experience.</p>
                <span class="ca-btn ca-btn-primary">Leave Feedback</span>
            </a>

        </div>

        <div class="ca-dash-side">
            <div class="ca-card ca-calendar-card">
                <div class="ca-widget-head">
                    <div class="ca-widget-title"><i class="ph ph-calendar-blank" aria-hidden="true"></i> Calendar</div>
                    <a href="reservations.php" class="ca-dash-viewall">View All</a>
                </div>
                <div class="ca-calendar-nav">
                    <button type="button" class="ca-calendar-nav-btn" id="calPrevBtn" aria-label="Previous month"><i class="ph ph-caret-left" aria-hidden="true"></i></button>
                    <span class="ca-calendar-month-label" id="calMonthLabel"></span>
                    <button type="button" class="ca-calendar-nav-btn" id="calNextBtn" aria-label="Next month"><i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
                <div class="ca-calendar-weekdays">
                    <span>SU</span><span>MO</span><span>TU</span><span>WE</span><span>TH</span><span>FR</span><span>SA</span>
                </div>
                <div class="ca-calendar-grid" id="calGrid"></div>
                <div class="ca-calendar-detail" id="calDetail"></div>
            </div>
        </div>

    </div>
</main>

<script>
window.CUSTOMER_RESERVATIONS_BY_DATE = <?= json_encode($calendarByDate) ?>;
</script>
<script src="assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/confirm-modal.js') ?>"></script>
<script src="assets/js/dashboard.js?v=<?= filemtime(__DIR__ . '/assets/js/dashboard.js') ?>"></script>
<script src="assets/js/dashboard-calendar.js?v=<?= filemtime(__DIR__ . '/assets/js/dashboard-calendar.js') ?>"></script>

</body>
</html>
