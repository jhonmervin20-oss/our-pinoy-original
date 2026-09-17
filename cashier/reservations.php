<?php
/**
 * cashier/reservations.php
 *
 * Cashier-only, read-only view of today's CONFIRMED reservations -- so a
 * cashier can see who to expect without needing owner/manager access to
 * the full reservation/reservations.php management screen (status changes,
 * payments, cancellations, other dates -- none of that lives here).
 * Cashier-only, matching remittance_report.php's own precedent (Owner has
 * no standing reason to land here; the full reservations screen already
 * covers Owner/Manager).
 *
 * "Today" is resolved from the DB's own CURDATE() via getDbNow(), the same
 * timezone-safe convention reservation/reservations.php itself uses --
 * never PHP's date(), which would drift on a misconfigured server tz.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/display_helpers.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../customer/includes/reservation_functions.php'; // getDbNow()

Session::start();
if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['cashier'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$activePage = 'reservations';

$restaurantName = $db->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
)->fetchColumn() ?: 'OPO! Our Pinoy Original';

$dbError = null;
$reservations = [];

try {
    $today = getDbNow($db)['today'];

    $stmt = $db->prepare(
        "SELECT r.reservation_id, r.reservation_number, r.number_of_guests,
                ts.slot_label, ts.start_time, ts.end_time,
                u.first_name, u.last_name, u.phone
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         JOIN users u ON u.user_id = r.customer_id
         WHERE r.reservation_date = ? AND r.status = 'confirmed'
         ORDER BY ts.start_time ASC"
    );
    $stmt->execute([$today]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('cashier/reservations.php failed: ' . $e->getMessage());
    $dbError = "Couldn't load today's reservations. Please refresh this page.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservations | <?= htmlspecialchars($restaurantName) ?></title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/pos.css?v=<?= filemtime(__DIR__ . '/assets/css/pos.css') ?>">
</head>
<body>

<div class="pos-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="pos-content">

        <button type="button" class="pos-icon-btn pos-menu-toggle" id="posMenuToggle" aria-label="Open menu">
            <i class="ph ph-list" aria-hidden="true"></i>
        </button>

        <?= flash_render() ?>

        <?php if ($dbError): ?>
            <div class="pos-report-alert">
                <i class="ph ph-warning-circle" aria-hidden="true"></i>
                <span><?= htmlspecialchars($dbError) ?></span>
            </div>
        <?php endif; ?>

        <main class="pos-report-main">

            <div class="pos-report-card">
                <div class="pos-report-card-head">
                    <div>
                        <h2 class="pos-report-card-title">Today's Confirmed Reservations</h2>
                        <p style="margin:2px 0 0;font-size:0.85rem;color:var(--op-ink-faint);">
                            <?= htmlspecialchars(date('l, F j, Y', strtotime($today ?? 'today'))) ?>
                            &middot; <?= count($reservations) ?> reservation<?= count($reservations) === 1 ? '' : 's' ?>
                        </p>
                    </div>
                </div>
                <div class="pos-report-table-wrap">
                    <table class="pos-report-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Reservation #</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th class="num">Guests</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reservations)): ?>
                                <tr><td colspan="5" class="pos-report-empty-row">No confirmed reservations for today.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reservations as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['slot_label'] ?: date('g:i A', strtotime($r['start_time'])) . '&ndash;' . date('g:i A', strtotime($r['end_time']))) ?></td>
                                        <td><?= htmlspecialchars($r['reservation_number']) ?></td>
                                        <td><?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
                                        <td><?= htmlspecialchars(phoneOrNa($r['phone'])) ?></td>
                                        <td class="num"><?= (int)$r['number_of_guests'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>

    </div>

</div>

<script>
(function () {
    const menuToggle = document.getElementById('posMenuToggle');
    const sidebar = document.getElementById('posSidebar');
    const backdrop = document.getElementById('posSidebarBackdrop');
    if (menuToggle && sidebar && backdrop) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('is-open');
            backdrop.classList.toggle('is-visible');
        });
        backdrop.addEventListener('click', () => {
            sidebar.classList.remove('is-open');
            backdrop.classList.remove('is-visible');
        });
    }
})();
</script>
</body>
</html>
