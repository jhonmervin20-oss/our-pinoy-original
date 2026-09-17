/**
 * owner/assets/js/searchable-select.js
 *
 * Turns `<select data-searchable>` into a type-to-filter combobox. Built for the
 * employee pickers on the Employee Management forms, where a plain dropdown
 * means scrolling a long alphabetical list to find one person.
 *
 * Three deliberate constraints:
 *
 * 1. The native <select> STAYS in the DOM, keeps its name, and remains the thing
 *    that is submitted. Nothing server-side changes, and existing page JS that
 *    does `sel.value = emp.employee_id` when opening an edit modal keeps working.
 * 2. Because that page JS assigns .value directly -- which fires no event -- the
 *    instance's `value` and `selectedIndex` accessors are wrapped so the visible
 *    text follows a programmatic change. Without that, opening Edit would load
 *    the right employee into the form while the box still showed the last one.
 * 3. If anything here throws, the native select is left visible and usable. A
 *    broken enhancement must never leave a required field unpickable.
 *
 * No external library: the panel loads no combobox dependency, and its styles
 * are injected from here rather than added to owner-panel.css, which exists as
 * five separate un-synced copies across module folders.
 */
(function () {
    'use strict';

    var STYLE_ID = 'ss-styles';

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var css = [
            '.ss-wrap{position:relative;}',
            '.ss-wrap select[data-searchable]{position:absolute;opacity:0;pointer-events:none;height:0;width:0;}',
            '.ss-input{width:100%;box-sizing:border-box;padding:10px 32px 10px 12px;border:1px solid var(--op-border,#ece6d8);',
            // Stroked chevron, not a filled triangle: the panel's icon set is thin-line
            // Phosphor and a solid caret reads as a different, heavier component.
            '  border-radius:var(--op-radius-sm,8px);background:var(--op-surface,#fff) url("data:image/svg+xml;charset=utf8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 16 16\' fill=\'none\' stroke=\'%23a39a8c\' stroke-width=\'1.6\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Cpath d=\'M4 6.5l4 4 4-4\'/%3E%3C/svg%3E") no-repeat right 11px center;',
            '  background-size:15px 15px;',
            '  font-family:inherit;font-size:0.88rem;color:var(--op-ink,#241f1a);}',
            '.ss-input::placeholder{color:var(--op-ink-faint,#a39a8c);}',
            '.ss-input:focus{outline:none;border-color:var(--op-gold,#9c7734);box-shadow:0 0 0 3px var(--op-gold-soft,rgba(156,119,52,0.08));}',
            '.ss-wrap.is-invalid .ss-input{border-color:var(--op-danger,#cf5a44);}',
            '.ss-list{position:absolute;z-index:60;left:0;right:0;top:calc(100% + 4px);max-height:230px;overflow-y:auto;',
            '  margin:0;padding:4px;list-style:none;background:var(--op-surface,#fff);border:1px solid var(--op-border,#ece6d8);',
            '  border-radius:var(--op-radius-sm,8px);box-shadow:0 16px 40px -20px rgba(30,24,16,0.35);}',
            '.ss-list[hidden]{display:none;}',
            '.ss-opt{padding:8px 10px;border-radius:6px;font-size:0.85rem;color:var(--op-ink,#241f1a);cursor:pointer;}',
            '.ss-opt.is-active,.ss-opt:hover{background:var(--op-gold-soft,rgba(156,119,52,0.08));color:var(--op-gold,#9c7734);}',
            '.ss-opt.is-placeholder{color:var(--op-ink-faint,#a39a8c);}',
            '.ss-empty{padding:10px;font-size:0.82rem;color:var(--op-ink-faint,#a39a8c);}'
        ].join('');
        var el = document.createElement('style');
        el.id = STYLE_ID;
        el.textContent = css;
        document.head.appendChild(el);
    }

    function enhance(sel) {
        if (sel.dataset.ssReady === '1') return;

        var wrap = document.createElement('div');
        wrap.className = 'ss-wrap';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'ss-input';
        input.autocomplete = 'off';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.placeholder = sel.getAttribute('data-search-placeholder') || 'Search…';

        var list = document.createElement('ul');
        list.className = 'ss-list';
        list.hidden = true;
        list.setAttribute('role', 'listbox');

        wrap.appendChild(input);
        wrap.appendChild(list);

        var activeIndex = -1;
        var visible = [];

        function selectedText() {
            var o = sel.options[sel.selectedIndex];
            return o ? o.textContent.trim() : '';
        }

        function syncDisplay() {
            input.value = selectedText();
        }

        function render(query) {
            var q = (query || '').trim().toLowerCase();
            list.innerHTML = '';
            visible = [];

            Array.prototype.forEach.call(sel.options, function (opt, i) {
                var text = opt.textContent.trim();
                // Hide the empty "Select an employee" row once the user is
                // searching -- it is not a result, and it happens to contain
                // most single letters, so it matched almost every query.
                if (q && opt.value === '') return;
                if (q && text.toLowerCase().indexOf(q) === -1) return;
                var li = document.createElement('li');
                li.className = 'ss-opt' + (opt.value === '' ? ' is-placeholder' : '');
                li.textContent = text;
                li.setAttribute('role', 'option');
                li.dataset.index = String(i);
                // mousedown, not click: blur fires first on click and would close
                // the list before the selection ever landed.
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    commit(i);
                });
                list.appendChild(li);
                visible.push(li);
            });

            if (!visible.length) {
                var empty = document.createElement('li');
                empty.className = 'ss-empty';
                empty.textContent = 'No matches';
                list.appendChild(empty);
            }
            activeIndex = -1;
        }

        function open() {
            render('');
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            input.select();
        }

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            syncDisplay();
        }

        function commit(optionIndex) {
            sel.selectedIndex = optionIndex;
            // The page's own handlers listen for change on the real select.
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            close();
        }

        function highlight(delta) {
            if (!visible.length) return;
            activeIndex += delta;
            if (activeIndex < 0) activeIndex = visible.length - 1;
            if (activeIndex >= visible.length) activeIndex = 0;
            visible.forEach(function (li, i) { li.classList.toggle('is-active', i === activeIndex); });
            visible[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        input.addEventListener('focus', open);
        input.addEventListener('input', function () {
            render(input.value);
            list.hidden = false;
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown')      { e.preventDefault(); if (list.hidden) open(); highlight(1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); highlight(-1); }
            else if (e.key === 'Enter')     {
                if (!list.hidden && activeIndex >= 0) {
                    e.preventDefault();
                    commit(parseInt(visible[activeIndex].dataset.index, 10));
                }
            }
            else if (e.key === 'Escape')    { close(); }
        });
        input.addEventListener('blur', function () { setTimeout(close, 0); });

        // A required <select> that is visually hidden cannot show the browser's
        // native validation bubble, so mirror the invalid state onto the box the
        // user can actually see.
        sel.addEventListener('invalid', function () {
            wrap.classList.add('is-invalid');
            input.focus();
        });
        sel.addEventListener('change', function () {
            wrap.classList.remove('is-invalid');
            syncDisplay();
        });

        // Catch `sel.value = x` / `sel.selectedIndex = n` from the pages' own
        // modal-populating code, which fires no event.
        ['value', 'selectedIndex'].forEach(function (prop) {
            var desc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
            if (!desc || !desc.set) return;
            Object.defineProperty(sel, prop, {
                configurable: true,
                enumerable: true,
                get: function () { return desc.get.call(this); },
                set: function (v) { desc.set.call(this, v); syncDisplay(); }
            });
        });

        sel.dataset.ssReady = '1';
        syncDisplay();
    }

    function init() {
        var targets = document.querySelectorAll('select[data-searchable]');
        if (!targets.length) return;
        injectStyles();
        Array.prototype.forEach.call(targets, function (sel) {
            try {
                enhance(sel);
            } catch (e) {
                // Leave this select exactly as the browser rendered it.
                if (window.console) console.error('searchable-select failed for #' + sel.id, e);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
