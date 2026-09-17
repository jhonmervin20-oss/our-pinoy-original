<?php
/**
 * inventory/inventory_adjustments.php
 *
 * Dedicated Stock Adjustment list + "New adjustment" modal.
 *
 * An adjustment moves stock in either direction, which is what separates it
 * from wastage (always a decrease):
 *   Increase -- creates a brand-new batch, so it needs a unit cost.
 *   Decrease -- draws down one specific existing batch, so it needs a batch.
 * inventory_adjustment_save.php enforces exactly that split; the modal below
 * mirrors it by swapping which field is required as the direction changes.
 *
 * Like wastage, there is no delete affordance: an adjustment is part of the
 * audit trail and is reversed by recording an opposite adjustment, never by
 * erasing the original.
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
$activeInvTab = 'adjustments';
$pageTitle    = 'Stock adjustments';
$isManager    = Session::hasRole(['manager']);

$adjustments = [];
$items       = [];
$itemBatches = [];
$preselect   = null;
$dbError     = null;

const ADJUSTMENT_REASON_LABELS = [
    'physical_count'      => 'Physical Count',
    'damaged'             => 'Damaged',
    'lost'                => 'Lost',
    'theft'               => 'Theft',
    'correction'          => 'Correction',
    'supplier_correction' => 'Supplier Correction',
    'other'               => 'Other',
];

/* Server-side, by GET -- same convention as inventory_transactions.php and the
   Wastage tab. The list is capped at 200 rows, so filtering in the browser
   would only ever search the most recent 200 and silently miss older matches. */
$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to'   => trim((string)($_GET['date_to'] ?? '')),
    'item_id'   => trim((string)($_GET['item_id'] ?? '')),
    'direction' => trim((string)($_GET['direction'] ?? '')),
    'reason'    => trim((string)($_GET['reason'] ?? '')),
];

