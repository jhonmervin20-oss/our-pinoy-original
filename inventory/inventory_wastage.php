<?php
/**
 * inventory/inventory_wastage.php
 *
 * Dedicated Wastage list + "New wastage" modal. Always decreases a
 * specific existing batch. No delete affordance anywhere on this page —
 * wastage records are a permanent part of the audit trail.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/inventory_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage   = 'inventory';
$activeInvTab = 'wastage';
$pageTitle    = 'Wastage';
$isManager    = Session::hasRole(['manager']);

$wastageRecords = [];
$items          = [];
$itemBatches    = [];
$preselect      = null;
$dbError        = null;

/* Server-side, by GET -- the same convention inventory_transactions.php uses,
   rather than the client-side DOM-hide pattern some other modules use. The
   list is capped at 200 rows, so filtering in the browser would only ever
   search the most recent 200 and quietly miss older matches. */
$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to'   => trim((string)($_GET['date_to'] ?? '')),
    'item_id'   => trim((string)($_GET['item_id'] ?? '')),
    'reason'    => trim((string)($_GET['reason'] ?? '')),
];

const WASTAGE_REASON_LABELS = [
    'expired'             => 'Expired',
    'spoiled'              => 'Spoiled',
    'burned'                 => 'Burned',
    'overcooked'               => 'Overcooked',
    'dropped'                    => 'Dropped',
    'customer_complaint'           => 'Customer Complaint',
    'quality_issue'                  => 'Quality Issue',
    'other'                             => 'Other',
];

