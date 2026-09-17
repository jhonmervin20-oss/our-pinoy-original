/**
 * customer/assets/js/dashboard-calendar.js
 *
 * Renders the "Reservations Calendar" sidebar widget on dashboard.php --
 * a month grid built entirely client-side from window.CUSTOMER_RESERVATIONS_BY_DATE
 * (set in dashboard.php, keyed 'YYYY-MM-DD') so prev/next month navigation
 * doesn't need a page reload or a round trip. Days with a reservation get a
 * dot; clicking a day renders that date's reservation(s) below the grid.
 */

(function () {
    'use strict';

    const grid = document.getElementById('calGrid');
    if (!grid) return;

    const monthLabel = document.getElementById('calMonthLabel');
    const detail = document.getElementById('calDetail');
    const prevBtn = document.getElementById('calPrevBtn');
    const nextBtn = document.getElementById('calNextBtn');
    const byDate = window.CUSTOMER_RESERVATIONS_BY_DATE || {};

    const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    const today = new Date();
    const todayStr = toDateStr(today);

    let viewYear = today.getFullYear();
    let viewMonth = today.getMonth(); // 0-11
    let selectedDate = byDate[todayStr] ? todayStr : null;

    function toDateStr(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function renderGrid() {
        monthLabel.textContent = MONTH_NAMES[viewMonth] + ' ' + viewYear;
        grid.innerHTML = '';

        const firstDow = new Date(viewYear, viewMonth, 1).getDay();
        const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

        for (let i = 0; i < firstDow; i++) {
            grid.appendChild(document.createElement('span'));
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = viewYear + '-' + String(viewMonth + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
            const hasReservation = !!byDate[dateStr];

            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'ca-calendar-day';
            if (dateStr === todayStr) cell.classList.add('is-today');
            if (dateStr === selectedDate) cell.classList.add('is-selected');
            if (hasReservation) cell.classList.add('has-reservation');

            cell.innerHTML = '<span class="ca-calendar-day-num">' + day + '</span>' + (hasReservation ? '<span class="ca-calendar-day-dot"></span>' : '');
            cell.addEventListener('click', () => {
                selectedDate = dateStr;
                renderGrid();
                renderDetail();
            });

            grid.appendChild(cell);
        }
    }

    function renderDetail() {
        if (!selectedDate) {
            detail.innerHTML = '<p class="ca-calendar-empty">Select a day to see reservation details.</p>';
            return;
        }

        const [y, m, d] = selectedDate.split('-').map(Number);
        const label = WEEKDAY_NAMES[new Date(y, m - 1, d).getDay()] + ', ' + MONTH_NAMES[m - 1] + ' ' + d;
        const items = byDate[selectedDate] || [];

        if (items.length === 0) {
            detail.innerHTML = '<div class="ca-calendar-detail-date">' + label + '</div><p class="ca-calendar-empty">No reservations this day.</p>';
            return;
        }

        const rows = items.map((it) => (
            '<a class="ca-calendar-detail-row" href="reservation_details.php?reservation_number=' + encodeURIComponent(it.number) + '">' +
                '<div class="ca-calendar-detail-time"><i class="ph ph-clock" aria-hidden="true"></i> ' + it.time + '</div>' +
                '<div class="ca-calendar-detail-meta">' +
                    '<span class="ca-cal-status-pill ' + it.class + '">' + it.label + '</span>' +
                    ' &middot; ' + it.guests + ' guest' + (it.guests === 1 ? '' : 's') +
                '</div>' +
            '</a>'
        )).join('');

        detail.innerHTML = '<div class="ca-calendar-detail-date">' + label + '</div>' + rows;
    }

    prevBtn.addEventListener('click', () => {
        viewMonth -= 1;
        if (viewMonth < 0) { viewMonth = 11; viewYear -= 1; }
        renderGrid();
    });

    nextBtn.addEventListener('click', () => {
        viewMonth += 1;
        if (viewMonth > 11) { viewMonth = 0; viewYear += 1; }
        renderGrid();
    });

    renderGrid();
    renderDetail();
})();