try {
    $pdo = Database::getInstance()->getConnection();

    $where  = [];
    $params = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
        $where[]  = 'a.adjustment_date >= ?';
        $params[] = $filters['date_from'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
        $where[]  = 'a.adjustment_date <= ?';
        $params[] = $filters['date_to'];
    }
    if ($filters['item_id'] !== '' && ctype_digit($filters['item_id'])) {
        $where[]  = 'a.item_id = ?';
        $params[] = (int)$filters['item_id'];
    }
    if (in_array($filters['direction'], ['increase', 'decrease'], true)) {
        $where[]  = 'a.direction = ?';
        $params[] = $filters['direction'];
    }
    if ($filters['reason'] !== '' && array_key_exists($filters['reason'], ADJUSTMENT_REASON_LABELS)) {
        $where[]  = 'a.reason = ?';
        $params[] = $filters['reason'];
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT a.adjustment_id, a.adjustment_number, a.direction, a.quantity, a.reason,
               a.remarks, a.adjustment_date, a.created_at,
               i.item_name, u.unit_code, b.batch_number,
               usr.first_name, usr.last_name
        FROM stock_adjustments a
        JOIN inventory_items i        ON i.item_id = a.item_id
        LEFT JOIN unit_of_measures u  ON u.unit_id = i.base_unit_id
        LEFT JOIN inventory_batches b ON b.batch_id = a.batch_id
        JOIN users usr                ON usr.user_id = a.adjusted_by
        {$whereSql}
        ORDER BY a.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = $pdo->query("
        SELECT i.item_id, i.item_name
        FROM inventory_items i
        WHERE i.is_active = 1
        ORDER BY i.item_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Only batches that still hold stock can be decreased from.
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
            'label'    => ($b['batch_number'] ?: ('Batch #' . $b['batch_id'])) . ' &mdash; ' . fmtQty($b['quantity_remaining']) . ' ' . $b['unit_code'] . ' remaining',
        ];
    }

    // Deep link from the Batches tab: preselect that batch (and its item).
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
    $dbError = "Couldn't load live adjustment data. Run the schema migration, then refresh this page.";
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
<title>Stock adjustments | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
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

            <?php /* Actions card, then a bare table card -- the list-page split used
                     everywhere else. No title or count above a list table: the
                     inventory nav above already says which tab this is. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <form method="get" action="inventory_adjustments.php" class="owner-inv-filters" style="margin:0;" data-autosubmit="adjustments">
                    <input type="date" name="date_from" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_from']) ?>" title="From date">
                    <input type="date" name="date_to" class="owner-input" style="width:auto;" value="<?= htmlspecialchars($filters['date_to']) ?>" title="To date">
                    <?php // Capped: an unconstrained select sizes to its widest option
                          // (long ingredient names), which pushed "New adjustment" onto
                          // a second line. Truncation is fine here -- the chosen item is
                          // also visible in the Item column of every filtered row. ?>
                    <select name="item_id" class="owner-select" style="max-width:170px;">
                        <option value="">All items</option>
                        <?php foreach ($items as $it): ?>
                        <option value="<?= (int)$it['item_id'] ?>" <?= $filters['item_id'] == $it['item_id'] ? 'selected' : '' ?>><?= htmlspecialchars($it['item_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="direction" class="owner-select">
                        <option value="">All directions</option>
                        <option value="increase" <?= $filters['direction'] === 'increase' ? 'selected' : '' ?>>Increase</option>
                        <option value="decrease" <?= $filters['direction'] === 'decrease' ? 'selected' : '' ?>>Decrease</option>
                    </select>
                    <select name="reason" class="owner-select">
                        <option value="">All reasons</option>
                        <?php foreach (ADJUSTMENT_REASON_LABELS as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $filters['reason'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="inventory_adjustments.php" class="owner-btn owner-btn-secondary owner-btn-sm">Clear</a>
                    <div style="margin-left:auto;">
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAdjustment">
                            <i class="ph ph-plus-circle" aria-hidden="true"></i> New adjustment
                        </button>
                    </div>
                </form>
            </div>

            <div class="owner-card">

                <?php if (isset($_GET['adjustment_id'])): ?>
                <div class="owner-alert" id="adjFilterBanner" style="background:var(--op-canvas);color:var(--op-ink-soft);">
                    <i class="ph ph-funnel" aria-hidden="true"></i>
                    <span>Showing the adjustment from your notification.</span>
                    <a href="inventory_adjustments.php" class="owner-btn owner-btn-secondary owner-btn-sm" style="margin-left:auto;">Show all</a>
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
                                <th>Direction</th>
                                <th>Quantity</th>
                                <th>Reason</th>
                                <th>Adjusted by</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($adjustments)): ?>
                                <?php // Distinguishes "nothing here" from "nothing matched", so a
                                      // filter returning nothing does not read as an empty log. ?>
                                <?php $hasFilters = implode('', $filters) !== ''; ?>
                                <tr><td colspan="9" class="owner-table-empty">
                                    <?php if ($hasFilters): ?>
                                        No stock adjustments match your filters.
                                        <div class="owner-table-empty-hint"><a href="inventory_adjustments.php">Clear filters</a></div>
                                    <?php else: ?>
                                        No stock adjustments recorded yet.
                                    <?php endif; ?>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach ($adjustments as $a): $isIncrease = $a['direction'] === 'increase'; ?>
                                <tr data-adjustment-id="<?= (int)$a['adjustment_id'] ?>">
                                    <td><strong><?= htmlspecialchars($a['adjustment_number']) ?></strong></td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($a['adjustment_date']))) ?></td>
                                    <td><?= htmlspecialchars($a['item_name']) ?></td>
                                    <td><?= $a['batch_number'] !== null ? htmlspecialchars($a['batch_number']) : '&mdash;' ?></td>
                                    <td><span class="owner-status-pill <?= $isIncrease ? 'is-success' : 'is-danger' ?>"><?= $isIncrease ? 'Increase' : 'Decrease' ?></span></td>
                                    <td><?= $isIncrease ? '+' : '&minus;' ?><?= fmtQty($a['quantity']) ?> <?= htmlspecialchars($a['unit_code'] ?? '') ?></td>
                                    <td><?= htmlspecialchars(ADJUSTMENT_REASON_LABELS[$a['reason']] ?? $a['reason']) ?></td>
                                    <td><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?></td>
                                    <td><?= $a['remarks'] !== null ? htmlspecialchars($a['remarks']) : '&mdash;' ?></td>
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

<!-- New adjustment modal -->
<div class="owner-modal-backdrop" id="adjBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="adjTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="adjTitle">New stock adjustment</h2>
            <button type="button" class="owner-modal-close" id="btnCloseAdj" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="post" action="inventory_adjustment_save.php" id="adjForm" novalidate>
            <div class="owner-modal-body">

                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="adjustments">

                <div class="owner-form-note owner-alert owner-alert-error" id="adjFormAlert" hidden>
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span id="adjFormAlertText">Please fix the highlighted fields before saving.</span>
                </div>

                <div class="owner-form-grid">
                    <div class="owner-form-group owner-form-group-full">
                        <label for="adjDirection">Direction <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="adjDirection" name="direction" class="owner-select" required>
                            <option value="increase">Increase &mdash; add new stock</option>
                            <option value="decrease">Decrease &mdash; take from an existing batch</option>
                        </select>
                        <small class="owner-form-hint">An increase creates a new batch. A decrease draws down one you pick below.</small>
                    </div>

                    <div class="owner-form-group owner-form-group-full" data-combobox>
                        <label for="adjItemSearch">Inventory item <span class="owner-required" aria-hidden="true">*</span></label>
                        <div class="owner-combobox-control">
                            <input type="text" id="adjItemSearch" class="owner-input owner-combobox-input" placeholder="Search item&hellip;" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="adjItemListbox" data-options='<?= inventoryComboboxOptions($itemOptions, "item_id", "label") ?>'>
                            <input type="hidden" name="item_id" id="adjItemId" required>
                            <i class="ph ph-caret-down owner-combobox-caret" aria-hidden="true"></i>
                            <ul class="owner-combobox-panel" id="adjItemListbox" role="listbox"></ul>
                        </div>
                        <span class="owner-form-error" data-error-for="item_id"></span>
                    </div>

                    <div class="owner-form-group" id="adjBatchGroup" hidden>
                        <label for="adjBatchSelect">Batch <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="adjBatchSelect" name="batch_id" class="owner-select">
                            <option value="">Select an item first&hellip;</option>
                        </select>
                        <span class="owner-form-error" data-error-for="batch_id"></span>
                    </div>

                    <div class="owner-form-group" id="adjUnitCostGroup">
                        <label for="adjUnitCost">Unit cost <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="number" step="0.01" min="0" id="adjUnitCost" name="unit_cost" class="owner-input" placeholder="0.00">
                        <span class="owner-form-error" data-error-for="unit_cost"></span>
                    </div>

                    <div class="owner-form-group">
                        <label for="adjQuantity">Quantity <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="number" step="0.001" min="0.001" id="adjQuantity" name="quantity" class="owner-input" placeholder="0.000" required>
                        <span class="owner-form-error" data-error-for="quantity"></span>
                    </div>

                    <div class="owner-form-group">
                        <label for="adjReason">Reason <span class="owner-required" aria-hidden="true">*</span></label>
                        <select id="adjReason" name="reason" class="owner-select" required>
                            <?php foreach (ADJUSTMENT_REASON_LABELS as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="owner-form-group">
                        <label for="adjDate">Date <span class="owner-required" aria-hidden="true">*</span></label>
                        <input type="date" id="adjDate" name="adjustment_date" class="owner-input" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="owner-form-group owner-form-group-full">
                        <label for="adjRemarks">Remarks <span class="owner-form-optional">(optional)</span></label>
                        <textarea id="adjRemarks" name="remarks" class="owner-input" rows="2" maxlength="255"></textarea>
                    </div>
                </div>

            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelAdj">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save adjustment</button>
            </div>
        </form>
    </div>
</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    // Deep link from an "adjustment recorded" notification -- shows exactly
    // that one row, the same "URL param wins" convention inventory.php uses.
    const requestedId = new URLSearchParams(window.location.search).get('adjustment_id');
    if (requestedId) {
        document.querySelectorAll('.owner-table tbody tr[data-adjustment-id]').forEach((row) => {
            row.style.display = row.getAttribute('data-adjustment-id') === requestedId ? '' : 'none';
        });
    }
})();
(function () {
    const itemBatches = <?= json_encode($itemBatches) ?>;
    const preselect   = <?= json_encode($preselect) ?>;

    const backdrop    = document.getElementById('adjBackdrop');
    const openBtn     = document.getElementById('btnOpenAdjustment');
    const closeBtn    = document.getElementById('btnCloseAdj');
    const cancelBtn   = document.getElementById('btnCancelAdj');
    const form        = document.getElementById('adjForm');
    const itemIdField = document.getElementById('adjItemId');
    const itemSearch  = document.getElementById('adjItemSearch');
    const batchSelect = document.getElementById('adjBatchSelect');
    const direction   = document.getElementById('adjDirection');
    const batchGroup  = document.getElementById('adjBatchGroup');
    const costGroup   = document.getElementById('adjUnitCostGroup');
    const unitCost    = document.getElementById('adjUnitCost');

    function openModal() {
        backdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closeModal() {
        backdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    // Increase needs a unit cost (it creates a batch); decrease needs a batch
    // to draw from. Mirrors inventory_adjustment_save.php's own validation --
    // and the hidden field is un-required too, so the browser can never block
    // submit on a control the server does not want.
    function syncDirectionUi() {
        const isIncrease = direction.value === 'increase';
        batchGroup.hidden = isIncrease;
        costGroup.hidden  = !isIncrease;
        batchSelect.required = !isIncrease;
        unitCost.required = isIncrease;
        if (isIncrease) { batchSelect.value = ''; } else { unitCost.value = ''; }
    }

    function populateBatches(itemId) {
        const batches = itemBatches[itemId] || [];
        if (batches.length === 0) {
            batchSelect.innerHTML = '<option value="">No batches with stock for this item</option>';
            return;
        }
        batchSelect.innerHTML = '<option value="">Select a batch&hellip;</option>' +
            batches.map(b => '<option value="' + b.batch_id + '">' + b.label + '</option>').join('');
    }

    function resetForm() {
        form.reset();
        batchSelect.innerHTML = '<option value="">Select an item first&hellip;</option>';
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.owner-form-error').forEach(el => { el.textContent = ''; });
        document.getElementById('adjFormAlert').hidden = true;
        syncDirectionUi();
    }

    if (openBtn) openBtn.addEventListener('click', () => { resetForm(); openModal(); });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', () => { resetForm(); closeModal(); });
    if (backdrop) backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal(); });
    direction.addEventListener('change', syncDirectionUi);

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
        const isIncrease = direction.value === 'increase';
        let message = '';
        if (!itemIdField.value) message = 'Please select an inventory item.';
        else if (!isIncrease && !batchSelect.value) message = 'Please select a batch to decrease from.';
        else if (isIncrease && unitCost.value === '') message = 'Please enter a unit cost.';
        else if (!document.getElementById('adjQuantity').value) message = 'Please enter a quantity.';

        if (message) {
            document.getElementById('adjFormAlertText').textContent = message;
            document.getElementById('adjFormAlert').hidden = false;
            return;
        }

        const verb = isIncrease ? 'Add' : 'Remove';
        const ok = await confirmAction(verb + ' ' + document.getElementById('adjQuantity').value + ' of "' + itemSearch.value + '"? Adjustments are permanent and are reversed by recording an opposite adjustment.');
        if (!ok) return;
        form.submit();
    });

    syncDirectionUi();

    if (preselect) {
        direction.value = 'decrease';
        syncDirectionUi();
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
