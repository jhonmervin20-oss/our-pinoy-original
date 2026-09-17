// auth-interactions.js
// Shared behavior for login / sign up / forgot password / reset password.

document.addEventListener('DOMContentLoaded', () => {

    // ------------------------------------------------------------
    // 2. Live email format validation
    //    Add data-email-check to an <input>, and a sibling
    //    <div class="validation-hint"></div> inside the same .form-group.
    // ------------------------------------------------------------
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    document.querySelectorAll('[data-email-check]').forEach((input) => {
        const group = input.closest('.form-group');
        const hint = group ? group.querySelector('.validation-hint') : null;
        if (!hint) return;

        input.addEventListener('input', () => {
            const val = input.value.trim();

            if (!val) {
                hint.textContent = '';
                hint.className = 'validation-hint';
                input.classList.remove('email-valid', 'email-invalid');
                return;
            }

            if (emailPattern.test(val)) {
                hint.textContent = 'Looks like a valid email.';
                hint.className = 'validation-hint valid';
                input.classList.add('email-valid');
                input.classList.remove('email-invalid');
            } else {
                hint.textContent = 'Enter a valid email address.';
                hint.className = 'validation-hint invalid';
                input.classList.add('email-invalid');
                input.classList.remove('email-valid');
            }
        });
    });

    // ------------------------------------------------------------
    // 3. Password strength meter
    //    Add data-pw-strength to a password <input>. Its .form-group
    //    should also contain .pw-strength > .pw-bar (x3) and .pw-hint.
    // ------------------------------------------------------------
    function scorePassword(pw) {
        let score = 0;
        if (pw.length >= 8) score++;
        if (pw.length >= 12) score++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
        if (/\d/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        return score; // 0 - 5
    }

    document.querySelectorAll('[data-pw-strength]').forEach((input) => {
        const group = input.closest('.form-group');
        if (!group) return;
        const bars = group.querySelectorAll('.pw-bar');
        const hint = group.querySelector('.pw-hint');

        input.addEventListener('input', () => {
            const val = input.value;

            if (!val) {
                bars.forEach((b) => (b.className = 'pw-bar'));
                if (hint) hint.textContent = '';
                return;
            }

            const score = scorePassword(val);
            let level = 'weak';
            let label = 'Weak — try a longer password.';
            let litBars = 1;

            if (score >= 4) {
                level = 'strong';
                label = 'Strong password.';
                litBars = 3;
            } else if (score >= 2) {
                level = 'fair';
                label = 'Could be stronger — add numbers or symbols.';
                litBars = 2;
            }

            bars.forEach((b, i) => {
                b.className = 'pw-bar' + (i < litBars ? ' ' + level : '');
            });
            if (hint) hint.textContent = label;
        });
    });

    // ------------------------------------------------------------
    // 4. Submit loading state — prevents double-submits and gives
    //    feedback that the request is in flight.
    // ------------------------------------------------------------
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"].btn-auth');
            if (btn) {
                btn.classList.add('is-loading');
                btn.disabled = true;
            }
        });
    });

});