<?php
/**
 * admin/activity_logs.php
 *
 * Read-only audit trail view over the `activity_logs` table: who did what,
 * in which module, and when.
 *
 * Filtering is split deliberately. The date range and module go through SQL so
 * the query returns exactly the entries asked for and the print view can honour
 * the same range; the free-text search stays client-side, refining what's already
 * loaded. Every matching row is returned -- there is no row cap, so a date filter
 * can never report "no entries" for a month that genuinely has them.
 *
 * ?print=1 renders a self-contained print document honouring the same
 * server-side filters, the pattern established by orders/orders.php and
 * owner/report.php. It cannot reuse the normal layout: owner-panel.css's
 * `@media print` block hides everything outside an open modal, so printing
 * the regular page produces a blank sheet.
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

$activePage = 'activity_logs';
$pageTitle  = 'Activity logs';

/** A GET date param is only honoured if it is a real Y-m-d calendar date. */
function activityDateParam(string $key): string
{
    $raw = trim((string)($_GET[$key] ?? ''));
    if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return '';
    }
    [$y, $m, $d] = array_map('intval', explode('-', $raw));
    return checkdate($m, $d, $y) ? $raw : '';
}

$fromDate = activityDateParam('from');
$toDate   = activityDateParam('to');

// A backwards range is a typo, not a query -- swap rather than return nothing.
if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$moduleFilter = trim((string)($_GET['module'] ?? ''));
$isPrint      = ($_GET['print'] ?? '') === '1';

$logs           = [];
$modules        = [];
$matchingTotal  = 0;
$restaurantName = 'OPO! Our Pinoy Original';
$dbError        = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $where  = [];
    $params = [];
    if ($fromDate !== '') {
        $where[]  = 'l.created_at >= ?';
        $params[] = $fromDate . ' 00:00:00';
    }
    if ($toDate !== '') {
        $where[]  = 'l.created_at <= ?';
        $params[] = $toDate . ' 23:59:59';
    }
    if ($moduleFilter !== '') {
        $where[]  = 'l.module = ?';
        $params[] = $moduleFilter;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare(
        "SELECT l.log_id, l.module, l.action, l.description, l.created_at,
                u.first_name, u.last_name
         FROM activity_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         {$whereSql}
         ORDER BY l.created_at DESC"
    );
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Same WHERE, counted in SQL: used by the print document's header so the
    // total doesn't depend on counting the rendered rows.
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs l {$whereSql}");
    $countStmt->execute($params);
    $matchingTotal = (int)$countStmt->fetchColumn();

    $modules = $pdo->query(
        "SELECT DISTINCT module FROM activity_logs ORDER BY module"
    )->fetchAll(PDO::FETCH_COLUMN);

    $name = $pdo->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name'"
    )->fetchColumn();
    if (is_string($name) && trim($name) !== '') {
        $restaurantName = trim($name);
    }
} catch (PDOException $e) {
    $dbError = "Couldn't load live activity log data. Run the schema migration, then refresh this page.";
}

$totalLogs = count($logs);

/** Human-readable one-liner describing the active server-side filters. */
function activityFilterSummary(string $from, string $to, string $module): string
{
    if ($from !== '' && $to !== '') {
        $dates = date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
    } elseif ($from !== '') {
        $dates = 'From ' . date('M j, Y', strtotime($from));
    } elseif ($to !== '') {
        $dates = 'Up to ' . date('M j, Y', strtotime($to));
    } else {
        $dates = 'All dates';
    }

    return $dates . ' · ' . ($module !== '' ? $module : 'All modules');
}

$filterSummary = activityFilterSummary($fromDate, $toDate, $moduleFilter);

/** Query string carrying the server-side filters onto the print view. */
$filterQs = http_build_query(array_filter([
    'from'   => $fromDate,
    'to'     => $toDate,
    'module' => $moduleFilter,
], fn($v) => $v !== ''));

/* ---------------------------------------------------------------------
 * Print document -- self-contained, never the panel layout.
 * ------------------------------------------------------------------- */
