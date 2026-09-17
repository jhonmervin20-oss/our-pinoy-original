<?php
/**
 * cashier/pos.php
 *
 * POS terminal entry point. Renders the shell + embeds the menu catalog
 * and active tax settings as JSON for pos.js — everything after this initial
 * load (reservation lookup, cart, checkout) happens via cashier/api/*.php
 * fetch() calls, not page reloads.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/pos_guard.php';
require_once __DIR__ . '/includes/pos_functions.php';

$db = Database::getInstance()->getConnection();

$restaurantName = $db->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_name' LIMIT 1"
)->fetchColumn() ?: 'OPO! Our Pinoy Original';

$menuCatalog = getMenuCatalog($db);
$taxSettings = getActiveTaxSettings($db);
$pricingSettings = posGetPricingSettings($db);
$discountTypes = getActiveDiscountTypes($db);

// GCash QR the owner uploaded in Settings > General. Verified on disk here, so a
// row pointing at a deleted file shows the "not set up" note rather than a broken
// image icon in front of a paying customer.
$gcashQrStmt = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
$gcashQrStmt->execute(['gcash_qr_path']);
$gcashQrRel = (string)$gcashQrStmt->fetchColumn();
$gcashQrSrc = ($gcashQrRel !== '' && is_file(__DIR__ . '/../owner/' . $gcashQrRel))
    ? '../owner/' . $gcashQrRel . '?v=' . filemtime(__DIR__ . '/../owner/' . $gcashQrRel)
    : '';
$openShift = getOpenShiftForCashier($db, Session::getUserId());
$activePage = 'pos';

// Shown as an auto-dismissing toast (showToast() in pos.js), not a
// permanent banner -- the POS screen is for selling, not for reading
// status messages. flash_consume() clears it from the session exactly
// like flash_render() would, just handed to JS instead of rendered here.
$flash = flash_consume();

$categories = [];
foreach ($menuCatalog as $item) {
    $catId = $item['category_id'] ?? 0;
    if (!isset($categories[$catId])) {
        $categories[$catId] = $item['category_name'] ?? 'Uncategorized';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<!-- Without this, iOS Safari's Data Detectors auto-linkify anything that
     looks like a date/number in the receipt (Date, Time, item amounts)
     into blue tap-to-act text -- the receipt is a static record, not a
     set of tappable phone/calendar links. -->
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<title>POS | <?= htmlspecialchars($restaurantName) ?></title>
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

        <?php if ($openShift): ?>
            <div class="pos-shift-indicator">
                <span class="pos-shift-indicator-dot" aria-hidden="true"></span>
                <span>Shift Open</span>
                <span class="pos-shift-indicator-sep">&middot;</span>
                <span>Shift #<?= (int)$openShift['shift_id'] ?></span>
                <span class="pos-shift-indicator-sep">&middot;</span>
                <span>Petty cash &#8369;<?= number_format((float)$openShift['opening_cash'], 2) ?></span>
            </div>
        <?php endif; ?>

        <main class="pos-main">

        <section class="pos-menu-pane">

            <div class="pos-search-row">
                <label class="pos-search-field">
                    <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" id="menuSearchInput" placeholder="Search menu&hellip;" autocomplete="off" aria-label="Search menu">
                </label>
                <button type="button" class="pos-btn-secondary pos-link-reservation-btn" id="linkReservationToggle" aria-expanded="false" aria-controls="reservationPanel">
                    <i class="ph ph-link" aria-hidden="true"></i> <span id="linkReservationBtnLabel">Link reservation</span>
                </button>
            </div>

            <div class="pos-card pos-reservation-panel" id="reservationPanel">
                <div class="pos-lookup-row">
                    <input type="text" id="reservationNumberInput" placeholder="e.g. RS-2026-001" autocomplete="off">
                    <button type="button" class="pos-btn-secondary" id="reservationLookupBtn">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i> Look up
                    </button>
                    <button type="button" class="pos-btn-secondary" id="scanQrBtn">
                        <i class="ph ph-qr-code" aria-hidden="true"></i> Scan QR
                    </button>
                </div>
                <div id="reservationResult"></div>
            </div>

            <div class="pos-toolbar">
                <div class="pos-category-pills" id="categoryPills">
                    <button type="button" class="pos-category-pill is-active" data-category="all">All</button>
                    <?php foreach ($categories as $catId => $catName): ?>
                        <button type="button" class="pos-category-pill" data-category="<?= htmlspecialchars((string)$catId) ?>"><?= htmlspecialchars($catName) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pos-item-grid" id="menuItemGrid"></div>

        </section>

        <aside class="pos-cart-pane">
            <div class="pos-cart-scroll" id="cartScroll">
                <div class="pos-cart-empty" id="cartEmptyState">Cart is empty. Tap a menu item to add it.</div>
            </div>

            <div class="pos-checkout">
                <div class="pos-category-pills" id="orderTypeRow">
                    <button type="button" class="pos-category-pill is-active" data-order-type="dine_in">Dine-in</button>
                    <button type="button" class="pos-category-pill" data-order-type="takeout">Takeout</button>
                </div>

                <div class="pos-discount-row">
                    <label for="discountSelect">Discount</label>
                    <select id="discountSelect">
                        <option value="">No discount</option>
                        <?php foreach ($discountTypes as $dt): ?>
                            <option value="<?= (int)$dt['discount_type_id'] ?>"
                                    data-kind="<?= htmlspecialchars($dt['discount_kind']) ?>"
                                    data-value="<?= htmlspecialchars($dt['discount_value']) ?>"
                                    data-vat-exempt="<?= (int)$dt['is_vat_exempt'] ?>">
                                <?= htmlspecialchars($dt['discount_name']) ?>
                                (<?= $dt['discount_kind'] === 'percentage' ? htmlspecialchars(rtrim(rtrim(number_format((float)$dt['discount_value'], 2), '0'), '.')) . '%' : '₱' . htmlspecialchars(number_format((float)$dt['discount_value'], 2)) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pos-totals-row">
                    <span>Subtotal</span>
                    <span id="totalSubtotal">₱0.00</span>
                </div>
                <div class="pos-totals-row is-discount" id="discountRow" style="display:none;">
                    <span id="discountLabel">Discount</span>
                    <span id="totalDiscount">-₱0.00</span>
                </div>
                <div class="pos-totals-row">
                    <span id="totalVatLabel"><?= htmlspecialchars($taxSettings['tax_name']) ?> (<?= htmlspecialchars(rtrim(rtrim(number_format((float)$taxSettings['tax_rate'], 2), '0'), '.')) ?>%)</span>
                    <span id="totalVat">₱0.00</span>
                </div>
                <div class="pos-totals-row" id="packagingFeeRow" style="display:none;">
                    <span>Packaging fee</span>
                    <span id="totalPackagingFee">₱0.00</span>
                </div>
                <div class="pos-totals-row is-credit" id="creditRow" style="display:none;">
                    <span>Reservation credit applied</span>
                    <span id="totalCredit">-₱0.00</span>
                </div>
                <div class="pos-credit-note" id="forfeitedCreditNote" style="display:none;"></div>
                <div class="pos-totals-row is-balance">
                    <span>Balance due</span>
                    <span id="totalBalance">₱0.00</span>
                </div>

                <div id="fullyCoveredNote" class="pos-covered-note" style="display:none;">
                    <i class="ph ph-check-circle" aria-hidden="true"></i> Fully covered by reservation credit
                </div>

                <button type="button" class="pos-checkout-btn" id="checkoutBtn" disabled>Place order</button>
            </div>
        </aside>

        </main>

    </div>

</div>

<?php if (!$openShift): ?>
<!-- Start-shift modal -- no close button, not JS-dismissible: a cashier
     can't take any order until a shift is open, matching real POS
     register/drawer behavior. Blocks the terminal behind it simply by
     covering the viewport (position:fixed, same mechanism the other
     .pos-modal-backdrops use), no extra JS needed to "disable" anything. -->
<div class="pos-modal-backdrop is-open">
    <div class="pos-modal pos-modal-sm">
        <div class="pos-modal-header">
            <h2 class="pos-modal-title">Petty Cash Fund</h2>
        </div>
        <form method="POST" action="shift_start.php">
            <div class="pos-modal-body">
                <?= csrf_field() ?>
                <p class="pos-modal-hint" style="margin-top:0;">Enter the petty cash fund placed in the drawer before accepting customer orders.</p>
                <div class="pos-shift-form-group">
                    <label for="openingCashInput">Petty cash fund</label>
                    <input type="number" id="openingCashInput" name="opening_cash" step="0.01" min="0" placeholder="0.00" inputmode="decimal" required autofocus>
                </div>
            </div>
            <div class="pos-modal-footer">
                <button type="submit" class="pos-checkout-btn">Start Shift</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- QR scanner modal -->
<div class="pos-modal-backdrop" id="qrScannerBackdrop">
    <div class="pos-modal pos-modal-sm">
        <div class="pos-modal-header">
            <h2 class="pos-modal-title">Scan reservation QR</h2>
            <button type="button" class="pos-modal-close" id="qrScannerClose" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="pos-modal-body">
            <div id="qrScannerRegion"></div>
            <p class="pos-modal-hint">Point the camera at the reservation's QR code (shown on the customer's e-ticket).</p>
        </div>
    </div>
</div>

<!-- Payment modal -- shown when "Place order" is clicked and a balance is
     due; skipped entirely when a reservation credit fully covers the order. -->
<div class="pos-modal-backdrop" id="paymentModalBackdrop">
    <div class="pos-modal pos-modal-sm">
        <div class="pos-modal-header">
            <h2 class="pos-modal-title">Payment</h2>
            <button type="button" class="pos-modal-close" id="paymentModalClose" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="pos-modal-body">
            <div class="pos-totals-row is-balance" style="margin-bottom:14px;">
                <span>Balance due</span>
                <span id="paymentModalBalance">₱0.00</span>
            </div>

            <div class="pos-pay-methods" id="payMethods">
                <button type="button" class="pos-pay-btn" data-method="cash">
                    Cash
                </button>
                <button type="button" class="pos-pay-btn" data-method="gcash">
                    GCash
                </button>
            </div>

            <div class="pos-gcash-qr" id="gcashQrPanel" style="display:none;">
                <?php if ($gcashQrSrc !== ''): ?>
                    <p class="pos-gcash-qr-label">Have the customer scan to pay</p>
                    <img src="<?= htmlspecialchars($gcashQrSrc) ?>" alt="GCash QR code">
                    <p class="pos-gcash-qr-hint">Confirm the payment landed in your GCash app before completing the order.</p>
                <?php else: ?>
                    <p class="pos-gcash-qr-missing">
                        <i class="ph ph-warning-circle" aria-hidden="true"></i>
                        No GCash QR uploaded yet &mdash; an owner can add one in Settings &rsaquo; General.
                    </p>
                <?php endif; ?>
            </div>

            <div class="pos-cash-tendered-row" id="cashTenderedRow" style="display:none;">
                <label for="cashTenderedInput">Cash tendered</label>
                <input type="number" id="cashTenderedInput" step="0.01" min="0" placeholder="0.00" inputmode="decimal">
                <span class="pos-cash-change-preview" id="cashChangePreview"></span>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button type="button" class="pos-checkout-btn" id="paymentModalCompleteBtn" disabled>Complete order</button>
        </div>
    </div>
</div>

<!-- Receipt modal -- shown automatically right after checkout -->
<div class="pos-modal-backdrop" id="receiptModalBackdrop">
    <div class="pos-modal">
        <div class="pos-modal-header">
            <h2 class="pos-modal-title">Receipt</h2>
            <button type="button" class="pos-modal-close" id="receiptModalClose" aria-label="Close">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="pos-modal-body" id="receiptModalBody"></div>
        <div class="pos-modal-footer">
            <button type="button" class="pos-btn-secondary" id="receiptModalPrintBtn">Print</button>
            <button type="button" class="pos-checkout-btn" id="receiptModalNewOrderBtn">Start new order</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
window.POS_MENU_CATALOG = <?= json_encode($menuCatalog) ?>;
window.POS_TAX_SETTINGS = <?= json_encode($taxSettings) ?>;
// Per-ingredient stock + per-serving needs, so the stock badge can be
// recomputed live against the cart. Display only -- inventory is still
// deducted server-side after checkout, never from this.
window.POS_STOCK_MODEL = <?= json_encode(getPosStockModel($db)) ?>;
window.POS_PRICING_SETTINGS = <?= json_encode($pricingSettings) ?>;
window.POS_DISCOUNT_TYPES = <?= json_encode($discountTypes) ?>;
window.POS_FLASH = <?= json_encode($flash) ?>;
</script>
<script src="assets/js/pos.js?v=<?= filemtime(__DIR__ . '/assets/js/pos.js') ?>"></script>
</body>
</html>
