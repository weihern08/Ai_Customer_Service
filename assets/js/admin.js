/**
 * Admin console behaviours: Chart.js dashboards, AI provider test, small form helpers.
 */
(function () {
    'use strict';

    const NS = window.NS;

    /* ---------------- Charts ---------------- */
    const chartData = document.getElementById('nsCharts');
    if (chartData && window.Chart) {
        const configs = JSON.parse(chartData.textContent);
        const palette = ['#6b4eff', '#0fb5a6', '#ff6f59', '#f59e0b', '#0ea5e9', '#16a34a', '#ec4899', '#64748b', '#8b5cf6', '#14b8a6', '#f97316', '#22c55e', '#e11d48', '#475569'];
        const alpha = (hex, a) => {
            const n = parseInt(hex.replace('#', ''), 16);
            return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
        };
        Chart.defaults.font.family = "'Manrope', system-ui, sans-serif";
        Chart.defaults.color = '#5b6b7f';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;

        document.querySelectorAll('[data-ns-chart]').forEach((canvas) => {
            const cfg = configs[canvas.dataset.nsChart];
            if (!cfg) return;
            const isLine = cfg.type === 'line';
            const isDoughnut = cfg.type === 'doughnut';
            const datasets = cfg.series
                ? cfg.series.map((s) => ({
                    label: s.label,
                    data: s.data,
                    borderColor: s.color,
                    backgroundColor: isLine ? alpha(s.color, 0.12) : s.color,
                    fill: isLine,
                    tension: 0.35,
                    pointRadius: isLine ? 2 : 0,
                    borderWidth: 2,
                    borderRadius: isLine ? 0 : 6,
                }))
                : [{
                    label: cfg.label || '',
                    data: cfg.data,
                    backgroundColor: cfg.colors || (isDoughnut ? palette : cfg.data.map((_, i) => palette[i % palette.length])),
                    borderWidth: 0,
                    borderRadius: isDoughnut ? 0 : 8,
                    maxBarThickness: 34,
                }];
            const options = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: isDoughnut || !!cfg.series,
                        position: 'bottom',
                        labels: { usePointStyle: true, boxWidth: 8, padding: 14 },
                    },
                },
            };
            if (!isDoughnut) {
                options.scales = {
                    x: { grid: { display: false }, stacked: !!cfg.stacked, ticks: { precision: 0 } },
                    y: { beginAtZero: true, stacked: !!cfg.stacked, ticks: { precision: 0 }, grid: { color: '#eef2f7' } },
                };
                if (cfg.horizontal) {
                    options.indexAxis = 'y';
                    options.scales.x.grid = { color: '#eef2f7' };
                    options.scales.y.grid = { display: false };
                }
            } else {
                options.cutout = '66%';
            }
            new Chart(canvas, { type: cfg.type, data: { labels: cfg.labels, datasets }, options });
        });
    }

    /* ---------------- AI provider connection test ---------------- */
    document.querySelectorAll('[data-ns-ai-test]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const slot = btn.dataset.nsAiTest;
            const box = document.querySelector('[data-ns-ai-test-result="' + slot + '"]');
            box.hidden = false;
            box.className = 'ns-test-result mt-3';
            box.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Contacting provider…';
            btn.disabled = true;
            try {
                const res = await NS.api(btn.dataset.url, { method: 'POST', body: { slot } });
                box.classList.add('ok');
                box.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>Connected to <strong></strong> in ' + res.latency_ms + ' ms. Reply: <code></code>';
                box.querySelector('strong').textContent = res.model;
                box.querySelector('code').textContent = res.reply;
            } catch (err) {
                box.classList.add('fail');
                box.innerHTML = '<i class="fa-solid fa-circle-xmark me-2"></i><span></span>';
                box.querySelector('span').textContent = err.message;
            } finally {
                btn.disabled = false;
            }
        });
    });

    document.querySelector('[data-ns-reset-prompt]')?.addEventListener('click', async () => {
        const target = document.getElementById('ai_system_prompt');
        const def = document.getElementById('nsDefaultPrompt');
        if (target && def && await NS.confirm('Replace the current system prompt with the built-in default? Remember to save.')) {
            target.value = JSON.parse(def.textContent);
        }
    });

    /* ---------------- Range outputs ---------------- */
    document.querySelectorAll('[data-ns-range-out]').forEach((out) => {
        const input = document.getElementById(out.dataset.nsRangeOut);
        input?.addEventListener('input', () => { out.textContent = input.value + '%'; });
    });

    /* ---------------- Escalation queue: quick take-over ---------------- */
    document.querySelectorAll('[data-ns-quick-takeover]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            try {
                await NS.api(btn.dataset.url, { method: 'POST', body: { action: 'takeover', session_id: Number(btn.dataset.nsQuickTakeover) } });
                window.location.href = btn.dataset.redirect;
            } catch (err) {
                NS.toast(err.message, 'danger');
                btn.disabled = false;
            }
        });
    });

    /* ---------------- Ticket reply: internal note styling ---------------- */
    const replyForm = document.querySelector('[data-ns-reply-form]');
    if (replyForm) {
        const hint = replyForm.querySelector('[data-ns-internal-hint]');
        const body = replyForm.querySelector('textarea');
        replyForm.querySelectorAll('input[name="is_internal"]').forEach((radio) => radio.addEventListener('change', () => {
            const internal = replyForm.querySelector('input[name="is_internal"]:checked').value === '1';
            hint.classList.toggle('d-none', !internal);
            body.classList.toggle('ns-internal-input', internal);
            body.placeholder = internal ? 'Write an internal note for the team…' : 'Write your reply…';
        }));
    }
})();
