/**
 * customer/assets/js/reservation-details.js
 *
 * "Continue Payment" — asks reservation_resume_payment.php for a fresh
 * PayMongo Checkout Session tied to this same pending reservation, then
 * redirects the browser there. Sent as application/x-www-form-urlencoded
 * (not JSON), matching every other reservation AJAX endpoint's CSRF
 * handling in this app.
 */

(function () {
    'use strict';

    const btn = document.getElementById('continuePaymentBtn');
    if (!btn) return;

    const overlay = document.getElementById('loadingOverlay');

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        overlay.classList.add('is-open');

        try {
            const res = await fetch('api/reservation_resume_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: window.CSRF_TOKEN,
                    reservation_number: window.RESERVATION_NUMBER,
                }),
            });
            const data = await res.json();

            if (!res.ok || !data.checkout_url) {
                throw new Error(data.error || 'Something went wrong. Please try again.');
            }

            window.location.href = data.checkout_url;
        } catch (e) {
            overlay.classList.remove('is-open');
            btn.disabled = false;
            alert(e.message);
        }
    });
})();
