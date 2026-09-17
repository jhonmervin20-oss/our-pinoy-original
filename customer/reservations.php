<?php
/**
 * customer/reservations.php
 *
 * The logged-in customer's reservation history — filterable by raw status
 * tab (Pending / Confirmed / Completed / Cancelled / No-show), same
 * granularity as the staff reservation panel (reservation/reservations.php).
 * Each card links to reservation_details.php for the full breakdown +
 * actions; this page only needs the list-row fields.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/flash.php';
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

$activePage = 'reservations';
$customerId = Session::getUserId();

$pdo = Database::getInstance()->getConnection();

$noShowHours = getReservationSettings($pdo)['reservation_no_show_hours'];
sweepNoShowReservations($pdo, $noShowHours);

$stmt = $pdo->prepare(
    "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.number_of_guests, r.status,
            ts.slot_label
     FROM reservations r
     JOIN time_slots ts ON ts.slot_id = r.slot_id
     WHERE r.customer_id = ?
     ORDER BY r.reservation_date DESC, r.created_at DESC"
);
$stmt->execute([$customerId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Raw-status tabs (Pending/Confirmed/...), matching the staff reservation
// panel (reservation/reservations.php) — not reservationBucket()'s combined
// "Upcoming" grouping, since a customer benefits from telling apart "still
// unpaid" from "paid and coming" too, same reasoning as the staff side.
$bucketCounts = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
foreach ($rows as &$r) {
    $r['bucket'] = $r['status'];
    $bucketCounts[$r['bucket']] = ($bucketCounts[$r['bucket']] ?? 0) + 1;
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservations | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">
<link rel="stylesheet" href="assets/css/reservations.css?v=<?= filemtime(__DIR__ . '/assets/css/reservations.css') ?>">
</head>
<body class="ca-body">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="ca-content">
    <?= ca_flash_render() ?>

    <div class="ca-res-page-head">
        <div>
            <h1 class="ca-welcome-title">My Reservations</h1>
            <p class="ca-welcome-sub">View and track all your table reservations.</p>
        </div>
        
    </div>

    <?php if (empty($rows)): ?>
        <div class="ca-card">
            <div class="ca-empty-state">
                <i class="ph ph-calendar-x" aria-hidden="true"></i>
                No reservations yet.
                <div style="margin-top:16px;">
                    <a href="make_reservation.php" class="ca-btn ca-btn-primary">Book a reservation</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="ca-res-tabs">
            <button type="button" class="ca-res-tab is-active" data-res-tab="all">
                All <span class="ca-res-tab-count"><?= count($rows) ?></span>
            </button>
            <button type="button" class="ca-res-tab" data-res-tab="pending">
                Pending <span class="ca-res-tab-count"><?= $bucketCounts['pending'] ?></span>
            </button>
            <button type="button" class="ca-res-tab" data-res-tab="confirmed">
                Confirmed <span class="ca-res-tab-count"><?= $bucketCounts['confirmed'] ?></span>
            </button>
            <button type="button" class="ca-res-tab" data-res-tab="completed">
                Completed <span class="ca-res-tab-count"><?= $bucketCounts['completed'] ?></span>
            </button>
            <button type="button" class="ca-res-tab" data-res-tab="cancelled">
                Cancelled <span class="ca-res-tab-count"><?= $bucketCounts['cancelled'] ?></span>
            </button>
            <button type="button" class="ca-res-tab" data-res-tab="no_show">
                No-show <span class="ca-res-tab-count"><?= $bucketCounts['no_show'] ?></span>
            </button>
        </div>

        <div class="ca-res-list">
            <?php foreach ($rows as $r): $meta = reservationStatusMeta($r['status']); ?>
                <a href="reservation_details.php?reservation_number=<?= urlencode($r['reservation_number']) ?>" class="ca-res-card" data-bucket="<?= htmlspecialchars($r['bucket']) ?>">
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
                        <span class="ca-res-badge <?= $meta['class'] ?>"><?= htmlspecialchars($meta['label']) ?></span>
                        <i class="ph ph-caret-right ca-res-card-chevron" aria-hidden="true"></i>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="ca-empty-state" id="resEmptyFiltered" hidden>
            <i class="ph ph-funnel-simple" aria-hidden="true"></i>
            No reservations in this category.
        </div>
    <?php endif; ?>
</main>

<script src="assets/js/reservations.js?v=<?= filemtime(__DIR__ . '/assets/js/reservations.js') ?>"></script>

</body>
</html>
