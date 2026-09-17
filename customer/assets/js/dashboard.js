/**
 * customer/assets/js/dashboard.js
 *
 * Keeps the hero card's countdown ("Starts in 2h 18m" / "Tomorrow" /
 * "In 3 Days") live without a page reload. Mirrors formatCountdown() in
 * dashboard.php exactly -- calendar-day difference first, hour/minute
 * countdown only for same-day -- so the server-rendered first paint and
 * the JS-updated text never disagree.
 */

(function () {
    'use strict';

    const el = document.getElementById('heroCountdown');
    if (!el) return;

    const textEl = el.querySelector('[data-countdown-text]');
    const targetDate = el.dataset.date; // 'YYYY-MM-DD'

    function daysUntil(dateStr) {
        const [y, m, d] = dateStr.split('-').map(Number);
        const target = new Date(y, m - 1, d);
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        return Math.round((target - today) / 86400000);
    }

    function render() {
        const days = daysUntil(targetDate);

        if (days > 1) {
            textEl.textContent = `In ${days} Days`;
            return;
        }
        if (days === 1) {
            textEl.textContent = 'Tomorrow';
            return;
        }
        if (days < 0) {
            el.hidden = true;
            return;
        }

        const target = new Date(el.dataset.target);
        const diffMin = Math.floor((target - new Date()) / 60000);
        if (diffMin <= 0) {
            el.hidden = true;
            return;
        }
        const h = Math.floor(diffMin / 60);
        const m = diffMin % 60;
        textEl.textContent = 'Starts in ' + (h > 0 ? `${h}h ${m}m` : `${m}m`);
    }

    render();
    setInterval(render, 30000);
})();
