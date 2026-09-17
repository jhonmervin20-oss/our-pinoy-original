<?php
/**
 * owner/suppliers.php
 *
 * Supplier Management: dashboard KPIs, filterable list, and a shared
 * Add/Edit modal. Owner/admin only — no manager-panel link exists for
 * this module (unlike inventory/, which is shared).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/display_helpers.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/supplier_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$activePage = 'suppliers';
$pageTitle  = 'Suppliers';

$suppliers        = [];
$totalSuppliers   = 0;
$suggestedCode      = 'SUP-0001';
$dbError            = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $suppliers = $pdo->query(
        "SELECT supplier_id, supplier_code, supplier_name, contact_person, phone, email, address, tin, notes, is_active
         FROM suppliers
         ORDER BY supplier_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $totalSuppliers    = count($suppliers);

    $suggestedCode = generateSupplierCode($pdo);

    // Profile-modal data for every supplier, batched in one query per
    // dataset (not N+1) — used to render each supplier's "View" modal
    // inline on this page instead of navigating to a separate page.
    $productsBySupplier       = [];
    $posBySupplier            = [];
    $receivingBySupplier      = [];
    $totalPurchasesBySupplier = [];

    if (!empty($suppliers)) {
        $supplierIds  = array_column($suppliers, 'supplier_id');
        $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));

        $productsStmt = $pdo->prepare(
            "SELECT i.item_id, i.item_name, i.preferred_supplier_id, c.category_name, u.unit_name,
                    i.reorder_level, i.last_purchase_cost, i.is_active
             FROM inventory_items i
             JOIN inventory_categories c ON c.category_id = i.category_id
             JOIN unit_of_measures u     ON u.unit_id = i.base_unit_id
             WHERE i.preferred_supplier_id IN ({$placeholders})
             ORDER BY i.item_name"
        );
        $productsStmt->execute($supplierIds);
        foreach ($productsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productsBySupplier[$row['preferred_supplier_id']][] = $row;
        }

        $poStmt = $pdo->prepare(
            "SELECT po_id, po_number, supplier_id, status, order_date, expected_delivery_date, total_amount
             FROM purchase_orders WHERE supplier_id IN ({$placeholders})
             ORDER BY order_date DESC, po_id DESC"
        );
        $poStmt->execute($supplierIds);
        foreach ($poStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $posBySupplier[$row['supplier_id']][] = $row;
        }

        $receivingStmt = $pdo->prepare(
            "SELECT b.batch_id, b.batch_number, b.supplier_id, i.item_name, b.quantity_received, b.unit_cost,
                    b.expiry_date, b.received_date, CONCAT(usr.first_name, ' ', usr.last_name) AS received_by_name
             FROM inventory_batches b
             JOIN inventory_items i ON i.item_id = b.item_id
             LEFT JOIN users usr    ON usr.user_id = b.received_by
             WHERE b.supplier_id IN ({$placeholders})
             ORDER BY b.received_date DESC, b.batch_id DESC"
        );
        $receivingStmt->execute($supplierIds);
        foreach ($receivingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $receivingBySupplier[$row['supplier_id']][] = $row;
        }

        $totalPurchasesStmt = $pdo->prepare(
            "SELECT supplier_id, COALESCE(SUM(total_amount), 0) AS total_purchases
             FROM purchase_orders WHERE supplier_id IN ({$placeholders}) AND status IN ('ordered', 'partially_received', 'received')
             GROUP BY supplier_id"
        );
        $totalPurchasesStmt->execute($supplierIds);
        foreach ($totalPurchasesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totalPurchasesBySupplier[$row['supplier_id']] = (float)$row['total_purchases'];
        }
    }
} catch (PDOException $e) {
    $dbError = "Couldn't load supplier data. Please refresh this page.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Suppliers | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/includes/header.php'; ?>

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
                        <input type="text" id="supplierFilterSearch" placeholder="Search suppliers&hellip;" autocomplete="off">
                    </div>
                    <select id="supplierFilterStatus" class="owner-select">
                        <option value="active" selected>Active</option>
                        <option value="inactive">Archived</option>
                        <option value="">All statuses</option>
                    </select>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="btnClearSupplierFilters">Clear</button>
                    <button type="button" class="owner-btn owner-btn-primary" id="btnOpenAddSupplier" style="margin-left:auto;">
                        <i class="ph ph-plus-circle" aria-hidden="true"></i> Add supplier
                    </button>
                </div>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Supplier</th>
                                <th>Contact</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($suppliers)): ?>
                                <tr><td colspan="7" class="owner-table-empty">No suppliers yet. Add your first one above.</td></tr>
                            <?php else: ?>
                                <?php foreach ($suppliers as $s):
                                    $isActive = (int)$s['is_active'] === 1;
                                    $searchKey = strtolower($s['supplier_name'] . ' ' . $s['supplier_code'] . ' ' . ($s['contact_person'] ?? '') . ' ' . ($s['email'] ?? ''));
                                    $supplierData = [
                                        'supplier_id'     => $s['supplier_id'],
                                        'supplier_code'   => $s['supplier_code'],
                                        'supplier_name'   => $s['supplier_name'],
                                        'contact_person'  => $s['contact_person'],
                                        'phone'           => $s['phone'],
                                        'email'           => $s['email'],
                                        'address'         => $s['address'],
                                        'tin'             => $s['tin'],
                                        'notes'           => $s['notes'],
                                        'is_active'       => $isActive ? 1 : 0,
                                    ];
                                ?>
                                <tr data-name="<?= htmlspecialchars($searchKey) ?>" data-status="<?= $isActive ? 'active' : 'inactive' ?>">
                                    <td><?= htmlspecialchars($s['supplier_code']) ?></td>
                                    <td><?= htmlspecialchars($s['supplier_name']) ?></td>
                                    <td><?= $s['contact_person'] !== null && $s['contact_person'] !== '' ? htmlspecialchars($s['contact_person']) : '&mdash;' ?></td>
                                    <td><?= htmlspecialchars(phoneOrNa($s['phone'])) ?></td>
                                    <td><?= $s['email'] !== null && $s['email'] !== '' ? htmlspecialchars($s['email']) : '&mdash;' ?></td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="owner-status-pill is-active"><i class="ph ph-check" aria-hidden="true"></i> Active</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="owner-table-actions">
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-view-supplier" data-view-supplier="<?= (int)$s['supplier_id'] ?>" aria-label="View supplier">
                                                <i class="ph ph-eye" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon owner-btn-edit-supplier"
                                                aria-label="Edit supplier" data-supplier='<?= htmlspecialchars(json_encode($supplierData), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </button>
                                            <form method="POST" action="supplier_toggle_status.php" style="display:inline;" <?php if ($isActive): ?>data-confirm="Deactivate <?= htmlspecialchars($s['supplier_name']) ?>? It will stay in history but won't be selectable for new purchases."<?php endif; ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="supplier_id" value="<?= (int)$s['supplier_id'] ?>">
                                                <?php if ($isActive): ?>
                                                    <button type="submit" class="owner-btn owner-btn-danger owner-btn-sm owner-btn-icon" aria-label="Deactivate supplier">
                                                        <i class="ph ph-archive" aria-hidden="true"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" aria-label="Activate supplier">
                                                        <i class="ph ph-arrow-clockwise" aria-hidden="true"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="supplierNoMatchRow" class="owner-table-empty-row" hidden>
                                    <td colspan="7" class="owner-table-empty">No suppliers match your filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="owner-pagination" id="supplierPagination" hidden>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="supplierPagePrev"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>
                    <span class="owner-pagination-info" id="supplierPageInfo">Page 1 of 1</span>
                    <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" id="supplierPageNext">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- Add / edit supplier modal -->
<div class="owner-modal-backdrop" id="addSupplierBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="addSupplierTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="addSupplierTitle">Add supplier</h2>
            <button type="button" class="owner-modal-close" id="btnCloseAddSupplier" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="post" action="supplier_save.php" id="addSupplierForm" novalidate>
            <div class="owner-modal-body">

                <?= csrf_field() ?>
                <input type="hidden" name="supplier_id" id="supplierFormSupplierId" value="">

                <div class="owner-form-note owner-alert owner-alert-error" id="addSupplierFormAlert" hidden>
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span>Please fix the highlighted fields before saving.</span>
                </div>

                <!-- Basic information -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Basic information</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="supplier_code">Supplier code <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="text" id="supplier_code" name="supplier_code" class="owner-input" placeholder="SUP-0001" required>
                            <span class="owner-form-hint">Auto-suggested, but you can change it.</span>
                            <span class="owner-form-error" data-error-for="supplier_code"></span>
                        </div>
                        <div class="owner-form-group">
                            <label for="supplier_name">Supplier name <span class="owner-required" aria-hidden="true">*</span></label>
                            <input type="text" id="supplier_name" name="supplier_name" class="owner-input" placeholder="e.g. Manila Fresh Meats Co." required>
                            <span class="owner-form-error" data-error-for="supplier_name"></span>
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Contact</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="contact_person">Contact person <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="contact_person" name="contact_person" class="owner-input" placeholder="e.g. Juan Dela Cruz">
                        </div>
                        <div class="owner-form-group">
                            <label for="phone">Phone <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="phone" name="phone" class="owner-input" placeholder="e.g. 0917 123 4567">
                            <span class="owner-form-error" data-error-for="phone"></span>
                        </div>
                        <div class="owner-form-group">
                            <label for="email">Email <span class="owner-form-optional">(optional)</span></label>
                            <input type="email" id="email" name="email" class="owner-input" placeholder="e.g. orders@supplier.com">
                            <span class="owner-form-error" data-error-for="email"></span>
                        </div>
                        <div class="owner-form-group owner-form-group-full">
                            <label for="address">Address <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="address" name="address" class="owner-input" placeholder="e.g. 123 Rizal St., Quezon City">
                        </div>
                    </div>
                </div>

                <!-- Business details -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Business details</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="tin">TIN <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="tin" name="tin" class="owner-input" placeholder="e.g. 123-456-789-000">
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Notes</h3>
                    <div class="owner-form-grid">
                        <div class="owner-form-group owner-form-group-full">
                            <label for="notes">Notes <span class="owner-form-optional">(optional)</span></label>
                            <textarea id="notes" name="notes" class="owner-input" rows="3" placeholder="Anything worth remembering about this supplier&hellip;"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div class="owner-form-section" hidden>
                    <h3 class="owner-form-section-title">Status</h3>
                    <label class="owner-checkbox-row" for="is_active">
                        <input type="checkbox" id="is_active" name="is_active" value="1" class="owner-checkbox-input" checked>
                        <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                        <span class="owner-checkbox-text">
                            <span class="owner-checkbox-label">Active supplier</span>
                            <span class="owner-checkbox-desc">Inactive suppliers can't be selected for new purchases but remain in history.</span>
                        </span>
                    </label>
                </div>

            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelAddSupplier">Cancel</button>
                <button type="submit" class="owner-btn owner-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> <span id="addSupplierSubmitLabel">Save Supplier</span></button>
            </div>
        </form>
    </div>
</div>

<!-- View supplier profile modals — one per supplier, shown/hidden via JS -->
<?php foreach ($suppliers as $s):
    $sid = (int)$s['supplier_id'];
    $sIsActive = (int)$s['is_active'] === 1;
    $sProducts = $productsBySupplier[$sid] ?? [];
    $sPOs = $posBySupplier[$sid] ?? [];
    $sReceiving = $receivingBySupplier[$sid] ?? [];
    $sTotalPurchases = $totalPurchasesBySupplier[$sid] ?? 0.0;
?>
<div class="owner-modal-backdrop owner-view-supplier-modal" id="viewSupplierBackdrop-<?= $sid ?>">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="viewSupplierTitle-<?= $sid ?>">
        <div class="owner-modal-header">
            <div>
                <h2 class="owner-modal-title" id="viewSupplierTitle-<?= $sid ?>"><?= htmlspecialchars($s['supplier_name']) ?></h2>
                <span class="owner-card-subtitle"><?= htmlspecialchars($s['supplier_code']) ?></span>
            </div>
            <button type="button" class="owner-modal-close" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="owner-modal-body">

            <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
                <?php if ($sIsActive): ?>
                    <span class="owner-status-pill is-active"><i class="ph ph-check" aria-hidden="true"></i> Active</span>
                <?php else: ?>
                    <span class="owner-status-pill is-inactive">Inactive</span>
                <?php endif; ?>
            </div>

            <!-- Key metrics -->
            <div class="owner-form-grid" style="margin-bottom:20px;">
                <div class="owner-summary-card">
                    <div>
                        <div class="owner-summary-card-label">Total purchases</div>
                        <div class="owner-summary-card-value">&#8369;<?= number_format($sTotalPurchases, 2) ?></div>
                        <div class="owner-summary-card-meta">ordered, partially received &amp; received POs</div>
                    </div>
                    <i class="ph ph-currency-circle-dollar" style="font-size:1.6rem;color:var(--op-gold);" aria-hidden="true"></i>
                </div>
            </div>

            <!-- Basic information -->
            <div class="owner-form-grid" style="margin-bottom:24px;">
                <div class="owner-form-group">
                    <label><i class="ph ph-identification-card" aria-hidden="true"></i> Contact person</label>
                    <div><?= $s['contact_person'] !== null && $s['contact_person'] !== '' ? htmlspecialchars($s['contact_person']) : '&mdash;' ?></div>
                </div>
                <div class="owner-form-group">
                    <label><i class="ph ph-phone" aria-hidden="true"></i> Phone</label>
                    <div><?= htmlspecialchars(phoneOrNa($s['phone'])) ?></div>
                </div>
                <div class="owner-form-group">
                    <label><i class="ph ph-envelope" aria-hidden="true"></i> Email</label>
                    <div><?= $s['email'] !== null && $s['email'] !== '' ? htmlspecialchars($s['email']) : '&mdash;' ?></div>
                </div>
                <div class="owner-form-group owner-form-group-full">
                    <label><i class="ph ph-map-pin" aria-hidden="true"></i> Address</label>
                    <div><?= $s['address'] !== null && $s['address'] !== '' ? htmlspecialchars($s['address']) : '&mdash;' ?></div>
                </div>
                <div class="owner-form-group">
                    <label><i class="ph ph-hash" aria-hidden="true"></i> TIN</label>
                    <div><?= $s['tin'] !== null && $s['tin'] !== '' ? htmlspecialchars($s['tin']) : '&mdash;' ?></div>
                </div>
                <div class="owner-form-group owner-form-group-full">
                    <label><i class="ph ph-note" aria-hidden="true"></i> Notes</label>
                    <div><?= $s['notes'] !== null && $s['notes'] !== '' ? nl2br(htmlspecialchars($s['notes'])) : '&mdash;' ?></div>
                </div>
            </div>

            <!-- Products supplied -->
            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Products supplied &mdash; <?= count($sProducts) ?> item<?= count($sProducts) === 1 ? '' : 's' ?></h3>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Unit</th>
                                <th>Reorder level</th>
                                <th>Last purchase cost</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sProducts)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No items list this supplier as preferred yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sProducts as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['item_name']) ?></td>
                                    <td><?= htmlspecialchars($p['category_name']) ?></td>
                                    <td><?= htmlspecialchars($p['unit_name']) ?></td>
                                    <td><?= fmtQtyPlain($p['reorder_level']) ?></td>
                                    <td><?= $p['last_purchase_cost'] !== null ? '&#8369;' . number_format((float)$p['last_purchase_cost'], 2) : '&mdash;' ?></td>
                                    <td>
                                        <?php if ((int)$p['is_active'] === 1): ?>
                                            <span class="owner-status-pill is-active">Active</span>
                                        <?php else: ?>
                                            <span class="owner-status-pill is-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Purchase orders -->
            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Purchase orders &mdash; <?= count($sPOs) ?> order<?= count($sPOs) === 1 ? '' : 's' ?></h3>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>PO #</th>
                                <th>Status</th>
                                <th>Order date</th>
                                <th>Expected delivery</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sPOs)): ?>
                                <tr><td colspan="5" class="owner-table-empty">No purchase orders yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sPOs as $po): ?>
                                <tr>
                                    <td><a href="../purchase_orders/purchase_order_view.php?id=<?= (int)$po['po_id'] ?>"><?= htmlspecialchars($po['po_number']) ?></a></td>
                                    <td><span class="owner-status-pill <?= poStatusPillClass($po['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $po['status']))) ?></span></td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($po['order_date']))) ?></td>
                                    <td><?= $po['expected_delivery_date'] !== null ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '&mdash;' ?></td>
                                    <td>&#8369;<?= number_format((float)$po['total_amount'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Receiving history -->
            <div class="owner-form-section">
                <h3 class="owner-form-section-title">Receiving history &mdash; <?= count($sReceiving) ?> batch<?= count($sReceiving) === 1 ? '' : 'es' ?></h3>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Batch #</th>
                                <th>Item</th>
                                <th>Qty received</th>
                                <th>Unit cost</th>
                                <th>Received date</th>
                                <th>Received by</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sReceiving)): ?>
                                <tr><td colspan="6" class="owner-table-empty">No receiving history yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sReceiving as $r): ?>
                                <tr>
                                    <td><?= $r['batch_number'] !== null ? htmlspecialchars($r['batch_number']) : '&mdash;' ?></td>
                                    <td><?= htmlspecialchars($r['item_name']) ?></td>
                                    <td><?= fmtQtyPlain($r['quantity_received']) ?></td>
                                    <td>&#8369;<?= number_format((float)$r['unit_cost'], 2) ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($r['received_date']))) ?></td>
                                    <td><?= $r['received_by_name'] !== null ? htmlspecialchars($r['received_by_name']) : '&mdash;' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/confirm-modal.js') ?>"></script>
<script>
(function () {
    const suggestedCode = <?= json_encode($suggestedCode) ?>;

    // -- Table filters -------------------------------------------------------
    const search = document.getElementById('supplierFilterSearch');
    const statusFilter = document.getElementById('supplierFilterStatus');
    const clearBtn = document.getElementById('btnClearSupplierFilters');
    const noMatchRow = document.getElementById('supplierNoMatchRow');
    const countEl = document.getElementById('supplierCount');
    const rows = Array.from(document.querySelectorAll('.owner-table tbody tr[data-name]'));

    // -- Pagination (client-side, over the currently filtered set) -----------
    const PAGE_SIZE = 10;
    const pagination = document.getElementById('supplierPagination');
    const pagePrev = document.getElementById('supplierPagePrev');
    const pageNext = document.getElementById('supplierPageNext');
    const pageInfo = document.getElementById('supplierPageInfo');
    let currentPage = 1;

    function applyFilters(resetPage) {
        if (resetPage) currentPage = 1;

        const q      = (search ? search.value : '').trim().toLowerCase();
        const status = statusFilter ? statusFilter.value : '';

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
        if (countEl) countEl.textContent = matched.length + (matched.length === 1 ? ' supplier' : ' suppliers');
        if (pagination) pagination.hidden = matched.length === 0 || totalPages <= 1;
        if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        if (pagePrev) pagePrev.disabled = currentPage <= 1;
        if (pageNext) pageNext.disabled = currentPage >= totalPages;
    }

    if (search)      search.addEventListener('input', () => applyFilters(true));
    if (statusFilter) statusFilter.addEventListener('change', () => applyFilters(true));
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (search) search.value = '';
            if (statusFilter) statusFilter.value = 'active';
            applyFilters(true);
        });
    }
    if (pagePrev) pagePrev.addEventListener('click', () => { currentPage--; applyFilters(false); });
    if (pageNext) pageNext.addEventListener('click', () => { currentPage++; applyFilters(false); });

    applyFilters(true);

    // -- Add / edit supplier modal -------------------------------------------
    const backdrop = document.getElementById('addSupplierBackdrop');
    const openBtn = document.getElementById('btnOpenAddSupplier');
    const closeBtn = document.getElementById('btnCloseAddSupplier');
    const cancelBtn = document.getElementById('btnCancelAddSupplier');
    const form = document.getElementById('addSupplierForm');
    const modalTitle = document.getElementById('addSupplierTitle');
    const submitLabel = document.getElementById('addSupplierSubmitLabel');
    const supplierIdField = document.getElementById('supplierFormSupplierId');

    function openModal() {
        backdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
        const firstField = form.querySelector('input, select');
        if (firstField) firstField.focus();
    }

    function closeModal() {
        backdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    function resetToAddMode() {
        form.reset();
        supplierIdField.value = '';
        document.getElementById('supplier_code').value = suggestedCode;
        modalTitle.textContent = 'Add supplier';
        submitLabel.textContent = 'Save Supplier';
    }

    if (openBtn)   openBtn.addEventListener('click', () => { resetToAddMode(); openModal(); });
    if (closeBtn)  closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', () => { form.reset(); closeModal(); });

    if (backdrop) {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) closeModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
    });

    // -- Edit supplier: populate the shared Add/Edit modal -------------------
    document.querySelectorAll('.owner-btn-edit-supplier').forEach((btn) => {
        btn.addEventListener('click', () => {
            let s;
            try { s = JSON.parse(btn.getAttribute('data-supplier')); } catch (e) { return; }

            form.reset();
            supplierIdField.value = s.supplier_id;
            document.getElementById('supplier_code').value   = s.supplier_code || '';
            document.getElementById('supplier_name').value   = s.supplier_name || '';
            document.getElementById('contact_person').value  = s.contact_person || '';
            document.getElementById('phone').value            = s.phone || '';
            document.getElementById('email').value             = s.email || '';
            document.getElementById('address').value            = s.address || '';
            document.getElementById('tin').value                  = s.tin || '';
            document.getElementById('notes').value                  = s.notes || '';
            document.getElementById('is_active').checked             = !!s.is_active;

            modalTitle.textContent = 'Edit supplier';
            submitLabel.textContent = 'Save Changes';
            openModal();
        });
    });

    // -- Inline validation -----------------------------------------------------
    function fieldGroup(el) { return el.closest('.owner-form-group'); }

    function showFieldError(groupEl, visibleEl, message) {
        if (visibleEl) visibleEl.classList.add('is-invalid');
        const err = groupEl ? groupEl.querySelector('.owner-form-error') : null;
        if (err) err.textContent = message;
    }

    function clearFieldError(groupEl, visibleEl) {
        if (visibleEl) visibleEl.classList.remove('is-invalid');
        const err = groupEl ? groupEl.querySelector('.owner-form-error') : null;
        if (err) err.textContent = '';
    }

    if (form) {
        const formAlert = document.getElementById('addSupplierFormAlert');
        const codeEl    = document.getElementById('supplier_code');
        const nameEl    = document.getElementById('supplier_name');
        const emailEl   = document.getElementById('email');
        const phoneEl   = document.getElementById('phone');

        form.addEventListener('submit', (e) => {
            let hasError = false;

            [codeEl, nameEl, emailEl, phoneEl].forEach((el) => {
                if (el) clearFieldError(fieldGroup(el), el);
            });

            if (!codeEl.value.trim()) {
                showFieldError(fieldGroup(codeEl), codeEl, 'Supplier code is required.');
                hasError = true;
            }
            if (!nameEl.value.trim()) {
                showFieldError(fieldGroup(nameEl), nameEl, 'Supplier name is required.');
                hasError = true;
            }
            if (emailEl.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailEl.value.trim())) {
                showFieldError(fieldGroup(emailEl), emailEl, 'Enter a valid email address.');
                hasError = true;
            }
            if (phoneEl.value.trim() && !/^[0-9+\-\s()]{7,20}$/.test(phoneEl.value.trim())) {
                showFieldError(fieldGroup(phoneEl), phoneEl, 'Enter a valid phone number.');
                hasError = true;
            }

            if (hasError) {
                e.preventDefault();
                if (formAlert) formAlert.hidden = false;
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            } else if (formAlert) {
                formAlert.hidden = true;
            }
        });

        form.addEventListener('reset', () => {
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            form.querySelectorAll('.owner-form-error').forEach(el => { el.textContent = ''; });
            if (formAlert) formAlert.hidden = true;
        });
    }

    <?php if (!empty($_SESSION['_reopen_supplier_modal'])): unset($_SESSION['_reopen_supplier_modal']); ?>
    openModal();
    <?php endif; ?>

    // -- View supplier profile modals ----------------------------------------
    function openViewModal(viewBackdrop) {
        viewBackdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function closeViewModal(viewBackdrop) {
        viewBackdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    document.querySelectorAll('.owner-btn-view-supplier').forEach((btn) => {
        btn.addEventListener('click', () => {
            const modal = document.getElementById('viewSupplierBackdrop-' + btn.getAttribute('data-view-supplier'));
            if (modal) openViewModal(modal);
        });
    });

    document.querySelectorAll('.owner-view-supplier-modal').forEach((viewBackdrop) => {
        const closeBtn = viewBackdrop.querySelector('.owner-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', () => closeViewModal(viewBackdrop));
        viewBackdrop.addEventListener('click', (e) => {
            if (e.target === viewBackdrop) closeViewModal(viewBackdrop);
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.owner-view-supplier-modal.is-open').forEach(closeViewModal);
    });
})();
</script>

<script src="assets/js/filter-persist.js?v=<?= filemtime(__DIR__ . '/assets/js/filter-persist.js') ?>"></script>
</body>
</html>
