/**
 * owner/assets/js/table-pager.js
 *
 * Client-side pager for an already-rendered table, matching the behaviour of
 * the two pagers in inventory/inventory.php.
 *
 * WHY CLIENT-SIDE: Prev/Next only change which rows are hidden. There is no
 * navigation, so the browser never re-lands at the top of the document and the
 * reader keeps their place in the list. A server-paged table cannot do that
 * without an anchor or a scroll-restoration script, and both are workarounds
 * for a page load that did not need to happen.
 *
 * The trade-off is that every matching row must already be in the DOM. That
 * suits tables whose result set is bounded by filters applied server-side
 * first; it does not suit an unbounded one. See the query in
 * owner/feedback_moderation.php for where that line is drawn.
 *
 * Lifted verbatim from inventory.php's local makePager() so the two behave
 * identically. inventory.php still carries its own copy -- extracting this
 * file means the next table to need a pager reuses it rather than adding a
 * third variant, and inventory can adopt it whenever that file is next
 * touched.
 *
 * Usage:
 *   const pager = makeTablePager({
 *       rows:     Array.from(document.querySelectorAll('tbody tr[data-id]')),
 *       pageSize: 5,
 *       container: document.getElementById('myPagination'),
 *       prevBtn:   document.getElementById('myPagePrev'),
 *       nextBtn:   document.getElementById('myPageNext'),
 *       info:      document.getElementById('myPageInfo'),
 *       matchFn:   (row) => true,          // optional client-side filter
 *       onUpdate:  (visibleCount) => {},   // optional, for a count label
 *   });
 *   pager.update(true);                    // true = reset to page 1
 */
(function () {
    'use strict';

    function makeTablePager({ rows, pageSize, container, prevBtn, nextBtn, info, matchFn, onUpdate }) {
        let currentPage = 1;

        function update(resetPage) {
            // Reset on a FILTER change, not on a page change -- staying on
            // page 4 of a result set that just shrank to one page would show
            // an empty table with no obvious cause.
            if (resetPage) currentPage = 1;

            const matched = matchFn ? rows.filter(matchFn) : rows.slice();
            const totalPages = Math.max(1, Math.ceil(matched.length / pageSize));
            if (currentPage > totalPages) currentPage = totalPages;

            const start = (currentPage - 1) * pageSize;
            const visible = new Set(matched.slice(start, start + pageSize));
            rows.forEach((row) => { row.style.display = visible.has(row) ? '' : 'none'; });

            // Hidden when there is nothing to page through, so a short table
            // isn't given a control that cannot do anything.
            if (container) container.hidden = matched.length === 0 || totalPages <= 1;
            if (info) info.textContent = `Page ${currentPage} of ${totalPages}`;
            if (prevBtn) prevBtn.disabled = currentPage <= 1;
            if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
            if (onUpdate) onUpdate(matched.length);
        }

        if (prevBtn) prevBtn.addEventListener('click', () => { currentPage--; update(false); });
        if (nextBtn) nextBtn.addEventListener('click', () => { currentPage++; update(false); });

        return { update };
    }

    window.makeTablePager = makeTablePager;
})();
