/**
 * customer/assets/js/eticket-download.js
 *
 * Shared "Download" wiring for the e-ticket card, used by both
 * reservation_confirm.php and reservation_details.php. Snapshots
 * #eTicketCard to a PNG via html2canvas (CDN, no build step — matches this
 * app's existing QRious/Phosphor CDN-script pattern) and triggers a real
 * browser download, since no server-side PDF/image library exists anywhere
 * in this codebase to generate one for us.
 */

(function () {
    'use strict';

    const btn = document.querySelector('[data-download-ticket]');
    const card = document.getElementById('eTicketCard');
    if (!btn || !card) return;

    btn.addEventListener('click', async () => {
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="ph ph-spinner" aria-hidden="true"></i> Preparing&hellip;';

        try {
            const canvas = await html2canvas(card, {
                backgroundColor: getComputedStyle(document.body).backgroundColor,
                scale: 2,
            });

            canvas.toBlob((blob) => {
                if (!blob) throw new Error('Could not generate image.');
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = btn.getAttribute('data-filename') || 'reservation-ticket.png';
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
            }, 'image/png');
        } catch (e) {
            alert('Could not download the ticket. Please try again.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    });
})();
