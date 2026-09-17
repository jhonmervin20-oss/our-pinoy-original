<?php
/**
 * customer/make_reservation.php
 *
 * The reservation booking wizard: Reservation Details -> Advance Order
 * -> Advance food order -> Summary & Payment
 * -> redirect to PayMongo Checkout. All business rules (fee, deposit %,
 * guest limits, lead time, capacity, operating days, closed dates) are
 * read from system_settings/reservation_blackouts here and passed to the
 * client as data — nothing is hardcoded in make-reservation.js.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/reservation_functions.php';
require_once __DIR__ . '/includes/menu_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['customer'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Not 'reservations' -- that's the separate "My Reservations" list page
// (reservations.php). This is the booking wizard, reached via the "Book a
// Reservation" CTA/FAB, not the Reservations tab -- reusing that key made
// the Reservations tab light up while on an unrelated page.
$activePage = 'make_reservation';

$db = Database::getInstance()->getConnection();

$settings    = getReservationSettings($db);
$activeSlots = getActiveTimeSlots($db);

// The only badge shown on the advance-order menu cards is Out of Stock,
// backed by real data (see includes/menu_functions.php) -- no vegetarian/
// spicy flags or allergen tags here, since nothing in the schema tracks
// those and fabricating them would be actively misleading on a real menu.
// Advance food ordering is a core feature of this system, always available.
// This used to be wrapped in `if ($settings['advance_order_enabled'])`, a
// Reservation Settings checkbox that could silently drop a documented step out
// of the booking wizard. The two settings that SHAPE the feature -- minimum
// order amount and deposit percentage -- are still configurable.
$menuCatalog = $db->query(
    "SELECT mi.item_id, mi.item_name, mi.description, mi.image_url, mi.selling_price, mi.created_at,
            mc.category_id, mc.category_name
     FROM menu_items mi
     LEFT JOIN menu_categories mc ON mc.category_id = mi.category_id
     WHERE mi.is_active = 1 AND mi.is_available = 1
     ORDER BY mc.category_name ASC, mi.item_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($menuCatalog as &$item) {
    $item['stock'] = computeMenuItemStock($db, (int)$item['item_id']);
    $item['is_out_of_stock'] = $item['stock'] !== null && $item['stock'] <= 0;
}
unset($item);

// Real active tax rate, used only for the advance-order cart's "Estimated
// Tax"/"Estimated Total" preview lines -- illustrative for the customer,
// since what's actually charged today is just the deposit percentage of
// the pre-order subtotal (see computePaymentDue() in make-reservation.js);
// the rest, including tax, settles at the restaurant.
$taxSettings = $db->query(
    "SELECT tax_name, tax_rate, tax_type FROM tax_settings
     WHERE is_active = 1 ORDER BY effective_date DESC, tax_setting_id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: ['tax_name' => 'VAT', 'tax_rate' => 0, 'tax_type' => 'exclusive'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book a Reservation | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/customer-app.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-app.css') ?>">
<link rel="stylesheet" href="assets/css/make-reservation.css?v=<?= filemtime(__DIR__ . '/assets/css/make-reservation.css') ?>">
</head>
<body class="ca-body ca-standalone">

<!-- No navbar.php here -- the booking wizard is a focused, single-task
     flow, not a place to leave the full nav (pill links, CTA, notifications)
     competing for attention. Just a compact header: a way back out, and
     enough context to know where you are. -->
<header class="ca-topbar">
    <div class="ca-topbar-inner">
        <a href="dashboard.php" class="ca-icon-btn" aria-label="Back to Home">
            <i class="ph ph-arrow-left" aria-hidden="true"></i>
        </a>
        <div class="ca-wizard-topbar-info">
            <span class="ca-wizard-topbar-title">Book a Reservation</span>
            <span class="ca-wizard-topbar-sub">Reserve your table at OPO! Our Pinoy Original</span>
        </div>
        <a href="dashboard.php" class="ca-logo" aria-label="Our Pinoy Original — Home">
            <img src="../assets/images/logo.jpg" alt="OPO! Our Pinoy Original">
        </a>
    </div>
</header>

<main class="ca-content">

    <?= ca_flash_render() ?>

    <div class="ca-wizard" id="reservationWizard">

        <!-- Progress indicator -->
        <div class="ca-wizard-progress" id="wizProgress"></div>

        <div class="ca-wizard-body">

            <!-- Step 1: Reservation Details -->
            <section class="ca-wizard-panel is-active" data-panel="1">
                <div class="ca-card ca-wizard-card">
                    <h2 class="ca-wizard-card-h"><i class="ph ph-calendar-dots" aria-hidden="true"></i> When would you like to dine?</h2>
                    <p class="ca-wizard-card-sub">Pick a date and time slot for your reservation.</p>
                    <div class="ca-date-time-layout">
                        <div class="ca-calendar" id="resCalendar"></div>
                        <div class="ca-slot-panel">
                            <!-- The trailing span is filled in by selectDate() with the
                                 chosen date, so the heading names what the grid below is
                                 actually showing rather than staying generic. -->
                            <label class="ca-slot-panel-label">Available time slots<span id="resSlotDate"></span></label>
                            <div class="ca-slot-placeholder" id="resSlotPlaceholder">
                                <i class="ph ph-calendar-blank" aria-hidden="true"></i>
                                <span>Select a date to see available time slots.</span>
                            </div>
                            <div id="resSlotSection" hidden>
                                <div class="ca-slot-grid" id="resSlots"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ca-card ca-wizard-card">
                    <?php /* Was "Reservation preferences" with a second column for
                             special requests; that field is gone, so the card is named
                             for the only thing left in it and the redundant <h3>
                             underneath was dropped. */ ?>
                    <h2 class="ca-wizard-card-h"><i class="ph ph-users-three" aria-hidden="true"></i> Party size</h2>
                    <p class="ca-prefs-sub">How many guests will be dining?</p>
                    <?php /* Stepper and hint share one row, hint pushed to the far
                             edge -- stacked in a single column they left most of a
                             full-width card empty. */ ?>
                    <div class="ca-party-row">
                        <div class="ca-stepper" id="guestStepper">
                            <button type="button" class="ca-stepper-btn" id="guestMinus" aria-label="Decrease guests">&minus;</button>
                            <span class="ca-stepper-value" id="guestValue">0</span>
                            <button type="button" class="ca-stepper-btn" id="guestPlus" aria-label="Increase guests">+</button>
                            <?php /* The icon sits outside #guestUnit: renderGuestStepper()
                                     sets that element's textContent, which would wipe an
                                     icon nested inside it. */ ?>
                            <span class="ca-stepper-unit-wrap">
                                <i class="ph ph-users" aria-hidden="true"></i>
                                <span id="guestUnit">guests</span>
                            </span>
                        </div>
                        <?php /* States the real limits from system_settings rather
                                 than "you can adjust this later": the stepper opens on
                                 the configured minimum and disables the minus button
                                 there, and without this line nothing explains why it
                                 won't go lower. Anything below the minimum is rejected
                                 by the sp_check_reservation_min_guests trigger anyway,
                                 so it is a hard limit, not a suggestion. */ ?>
                        <p class="ca-prefs-hint">
                            <i class="ph ph-info" aria-hidden="true"></i>
                            Reservations are for <?= (int)$settings['reservation_min_guests'] ?>&ndash;<?= (int)$settings['reservation_max_guests'] ?> guests.
                        </p>
                    </div>
                    <span class="ca-form-error" id="guestError"></span>
                </div>
            </section>

            <!-- Step 2: Advance Order.
                 On desktop this splits into menu + a sticky "My Order" panel
                 (.ca-advance-layout becomes a 2-column grid at >=1100px); on
                 phones the layout stays single-column and the order summary is
                 the existing bottom cart bar instead. Both are driven from the
                 same state.cart, so neither can drift from the other. -->
            <section class="ca-wizard-panel" data-panel="2">
                <div class="ca-advance-layout">
                    <div class="ca-advance-main">
                        <div class="ca-card ca-wizard-card" id="advanceOrderPrompt">
                            <h2>Would you like to place an advance order?</h2>
                            <p class="ca-wizard-card-sub">Pre-order your food so it's ready when you arrive.</p>
                            <div class="ca-toggle-choice">
                                <div class="ca-toggle-card" id="advOrderYes">
                                    <strong>Yes, I'll pre-order</strong>
                                    <span class="ca-toggle-card-sub">Let us know your preferences</span>
                                </div>
                                <div class="ca-toggle-card" id="advOrderNo">
                                    <strong>No, just the table</strong>
                                    <span class="ca-toggle-card-sub">I'll order when I arrive.</span>
                                </div>
                            </div>
                        </div>

                        <div id="advanceOrderMenu" hidden>
                            <!-- The card classes live on #menuGrid itself, not a
                                 wrapper: renderMenu() replaces this element's
                                 innerHTML, never the element, so they survive
                                 every re-render. Groups the AI filter, the
                                 search + category chips and the item grid into
                                 one surface, as a sibling of the prompt card
                                 above rather than a box nested inside it. -->
                            <div id="menuGrid" class="ca-card ca-wizard-card"></div>
                        </div>
                    </div>

                    <!-- Desktop-only order summary. Hidden by CSS below 1100px,
                         where the bottom cart bar covers the same job. -->
                    <aside class="ca-order-panel" id="orderPanel" hidden aria-label="Your advance order">
                        <h3 class="ca-order-panel-title">My Order</h3>
                        <div class="ca-order-panel-lines" id="orderPanelLines"></div>
                        <div class="ca-order-panel-totals" id="orderPanelTotals"></div>
                        <button type="button" class="ca-btn ca-btn-primary ca-order-panel-continue" id="orderPanelContinue">
                            Continue to Summary &amp; Payment <i class="ph ph-arrow-right" aria-hidden="true"></i>
                        </button>
                    </aside>
                </div>
            </section>

            <!-- Step 3: Summary & Payment -->
            <section class="ca-wizard-panel" data-panel="3">
                <div class="ca-card ca-wizard-card">
                    <h2>Review &amp; pay</h2>
                    <p class="ca-wizard-card-sub">Please confirm your reservation before paying.</p>
                    <div class="ca-receipt" id="summaryReceipt"></div>
                </div>

                <!-- Grounded in the restaurant's real, owner-configured
                     settings (lead time, hold window, no-show grace period)
                     rather than generic boilerplate -- so this always
                     matches what the system actually does, not just what a
                     legal template says. -->
                <div class="ca-card ca-wizard-card">
                    <h2>Terms &amp; Conditions</h2>
                    <ul class="ca-terms-list">
                        <li>The reservation fee (or, if you place an advance order, your deposit) secures your table today and is <strong>non-refundable</strong>.</li>
                        <li>Reservations must be made at least <?= (int)$settings['reservation_min_lead_hours'] ?> hour<?= (int)$settings['reservation_min_lead_hours'] === 1 ? '' : 's' ?> before your chosen time slot.</li>
                        <li>If payment isn't completed within <?= (int)$settings['reservation_hold_minutes'] ?> minutes of starting checkout, your hold is released automatically and no charge is made.</li>
                        <li>If you haven't checked in within <?= (int)$settings['reservation_no_show_hours'] ?> hour<?= (int)$settings['reservation_no_show_hours'] === 1 ? '' : 's' ?> of your reserved time, your reservation is marked a no-show and the fee/deposit already paid is forfeited.</li>
                        <li>Any remaining balance &mdash; food, drinks, and service beyond what's paid today &mdash; is settled directly at the restaurant.</li>
                    </ul>
                    <label class="ca-terms-agree">
                        <input type="checkbox" id="agreeTerms">
                        <span>I have read and agree to the Terms &amp; Conditions above.</span>
                    </label>
                    <span class="ca-form-error" id="termsError"></span>
                </div>

                <span class="ca-form-error" id="checkoutError" style="display:block;margin-top:14px;"></span>
            </section>

        </div>

        <div class="ca-wizard-nav">
            <?php /* Replaces the native alert() the details step used to throw for
                     "pick a date and time slot" / party-size problems, and carries
                     its own dismiss button instead of borrowing the browser's.

                     Nested INSIDE the nav rather than placed before it: the nav is
                     position:fixed at the bottom of the viewport, so a sibling in
                     normal flow would render at the end of the wizard content --
                     nowhere near the button that raised it. As a full-width row of
                     the bar itself it is pinned with it, directly above Continue.
                     Safe here because goNext() only raises this on the details
                     step, and .is-replaced-by-cartbar (which hides the whole nav)
                     is only ever set on the advance step.

                     role="alert" so it is announced when goNext() unhides it. */ ?>
            <div class="ca-wizard-alert" id="wizardAlert" role="alert" hidden>
                <i class="ph ph-warning-circle" aria-hidden="true"></i>
                <span id="wizardAlertText"></span>
                <button type="button" class="ca-wizard-alert-close" id="wizardAlertClose">Got it</button>
            </div>
            <button type="button" class="ca-btn ca-btn-secondary" id="wizBack">
                <i class="ph ph-arrow-left" aria-hidden="true"></i> Back
            </button>
            <button type="button" class="ca-btn ca-btn-primary" id="wizNext">
                Continue <i class="ph ph-arrow-right" aria-hidden="true"></i>
            </button>
        </div>

    </div>

