<?php
/**
 * purchase_orders/purchase_order_view.php
 *
 * Read-only Purchase Order detail. All 3 roles can view; action buttons
 * are status/role-appropriate (Owner/Admin get Edit/Finalize/Mark as
 * Ordered/Cancel; all 3 roles get Receive Stock once ordered).
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

$poId = trim((string)($_GET['id'] ?? ''));
if ($poId === '' || !ctype_digit($poId)) {
    header('Location: purchase_orders.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare(
    "SELECT po.*, s.supplier_name, s.contact_person, s.phone, s.email AS supplier_email,
            CONCAT(usr.first_name, ' ', usr.last_name) AS created_by_name
     FROM purchase_orders po
     JOIN suppliers s ON s.supplier_id = po.supplier_id
     JOIN users usr   ON usr.user_id = po.created_by
     WHERE po.po_id = ?"
);
$stmt->execute([$poId]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    header('Location: purchase_orders.php');
    exit;
}

// A Manager may only open a PO that has actually been placed with the
// supplier. Everything earlier in the pipeline is the Owner's alone, and
// this check is what stops a guessed ?id= from exposing one.
if (!poCanView($po['status'])) {
    poDenyAccess('Only the owner can view purchase orders that have not been ordered yet.');
}

$pageTitle = $po['po_number'];

$itemsStmt = $pdo->prepare(
    "SELECT poi.po_item_id, poi.item_id, poi.quantity_ordered, poi.quantity_received, poi.unit_cost, poi.subtotal, poi.vat_amount,
            i.item_name, u.unit_code
     FROM purchase_order_items poi
     JOIN inventory_items i       ON i.item_id = poi.item_id
     LEFT JOIN unit_of_measures u ON u.unit_id = i.base_unit_id
     WHERE poi.po_id = ?
     ORDER BY i.item_name"
);
$itemsStmt->execute([$poId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Every pipeline action -- edit, finalize, mark ordered, email, cancel -- is
// Owner-only and gated on poCanManage(), matching the guards those endpoints
// now carry themselves. Receiving is the one thing a Manager does here, so it
// is the only capability not behind that flag.
$canManage     = poCanManage();
$canEdit       = $canManage && $po['status'] === 'draft';
$canFinalize   = $canManage && $po['status'] === 'draft';
$canMarkOrdered = $canManage && $po['status'] === 'approved';
$canCancel     = $canManage && !in_array($po['status'], ['received', 'cancelled'], true);
// Once a PO is fully received there is nothing left to tell the supplier --
// the transaction is closed. Excluding 'received' here (mirroring $canCancel
// above) is what stops a stale "Resend to supplier" button from lingering
// on a completed order.
$canEmail      = $canManage && !in_array($po['status'], ['draft', 'cancelled', 'received'], true);
$supplierHasEmail = $po['supplier_email'] !== null && trim((string)$po['supplier_email']) !== '';
// Real send history, so the button can say "Email" vs "Resend" honestly rather
// than leaving the reader to guess whether the supplier ever heard about this.
$lastEmailedAt = poLastEmailedAt($pdo, (int)$poId, (string)$po['po_number']);
$canReceive    = in_array($po['status'], ['ordered', 'partially_received'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($po['po_number']) ?> | Purchase Orders | <?= $isManager ? 'Manager' : 'Owner' ?> Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/assets/css/owner-panel.css') ?>">
<style>
    /* Purchase order header.
       Previously the identity pills, every action button and the "last emailed"
       hint all sat in one flex row, so three different kinds of thing competed
       at the same visual weight and the hint dangled off the end of the
       buttons. Split into three bands: what this PO IS, what you can DO to it,
       and a quiet status note underneath. */
    .po-head{
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
    }
    .po-head-id{ min-width: 0; flex: 1 1 320px; }

    /* Pills belong next to the number -- they describe the PO, they are not
       things you click. */
    .po-head-title-row{
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 4px;
    }
    .po-head-title-row .owner-card-title{ margin: 0; }
    .po-head-id .owner-card-subtitle{ margin: 0; display: block; }

    .po-head-actions{
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        flex-shrink: 0;
    }
    .po-head-actions form{ display: inline-flex; margin: 0; }

    /* Its own line, so it reads as a fact about the PO rather than a disabled
       button parked at the end of the row. */
    .po-head-note{
        margin-top: 12px;
        font-size: 0.78rem;
        color: var(--op-ink-faint);
    }

    /* Read-only facts, not a form. .owner-form-group renders <label> as a form
       field label and carried form spacing with it; this is a definition list
       in behaviour, so it is styled as one. */
    .po-meta{
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 18px 24px;
        margin-top: 20px;
        padding-top: 18px;
        border-top: 1px solid var(--op-border-soft);
    }
    .po-meta-label{
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--op-ink-faint);
        margin-bottom: 4px;
    }
    .po-meta-value{
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--op-ink);
        overflow-wrap: anywhere;
    }
    .po-remarks{
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid var(--op-border-soft);
    }
    .po-remarks .po-meta-value{ font-weight: 400; line-height: 1.55; color: var(--op-ink-soft); }

    @media (max-width: 720px){
        .po-head-actions{ width: 100%; }
        .po-head-actions > *, .po-head-actions form{ flex: 1 1 auto; }
        .po-head-actions .owner-btn{ width: 100%; justify-content: center; }
    }
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
                <?php // Always the PO list, not back_link()'s "wherever you came from" --
                      // this page is reachable from a notification link, an action button's
                      // own redirect, or a bookmark, and Back should mean the same thing
                      // every time rather than depending on how you got here. ?>
                <a href="purchase_orders.php" class="owner-btn owner-btn-secondary owner-btn-sm">
                    <i class="ph ph-arrow-left" aria-hidden="true"></i> Back
                </a>
            </div>

            <?= flash_render() ?>

            <div class="owner-card" style="margin-bottom:20px;">
                <div class="po-head">
                    <div class="po-head-id">
                        <div class="po-head-title-row">
                            <h2 class="owner-card-title"><?= htmlspecialchars($po['po_number']) ?></h2>
                            <?php // Identity, not actions -- these sit with the number. ?>
                            <?php if ($po['is_auto_generated']): ?>
                                <span class="owner-status-pill is-info" title="Auto-generated based on demand forecasting -- review and approve like any other draft.">Auto-generated</span>
                            <?php endif; ?>
                            <span class="owner-status-pill <?= poOrderStatusPillClass($po['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $po['status']))) ?></span>
                        </div>
                        <span class="owner-card-subtitle">
                            <?php if ($po['is_auto_generated']): ?>
                                Auto-generated by the demand forecast policy sweep on <?= htmlspecialchars(date('M j, Y', strtotime($po['created_at']))) ?>
                            <?php else: ?>
                                Created by <?= htmlspecialchars($po['created_by_name']) ?> on <?= htmlspecialchars(date('M j, Y', strtotime($po['created_at']))) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="po-head-actions">
                        <a href="purchase_order_pdf.php?id=<?= (int)$poId ?>" target="_blank" rel="noopener" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-file-pdf" aria-hidden="true"></i> Preview / Download PDF</a>
                        <?php if ($canEdit): ?>
                            <a href="purchase_order_form.php?id=<?= (int)$poId ?>" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Edit</a>
                        <?php endif; ?>
                        <?php /* At 'approved', emailing the supplier and marking the PO ordered are
                                 the same real-world act, so they're one button. The plain-text
                                 sibling covers orders actually placed by phone or in person --
                                 with local suppliers that's common, and it's also the automatic
                                 fallback when a supplier has no email on file. After this state
                                 "Email to supplier" stands alone below, where it means resend. */ ?>
                        <?php if ($canMarkOrdered && $supplierHasEmail): ?>
                            <form method="post" action="purchase_order_email.php" style="display:inline;" data-confirm="Email <?= htmlspecialchars($po['po_number']) ?> to <?= htmlspecialchars($po['supplier_name']) ?> (<?= htmlspecialchars($po['supplier_email']) ?>) and mark it as ordered?" data-confirm-danger="false">
                                <?= csrf_field() ?>
                                <input type="hidden" name="po_id" value="<?= (int)$poId ?>">
                                <input type="hidden" name="mark_ordered" value="1">
                                <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">
                                    Email &amp; mark as ordered
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php // "Mark as ordered without emailing" is gone: when the supplier
                              // has an address, emailing IS how the order gets placed, and a
                              // second button that silently skips it only invited marking a PO
                              // ordered that nobody actually sent. The plain button survives
                              // ONLY for a supplier with no email on file -- supplier email is
                              // optional (owner/supplier_save.php), and without this branch
                              // such a PO could never leave 'approved' at all. ?>
                        <?php if ($canMarkOrdered && !$supplierHasEmail): ?>
                            <form method="post" action="purchase_order_status.php" style="display:inline;" data-confirm="Mark <?= htmlspecialchars($po['po_number']) ?> as ordered? Confirm you have actually placed this order with the supplier." data-confirm-danger="false">
                                <?= csrf_field() ?>
                                <input type="hidden" name="po_id" value="<?= (int)$poId ?>">
                                <input type="hidden" name="action" value="mark_ordered">
                                <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">
                                    <i class="ph ph-check" aria-hidden="true"></i> Mark as ordered
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canReceive): ?>
                            <a href="purchase_order_receive.php?id=<?= (int)$poId ?>" class="owner-btn owner-btn-primary owner-btn-sm">Receive stock</a>
                        <?php endif; ?>
                        <?php if ($canEmail && $supplierHasEmail && !$canMarkOrdered): ?>
                            <?php // Reached 'ordered' without ever being emailed? Then this is a
                                  // first send. Already sent once? Then it is plainly a resend, and
                                  // saying so is what stops it reading as a duplicate button. ?>
                            <form method="post" action="purchase_order_email.php" style="display:inline;" data-confirm="<?= $lastEmailedAt !== null ? 'Resend' : 'Email' ?> <?= htmlspecialchars($po['po_number']) ?> to <?= htmlspecialchars($po['supplier_name']) ?> (<?= htmlspecialchars($po['supplier_email']) ?>)?" data-confirm-danger="false">
                                <?= csrf_field() ?>
                                <input type="hidden" name="po_id" value="<?= (int)$poId ?>">
                                <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm">
                                    <?= $lastEmailedAt !== null ? 'Resend to supplier' : 'Email to supplier' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                    /* The email state, on its own line under the actions. It used to be a
                       <span> inside the button row with align-self:center, which left it
                       hanging off the end of the buttons looking like a disabled control. */
                    $headNote = null;
                    if ($canEmail && $supplierHasEmail && !$canMarkOrdered) {
                        $headNote = $lastEmailedAt !== null
                            ? 'Last emailed ' . date('M j, Y g:i A', strtotime($lastEmailedAt))
                            : 'Not emailed yet';
                    } elseif ($canEmail && !$supplierHasEmail) {
                        $headNote = 'No email on file for this supplier.';
                    }
                ?>
                <?php if ($headNote !== null): ?>
                    <div class="po-head-note"><?= htmlspecialchars($headNote) ?></div>
                <?php endif; ?>

                <?php // Read-only facts, laid out as definitions rather than form fields. ?>
                <div class="po-meta">
                    <div>
                        <span class="po-meta-label">Supplier</span>
                        <span class="po-meta-value"><?= htmlspecialchars($po['supplier_name']) ?></span>
                    </div>
                    <div>
                        <span class="po-meta-label">Order date</span>
                        <span class="po-meta-value"><?= htmlspecialchars(date('M j, Y', strtotime($po['order_date']))) ?></span>
                    </div>
                    <div>
                        <span class="po-meta-label">Expected delivery</span>
                        <span class="po-meta-value"><?= $po['expected_delivery_date'] !== null ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '&mdash;' ?></span>
                    </div>
                </div>

                <div class="po-remarks">
                    <span class="po-meta-label">Remarks</span>
                    <span class="po-meta-value"><?= $po['remarks'] !== null && $po['remarks'] !== '' ? nl2br(htmlspecialchars($po['remarks'])) : '&mdash;' ?></span>
                </div>
            </div>

            <div class="owner-card">
                <div class="owner-card-head">
                    <h2 class="owner-card-title">Line items</h2>
                    <span class="owner-card-subtitle"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
                </div>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Ordered</th>
                                <th>Received</th>
                                <th>Unit cost</th>
                                <th>Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $it): ?>
                            <tr>
                                <td><?= htmlspecialchars($it['item_name']) ?></td>
                                <td><?= poFmtQtyPlain($it['quantity_ordered']) ?> <?= htmlspecialchars($it['unit_code'] ?? '') ?></td>
                                <td><?= poFmtQtyPlain($it['quantity_received']) ?> <?= htmlspecialchars($it['unit_code'] ?? '') ?></td>
                                <td>&#8369;<?= number_format((float)$it['unit_cost'], 2) ?></td>
                                <td>&#8369;<?= number_format((float)$it['subtotal'] + (float)$it['vat_amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                    <div style="min-width:260px;">
                        <div style="display:flex;justify-content:space-between;padding:4px 0;"><span>Subtotal</span><strong>&#8369;<?= number_format((float)$po['subtotal_amount'], 2) ?></strong></div>
                        <div style="display:flex;justify-content:space-between;padding:4px 0;"><span>VAT</span><strong>&#8369;<?= number_format((float)$po['vat_amount'], 2) ?></strong></div>
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-top:1px solid var(--op-border);margin-top:4px;font-size:1.05rem;"><span>Total</span><strong>&#8369;<?= number_format((float)$po['total_amount'], 2) ?></strong></div>
                        <?php if ($canFinalize || $canCancel): ?>
                        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
                            <?php if ($canCancel): ?>
                                <form method="post" action="purchase_order_status.php" style="display:inline;" data-confirm="Cancel <?= htmlspecialchars($po['po_number']) ?>? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="po_id" value="<?= (int)$poId ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <button type="submit" class="owner-btn owner-btn-danger" style="padding:8px 18px;font-size:0.85rem;">
                                        <i class="ph ph-x" aria-hidden="true"></i> Cancel
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canFinalize): ?>
                                <form method="post" action="purchase_order_status.php" style="display:inline;" data-confirm="Finalize <?= htmlspecialchars($po['po_number']) ?>? It will no longer be editable." data-confirm-danger="false">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="po_id" value="<?= (int)$poId ?>">
                                    <input type="hidden" name="action" value="finalize">
                                    <button type="submit" class="owner-btn owner-btn-primary" style="padding:8px 18px;font-size:0.85rem;">
                                        <i class="ph ph-check" aria-hidden="true"></i> Finalize
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </main>

    </div>

</div>

<script src="../owner/assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/confirm-modal.js') ?>"></script>
</body>
</html>