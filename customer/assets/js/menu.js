/**
 * customer/assets/js/menu.js
 *
 * Category filter pills + search box -- pure DOM show/hide via the native
 * hidden attribute against a single flat grid of cards (all items are
 * already in the DOM from the server render; no fetch on filter/search),
 * paired with an explicit .ca-menu-card[hidden]{display:none} rule in
 * menu.css (an element's own display rule silently defeats the browser's
 * default [hidden] behavior otherwise).
 *
 * The "Describe your preference" AI filter is deliberately NOT on this page --
 * it belongs to the advance-order step only (assets/js/make-reservation.js).
 */

(function () {
    'use strict';

    const chips = document.querySelectorAll('[data-filter-category]');
    const cards = document.querySelectorAll('[data-item-category]');
    const searchInput = document.getElementById('menuSearch');
    const noMatch = document.getElementById('menuNoMatch');
    let activeCategory = 'all';
    let searchQuery = '';

    function applyFilters() {
        let visibleCount = 0;
        cards.forEach((card) => {
            const matchesCategory = activeCategory === 'all' || card.getAttribute('data-item-category') === activeCategory;
            const matchesSearch = !searchQuery || card.getAttribute('data-item-name').includes(searchQuery);
            const visible = matchesCategory && matchesSearch;
            card.hidden = !visible;
            if (visible) visibleCount++;
        });
        if (noMatch) noMatch.hidden = visibleCount !== 0;
    }

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chips.forEach((c) => c.classList.remove('is-active'));
            chip.classList.add('is-active');
            activeCategory = chip.getAttribute('data-filter-category');
            applyFilters();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            searchQuery = searchInput.value.trim().toLowerCase();
            applyFilters();
        });
    }
})();