</main>

<!-- Sticky cart summary -- fixed to the viewport, only shown once the
     customer has said "Yes, I'll pre-order" and added something. Collapsed
     by default (icon + count + total + "View Order"); tapping expands it in
     place into the itemized order + totals + Continue, replacing the
     wizard's own Back/Continue bar for as long as it's showing (see
     toggleCartBarMode() in make-reservation.js). -->
<div class="ca-cart-bar" id="cartBar" hidden>
    <button type="button" class="ca-cart-bar-summary" id="cartBarToggle" aria-expanded="false" aria-controls="cartSheetBody">
        <span class="ca-cart-bar-icon">
            <i class="ph ph-shopping-cart" aria-hidden="true"></i>
            <span class="ca-cart-bar-count" id="cartBarCount">0</span>
        </span>
        <span class="ca-cart-bar-text">
            <strong id="cartBarItemsLabel">0 items</strong>
            <span id="cartBarTotal">Total: &#8369;0.00</span>
        </span>
        <span class="ca-cart-bar-action">
            View Order <i class="ph ph-caret-up" id="cartBarCaret" aria-hidden="true"></i>
        </span>
    </button>
    <div class="ca-cart-sheet-body" id="cartSheetBody">
        <div class="ca-cart-sheet-handle"></div>
        <h3 class="ca-cart-sheet-title">My Order</h3>
        <div class="ca-cart-sheet-lines" id="cartSheetLines"></div>
        <div class="ca-cart-sheet-totals" id="cartSheetTotals"></div>
        <button type="button" class="ca-btn ca-btn-primary ca-cart-sheet-continue" id="cartSheetContinue">
            Continue <i class="ph ph-arrow-right" aria-hidden="true"></i>
        </button>
    </div>
