/**
 * owner/assets/js/filter-autosubmit.js
 *
 * Makes a server-side filter bar filter as you use it, so it needs no "Filter"
 * button and no "Clear" link -- emptying a field IS clearing it.
 *
 * Opt in per form: <form class="owner-inv-filters" data-autosubmit="orders">.
 * The attribute's value just names the bar, so focus can be restored to the
 * right one on a page that has more than one.
 *
 * Why a real submit and not client-side filtering: these lists are filtered in
 * SQL and paginated, so the DOM only ever holds ONE page of rows. Hiding rows
 * client-side would silently filter those few instead of the whole result set.
 *
 * Two details that make a reloading filter bar usable:
 *   - Empty controls are disabled just before submit, so they are left out of
 *     the query string. Without it every filter change grows a URL full of
 *     `?date_from=&order_type=&search=`. The page navigates away immediately,
 *     so nothing is visibly disabled.
 *   - The focused field and caret position are stashed and restored, since a
 *     full reload per keystroke would otherwise throw you out of the text box
 *     mid-word.
 *
 * Dropping `page` happens for free: it is not a control in the form, so
 * changing a filter while on page 3 correctly lands back on page 1.
 */
(function () {
    'use strict';

    // Long enough to type a few characters without a reload between each one.
    var DEBOUNCE_MS = 450;
    var FOCUS_KEY = 'opo:filterFocus:' + window.location.pathname;

    var forms = document.querySelectorAll('form.owner-inv-filters[data-autosubmit]');

    Array.prototype.forEach.call(forms, function (form) {
        var timer = null;

        function submitNow() {
            clearTimeout(timer);

            // Capture focus BEFORE disabling anything -- disabling the focused
            // control blurs it, and activeElement would already be <body>.
            var active = document.activeElement;
            if (active && form.contains(active) && active.name) {
                var pos = null;
                try { pos = active.selectionStart; } catch (e) { /* date/select inputs throw */ }
                try {
                    sessionStorage.setItem(FOCUS_KEY, JSON.stringify({
                        bar: form.getAttribute('data-autosubmit'),
                        name: active.name,
                        pos: pos
                    }));
                } catch (e) { /* storage blocked -- we just lose focus restore */ }
            }

            Array.prototype.forEach.call(form.querySelectorAll('input, select'), function (el) {
                if (el.type !== 'hidden' && el.value === '') { el.disabled = true; }
            });

            form.submit();
        }

        // isTrusted keeps a scripted change (filter-persist.js restores values
        // by dispatching change/input) from triggering a submit, which would
        // reload, restore, submit... forever.
        form.addEventListener('change', function (e) {
            if (!e.isTrusted) { return; }
            if (e.target.matches('select, input[type="date"]')) { submitNow(); }
        });

        form.addEventListener('input', function (e) {
            if (!e.isTrusted) { return; }
            if (!e.target.matches('input[type="text"], input[type="search"]')) { return; }
            clearTimeout(timer);
            timer = setTimeout(submitNow, DEBOUNCE_MS);
        });

        // Enter filters now rather than waiting out the debounce. Without this
        // the browser would submit the form itself and skip the empty-field
        // stripping and focus capture above.
        form.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') { return; }
            e.preventDefault();
            submitNow();
        });
    });

    // -- Restore focus after the reload the filtering caused --------------------
    try {
        var raw = sessionStorage.getItem(FOCUS_KEY);
        if (raw) {
            sessionStorage.removeItem(FOCUS_KEY);
            var want = JSON.parse(raw);
            var bar = document.querySelector(
                'form.owner-inv-filters[data-autosubmit="' + want.bar + '"]'
            );
            var el = bar && bar.querySelector('[name="' + want.name + '"]');
            if (el) {
                el.focus();
                if (want.pos !== null && el.setSelectionRange) {
                    // Only text-ish inputs support a caret; the rest throw.
                    try { el.setSelectionRange(want.pos, want.pos); } catch (e) {}
                }
            }
        }
    } catch (e) { /* nothing to restore */ }
})();
