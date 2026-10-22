/**
 * Live conversation view for support staff (admin/conversation.php):
 * transcript with AI insight, live polling, take over / return to AI / resolve, and agent replies.
 */
(function () {
    'use strict';

    const dataEl = document.getElementById('nsLiveChat');
    if (!dataEl) return;
    const NS = window.NS;
    const data = JSON.parse(dataEl.textContent);
    const transcript = document.querySelector('[data-ns-transcript]');
    const statusEl = document.querySelector('[data-ns-live-status]');
    const actions = document.querySelector('[data-ns-staff-actions]');
    const composer = document.querySelector('[data-ns-staff-composer]');
    const textarea = composer ? composer.querySelector('textarea') : null;

    const PROVIDERS = { primary: 'Primary AI', fallback: 'Fallback AI', local: 'Local knowledge', human: 'Human' };
    const INTENT_LABEL = (v) => (v || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    let state = { status: data.status, staffName: data.staffName, isMine: data.isMine };
    let lastId = 0;
    const seen = new Set();

    function chip(text, cls) {
        const span = document.createElement('span');
        span.className = 'ns-badge ' + (cls || 'ns-badge-muted');
        span.textContent = text;
        return span;
    }

    function append(msg) {
        if (!msg || seen.has(msg.id)) return;
        seen.add(msg.id);
        lastId = Math.max(lastId, msg.id);

        const row = document.createElement('div');
        row.className = 'ns-smsg ns-smsg-' + msg.sender_type;
        if (msg.sender_type === 'system') {
            row.innerHTML = '<div class="ns-msg-system"><i class="fa-solid fa-circle-info me-1"></i><span></span> <time></time></div>';
            row.querySelector('span').textContent = msg.body;
            row.querySelector('time').textContent = msg.time;
            transcript.appendChild(row);
            return;
        }
        const head = document.createElement('div');
        head.className = 'ns-smsg-head';
        const name = document.createElement('strong');
        name.textContent = msg.sender_type === 'ai' ? msg.sender_name + ' (AI)' : msg.sender_name || (msg.sender_type === 'staff' ? 'Agent' : 'Customer');
        const time = document.createElement('time');
        time.textContent = msg.time;
        head.append(name, time);

        const bubble = document.createElement('div');
        bubble.className = 'ns-smsg-bubble';
        bubble.innerHTML = NS.formatText(msg.body);

        const insight = document.createElement('div');
        insight.className = 'ns-smsg-insight';
        if (msg.sender_type === 'customer') {
            if (msg.intent) insight.appendChild(chip(INTENT_LABEL(msg.intent)));
            if (msg.sentiment) insight.appendChild(chip(msg.sentiment.charAt(0).toUpperCase() + msg.sentiment.slice(1), 'ns-badge-sentiment-' + msg.sentiment));
            if (msg.language) insight.appendChild(chip(msg.language.toUpperCase()));
        }
        if (msg.sender_type === 'ai') {
            if (msg.confidence !== null) insight.appendChild(chip('Confidence ' + msg.confidence + '%', msg.needs_human ? 'ns-badge-sentiment-negative' : 'ns-badge-status-resolved'));
            if (msg.provider) insight.appendChild(chip(PROVIDERS[msg.provider] + (msg.model ? ' · ' + msg.model : '')));
            (msg.sources || []).forEach((s) => insight.appendChild(chip((s.type === 'faq' ? 'FAQ: ' : 'Article: ') + s.title, 'ns-badge-source')));
            if (msg.feedback) insight.appendChild(chip(msg.feedback === 'helpful' ? '👍 Helpful' : '👎 Not helpful', msg.feedback === 'helpful' ? 'ns-badge-status-resolved' : 'ns-badge-sentiment-angry'));
        }
        row.append(head, bubble);
        if (insight.childNodes.length) row.appendChild(insight);
        transcript.appendChild(row);
    }

    function renderState() {
        const labels = {
            active: 'AI assistant is handling this conversation',
            escalated: 'Customer is waiting for a human agent',
            human: 'Handled by ' + (state.staffName || 'an agent') + (state.isMine ? ' (you)' : ''),
            closed: 'Conversation closed',
        };
        statusEl.textContent = labels[state.status] || state.status;
        statusEl.className = 'small ns-live-' + state.status;
        if (actions) {
            const show = (action, visible) => { const b = actions.querySelector('[data-ns-action="' + action + '"]'); if (b) b.hidden = !visible; };
            show('takeover', state.status === 'active' || state.status === 'escalated' || (state.status === 'human' && !state.isMine));
            show('release', state.status === 'human' || state.status === 'escalated');
            show('resolve', state.status !== 'closed');
        }
        if (composer) {
            const closed = state.status === 'closed';
            textarea.disabled = closed;
            composer.querySelector('button').disabled = closed;
        }
    }

    async function poll() {
        try {
            const res = await NS.api(data.urls.messages + '?session_id=' + data.sessionId + '&after_id=' + lastId);
            const before = lastId;
            res.messages.forEach(append);
            if (lastId > before) transcript.scrollTop = transcript.scrollHeight;
            state.status = res.session.status;
            state.staffName = res.session.staff_name;
            renderState();
        } catch (e) { /* keep trying */ }
    }

    actions?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-ns-action]');
        if (!btn) return;
        if (btn.dataset.confirm && !(await NS.confirm(btn.dataset.confirm))) return;
        btn.disabled = true;
        try {
            const res = await NS.api(data.urls.escalation, { method: 'POST', body: { action: btn.dataset.nsAction, session_id: data.sessionId } });
            state.status = res.session.status;
            state.staffName = res.session.staff_name;
            state.isMine = btn.dataset.nsAction === 'takeover' || (state.isMine && state.status === 'human');
            NS.toast({ takeover: 'You are now handling this conversation.', release: 'Conversation returned to the AI assistant.', resolve: 'Conversation resolved and closed.' }[btn.dataset.nsAction], 'success');
            await poll();
            if (btn.dataset.nsAction === 'takeover') textarea?.focus();
        } catch (err) {
            NS.toast(err.message, 'danger');
        } finally {
            btn.disabled = false;
        }
    });

    composer?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = textarea.value.trim();
        if (!text) return;
        const button = composer.querySelector('button');
        button.disabled = true;
        try {
            const res = await NS.api(data.urls.messages, { method: 'POST', body: { session_id: data.sessionId, message: text } });
            textarea.value = '';
            state.isMine = true;
            await poll();
            append(res.message);
            transcript.scrollTop = transcript.scrollHeight;
        } catch (err) {
            NS.toast(err.message, 'danger');
        } finally {
            button.disabled = state.status === 'closed';
            textarea.focus();
        }
    });
    textarea?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) composer.requestSubmit();
    });

    data.messages.forEach(append);
    transcript.scrollTop = transcript.scrollHeight;
    renderState();
    setInterval(() => { if (!document.hidden && state.status !== 'closed') poll(); }, 4000);
})();