if ($isPrint && !$dbError):
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity Logs | <?= htmlspecialchars($restaurantName) ?></title>
<style>
    @page{ size:A4 landscape; margin:12mm; }
    body{ font-family:"DejaVu Sans", Helvetica, Arial, sans-serif; color:#000; font-size:12px; margin:0; padding:0; background:#f6f3ec; }
    .doc{ max-width:1040px; margin:24px auto; padding:32px; background:#fff; border:1px solid #ddd; }
    .doc-header{ width:100%; border-collapse:collapse; margin-bottom:14px; }
    .doc-header td{ vertical-align:top; padding:0; }
    h1{ font-size:20px; margin:0 0 4px; }
    .muted{ color:#555; }
    .divider{ border-top:1px solid #000; margin:14px 0; }
    table.doc-table{ width:100%; border-collapse:collapse; margin-top:6px; }
    table.doc-table th, table.doc-table td{ border:1px solid #000; padding:6px 8px; font-size:11px; text-align:left; vertical-align:top; }
    table.doc-table th{ background:#eee; }
    table.doc-kpi-table{ width:auto; min-width:340px; }
    table.doc-kpi-table td{ font-size:12px; }
    .empty-note{ font-size:11px; color:#555; font-style:italic; margin:6px 0; }
    .footer{ margin-top:24px; font-size:10px; color:#555; }
    .pdf-actions{ max-width:1040px; margin:14px auto 24px; display:flex; justify-content:center; gap:10px; }
    .pdf-actions button, .pdf-actions a{ display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:8px; border:1px solid #ddd; background:#fff; color:#000; font-family:sans-serif; font-size:0.85rem; font-weight:600; cursor:pointer; text-decoration:none; }
    .pdf-actions .primary{ background:#9c7734; border-color:#9c7734; color:#fff; }
    @media print{ .pdf-actions{ display:none !important; } body{ background:#fff; } .doc{ border:none; margin:0; max-width:none; } }
</style>
</head>
<body>
<div class="doc">
    <table class="doc-header">
        <tr>
            <td>
                <h1><?= htmlspecialchars($restaurantName) ?></h1>
                <div class="muted">System Activity Log</div>
            </td>
            <td style="text-align:right;">
                <div><strong><?= htmlspecialchars($filterSummary) ?></strong></div>
                <div class="muted" style="margin-top:4px;">Printed <?= htmlspecialchars(date('M j, Y g:i A')) ?></div>
            </td>
        </tr>
    </table>
    <div class="divider"></div>

    <table class="doc-table doc-kpi-table">
        <tr><td>Entries</td><td><strong><?= number_format($matchingTotal) ?></strong></td></tr>
    </table>

    <h1 style="font-size:14px;margin:22px 0 8px;border-bottom:1px solid #000;padding-bottom:4px;">Entries</h1>
    <?php if (empty($logs)): ?>
        <div class="empty-note">No activity matches these filters.</div>
    <?php else: ?>
        <table class="doc-table">
            <thead>
                <tr>
                    <th style="width:130px;">When</th>
                    <th style="width:120px;">User</th>
                    <th style="width:120px;">Module</th>
                    <th style="width:150px;">Action</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log):
                    $userName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'System';
                ?>
                <tr>
                    <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                    <td><?= htmlspecialchars($userName) ?></td>
                    <td><?= htmlspecialchars($log['module']) ?></td>
                    <td><?= htmlspecialchars($log['action']) ?></td>
                    <td><?= htmlspecialchars($log['description'] ?? '') ?: '&mdash;' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="footer">Generated from the <?= htmlspecialchars($restaurantName) ?> admin panel &middot; Activity logs</div>
</div>

<div class="pdf-actions">
    <button type="button" class="primary" onclick="window.print()">Print</button>
    <a href="activity_logs.php<?= $filterQs !== '' ? '?' . htmlspecialchars($filterQs) : '' ?>">Back to activity logs</a>
</div>
</body>
</html>
<?php
    exit;
endif;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity logs | Admin Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
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

            <?php /* Page actions + filters sit in their own card above the
                     table -- same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <form method="GET" action="activity_logs.php" class="owner-inv-filters" style="margin:0;" data-autosubmit="logs">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="logFilterSearch" placeholder="Search user, action, description&hellip;" autocomplete="off">
                    </div>
                    <input type="date" name="from" class="owner-input" style="max-width:175px;" value="<?= htmlspecialchars($fromDate) ?>" aria-label="From date">
                    <input type="date" name="to" class="owner-input" style="max-width:175px;" value="<?= htmlspecialchars($toDate) ?>" aria-label="To date">
                    <select name="module" class="owner-select">
                        <option value="">All modules</option>
                        <?php foreach ($modules as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>" <?= $moduleFilter === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="activity_logs.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                    <a href="activity_logs.php?print=1<?= $filterQs !== '' ? '&amp;' . htmlspecialchars($filterQs) : '' ?>" target="_blank" rel="noopener" class="owner-btn owner-btn-secondary" style="margin-left:auto;">
                        <i class="ph ph-printer" aria-hidden="true"></i> Print
                    </a>
                </form>
            </div>

            <div class="owner-card">
                <?php if (isset($_GET['log_id'])): ?>
                <div class="owner-alert" style="background:var(--op-canvas);color:var(--op-ink-soft);">
                    <i class="ph ph-funnel" aria-hidden="true"></i>
                    <span>Showing the entry from your notification.</span>
                    <a href="activity_logs.php" class="owner-btn owner-btn-secondary owner-btn-sm" style="margin-left:auto;">Show all</a>
                </div>
                <?php endif; ?>

                <div class="owner-table-wrap">
                    <table class="owner-table" style="table-layout:fixed;">
                        <colgroup>
                            <col style="width:160px;">
                            <col style="width:150px;">
                            <col style="width:130px;">
                            <col style="width:220px;">
                            <col>
                        </colgroup>
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>User</th>
                                <th>Module</th>
                                <th>Action</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="5" class="owner-table-empty">No activity matches these filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log):
                                    $userName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'System';
                                ?>
                                <tr data-log-id="<?= (int)$log['log_id'] ?>" data-search="<?= htmlspecialchars(strtolower($userName . ' ' . $log['action'] . ' ' . ($log['description'] ?? ''))) ?>" style="height:64px;">
                                    <td style="white-space:nowrap;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                                    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($userName) ?>"><?= htmlspecialchars($userName) ?></td>
                                    <td><span class="owner-status-pill is-active"><?= htmlspecialchars($log['module']) ?></span></td>
                                    <td title="<?= htmlspecialchars($log['action']) ?>"><div style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;"><?= htmlspecialchars($log['action']) ?></div></td>
                                    <td title="<?= htmlspecialchars($log['description'] ?? '') ?>"><div style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;"><?= htmlspecialchars($log['description'] ?? '') ?: '&mdash;' ?></div></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="logNoMatchRow" class="owner-table-empty-row" hidden>
                                    <td colspan="5" class="owner-table-empty">No entries match your search.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-pagination" id="logPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="logPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="logPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="logPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

        </main>

    </div>

</div>

<script>
(function () {
    const search     = document.getElementById('logFilterSearch');
    const noMatchRow = document.getElementById('logNoMatchRow');
    const countEl    = document.getElementById('logCount');
    const tbody      = document.querySelector('.owner-table tbody');
    const rows       = Array.from(document.querySelectorAll('.owner-table tbody tr[data-search]'));

    // Deep link from a notification (admin/includes/header.php's
    // adminNotifLink()) -- shows exactly that one entry, same "URL param
    // wins" convention as every other module's notification filtering.
    const requestedLogId = new URLSearchParams(window.location.search).get('log_id');

    // Date range and module are applied in SQL, so only the free-text search
    // runs here. Pagination is client-side over whatever the query returned.
    const PAGE_SIZE = 5;
    const pagination = document.getElementById('logPagination');
    const pagePrev = document.getElementById('logPagePrev');
    const pageNext = document.getElementById('logPageNext');
    const pageInfo = document.getElementById('logPageInfo');
    let currentPage = 1;

    // Filler rows keep the table -- and the pagination bar under it -- at a
    // constant height across pages. Without them, a page with fewer than
    // PAGE_SIZE rows (almost always the last one) makes the card shrink and
    // the whole layout jump every time Prev/Next is clicked.
    const columnCount = document.querySelectorAll('.owner-table thead th').length;
    const fillerRows = [];
    if (tbody) {
        for (let i = 0; i < PAGE_SIZE; i++) {
            const tr = document.createElement('tr');
            tr.className = 'owner-table-filler-row';
            tr.hidden = true;
            tr.style.height = '64px';
            const td = document.createElement('td');
            td.colSpan = columnCount;
            td.innerHTML = '&nbsp;';
            tr.appendChild(td);
            tbody.appendChild(tr);
            fillerRows.push(tr);
        }
    }

    function applyFilters(resetPage) {
        if (resetPage) currentPage = 1;

        const q = (search ? search.value : '').trim().toLowerCase();

        const matched = rows.filter((row) => {
            // A specific log_id in the URL always wins -- shows exactly
            // that one entry, ignoring every other filter input.
            if (requestedLogId) return row.getAttribute('data-log-id') === requestedLogId;
            return !q || row.getAttribute('data-search').includes(q);
        });

        const totalPages = Math.max(1, Math.ceil(matched.length / PAGE_SIZE));
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * PAGE_SIZE;
        const end = start + PAGE_SIZE;
        const shown = matched.slice(start, end);
        const matchedSet = new Set(shown);

        rows.forEach((row) => { row.style.display = matchedSet.has(row) ? '' : 'none'; });

        // Pad the visible page out to PAGE_SIZE rows so the table height
        // never changes between pages -- only while there's at least one
        // real row, so the dedicated "no matches" row still governs the
        // empty state instead of a wall of blank rows.
        const fillersNeeded = shown.length > 0 ? (PAGE_SIZE - shown.length) : 0;
        fillerRows.forEach((tr, i) => { tr.hidden = i >= fillersNeeded; });

        if (noMatchRow) noMatchRow.hidden = (rows.length === 0 || matched.length > 0);
        if (countEl) countEl.textContent = matched.length + (matched.length === 1 ? ' entry' : ' entries');
        if (pagination) pagination.hidden = matched.length === 0 || totalPages <= 1;
        if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        if (pagePrev) pagePrev.disabled = currentPage <= 1;
        if (pageNext) pageNext.disabled = currentPage >= totalPages;
    }

    if (search) search.addEventListener('input', () => applyFilters(true));
    if (pagePrev) pagePrev.addEventListener('click', () => { currentPage--; applyFilters(false); });
    if (pageNext) pageNext.addEventListener('click', () => { currentPage++; applyFilters(false); });

    applyFilters(true);
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
<script src="../owner/assets/js/filter-autosubmit.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-autosubmit.js') ?>"></script>
</body>
</html>