try {
    $pdo = Database::getInstance()->getConnection();

    $where  = [];
    $params = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
        $where[]  = 'w.wastage_date >= ?';
        $params[] = $filters['date_from'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
        $where[]  = 'w.wastage_date <= ?';
        $params[] = $filters['date_to'];
    }
    if ($filters['item_id'] !== '' && ctype_digit($filters['item_id'])) {
        $where[]  = 'w.item_id = ?';
        $params[] = (int)$filters['item_id'];
    }
    if ($filters['reason'] !== '' && array_key_exists($filters['reason'], WASTAGE_REASON_LABELS)) {
        $where[]  = 'w.reason = ?';
        $params[] = $filters['reason'];
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT w.wastage_id, w.wastage_number, w.quantity, w.reason, w.remarks, w.wastage_date, w.created_at,
               i.item_name, u.unit_code, b.batch_number,
               usr.first_name, usr.last_name
        FROM wastage_records w
        JOIN inventory_items i        ON i.item_id = w.item_id
        LEFT JOIN unit_of_measures u  ON u.unit_id = i.base_unit_id
        JOIN inventory_batches b      ON b.batch_id = w.batch_id
        JOIN users usr                ON usr.user_id = w.recorded_by
        {$whereSql}
        ORDER BY w.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $wastageRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = $pdo->query("
        SELECT i.item_id, i.item_name
        FROM inventory_items i
        WHERE i.is_active = 1
        ORDER BY i.item_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $batchRows = $pdo->query("
        SELECT b.batch_id, b.item_id, b.batch_number, b.quantity_remaining, u.unit_code
        FROM inventory_batches b
        JOIN inventory_items i       ON i.item_id = b.item_id
        LEFT JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
        WHERE b.quantity_remaining > 0
        ORDER BY b.received_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($batchRows as $b) {
        $itemBatches[$b['item_id']][] = [
            'batch_id' => $b['batch_id'],
            'label'    => ($b['batch_number'] ?: ('Batch #' . $b['batch_id'])) . ' — ' . fmtQty($b['quantity_remaining']) . ' ' . $b['unit_code'] . ' remaining',
        ];
    }

    $preselectBatchId = trim((string)($_GET['batch_id'] ?? ''));
    if ($preselectBatchId !== '' && ctype_digit($preselectBatchId)) {
        $stmt = $pdo->prepare("
            SELECT b.batch_id, b.item_id, i.item_name
            FROM inventory_batches b
            JOIN inventory_items i ON i.item_id = b.item_id
            WHERE b.batch_id = ?
        ");
        $stmt->execute([$preselectBatchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $preselect = [
                'item_id'   => $row['item_id'],
                'item_name' => $row['item_name'],
                'batch_id'  => $row['batch_id'],
            ];
        }
    }
} catch (PDOException $e) {
    $dbError = "Couldn't load live wastage data. Run the schema migration, then refresh this page.";
}

$itemOptions = array_map(
    fn($i) => ['item_id' => $i['item_id'], 'label' => $i['item_name']],
    $items
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Wastage | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
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

            <?php require __DIR__ . '/includes/inventory_nav.php'; ?>

            <?php /* Filters + actions card, then a bare table card -- the list-page
                     split used across this system. No title or count above a list
                     table: the inventory nav above already says which tab this is. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <form method="get" action="inventory_wastage.php" class="owner-inv-filters" style="margin:0;" data-autosubmit="wastage">
                    <input type="date" name="date_from" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_from']) ?>" title="From date">
                    <input type="date" name="date_to" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_to']) ?>" title="To date">
                    <select name="item_id" class="owner-select">
                        <option value="">All items</option>
                        <?php foreach ($items as $it): ?>
                        <option value="<?= (int)$it['item_id'] ?>" <?= $filters['item_id'] == $it['item_id'] ? 'selected' : '' ?>><?= htmlspecialchars($it['item_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="reason" class="owner-select">
                        <option value="">All reasons</option>
                        <?php foreach (WASTAGE_REASON_LABELS as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $filters['reason'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="inventory_wastage.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                    <div style="margin-left:auto;">
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenWastage">
                            <i class="ph ph-plus-circle" aria-hidden="true"></i> New wastage
                        </button>
                    </div>
                </form>
            </div>

            <div class="owner-card">

                <?php if (isset($_GET['wastage_id'])): ?>
                <div class="owner-alert" style="background:var(--op-canvas);color:var(--op-ink-soft);">
                    <i class="ph ph-funnel" aria-hidden="true"></i>
                    <span>Showing the wastage record from your notification.</span>
                    <a href="inventory_wastage.php" class="owner-btn owner-btn-secondary owner-btn-sm" style="margin-left:auto;">Show all</a>
                </div>
                <?php endif; ?>

                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Reference #</th>
                                <th>Date</th>
                                <th>Item</th>
                                <th>Batch</th>
                                <th>Quantity</th>
                                <th>Reason</th>
                                <th>Recorded by</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($wastageRecords)): ?>
                                <?php // Distinguishes "nothing here" from "nothing matched" --
                                      // otherwise a filter that returns nothing reads as an empty
                                      // log and the user has no idea a filter is on. ?>
                                <?php $hasFilters = implode('', $filters) !== ''; ?>
                                <tr><td colspan="8" class="owner-table-empty">
                                    <?php if ($hasFilters): ?>
                                        No wastage matches your filters.
                                        <div class="owner-table-empty-hint"><a href="inventory_wastage.php">Clear filters</a></div>
                                    <?php else: ?>
                                        No wastage recorded yet.
                                    <?php endif; ?>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach ($wastageRecords as $w): ?>
                                <tr data-wastage-id="<?= (int)$w['wastage_id'] ?>">
                                    <td><strong><?= htmlspecialchars($w['wastage_number']) ?></strong></td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($w['wastage_date']))) ?></td>
                                    <td><?= htmlspecialchars($w['item_name']) ?></td>
                                    <td><?= $w['batch_number'] !== null ? htmlspecialchars($w['batch_number']) : '&mdash;' ?></td>
                                    <td><?= fmtQty($w['quantity']) ?> <?= htmlspecialchars($w['unit_code'] ?? '') ?></td>
                                    <td><span class="owner-status-pill is-danger"><?= htmlspecialchars(WASTAGE_REASON_LABELS[$w['reason']] ?? $w['reason']) ?></span></td>
                                    <td><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></td>
                                    <td><?= $w['remarks'] !== null ? htmlspecialchars($w['remarks']) : '&mdash;' ?></td>
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

<!-- New wastage modal -->
<div class="owner-modal-backdrop" id="wstBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="wstTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="wstTitle">Log wastage</h2>
            <button type="button" class="owner-modal-close" id="btnCloseWst" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="post" action="inventory_wastage_save.php" id="wstForm" novalidate>
            <div class="owner-modal-body">

                <?= csrf_field() ?>

                <div class="owner-form-note owner-alert owner-alert-error" id="wstFormAlert" hidden>
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span id="wstFormAlertText">Please fix the highlighted fields before saving.</span>
                </div>

                <div class="owner-form-grid">
                    <div class="owner-form-group owner-form-group-full" data-combobox>
                        <label for="wstItemSearch">Inventory item <span class="owner-required" aria-hidden="true">*</span></label>
                        <div class="owner-combobox-control">
                            <input type="text" id="wstItemSearch" class="owner-input owner-combobox-input" placeholder="Search item&hellip;" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="wstItemListbox" data-options='<?= inventoryComboboxOptions($itemOptions, "item_id", "label") ?>'>
                            <input type="hidden" name="item_id" id="wstItemId" required>
                            <i class="ph ph-caret-down owner-combobox-caret" aria-hidden="true"></i>
                            <ul class="owner-combobox-panel" id="wstItemListbox" role="listbox"></ul>
                        </div>
                        <span class="owner-form-error" data-error-for="item_id"></span>
                    </div>

                    <div class="owner-form-group">
                        <label for="wstBatchSelect">Batch <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="wstBatchSelect" name="batch_id" class="owner-select" required>
                            <option value="">Select an item first&hellip;</option>
                        </select>
                        <span class="owner-form-error" data-error-for="batch_id"></span>
                    </div>

                    <div class="owner-form-group">
                        <label for="wstQuantity">Quantity <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="number" step="0.001" min="0.001" id="wstQuantity" name="quantity" class="owner-input" placeholder="0.000" required>
                        <span class="owner-form-error" data-error-for="quantity"></span>
                    </div>

                    <div class="owner-form-group">
                        <label for="wstReason">Reason <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="wstReason" name="reason" class="owner-select" required>
                            <?php foreach (WASTAGE_REASON_LABELS as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="owner-form-group">
                        <label for="wstDate">Date <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="date" id="wstDate" name="wastage_date" class="owner-input" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="owner-form-group owner-form-group-full">
                        <label for="wstRemarks">Remarks <span class="owner-form-optional">(optional)</span></label>
                        <textarea id="wstRemarks" name="remarks" class="owner-input" rows="2" maxlength="255"></textarea>
                    </div>
                </div>

            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelWst">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save wastage</button>
            </div>
        </form>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    // Deep link from a "wastage recorded" notification -- shows exactly that
    // one row, same "URL param wins" convention as inventory.php's own
    // item_id/batch_id filtering.
    const requestedWastageId = new URLSearchParams(window.location.search).get('wastage_id');
    if (requestedWastageId) {
        document.querySelectorAll('.owner-table tbody tr[data-wastage-id]').forEach((row) => {
            row.style.display = row.getAttribute('data-wastage-id') === requestedWastageId ? '' : 'none';
        });
    }
})();
(function () {
    const itemBatches = <?= json_encode($itemBatches) ?>;
    const preselect    = <?= json_encode($preselect) ?>;

    const backdrop    = document.getElementById('wstBackdrop');
    const openBtn     = document.getElementById('btnOpenWastage');
    const closeBtn    = document.getElementById('btnCloseWst');
    const cancelBtn   = document.getElementById('btnCancelWst');
    const form        = document.getElementById('wstForm');
    const itemIdField = document.getElementById('wstItemId');
    const itemSearch  = document.getElementById('wstItemSearch');
    const batchSelect = document.getElementById('wstBatchSelect');

    function openModal() {
        backdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closeModal() {
        backdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    function populateBatches(itemId) {
        const batches = itemBatches[itemId] || [];
        if (batches.length === 0) {
            batchSelect.innerHTML = '<option value="">No batches with stock for this item</option>';
            return;
        }
        batchSelect.innerHTML = '<option value="">Select a batch&hellip;</option>' +
            batches.map(b => `<option value="${b.batch_id}">${b.label}</option>`).join('');
    }

    function resetForm() {
        form.reset();
        batchSelect.innerHTML = '<option value="">Select an item first&hellip;</option>';
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.owner-form-error').forEach(el => { el.textContent = ''; });
        document.getElementById('wstFormAlert').hidden = true;
    }

    if (openBtn) openBtn.addEventListener('click', () => { resetForm(); openModal(); });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', () => { resetForm(); closeModal(); });
    if (backdrop) backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal(); });

    (function initItemCombobox() {
        const root  = itemSearch.closest('[data-combobox]');
        const panel = root.querySelector('.owner-combobox-panel');
        let options = [];
        try { options = JSON.parse(itemSearch.getAttribute('data-options') || '[]'); } catch (e) {}

        function render(list) {
            panel.innerHTML = '';
            list.forEach((opt) => {
                const li = document.createElement('li');
                li.className = 'owner-combobox-option';
                li.textContent = opt.label;
                li.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    itemIdField.value = opt.id;
                    itemSearch.value = opt.label;
                    panel.classList.remove('is-open');
                    populateBatches(opt.id);
                });
                panel.appendChild(li);
            });
        }
        function open() {
            const q = itemSearch.value.trim().toLowerCase();
            render(q ? options.filter(o => o.label.toLowerCase().includes(q)) : options);
            panel.classList.add('is-open');
        }
        itemSearch.addEventListener('focus', open);
        itemSearch.addEventListener('input', () => { itemIdField.value = ''; open(); });
        document.addEventListener('click', (e) => { if (!root.contains(e.target)) panel.classList.remove('is-open'); });
    })();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        let message = '';
        if (!itemIdField.value) message = 'Please select an inventory item.';
        else if (!batchSelect.value) message = 'Please select a batch.';
        else if (!document.getElementById('wstQuantity').value) message = 'Please enter a quantity.';

        if (message) {
            document.getElementById('wstFormAlertText').textContent = message;
            document.getElementById('wstFormAlert').hidden = false;
            return;
        }

        const ok = await confirmAction(`Log ${document.getElementById('wstQuantity').value} of "${itemSearch.value}" as wastage? This cannot be undone or deleted.`);
        if (!ok) return;
        form.submit();
    });

    if (preselect) {
        itemIdField.value = preselect.item_id;
        itemSearch.value = preselect.item_name;
        populateBatches(preselect.item_id);
        if (preselect.batch_id) batchSelect.value = preselect.batch_id;
        openModal();
    }
})();
</script>

<script src="../owner/assets/js/filter-autosubmit.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/filter-autosubmit.js') ?>"></script>
</body>
</html>