</div>

<!-- Item detail bottom sheet -- opened by tapping a menu card. Ingredients/
     allergens aren't shown here: nothing in the menu_items schema tracks
     either yet, and this app already went out of its way to remove a
     "Stock: 1352"-style badge from the plain browse-menu page for being
     noise, so inventing dietary/ingredient data here would be worse than
     noise -- it'd be actively misleading on a real menu. -->
<div class="ca-sheet-backdrop" id="itemSheetBackdrop">
    <div class="ca-item-sheet" id="itemSheet">
        <button type="button" class="ca-modal-close ca-item-sheet-close" id="itemSheetClose" aria-label="Close">
            <i class="ph ph-x" aria-hidden="true"></i>
        </button>
        <div class="ca-item-sheet-image-wrap" id="itemSheetImageWrap"></div>
        <div class="ca-item-sheet-body">
            <div class="ca-item-sheet-badges" id="itemSheetBadges"></div>
            <h3 class="ca-item-sheet-name" id="itemSheetName"></h3>
            <p class="ca-item-sheet-desc" id="itemSheetDesc"></p>
            <div class="ca-item-sheet-price" id="itemSheetPrice"></div>

            <div class="ca-item-sheet-footer">
                <div class="ca-stepper" id="itemSheetStepper">
                    <button type="button" class="ca-stepper-btn" id="itemSheetMinus" aria-label="Decrease quantity">&minus;</button>
                    <span class="ca-stepper-value" id="itemSheetQty">1</span>
                    <button type="button" class="ca-stepper-btn" id="itemSheetPlus" aria-label="Increase quantity">+</button>
                </div>
                <button type="button" class="ca-btn ca-btn-primary" id="itemSheetAddBtn">Add to Order</button>
            </div>
        </div>
    </div>
</div>

<div class="ca-loading-overlay" id="loadingOverlay">
    <div class="ca-spinner"></div>
    <div class="ca-loading-text" id="loadingText">Loading&hellip;</div>
</div>

<script>
window.RESERVATION_SETTINGS = <?= json_encode($settings) ?>;
window.TIME_SLOTS = <?= json_encode($activeSlots) ?>;
window.MENU_CATALOG = <?= json_encode($menuCatalog) ?>;
window.TAX_SETTINGS = <?= json_encode($taxSettings) ?>;
window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
</script>
<script src="assets/js/menu-ai-filter.js?v=<?= filemtime(__DIR__ . '/assets/js/menu-ai-filter.js') ?>"></script>
<script src="assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/confirm-modal.js') ?>"></script>
<script src="assets/js/make-reservation.js?v=<?= filemtime(__DIR__ . '/assets/js/make-reservation.js') ?>"></script>

</body>
</html>
