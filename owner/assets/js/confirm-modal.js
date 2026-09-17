/**
 * Shared confirm modal (owner-panel skin) -- replaces native confirm().
 * Loaded on every staff-side page (owner/manager/admin/employee_management/inventory/
 * purchase_orders/reservation) that needs a confirm dialog.
 *
 * Usage:
 *   1. Add data-confirm="message" (and optionally data-confirm-danger="false",
 *      data-confirm-title="...", data-confirm-ok="...") to a <form>. Its
 *      submit is intercepted automatically -- no other JS needed.
 *   2. For messages only known at click-time (built from live JS state),
 *      call window.confirmAction(message, opts) directly and await it.
 */
(function () {
    var backdrop = null;

    function build() {
        if (backdrop) return backdrop;
        backdrop = document.createElement('div');
        backdrop.className = 'owner-modal-backdrop';
        backdrop.style.zIndex = '200';
        backdrop.innerHTML =
            '<div class="owner-modal owner-modal-sm" role="alertdialog" aria-modal="true" aria-labelledby="opConfirmTitle">' +
                '<div class="owner-modal-header">' +
                    '<h2 class="owner-modal-title" id="opConfirmTitle">Please confirm</h2>' +
                    '<button type="button" class="owner-modal-close" id="opConfirmClose" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>' +
                '</div>' +
                '<div class="owner-modal-body"><p id="opConfirmMessage" style="margin:0;color:var(--op-ink);line-height:1.5;"></p></div>' +
                '<div class="owner-modal-footer">' +
                    '<button type="button" class="owner-btn owner-btn-secondary" id="opConfirmCancel">Cancel</button>' +
                    '<button type="button" class="owner-btn owner-btn-danger" id="opConfirmOk">Confirm</button>' +
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
        var closeBtn = el.querySelector('#opConfirmClose');

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
        okBtn.className = 'owner-btn ' + (opts.danger === false ? 'owner-btn-primary' : 'owner-btn-danger');

        return new Promise(function (resolve) {
            function cleanup(result) {
                el.classList.remove('is-open');
                document.body.classList.remove('owner-modal-open');
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                closeBtn.removeEventListener('click', onCancel);
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
            closeBtn.addEventListener('click', onCancel);
            el.addEventListener('click', onBackdropClick);
            document.addEventListener('keydown', onKeydown);

            el.classList.add('is-open');
            document.body.classList.add('owner-modal-open');
            okBtn.focus();
        });
    };

    document.addEventListener('submit', function (e) {
        var form = e.target.closest ? e.target.closest('form[data-confirm]') : null;
        if (!form) return;
        e.preventDefault();

        var dangerAttr = form.getAttribute('data-confirm-danger');
        var danger = dangerAttr === null ? !!form.querySelector('.owner-btn-danger') : dangerAttr !== 'false';

        window.confirmAction(form.getAttribute('data-confirm'), {
            title: form.getAttribute('data-confirm-title') || undefined,
            confirmText: form.getAttribute('data-confirm-ok') || undefined,
            danger: danger,
        }).then(function (ok) {
            if (ok) form.submit();
        });
    }, true);
})();
