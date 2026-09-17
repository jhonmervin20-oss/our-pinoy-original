/**
 * customer/assets/js/reservations.js
 *
 * Status-tab filtering for the reservation list — pure DOM show/hide, no
 * re-fetch. Each card is a plain link to reservation_details.php now, so
 * there's no modal/JSON payload to manage here.
 */

(function () {
    'use strict';

    const tabs  = document.querySelectorAll('[data-res-tab]');
    const cards = document.querySelectorAll('.ca-res-card[data-bucket]');
    const emptyFiltered = document.getElementById('resEmptyFiltered');

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            tabs.forEach((t) => t.classList.remove('is-active'));
            tab.classList.add('is-active');

            const bucket = tab.getAttribute('data-res-tab');
            let visibleCount = 0;
            cards.forEach((card) => {
                const show = bucket === 'all' || card.getAttribute('data-bucket') === bucket;
                card.hidden = !show;
                if (show) visibleCount++;
            });
            if (emptyFiltered) emptyFiltered.hidden = visibleCount !== 0;
        });
    });

    // Deep-link support for dashboard.php's status tiles (?status=pending
    // etc.) -- click the matching tab programmatically so it gets the same
    // filtering + active-state handling as a real click, instead of
    // duplicating that logic here.
    const requestedStatus = new URLSearchParams(window.location.search).get('status');
    if (requestedStatus) {
        const match = document.querySelector(`[data-res-tab="${requestedStatus}"]`);
        if (match) match.click();
    }
})();
