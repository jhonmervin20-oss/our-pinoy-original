/**
 * owner/assets/js/filter-persist.js
 *
 * Carries a list page's filter bar across ONE save round-trip, then forgets it.
 *
 * The problem: every list page filters client-side (a search box and a few
 * selects, over rows already in the DOM), but every save/toggle posts to a
 * *_save.php that finishes with `header('Location: <list>.php')` -- no query
 * string. The redirect reloads the page from scratch, so filter to one
 * department, fix one row, and you are back at the top of an unfiltered list.
 *
 * The important half of the design is the FORGETTING. A first version simply
 * kept the state in sessionStorage per path, which meant a filter set once
 * stayed set for the rest of the session -- go to the dashboard, come back, and
 * the list was still filtered with no memory of having done it. That is worse
 * than the original bug, because the page silently lies about how much data it
 * is showing.
 *
 * So the state is a one-shot handoff, not a preference:
 *   - written only when a form on this page is submitted (the edit/save flow),
 *   - consumed and deleted on the very next load of the same page,
 *   - ignored entirely if more than HANDOFF_TTL_MS has passed.
 *
 * Arriving from anywhere else -- a nav link, a bookmark, a refresh -- therefore
 * starts clean, which is what every other admin tool does.
 */
(function () {
    'use strict';

    var KEY   = 'opo:filters:' + window.location.pathname;
    var SCOPE = '.owner-inv-filters';

    // Long enough for a save to round-trip, short enough that a submit you
    // abandoned (closed the tab, wandered off) cannot resurface later.
    var HANDOFF_TTL_MS = 120000;

    /** Filter bars safe to persist, i.e. the client-side-only ones. */
    function bars() {
        return Array.prototype.filter.call(
            document.querySelectorAll(SCOPE),
            // A GET form's controls are already carried in the URL and rendered
            // back by PHP. Restoring those here would fight the server value.
            function (bar) { return !bar.closest('form'); }
        );
    }

    function controls() {
        var out = [];
        bars().forEach(function (bar) {
            Array.prototype.forEach.call(bar.querySelectorAll('input, select'), function (el) {
                if (!el.id) return;
                if (el.type === 'hidden' || el.type === 'button' || el.type === 'submit') return;
                out.push(el);
            });
        });
        return out;
    }

    /** True only if at least one control is actually filtering something. */
    function isFiltering(values) {
        return Object.keys(values).some(function (k) {
            var v = values[k];
            return v !== '' && v !== false;
        });
    }

    function handOff() {
        try {
            var values = {};
            controls().forEach(function (el) {
                values[el.id] = (el.type === 'checkbox') ? el.checked : el.value;
            });
            // Nothing set means nothing to carry -- don't leave a stale marker.
            if (!isFiltering(values)) {
                sessionStorage.removeItem(KEY);
                return;
            }
            sessionStorage.setItem(KEY, JSON.stringify({ ts: Date.now(), values: values }));
        } catch (e) {
            // Private windows and "block site data" throw on write. Filters just
            // stop carrying across a save; nothing else depends on this.
        }
    }

    /** Reads, deletes, and returns the handoff -- it is valid for one load only. */
    function takeHandoff() {
        var raw = null;
        try {
            raw = sessionStorage.getItem(KEY);
            sessionStorage.removeItem(KEY);
        } catch (e) {
            return null;
        }
        if (!raw) return null;

        var parsed;
        try {
            parsed = JSON.parse(raw);
        } catch (e) {
            return null;
        }
        if (!parsed || !parsed.values || typeof parsed.ts !== 'number') return null;
        if (Date.now() - parsed.ts > HANDOFF_TTL_MS) return null;

        return parsed.values;
    }

    function restore(values) {
        controls().forEach(function (el) {
            if (!Object.prototype.hasOwnProperty.call(values, el.id)) return;
            var v = values[el.id];

            if (el.type === 'checkbox') {
                if (el.checked === v) return;
                el.checked = v;
            } else {
                if (el.value === v) return;
                // The saved option may be gone -- a department deleted, a status
                // no longer present. Leave the page's own default rather than
                // selecting nothing and rendering an empty table.
                if (el.tagName === 'SELECT' && !Array.prototype.some.call(el.options, function (o) { return o.value === v; })) {
                    return;
                }
                el.value = v;
            }

            // Replay the event a person typing would have fired, so the page's
            // own applyFilters() runs exactly as it always does.
            el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
        });
    }

    function init() {
        if (!bars().length) return;

        var values = takeHandoff();
        if (values) restore(values);

        // Capture phase: a submit handler elsewhere may preventDefault or
        // re-target, but by then the user's intent to save is already clear.
        document.addEventListener('submit', handOff, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
