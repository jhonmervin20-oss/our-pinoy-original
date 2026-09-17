/**
 * customer/assets/js/menu-ai-filter.js
 *
 * The "Describe your preference" control, shared by customer/menu.php and the
 * advance-order step of make_reservation.php so both surfaces behave and look
 * identically. Both live in customer/, so the endpoint path is the same
 * relative 'api/menu_ai_filter.php' from either.
 *
 * This module owns only the control and the request. It does NOT touch the
 * grid -- it hands the caller an ordered list of item_ids through onApply()
 * and lets each page apply that however it already filters (menu.js toggles
 * the hidden attribute on server-rendered cards; make-reservation.js
 * re-renders from MENU_CATALOG). Keeping it that way is what lets one control
 * serve two quite different rendering strategies.
 *
 * Order matters in the returned ids: the server sorts them (best-seller
 * ranking, then relevance), so a caller that wants "most popular first" to
 * mean anything must preserve that sequence rather than re-sorting.
 */

(function () {
    'use strict';

    const ENDPOINT = 'api/menu_ai_filter.php';
    const PLACEHOLDER = 'Example: Something spicy, no shellfish, good for 5 people…';

    function buildMarkup() {
        const wrap = document.createElement('div');
        wrap.className = 'ca-menu-ai';
        wrap.innerHTML = `
            <span class="ca-menu-ai-label">Describe your preference</span>
            <div class="ca-menu-ai-row">
                <div class="ca-menu-ai-field">
                    <i class="ph ph-sparkle" aria-hidden="true"></i>
                    <input type="text" class="ca-menu-ai-input" placeholder="${PLACEHOLDER}"
                           autocomplete="off" maxlength="300" aria-label="Describe what you feel like eating">
                    <button type="button" class="ca-menu-ai-clear" aria-label="Clear" hidden>
                        <i class="ph ph-x" aria-hidden="true"></i>
                    </button>
                </div>
                <button type="button" class="ca-menu-ai-btn">
                    <i class="ph ph-sparkle" aria-hidden="true"></i> Find for me
                </button>
            </div>
            <div class="ca-menu-ai-result" hidden></div>
        `;
        return wrap;
    }

    /**
     * options:
     *   getCategoryId() -> number|null   the active chip ('All' must give null)
     *   onApply(itemIds)                 ordered ids to show
     *   onClear()                        drop the AI filter entirely
     */
    function createMenuAiFilter(options) {
        const el = buildMarkup();
        const input = el.querySelector('.ca-menu-ai-input');
        const clearBtn = el.querySelector('.ca-menu-ai-clear');
        const submitBtn = el.querySelector('.ca-menu-ai-btn');
        const result = el.querySelector('.ca-menu-ai-result');
        const icon = submitBtn.querySelector('i');

        let busy = false;

        function setResult(html, isError) {
            result.innerHTML = html;
            result.classList.toggle('ca-menu-ai-error', !!isError);
            result.hidden = !html;
        }

        function setBusy(on) {
            busy = on;
            submitBtn.disabled = on;
            submitBtn.classList.toggle('is-loading', on);
            // Swapping to a spinner glyph, not just spinning the sparkle --
            // a rotating sparkle reads as decoration rather than progress.
            icon.className = on ? 'ph ph-circle-notch' : 'ph ph-sparkle';
        }

        function syncClearVisibility() {
            clearBtn.hidden = input.value.trim() === '';
        }

        function clearFilter() {
            input.value = '';
            syncClearVisibility();
            setResult('', false);
            if (typeof options.onClear === 'function') options.onClear();
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, (c) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
            ));
        }

        async function submit() {
            const description = input.value.trim();
            if (busy) return;
            if (!description) {
                clearFilter();
                return;
            }

            setBusy(true);
            setResult('Finding dishes for you…', false);

            try {
                const categoryId = typeof options.getCategoryId === 'function' ? options.getCategoryId() : null;
                const res = await fetch(ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ description: description, category_id: categoryId })
                });
                const data = await res.json().catch(() => ({}));

                if (!res.ok) {
                    // Every failure path leaves the existing category/search
                    // filtering untouched -- the page must stay usable.
                    setResult(escapeHtml(data.error || 'Smart search is unavailable right now.'), true);
                    if (typeof options.onClear === 'function') options.onClear();
                    return;
                }

                const ids = Array.isArray(data.item_ids) ? data.item_ids : [];
                let html = escapeHtml(data.summary || '');
                (data.unsupported || []).forEach((note) => {
                    html += `<span class="ca-menu-ai-note">Couldn’t filter on: ${escapeHtml(note)}</span>`;
                });
                if (data.disclaimer) {
                    html += `<span class="ca-menu-ai-note">${escapeHtml(data.disclaimer)}</span>`;
                }
                setResult(html, false);

                if (typeof options.onApply === 'function') options.onApply(ids);
            } catch (err) {
                setResult('Smart search is unavailable right now — the category filters still work.', true);
                if (typeof options.onClear === 'function') options.onClear();
            } finally {
                setBusy(false);
            }
        }

        submitBtn.addEventListener('click', submit);
        clearBtn.addEventListener('click', clearFilter);
        input.addEventListener('input', syncClearVisibility);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                submit();
            }
        });

        return {
            element: el,
            clear: clearFilter,
            /** Re-run for the current text, e.g. after the category chip changed. */
            rerunIfActive: function () {
                if (input.value.trim() !== '') submit();
            },
            hasQuery: function () { return input.value.trim() !== ''; }
        };
    }

    window.createMenuAiFilter = createMenuAiFilter;
})();
