/**
 * NovaServe shared front-end helpers (customer portal + admin console).
 * Vanilla JavaScript, no build step required.
 */
(function () {
    'use strict';

    const NS = window.NS = window.NS || {};

    /* ------------------------------------------------------------------
     * Translation, URLs, escaping
     * ------------------------------------------------------------------ */
    NS.t = function (key, vars) {
        let text = (NS.i18n && NS.i18n['js.' + key]) || key;
        Object.entries(vars || {}).forEach(([name, value]) => {
            text = text.split(':' + name).join(String(value));
        });
        return text;
    };

    NS.url = function (path) {
        if (/^https?:\/\//i.test(path)) return path;
        return (NS.baseUrl || '') + '/' + String(path || '').replace(/^\/+/, '');
    };

    NS.escape = function (value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    };

    /** Safe mini-formatter for chat text: escapes first, then supports lists, **bold** and ## headings. */
    NS.formatText = function (text) {
        const lines = NS.escape(text).split(/\r?\n/);
        const inline = (s) => s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        let html = '';
        let list = null;
        const closeList = () => { if (list) { html += '</' + list + '>'; list = null; } };
        lines.forEach((raw) => {
            const line = raw.trim();
            let m;
            if ((m = line.match(/^[-•*]\s+(.*)$/)) || (m = line.match(/^(\d+)[.)]\s+(.*)$/))) {
                const type = m.length === 2 ? 'ul' : 'ol';
                if (list !== type) { closeList(); html += '<' + type + '>'; list = type; }
                html += '<li>' + inline(m[m.length - 1]) + '</li>';
                return;
            }
            closeList();
            if (line === '') return;
            const heading = line.match(/^#{1,3}\s+(.*)$/);
            html += heading ? '<p><strong>' + inline(heading[1]) + '</strong></p>' : '<p>' + inline(line) + '</p>';
        });
        closeList();
        return html;
    };

    /* ------------------------------------------------------------------
     * JSON API helper (sends the CSRF token on every request)
     * ------------------------------------------------------------------ */
    NS.api = async function (url, options = {}) {
        const init = {
            method: options.method || 'GET',
            headers: { Accept: 'application/json', 'X-CSRF-Token': NS.csrf || '' },
            credentials: 'same-origin',
        };
        if (options.body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        let response;
        try {
            response = await fetch(url, init);
        } catch (e) {
            throw new Error(NS.t('network_error'));
        }
        let data = null;
        try { data = await response.json(); } catch (e) { /* non-JSON response */ }
        if (!response.ok || !data || data.ok === false) {
            const error = new Error((data && data.error) || NS.t('error_generic'));
            error.status = response.status;
            error.data = data || {};
            throw error;
        }
        return data;
    };

    /* ------------------------------------------------------------------
     * Toasts & confirmation dialog
     * ------------------------------------------------------------------ */
    NS.toast = function (message, type = 'info') {
        const container = document.getElementById('nsToasts');
        if (!container || !window.bootstrap) { return; }
        const icons = { success: 'fa-circle-check', danger: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
        const el = document.createElement('div');
        el.className = 'toast ns-toast ns-toast-' + type;
        el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        el.innerHTML = '<div class="d-flex align-items-start gap-2 p-3"><i class="fa-solid ' + (icons[type] || icons.info) + ' mt-1"></i>'
            + '<div class="flex-grow-1">' + NS.escape(message) + '</div>'
            + '<button type="button" class="btn-close btn-sm" data-bs-dismiss="toast" aria-label="Close"></button></div>';
        container.appendChild(el);
        const toast = new bootstrap.Toast(el, { delay: 5000 });
        el.addEventListener('hidden.bs.toast', () => el.remove());
        toast.show();
    };

    NS.confirm = function (message) {
        return new Promise((resolve) => {
            if (!window.bootstrap) { resolve(window.confirm(message)); return; }
            const wrapper = document.createElement('div');
            wrapper.className = 'modal fade';
            wrapper.tabIndex = -1;
            wrapper.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content ns-confirm">'
                + '<div class="modal-body"><div class="ns-confirm-icon"><i class="fa-solid fa-circle-question"></i></div><p class="mb-0">' + NS.escape(message) + '</p></div>'
                + '<div class="modal-footer"><button type="button" class="btn ns-btn-ghost" data-answer="no">Cancel</button>'
                + '<button type="button" class="btn ns-btn-primary" data-answer="yes">Confirm</button></div></div></div>';
            document.body.appendChild(wrapper);
            const modal = new bootstrap.Modal(wrapper);
            let answer = false;
            wrapper.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-answer]');
                if (btn) { answer = btn.dataset.answer === 'yes'; modal.hide(); }
            });
            wrapper.addEventListener('hidden.bs.modal', () => { wrapper.remove(); resolve(answer); });
            wrapper.addEventListener('shown.bs.modal', () => wrapper.querySelector('[data-answer="yes"]').focus());
            modal.show();
        });
    };

    // Forms with data-confirm ask before submitting
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.confirm && form.dataset.confirmed !== '1') {
            event.preventDefault();
            NS.confirm(form.dataset.confirm).then((ok) => {
                if (ok) { form.dataset.confirmed = '1'; form.submit(); }
            });
            return;
        }
        // Prevent double submission
        if (form.hasAttribute('data-ns-submit-once')) {
            if (form.dataset.submitting === '1') { event.preventDefault(); return; }
            form.dataset.submitting = '1';
            form.querySelectorAll('button[type="submit"]').forEach((btn) => {
                btn.disabled = true;
                btn.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>');
            });
        }
    }, true);

    /* ------------------------------------------------------------------
     * Small UI behaviours
     * ------------------------------------------------------------------ */
    document.addEventListener('click', (event) => {
        const demo = event.target.closest('[data-ns-demo-email]');
        if (demo) {
            const email = document.getElementById('email');
            const password = document.getElementById('password');
            if (email && password) {
                email.value = demo.dataset.nsDemoEmail;
                password.value = demo.dataset.nsDemoPassword;
                password.focus();
            }
        }
        const toggle = event.target.closest('[data-ns-toggle-password]');
        if (toggle) {
            const input = document.getElementById(toggle.dataset.nsTogglePassword);
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
                toggle.querySelector('i')?.classList.toggle('fa-eye-slash');
            }
        }
    });

    // Top bar shadow once the page is scrolled
    const topbar = document.querySelector('.ns-topbar, .ns-admin-topbar');
    if (topbar) {
        const onScroll = () => topbar.classList.toggle('is-scrolled', window.scrollY > 8);
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // Fade sections in as they scroll into view
    // A plain position check is used instead of IntersectionObserver, which can stall in embedded/background views
    let revealPending = Array.from(document.querySelectorAll('[data-ns-reveal]'));
    revealPending.forEach((el, i) => el.style.setProperty('--ns-delay', (i % 4) * 80 + 'ms'));
    const revealCheck = () => {
        const limit = window.innerHeight - 40;
        revealPending = revealPending.filter((el) => {
            if (el.getBoundingClientRect().top < limit) {
                el.classList.add('is-visible');
                return false;
            }
            return true;
        });
        if (!revealPending.length) {
            window.removeEventListener('scroll', revealCheck);
            window.removeEventListener('resize', revealCheck);
        }
    };
    if (revealPending.length) {
        window.addEventListener('scroll', revealCheck, { passive: true });
        window.addEventListener('resize', revealCheck);
        revealCheck();
    }

    // Reading progress bar on knowledge articles
    const progress = document.querySelector('[data-ns-read-progress]');
    const article = document.querySelector('[data-ns-article]');
    if (progress && article) {
        const update = () => {
            const rect = article.getBoundingClientRect();
            const total = Math.max(1, rect.height - window.innerHeight * 0.6);
            const done = Math.min(1, Math.max(0, -rect.top / total));
            progress.style.width = (done * 100).toFixed(1) + '%';
        };
        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    }

    // Auto-hide flash messages
    document.querySelectorAll('.ns-flash.alert-success, .ns-flash.alert-info').forEach((el) => {
        setTimeout(() => window.bootstrap && bootstrap.Alert.getOrCreateInstance(el).close(), 6000);
    });

    /* ------------------------------------------------------------------
     * Notifications (bell)
     * ------------------------------------------------------------------ */
    const notifRoot = document.querySelector('[data-ns-notifications]');
    if (notifRoot && NS.loggedIn) {
        const countEl = notifRoot.querySelector('[data-ns-notif-count]');
        const listEl = notifRoot.querySelector('[data-ns-notif-list]');
        const endpoint = NS.url('api/notifications.php');
        let pollHandle = null;
        const iconFor = (type) => ({
            escalation: 'fa-hand-holding-heart', angry_customer: 'fa-face-angry', ticket_new: 'fa-ticket',
            ticket_reply: 'fa-reply', ticket_assigned: 'fa-user-tag', ticket_status: 'fa-arrows-rotate',
            chat_takeover: 'fa-headset', chat_reply: 'fa-comment-dots',
        }[type] || 'fa-bell');

        const setCount = (n) => {
            countEl.textContent = n > 99 ? '99+' : String(n);
            countEl.classList.toggle('d-none', n <= 0);
        };
        const render = (items) => {
            if (!items.length) {
                listEl.innerHTML = '<div class="p-4 text-center text-muted small"><i class="fa-regular fa-bell-slash d-block mb-2 fs-4"></i>' + NS.escape(NS.t('notif_empty')) + '</div>';
                return;
            }
            listEl.innerHTML = items.map((n) => '<a class="ns-notif-item ' + (n.is_read ? '' : 'unread') + '" href="' + NS.escape(n.link || '#') + '" data-id="' + n.id + '">'
                + '<span class="ns-notif-icon"><i class="fa-solid ' + iconFor(n.type) + '"></i></span>'
                + '<span class="ns-notif-text"><strong>' + NS.escape(n.title) + '</strong>'
                + (n.body ? '<small>' + NS.escape(n.body) + '</small>' : '')
                + '<em>' + NS.escape(n.time) + '</em></span></a>').join('');
        };
        const load = () => NS.api(endpoint).then((d) => { setCount(d.unread); render(d.items); }).catch(() => {});
        const poll = () => NS.api(endpoint + '?count_only=1').then((d) => setCount(d.unread)).catch((e) => {
            if (e.status === 401) clearInterval(pollHandle);
        });

        notifRoot.addEventListener('show.bs.dropdown', load);
        listEl.addEventListener('click', (e) => {
            const item = e.target.closest('.ns-notif-item');
            if (!item) return;
            e.preventDefault();
            const href = item.getAttribute('href');
            NS.api(endpoint, { method: 'POST', body: { action: 'read', id: Number(item.dataset.id) } })
                .finally(() => { if (href && href !== '#') window.location.href = href; else load(); });
        });
        notifRoot.querySelector('[data-ns-notif-readall]')?.addEventListener('click', () => {
            NS.api(endpoint, { method: 'POST', body: { action: 'read_all' } }).then(() => load()).catch(() => {});
        });
        pollHandle = setInterval(poll, 45000);
    }

    /* ------------------------------------------------------------------
     * Live knowledge search (homepage + help center)
     * ------------------------------------------------------------------ */
    document.querySelectorAll('[data-ns-live-search]').forEach((form) => {
        const input = form.querySelector('input[name="q"]');
        const box = form.querySelector('[data-ns-search-results]');
        if (!input || !box) return;
        let timer = null;
        let lastQuery = '';
        const hide = () => { box.hidden = true; };
        const section = (title, items, icon) => items.length
            ? '<div class="ns-sr-title">' + NS.escape(title) + '</div>' + items.map((i) => '<a class="ns-sr-item" href="' + NS.escape(i.url) + '"><i class="fa-solid ' + icon + '"></i><span><strong>'
                + NS.escape(i.title) + '</strong><small>' + NS.escape(i.summary) + '</small></span></a>').join('')
            : '';
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2) { hide(); return; }
            timer = setTimeout(() => {
                lastQuery = q;
                NS.api(NS.url('api/knowledge.php') + '?limit=4&q=' + encodeURIComponent(q)).then((d) => {
                    if (q !== lastQuery) return;
                    const html = section(NS.t('search_articles'), d.articles, 'fa-file-lines') + section(NS.t('search_faqs'), d.faqs, 'fa-circle-question');
                    box.innerHTML = html || '<div class="ns-sr-empty">' + NS.escape(NS.t('search_none')) + '</div>';
                    box.hidden = false;
                }).catch(hide);
            }, 250);
        });
        input.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
        document.addEventListener('click', (e) => { if (!form.contains(e.target)) hide(); });
    });

    /* ------------------------------------------------------------------
     * FAQ: view tracking + deep links
     * ------------------------------------------------------------------ */
    const viewedFaqs = new Set();
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-ns-faq-view]');
        if (btn && btn.classList.contains('collapsed') === false) {
            const id = Number(btn.dataset.nsFaqView);
            if (!viewedFaqs.has(id)) {
                viewedFaqs.add(id);
                NS.api(NS.url('api/faq.php'), { method: 'POST', body: { action: 'view', id } }).catch(() => {});
            }
        }
        const link = e.target.closest('[data-ns-open-faq]');
        if (link) {
            e.preventDefault();
            openFaq(link.dataset.nsOpenFaq);
        }
    });
    function openFaq(id) {
        const item = document.getElementById('faq-' + id);
        if (!item || !window.bootstrap) return;
        const body = item.querySelector('.accordion-collapse');
        const btn = item.querySelector('.accordion-button');
        if (body && !body.classList.contains('show')) { btn?.click(); }
        item.scrollIntoView({ behavior: 'smooth', block: 'center' });
        item.classList.add('ns-highlight');
        setTimeout(() => item.classList.remove('ns-highlight'), 2000);
    }
    const hashMatch = window.location.hash.match(/^#faq-(\d+)$/);
    if (hashMatch) { window.addEventListener('load', () => openFaq(hashMatch[1])); }

    /* ------------------------------------------------------------------
     * Attachment picker preview + client-side checks (server re-validates)
     * ------------------------------------------------------------------ */
    document.querySelectorAll('[data-ns-upload]').forEach((wrap) => {
        const input = wrap.querySelector('input[type="file"]');
        const list = wrap.querySelector('[data-ns-file-list]');
        if (!input || !list) return;
        const maxBytes = Number(input.dataset.maxBytes || 0);
        const maxFiles = Number(input.dataset.maxFiles || 3);
        const allowed = (input.getAttribute('accept') || '').split(',').map((s) => s.trim().replace('.', '').toLowerCase());
        input.addEventListener('change', () => {
            const problems = [];
            const files = Array.from(input.files || []);
            if (files.length > maxFiles) problems.push(NS.t('too_many_files', { n: maxFiles }));
            list.innerHTML = files.map((f) => {
                const ext = f.name.split('.').pop().toLowerCase();
                let bad = '';
                if (!allowed.includes(ext)) { bad = NS.t('file_type', { name: f.name }); problems.push(bad); }
                else if (maxBytes && f.size > maxBytes) { bad = NS.t('file_too_large', { name: f.name }); problems.push(bad); }
                return '<li class="' + (bad ? 'bad' : '') + '"><i class="fa-solid ' + (bad ? 'fa-circle-exclamation' : 'fa-paperclip') + '"></i>'
                    + NS.escape(f.name) + ' <small>' + (f.size / 1024 / 1024).toFixed(2) + ' MB</small></li>';
            }).join('');
            if (problems.length) {
                NS.toast(problems.join(' '), 'warning');
                input.value = '';
                list.innerHTML = '';
            }
        });
    });
})();
