<?php
/**
 * admin/dashboard.php
 *
 * Landing page for the Admin panel: at-a-glance account counts, how those
 * accounts are distributed across roles, and the most recent activity log
 * entries.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['admin'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'dashboard';
$pageTitle  = 'Dashboard';

$totalUsers     = 0;
$staffCount     = 0;
$customerCount  = 0;
$employeeCount  = 0;
$recentActivity = [];
$roleCounts     = [];
$dbError        = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    $customerCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name = 'customer'"
    )->fetchColumn();

    $staffCount = $totalUsers - $customerCount;

    // LEFT JOIN, not INNER: a role with zero accounts is a real and useful
    // reading of the distribution, and dropping its bar would silently
    // misrepresent the chart as "these are all the roles that exist".
    $roleCounts = $pdo->query(
        "SELECT r.role_name, COUNT(u.user_id) AS total
         FROM roles r
         LEFT JOIN users u ON u.role_id = r.role_id
         GROUP BY r.role_id, r.role_name
         ORDER BY r.role_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    // is_active, matching employee_management/employees.php's own headline
    // count -- an archived employee is off the roster, not a smaller roster.
    $employeeCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM employees WHERE is_active = 1"
    )->fetchColumn();

    $recentActivity = $pdo->query(
        "SELECT l.log_id, l.module, l.action, l.description, l.created_at,
                u.first_name, u.last_name
         FROM activity_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         ORDER BY l.created_at DESC
         LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Couldn't load live dashboard data. Run the schema migration, then refresh this page.";
}

/* One colour for every bar, in the panel's own gold. This used to be a
   five-colour map (red/gold/blue/teal/brown), which read as five categories
   that mean something different from each other -- they don't: it is one
   series, "accounts", and the y-axis already names each role. A single hue
   also stops the chart fighting the rest of the panel for attention. */
const ROLE_BAR_COLOUR = '#9c7734';   // --op-gold

$roleLabels  = array_map(fn($r) => ucfirst($r['role_name']), $roleCounts);
$roleTotals  = array_map(fn($r) => (int)$r['total'], $roleCounts);
$hasRoleData = array_sum($roleTotals) > 0;

/**
 * "CFO Owner" -> "CO". Falls back to a single letter, and to a dash for the
 * System actor so an unattributed entry never borrows a person's monogram.
 */
function activityInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
        return '&mdash;';
    }
    if (count($parts) === 1) {
        return htmlspecialchars(mb_strtoupper(mb_substr($parts[0], 0, 1)));
    }
    return htmlspecialchars(mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Admin Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
    /* Page-scoped rather than added to owner-panel.css -- that file has five
       separate copies across module folders and these two cards exist only
       here. Same reasoning as admin/users.php's password-field styles. */
    /* Both widgets share ONE fixed height so the band reads as a matrix and,
       more importantly, fits a laptop viewport without scrolling. Letting the
       cards size to their content made the pair as tall as an 8-entry feed and
       pushed half the chart below the fold. Content taller than the box scrolls
       inside it -- same approach manager/dashboard.php already uses. */
    :root{ --adm-widget-h: 420px; }
    .adm-two-col{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:20px; align-items:stretch; }
    .adm-two-col > .owner-card{
        /* 420px where there's room, but never taller than the space actually
           left below the summary strip -- measured, a fixed 420 still scrolled
           a 1280x720 laptop by ~44px. dvh not vh: vh ignores mobile browser
           chrome and would reintroduce the overflow on a phone. */
        height:var(--adm-widget-h);
        height:clamp(260px, calc(100dvh - 280px), var(--adm-widget-h));
        box-sizing:border-box;
        display:flex; flex-direction:column;
        /* owner-panel.css gives `.owner-card + .owner-card` a 20px margin-top for
           stacked cards. Inside this grid that hits the SECOND card only, dropping
           the right column 20px below the left -- same height, never level. */
        margin-top:0; margin-bottom:0;
    }
    /* The heading stays put; only what follows it scrolls. Without min-height:0 a
       flex child refuses to shrink below its content, so the box would grow and
       the fixed height would do nothing. */
    .adm-two-col > .owner-card > .owner-card-head,
    .adm-two-col > .owner-card > .adm-feed-head{ flex:0 0 auto; }
    .adm-widget-body{ flex:1 1 auto; min-height:0; min-width:0; overflow-y:auto; display:flex; flex-direction:column; }
    /* The chart takes whatever the heading leaves rather than claiming a fixed
       height of its own, so a wrapped subtitle can't overflow the widget and put
       a scrollbar on a graph. */
    .adm-widget-body > .adm-chart-wrap{ position:relative; flex:1 1 auto; height:auto; min-height:0; min-width:0; margin-top:4px; }

    /* .owner-summary-card is flex-wrap:wrap, so the one card with a wide meta
       line ("admin, owner, manager, cashier") pushes its icon onto a second
       row while its three neighbours keep theirs inline. Same fix manager/
       dashboard.php already documents: top-align so every number shares a
       baseline, equal heights, and never wrap the icon. */
    .owner-form-grid > .owner-summary-card{
        height:100%; box-sizing:border-box;
        align-items:flex-start; flex-wrap:nowrap; margin-bottom:0;
    }
    .owner-form-grid > .owner-summary-card > div{ min-width:0; }
    /* Same bare-icon treatment manager/dashboard.php's dashboardKpiCard()
       helper uses (font-size:1.6rem, no fixed box) -- kept identical so the
       two panels' KPI strips render at the same size and proportions. */
    .owner-form-grid > .owner-summary-card > i{
        flex-shrink:0;
        font-size:1.6rem;
        color:var(--op-gold);
    }
    .adm-empty{ display:flex; flex:1 1 auto; flex-direction:column; align-items:center; justify-content:center;
                gap:8px; color:var(--op-ink-faint); font-size:0.85rem; }
    .adm-empty i{ font-size:1.7rem; }

    /* Legend sits below the plot and never scrolls away with it. Text labels
       carry the identity, so the colours are reinforcement rather than the only
       way to read the chart. */
    .adm-legend{ flex:0 0 auto; list-style:none; display:flex; flex-wrap:wrap; gap:8px 16px;
                 margin:12px 0 0; padding:10px 0 0; border-top:1px solid var(--op-border-soft); }
    .adm-legend-item{ display:inline-flex; align-items:center; gap:7px; font-size:0.78rem; color:var(--op-ink-soft); }
    .adm-legend-value{ font-weight:600; color:var(--op-ink); }

    /* Recent activity feed */
    .adm-feed-head{ display:flex; align-items:flex-start; gap:14px; margin-bottom:6px; }
    .adm-feed-icon{ flex-shrink:0; display:flex; align-items:center; justify-content:center;
                    width:44px; height:44px; border-radius:12px;
                    background:var(--op-gold-soft); color:var(--op-gold); font-size:1.35rem; }
    .adm-feed-head-text{ flex:1; min-width:0; }
    .adm-feed-list{ list-style:none; margin:0; padding:0; }
    .adm-feed-item{ display:flex; align-items:center; gap:13px; padding:11px 4px 11px 0;
                    border-top:1px solid var(--op-border-soft); border-radius:6px; }
    .adm-feed-item:first-child{ border-top:0; }
    .adm-feed-item:hover{ background:var(--op-gold-soft); }
    .adm-feed-avatar{ flex-shrink:0; display:flex; align-items:center; justify-content:center;
                      width:38px; height:38px; border-radius:50%;
                      background:var(--op-gold); color:#fff;
                      font-size:0.78rem; font-weight:600; letter-spacing:0.02em; }
    /* An unattributed entry is a different kind of thing from a person's
       action, so it gets a neutral chip instead of a person's gold one. */
    .adm-feed-avatar.is-system{ background:var(--op-canvas); color:var(--op-ink-faint); border:1px solid var(--op-border); }
    .adm-feed-body{ flex:1; min-width:0; font-size:0.88rem; color:var(--op-ink); line-height:1.45; }
    .adm-feed-actor{ color:var(--op-ink-soft); }
    .adm-feed-action{ font-weight:600; color:var(--op-ink); }
    .adm-feed-desc{ display:block; margin-top:2px; font-size:0.78rem; color:var(--op-ink-faint);
                    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .adm-feed-time{ flex-shrink:0; font-size:0.8rem; color:var(--op-ink-faint); white-space:nowrap; }

    @media (max-width: 1000px){
        /* minmax(0,1fr), not 1fr. A bare 1fr is minmax(auto,1fr), and `auto`
           refuses to shrink below the content -- so the chart canvas held the
           column open and the card ran off the right of the screen below
           ~520px. Same reason .adm-chart-wrap needs min-width:0 below. */
        .adm-two-col{ grid-template-columns:minmax(0,1fr); }
        .adm-feed-time{ display:none; }
    }
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

            <!-- Summary cards -->
            <div class="owner-form-grid" style="margin-bottom:20px;">
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Total users</div>
                        <div class="owner-summary-card-value"><?= (int)$totalUsers ?></div>
                        <div class="owner-summary-card-meta">staff and customers combined</div>
                    </div>
                    <i class="ph ph-users" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Staff accounts</div>
                        <div class="owner-summary-card-value"><?= (int)$staffCount ?></div>
                        <div class="owner-summary-card-meta">admin, owner, manager, cashier</div>
                    </div>
                    <i class="ph ph-identification-badge" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Customers</div>
                        <div class="owner-summary-card-value"><?= (int)$customerCount ?></div>
                        <div class="owner-summary-card-meta">with a registered account</div>
                    </div>
                    <i class="ph ph-user-circle" aria-hidden="true"></i>
                </div>
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Total employees</div>
                        <div class="owner-summary-card-value"><?= (int)$employeeCount ?></div>
                        <div class="owner-summary-card-meta">on the active roster</div>
                    </div>
                    <i class="ph ph-users-three" aria-hidden="true"></i>
                </div>
            </div>

            <div class="adm-two-col">

                <!-- Account distribution -->
                <div class="owner-card">
                    <div class="owner-card-head">
                        <div>
                            <h2 class="owner-card-title">Account distribution</h2>
                            <span class="owner-card-subtitle">Accounts per role &middot; <?= (int)$totalUsers ?> total</span>
                        </div>
                    </div>
                    <div class="adm-widget-body">
                        <?php if (!$hasRoleData): ?>
                            <div class="adm-empty">
                                <i class="ph ph-chart-bar" aria-hidden="true"></i>
                                <span>No user accounts yet.</span>
                            </div>
                        <?php else: ?>
                            <div class="adm-chart-wrap"><canvas id="admRoleChart"></canvas></div>
                            <?php /* No colour dots any more: with one bar colour they would
                                     be five identical swatches pretending to be a key, and
                                     a key that maps every label to the same colour tells
                                     the reader nothing. Role and count stay -- they read as
                                     a compact summary under the chart. */ ?>
                            <ul class="adm-legend">
                                <?php foreach ($roleCounts as $r): ?>
                                <li class="adm-legend-item">
                                    <span class="adm-legend-label"><?= htmlspecialchars(ucfirst($r['role_name'])) ?></span>
                                    <span class="adm-legend-value"><?= (int)$r['total'] ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <table class="owner-table" hidden>
                                <caption>Account distribution by role</caption>
                                <thead><tr><th>Role</th><th>Accounts</th></tr></thead>
                                <tbody>
                                    <?php foreach ($roleCounts as $r): ?>
                                    <tr><td><?= htmlspecialchars(ucfirst($r['role_name'])) ?></td><td><?= (int)$r['total'] ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent activity -->
                <div class="owner-card">
                    <div class="adm-feed-head">
                        <div class="adm-feed-head-text">
                            <h2 class="owner-card-title">Recent activity</h2>
                            <span class="owner-card-subtitle">Latest actions across the system</span>
                        </div>
                        <a href="activity_logs.php" class="owner-btn owner-btn-secondary owner-btn-sm">
                            View all
                        </a>
                    </div>

                    <div class="adm-widget-body">
                    <?php if (empty($recentActivity)): ?>
                        <div class="adm-empty">
                            <i class="ph ph-tray" aria-hidden="true"></i>
                            <span>No activity recorded yet.</span>
                        </div>
                    <?php else: ?>
                        <ul class="adm-feed-list">
                            <?php foreach ($recentActivity as $log):
                                $userName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                                $isSystem = ($userName === '');
                                $display  = $isSystem ? 'System' : $userName;
                            ?>
                            <li class="adm-feed-item">
                                <span class="adm-feed-avatar<?= $isSystem ? ' is-system' : '' ?>" aria-hidden="true"><?= $isSystem ? '<i class="ph ph-gear"></i>' : activityInitials($display) ?></span>
                                <span class="adm-feed-body">
                                    <span class="adm-feed-actor"><?= htmlspecialchars($display) ?></span>
                                    <span class="adm-feed-action"><?= htmlspecialchars($log['action']) ?></span>
                                    <?php if (!empty($log['description'])): ?>
                                        <span class="adm-feed-desc" title="<?= htmlspecialchars($log['description']) ?>"><?= htmlspecialchars($log['description']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="adm-feed-time"><?= htmlspecialchars(date('M j, g:i A', strtotime($log['created_at']))) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    </div>
                </div>

            </div>

        </main>

    </div>

</div>

<?php if ($hasRoleData): ?>
<script>
(function () {
    const el = document.getElementById('admRoleChart');
    if (!el || typeof Chart === 'undefined') return;

    const labels = <?= json_encode($roleLabels) ?>;
    const values = <?= json_encode($roleTotals) ?>;
    const barColour = <?= json_encode(ROLE_BAR_COLOUR) ?>;

    const valueLabels = {
        id: 'valueLabels',
        afterDatasetsDraw(chart) {
            const { ctx } = chart;
            ctx.save();
            ctx.font = '600 12px Poppins, sans-serif';
            ctx.fillStyle = '#6f6355';
            ctx.textBaseline = 'middle';
            chart.getDatasetMeta(0).data.forEach((bar, i) => {
                ctx.fillText(values[i].toLocaleString('en-PH'), bar.x + 8, bar.y);
            });
            ctx.restore();
        }
    };

    new Chart(el, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Accounts',
                data: values,
                backgroundColor: barColour,
                hoverBackgroundColor: barColour,
                // Rounded on all four corners, like a pill lying on the axis.
                // Chart.js clamps the radius to half the bar's height, so short
                // bars stay proportionate instead of turning into circles.
                borderRadius: 8,
                borderSkipped: false,
                // Proportional, not a fixed pixel thickness: this card stretches
                // to match the activity feed beside it, and fixed-width bars left
                // five hairlines floating in a tall empty box. Capped so a short
                // card can't turn them into slabs either.
                categoryPercentage: 0.9,
                barPercentage: 0.82,
                maxBarThickness: 58
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { right: 28 } },
            plugins: {
                // Single series -- the card title already names it.
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ' ' + Number(ctx.parsed.x).toLocaleString('en-PH')
                            + (Number(ctx.parsed.x) === 1 ? ' account' : ' accounts')
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { precision: 0, color: '#a39a8c' },
                    grid: { color: '#f1ece0' },
                    border: { display: false }
                },
                y: {
                    ticks: { color: '#6f6355', font: { size: 12 } },
                    grid: { display: false },
                    border: { display: false }
                }
            }
        },
        plugins: [valueLabels]
    });
})();
</script>
<?php endif; ?>

</body>
</html>
