<?php
/**
 * purchase_orders/purchase_orders.php
 *
 * Purchase Order list.
 *
 * Owner owns the purchasing pipeline -- create, edit, finalize, email, cancel.
 * Manager is here to receive deliveries only: the list is filtered to POs that
 * have actually been placed with a supplier (PO_MANAGER_VISIBLE_STATUSES), so
 * drafts and approved-but-unplaced orders never appear, and the create/edit
 * affordances are not rendered for them.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/po_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage  = 'purchase_orders';
$isManager   = Session::hasRole(['manager']);
// One page, two names. A Manager cannot raise or even see a draft -- purchasing
// is the Owner's -- so what a Manager comes here to do is receive stock against
// an approved order. The sidebar link already says "Receiving Orders"; the
// heading has to agree with the link that got you here.
// Deliberately not a second receiving_orders.php: the list, the filters, the
// permissions and the receive flow would all be duplicated, and the copies
// would drift the first time either was touched.
$pageTitle   = $isManager ? 'Receiving Orders' : 'Purchase Orders';
$canManage   = poCanManage();

$orders   = [];
$dbError  = null;

try {
    $pdo = Database::getInstance()->getConnection();

    // Filtered in SQL, not just hidden in the markup -- a Manager's page should
    // never carry a draft PO's number, supplier or value in its HTML at all.
    $statusSql    = '';
    $statusParams = [];
    if (!$canManage) {
        $statusSql    = ' WHERE po.status IN (' . implode(',', array_fill(0, count(PO_MANAGER_VISIBLE_STATUSES), '?')) . ')';
        $statusParams = PO_MANAGER_VISIBLE_STATUSES;
    }

    $stmt = $pdo->prepare(
        "SELECT po.po_id, po.po_number, po.status, po.order_date, po.expected_delivery_date, po.total_amount, po.is_auto_generated,
                s.supplier_id, s.supplier_name, s.email AS supplier_email,
                CONCAT(usr.first_name, ' ', usr.last_name) AS created_by_name
         FROM purchase_orders po
         JOIN suppliers s ON s.supplier_id = po.supplier_id
         JOIN users usr   ON usr.user_id = po.created_by
         {$statusSql}
         ORDER BY po.order_date DESC, po.po_id DESC"
    );
    $stmt->execute($statusParams);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Only the list's own "N orders" subtitle needs a count now -- the summary
    // card strip that used awaiting/received/committed-spend is gone, and with
    // it the extra committed-spend query that ran on every page load.
    $totalCount = count($orders);
} catch (PDOException $e) {
    $dbError = "Couldn't load purchase order data. Please refresh this page.";
    $totalCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchase Orders | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">

    <?php
    if ($isManager) {
        $managerBase = '../manager/';
        require_once __DIR__ . '/../manager/includes/sidebar.php';
    } else {
        $ownerBase = '../owner/';
        require_once __DIR__ . '/../owner/includes/sidebar.php';
    }
    ?>

    <div class="owner-main">

        <?php
        if ($isManager) {
            require_once __DIR__ . '/../manager/includes/header.php';
        } else {
            require_once __DIR__ . '/../owner/includes/header.php';
        }
        ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            <?php /* Page actions + filters sit in their own card above the
                     table -- same split used across every list page. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <div class="owner-inv-filter-search">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <input type="text" id="poFilterSearch" placeholder="Search purchase orders&hellip;" autocomplete="off">
                    </div>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnClearPoFilters">Clear</button>
                    <?php // Only managers may raise a PO, so the button keeps the same
                          // $canManage guard it had before the filters/table split. ?>
                    <?php if ($canManage): ?>
                    <a href="purchase_order_form.php" class="owner-btn owner-btn-primary" style="margin-left:auto;">
                        <i class="ph ph-plus-circle" aria-hidden="true"></i> New purchase order
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* Status is a tab strip rather than a dropdown: working through
                     purchase orders IS moving between statuses (drafts to approve,
                     deliveries to receive), so the statuses earn permanent space
                     instead of hiding one level down in a select. Sits directly on
                     top of the table it filters. Same .owner-tabs component the
                     Inventory and Users pages use; each tab filters client-side
                     over the same rows -- see applyFilters().

                     Offering a status the list can never contain is a dead filter
                     that just makes the page look broken when it returns nothing,
                     so the manage-only statuses stay behind $canManage. */ ?>
            <div class="owner-tabs" id="poStatusTabs" role="tablist" aria-label="Filter by status">
                <button type="button" class="owner-tabs-link is-active" data-po-status="" role="tab" aria-selected="true">All</button>
                <?php if ($canManage): ?>
                    <button type="button" class="owner-tabs-link" data-po-status="draft" role="tab" aria-selected="false">Draft</button>
                    <button type="button" class="owner-tabs-link" data-po-status="approved" role="tab" aria-selected="false">Approved</button>
                <?php endif; ?>
                <button type="button" class="owner-tabs-link" data-po-status="ordered" role="tab" aria-selected="false">Ordered</button>
                <button type="button" class="owner-tabs-link" data-po-status="partially_received" role="tab" aria-selected="false">Partially received</button>
                <button type="button" class="owner-tabs-link" data-po-status="received" role="tab" aria-selected="false">Received</button>
                <?php if ($canManage): ?>
                    <button type="button" class="owner-tabs-link" data-po-status="cancelled" role="tab" aria-selected="false">Cancelled</button>
                <?php endif; ?>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>PO #</th>
                                <th>Supplier</th>
                                <th>Order date</th>
                                <th>Expected delivery</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr><td colspan="7" class="owner-table-empty"><?= $canManage ? 'No purchase orders yet.' : 'Nothing to receive right now.' ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($orders as $po):
                                    $searchKey = strtolower($po['po_number'] . ' ' . $po['supplier_name']);
                                    $supplierHasEmail = $po['supplier_email'] !== null && trim((string)$po['supplier_email']) !== '';
                                ?>
                                <tr data-name="<?= htmlspecialchars($searchKey) ?>" data-status="<?= htmlspecialchars($po['status']) ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                                        <?php if ($po['is_auto_generated']): ?>
                                            <span class="owner-status-pill is-info" title="Auto-generated based on demand forecasting -- review and approve like any other draft.">Auto-generated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($po['order_date']))) ?></td>
                                    <td><?= $po['expected_delivery_date'] !== null ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '&mdash;' ?></td>
                                    <td>&#8369;<?= number_format((float)$po['total_amount'], 2) ?></td>
                                    <td><span class="owner-status-pill <?= poOrderStatusPillClass($po['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $po['status']))) ?></span></td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <a href="purchase_order_view.php?id=<?= (int)$po['po_id'] ?>" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="View purchase order">
                                                <i class="ph ph-eye" aria-hidden="true"></i>
                                            </a>
                                            <?php if ($canManage && $po['status'] === 'draft'): ?>
                                                <a href="purchase_order_form.php?id=<?= (int)$po['po_id'] ?>" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Edit purchase order">
                                                    <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php // Same "email + mark ordered is one act, plain-text fallback
                                                  // for a supplier with no email" split as purchase_order_view.php's
                                                  // header actions -- an Approved order shouldn't need a detour
                                                  // through the detail page just to place it. Text only, matching
                                                  // "Receive stock" below and the detail page's own version of
                                                  // this exact button. ?>
                                            <?php if ($canManage && $po['status'] === 'approved' && $supplierHasEmail): ?>
                                                <form method="post" action="purchase_order_email.php" style="display:inline;" data-confirm="Email <?= htmlspecialchars($po['po_number']) ?> to <?= htmlspecialchars($po['supplier_name']) ?> (<?= htmlspecialchars($po['supplier_email']) ?>) and mark it as ordered?" data-confirm-danger="false">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                                    <input type="hidden" name="mark_ordered" value="1">
                                                    <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">Email &amp; mark as ordered</button>
                                                </form>
                                            <?php elseif ($canManage && $po['status'] === 'approved' && !$supplierHasEmail): ?>
                                                <form method="post" action="purchase_order_status.php" style="display:inline;" data-confirm="Mark <?= htmlspecialchars($po['po_number']) ?> as ordered? Confirm you have actually placed this order with the supplier." data-confirm-danger="false">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                                    <input type="hidden" name="action" value="mark_ordered">
                                                    <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">Mark as ordered</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php // Straight to the receiving screen, for Owner and Manager
                                                  // alike -- it is the Manager's whole reason for being on
                                                  // this page, and the Owner receives deliveries too.
                                                  // Text only: a bare box glyph next to the eye read as
                                                  // another view mode, and this one writes stock into
                                                  // inventory. ?>
                                            <?php if (in_array($po['status'], ['ordered', 'partially_received'], true)): ?>
                                                <a href="purchase_order_receive.php?id=<?= (int)$po['po_id'] ?>" class="owner-btn owner-btn-primary owner-btn-sm">Receive stock</a>
                                            <?php endif; ?>
                                            <?php // Draft only, Owner only. A draft was never sent to anyone,
                                                  // so it can be removed outright; everything later is either a
                                                  // real commitment or the record of a decision, and those are
                                                  // cancelled rather than erased. Deleting an unwanted auto-draft
                                                  // also hands its quantity back to "on order", so the next
                                                  // forecast run stops treating the item as already handled. ?>
                                            <?php if ($canManage && $po['status'] === 'draft'): ?>
                                                <form method="post" action="purchase_order_delete.php" style="display:inline;" data-confirm="Delete <?= htmlspecialchars($po['po_number']) ?> permanently? This cannot be undone.">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                                    <?php // Kept current by syncUrl() below, so deleting from page 3 of a
                                                          // filtered list returns to page 3 of that same filtered list
                                                          // instead of dumping the user back at an unfiltered page 1. ?>
                                                    <input type="hidden" name="return_qs" value="" class="po-return-qs">
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Delete purchase order" title="Delete">
                                                        <i class="ph ph-trash" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="poNoMatchRow" class="owner-table-empty-row" hidden>
                                    <td colspan="7" class="owner-table-empty">No purchase orders match your filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-pagination" id="poPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="poPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="poPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="poPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

        </main>

    </div>

