/**
 * Shared confirm modal (customer bottom-sheet skin) -- replaces native confirm().
 * Same window.confirmAction(message, opts) API and data-confirm auto-wiring
 * as owner/assets/js/confirm-modal.js, just skinned with .ca-sheet-backdrop /
 * .ca-item-sheet to match this module's own design system instead.
 */
(function () {
    var backdrop = null;

    function build() {
        if (backdrop) return backdrop;
        backdrop = document.createElement('div');
        backdrop.className = 'ca-sheet-backdrop is-confirm';
        backdrop.style.zIndex = '500';
        backdrop.innerHTML =
            // No close X: Cancel already says exactly what dismissing does, and
            // the backdrop and Escape both still dismiss. A second, wordless way
            // out sitting above an explicit Cancel button only adds ambiguity
            // about whether the two do the same thing.
            '<div class="ca-item-sheet" role="alertdialog" aria-modal="true" aria-labelledby="opConfirmTitle">' +
                '<div class="ca-item-sheet-body">' +
                    '<h3 class="ca-item-sheet-name" id="opConfirmTitle">Please confirm</h3>' +
                    '<p class="ca-item-sheet-desc" id="opConfirmMessage" style="margin-bottom:18px;"></p>' +
                    '<div class="ca-item-sheet-footer">' +
                        '<button type="button" class="ca-btn ca-btn-secondary" id="opConfirmCancel">Cancel</button>' +
                        '<button type="button" class="ca-btn ca-btn-primary" id="opConfirmOk">Confirm</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(backdrop);
        return backdrop;
    }

    window.confirmAction = function (message, opts) {
        opts = opts || {};
        var el = build();
        var titleEl = el.querySelector('#opConfirmTitle');
        var msgEl = el.querySelector('#opConfirmMessage');
        var okBtn = el.querySelector('#opConfirmOk');
        var cancelBtn = el.querySelector('#opConfirmCancel');

        titleEl.textContent = opts.title || 'Please confirm';
        msgEl.textContent = message;
        okBtn.textContent = opts.confirmText || (opts.alert ? 'Got it' : 'Confirm');
        cancelBtn.textContent = opts.cancelText || 'Cancel';

        // alert:true -- one button, nothing to decline. A confirmation asks
        // "shall I?"; an alert says "here is why that didn't work", and a
        // Cancel button on the second one offers a choice that does not exist.
        // The promise still resolves, so callers can await it either way.
        //
        // display is set explicitly, not just the hidden attribute: .ca-btn (and
        // .owner-btn) declare display:inline-flex, and an element's own display
        // rule beats the browser's [hidden]{display:none}. Setting .hidden alone
        // left the button fully visible. The attribute is kept as well, so
        // assistive tech skips it rather than announcing a button nobody can see.
        cancelBtn.hidden = !!opts.alert;
        cancelBtn.style.display = opts.alert ? 'none' : '';

        return new Promise(function (resolve) {
            function cleanup(result) {
                el.classList.remove('is-open');
                document.body.classList.remove('ca-modal-open');
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                el.removeEventListener('click', onBackdropClick);
                document.removeEventListener('keydown', onKeydown);
                resolve(result);
            }
            function onOk() { cleanup(true); }
            function onCancel() { cleanup(false); }
            function onBackdropClick(e) { if (e.target === el) cleanup(false); }
            function onKeydown(e) { if (e.key === 'Escape') cleanup(false); }

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            el.addEventListener('click', onBackdropClick);
            document.addEventListener('keydown', onKeydown);

            el.classList.add('is-open');
            document.body.classList.add('ca-modal-open');
            okBtn.focus();
        });
    };

    document.addEventListener('submit', function (e) {
        var form = e.target.closest ? e.target.closest('form[data-confirm]') : null;
        if (!form) return;
        e.preventDefault();

        window.confirmAction(form.getAttribute('data-confirm'), {
            title: form.getAttribute('data-confirm-title') || undefined,
            confirmText: form.getAttribute('data-confirm-ok') || undefined,
        }).then(function (ok) {
            if (ok) form.submit();
        });
    }, true);
})();
