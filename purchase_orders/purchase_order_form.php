<?php
/**
 * purchase_orders/purchase_order_form.php
 *
 * Create/edit a Purchase Order. Owner/Admin only. Every new PO starts
 * (and every save keeps it) as 'draft' — Finalize/Mark as Ordered/Cancel
 * are separate one-click actions on purchase_order_view.php, so a PO can
 * be built up over multiple edits before being committed.
 *
 * Three entry modes:
 *   - bare (no query string): blank new PO
 *   - ?id=N: edit an existing draft (redirects to the read-only view for
 *     anything past draft — a committed record is never hand-edited)
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/back_link.php';   // back_link()
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
// Owner-only: creating and managing the purchasing pipeline is theirs. A
// Manager reaching this by URL is bounced back to the list rather than to
// login -- they ARE signed in and do have the module, just not this action.
if (!poCanManage()) {
    poDenyAccess('Only the owner can create or manage purchase orders.');
}

$activePage  = 'purchase_orders';
$isManager   = Session::hasRole(['manager']);

$pdo = Database::getInstance()->getConnection();

$poId              = trim((string)($_GET['id'] ?? ''));
$isEdit            = false;

$prefill = ['supplier_id' => '', 'order_date' => date('Y-m-d'), 'expected_delivery_date' => '', 'remarks' => ''];
$initialLines = [];

if ($poId !== '' && ctype_digit($poId)) {
    $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE po_id = ?');
    $stmt->execute([$poId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po || $po['status'] !== 'draft') {
        header('Location: purchase_order_view.php?id=' . $poId);
        exit;
    }

    $isEdit  = true;
    $prefill = [
        'supplier_id'             => $po['supplier_id'],
        'order_date'              => $po['order_date'],
        'expected_delivery_date'  => $po['expected_delivery_date'] ?? '',
        'remarks'                 => $po['remarks'] ?? '',
    ];

    $lineStmt = $pdo->prepare(
        "SELECT poi.item_id, poi.quantity_ordered, poi.unit_cost, i.item_name, u.unit_code
         FROM purchase_order_items poi
         JOIN inventory_items i       ON i.item_id = poi.item_id
         LEFT JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
         WHERE poi.po_id = ?"
    );
    $lineStmt->execute([$poId]);
    $initialLines = array_map(
        fn($l) => [
            'item_id' => (int)$l['item_id'], 'item_name' => $l['item_name'], 'unit_code' => $l['unit_code'] ?? '',
            'quantity' => poFmtQtyPlain($l['quantity_ordered']), 'unit_cost' => number_format((float)$l['unit_cost'], 2, '.', ''),
        ],
        $lineStmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

$pageTitle = $isEdit ? 'Edit Purchase Order' : 'New Purchase Order';

$suppliers = $pdo->query('SELECT supplier_id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name')->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query(
    "SELECT i.item_id, i.item_name, i.is_vat_exempt, i.preferred_supplier_id, i.last_purchase_cost, u.unit_code
     FROM inventory_items i
     LEFT JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
     WHERE i.is_active = 1
     ORDER BY i.item_name"
)->fetchAll(PDO::FETCH_ASSOC);

/* What this supplier last charged for each item, from their own PO history.
   This beats inventory_items.last_purchase_cost as a default because that
   column is whatever was received LAST from ANYONE (the
   trg_update_last_purchase_cost trigger rewrites it on every batch insert),
   so on a two-supplier item it can easily be the other supplier's price.
   Cancelled POs are excluded -- a price nobody ever paid is not a reference.
   Same "supplier-specific first, last purchase price second" order SAP,
   ERPNext and Odoo all use. */