</div>

<?php // Drives the data-confirm on the Delete form below. This page had no
      // confirm dialog before because it had no destructive action on it. ?>
<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const search = document.getElementById('poFilterSearch');
    const clearBtn = document.getElementById('btnClearPoFilters');

    // Status lives in the tab strip now, not a <select>, so it is plain state
    // here. setStatus() is the single place that moves it, so the active tab
    // and the value the filter reads can never drift apart.
    const statusTabs = Array.from(document.querySelectorAll('#poStatusTabs .owner-tabs-link'));
    let status = '';

    function setStatus(next) {
        // A tab the current role isn't offered (?status=draft on a receive-only
        // account) falls back to All rather than filtering to a hidden tab and
        // showing an empty table with no active tab to explain it.
        const known = statusTabs.some((t) => t.getAttribute('data-po-status') === next);
        status = known ? next : '';
        statusTabs.forEach((t) => {
            const on = t.getAttribute('data-po-status') === status;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }
    const noMatchRow = document.getElementById('poNoMatchRow');
    const rows = Array.from(document.querySelectorAll('.owner-table tbody tr[data-name]'));

    // -- Pagination (client-side, over the currently filtered set) -----------
    const PAGE_SIZE = 10;
    const pagination = document.getElementById('poPagination');
    const pagePrev = document.getElementById('poPagePrev');
    const pageNext = document.getElementById('poPageNext');
    const pageInfo = document.getElementById('poPageInfo');
    let currentPage = 1;

    /* Filter and page were pure in-memory state, so anything that left this
       page and came back -- deleting a PO, opening one and hitting Back --
       landed on an unfiltered page 1. They live in the URL now: syncUrl()
       writes them on every change (replaceState, so it doesn't spam history)
       and the block at the bottom reads them back on load. */
    const returnQsInputs = Array.from(document.querySelectorAll('.po-return-qs'));

    function currentQs() {
        const params = new URLSearchParams();
        const q      = (search ? search.value : '').trim();
        if (q) params.set('search', q);
        if (status) params.set('status', status);
        if (currentPage > 1) params.set('page', String(currentPage));
        return params.toString();
    }

    function syncUrl() {
        const qs = currentQs();
        history.replaceState(null, '', qs ? location.pathname + '?' + qs : location.pathname);
        // Every Delete form posts the same string back, so the redirect can
        // rebuild this exact view.
        returnQsInputs.forEach((input) => { input.value = qs; });
    }

    function applyFilters(resetPage) {
        if (resetPage) currentPage = 1;

        const q = (search ? search.value : '').trim().toLowerCase();

        const matched = rows.filter((row) => {
            const matchesSearch = !q || row.getAttribute('data-name').includes(q);
            const matchesStatus = !status || row.getAttribute('data-status') === status;
            return matchesSearch && matchesStatus;
        });

        const totalPages = Math.max(1, Math.ceil(matched.length / PAGE_SIZE));
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * PAGE_SIZE;
        const matchedSet = new Set(matched.slice(start, start + PAGE_SIZE));

        rows.forEach((row) => { row.style.display = matchedSet.has(row) ? '' : 'none'; });

        if (noMatchRow) noMatchRow.hidden = (rows.length === 0 || matched.length > 0);
        if (pagination) pagination.hidden = matched.length === 0 || totalPages <= 1;
        if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        if (pagePrev) pagePrev.disabled = currentPage <= 1;
        if (pageNext) pageNext.disabled = currentPage >= totalPages;

        syncUrl();
    }

    if (search) search.addEventListener('input', () => applyFilters(true));
    statusTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            setStatus(tab.getAttribute('data-po-status'));
            applyFilters(true);
        });
    });
    if (pagePrev) pagePrev.addEventListener('click', () => { currentPage--; applyFilters(false); });
    if (pageNext) pageNext.addEventListener('click', () => { currentPage++; applyFilters(false); });
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (search) search.value = '';
            setStatus('');
            applyFilters(true);
        });
    }

    // Restore whatever the last view was before running the first pass.
    // currentPage is set after the filters so the clamp inside applyFilters()
    // still catches a page number that no longer exists -- e.g. deleting the
    // only row on the last page.
    (function restoreFromUrl() {
        const params = new URLSearchParams(location.search);
        const q    = params.get('search');
        const page = parseInt(params.get('page') || '1', 10);
        if (q && search) search.value = q;
        setStatus(params.get('status') || '');
        currentPage = Number.isFinite(page) && page > 0 ? page : 1;
    })();

    applyFilters(false);
})();
</script>

<script src="../owner/assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-persist.js') ?>"></script>
</body>
</html>
