/*
 * Construction CMS UI helpers (no build step, served from public/ so the CSP 'self' rule allows it).
 *  - Sidebar:      collapsible groups (state remembered per browser), mobile open/close.
 *  - Modals:       <a data-modal href="/users/create"> loads that page's form into the modal.
 *                  <button data-modal-target="#tpl-id"> copies a <template id="tpl-id"> into the modal.
 *                  Forms submit normally. A hidden _modal field lets the next page reopen the modal
 *                  with the submitted values and errors when validation fails.
 *  - Confirm:      <button data-confirm="Message"> asks before submitting (delete, cancel, reject).
 *  - Lines:        <button data-add-line="#table"> clones the last row of a line-item table.
 *  - Auto-submit:  <select data-autosubmit> submits its form on change (store pickers).
 *  - Passwords:    every password field gets a show/hide (eye) button.
 *  - Messages:     flash messages fade out after 5 seconds (paused while the mouse is over them).
 *  - Placeholders: fields without a placeholder get one from their label.
 */
(function () {
    'use strict';

    var store = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } }
    };

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (html !== undefined) { n.innerHTML = html; }
        return n;
    }

    /* ---------- Sidebar ---------- */
    function setGroup(group, open) {
        group.classList.toggle('open', open);
        var btn = group.querySelector('.nav-group-toggle');
        if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    function initSidebar() {
        document.querySelectorAll('[data-nav-group]').forEach(function (group) {
            var key = 'nav:' + group.dataset.navGroup;
            if (!group.classList.contains('has-active')) {
                var saved = store.get(key);
                if (saved !== null) { setGroup(group, saved === '1'); }
            }
            group.querySelector('.nav-group-toggle').addEventListener('click', function () {
                var open = !group.classList.contains('open');
                setGroup(group, open);
                store.set(key, open ? '1' : '0');
            });
        });
        document.querySelectorAll('[data-nav-toggle]').forEach(function (b) {
            b.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
        });
        document.querySelectorAll('[data-nav-close]').forEach(function (b) {
            b.addEventListener('click', function () { document.body.classList.remove('nav-open'); });
        });
    }

    /* ---------- Field enhancements (run on the page and on modal content) ---------- */
    function labelFor(f) {
        var text = '';
        if (f.id) {
            var l = document.querySelector('label[for="' + f.id + '"]');
            if (l) { text = l.textContent; }
        }
        if (!text) {
            var wrap = f.closest('.field, .grid > div, .form-grid > div');
            var l2 = wrap && wrap.querySelector('label:not(.check)');
            if (l2 && !l2.contains(f)) { text = l2.textContent; }
        }
        if (!text && f.getAttribute('aria-label')) { text = f.getAttribute('aria-label'); }
        if (!text) {
            var cell = f.closest('td');
            var table = f.closest('table');
            if (cell && table) {
                var th = table.querySelectorAll('thead th')[cell.cellIndex];
                if (th) { text = th.textContent; }
            }
        }
        return text.replace(/\s+/g, ' ').replace(/\(optional\)/i, '').trim();
    }

    function addPlaceholders(root) {
        root.querySelectorAll('input:not([placeholder]), textarea:not([placeholder])').forEach(function (f) {
            var type = (f.getAttribute('type') || 'text').toLowerCase();
            if (['hidden', 'checkbox', 'radio', 'file', 'date', 'submit', 'button', 'color', 'range'].indexOf(type) !== -1) { return; }
            if (type === 'email') { f.placeholder = 'name@example.com'; return; }
            if (type === 'password') { f.placeholder = 'Enter password'; return; }
            if (type === 'number') {
                var min = f.getAttribute('min');
                f.placeholder = min !== null && min !== '' ? 'e.g. ' + (parseFloat(min) > 0 ? min : '0') : 'e.g. 0';
                return;
            }
            var label = labelFor(f);
            if (label) { f.placeholder = 'Enter ' + label.charAt(0).toLowerCase() + label.slice(1); }
        });
    }

    function addPasswordToggles(root) {
        root.querySelectorAll('input[type=password]:not([data-pw-ready])').forEach(function (f) {
            f.setAttribute('data-pw-ready', '1');
            var wrap = el('span', 'password-wrap');
            f.parentNode.insertBefore(wrap, f);
            wrap.appendChild(f);
            var btn = el('button', 'password-toggle', '<i class="fas fa-eye"></i>');
            btn.type = 'button';
            btn.setAttribute('aria-label', 'Show password');
            btn.title = 'Show password';
            btn.addEventListener('click', function () {
                var show = f.type === 'password';
                f.type = show ? 'text' : 'password';
                btn.innerHTML = show ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.title = show ? 'Hide password' : 'Show password';
                f.focus();
            });
            wrap.appendChild(btn);
        });
    }

    function enhance(root) {
        addPasswordToggles(root);
        addPlaceholders(root);
    }

    /* ---------- Flash messages ---------- */
    function initFlash() {
        document.querySelectorAll('[data-flash]').forEach(function (box) {
            var close = el('button', 'alert-close', '<i class="fas fa-xmark"></i>');
            close.type = 'button';
            close.setAttribute('aria-label', 'Dismiss message');
            box.appendChild(close);
            var timer = null;
            function dismiss() {
                box.classList.add('fade-out');
                setTimeout(function () { box.remove(); }, 600);
            }
            function start() { timer = setTimeout(dismiss, 5000); }
            close.addEventListener('click', dismiss);
            box.addEventListener('mouseenter', function () { clearTimeout(timer); });
            box.addEventListener('mouseleave', start);
            start();
        });
    }

    /* ---------- Line-item tables ---------- */
    function addLine(table) {
        var tbody = table.tBodies[0];
        var last = tbody.rows[tbody.rows.length - 1];
        var next = tbody.rows.length;
        var row = last.cloneNode(true);
        row.querySelectorAll('input, select, textarea').forEach(function (f) {
            if (f.name) { f.name = f.name.replace(/\[\d+\]/, '[' + next + ']'); }
            if (f.tagName === 'SELECT') { f.selectedIndex = 0; } else if (f.type !== 'hidden') { f.value = ''; }
        });
        row.querySelectorAll('.err').forEach(function (e) { e.remove(); });
        tbody.appendChild(row);
        return row;
    }

    function initLines() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-add-line]');
            if (!btn) { return; }
            e.preventDefault();
            var scope = btn.closest('.modal-body') || document;
            var table = scope.querySelector(btn.dataset.addLine);
            if (!table) { return; }
            var row = addLine(table);
            enhance(row);
            var first = row.querySelector('select, input:not([type=hidden])');
            if (first) { first.focus(); }
        });
        document.addEventListener('change', function (e) {
            var f = e.target;
            if (f.matches && f.matches('[data-autosubmit]') && f.form) {
                if (f.form.requestSubmit) { f.form.requestSubmit(); } else { f.form.submit(); }
            }
        });
    }

    /* ---------- Modal core ---------- */
    var lastFocus = null;

    function openModal(modal) {
        if (modal.hidden) { lastFocus = document.activeElement; }
        modal.hidden = false;
        document.body.classList.add('modal-open');
        var first = modal.querySelector('.modal-body input:not([type=hidden]):not([disabled]), .modal-body select:not([disabled]), .modal-body textarea, [data-confirm-ok]');
        (first || modal.querySelector('.modal-close')).focus();
    }

    function closeModal(modal) {
        modal.hidden = true;
        if (!document.querySelector('.modal:not([hidden])')) { document.body.classList.remove('modal-open'); }
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    function postForm(root) {
        return Array.prototype.find.call(root.querySelectorAll('form'), function (f) { return f.method === 'post'; });
    }

    /** Prepare the form for the modal: tag it, turn Cancel links into close buttons, move submit buttons to a footer. */
    function adoptForm(form, target) {
        form.classList.remove('card');
        form.style.maxWidth = '';
        var marker = document.createElement('input');
        marker.type = 'hidden';
        marker.name = '_modal';
        marker.value = target;
        form.appendChild(marker);

        form.querySelectorAll('a.btn.secondary').forEach(function (a) {
            if (/cancel/i.test(a.textContent)) { a.remove(); }
        });
        var submits = Array.prototype.slice.call(form.querySelectorAll('button:not([type=button])')).filter(function (b) {
            return !b.closest('table') && !b.closest('.modal-keep');
        });
        var foot = el('div', 'modal-foot');
        var cancel = el('button', 'btn secondary', '<i class="fas fa-xmark"></i> Cancel');
        cancel.type = 'button';
        cancel.setAttribute('data-modal-close', '');
        foot.appendChild(cancel);
        submits.forEach(function (b) {
            if (!b.querySelector('i')) { b.insertAdjacentHTML('afterbegin', '<i class="fas fa-floppy-disk"></i> '); }
            foot.appendChild(b);
        });
        form.querySelectorAll('.actions, p').forEach(function (n) { if (!n.textContent.trim() && !n.children.length) { n.remove(); } });
        form.appendChild(foot);
    }

    function showInModal(title, nodes, target) {
        var modal = document.getElementById('app-modal');
        var body = modal.querySelector('.modal-body');
        modal.querySelector('#app-modal-title').textContent = title;
        body.innerHTML = '';
        nodes.forEach(function (n) { body.appendChild(n); });
        var form = postForm(body);
        if (form) { adoptForm(form, target); }
        var dialog = modal.querySelector('.modal-dialog');
        var wide = !!body.querySelector('table');
        dialog.classList.toggle('modal-xl', wide);
        dialog.classList.toggle('modal-lg', !wide && !!body.querySelector('[data-modal-wide], textarea, fieldset, .form-grid .field:nth-child(7)'));
        enhance(body);
        openModal(modal);
        return form;
    }

    /** Load a page and show its form. Falls back to normal navigation on any problem. */
    function loadPageIntoModal(url, restore) {
        var modal = document.getElementById('app-modal');
        modal.classList.add('loading');
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok || res.redirected) { throw new Error('fallback'); }
                return res.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var content = doc.querySelector('.content');
                if (!content || !postForm(content)) { throw new Error('fallback'); }
                var h1 = content.querySelector('.page-head h1');
                var nodes = [];
                var intro = content.querySelector('.page-head p');
                if (intro) { nodes.push(el('p', 'muted modal-intro', intro.innerHTML)); }
                Array.prototype.forEach.call(content.children, function (child) {
                    if (child.classList.contains('page-head') || child.classList.contains('stats-row')) { return; }
                    if (child.matches('[data-flash], script')) { return; }
                    nodes.push(document.importNode(child, true));
                });
                var form = showInModal(h1 ? h1.textContent.trim() : document.title, nodes, url);
                if (restore && form) { restoreForm(form, restore); }
            })
            .catch(function () { window.location.href = url; })
            .then(function () { modal.classList.remove('loading'); });
    }

    function openTemplate(selector, restore) {
        var tpl = document.querySelector(selector);
        if (!tpl) { return; }
        var frag = tpl.content ? document.importNode(tpl.content, true) : tpl.cloneNode(true);
        var nodes = Array.prototype.slice.call(frag.childNodes).filter(function (n) { return n.nodeType === 1; });
        var form = showInModal(tpl.dataset.title || '', nodes, selector);
        if (restore && form) { restoreForm(form, restore); }
    }

    /* ---------- Reopen after a failed submit ---------- */
    function oldValue(old, name) {
        var path = name.replace(/\]/g, '').split('[');
        var v = old;
        for (var i = 0; i < path.length; i++) {
            if (path[i] === '') { return v; }
            if (v === null || typeof v !== 'object' || !(path[i] in v)) { return undefined; }
            v = v[path[i]];
        }
        return v;
    }

    /** Make line tables as long as the submitted data before filling them in. */
    function growLines(form, old) {
        form.querySelectorAll('[data-add-line]').forEach(function (btn) {
            var table = form.querySelector(btn.dataset.addLine);
            var first = table && table.tBodies[0].querySelector('[name]');
            if (!first) { return; }
            var base = first.name.split('[')[0];
            var rows = old[base];
            var count = Array.isArray(rows) ? rows.length : (rows && typeof rows === 'object' ? Object.keys(rows).length : 0);
            while (table.tBodies[0].rows.length < count) { addLine(table); }
        });
    }

    function restoreForm(form, data) {
        var old = data.old || {};
        growLines(form, old);
        Array.prototype.forEach.call(form.elements, function (f) {
            if (!f.name || f.disabled || f.type === 'hidden' || f.type === 'file' || f.type === 'password' || f.name.charAt(0) === '_') { return; }
            var v = oldValue(old, f.name);
            if (f.type === 'checkbox' || f.type === 'radio') {
                if (Array.isArray(v)) { f.checked = v.map(String).indexOf(f.value) !== -1; }
                else { f.checked = v !== undefined && String(v) === f.value; }
            } else if (v !== undefined && v !== null && typeof v !== 'object') {
                f.value = v;
            } else if (v === undefined || v === null) {
                if (f.tagName === 'SELECT') { f.selectedIndex = 0; } else if (f.tagName !== 'BUTTON') { f.value = ''; }
            }
        });

        var errors = data.errors || {};
        var all = [];
        Object.keys(errors).forEach(function (key) {
            errors[key].forEach(function (m) { all.push(m); });
            var base = key.split('.')[0];
            var field = Array.prototype.find.call(form.elements, function (f) {
                return f.name && f.type !== 'hidden' && f.name.replace(/\]/g, '').split('[').join('.').replace(/\.$/, '') === key;
            }) || form.querySelector('[name="' + base + '"]:not([type=hidden]), [name="' + base + '[]"]');
            if (field) {
                var wrap = field.closest('.field, td') || field.parentNode;
                wrap.classList.add('has-error');
                if (!wrap.querySelector('.err')) { wrap.appendChild(el('div', 'err', '')).textContent = errors[key][0]; }
            }
        });
        if (all.length) {
            var box = el('div', 'alert bad');
            box.setAttribute('role', 'alert');
            var ul = el('ul');
            all.forEach(function (m) { var li = el('li'); li.textContent = m; ul.appendChild(li); });
            box.appendChild(ul);
            form.insertBefore(box, form.firstChild);
        }
    }

    function initModals() {
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('a[data-modal]');
            if (trigger && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                e.preventDefault();
                document.body.classList.remove('nav-open');
                loadPageIntoModal(trigger.href);
                return;
            }
            var tplTrigger = e.target.closest('[data-modal-target]');
            if (tplTrigger) {
                e.preventDefault();
                openTemplate(tplTrigger.dataset.modalTarget);
                return;
            }
            if (e.target.closest('[data-modal-close]')) {
                closeModal(e.target.closest('.modal'));
                return;
            }
            if (e.target.classList.contains('modal')) { closeModal(e.target); }
        });
        // A GET form inside the modal (e.g. "source store" picker) reloads the modal instead of leaving the page.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form.method !== 'get' || !form.closest('#app-modal')) { return; }
            e.preventDefault();
            var url = new URL(form.action || window.location.href, window.location.href);
            new FormData(form).forEach(function (v, k) { url.searchParams.set(k, v); });
            loadPageIntoModal(url.toString());
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var open = document.querySelectorAll('.modal:not([hidden])');
                if (open.length) { closeModal(open[open.length - 1]); }
            }
        });

        var restoreTag = document.getElementById('modal-restore');
        if (restoreTag) {
            var data = JSON.parse(restoreTag.textContent);
            if (data.target.charAt(0) === '#') { openTemplate(data.target, data); }
            else if (data.target.indexOf(window.location.origin + '/') === 0) { loadPageIntoModal(data.target, data); }
        }
    }

    /* ---------- Confirmation ---------- */
    function initConfirm() {
        var modal = document.getElementById('confirm-modal');
        if (!modal) { return; }
        var pending = null;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-confirm]');
            if (!btn || btn.dataset.confirmed === '1') { return; }
            e.preventDefault();
            var form = btn.form;
            if (form && !form.checkValidity()) { form.reportValidity(); return; }
            pending = btn;
            modal.querySelector('.confirm-message').textContent = btn.dataset.confirm;
            modal.querySelector('[data-confirm-ok] span').textContent = btn.dataset.confirmOk || 'Yes, continue';
            openModal(modal);
        });
        modal.querySelector('[data-confirm-ok]').addEventListener('click', function () {
            if (!pending) { return; }
            var btn = pending;
            pending = null;
            closeModal(modal);
            btn.dataset.confirmed = '1';
            btn.click();
            setTimeout(function () { btn.dataset.confirmed = ''; }, 0);
        });
    }

    /* ---------- Print buttons ---------- */
    function initPrint() {
        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-print]')) { window.print(); }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initFlash();
        initLines();
        initPrint();
        enhance(document);
        initModals();
        initConfirm();
    });
})();
