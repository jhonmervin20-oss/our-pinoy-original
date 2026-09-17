<?php
/**
 * owner/settings/discounts.php
 *
 * Discount types the cashier can pick from at checkout (POS discount
 * dropdown). Percentage discounts cut a % off the order's net subtotal;
 * fixed discounts subtract a flat peso amount (clamped to the subtotal so
 * it can never go negative). "VAT exempt" mirrors the PH Senior
 * Citizen/PWD rule -- selecting a discount flagged this way zeroes VAT for
 * the whole order, same as this app already does per-menu-item via
 * menu_items.is_vat_exempt.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/flash.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$db = Database::getInstance()->getConnection();

const DISCOUNT_KINDS = ['percentage', 'fixed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        flash_set('error', 'Your session expired. Please try again.');
        header('Location: discounts.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_discount' || $action === 'update_discount') {
        $discountName   = trim($_POST['discount_name'] ?? '');
        $discountKind   = $_POST['discount_kind'] ?? '';
        $discountValue  = $_POST['discount_value'] ?? '';
        $isVatExempt    = isset($_POST['is_vat_exempt']) ? 1 : 0;
        $isActive       = isset($_POST['is_active']) ? 1 : 0;

        $error = null;
        if ($discountName === '' || mb_strlen($discountName) > 50) {
            $error = 'Please enter a discount name (up to 50 characters).';
        } elseif (!in_array($discountKind, DISCOUNT_KINDS, true)) {
            $error = 'Please choose a valid discount type.';
        } elseif (!is_numeric($discountValue) || (float)$discountValue < 0) {
            $error = 'Discount value must be a number of 0 or more.';
        } elseif ($discountKind === 'percentage' && (float)$discountValue > 100) {
            $error = 'A percentage discount cannot exceed 100.';
        }

        if ($error) {
            flash_set('error', $error);
        } else {
            try {
                if ($action === 'create_discount') {
                    $stmt = $db->prepare(
                        'INSERT INTO discount_types (discount_name, discount_kind, discount_value, is_vat_exempt, is_active)
                         VALUES (?, ?, ?, ?, 1)'
                    );
                    $stmt->execute([$discountName, $discountKind, round((float)$discountValue, 2), $isVatExempt]);
                    flash_set('success', 'Discount added.');
                } else {
                    $discountTypeId = (int)($_POST['discount_type_id'] ?? 0);
                    $stmt = $db->prepare(
                        'UPDATE discount_types SET discount_name = ?, discount_kind = ?, discount_value = ?, is_vat_exempt = ?, is_active = ? WHERE discount_type_id = ?'
                    );
                    $stmt->execute([$discountName, $discountKind, round((float)$discountValue, 2), $isVatExempt, $isActive, $discountTypeId]);
                    flash_set('success', 'Discount updated.');
                }
            } catch (PDOException $e) {
                error_log('discounts.php discount save failed: ' . $e->getMessage());
                flash_set('error', 'Something went wrong saving that discount. Please try again.');
            }
        }
    } elseif ($action === 'toggle_discount_active') {
        $discountTypeId = (int)($_POST['discount_type_id'] ?? 0);
        $db->prepare('UPDATE discount_types SET is_active = NOT is_active WHERE discount_type_id = ?')->execute([$discountTypeId]);
        flash_set('success', 'Discount status updated.');
    }

    header('Location: discounts.php');
    exit;
}

$discounts = $db->query('SELECT * FROM discount_types ORDER BY discount_name ASC')->fetchAll();

$editingDiscountId = isset($_GET['edit_discount_id']) ? (int)$_GET['edit_discount_id'] : null;

$activePage        = 'settings';
$activeSettingsTab = 'discounts';
$pageTitle         = 'Discount management';
$ownerBase         = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Discounts | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/../includes/header.php'; ?>

        <main class="owner-content">
            <?= flash_render() ?>
            <?php require __DIR__ . '/includes/settings_nav.php'; ?>

            <?php if ($editingDiscountId !== null): ?>
                <?php
                $editingDiscount = null;
                foreach ($discounts as $d) {
                    if ((int)$d['discount_type_id'] === $editingDiscountId) { $editingDiscount = $d; break; }
                }
                ?>
                <?php if ($editingDiscount): ?>
                    <form method="POST" action="discounts.php" id="editDiscountForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_discount">
                        <input type="hidden" name="discount_type_id" value="<?= (int)$editingDiscount['discount_type_id'] ?>">
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <div class="owner-card">
                <h2 class="owner-card-title">Discount types</h2>
                <p class="owner-card-subtitle">These appear in the cashier's discount dropdown at checkout. "VAT exempt" also zeroes VAT for the whole order when applied (e.g. Senior Citizen / PWD).</p>

                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Value</th>
                                <th>VAT exempt</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$discounts): ?>
                                <tr><td colspan="6" class="owner-table-empty">No discounts yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($discounts as $discount): ?>
                                <?php if ($editingDiscountId === (int)$discount['discount_type_id']): ?>
                                    <tr class="owner-inline-edit-row">
                                        <td><input type="text" name="discount_name" form="editDiscountForm" class="owner-input" value="<?= htmlspecialchars($discount['discount_name']) ?>" maxlength="50" required></td>
                                        <td>
                                            <select name="discount_kind" form="editDiscountForm" class="owner-select">
                                                <option value="percentage" <?= $discount['discount_kind'] === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                                                <option value="fixed" <?= $discount['discount_kind'] === 'fixed' ? 'selected' : '' ?>>Fixed (₱)</option>
                                            </select>
                                        </td>
                                        <td><input type="number" name="discount_value" form="editDiscountForm" class="owner-input" min="0" max="100000" step="0.01" value="<?= htmlspecialchars($discount['discount_value']) ?>" required></td>
                                        <td>
                                            <label class="owner-toggle" aria-label="VAT exempt">
                                                <input type="checkbox" name="is_vat_exempt" form="editDiscountForm" <?= $discount['is_vat_exempt'] ? 'checked' : '' ?>>
                                                <span class="owner-toggle-track"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <label class="owner-toggle" aria-label="Active">
                                                <input type="checkbox" name="is_active" form="editDiscountForm" <?= $discount['is_active'] ? 'checked' : '' ?>>
                                                <span class="owner-toggle-track"></span>
                                            </label>
                                        </td>
                                        <td class="owner-table-actions">
                                            <button type="submit" form="editDiscountForm" class="owner-btn owner-btn-primary owner-btn-sm"><i class="ph ph-check" aria-hidden="true"></i></button>
                                            <a href="discounts.php" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-x" aria-hidden="true"></i></a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td><?= htmlspecialchars($discount['discount_name']) ?></td>
                                        <td>
                                            <span class="owner-status-pill <?= $discount['discount_kind'] === 'percentage' ? 'is-info' : 'is-neutral' ?>">
                                                <?= $discount['discount_kind'] === 'percentage' ? 'Percentage' : 'Fixed (₱)' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $discount['discount_kind'] === 'percentage'
                                                ? htmlspecialchars(rtrim(rtrim(number_format((float)$discount['discount_value'], 2), '0'), '.')) . '%'
                                                : '₱' . htmlspecialchars(number_format((float)$discount['discount_value'], 2)) ?>
                                        </td>
                                        <td><?= $discount['is_vat_exempt'] ? '<i class="ph ph-check" aria-hidden="true"></i>' : '—' ?></td>
                                        <td>
                                            <form method="POST" action="discounts.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_discount_active">
                                                <input type="hidden" name="discount_type_id" value="<?= (int)$discount['discount_type_id'] ?>">
                                                <label class="owner-toggle" aria-label="Toggle active">
                                                    <input type="checkbox" <?= $discount['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                                    <span class="owner-toggle-track"></span>
                                                </label>
                                            </form>
                                        </td>
                                        <td class="owner-table-actions">
                                            <a href="discounts.php?edit_discount_id=<?= (int)$discount['discount_type_id'] ?>" class="owner-btn owner-btn-secondary owner-btn-icon owner-btn-sm" aria-label="Edit">
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="POST" action="discounts.php" style="margin-top:18px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_discount">
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="new_discount_name">Discount name</label>
                            <input type="text" id="new_discount_name" name="discount_name" class="owner-input" maxlength="50" placeholder="Senior Citizen" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="new_discount_kind">Type</label>
                            <select id="new_discount_kind" name="discount_kind" class="owner-select">
                                <option value="percentage">Percentage</option>
                                <option value="fixed">Fixed (₱)</option>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="new_discount_value">Value</label>
                            <input type="number" id="new_discount_value" name="discount_value" class="owner-input" min="0" max="100000" step="0.01" placeholder="20" required>
                        </div>
                    </div>
                    <div class="owner-toggle-row" style="margin-top:14px;">
                        <div>
                            <div class="owner-toggle-row-label">VAT exempt</div>
                            <div class="owner-toggle-row-desc">Applying this discount also zeroes VAT for the whole order (e.g. Senior Citizen / PWD).</div>
                        </div>
                        <label class="owner-toggle" aria-label="VAT exempt">
                            <input type="checkbox" name="is_vat_exempt">
                            <span class="owner-toggle-track"></span>
                        </label>
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">
                            <i class="ph ph-plus" aria-hidden="true"></i> Add discount
                        </button>
                    </div>
                </form>
            </div>
        </main>

    </div>

</div>

</body>
</html>
