/**
 * customer/assets/js/make-reservation.js
 *
 * Reservation wizard client logic. Every business rule (fee, deposit %,
 * guest min/max, lead time, capacity) comes from window.RESERVATION_SETTINGS
 * (server-rendered from system_settings) and the availability AJAX endpoint
 * — nothing here is a hardcoded number. This is this app's second
 * deliberate exception to its otherwise-universal POST-redirect-GET
 * convention (the first being cashier/pos.js) — only availability and the
 * final checkout call genuinely need a live round-trip.
 */

(function () {
    'use strict';

    const SETTINGS = window.RESERVATION_SETTINGS;
    const TIME_SLOTS = window.TIME_SLOTS || [];
    const MENU_CATALOG = window.MENU_CATALOG || [];
    const CSRF_TOKEN = window.CSRF_TOKEN;

    const TAX_SETTINGS = window.TAX_SETTINGS || { tax_name: 'VAT', tax_rate: 0, tax_type: 'exclusive' };

    const state = {
        stepIndex: 0,
        selectedDate: null,
        selectedSlot: null,
        // Starts at 0, not at the minimum: the count is the customer's to
        // state, and opening on a number nobody chose invites them to skip
        // straight past it and book for a party size they never set.
        guests: 0,
        guestTouched: false,
        wantsAdvanceOrder: null,
        cart: [],
        menuSearchQuery: '',
        menuActiveCategory: 'all',
        menuAiAllowedIds: null,  // Set of item_ids from the AI filter; null = filter inactive
        menuAiOrder: null,       // server-supplied ordering for those ids (popularity/relevance)
        calendarMonth: localMonthKey(new Date()),
        monthCache: {},
        dateAvailability: null,
        pollTimer: null,
        submitting: false,
        cartBarExpanded: false,
        sheetItemId: null,   // menu item currently open in the item detail sheet
        sheetQty: 1,
        agreedToTerms: false,
    };

    // The shared "Describe your preference" control, created once the menu
    // step renders its toolbar (see mountMenuAiFilter()). Stays null when
    // advance ordering is off or the module didn't load.
    let menuAiFilter = null;

    const el = {
        topbarTitle: document.querySelector('.ca-wizard-topbar-title'),
        topbarSub: document.querySelector('.ca-wizard-topbar-sub'),
        progress: document.getElementById('wizProgress'),
        calendar: document.getElementById('resCalendar'),
        slotSection: document.getElementById('resSlotSection'),
        slotPlaceholder: document.getElementById('resSlotPlaceholder'),
        slotDate: document.getElementById('resSlotDate'),
        slots: document.getElementById('resSlots'),
        guestValue: document.getElementById('guestValue'),
        guestUnit: document.getElementById('guestUnit'),
        guestMinus: document.getElementById('guestMinus'),
        guestPlus: document.getElementById('guestPlus'),
        guestError: document.getElementById('guestError'),
        wizardAlert: document.getElementById('wizardAlert'),
        wizardAlertText: document.getElementById('wizardAlertText'),
        wizardAlertClose: document.getElementById('wizardAlertClose'),
        advOrderYes: document.getElementById('advOrderYes'),
        advOrderNo: document.getElementById('advOrderNo'),
        advanceOrderMenu: document.getElementById('advanceOrderMenu'),
        orderPanel: document.getElementById('orderPanel'),
        orderPanelLines: document.getElementById('orderPanelLines'),
        orderPanelTotals: document.getElementById('orderPanelTotals'),
        orderPanelContinue: document.getElementById('orderPanelContinue'),
        menuGrid: document.getElementById('menuGrid'),
        summaryReceipt: document.getElementById('summaryReceipt'),
        agreeTerms: document.getElementById('agreeTerms'),
        termsError: document.getElementById('termsError'),
        checkoutError: document.getElementById('checkoutError'),
        wizNav: document.querySelector('.ca-wizard-nav'),
        wizard: document.getElementById('reservationWizard'),
        wizBack: document.getElementById('wizBack'),
        wizNext: document.getElementById('wizNext'),
        loadingOverlay: document.getElementById('loadingOverlay'),
        loadingText: document.getElementById('loadingText'),

        cartBar: document.getElementById('cartBar'),
        cartBarToggle: document.getElementById('cartBarToggle'),
        cartBarCount: document.getElementById('cartBarCount'),
        cartBarItemsLabel: document.getElementById('cartBarItemsLabel'),
        cartBarTotal: document.getElementById('cartBarTotal'),
        cartSheetLines: document.getElementById('cartSheetLines'),
        cartSheetTotals: document.getElementById('cartSheetTotals'),
        cartSheetContinue: document.getElementById('cartSheetContinue'),

        itemSheetBackdrop: document.getElementById('itemSheetBackdrop'),
        itemSheetClose: document.getElementById('itemSheetClose'),
        itemSheetImageWrap: document.getElementById('itemSheetImageWrap'),
        itemSheetBadges: document.getElementById('itemSheetBadges'),
        itemSheetName: document.getElementById('itemSheetName'),
        itemSheetDesc: document.getElementById('itemSheetDesc'),
        itemSheetPrice: document.getElementById('itemSheetPrice'),
        itemSheetQty: document.getElementById('itemSheetQty'),
        itemSheetMinus: document.getElementById('itemSheetMinus'),
        itemSheetPlus: document.getElementById('itemSheetPlus'),
        itemSheetAddBtn: document.getElementById('itemSheetAddBtn'),
    };

    // ---- helpers ------------------------------------------------------------

    function formatCurrency(n) {
        return '₱' + (Math.round((n + Number.EPSILON) * 100) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    // Local-calendar date/month keys — deliberately NOT toISOString(), which
    // converts to UTC and rolls the date back a day in any positive-UTC-offset
    // timezone (e.g. Asia/Manila), silently breaking month navigation.
    function localDateKey(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function localMonthKey(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    }

    function formatDateLong(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }

    // Compact form for the slot-panel heading, where the full weekday-and-month
    // version would wrap on a narrow panel.
    function formatDateShort(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    async function fetchJSON(url, options) {
        const res = await fetch(url, options || {});
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(data.error || 'Something went wrong. Please try again.');
        }
        return data;
    }

    function showLoading(text) {
        el.loadingText.textContent = text || 'Loading…';
        el.loadingOverlay.classList.add('is-open');
    }
    function hideLoading() {
        el.loadingOverlay.classList.remove('is-open');
    }

    // ---- step definitions ------------------------------------------------------

    // Three fixed steps. The Advance Order step used to be conditional on
    // SETTINGS.advance_order_enabled, a Reservation Settings checkbox that has
    // been removed -- advance food ordering is a core feature of this system,
    // not something the wizard can be configured to skip.
    function getStepDefs() {
        return [
            { key: 'details', panel: '1', label: 'Reservation Details' },
            { key: 'advance', panel: '2', label: 'Advance Order' },
            { key: 'summary', panel: '3', label: 'Summary & Payment' }
        ];
    }

    function renderProgress() {
        const steps = getStepDefs();
        el.progress.innerHTML = steps.map((s, i) => {
            const cls = i === state.stepIndex ? 'is-active' : (i < state.stepIndex ? 'is-done' : '');
            const connector = i < steps.length - 1 ? '<div class="ca-wizard-connector"></div>' : '';
            // The digit sits in its own <span> so the is-done rule in
            // make-reservation.css can hide it and leave only the ::before
            // check -- a bare text node here can't be targeted, which is why
            // completed steps used to read ".1" instead of just the check.
            return `<div class="ca-wizard-step ${cls}"><span class="ca-wizard-step-num"><span>${i + 1}</span></span><span class="ca-wizard-step-label">${escapeHtml(s.label)}</span></div>${connector}`;
        }).join('');
    }

    // Header title/subtitle track the active step -- "Book a Reservation" is
    // the right framing on step 1 (the wizard's entry point), but a
    // persistent generic title stops being useful once you're deep in a
    // specific step further along.
    const STEP_TOPBAR_TEXT = {
        details:  { title: 'Book a Reservation', sub: 'Reserve your table at OPO! Our Pinoy Original' },
        advance:  { title: 'Advance Order', sub: 'Pre-order so it\'s ready when you arrive' },
        summary:  { title: 'Review & Pay', sub: 'Confirm your reservation details' },
    };

    function showStep(index) {
        state.stepIndex = index;
        hideWizardAlert();   // hoisted; never carry a stale warning across steps
        const steps = getStepDefs();
        const activeStep = steps[index];
        const activePanel = activeStep.panel;

        document.querySelectorAll('.ca-wizard-panel').forEach((panel) => {
            panel.classList.toggle('is-active', panel.getAttribute('data-panel') === activePanel);
        });
        renderProgress();

        const topbarText = STEP_TOPBAR_TEXT[activeStep.key];
        if (topbarText && el.topbarTitle) el.topbarTitle.textContent = topbarText.title;
        if (topbarText && el.topbarSub) el.topbarSub.textContent = topbarText.sub;

        el.wizBack.style.visibility = index === 0 ? 'hidden' : 'visible';
        el.wizNext.innerHTML = (index === steps.length - 1)
            ? '<img src="assets/images/gcash-logo.png" alt="" class="ca-gcash-badge"> Pay in GCash'
            : 'Continue <i class="ph ph-arrow-right" aria-hidden="true"></i>';

        if (steps[index].key === 'advance') stopPolling();
        if (steps[index].key === 'details' && state.selectedDate) startPolling();
        if (steps[index].key === 'summary') { stopPolling(); renderSummary(); }

        updateBottomBarMode();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // The fixed cart bar takes over from the normal Back/Continue bar only
    // once there's actually an order to show for it -- otherwise (not on
    // the advance-order step, "No" chosen, or "Yes" but nothing added yet)
    // the plain wizard nav stays in charge.
    function updateBottomBarMode() {
        const steps = getStepDefs();
        const onAdvanceStep = steps[state.stepIndex] && steps[state.stepIndex].key === 'advance';
        const showCartBar = onAdvanceStep && state.wantsAdvanceOrder === true && state.cart.length > 0;

        el.cartBar.hidden = !showCartBar;
        // A class, not the hidden attribute: whether the nav should actually
        // disappear depends on the viewport (the cart bar only replaces it on
        // phones), and only CSS knows that. Using [hidden] here would force the
        // desktop rule to fight it with !important.
        el.wizNav.classList.toggle('is-replaced-by-cartbar', showCartBar);
        document.body.classList.toggle('ca-has-cartbar', showCartBar);

        if (!showCartBar) {
            state.cartBarExpanded = false;
            el.cartBar.classList.remove('is-expanded');
        }

        // The desktop "My Order" panel appears as soon as pre-ordering is
        // chosen -- unlike the bottom bar it doesn't wait for a first item,
        // because an empty panel with its own empty state is what gives the
        // two-column layout its shape. CSS decides whether it's on screen at
        // all (>=1100px only), so no width is measured here.
        const showPanel = onAdvanceStep && state.wantsAdvanceOrder === true;
        if (el.orderPanel) {
            el.orderPanel.hidden = !showPanel;
        }
        // Lets the desktop rule drop the wizard's own Continue while the panel
        // is showing one, without hiding Back.
        el.wizard.classList.toggle('is-order-panel-open', showPanel);
    }

    // ---- Step 1: Calendar ------------------------------------------------------

    function monthKey(d) { return d.slice(0, 7); }

    async function loadMonth(month) {
        if (state.monthCache[month]) return state.monthCache[month];
        const data = await fetchJSON('api/reservation_availability.php?month=' + encodeURIComponent(month));
        state.monthCache[month] = data.days;
        return data.days;
    }

    async function renderCalendar() {
        el.calendar.innerHTML = '<div class="ca-skeleton" style="height:280px;"></div>';

        let days;
        try {
            days = await loadMonth(state.calendarMonth);
        } catch (e) {
            el.calendar.innerHTML = `<div class="ca-empty-state"><i class="ph ph-warning-circle"></i>${escapeHtml(e.message)}</div>`;
            return;
        }

        const [year, month] = state.calendarMonth.split('-').map(Number);
        const firstOfMonth = new Date(year, month - 1, 1);
        const startWeekday = (firstOfMonth.getDay() + 6) % 7; // Mon=0..Sun=6
        const daysInMonth = new Date(year, month, 0).getDate();
        const monthLabel = firstOfMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        const currentMonthKey = localMonthKey(new Date());
        const isCurrentOrPastMonth = state.calendarMonth <= currentMonthKey;

        let cells = '';
        for (let i = 0; i < startWeekday; i++) cells += '<div class="ca-calendar-day is-empty"></div>';
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const info = days[dateStr] || { closed: true, has_availability: false };
            const disabled = info.closed || !info.has_availability;
            const isToday = dateStr === localDateKey(new Date());
            const isSelected = dateStr === state.selectedDate;
            const cls = ['ca-calendar-day', disabled ? 'is-disabled' : '', isToday ? 'is-today' : '', isSelected ? 'is-selected' : ''].filter(Boolean).join(' ');
            let title = '';
            if (info.is_blackout) title = 'Closed';
            else if (info.is_too_far_ahead) title = 'Too far ahead to book yet';
            else if (disabled) title = 'Not available';
            cells += `<button type="button" class="${cls}" data-date="${dateStr}" ${disabled ? 'disabled' : ''} title="${escapeHtml(title)}">${d}</button>`;
        }

        el.calendar.innerHTML = `
            <div class="ca-calendar-head">
                <div class="ca-calendar-title">${escapeHtml(monthLabel)}</div>
                <div class="ca-calendar-nav">
                    <button type="button" class="ca-calendar-nav-btn" id="calPrev" ${isCurrentOrPastMonth ? 'disabled' : ''} aria-label="Previous month"><i class="ph ph-caret-left"></i></button>
                    <button type="button" class="ca-calendar-nav-btn" id="calNext" aria-label="Next month"><i class="ph ph-caret-right"></i></button>
                </div>
            </div>
            <div class="ca-calendar-weekdays"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
            <div class="ca-calendar-grid">${cells}</div>
            <div class="ca-calendar-legend">
                <span><i style="background:var(--ca-gold);"></i> Selected</span>
                <span><i style="background:var(--ca-canvas);border:1px solid var(--ca-border);"></i> Unavailable</span>
            </div>
        `;

        document.getElementById('calPrev').addEventListener('click', () => shiftMonth(-1));
        document.getElementById('calNext').addEventListener('click', () => shiftMonth(1));
        el.calendar.querySelectorAll('.ca-calendar-day[data-date]:not(.is-disabled)').forEach((btn) => {
            btn.addEventListener('click', () => selectDate(btn.getAttribute('data-date')));
        });
    }

    function shiftMonth(delta) {
        const [y, m] = state.calendarMonth.split('-').map(Number);
        const d = new Date(y, m - 1 + delta, 1);
        state.calendarMonth = localMonthKey(d);
        renderCalendar();
    }

    async function selectDate(dateStr) {
        state.selectedDate = dateStr;
        state.selectedSlot = null;
        hideWizardAlert();
        renderCalendar();
        if (el.slotPlaceholder) el.slotPlaceholder.hidden = true;
        if (el.slotDate) el.slotDate.textContent = ' for ' + formatDateShort(dateStr);
        el.slotSection.hidden = false;
        el.slots.innerHTML = '<div class="ca-skeleton" style="height:60px;"></div>';
        await refreshDateAvailability();
        startPolling();
    }

    async function refreshDateAvailability(silent) {
        if (!state.selectedDate) return;
        try {
            const data = await fetchJSON('api/reservation_availability.php?date=' + encodeURIComponent(state.selectedDate));
            const previouslySelected = state.selectedSlot ? state.selectedSlot.slot_id : null;
            state.dateAvailability = data;
            renderSlots();

            if (previouslySelected !== null) {
                const stillGood = data.slots.find(s => s.slot_id === previouslySelected && !s.is_full && !s.lead_time_blocked && s.remaining >= state.guests);
                if (!stillGood) {
                    state.selectedSlot = null;
                    if (silent) {
                        showAvailabilityChangedWarning();
                    }
                }
            }
        } catch (e) {
            if (!silent) el.slots.innerHTML = `<div class="ca-empty-state"><i class="ph ph-warning-circle"></i>${escapeHtml(e.message)}</div>`;
        }
    }

    function showAvailabilityChangedWarning() {
        let banner = document.getElementById('availabilityChangedBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'availabilityChangedBanner';
            banner.className = 'ca-alert ca-alert-error';
            banner.innerHTML = '<i class="ph ph-warning-circle"></i><span>Availability changed &mdash; please choose another time slot.</span>';
            el.slotSection.insertBefore(banner, el.slots);
        }
        banner.hidden = false;
        setTimeout(() => { if (banner) banner.hidden = true; }, 6000);
    }

    function renderSlots() {
        if (!state.dateAvailability) return;
        const slots = state.dateAvailability.slots;

        if (slots.length === 0) {
            el.slots.innerHTML = '<div class="ca-empty-state"><i class="ph ph-calendar-x"></i>No time slots configured.</div>';
            return;
        }

        el.slots.innerHTML = slots.map((s) => {
            const disabled = s.is_full || s.lead_time_blocked;
            const isSelected = state.selectedSlot && state.selectedSlot.slot_id === s.slot_id;
            const cls = ['ca-slot-chip', isSelected ? 'is-selected' : '', (!disabled && s.remaining <= 5) ? 'is-limited' : ''].filter(Boolean).join(' ');
            let meta;
            // Name the actual requirement. "Minimum lead time requirement" tells
            // a customer a rule exists but not what it is, so they cannot work
            // out which slot to pick instead -- the number is the whole point.
            // Falls back to the generic wording only if the API didn't send it.
            if (s.lead_time_blocked) {
                const lead = state.dateAvailability.min_lead_hours;
                meta = lead
                    ? `Needs ${lead} hour${lead === 1 ? '' : 's'} advance notice`
                    : 'Unavailable — too soon to book';
            }
            else if (s.is_full) meta = 'Fully booked';
            else meta = `${s.remaining} seat${s.remaining === 1 ? '' : 's'} available`;

            return `<button type="button" class="${cls}" data-slot-id="${s.slot_id}" ${disabled ? 'disabled' : ''} title="${escapeHtml(meta)}">
                <i class="ph ph-clock" aria-hidden="true"></i>
                <span class="ca-slot-chip-text">
                    <span class="ca-slot-chip-label">${escapeHtml(s.slot_label)}</span>
                    <span class="ca-slot-chip-meta">${escapeHtml(meta)}</span>
                </span>
            </button>`;
        }).join('');

        el.slots.querySelectorAll('.ca-slot-chip:not(:disabled)').forEach((btn) => {
            btn.addEventListener('click', () => {
                const slotId = parseInt(btn.getAttribute('data-slot-id'), 10);
                state.selectedSlot = slots.find(s => s.slot_id === slotId);
                hideWizardAlert();
                renderSlots();
            });
        });
    }

    function startPolling() {
        stopPolling();
        state.pollTimer = setInterval(() => refreshDateAvailability(true), 18000);
    }
    function stopPolling() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    // ---- Guest stepper ------------------------------------------------------------

    function renderGuestStepper() {
        const min = SETTINGS.reservation_min_guests;
        el.guestValue.textContent = state.guests;
        el.guestUnit.textContent = state.guests === 1 ? 'guest' : 'guests';
        // Minus stops at 0 rather than at the minimum. The minimum is now
        // enforced by the warning below and by validateStep(), which is the
        // only place it can be enforced once counts under it are reachable.
        el.guestMinus.disabled = state.guests <= 0;
        el.guestPlus.disabled = state.guests >= SETTINGS.reservation_max_guests;
        // Held back until the stepper has actually been used: a red line under
        // an untouched field on first paint reads as "you did something wrong"
        // before the customer has done anything at all.
        el.guestError.textContent = (state.guestTouched && state.guests < min)
            ? `Reservations are for ${min} guest${min === 1 ? '' : 's'} or more.`
            : '';
    }

    el.guestMinus.addEventListener('click', () => {
        if (state.guests > 0) { state.guests--; state.guestTouched = true; hideWizardAlert(); renderGuestStepper(); }
    });
    el.guestPlus.addEventListener('click', () => {
        if (state.guests < SETTINGS.reservation_max_guests) { state.guests++; state.guestTouched = true; hideWizardAlert(); renderGuestStepper(); }
    });

    el.agreeTerms.addEventListener('change', () => {
        state.agreedToTerms = el.agreeTerms.checked;
        el.termsError.textContent = '';
    });

    // ---- Step 2: Advance order ------------------------------------------------------

    el.advOrderYes.addEventListener('click', () => {
        state.wantsAdvanceOrder = true;
        el.advOrderYes.classList.add('is-selected');
        el.advOrderNo.classList.remove('is-selected');
        el.advanceOrderMenu.hidden = false;
        renderMenu();
        renderCartBar();
        updateBottomBarMode();
    });
    el.advOrderNo.addEventListener('click', () => {
        state.wantsAdvanceOrder = false;
        state.cart = [];
        el.advOrderNo.classList.add('is-selected');
        el.advOrderYes.classList.remove('is-selected');
        el.advanceOrderMenu.hidden = true;
        renderCartBar();
        updateBottomBarMode();
    });

    // The desktop panel's button is the same action as the wizard's Continue.
    if (el.orderPanelContinue) {
        el.orderPanelContinue.addEventListener('click', () => el.wizNext.click());
    }

    // Category chips are built once from whatever categories actually exist
    // in the catalog (order of first appearance), not a fixed list.
    function getMenuCategories() {
        const cats = [];
        const seen = new Set();
        MENU_CATALOG.forEach((item) => {
            const cat = item.category_name || 'Other';
            if (!seen.has(cat)) { seen.add(cat); cats.push(cat); }
        });
        return cats;
    }

    // Builds the search + filter chip toolbar once (its own listeners are
    // wired here, once) and an empty grid target -- renderMenuItems() below
    // is what actually (re-)populates the grid, called on every search/
    // filter change without re-touching the toolbar, so the search input
    // never loses focus mid-keystroke.
    function renderMenu() {
        if (MENU_CATALOG.length === 0) {
            el.menuGrid.innerHTML = '<div class="ca-empty-state"><i class="ph ph-fork-knife"></i>No menu items available right now.</div>';
            return;
        }

        const categories = getMenuCategories();

        el.menuGrid.innerHTML = `
            <div id="advMenuAiMount"></div>
            <div class="ca-menu-toolbar">
                <label class="ca-menu-search">
                    <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" id="advMenuSearch" placeholder="Search menu&hellip;" autocomplete="off" aria-label="Search menu">
                </label>
                <div class="ca-menu-filter-chips" id="advMenuChips">
                    <button type="button" class="ca-menu-filter-chip is-active" data-cat="all">All</button>
                    ${categories.map((c) => `<button type="button" class="ca-menu-filter-chip" data-cat="${escapeHtml(c)}">${escapeHtml(c)}</button>`).join('')}
                </div>
            </div>
            <div class="ca-menu-grid" id="advMenuItems"></div>
        `;

        document.getElementById('advMenuSearch').addEventListener('input', (e) => {
            state.menuSearchQuery = e.target.value.trim().toLowerCase();
            renderMenuItems();
        });

        document.getElementById('advMenuChips').querySelectorAll('.ca-menu-filter-chip').forEach((chip) => {
            chip.addEventListener('click', () => {
                state.menuActiveCategory = chip.getAttribute('data-cat');
                document.querySelectorAll('#advMenuChips .ca-menu-filter-chip').forEach((c) => c.classList.toggle('is-active', c === chip));
                renderMenuItems();
                // The AI result was scoped to the category active when it ran,
                // so a chip change has to re-ask rather than reuse a stale set.
                if (menuAiFilter && menuAiFilter.hasQuery()) menuAiFilter.rerunIfActive();
            });
        });

        mountMenuAiFilter();
        renderMenuItems();
    }

    // The chips carry a category NAME (that's what this step filters on), but
    // the endpoint keys off category_id -- resolved through MENU_CATALOG,
    // which already carries both.
    function activeCategoryIdForAi() {
        if (state.menuActiveCategory === 'all') return null;
        const match = MENU_CATALOG.find((i) => (i.category_name || 'Other') === state.menuActiveCategory);
        return match && match.category_id ? parseInt(match.category_id, 10) : null;
    }

    function mountMenuAiFilter() {
        const mount = document.getElementById('advMenuAiMount');
        if (!mount || typeof window.createMenuAiFilter !== 'function') return;

        menuAiFilter = window.createMenuAiFilter({
            getCategoryId: activeCategoryIdForAi,
            onApply: (itemIds) => {
                state.menuAiAllowedIds = new Set(itemIds);
                state.menuAiOrder = itemIds;
                renderMenuItems();
            },
            onClear: () => {
                state.menuAiAllowedIds = null;
                state.menuAiOrder = null;
                renderMenuItems();
            }
        });
        mount.appendChild(menuAiFilter.element);
    }

    // Brief scale/flash feedback -- retriggerable even on a freshly-created
    // element, since the class is simply added and then dropped again once
    // its CSS animation finishes.
    function bumpElement(element) {
        if (!element) return;
        element.classList.add('is-bump');
        element.addEventListener('animationend', () => element.classList.remove('is-bump'), { once: true });
    }

    function pulseCartBar() {
        if (!el.cartBar || el.cartBar.hidden) return;
        el.cartBar.classList.add('is-pulse');
        el.cartBar.addEventListener('animationend', () => el.cartBar.classList.remove('is-pulse'), { once: true });
    }

    // Real data only: is_out_of_stock comes from make_reservation.php (live
    // inventory). No vegetarian/spicy badges -- nothing in the schema
    // tracks dietary info yet, and guessing would be actively misleading.
    function menuBadgesHtml(item) {
        const badges = [];
        if (item.is_out_of_stock) badges.push('<span class="ca-menu-badge is-outofstock">Out of Stock</span>');
        return badges.length ? `<div class="ca-menu-card-badges">${badges.join('')}</div>` : '';
    }

    function menuCardActionsHtml(itemId) {
        const item = MENU_CATALOG.find(i => i.item_id === itemId);
        if (item && item.is_out_of_stock) {
            return '<span class="ca-menu-card-unavailable">Unavailable</span>';
        }
        const qty = cartQty(itemId);
        return qty > 0
            ? `<div class="ca-stepper ca-menu-card-stepper">
                   <button type="button" class="ca-stepper-btn menu-qty-minus" data-item-id="${itemId}" aria-label="Decrease quantity">&minus;</button>
                   <span class="ca-stepper-value menu-qty-value" data-item-id="${itemId}">${qty}</span>
                   <button type="button" class="ca-stepper-btn menu-qty-plus" data-item-id="${itemId}" aria-label="Increase quantity">+</button>
               </div>`
            : `<button type="button" class="ca-menu-card-add menu-qty-plus" data-item-id="${itemId}">
                   <i class="ph ph-plus" aria-hidden="true"></i> Add
               </button>`;
    }

    function wireMenuCardButtons(scope) {
        scope.querySelectorAll('.menu-qty-plus').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                changeCartQty(parseInt(btn.getAttribute('data-item-id'), 10), 1);
            });
        });
        scope.querySelectorAll('.menu-qty-minus').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                changeCartQty(parseInt(btn.getAttribute('data-item-id'), 10), -1);
            });
        });
    }

    // Re-renders just one card's actions (Add button <-> stepper) in place
    // -- not a full grid re-render, so scroll position and any in-progress
    // search typing are undisturbed.
    function refreshMenuCardActions(itemId) {
        const actionsEl = el.menuGrid.querySelector(`.ca-menu-card-actions[data-item-id="${itemId}"]`);
        if (actionsEl) {
            actionsEl.innerHTML = menuCardActionsHtml(itemId);
            wireMenuCardButtons(actionsEl);
        }
    }

    function menuCardHtml(item) {
        // image_url is stored relative to owner/ (menu items are managed
        // from the owner panel) -- customer/ is a sibling folder, so it
        // needs the same "../owner/" prefix menu.php uses.
        const img = item.image_url
            ? `<img class="ca-menu-card-img" src="../owner/${escapeHtml(item.image_url)}" alt="${escapeHtml(item.item_name)}">`
            : `<div class="ca-menu-card-img-placeholder"><i class="ph ph-fork-knife"></i></div>`;
        const descHtml = item.description ? `<div class="ca-menu-card-desc">${escapeHtml(item.description)}</div>` : '';

        return `
            <div class="ca-menu-card${item.is_out_of_stock ? ' is-outofstock' : ''}" data-item-id="${item.item_id}">
                <div class="ca-menu-card-img-wrap">
                    ${img}
                    ${menuBadgesHtml(item)}
                </div>
                <div class="ca-menu-card-body">
                    <div class="ca-menu-card-name">${escapeHtml(item.item_name)}</div>
                    ${descHtml}
                    <div class="ca-menu-card-footer">
                        <span class="ca-menu-card-price">${formatCurrency(parseFloat(item.selling_price))}</span>
                        <div class="ca-menu-card-actions" data-item-id="${item.item_id}">${menuCardActionsHtml(item.item_id)}</div>
                    </div>
                </div>
            </div>
        `;
    }

    function renderMenuItems() {
        const container = document.getElementById('advMenuItems');
        if (!container) return;

        const filtered = MENU_CATALOG.filter((item) => {
            const cat = item.category_name || 'Other';
            const matchesCategory = state.menuActiveCategory === 'all' || cat === state.menuActiveCategory;
            const matchesSearch = !state.menuSearchQuery || item.item_name.toLowerCase().includes(state.menuSearchQuery);
            // null (not an empty Set) means no AI filter is active.
            const matchesAi = !state.menuAiAllowedIds || state.menuAiAllowedIds.has(parseInt(item.item_id, 10));
            return matchesCategory && matchesSearch && matchesAi;
        });

        // Honour the server's ordering (best-seller ranking, then relevance)
        // so "most popular first" actually shows that way.
        if (state.menuAiOrder) {
            const rank = new Map(state.menuAiOrder.map((id, i) => [id, i]));
            filtered.sort((a, b) => {
                const ra = rank.has(parseInt(a.item_id, 10)) ? rank.get(parseInt(a.item_id, 10)) : Number.MAX_SAFE_INTEGER;
                const rb = rank.has(parseInt(b.item_id, 10)) ? rank.get(parseInt(b.item_id, 10)) : Number.MAX_SAFE_INTEGER;
                return ra - rb;
            });
        }

        if (filtered.length === 0) {
            container.innerHTML = '<div class="ca-empty-state"><i class="ph ph-magnifying-glass"></i>No items match.</div>';
            return;
        }

        container.innerHTML = filtered.map(menuCardHtml).join('');

        // The whole card opens the item detail sheet; a click that
        // originated inside the qty controls is left alone (they already
        // stopPropagation() in wireMenuCardButtons()) so tapping +/- or Add
        // doesn't also pop the sheet open underneath it.
        container.querySelectorAll('.ca-menu-card').forEach((card) => {
            card.addEventListener('click', () => {
                openItemSheet(parseInt(card.getAttribute('data-item-id'), 10));
            });
        });

        wireMenuCardButtons(container);
    }

    function cartQty(itemId) {
        const line = state.cart.find(l => l.item_id === itemId);
        return line ? line.quantity : 0;
    }

    // Delta-based -- used by the inline +/- steppers once an item is
    // already in the cart. setCartLine() below (used by the item detail
    // sheet) sets an absolute quantity instead.
    function changeCartQty(itemId, delta) {
        const item = MENU_CATALOG.find(i => i.item_id === itemId);
        if (!item || item.is_out_of_stock) return;
        let line = state.cart.find(l => l.item_id === itemId);
        if (!line) {
            if (delta <= 0) return;
            line = { item_id: itemId, item_name: item.item_name, unit_price: parseFloat(item.selling_price), quantity: 0 };
            state.cart.push(line);
        }
        line.quantity = Math.max(0, line.quantity + delta);
        if (line.quantity === 0) {
            state.cart = state.cart.filter(l => l.item_id !== itemId);
        }
        refreshMenuCardActions(itemId);
        if (delta > 0) {
            bumpElement(el.menuGrid.querySelector(`.menu-qty-value[data-item-id="${itemId}"]`));
            pulseCartBar();
        }
        renderCartBar();
        updateBottomBarMode();
    }

    // Absolute-quantity set (not delta) -- used by the item detail sheet's
    // "Add to Order", which always reflects exactly what the sheet's own
    // stepper currently shows, and is the only path that can attach a
    // per-item note.
    function setCartLine(itemId, quantity) {
        const item = MENU_CATALOG.find(i => i.item_id === itemId);
        if (!item) return;
        let line = state.cart.find(l => l.item_id === itemId);
        if (quantity <= 0) {
            state.cart = state.cart.filter(l => l.item_id !== itemId);
        } else if (line) {
            line.quantity = quantity;
        } else {
            state.cart.push({ item_id: itemId, item_name: item.item_name, unit_price: parseFloat(item.selling_price), quantity });
        }
        refreshMenuCardActions(itemId);
        renderCartBar();
        updateBottomBarMode();
    }

    function cartSubtotal() {
        return state.cart.reduce((sum, l) => sum + (l.unit_price * l.quantity), 0);
    }

    // computeEstimatedTax()/TAX_SETTINGS: a preview only, using the
    // restaurant's real active tax rate (never fabricated), shown so the
    // customer isn't surprised later -- what's actually charged today is
    // just the deposit percentage of the pre-order subtotal (see
    // computePaymentDue()); the rest, including tax, settles at the
    // restaurant, same as computeOrderTotals() in cashier/pos_functions.php
    // handles it at checkout.
    function computeEstimatedTax(subtotal) {
        const rate = parseFloat(TAX_SETTINGS.tax_rate) || 0;
        if (rate <= 0 || subtotal <= 0) return 0;
        if (TAX_SETTINGS.tax_type === 'inclusive') {
            return Math.round((subtotal * rate / (100 + rate)) * 100) / 100;
        }
        return Math.round((subtotal * rate / 100) * 100) / 100;
    }

    // ---- Sticky cart bar (collapsed summary + expandable order sheet) --------

    // The phone's bottom cart sheet and the desktop "My Order" panel show the
    // same thing, so both are filled from these two functions against the same
    // state.cart -- there is no second copy of the cart to drift out of sync.
    // Which one is on screen is purely a CSS breakpoint decision.
    function renderCartBar() {
        const count = state.cart.reduce((sum, l) => sum + l.quantity, 0);
        el.cartBarCount.textContent = count;
        el.cartBarItemsLabel.textContent = `${count} item${count === 1 ? '' : 's'}`;
        el.cartBarTotal.textContent = 'Total: ' + formatCurrency(cartSubtotal());

        renderCartLinesInto(el.cartSheetLines);
        renderCartLinesInto(el.orderPanelLines);
        renderCartTotalsInto(el.cartSheetTotals);
        renderCartTotalsInto(el.orderPanelTotals);
    }

    function renderCartLinesInto(target) {
        if (!target) return;

        if (state.cart.length === 0) {
            target.innerHTML = '<div class="ca-cart-empty">No items added yet. Browse the menu to add something.</div>';
            return;
        }
        target.innerHTML = state.cart.map((l) => `
            <div class="ca-cart-row">
                <div class="ca-cart-row-main">
                    <span class="ca-cart-row-name">${escapeHtml(l.item_name)}</span>
                    <span class="ca-cart-qty-stepper">
                        <button type="button" class="ca-cart-qty-btn" data-qty-delta="-1" data-item-id="${l.item_id}" aria-label="Decrease quantity of ${escapeHtml(l.item_name)}">&minus;</button>
                        <span class="ca-cart-qty-value">${l.quantity}</span>
                        <button type="button" class="ca-cart-qty-btn" data-qty-delta="1" data-item-id="${l.item_id}" aria-label="Increase quantity of ${escapeHtml(l.item_name)}">+</button>
                    </span>
                </div>
                <span class="ca-cart-row-sub">${formatCurrency(l.unit_price * l.quantity)}</span>
                <button type="button" class="ca-cart-remove" data-item-id="${l.item_id}" aria-label="Remove ${escapeHtml(l.item_name)}"><i class="ph ph-x"></i></button>
            </div>
        `).join('');

        // Quantity is adjustable from the cart itself, not just the menu card.
        // Without this, an item whose card is filtered out of the grid (a
        // category chip, a search term, or an AI filter) could only be deleted
        // -- refreshMenuCardActions() is a no-op when the card isn't rendered,
        // so there was no way to go from 1 to 2 without clearing the filter.
        // changeCartQty() is the same function the card steppers use, so both
        // views stay in step and neither needs its own quantity logic.
        target.querySelectorAll('.ca-cart-qty-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                changeCartQty(
                    parseInt(btn.getAttribute('data-item-id'), 10),
                    parseInt(btn.getAttribute('data-qty-delta'), 10)
                );
            });
        });

        target.querySelectorAll('.ca-cart-remove').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-item-id'), 10);
                state.cart = state.cart.filter(l => l.item_id !== id);
                refreshMenuCardActions(id);
                renderCartBar();
                updateBottomBarMode();
            });
        });
    }

    function renderCartTotalsInto(target) {
        if (!target) return;

        const subtotal = cartSubtotal();
        const tax = computeEstimatedTax(subtotal);
        // Inclusive tax is already part of the subtotal (it's just broken
        // out below for transparency); exclusive tax is added on top.
        const total = TAX_SETTINGS.tax_type === 'inclusive' ? subtotal : subtotal + tax;

        target.innerHTML = `
            <div class="ca-cart-total-row"><span>Subtotal</span><span>${formatCurrency(subtotal)}</span></div>
            <div class="ca-cart-total-row"><span>Estimated ${escapeHtml(TAX_SETTINGS.tax_name || 'Tax')}</span><span>${formatCurrency(tax)}</span></div>
            <div class="ca-cart-total-row ca-cart-total-grand"><span>Estimated Total</span><span>${formatCurrency(total)}</span></div>
        `;
    }

    el.cartBarToggle.addEventListener('click', () => {
        state.cartBarExpanded = !state.cartBarExpanded;
        el.cartBar.classList.toggle('is-expanded', state.cartBarExpanded);
        el.cartBarToggle.setAttribute('aria-expanded', String(state.cartBarExpanded));
    });

    // ---- Item detail bottom sheet --------------------------------------------
    // Ingredients/allergens intentionally aren't shown -- nothing in the
    // menu_items schema tracks either, and this app already removed a
    // "Stock: 1352"-style badge from the plain browse-menu page for being
    // noise, so inventing dietary data here would be worse than noise.

    function openItemSheet(itemId) {
        const item = MENU_CATALOG.find(i => i.item_id === itemId);
        if (!item) return;

        state.sheetItemId = itemId;
        const existingLine = state.cart.find(l => l.item_id === itemId);
        state.sheetQty = existingLine ? existingLine.quantity : 1;

        el.itemSheetImageWrap.innerHTML = item.image_url
            ? `<img src="../owner/${escapeHtml(item.image_url)}" alt="${escapeHtml(item.item_name)}">`
            : `<div class="ca-item-sheet-image-placeholder"><i class="ph ph-fork-knife"></i></div>`;
        el.itemSheetBadges.innerHTML = menuBadgesHtml(item);
        el.itemSheetName.textContent = item.item_name;
        el.itemSheetDesc.textContent = item.description || '';
        el.itemSheetDesc.hidden = !item.description;
        el.itemSheetPrice.textContent = formatCurrency(parseFloat(item.selling_price));
        el.itemSheetQty.textContent = state.sheetQty;
        el.itemSheetMinus.disabled = !!item.is_out_of_stock;
        el.itemSheetPlus.disabled = !!item.is_out_of_stock;
        el.itemSheetAddBtn.disabled = !!item.is_out_of_stock;
        el.itemSheetAddBtn.textContent = item.is_out_of_stock ? 'Unavailable' : (existingLine ? 'Update Order' : 'Add to Order');

        el.itemSheetBackdrop.classList.add('is-open');
        document.body.classList.add('ca-modal-open');
    }

    function closeItemSheet() {
        el.itemSheetBackdrop.classList.remove('is-open');
        document.body.classList.remove('ca-modal-open');
        state.sheetItemId = null;
    }

    el.itemSheetClose.addEventListener('click', closeItemSheet);
    el.itemSheetBackdrop.addEventListener('click', (e) => {
        if (e.target === el.itemSheetBackdrop) closeItemSheet();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && el.itemSheetBackdrop.classList.contains('is-open')) closeItemSheet();
    });

    el.itemSheetMinus.addEventListener('click', () => {
        state.sheetQty = Math.max(1, state.sheetQty - 1);
        el.itemSheetQty.textContent = state.sheetQty;
        bumpElement(el.itemSheetQty);
    });
    el.itemSheetPlus.addEventListener('click', () => {
        state.sheetQty += 1;
        el.itemSheetQty.textContent = state.sheetQty;
        bumpElement(el.itemSheetQty);
    });

    el.itemSheetAddBtn.addEventListener('click', () => {
        if (state.sheetItemId === null) return;
        setCartLine(state.sheetItemId, state.sheetQty);
        closeItemSheet();
        pulseCartBar();
    });

    // ---- Step 3: Summary ------------------------------------------------------

    function computePaymentDue() {
        const advTotal = state.wantsAdvanceOrder ? cartSubtotal() : 0;
        if (advTotal > 0) {
            const pct = SETTINGS.advance_order_deposit_percentage;
            const due = Math.round((advTotal * pct / 100) * 100) / 100;
            return { purpose: 'advance_order_deposit', amountDue: due, depositPct: pct, advTotal, remaining: Math.round((advTotal - due) * 100) / 100 };
        }
        return { purpose: 'reservation_fee', amountDue: SETTINGS.reservation_fee_amount, depositPct: null, advTotal: 0, remaining: 0 };
    }

    function renderSummary() {
        const pay = computePaymentDue();
        let html = `
            <div class="ca-receipt-section">
                <div class="ca-receipt-section-title">Reservation Details</div>
                <div class="ca-receipt-row"><span>Date</span><span>${escapeHtml(formatDateLong(state.selectedDate))}</span></div>
                <div class="ca-receipt-row"><span>Time</span><span>${escapeHtml(state.selectedSlot ? state.selectedSlot.slot_label : '')}</span></div>
                <div class="ca-receipt-row"><span>Guests</span><span>${state.guests}</span></div>
            </div>
        `;

        if (state.wantsAdvanceOrder && state.cart.length > 0) {
            html += `<div class="ca-receipt-section"><div class="ca-receipt-section-title">Advance Order</div>`;
            state.cart.forEach((l) => {
                html += `<div class="ca-receipt-row"><span>${escapeHtml(l.item_name)} &times; ${l.quantity}</span><span>${formatCurrency(l.unit_price * l.quantity)}</span></div>`;
            });
            html += `<div class="ca-receipt-row ca-receipt-grand"><span>Order total</span><span>${formatCurrency(pay.advTotal)}</span></div></div>`;
        }

        html += `<div class="ca-receipt-section">
            <div class="ca-receipt-section-title">Payment Summary</div>`;
        if (pay.purpose === 'reservation_fee') {
            html += `<div class="ca-receipt-row"><span>Reservation fee</span><span>${formatCurrency(pay.amountDue)}</span></div>`;
        } else {
            html += `<div class="ca-receipt-row"><span>Deposit (${pay.depositPct}% of order)</span><span>${formatCurrency(pay.amountDue)}</span></div>
                      <div class="ca-receipt-row"><span>Remaining balance </span><span>${formatCurrency(pay.remaining)}</span></div>`;
        }
        html += `<div class="ca-receipt-row ca-receipt-grand"><span>Amount due </span><span>${formatCurrency(pay.amountDue)}</span></div>
            </div>`;
        // No "Policies" section here anymore -- superseded by the dedicated
        // Terms & Conditions card in make_reservation.php's step 3 panel,
        // which the customer has to actually agree to (see validateStep()'s
        // 'summary' case), not just a passive line of receipt text.

        el.summaryReceipt.innerHTML = html;
    }

    // ---- Validation per step ------------------------------------------------------

    function validateStep(key) {
        if (key === 'details') {
            if (!state.selectedDate || !state.selectedSlot) return 'Please select a date and time slot.';
            if (state.guests < SETTINGS.reservation_min_guests) {
                // Trying to continue counts as having engaged with the field, so
                // the inline warning surfaces from here on even if the stepper
                // itself was never touched.
                state.guestTouched = true;
                const msg = state.guests === 0
                    ? `Please set your party size — reservations are for ${SETTINGS.reservation_min_guests} guests or more.`
                    : `Reservations are for ${SETTINGS.reservation_min_guests} guests or more.`;
                el.guestError.textContent = msg;
                return msg;
            }
            if (state.guests > SETTINGS.reservation_max_guests) {
                el.guestError.textContent = `Maximum reservation is ${SETTINGS.reservation_max_guests} guests.`;
                return `Maximum reservation is ${SETTINGS.reservation_max_guests} guests.`;
            }
            if (state.selectedSlot.remaining < state.guests) {
                return `Only ${state.selectedSlot.remaining} seats remain for this time slot.`;
            }
            return null;
        }

    /**
     * One-button notice for the advance-order step.
     *
     * Falls back to a plain alert() if confirm-modal.js failed to load, so a
     * missing script can never silently swallow the reason a guest cannot
     * continue -- an unexplained dead Continue button is the worst outcome here.
     */
    function showAdvanceOrderNotice(title, message) {
        if (typeof window.confirmAction === 'function') {
            window.confirmAction(message, { title: title, alert: true, confirmText: 'Got it' });
        } else {
            window.alert(message);
        }
    }

        if (key === 'advance') {
            if (state.wantsAdvanceOrder === null) return 'Please let us know if you\'d like to place an advance order.';
            if (state.wantsAdvanceOrder) {
                if (state.cart.length === 0) {
                    // A modal, not a line of text above the menu: on a phone the
                    // menu is long and the Continue button is at the bottom, so a
                    // message pinned to the top scrolled out of sight exactly when
                    // it was needed. Nothing to decline here, so one button.
                    showAdvanceOrderNotice(
                        'Your order is empty',
                        'Please add at least one item, or choose \u201cNo, just the table\u201d instead.'
                    );
                    return 'no items';
                }
                const subtotal = cartSubtotal();
                if (subtotal < SETTINGS.advance_order_min_amount) {
                    const msg = `Minimum advance order amount is ${formatCurrency(SETTINGS.advance_order_min_amount)}.`;
                    showAdvanceOrderNotice('A little more to go', msg);
                    return msg;
                }
            }
            return null;
        }
        if (key === 'summary') {
            if (!state.agreedToTerms) {
                const msg = 'Please agree to the Terms & Conditions to continue.';
                el.termsError.textContent = msg;
                return msg;
            }
            el.termsError.textContent = '';
            return null;
        }
        return null;
    }

    // ---- Navigation ------------------------------------------------------

    // Shared by the wizard's own Continue button and the cart sheet's
    // Continue button (shown instead of the wizard nav once there's an
    // advance order) -- both just mean "move to the next step."
    // The details step is the one that can fail with nothing on screen to
    // attach the reason to -- no date picked, no slot picked -- so it needs a
    // message of its own. The other steps already write their reason into a
    // field-level error next to the control that caused it.
    function showWizardAlert(message) {
        if (!el.wizardAlert) return;
        el.wizardAlertText.textContent = message;
        el.wizardAlert.hidden = false;
    }
    function hideWizardAlert() {
        if (el.wizardAlert) el.wizardAlert.hidden = true;
    }
    if (el.wizardAlertClose) {
        el.wizardAlertClose.addEventListener('click', hideWizardAlert);
    }

    async function goNext() {
        const steps = getStepDefs();
        const currentKey = steps[state.stepIndex].key;
        const error = validateStep(currentKey);
        if (error) {
            if (currentKey === 'details') {
                showWizardAlert(error);
            }
            return;
        }
        // Whatever it was complaining about has just passed validation.
        hideWizardAlert();

        if (currentKey === 'summary') {
            await submitCheckout();
            return;
        }

        showStep(state.stepIndex + 1);
    }

    el.wizNext.addEventListener('click', goNext);
    el.cartSheetContinue.addEventListener('click', goNext);

    el.wizBack.addEventListener('click', () => {
        if (state.stepIndex > 0) showStep(state.stepIndex - 1);
    });

    // ---- Checkout ------------------------------------------------------

    async function submitCheckout() {
        if (state.submitting) return;
        state.submitting = true;
        el.checkoutError.textContent = '';
        showLoading('Reserving your table…');

        const advanceOrderItems = (state.wantsAdvanceOrder ? state.cart : []).map(l => ({ item_id: l.item_id, quantity: l.quantity }));
        const body = new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            reservation_date: state.selectedDate,
            slot_id: String(state.selectedSlot.slot_id),
            number_of_guests: String(state.guests),
            advance_order_items: JSON.stringify(advanceOrderItems),
        });

        try {
            const data = await fetchJSON('api/reservation_checkout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });

            if (data.checkout_url) {
                showLoading('Redirecting to secure payment…');
                window.location.href = data.checkout_url;
                return;
            }
            throw new Error('Payment could not be started. Please try again.');
        } catch (e) {
            hideLoading();
            el.checkoutError.textContent = e.message;
            state.submitting = false;
        }
    }

    // ---- Init ------------------------------------------------------

    renderGuestStepper();
    renderCalendar();
    showStep(0);
})();
