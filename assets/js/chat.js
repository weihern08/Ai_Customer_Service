/**
 * Customer AI chat (chat.php).
 * Messages are always inserted with textContent / escaped HTML - never raw server HTML.
 */
(function () {
    'use strict';

    const dataEl = document.getElementById('nsChatData');
    if (!dataEl) return;
    const NS = window.NS;
    const Voice = window.NSVoice;
    const data = JSON.parse(dataEl.textContent);

    const $ = (sel) => document.querySelector(sel);
    const els = {
        messages: $('[data-ns-messages]'),
        form: $('[data-ns-composer]'),
        input: $('[data-ns-input]'),
        send: $('[data-ns-send]'),
        mic: $('[data-ns-mic]'),
        counter: $('[data-ns-counter]'),
        voiceStatus: $('[data-ns-voice-status]'),
        status: $('[data-ns-chat-status]'),
        name: $('[data-ns-chat-name]'),
        avatar: $('[data-ns-chat-avatar]'),
        banner: $('[data-ns-chat-banner]'),
        escalate: $('[data-ns-escalate]'),
        ticketLink: $('[data-ns-ticket-from-chat]'),
    };

    let session = data.session;
    let lastId = 0;
    const seen = new Set();
    let busy = false;
    let pollTimer = null;
    let typingEl = null;
    let speakingBtn = null;
    const defaultHint = els.voiceStatus ? els.voiceStatus.textContent : '';

    /* ---------------- Rendering ---------------- */

    function confidenceClass(score) {
        if (score >= Math.max(data.threshold + 20, 80)) return 'high';
        if (score >= data.threshold) return 'medium';
        return 'low';
    }

    function scrollToBottom(smooth) {
        els.messages.scrollTo({ top: els.messages.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }

    function renderWelcome() {
        if (els.messages.querySelector('.ns-welcome')) return;
        const box = document.createElement('div');
        box.className = 'ns-welcome';
        box.innerHTML = '<span class="ns-ai-avatar ns-ai-avatar-lg"><i class="fa-solid fa-star-of-life"></i></span>'
            + '<h2>' + NS.escape(NS.t('welcome_title', { user: data.userName.split(' ')[0], name: data.assistant })) + '</h2>'
            + '<p>' + NS.escape(NS.t('welcome_text')) + '</p>';
        const chips = document.createElement('div');
        chips.className = 'ns-welcome-chips';
        document.querySelectorAll('[data-ns-suggestion]').forEach((btn, i) => {
            if (i > 3) return;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'ns-chip';
            chip.textContent = btn.dataset.nsSuggestion;
            chip.addEventListener('click', () => send(chip.textContent));
            chips.appendChild(chip);
        });
        box.appendChild(chips);
        els.messages.appendChild(box);
    }

    function appendMessage(msg, animate = true) {
        if (!msg || seen.has(msg.id)) return;
        seen.add(msg.id);
        lastId = Math.max(lastId, msg.id);
        els.messages.querySelector('.ns-welcome')?.remove();

        const row = document.createElement('div');
        row.className = 'ns-msg ns-msg-' + msg.sender_type + (animate ? ' ns-msg-in' : '');
        row.dataset.id = msg.id;

        if (msg.sender_type === 'system') {
            row.innerHTML = '<div class="ns-msg-system"><i class="fa-solid fa-circle-info me-1"></i><span></span></div>';
            row.querySelector('span').textContent = msg.body;
            els.messages.appendChild(row);
            return row;
        }

        const avatar = document.createElement('span');
        avatar.className = 'ns-msg-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        if (msg.sender_type === 'ai') avatar.innerHTML = '<i class="fa-solid fa-star-of-life"></i>';
        else if (msg.sender_type === 'staff') avatar.innerHTML = '<i class="fa-solid fa-headset"></i>';
        else avatar.textContent = (data.userName || '?').trim().charAt(0).toUpperCase();

        const body = document.createElement('div');
        body.className = 'ns-msg-body';

        const meta = document.createElement('div');
        meta.className = 'ns-msg-meta';
        const who = msg.sender_type === 'customer' ? NS.t('you')
            : msg.sender_type === 'staff' ? (msg.sender_name || NS.t('agent')) + ' · ' + NS.t('agent')
            : data.assistant + ' · ' + NS.t('ai_label');
        meta.innerHTML = '<strong></strong><time></time>';
        meta.querySelector('strong').textContent = who;
        meta.querySelector('time').textContent = msg.time;
        meta.querySelector('time').dateTime = msg.datetime || '';

        const bubble = document.createElement('div');
        bubble.className = 'ns-msg-bubble';
        bubble.innerHTML = NS.formatText(msg.body);

        body.append(meta, bubble);

        if (msg.sender_type === 'ai') {
            const extras = document.createElement('div');
            extras.className = 'ns-msg-extras';
            if (msg.confidence !== null && msg.confidence !== undefined) {
                const conf = document.createElement('span');
                conf.className = 'ns-confidence ns-confidence-' + confidenceClass(msg.confidence);
                conf.innerHTML = '<i class="fa-solid fa-gauge-high"></i> ';
                conf.append(NS.t('confidence') + ': ' + msg.confidence + '%');
                conf.title = NS.t('confidence');
                extras.appendChild(conf);
            }
            (msg.sources || []).forEach((src) => {
                const a = document.createElement('a');
                a.className = 'ns-source-chip';
                a.href = NS.url(src.url);
                a.target = '_blank';
                a.rel = 'noopener';
                a.innerHTML = '<i class="fa-solid ' + (src.type === 'faq' ? 'fa-circle-question' : 'fa-book') + '"></i> ';
                a.append(src.title.length > 48 ? src.title.slice(0, 46) + '…' : src.title);
                a.title = NS.t('sources') + ': ' + src.title;
                extras.appendChild(a);
            });
            body.appendChild(extras);
            body.appendChild(buildActions(msg));
        }

        row.append(avatar, body);
        els.messages.appendChild(row);
        return row;
    }

    function buildActions(msg) {
        const actions = document.createElement('div');
        actions.className = 'ns-msg-actions';
        const button = (icon, label, cls) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ns-msg-action ' + (cls || '');
            b.innerHTML = '<i class="fa-solid ' + icon + '"></i>';
            b.title = label;
            b.setAttribute('aria-label', label);
            return b;
        };

        if (Voice && Voice.canSpeak()) {
            const listen = button('fa-volume-high', NS.t('listen'));
            listen.addEventListener('click', () => toggleSpeak(msg, listen));
            actions.appendChild(listen);
        }
        const copy = button('fa-copy', NS.t('copy'));
        copy.addEventListener('click', () => {
            navigator.clipboard?.writeText(msg.body).then(() => NS.toast(NS.t('copied'), 'success')).catch(() => {});
        });
        actions.appendChild(copy);

        const up = button('fa-thumbs-up', NS.t('helpful'), 'ns-fb');
        const down = button('fa-thumbs-down', NS.t('not_helpful'), 'ns-fb');
        if (msg.feedback === 'helpful') up.classList.add('active');
        if (msg.feedback === 'not_helpful') down.classList.add('active');
        up.addEventListener('click', () => sendFeedback(msg, 'helpful', up, down));
        down.addEventListener('click', () => sendFeedback(msg, 'not_helpful', down, up));
        actions.append(up, down);
        return actions;
    }

    function showTyping() {
        hideTyping();
        typingEl = document.createElement('div');
        typingEl.className = 'ns-msg ns-msg-ai ns-msg-typing';
        typingEl.innerHTML = '<span class="ns-msg-avatar"><i class="fa-solid fa-star-of-life"></i></span>'
            + '<div class="ns-msg-body"><div class="ns-msg-bubble"><div class="ns-typing" role="status"><span></span><span></span><span></span>'
            + '<em class="visually-hidden"></em></div></div></div>';
        typingEl.querySelector('em').textContent = NS.t('typing', { name: data.assistant });
        els.messages.appendChild(typingEl);
        scrollToBottom(true);
    }
    function hideTyping() { typingEl?.remove(); typingEl = null; }

    /* ---------------- Status & banners ---------------- */

    function updateStatus() {
        const s = session.status;
        const map = {
            active: ['ns-status-ai', NS.t('status_ai')],
            escalated: ['ns-status-waiting', NS.t('status_waiting')],
            human: ['ns-status-human', NS.t('status_human', { name: session.staff_name || NS.t('agent') })],
            closed: ['ns-status-closed', NS.t('status_closed')],
        };
        const [cls, label] = map[s] || map.active;
        els.status.className = 'ns-chat-status ' + cls;
        els.status.textContent = label;
        els.name.textContent = s === 'human' && session.staff_name ? session.staff_name : data.assistant;
        els.avatar.innerHTML = s === 'human' ? '<i class="fa-solid fa-headset"></i>' : '<i class="fa-solid fa-star-of-life"></i>';
        els.avatar.classList.toggle('is-human', s === 'human');

        els.escalate.disabled = s !== 'active';
        els.escalate.classList.toggle('is-waiting', s === 'escalated');

        const closed = s === 'closed';
        els.input.disabled = closed;
        els.send.disabled = closed || busy;
        els.mic.disabled = closed;
        if (closed) {
            showBanner('closed', NS.t('closed_notice'), { label: NS.t('start_new'), action: newChat, icon: 'fa-plus' });
        }
        if (els.ticketLink) {
            els.ticketLink.href = NS.url('ticket.php?new=1' + (session.id ? '&chat=' + session.id : ''));
        }
    }

    function showBanner(type, text, button) {
        els.banner.className = 'ns-chat-banner ns-banner-' + type;
        els.banner.innerHTML = '<i class="fa-solid ' + (type === 'angry' ? 'fa-heart-crack' : type === 'closed' ? 'fa-lock' : 'fa-life-ring') + '"></i><span></span>';
        els.banner.querySelector('span').textContent = text;
        if (button) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm ns-btn-human';
            b.innerHTML = '<i class="fa-solid ' + (button.icon || 'fa-headset') + ' me-1"></i>';
            b.append(button.label);
            b.addEventListener('click', button.action);
            els.banner.appendChild(b);
        }
        if (type !== 'closed') {
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'btn-close btn-sm';
            x.setAttribute('aria-label', NS.t('dismiss'));
            x.addEventListener('click', hideBanner);
            els.banner.appendChild(x);
        }
        els.banner.hidden = false;
    }
    function hideBanner() { els.banner.hidden = true; }

    function handleRecommendation(result) {
        if (!result.recommend_human || session.status !== 'active') return;
        const conf = result.ai_message ? result.ai_message.confidence : null;
        const reason = result.recommend_reason;
        const text = reason === 'negative_sentiment' ? NS.t('angry')
            : reason === 'low_confidence' ? NS.t('low_confidence', { score: conf ?? 0 })
            : NS.t('human_suggested');
        showBanner(reason === 'negative_sentiment' ? 'angry' : 'suggest', text, {
            label: NS.t('talk_human'),
            action: () => escalate(reason || 'customer_request'),
        });
    }

    /* ---------------- Actions ---------------- */

    async function send(text) {
        text = (text || '').trim();
        if (!text || busy || session.status === 'closed') return;
        if (text.length > data.maxLength) { NS.toast(NS.t('error_generic'), 'warning'); return; }
        busy = true;
        els.send.disabled = true;
        Voice?.stopListening();
        hideBanner();

        const pending = appendMessage({ id: -Date.now(), sender_type: 'customer', body: text, time: NS.t('sending'), sources: [] });
        pending?.classList.add('ns-msg-pending');
        els.input.value = '';
        autoResize();
        updateCounter();
        scrollToBottom(true);
        if (session.status !== 'human') showTyping();

        try {
            const result = await NS.api(data.urls.chat, {
                method: 'POST',
                body: { action: 'send', session_id: session.id, message: text, lang: NS.lang },
            });
            pending?.remove();
            hideTyping();
            session = result.session;
            appendMessage(result.customer_message);
            appendMessage(result.ai_message);
            updateStatus();
            handleRecommendation(result);
            if (result.escalated) NS.toast(NS.t('escalated_ok'), 'info');
            if (result.ai_message && Voice && Voice.prefs.autoSpeak) Voice.speak(result.ai_message.body, result.ai_message.language || NS.lang);
            scrollToBottom(true);
            startPolling();
        } catch (error) {
            pending?.remove();
            hideTyping();
            els.input.value = text;
            autoResize();
            NS.toast(error.message, 'danger');
            if (error.data && error.data.code === 'closed') { session.status = 'closed'; updateStatus(); }
            if (error.status === 401) window.location.href = NS.url('login.php?next=chat.php');
        } finally {
            busy = false;
            els.send.disabled = session.status === 'closed';
            els.input.focus();
        }
    }

    async function escalate(reason) {
        try {
            const result = await NS.api(data.urls.escalation, {
                method: 'POST',
                body: { action: 'request', session_id: session.id, reason: reason || 'customer_request' },
            });
            session = result.session;
            hideBanner();
            updateStatus();
            NS.toast(NS.t('escalated_ok'), 'success');
            await poll();
            startPolling();
        } catch (error) {
            NS.toast(error.message, 'danger');
        }
    }

    async function newChat() {
        if (session.id && !(await NS.confirm(NS.t('confirm_new')))) return;
        try {
            await NS.api(data.urls.chat, { method: 'POST', body: { action: 'new' } });
        } catch (e) { /* still reset the screen */ }
        window.location.href = NS.url('chat.php');
    }

    async function sendFeedback(msg, rating, button, other) {
        try {
            await NS.api(data.urls.feedback, { method: 'POST', body: { message_id: msg.id, rating } });
            button.classList.add('active');
            other.classList.remove('active');
            msg.feedback = rating;
            if (rating === 'not_helpful') askComment(msg, button.closest('.ns-msg-body'));
            else NS.toast(NS.t('feedback_thanks'), 'success');
        } catch (error) {
            NS.toast(error.message, 'danger');
        }
    }

    function askComment(msg, container) {
        if (container.querySelector('.ns-fb-comment')) return;
        const form = document.createElement('form');
        form.className = 'ns-fb-comment';
        form.innerHTML = '<input type="text" class="form-control form-control-sm" maxlength="500">'
            + '<button class="btn btn-sm ns-btn-primary" type="submit"></button>';
        const input = form.querySelector('input');
        input.placeholder = NS.t('feedback_comment');
        input.setAttribute('aria-label', NS.t('feedback_comment'));
        form.querySelector('button').textContent = NS.t('feedback_send');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await NS.api(data.urls.feedback, { method: 'POST', body: { message_id: msg.id, rating: 'not_helpful', comment: input.value } });
                form.remove();
                NS.toast(NS.t('feedback_thanks'), 'success');
            } catch (error) { NS.toast(error.message, 'danger'); }
        });
        container.appendChild(form);
        input.focus();
    }

    function toggleSpeak(msg, btn) {
        if (speakingBtn === btn) {
            Voice.stopSpeaking();
            btn.classList.remove('active');
            speakingBtn = null;
            return;
        }
        speakingBtn?.classList.remove('active');
        speakingBtn = btn;
        btn.classList.add('active');
        Voice.speak(msg.body, msg.language || NS.lang, () => {
            btn.classList.remove('active');
            if (speakingBtn === btn) speakingBtn = null;
        });
    }

    /* ---------------- Polling for agent replies ---------------- */

    async function poll() {
        if (!session.id) return;
        try {
            const result = await NS.api(data.urls.messages + '?session_id=' + session.id + '&after_id=' + lastId);
            const before = lastId;
            result.messages.forEach((m) => appendMessage(m));
            const statusChanged = result.session.status !== session.status || result.session.staff_name !== session.staff_name;
            session = Object.assign(session, result.session);
            if (statusChanged) updateStatus();
            if (lastId > before) {
                scrollToBottom(true);
                const newest = result.messages[result.messages.length - 1];
                if (newest && newest.sender_type === 'staff' && Voice && Voice.prefs.autoSpeak) Voice.speak(newest.body, newest.language || NS.lang);
            }
            if (session.status === 'closed') stopPolling();
        } catch (error) {
            if (error.status === 401 || error.status === 404) stopPolling();
        }
    }
    function startPolling() {
        if (pollTimer || !session.id || session.status === 'closed') return;
        const tick = () => {
            pollTimer = setTimeout(async () => { await poll(); if (pollTimer) tick(); }, document.hidden ? 15000 : 4000);
        };
        tick();
    }
    function stopPolling() { clearTimeout(pollTimer); pollTimer = null; }

    /* ---------------- Composer ---------------- */

    function autoResize() {
        els.input.style.height = 'auto';
        els.input.style.height = Math.min(els.input.scrollHeight, 160) + 'px';
    }
    function updateCounter() {
        els.counter.textContent = els.input.value.length + '/' + data.maxLength;
    }

    els.form.addEventListener('submit', (e) => { e.preventDefault(); send(els.input.value); });
    els.input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(els.input.value); }
    });
    els.input.addEventListener('input', () => { autoResize(); updateCounter(); });
    document.querySelectorAll('[data-ns-suggestion]').forEach((btn) => btn.addEventListener('click', () => {
        const offcanvas = window.bootstrap && bootstrap.Offcanvas.getInstance(document.getElementById('chatSidebar'));
        offcanvas?.hide();
        send(btn.dataset.nsSuggestion);
    }));
    document.querySelectorAll('[data-ns-new-chat]').forEach((btn) => btn.addEventListener('click', newChat));
    els.escalate.addEventListener('click', () => escalate('customer_request'));
    document.querySelector('[data-ns-stop-speaking]')?.addEventListener('click', () => Voice?.stopSpeaking());

    /* ---------------- Voice input & settings ---------------- */

    if (!Voice || !Voice.canListen()) {
        els.mic.classList.add('is-unsupported');
        els.mic.title = NS.t('voice_unsupported');
    }
    els.mic.addEventListener('click', () => {
        if (!Voice || !Voice.canListen()) { NS.toast(NS.t('voice_unsupported'), 'warning'); return; }
        if (Voice.isListening()) { Voice.stopListening(); return; }
        const base = els.input.value ? els.input.value.trim() + ' ' : '';
        const started = Voice.listen(NS.speechLang, {
            onText: (text) => { els.input.value = base + text; autoResize(); updateCounter(); },
            onEnd: (finalText) => {
                els.mic.classList.remove('is-listening');
                els.voiceStatus.textContent = finalText ? NS.t('voice_ready') : defaultHint;
                els.input.focus();
            },
            onError: (code) => {
                els.mic.classList.remove('is-listening');
                els.voiceStatus.textContent = defaultHint;
                if (code !== 'no-speech' && code !== 'aborted') {
                    NS.toast(code === 'not-allowed' || code === 'service-not-allowed' ? NS.t('mic_denied') : NS.t('voice_error'), 'warning');
                }
            },
        });
        if (started) {
            els.mic.classList.add('is-listening');
            els.voiceStatus.textContent = NS.t('listening');
        }
    });

    const voiceSelect = document.querySelector('[data-ns-voice-select]');
    const rate = document.querySelector('[data-ns-voice-rate]');
    const rateLabel = document.querySelector('[data-ns-rate-label]');
    const autoSpeak = document.querySelector('[data-ns-auto-speak]');
    if (Voice && Voice.canSpeak()) {
        Voice.populateSelect(voiceSelect, NS.lang, NS.t('default_voice'));
        voiceSelect.addEventListener('change', () => Voice.setVoice(voiceSelect.value));
        rate.value = Voice.prefs.rate;
        rateLabel.textContent = Number(rate.value).toFixed(1) + '×';
        rate.addEventListener('input', () => { Voice.setRate(Number(rate.value)); rateLabel.textContent = Number(rate.value).toFixed(1) + '×'; });
        autoSpeak.checked = Voice.prefs.autoSpeak;
        autoSpeak.addEventListener('change', () => Voice.setAutoSpeak(autoSpeak.checked));
    } else {
        [voiceSelect, rate, autoSpeak].forEach((el) => { if (el) el.disabled = true; });
        const note = document.querySelector('[data-ns-voice-support]');
        if (note) note.textContent = NS.t('speech_unsupported') + ' ' + note.textContent;
    }

    /* ---------------- Init ---------------- */

    if (data.messages.length) {
        data.messages.forEach((m) => appendMessage(m, false));
    } else {
        renderWelcome();
    }
    updateStatus();
    scrollToBottom(false);
    if (data.prefill) {
        els.input.value = data.prefill;
        autoResize();
        updateCounter();
    }
    els.input.focus();
    startPolling();
})();