$supplierItemPrices = [];
foreach ($pdo->query(
    "SELECT po.supplier_id, poi.item_id, poi.unit_cost
     FROM purchase_order_items poi
     JOIN purchase_orders po ON po.po_id = poi.po_id
     JOIN (
         SELECT po2.supplier_id, poi2.item_id, MAX(po2.po_id) AS latest_po_id
         FROM purchase_order_items poi2
         JOIN purchase_orders po2 ON po2.po_id = poi2.po_id
         WHERE po2.status <> 'cancelled'
         GROUP BY po2.supplier_id, poi2.item_id
     ) latest ON latest.latest_po_id = po.po_id AND latest.item_id = poi.item_id
     WHERE poi.unit_cost > 0"
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $supplierItemPrices[(string)$row['supplier_id']][(string)$row['item_id']] = number_format((float)$row['unit_cost'], 2, '.', '');
}

$itemOptions = array_map(
    fn($i) => [
        'id' => (string)$i['item_id'],
        'label' => $i['item_name'] . ' (' . ($i['unit_code'] ?? '') . ')',
        'unit_code' => $i['unit_code'] ?? '',
        'is_vat_exempt' => (bool)$i['is_vat_exempt'],
        'preferred_supplier_id' => $i['preferred_supplier_id'] !== null ? (string)$i['preferred_supplier_id'] : null,
        // Fallback default when this supplier has no history for the item.
        'last_cost' => ($i['last_purchase_cost'] !== null && (float)$i['last_purchase_cost'] > 0)
            ? number_format((float)$i['last_purchase_cost'], 2, '.', '')
            : '',
    ],
    $items
);

$taxSettings = poGetActiveTaxSettings($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
<style>
    /* Says where a prefilled unit cost came from, so a suggested number is
       never mistaken for one somebody actually confirmed with the supplier.
       Disappears the moment the buyer types their own figure. */
    .po-cost-source{ font-size:0.68rem; color:var(--op-ink-faint); margin-top:3px; max-width:150px; line-height:1.3; }
</style>
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

            <div style="margin-bottom:16px;">
                <a href="<?= htmlspecialchars(back_link('purchase_orders.php')) ?>" class="owner-btn owner-btn-secondary owner-btn-sm">
                    <i class="ph ph-arrow-left" aria-hidden="true"></i> Back
                </a>
            </div>

            <?= flash_render() ?>

            <form method="post" action="purchase_order_save.php" id="poForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="po_id" value="<?= $isEdit ? (int)$poId : '' ?>">
                <input type="hidden" name="line_items" id="poLineItemsField" value="">

                <div class="owner-form-note owner-alert owner-alert-error" id="poFormAlert" hidden>
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span id="poFormAlertText">Please fix the highlighted fields before saving.</span>
                </div>

                <div class="owner-card" style="margin-bottom:20px;">
                    <div class="owner-card-head">
                        <h2 class="owner-card-title"><?= htmlspecialchars($pageTitle) ?></h2>
                    </div>
                    <div class="owner-form-grid">
                        <div class="owner-form-group" data-combobox>
                            <label for="supplierSearch">Supplier <span class="owner-required" aria-hidden="true">*</span></label>
                            <div class="owner-combobox-control">
                                <input type="text" id="supplierSearch" class="owner-input owner-combobox-input" placeholder="Search supplier&hellip;" autocomplete="off" role="combobox" aria-expanded="false" data-options='<?= poComboboxOptions($suppliers, "supplier_id", "supplier_name") ?>'>
                                <input type="hidden" name="supplier_id" id="supplierId" value="<?= htmlspecialchars((string)$prefill['supplier_id']) ?>" required>
                                <i class="ph ph-caret-down owner-combobox-caret" aria-hidden="true"></i>
                                <ul class="owner-combobox-panel" id="supplierListbox" role="listbox"></ul>
                            </div>
                            <span class="owner-form-error" data-error-for="supplier_id"></span>
                        </div>
                        <div class="owner-form-group">
                            <label for="orderDate">Order date <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="date" id="orderDate" name="order_date" class="owner-input" value="<?= htmlspecialchars($prefill['order_date']) ?>" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="expectedDeliveryDate">Expected delivery <span class="owner-form-optional">(optional)</span></label>
                            <input type="date" id="expectedDeliveryDate" name="expected_delivery_date" class="owner-input" value="<?= htmlspecialchars($prefill['expected_delivery_date']) ?>">
                        </div>
                        <div class="owner-form-group owner-form-group-full">
                            <label for="poRemarks">Remarks <span class="owner-form-optional">(optional)</span></label>
                            <textarea id="poRemarks" name="remarks" class="owner-input" rows="2"><?= htmlspecialchars($prefill['remarks']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="owner-card" style="margin-bottom:20px;">
                    <div class="owner-card-head">
                        <div>
                            <h2 class="owner-card-title">Line items</h2>
                            <span class="owner-card-subtitle" id="poLineCount">0 items</span>
                        </div>
                    </div>

                    <div class="owner-form-grid" style="margin-bottom:14px;">
                        <div class="owner-form-group owner-form-group-full" data-combobox>
                            <label for="itemSearch">Add an item</label>
                            <div class="owner-combobox-control">
                                <input type="text" id="itemSearch" class="owner-input owner-combobox-input" placeholder="Search inventory items&hellip;" autocomplete="off" role="combobox" aria-expanded="false" data-options='<?= poComboboxOptions($itemOptions, "id", "label") ?>'>
                                <i class="ph ph-caret-down owner-combobox-caret" aria-hidden="true"></i>
                                <ul class="owner-combobox-panel" id="itemListbox" role="listbox"></ul>
                            </div>
                            <span class="owner-form-hint" id="itemSearchHint">Showing items linked to the selected supplier, plus unassigned items. Pick a supplier above to narrow this list.</span>
                        </div>
                    </div>

                    <div class="owner-table-wrap">
                        <table class="owner-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Unit cost</th>
                                    <th>Line total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="poLinesBody">
                                <tr id="poLinesEmptyRow"><td colspan="5" class="owner-table-empty">No items added yet. Search above to add one.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                        <div style="min-width:260px;">
                            <div style="display:flex;justify-content:space-between;padding:4px 0;"><span>Subtotal</span><strong id="poSubtotal">&#8369;0.00</strong></div>
                            <div style="display:flex;justify-content:space-between;padding:4px 0;"><span><?= htmlspecialchars($taxSettings['tax_name']) ?> (<?= htmlspecialchars(rtrim(rtrim(number_format((float)$taxSettings['tax_rate'], 2), '0'), '.')) ?>%)</span><strong id="poVat">&#8369;0.00</strong></div>
                            <div style="display:flex;justify-content:space-between;padding:8px 0;border-top:1px solid var(--op-border);margin-top:4px;font-size:1.05rem;"><span>Total</span><strong id="poTotal">&#8369;0.00</strong></div>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <a href="purchase_orders.php" class="owner-btn owner-btn-secondary">Cancel</a>
                    <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Save purchase order</button>
                </div>
            </form>

        </main>

    </div>

</div>

<script>
(function () {
    const initialLines = <?= json_encode($initialLines) ?>;
    const itemOptionsById = {};
    <?php foreach ($itemOptions as $opt): ?>
        itemOptionsById[<?= json_encode($opt['id']) ?>] = <?= json_encode($opt) ?>;
    <?php endforeach; ?>
    const SUPPLIER_ITEM_PRICES = <?= json_encode($supplierItemPrices) ?>;
    const TAX_SETTINGS = <?= json_encode($taxSettings) ?>;

    const state = { lines: initialLines.slice() };

    const linesBody   = document.getElementById('poLinesBody');
    const emptyRow    = document.getElementById('poLinesEmptyRow');
    const lineCountEl = document.getElementById('poLineCount');
    const hiddenField = document.getElementById('poLineItemsField');
    const form        = document.getElementById('poForm');

    function round2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }
    function formatCurrency(n) { return '₱' + round2(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    // Mirrors computePOTotals() in po_functions.php exactly — a live
    // preview only; the server re-computes and is the source of truth.
    function computeTotals() {
        const rate = parseFloat(TAX_SETTINGS.tax_rate) || 0;
        const inclusive = TAX_SETTINGS.tax_type === 'inclusive';
        let subtotal = 0, vatAmount = 0;

        state.lines.forEach((line) => {
            const opt = itemOptionsById[String(line.item_id)];
            const exempt = opt ? !!opt.is_vat_exempt : false;
            const gross = round2((parseFloat(line.quantity) || 0) * (parseFloat(line.unit_cost) || 0));
            let lineNet, lineVat;
            if (inclusive) {
                lineVat = exempt ? 0 : round2((gross * rate) / (100 + rate));
                lineNet = round2(gross - lineVat);
            } else {
                lineNet = gross;
                lineVat = exempt ? 0 : round2((lineNet * rate) / 100);
            }
            subtotal += lineNet;
            vatAmount += lineVat;
        });

        subtotal = round2(subtotal);
        vatAmount = round2(vatAmount);
        return { subtotal, vat_amount: vatAmount, total_amount: round2(subtotal + vatAmount) };
    }

    function renderTotals() {
        const t = computeTotals();
        document.getElementById('poSubtotal').textContent = formatCurrency(t.subtotal);
        document.getElementById('poVat').textContent = formatCurrency(t.vat_amount);
        document.getElementById('poTotal').textContent = formatCurrency(t.total_amount);
    }

    function renderLines() {
        linesBody.innerHTML = '';
        if (state.lines.length === 0) {
            linesBody.appendChild(emptyRow);
            lineCountEl.textContent = '0 items';
            renderTotals();
            return;
        }

        state.lines.forEach((line, idx) => {
            const lineTotal = round2((parseFloat(line.quantity) || 0) * (parseFloat(line.unit_cost) || 0));
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(line.item_name)}</td>
                <td><input type="number" step="0.001" min="0.001" class="owner-input po-line-qty" style="max-width:110px;" value="${escapeHtml(line.quantity)}" data-idx="${idx}">${line.unit_code ? ` <span class="owner-input-unit">${escapeHtml(line.unit_code)}</span>` : ''}</td>
                <td>
                    <input type="number" step="0.01" min="0" class="owner-input po-line-cost" style="max-width:120px;" value="${escapeHtml(line.unit_cost)}" data-idx="${idx}">
                    ${line.cost_source ? `<div class="po-cost-source">${escapeHtml(line.cost_source)}</div>` : ''}
                </td>
                <td class="po-line-total">${formatCurrency(lineTotal)}</td>
                <td>
                    <button type="button" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon po-line-remove" data-idx="${idx}" aria-label="Remove item">
                        <i class="ph ph-trash" aria-hidden="true"></i>
                    </button>
                </td>
            `;
            linesBody.appendChild(tr);
        });

        lineCountEl.textContent = state.lines.length + (state.lines.length === 1 ? ' item' : ' items');
        renderTotals();

        // Typing in a quantity/cost field only updates that row's own total
        // + the overall totals -- it must NOT call the full renderLines()
        // again. renderLines() rebuilds every <tr> (and therefore every
        // <input>) from scratch, which destroys the very input the user is
        // actively typing into and forces them to click back into it after
        // every single character.
        linesBody.querySelectorAll('.po-line-qty').forEach((input) => {
            input.addEventListener('input', () => {
                const idx = parseInt(input.getAttribute('data-idx'), 10);
                state.lines[idx].quantity = input.value;
                updateLineTotalOnly(idx);
            });
        });
        linesBody.querySelectorAll('.po-line-cost').forEach((input) => {
            input.addEventListener('input', () => {
                const idx = parseInt(input.getAttribute('data-idx'), 10);
                state.lines[idx].unit_cost = input.value;
                // Once it is the buyer's own number, stop crediting history for
                // it. Removed from the DOM directly rather than via renderLines()
                // -- a full re-render here would rebuild the input the user is
                // typing into and lose the caret (see the note below).
                if (state.lines[idx].cost_source) {
                    state.lines[idx].cost_source = '';
                    const caption = input.parentElement.querySelector('.po-cost-source');
                    if (caption) caption.remove();
                }
                updateLineTotalOnly(idx);
            });
        });
        linesBody.querySelectorAll('.po-line-remove').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.lines.splice(parseInt(btn.getAttribute('data-idx'), 10), 1);
                renderLines();
            });
        });
    }

    /** Updates just one row's line-total cell + the overall totals in place, without touching any <input> element -- see the comment above where this is called from. */
    function updateLineTotalOnly(idx) {
        const line = state.lines[idx];
        if (!line) return;
        const lineTotal = round2((parseFloat(line.quantity) || 0) * (parseFloat(line.unit_cost) || 0));
        const row = linesBody.children[idx];
        const totalCell = row ? row.querySelector('.po-line-total') : null;
        if (totalCell) totalCell.textContent = formatCurrency(lineTotal);
        renderTotals();
    }

    /* The unit cost a new line opens on, and where the number came from.
       Order matches SAP / ERPNext / Odoo: what THIS supplier last charged
       beats the item's last received cost, which beats nothing at all. A
       suggestion only -- the field stays editable, because the invoice in the
       buyer's hand always wins over history. */
    function suggestedUnitCost(itemId) {
        // Looked up here, not captured: the page's `supplierHidden` const lives
        // inside initItemCombobox()'s own scope and isn't visible from here.
        const supplierField = document.getElementById('supplierId');
        const supplierId = supplierField ? (supplierField.value || '').trim() : '';
        const bySupplier = supplierId && SUPPLIER_ITEM_PRICES[supplierId]
            ? SUPPLIER_ITEM_PRICES[supplierId][itemId] : undefined;
        if (bySupplier !== undefined && bySupplier !== '') {
            return { cost: bySupplier, source: 'last price from this supplier' };
        }
        const opt = itemOptionsById[itemId];
        if (opt && opt.last_cost) {
            return { cost: opt.last_cost, source: 'last purchase cost' };
        }
        return { cost: '', source: '' };
    }

    function addLine(itemId, itemName, unitCode) {
        const existing = state.lines.find(l => l.item_id === itemId);
        if (existing) { renderLines(); return; }
        const suggested = suggestedUnitCost(itemId);
        state.lines.push({
            item_id: itemId, item_name: itemName, unit_code: unitCode, quantity: '1',
            unit_cost: suggested.cost, cost_source: suggested.source,
        });
        renderLines();
    }

    (function initItemCombobox() {
        const input = document.getElementById('itemSearch');
        const root  = input.closest('[data-combobox]');
        const panel = root.querySelector('.owner-combobox-panel');
        const supplierHidden = document.getElementById('supplierId');
        let options = [];
        try { options = JSON.parse(input.getAttribute('data-options') || '[]'); } catch (e) {}

        // Only items linked to the currently-chosen supplier, plus items with
        // no preferred supplier assigned at all, are orderable on this PO.
        // Re-evaluated live (not cached) so the list updates the moment a
        // supplier is picked -- itemOptionsById carries the real
        // preferred_supplier_id (the id/label-only combobox JSON in
        // `options` above doesn't, same reason it never had unit_code either).
        function supplierFiltered(list) {
            const supplierId = (supplierHidden.value || '').trim();
            return list.filter((o) => {
                const full = itemOptionsById[String(o.id)];
                const linkedTo = full && full.preferred_supplier_id;
                return !linkedTo || String(linkedTo) === supplierId;
            });
        }

        function render(list) {
            panel.innerHTML = '';
            if (list.length === 0) {
                const li = document.createElement('li');
                li.className = 'owner-combobox-empty';
                li.textContent = 'No matches found';
                panel.appendChild(li);
                return;
            }
            list.forEach((opt) => {
                const li = document.createElement('li');
                li.className = 'owner-combobox-option';
                li.textContent = opt.label;
                li.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    const full = itemOptionsById[String(opt.id)];
                    addLine(parseInt(opt.id, 10), opt.label.replace(/\s*\([^)]*\)$/, ''), (full && full.unit_code) || '');
                    input.value = '';
                    panel.classList.remove('is-open');
                });
                panel.appendChild(li);
            });
        }
        function open() {
            const q = input.value.trim().toLowerCase();
            const textMatched = q ? options.filter(o => o.label.toLowerCase().includes(q)) : options;
            render(supplierFiltered(textMatched));
            panel.classList.add('is-open');
        }
        input.addEventListener('focus', open);
        input.addEventListener('input', open);
        document.addEventListener('click', (e) => { if (!root.contains(e.target)) panel.classList.remove('is-open'); });
    })();

    (function initSupplierCombobox() {
        const input  = document.getElementById('supplierSearch');
        const hidden = document.getElementById('supplierId');
        const root   = input.closest('[data-combobox]');
        const panel  = root.querySelector('.owner-combobox-panel');
        let options = [];
        try { options = JSON.parse(input.getAttribute('data-options') || '[]'); } catch (e) {}

        if (hidden.value) {
            const found = options.find(o => String(o.id) === String(hidden.value));
            if (found) input.value = found.label;
        }

        function render(list) {
            panel.innerHTML = '';
            list.forEach((opt) => {
                const li = document.createElement('li');
                li.className = 'owner-combobox-option';
                li.textContent = opt.label;
                li.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    hidden.value = opt.id;
                    input.value = opt.label;
                    input.classList.remove('is-invalid');
                    panel.classList.remove('is-open');
                });
                panel.appendChild(li);
            });
        }
        function open() {
            const q = input.value.trim().toLowerCase();
            render(q ? options.filter(o => o.label.toLowerCase().includes(q)) : options);
            panel.classList.add('is-open');
        }
        input.addEventListener('focus', open);
        input.addEventListener('input', () => { hidden.value = ''; open(); });
        document.addEventListener('click', (e) => { if (!root.contains(e.target)) panel.classList.remove('is-open'); });
    })();

    renderLines();

    form.addEventListener('submit', (e) => {
        const alertEl = document.getElementById('poFormAlert');
        const alertText = document.getElementById('poFormAlertText');
        let message = '';

        if (!document.getElementById('supplierId').value) message = 'Please select a supplier.';
        else if (state.lines.length === 0) message = 'Add at least one item to this purchase order.';
        else {
            for (const line of state.lines) {
                if (!line.quantity || parseFloat(line.quantity) <= 0) { message = 'Every item needs a quantity greater than 0.'; break; }
                if (line.unit_cost === '' || line.unit_cost === null || parseFloat(line.unit_cost) < 0) { message = 'Every item needs a unit cost.'; break; }
            }
        }

        if (message) {
            e.preventDefault();
            alertText.textContent = message;
            alertEl.hidden = false;
            return;
        }

        alertEl.hidden = true;
        hiddenField.value = JSON.stringify(state.lines.map(l => ({
            item_id: l.item_id, quantity: l.quantity, unit_cost: l.unit_cost,
        })));
    });
})();
</script>

</body>
</html>
