/*
 * Construction CMS UI helpers (no build step, served from public/ so the CSP 'self' rule allows it).
 *  - Sidebar: collapsible groups (state remembered per browser), mobile open/close.
 *  - Modals:  <a data-modal href="/users/create"> loads that page's form into the modal.
 *             <button data-modal-target="#tpl-id"> copies a <template id="tpl-id"> into the modal.
 *             Forms submit normally. A hidden _modal field lets the next page reopen the modal
 *             with the submitted values and errors when validation fails.
 *  - Confirm: <button data-confirm="Message"> asks before submitting (delete, cancel, reject).
 */
(function () {
    'use strict';

    var store = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } }
    };

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

    /* ---------- Modal core ---------- */
    var lastFocus = null;

    function openModal(modal) {
        lastFocus = document.activeElement;
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

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (html !== undefined) { n.innerHTML = html; }
        return n;
    }

    /** Prepare a form for display in the modal: tag it, turn Cancel links into close buttons, add a footer. */
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
        form.appendChild(foot);
    }

    function showInModal(title, nodes, target) {
        var modal = document.getElementById('app-modal');
        var body = modal.querySelector('.modal-body');
        modal.querySelector('#app-modal-title').textContent = title;
        body.innerHTML = '';
        nodes.forEach(function (n) { body.appendChild(n); });
        var form = body.querySelector('form');
        if (form) { adoptForm(form, target); }
        modal.querySelector('.modal-dialog').classList.toggle('modal-lg', !!body.querySelector('[data-modal-wide], textarea, fieldset, .form-grid .field:nth-child(7)'));
        openModal(modal);
        return form;
    }

    /** Load a create/edit page and show its form. Falls back to normal navigation on any problem. */
    function loadPageIntoModal(url, restore) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok || res.redirected) { throw new Error('fallback'); }
                return res.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var content = doc.querySelector('.content');
                if (!content || !content.querySelector('form[method=POST], form[method=post]')) { throw new Error('fallback'); }
                var h1 = content.querySelector('.page-head h1');
                var nodes = [];
                var intro = content.querySelector('.page-head p');
                if (intro) { nodes.push(el('p', 'muted modal-intro', intro.innerHTML)); }
                Array.prototype.forEach.call(content.children, function (child) {
                    if (child.classList.contains('page-head')) { return; }
                    if (child.matches('.alert[role=status], .alert[role=alert]')) { return; }
                    nodes.push(document.importNode(child, true));
                });
                var form = showInModal(h1 ? h1.textContent.trim() : document.title, nodes, url);
                if (restore && form) { restoreForm(form, restore); }
            })
            .catch(function () { window.location.href = url; });
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

    function restoreForm(form, data) {
        var old = data.old || {};
        Array.prototype.forEach.call(form.elements, function (f) {
            if (!f.name || f.type === 'hidden' || f.type === 'file' || f.type === 'password' || f.name.charAt(0) === '_') { return; }
            var v = oldValue(old, f.name);
            if (f.type === 'checkbox' || f.type === 'radio') {
                if (Array.isArray(v)) { f.checked = v.map(String).indexOf(f.value) !== -1; }
                else { f.checked = v !== undefined && String(v) === f.value; }
            } else if (v !== undefined && v !== null && typeof v !== 'object') {
                f.value = v;
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
                var wrap = field.closest('.field') || field.parentNode;
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

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initModals();
        initConfirm();
    });
})();
