/**
 * cashier/assets/js/pos.js
 *
 * Cart state + checkout for the POS terminal. Talks to cashier/api/*.php
 * via fetch() so the cart never needs a page reload mid-transaction.
 *
 * VAT math here mirrors computeOrderTotals() in pos_functions.php exactly —
 * this is a live preview only; the server re-computes and is the source of
 * truth for what actually gets billed.
 */

(function () {
    'use strict';

    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
    // let, not const: refreshMenuCatalog() reassigns this after checkout so
    // the stock badges reflect what the sale just deducted -- everything
    // that reads MENU_CATALOG (renderMenuGrid, the order-btn click handler,
    // computePackagingFee) does so at call time, so a reassignment
    // here is picked up everywhere without any other wiring.
    let MENU_CATALOG = window.POS_MENU_CATALOG || [];
    const TAX_SETTINGS = window.POS_TAX_SETTINGS || { tax_name: 'VAT', tax_rate: 0, tax_type: 'exclusive' };
    const PRICING_SETTINGS = window.POS_PRICING_SETTINGS || {
        packaging_fee_policy: 'separate',
    };
    const DISCOUNT_TYPES = window.POS_DISCOUNT_TYPES || [];
    const FLASH = window.POS_FLASH || null;

    const state = {
        reservation: null,       // full reservation row from lookup, or null
        advanceOrders: [],       // [{advance_order_id, menu_item_id, item_name, unit_price, subtotal, quantity, is_vat_exempt, included}]
        alreadyCheckedOut: false,
        creditRemaining: 0,
        manualItems: [],         // [{menu_item_id, item_name, unit_price, is_vat_exempt, quantity}]
        orderType: 'dine_in',
        paymentMethod: null,     // 'cash' | 'gcash' | null
        activeCategory: 'all',
        searchQuery: '',
        submitting: false,
        discountTypeId: null,
    };

    const el = {
        menuSearchInput: document.getElementById('menuSearchInput'),
        linkReservationToggle: document.getElementById('linkReservationToggle'),
        linkReservationBtnLabel: document.getElementById('linkReservationBtnLabel'),
        reservationPanel: document.getElementById('reservationPanel'),
        reservationInput: document.getElementById('reservationNumberInput'),
        reservationLookupBtn: document.getElementById('reservationLookupBtn'),
        reservationResult: document.getElementById('reservationResult'),
        categoryPills: document.getElementById('categoryPills'),
        menuGrid: document.getElementById('menuItemGrid'),
        orderTypeRow: document.getElementById('orderTypeRow'),
        cartScroll: document.getElementById('cartScroll'),
        cartEmptyState: document.getElementById('cartEmptyState'),
        discountSelect: document.getElementById('discountSelect'),
        discountRow: document.getElementById('discountRow'),
        discountLabel: document.getElementById('discountLabel'),
        totalDiscount: document.getElementById('totalDiscount'),
        totalSubtotal: document.getElementById('totalSubtotal'),
        totalVat: document.getElementById('totalVat'),
        packagingFeeRow: document.getElementById('packagingFeeRow'),
        totalPackagingFee: document.getElementById('totalPackagingFee'),
        creditRow: document.getElementById('creditRow'),
        totalCredit: document.getElementById('totalCredit'),
        forfeitedCreditNote: document.getElementById('forfeitedCreditNote'),
        totalBalance: document.getElementById('totalBalance'),
        fullyCoveredNote: document.getElementById('fullyCoveredNote'),
        payMethods: document.getElementById('payMethods'),
        checkoutBtn: document.getElementById('checkoutBtn'),
        gcashQrPanel: document.getElementById('gcashQrPanel'),
        cashTenderedRow: document.getElementById('cashTenderedRow'),
        cashTenderedInput: document.getElementById('cashTenderedInput'),
        cashChangePreview: document.getElementById('cashChangePreview'),

        paymentModalBackdrop: document.getElementById('paymentModalBackdrop'),
        paymentModalBalance: document.getElementById('paymentModalBalance'),
        paymentModalCompleteBtn: document.getElementById('paymentModalCompleteBtn'),

        reservationScanBtn: document.getElementById('scanQrBtn'),
        qrScannerBackdrop: document.getElementById('qrScannerBackdrop'),
        qrScannerRegion: document.getElementById('qrScannerRegion'),

        receiptModalBackdrop: document.getElementById('receiptModalBackdrop'),
        receiptModalBody: document.getElementById('receiptModalBody'),
        receiptModalPrintBtn: document.getElementById('receiptModalPrintBtn'),
        receiptModalNewOrderBtn: document.getElementById('receiptModalNewOrderBtn'),

        posMenuToggle: document.getElementById('posMenuToggle'),
        posSidebar: document.getElementById('posSidebar'),
        posSidebarBackdrop: document.getElementById('posSidebarBackdrop'),
    };

    // ---- helpers ------------------------------------------------------

    function round2(n) {
        return Math.round((n + Number.EPSILON) * 100) / 100;
    }

    function formatCurrency(n) {
        return '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function showToast(message, type) {
        const existing = document.querySelector('.pos-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = 'pos-toast' + (type ? ' is-' + type : '');
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    // A shift-start success/error message (see pos.php's $flash) shows as
    // this same toast instead of a permanent banner -- consumed server-side
    // already (flash_consume(), not flash_render()), so it never re-appears
    // on refresh.
    if (FLASH && FLASH.message) {
        showToast(FLASH.message, FLASH.type === 'success' ? 'success' : 'error');
    }

    async function postForm(url, params) {
        const body = new URLSearchParams(params);
        body.set('csrf_token', CSRF_TOKEN);

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || 'Something went wrong. Please try again.');
        }
        return data;
    }

    // ---- modals -----------------------------------------------------------

    let qrScanner = null;

    // pos.css's max-height: calc(100dvh - 40px) covers most browsers, but
    // some mobile/tablet Safari versions still misreport dvh (or don't
    // shrink it) inside Stage Manager / windowed Safari, letting the modal
    // grow taller than what's actually visible and pushing its footer
    // buttons below the fold. window.innerHeight is what the browser
    // itself says is visible right now, no unit ambiguity -- setting an
    // inline max-height from it overrides the CSS as a guaranteed floor.
    function clampModalHeight(backdrop) {
        const box = backdrop.querySelector('.pos-modal');
        if (!box) return;
        const viewportHeight = (window.visualViewport && window.visualViewport.height) || window.innerHeight;
        box.style.maxHeight = Math.max(200, viewportHeight - 40) + 'px';
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('is-open');
        document.body.classList.add('pos-modal-open');
        clampModalHeight(modal);
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        document.body.classList.remove('pos-modal-open');

        // Closing the QR modal through ANY path (X button, backdrop click,
        // Escape) must also tear down the camera stream -- routing every
        // close through this one function is what guarantees that.
        if (modal === el.qrScannerBackdrop && qrScanner) {
            const runningScanner = qrScanner;
            qrScanner = null;
            runningScanner.stop().then(() => runningScanner.clear()).catch(() => {});
        }
    }

    document.querySelectorAll('.pos-modal-backdrop').forEach((modal) => {
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(modal); });
        const closeBtn = modal.querySelector('.pos-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', () => closeModal(modal));
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.pos-modal-backdrop.is-open').forEach(closeModal);
    });

    function reclampOpenModals() {
        document.querySelectorAll('.pos-modal-backdrop.is-open').forEach(clampModalHeight);
    }
    window.addEventListener('resize', reclampOpenModals);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', reclampOpenModals);
    }

    // ---- sidebar (mobile off-canvas drawer) --------------------------------

    if (el.posMenuToggle && el.posSidebar && el.posSidebarBackdrop) {
        el.posMenuToggle.addEventListener('click', () => {
            el.posSidebar.classList.toggle('is-open');
            el.posSidebarBackdrop.classList.toggle('is-visible');
        });
        el.posSidebarBackdrop.addEventListener('click', () => {
            el.posSidebar.classList.remove('is-open');
            el.posSidebarBackdrop.classList.remove('is-visible');
        });
    }

    // ---- QR scanner ---------------------------------------------------------
    // No barcode-scanner hardware at the counter, so reservation QR codes
    // (which already just encode the plain reservation_number string -- see
    // customer/reservation_confirm.php's QRious usage) are read via the
    // device camera instead. A successful decode feeds the exact same
    // reservationNumberInput + lookupReservation() path manual typing uses,
    // so linking/credit/checkout behavior is entirely unchanged.

    function startQrScanner() {
        if (typeof Html5Qrcode === 'undefined') {
            showToast('QR scanner is unavailable right now.', 'error');
            return;
        }

        openModal(el.qrScannerBackdrop);
        qrScanner = new Html5Qrcode('qrScannerRegion');
        qrScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: 220 },
            (decodedText) => {
                el.reservationInput.value = decodedText.trim();
                closeModal(el.qrScannerBackdrop);
                lookupReservation();
            },
            () => {} // per-frame "no code found yet" -- expected, not an error
        ).catch(() => {
            showToast('Could not access the camera. Check permissions and try again.', 'error');
            closeModal(el.qrScannerBackdrop);
        });
    }

    if (el.reservationScanBtn) {
        el.reservationScanBtn.addEventListener('click', startQrScanner);
    }

    // ---- VAT math (mirrors computeOrderTotals in pos_functions.php) ----

    function computeTotals(lines) {
        const rate = parseFloat(TAX_SETTINGS.tax_rate) || 0;
        const inclusive = TAX_SETTINGS.tax_type === 'inclusive';

        let subtotal = 0;
        let vatAmount = 0;

        lines.forEach((line) => {
            const gross = line.line_gross;
            const exempt = !!line.is_vat_exempt;
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

    function getSelectedDiscountType() {
        if (!state.discountTypeId) return null;
        return DISCOUNT_TYPES.find((d) => Number(d.discount_type_id) === state.discountTypeId) || null;
    }

    // Mirrors computeDiscountAmount() in pos_functions.php exactly -- clamped
    // to the subtotal so a discount can never push it negative.
    function computeDiscount(subtotal, discountType) {
        if (!discountType || subtotal <= 0) return 0;
        const amount = discountType.discount_kind === 'percentage'
            ? subtotal * (parseFloat(discountType.discount_value) || 0) / 100
            : (parseFloat(discountType.discount_value) || 0);
        return round2(Math.min(Math.max(amount, 0), subtotal));
    }

    function getBillableLines() {
        const discountType = getSelectedDiscountType();
        const forceExempt = !!(discountType && Number(discountType.is_vat_exempt) === 1);

        const lines = [];
        state.advanceOrders.forEach((a) => {
            if (a.included) lines.push({ line_gross: a.subtotal, is_vat_exempt: forceExempt || a.is_vat_exempt });
        });
        state.manualItems.forEach((m) => {
            lines.push({ line_gross: round2(m.unit_price * m.quantity), is_vat_exempt: forceExempt || m.is_vat_exempt });
        });
        return lines;
    }

    // ---- live stock -----------------------------------------------------
    // The badge used to show the figure from page load, so adding 60 of a
    // 63-serving dish still read "Stock: 63". These recompute against the cart
    // on every change, at the INGREDIENT level -- two dishes sharing chicken
    // correctly reduce each other, which a per-item counter cannot express.
    //
    // Nothing here writes to inventory. Stock is deducted server-side after the
    // order commits (deductInventoryForOrderItem), exactly as before.
    const STOCK_MODEL = window.POS_STOCK_MODEL || { stock: {}, recipes: {}, packaging: {} };
    // Mirrors $lowStockThreshold in pos_functions.php's computeMenuItemAvailability().
    const LOW_STOCK_THRESHOLD = 5;

    /** Lines one serving of this item consumes, for the current order type. */
    function consumptionLines(menuItemId) {
        const id = String(menuItemId);
        const recipe = STOCK_MODEL.recipes[id] || [];
        const pkg = state.orderType === 'takeout' ? (STOCK_MODEL.packaging[id] || []) : [];
        return recipe.concat(pkg);
    }

    /** How much of each ingredient the cart has already spoken for. */
    function cartClaims() {
        const claims = {};
        const add = (menuItemId, qty) => {
            consumptionLines(menuItemId).forEach((l) => {
                if (l.need === null) return;
                claims[l.id] = (claims[l.id] || 0) + l.need * qty;
            });
        };
        state.manualItems.forEach((m) => add(m.menu_item_id, m.quantity));
        // Advance-order lines are deducted by the same server path, so they
        // claim stock too -- only the ones actually being included.
        state.advanceOrders.forEach((a) => { if (a.included) add(a.menu_item_id, a.quantity); });
        return claims;
    }

    /**
     * Servings of this item that could still be added, given what the cart has
     * already claimed. null = unconstrained (no recipe/packaging rows at all).
     */
    function remainingFor(menuItemId, claims) {
        const lines = consumptionLines(menuItemId);
        if (!lines.length) return null;
        claims = claims || cartClaims();

        let least = null;
        for (const l of lines) {
            // No unit-conversion path: fail safe to zero rather than pretend it
            // is unlimited, matching computeMenuItemAvailability().
            if (l.need === null) return 0;
            const free = (Number(STOCK_MODEL.stock[l.id]) || 0) - (claims[l.id] || 0);
            const possible = Math.max(0, Math.floor(free / l.need));
            if (least === null || possible < least) least = possible;
        }
        return least;
    }

    /** Total makeable ignoring the cart -- what the badge showed before. */
    function stockCapFor(menuItemId) {
        return remainingFor(menuItemId, {});
    }

    /**
     * Raise a cart line to `wanted`, clamped to what is actually makeable.
     * Returns true if the change went through at the requested size.
     */
    function setLineQuantity(line, wanted) {
        const delta = wanted - line.quantity;
        if (delta <= 0) { line.quantity = Math.max(0, wanted); return true; }

        // remainingFor() already accounts for this line's current quantity, so
        // what matters is whether the INCREASE still fits.
        const free = remainingFor(line.menu_item_id);
        if (free !== null && delta > free) {
            line.quantity += Math.max(0, free);
            showToast(
                free <= 0
                    ? `No more ${line.item_name} can be made with the stock left.`
                    : `Only ${free} more ${line.item_name} can be made.`,
                'error'
            );
            return false;
        }
        line.quantity = wanted;
        return true;
    }

    /**
     * Badge text/class/state for one catalog row, given the cart's claims.
     * Shared by the full grid render and the in-place refresh below so the two
     * can never disagree about what a card should say.
     */
    function isChannelEligible(item) {
        return state.orderType === 'takeout'
            ? Number(item.available_takeout) === 1
            : Number(item.available_dine_in) === 1;
    }

    function badgeStateFor(item, claims) {
        const manuallySoldOut = Number(item.is_available) === 0;
        const channelEligible = isChannelEligible(item);
        const channel = state.orderType === 'takeout' ? 'takeout_status' : 'dine_in_status';
        const count = remainingFor(item.item_id, claims);
        const status = count === null
            ? (item[channel] || 'available')
            : (count <= 0 ? 'unavailable' : (count < LOW_STOCK_THRESHOLD ? 'low_stock' : 'available'));
        const hasCount = count !== null && count !== undefined;

        let cls = 'is-success';
        let text = hasCount ? `Stock: ${count}` : 'Available';
        if (!channelEligible) {
            // Checked first -- a channel restriction (manager-set on the item,
            // e.g. "dine-in only") is a hard rule, unlike a stock shortage,
            // and stays true regardless of what the cart or inventory say.
            cls = 'is-danger';
            text = state.orderType === 'takeout' ? 'Not for Takeout' : 'Not for Dine-in';
        } else if (manuallySoldOut) {
            cls = 'is-danger';
            text = 'Sold Out';
        } else if (status === 'unavailable') {
            cls = 'is-danger';
            text = 'Out of Stock';
        } else if (status === 'low_stock') {
            cls = 'is-warning';
            text = hasCount ? `Low Stock: ${count}` : 'Low Stock';
        } else if (!hasCount) {
            cls = 'is-neutral';
        }
        return { cls, text, status, soldOut: !channelEligible || manuallySoldOut || status === 'unavailable' };
    }

    /**
     * Update ONLY the badge + sold-out state on the cards already on screen.
     *
     * renderMenuGrid() rebuilds innerHTML, which re-creates every <img> and
     * makes the whole grid visibly flash on each cart change. Stock is the only
     * thing that moves when the cart changes, so only that is touched here.
     */
    function refreshStockBadges() {
        const claims = cartClaims();
        el.menuGrid.querySelectorAll('.pos-item-card').forEach((card) => {
            const item = MENU_CATALOG.find((m) => Number(m.item_id) === Number(card.dataset.itemId));
            if (!item) return;
            const badge = badgeStateFor(item, claims);

            const el2 = card.querySelector('.pos-item-status-badge');
            if (el2) {
                if (el2.textContent !== badge.text) el2.textContent = badge.text;
                const cls = 'pos-item-status-badge ' + badge.cls;
                if (el2.className !== cls) el2.className = cls;
            }
            card.classList.toggle('is-sold-out', badge.soldOut);
            card.disabled = badge.soldOut;
        });
    }

    // ---- menu grid ------------------------------------------------------

    function renderMenuGrid() {
        const filtered = MENU_CATALOG.filter((item) => {
            const matchesCategory = state.activeCategory === 'all' || String(item.category_id) === state.activeCategory;
            const matchesSearch = !state.searchQuery || item.item_name.toLowerCase().includes(state.searchQuery);
            return matchesCategory && matchesSearch;
        });

        if (!filtered.length) {
            el.menuGrid.innerHTML = '<div class="pos-cart-empty">No items match.</div>';
            return;
        }

        // Availability badge reflects whichever channel is currently
        // selected -- an item can be fine for dine-in but out of packaging
        // for takeout (business rule: packaging shortage never affects
        // dine-in). Reservation orders are dine-in in this system.
        const channel = state.orderType === 'takeout' ? 'takeout_status' : 'dine_in_status';

        // One claims pass for the whole grid rather than one per card.
        const claims = cartClaims();

        el.menuGrid.innerHTML = filtered.map((item) => {
            const manuallySoldOut = Number(item.is_available) === 0;
            // LIVE count: what is still makeable once the cart's claims are
            // taken out. The page-load figure on the catalog row is only the
            // fallback for items with no recipe at all.
            const badge = badgeStateFor(item, claims);
            const stockUnavailable = badge.status === 'unavailable';
            const lowStock = badge.status === 'low_stock';
            const soldOut = manuallySoldOut || stockUnavailable;

            // Always shown -- the real makeable-servings count when one
            // exists (from computeMenuItemAvailability()'s FIFO-stock-vs-
            // recipe math), not just for the low-stock tier. null only means
            // this item has no recipe at all, so there's genuinely no count.
            const statusBadge = `<span class="pos-item-status-badge ${badge.cls}">${badge.text}</span>`;

            // image_url is stored relative to owner/ (menu items are managed
            // from the owner panel) -- cashier/ is a sibling folder, so it
            // needs the same "../owner/" prefix menu_items.php itself uses
            // (via $ownerBase) to resolve to the real uploaded file.
            const imageHtml = item.image_url
                ? `<img class="pos-item-image" src="../owner/${escapeHtml(item.image_url)}" alt="" loading="lazy">`
                : `<div class="pos-item-image pos-item-image-placeholder">No photo</div>`;

            // The whole card is a real <button> (not a div) so it's a native
            // touch/keyboard tap target -- the "Order" pill inside is now a
            // plain <span>, a visual affordance only, its click just bubbles
            // up to this button like everything else in the card.
            return `
                <button type="button" class="pos-item-card${soldOut ? ' is-sold-out' : ''}" data-item-id="${item.item_id}" ${soldOut ? 'disabled' : ''}>
                    <div class="pos-item-image-wrap">
                        ${imageHtml}
                    </div>
                    <div class="pos-item-body">
                        ${statusBadge}
                        <div class="pos-item-name" title="${escapeHtml(item.item_name)}">${escapeHtml(item.item_name)}</div>
                        <div class="pos-item-bottom">
                            <span class="pos-item-price">${formatCurrency(item.selling_price)}</span>
                            <span class="pos-item-order-btn">Order</span>
                        </div>
                    </div>
                </button>
            `;
        }).join('');
    }

    // Re-pulls the catalog (including dine_in_stock/takeout_stock/status)
    // and redraws the grid -- called right after checkout so the badges
    // reflect what that sale just deducted, instead of staying frozen at
    // whatever they were when the page first loaded. Best-effort: if the
    // fetch fails, the badges just stay as they were rather than blocking
    // anything, since the sale itself already succeeded by the time this runs.
    async function refreshMenuCatalog() {
        try {
            const response = await fetch('api/menu_catalog.php');
            if (!response.ok) return;
            const data = await response.json();
            if (!Array.isArray(data)) return;
            MENU_CATALOG = data;
            renderMenuGrid();
        } catch (err) {
            // Stale badges until the next successful refresh or a manual
            // page reload -- not worth surfacing to the cashier.
        }
    }

    // img "error" events don't bubble, so this needs the capture phase to
    // catch them via delegation -- swaps a broken/missing file for the same
    // placeholder used when an item simply has no image_url set.
    el.menuGrid.addEventListener('error', (e) => {
        const img = e.target;
        if (!img.classList || !img.classList.contains('pos-item-image')) return;
        const placeholder = document.createElement('div');
        placeholder.className = 'pos-item-image pos-item-image-placeholder';
        placeholder.textContent = 'No photo';
        img.replaceWith(placeholder);
    }, true);

    el.menuGrid.addEventListener('click', (e) => {
        const card = e.target.closest('.pos-item-card');
        if (!card || card.disabled) return;

        const itemId = Number(card.dataset.itemId);
        const item = MENU_CATALOG.find((m) => Number(m.item_id) === itemId);
        if (!item) return;

        const existing = state.manualItems.find((m) => m.menu_item_id === itemId);
        if (existing) {
            setLineQuantity(existing, existing.quantity + 1);
        } else {
            const cap = remainingFor(itemId);
            if (cap !== null && cap <= 0) {
                showToast(`${item.item_name} is out of stock.`, 'error');
                return;
            }
            state.manualItems.push({
                menu_item_id: itemId,
                item_name: item.item_name,
                unit_price: parseFloat(item.selling_price),
                is_vat_exempt: Number(item.is_vat_exempt) === 1,
                quantity: 1,
            });
        }
        renderCart();
    });

    el.categoryPills.addEventListener('click', (e) => {
        const pill = e.target.closest('.pos-category-pill');
        if (!pill) return;
        state.activeCategory = pill.dataset.category;
        el.categoryPills.querySelectorAll('.pos-category-pill').forEach((p) => p.classList.toggle('is-active', p === pill));
        renderMenuGrid();
    });

    // Re-clamp every line to the new channel's caps. Without this, a cart built
    // for dine-in could exceed the takeout caps the instant the type is flipped,
    // and the cashier would only find out when the server rejected the sale.
    function reclampCartToOrderType() {
        const channelLabel = state.orderType === 'takeout' ? 'takeout' : 'dine-in';
        let removed = [];
        let trimmed = [];
        state.manualItems.forEach((line) => {
            const item = MENU_CATALOG.find((m) => Number(m.item_id) === line.menu_item_id);
            if (item && !isChannelEligible(item)) {
                removed.push(line.item_name);
                line.quantity = 0;
                return;
            }

            // Measured with this line zeroed out, so it is compared against the
            // stock the REST of the cart leaves rather than against itself.
            const held = line.quantity;
            line.quantity = 0;
            const cap = remainingFor(line.menu_item_id);
            line.quantity = held;
            if (cap !== null && held > cap) {
                line.quantity = Math.max(0, cap);
                trimmed.push(`${line.item_name} → ${line.quantity}`);
            }
        });
        state.manualItems = state.manualItems.filter((l) => l.quantity > 0);

        const messages = [];
        if (removed.length) messages.push(`Not available for ${channelLabel}, removed: ` + removed.join(', '));
        if (trimmed.length) messages.push('Reduced to available stock: ' + trimmed.join(', '));
        if (messages.length) showToast(messages.join(' '), 'error');
    }

    el.orderTypeRow.addEventListener('click', (e) => {
        const pill = e.target.closest('.pos-category-pill');
        if (!pill || state.reservation) return;
        state.orderType = pill.dataset.orderType;
        el.orderTypeRow.querySelectorAll('.pos-category-pill').forEach((p) => p.classList.toggle('is-active', p === pill));
        reclampCartToOrderType();
        renderMenuGrid();
        renderCart();
    });

    if (el.menuSearchInput) {
        el.menuSearchInput.addEventListener('input', () => {
            state.searchQuery = el.menuSearchInput.value.trim().toLowerCase();
            renderMenuGrid();
        });
    }

    // ---- reservation lookup ----------------------------------------------

    // Collapsed by default (walk-ins are the common case) -- clicking just
    // expands/collapses the inline lookup field; state.reservation itself
    // is untouched either way, so collapsing after a successful lookup
    // doesn't drop the linked reservation, only hides the detail panel.
    el.linkReservationToggle.addEventListener('click', () => {
        const isOpen = el.reservationPanel.classList.toggle('is-open');
        el.linkReservationToggle.setAttribute('aria-expanded', String(isOpen));
        if (isOpen) el.reservationInput.focus();
    });

    // Reflects whether a reservation is currently linked on the toggle
    // button itself, so that's still visible even when the panel is
    // collapsed back down after a successful lookup.
    function renderLinkReservationButton() {
        if (state.reservation) {
            el.linkReservationBtnLabel.textContent = state.reservation.reservation_number;
            el.linkReservationToggle.classList.add('is-linked');
        } else {
            el.linkReservationBtnLabel.textContent = 'Link reservation';
            el.linkReservationToggle.classList.remove('is-linked');
        }
    }

    async function lookupReservation() {
        const number = el.reservationInput.value.trim();
        if (!number) return;

        el.reservationLookupBtn.disabled = true;
        try {
            const data = await postForm('api/lookup_reservation.php', { reservation_number: number });

            if (!data.found) {
                state.reservation = null;
                state.advanceOrders = [];
                state.alreadyCheckedOut = false;
                state.creditRemaining = 0;
                el.reservationResult.innerHTML = '<div class="pos-reservation-result"><p style="color:var(--op-danger);font-size:0.85rem;margin:0;">No reservation found with that number.</p></div>';
                renderLinkReservationButton();
                renderCart();
                return;
            }

            // Found, but its table/credit was already released (no_show or
            // staff-cancelled) -- server already refuses this at checkout
            // (create_order.php), so don't let it be linked here either.
            if (data.usable === false) {
                state.reservation = null;
                state.advanceOrders = [];
                state.alreadyCheckedOut = false;
                state.creditRemaining = 0;
                const label = data.status === 'no_show' ? 'marked as a no-show' : 'cancelled';
                el.reservationResult.innerHTML = `<div class="pos-reservation-result"><p style="color:var(--op-danger);font-size:0.85rem;margin:0;"><i class="ph ph-warning-circle" aria-hidden="true"></i> ${escapeHtml(data.reservation_number)} was ${label} and can no longer be used to check in.</p></div>`;
                renderLinkReservationButton();
                renderCart();
                return;
            }

            state.reservation = data.reservation;
            state.creditRemaining = data.credit.remaining;
            state.alreadyCheckedOut = data.credit.already_checked_out;
            state.orderType = 'dine_in'; // reservations always seat in-house; reservation_id (not order_type) marks it as reservation-sourced

            state.advanceOrders = data.advance_orders.map((a) => ({
                advance_order_id: a.advance_order_id,
                menu_item_id: a.menu_item_id,
                item_name: a.item_name,
                unit_price: parseFloat(a.unit_price),
                subtotal: parseFloat(a.subtotal),
                quantity: a.quantity,
                is_vat_exempt: Number(a.is_vat_exempt) === 1,
                included: !state.alreadyCheckedOut,
            }));

            renderReservationResult();
            renderLinkReservationButton();
            renderCart();
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            el.reservationLookupBtn.disabled = false;
        }
    }

    function renderReservationResult() {
        const r = state.reservation;
        if (!r) {
            el.reservationResult.innerHTML = '';
            return;
        }

        // The reservation number was missing here entirely: a cashier who
        // looked one up had no way to confirm on screen that the number they
        // typed is the booking they're now charging against.
        const resNo = escapeHtml(r.reservation_number || '');
        const name = escapeHtml(`${r.first_name} ${r.last_name}`);
        const guests = escapeHtml(String(r.number_of_guests));
        const dateStr = escapeHtml(r.reservation_date);
        const slot = escapeHtml(r.slot_label || '');

        let checkedOutNote = '';
        if (state.alreadyCheckedOut) {
            checkedOutNote = `<p style="color:var(--op-danger);font-size:0.78rem;margin:8px 0 0;">
                <i class="ph ph-warning-circle" aria-hidden="true"></i> Already checked out before. Pre-ordered items were not re-added — remaining credit still applies.
            </p>`;
        }

        const advanceListHtml = state.advanceOrders.length
            ? `<div class="pos-advance-list">${state.advanceOrders.map((a) => `
                <label class="pos-advance-item">
                    <input type="checkbox" data-advance-order-id="${a.advance_order_id}" ${a.included ? 'checked' : ''}>
                    <span class="pos-advance-item-name">${escapeHtml(a.item_name)} × ${a.quantity}</span>
                    <span class="pos-advance-item-price">${formatCurrency(a.subtotal)}</span>
                </label>
            `).join('')}</div>`
            : '<p style="font-size:0.82rem;color:var(--op-ink-faint);margin:0;">No pre-ordered items.</p>';

        el.reservationResult.innerHTML = `
            <div class="pos-reservation-result">
                <div class="pos-reservation-meta">
                    <span class="pos-reservation-number">${resNo}</span>
                    <span><strong>${name}</strong></span>
                    <span>${guests} guests</span>
                    <span>${dateStr}</span>
                    <span>${slot}</span>
                </div>
                <div class="pos-cart-section-label" style="margin-top:0;">Pre-ordered items</div>
                ${advanceListHtml}
                ${checkedOutNote}
            </div>
        `;

        el.reservationResult.querySelectorAll('[data-advance-order-id]').forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                const id = Number(checkbox.dataset.advanceOrderId);
                const line = state.advanceOrders.find((a) => a.advance_order_id === id);
                if (line) line.included = checkbox.checked;
                renderCart();
            });
        });
    }

    el.reservationLookupBtn.addEventListener('click', lookupReservation);
    el.reservationInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            lookupReservation();
        }
    });

    // ---- cart + totals ----------------------------------------------------

    function renderCart() {
        const includedAdvance = state.advanceOrders.filter((a) => a.included);
        const hasAnyLines = includedAdvance.length > 0 || state.manualItems.length > 0;

        el.orderTypeRow.style.display = state.reservation ? 'none' : 'flex';

        if (!hasAnyLines) {
            el.cartEmptyState.style.display = 'block';
        } else {
            el.cartEmptyState.style.display = 'none';
        }

        const sections = [];

        if (includedAdvance.length) {
            sections.push('<div class="pos-cart-section-label">From reservation</div>');
            includedAdvance.forEach((a) => {
                sections.push(`
                    <div class="pos-cart-line">
                        <div class="pos-cart-line-name">${escapeHtml(a.item_name)}<small>Qty ${a.quantity} · locked</small></div>
                        <div class="pos-cart-line-price">${formatCurrency(a.subtotal)}</div>
                    </div>
                `);
            });
        }

        if (state.manualItems.length) {
            sections.push('<div class="pos-cart-section-label">Added items</div>');
            state.manualItems.forEach((m, index) => {
                sections.push(`
                    <div class="pos-cart-line">
                        <div class="pos-cart-line-name">${escapeHtml(m.item_name)}</div>
                        <div class="pos-qty-stepper">
                            <button type="button" data-action="dec" data-index="${index}">−</button>
                            <span>${m.quantity}</span>
                            <button type="button" data-action="inc" data-index="${index}">+</button>
                        </div>
                        <div class="pos-cart-line-price">${formatCurrency(m.unit_price * m.quantity)}</div>
                        <button type="button" class="pos-cart-line-remove" data-action="remove" data-index="${index}" aria-label="Remove item" title="Remove"><i class="ph ph-trash" aria-hidden="true"></i></button>
                    </div>
                `);
            });
        }

        // Rebuild scroll contents, keeping the order-type row + empty state elements in place.
        el.cartScroll.querySelectorAll('.pos-cart-section-label, .pos-cart-line').forEach((n) => n.remove());
        el.cartScroll.insertAdjacentHTML('beforeend', sections.join(''));

        el.cartScroll.querySelectorAll('[data-action="inc"]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const line = state.manualItems[Number(btn.dataset.index)];
                setLineQuantity(line, line.quantity + 1);
                renderCart();
            });
        });
        el.cartScroll.querySelectorAll('[data-action="dec"]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const idx = Number(btn.dataset.index);
                state.manualItems[idx].quantity -= 1;
                if (state.manualItems[idx].quantity <= 0) state.manualItems.splice(idx, 1);
                renderCart();
            });
        });
        el.cartScroll.querySelectorAll('[data-action="remove"]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.manualItems.splice(Number(btn.dataset.index), 1);
                renderCart();
            });
        });
        renderTotals();
        // Badges only -- a full renderMenuGrid() here re-created every <img>
        // and made the grid flicker on every tap.
        refreshStockBadges();
    }

    // Packaging Fee: pass-through packaging cost, only when the policy is
    // 'separate' and only for takeout -- mirrors create_order.php exactly
    // so this preview matches what the server will actually charge.
    function computePackagingFee() {
        let packagingFee = 0;
        if (state.orderType === 'takeout' && PRICING_SETTINGS.packaging_fee_policy === 'separate') {
            state.manualItems.forEach((m) => {
                const catalogItem = MENU_CATALOG.find((c) => Number(c.item_id) === m.menu_item_id);
                const packagingCost = catalogItem ? parseFloat(catalogItem.packaging_cost) || 0 : 0;
                packagingFee += packagingCost * m.quantity;
            });
            packagingFee = round2(packagingFee);
        }

        return { packagingFee };
    }

    function renderTotals() {
        const lines = getBillableLines();
        const totals = computeTotals(lines);
        const discountType = getSelectedDiscountType();
        const discountAmount = computeDiscount(totals.subtotal, discountType);
        const netSubtotal = round2(totals.subtotal - discountAmount);
        const { packagingFee } = computePackagingFee();
        const grandTotal = round2(netSubtotal + totals.vat_amount + packagingFee);

        const credit = state.reservation ? Math.min(state.creditRemaining, grandTotal) : 0;
        const balanceDue = round2(Math.max(0, grandTotal - credit));
        state.balanceDue = balanceDue; // read by the cash-tendered change preview + checkout validation below

        el.totalSubtotal.textContent = formatCurrency(totals.subtotal);

        if (discountAmount > 0) {
            el.discountRow.style.display = 'flex';
            el.discountLabel.textContent = discountType.discount_name;
            el.totalDiscount.textContent = '-' + formatCurrency(discountAmount);
        } else {
            el.discountRow.style.display = 'none';
        }

        el.totalVat.textContent = formatCurrency(totals.vat_amount);

        if (packagingFee > 0) {
            el.packagingFeeRow.style.display = 'flex';
            el.totalPackagingFee.textContent = formatCurrency(packagingFee);
        } else {
            el.packagingFeeRow.style.display = 'none';
        }

        el.totalBalance.textContent = formatCurrency(balanceDue);

        if (credit > 0) {
            el.creditRow.style.display = 'flex';
            el.totalCredit.textContent = '-' + formatCurrency(credit);
        } else {
            el.creditRow.style.display = 'none';
        }

        const forfeited = state.reservation ? round2(Math.max(0, state.creditRemaining - credit)) : 0;
        if (forfeited > 0) {
            el.forfeitedCreditNote.style.display = 'block';
            el.forfeitedCreditNote.textContent = `${formatCurrency(forfeited)} of this reservation's credit exceeds the order total and will not be carried forward.`;
        } else {
            el.forfeitedCreditNote.style.display = 'none';
        }

        const hasLines = lines.length > 0;
        const fullyCovered = hasLines && balanceDue === 0 && grandTotal > 0;
        el.fullyCoveredNote.style.display = fullyCovered ? 'block' : 'none';

        if (fullyCovered) {
            state.paymentMethod = null;
            el.payMethods.querySelectorAll('.pos-pay-btn').forEach((b) => b.classList.remove('is-selected'));
            if (el.gcashQrPanel) el.gcashQrPanel.style.display = 'none';
            hideCashTendered();
        }

        // "Place order" only needs a non-empty cart -- payment method is
        // picked afterward, in the payment modal (skipped entirely when
        // fully covered by credit; see handlePlaceOrderClick()).
        el.checkoutBtn.disabled = state.submitting || !hasLines;

        const cashTenderedShort = state.paymentMethod === 'cash' && balanceDue > 0
            && !(parseFloat(el.cashTenderedInput.value) >= balanceDue - 0.001);

        el.paymentModalBalance.textContent = formatCurrency(balanceDue);
        el.paymentModalCompleteBtn.disabled = state.submitting || (balanceDue > 0 && !state.paymentMethod) || cashTenderedShort;
    }

    el.discountSelect.addEventListener('change', () => {
        state.discountTypeId = el.discountSelect.value ? Number(el.discountSelect.value) : null;
        renderTotals();
    });

    // ---- cash tendered / change ------------------------------------------

    function hideCashTendered() {
        el.cashTenderedRow.style.display = 'none';
        el.cashTenderedInput.value = '';
        el.cashChangePreview.textContent = '';
    }

    function updateChangePreview() {
        const tendered = parseFloat(el.cashTenderedInput.value);
        if (!tendered || tendered <= 0) {
            el.cashChangePreview.textContent = '';
            el.cashChangePreview.classList.remove('is-short');
            return;
        }
        const due = state.balanceDue || 0;
        const change = round2(tendered - due);
        if (change < 0) {
            el.cashChangePreview.textContent = `${formatCurrency(Math.abs(change))} short`;
            el.cashChangePreview.classList.add('is-short');
        } else {
            el.cashChangePreview.textContent = `Change: ${formatCurrency(change)}`;
            el.cashChangePreview.classList.remove('is-short');
        }
    }

    el.cashTenderedInput.addEventListener('input', () => {
        updateChangePreview();
        renderTotals();
    });

    el.payMethods.addEventListener('click', (e) => {
        const btn = e.target.closest('.pos-pay-btn');
        if (!btn) return;
        state.paymentMethod = btn.dataset.method;
        el.payMethods.querySelectorAll('.pos-pay-btn').forEach((b) => b.classList.toggle('is-selected', b === btn));

        if (state.paymentMethod === 'cash') {
            el.cashTenderedRow.style.display = 'flex';
            el.cashTenderedInput.focus();
        } else {
            hideCashTendered();
        }
        // style.display, not the hidden attribute: .pos-gcash-qr sets its own
        // display, which would silently defeat [hidden] -- and it matches how
        // cashTenderedRow above is already toggled.
        if (el.gcashQrPanel) {
            el.gcashQrPanel.style.display = state.paymentMethod === 'gcash' ? 'block' : 'none';
        }

        renderTotals();
    });

    // ---- checkout -----------------------------------------------------

    // "Place order" -- if there's nothing left to pay (fully covered by
    // reservation credit) there's no payment method to pick, so it skips
    // straight to submitting; otherwise it opens the payment modal, and
    // that modal's own "Complete order" button is what actually submits.
    function handlePlaceOrderClick() {
        if (el.checkoutBtn.disabled) return;

        if ((state.balanceDue || 0) <= 0) {
            completeOrder();
            return;
        }

        openModal(el.paymentModalBackdrop);
    }

    async function completeOrder() {
        if (state.submitting) return;

        state.submitting = true;
        el.checkoutBtn.disabled = true;
        el.paymentModalCompleteBtn.disabled = true;
        el.paymentModalCompleteBtn.textContent = 'Processing…';

        try {
            const includedIds = state.advanceOrders.filter((a) => a.included).map((a) => a.advance_order_id);
            const manualPayload = state.manualItems.map((m) => ({ menu_item_id: m.menu_item_id, quantity: m.quantity }));

            const data = await postForm('api/create_order.php', {
                reservation_id: state.reservation ? state.reservation.reservation_id : '',
                included_advance_order_ids: JSON.stringify(includedIds),
                manual_items: JSON.stringify(manualPayload),
                order_type: state.orderType,
                payment_method: state.paymentMethod || '',
                discount_type_id: state.discountTypeId || '',
                cash_tendered: state.paymentMethod === 'cash' ? (el.cashTenderedInput.value || '') : '',
            });

            // The receipt modal is the only "order complete" UI now -- reset
            // the cart pane straight back to empty (no separate inline
            // confirmation card sitting behind the modal), close the payment
            // modal if it was open, and let the receipt modal's own
            // Print/Start new order actions be the one place to act next.
            resetCartState();
            closeModal(el.paymentModalBackdrop);
            showReceiptModal(data.order_id);
            refreshMenuCatalog(); // not awaited -- best-effort, shouldn't delay the receipt
        } catch (err) {
            showToast(err.message, 'error');
            state.submitting = false;
            el.paymentModalCompleteBtn.textContent = 'Complete order';
            renderTotals();
        }
    }

    // Shows the itemized invoice (discount line and reservation number
    // included automatically -- same conditional markup the old standalone
    // receipt.php page used to render) right after checkout, so the cashier
    // doesn't have to click anything to see it. This modal is now the only
    // place the receipt is shown -- there's no separate page anymore, so
    // printing (see receiptModalPrintBtn below) prints the modal itself via
    // the @media print rules in pos.css rather than opening another tab.
    async function showReceiptModal(orderId) {
        el.receiptModalBody.innerHTML = '<p style="text-align:center;color:var(--op-ink-faint);padding:30px 0;">Loading receipt…</p>';
        openModal(el.receiptModalBackdrop);

        try {
            const response = await fetch(`api/receipt_fragment.php?order_id=${orderId}`);
            const html = await response.text();
            if (!response.ok) throw new Error('Could not load the receipt.');
            el.receiptModalBody.innerHTML = html;
        } catch (err) {
            el.receiptModalBody.innerHTML = `<p style="text-align:center;color:var(--op-danger);padding:30px 0;">${escapeHtml(err.message)}</p>`;
        }
    }

    if (el.receiptModalNewOrderBtn) {
        el.receiptModalNewOrderBtn.addEventListener('click', resetOrder);
    }

    if (el.receiptModalPrintBtn) {
        el.receiptModalPrintBtn.addEventListener('click', () => window.print());
    }

    // Just the cart/form state -- no modal interaction. Called right after a
    // successful checkout (so the cart pane is instantly ready for the next
    // order while the receipt modal shows on top) AND from resetOrder()
    // below (so "Start new order" inside the modal gets the same reset).
    function resetCartState() {
        state.reservation = null;
        state.advanceOrders = [];
        state.alreadyCheckedOut = false;
        state.creditRemaining = 0;
        state.manualItems = [];
        state.orderType = 'dine_in';
        state.paymentMethod = null;
        state.submitting = false;
        state.discountTypeId = null;

        el.discountSelect.value = '';
        el.reservationInput.value = '';
        el.reservationResult.innerHTML = '';
        el.reservationPanel.classList.remove('is-open');
        el.linkReservationToggle.setAttribute('aria-expanded', 'false');
        renderLinkReservationButton();
        el.payMethods.querySelectorAll('.pos-pay-btn').forEach((b) => b.classList.remove('is-selected'));
        el.orderTypeRow.querySelectorAll('.pos-category-pill').forEach((p) => p.classList.toggle('is-active', p.dataset.orderType === 'dine_in'));
        hideCashTendered();
        if (el.gcashQrPanel) el.gcashQrPanel.style.display = 'none';

        el.checkoutBtn.style.display = 'block';
        el.checkoutBtn.textContent = 'Place order';
        el.paymentModalCompleteBtn.textContent = 'Complete order';

        // orderTypeRow now lives in the checkout panel, not cartScroll --
        // only cartEmptyState needs re-inserting after the wipe below.
        el.cartScroll.innerHTML = '';
        el.cartScroll.appendChild(el.cartEmptyState);

        renderCart();
    }

    function resetOrder() {
        closeModal(el.receiptModalBackdrop);
        resetCartState();
    }

    el.checkoutBtn.addEventListener('click', handlePlaceOrderClick);
    el.paymentModalCompleteBtn.addEventListener('click', completeOrder);

    // ---- init -----------------------------------------------------------

    renderMenuGrid();
    renderCart();
})();
