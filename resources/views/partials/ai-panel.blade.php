{{--
  "Explain this reading" panel. Two small AI calls, run only on a button press.
  Needs window.prismLatest set by the dashboard script to the latest /api/readings
  response (an object with scenario and tick). If the AI is slow or off, the
  server sends back the built-in explanation, so the panel always has an answer.
--}}
<section class="card ai" id="ai-panel" aria-labelledby="ai-title"
         data-diagnosis="{{ url('/api/diagnosis') }}" data-order="{{ url('/api/work-order') }}"
         data-feedback="{{ url('/api/ai-feedback') }}">
    <style>
        .ai { margin-top: 12px; border: 2px solid var(--prism-accent, #8ab4f8); background: var(--prism-surface-raised, var(--prism-surface)); }
        .ai-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 24px; }
        .ai-head h2 { margin: 0; font-size: 1.25rem; }
        .ai-head p { margin: 4px 0 0; }
        .ai-cta { font-size: 1.0625rem; min-height: 52px; padding-left: 28px; padding-right: 28px; }
        @media (max-width: 480px) { .ai-cta { width: 100%; } }
        .ai h3 { font-size: 0.9375rem; margin: 14px 0 4px; }
        .ai ul, .ai ol { margin: 6px 0 0; padding-left: 20px; }
        .ai p { margin: 6px 0 0; }
        .ai .ai-note { color: var(--prism-muted, var(--muted, #a3b3d1)); font-size: 0.8125rem; }
        .ai [hidden] { display: none; }
        .ai-modal { width: min(640px, calc(100vw - 32px)); max-height: calc(100vh - 48px); padding: 0; border: 2px solid var(--prism-accent, #8ab4f8); border-radius: 12px; background: var(--prism-surface-raised, var(--prism-surface, #fff)); color: inherit; overflow: auto; }
        .ai-modal::backdrop { background: rgba(0, 0, 0, 0.6); }
        .ai-modal-inner { padding: 20px 24px 24px; }
        .ai-modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .ai-modal-head h2 { margin: 0; font-size: 1.25rem; }
        .ai-close { min-height: 44px; min-width: 44px; }
        .ai-label { display: inline-block; margin: 8px 0 0; padding: 2px 10px; border: 1px solid var(--prism-accent, #8ab4f8); border-radius: 6px; color: var(--prism-accent, #8ab4f8); font-size: 0.75rem; font-weight: 600; letter-spacing: 0.06em; }
        .ai-warn { margin: 8px 0 0; padding: 8px 12px; border-left: 4px solid var(--prism-risk-elevated, #fbbf24); background: var(--prism-surface, transparent); border-radius: 6px; font-size: 0.875rem; }
        .ai-feedback { margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--prism-border, #26355a); }
        .ai-feedback p { margin: 0 0 8px; font-size: 0.9375rem; }
        .ai-feedback .ai-fb-row { display: flex; flex-wrap: wrap; gap: 8px; }
        .ai-feedback .btn { min-height: 44px; }
    </style>
    <div class="ai-head">
        <div>
            <h2 id="ai-title">Explain this reading</h2>
            <p class="ai-note">Get the likely cause and a drafted work order for the current reading. If the AI is slow or unavailable, PRISM shows its built-in explanation instead. <a href="{{ url('/ai') }}">How PRISM uses AI</a></p>
        </div>
        <button type="button" class="btn btn--primary ai-cta" id="ai-run">Explain this reading</button>
    </div>
    <dialog class="ai-modal" id="ai-modal" aria-labelledby="ai-modal-title">
        <div class="ai-modal-inner">
            <div class="ai-modal-head">
                <h2 id="ai-modal-title">Explain this reading</h2>
                <button type="button" class="btn ai-close" id="ai-close" aria-label="Close">Close</button>
            </div>
            <span class="ai-label" id="ai-label" hidden></span>
            <p class="ai-warn" id="ai-warn" hidden></p>
            <div aria-live="polite">
                <p class="ai-note" id="ai-status"></p>
                <div id="ai-diagnosis" hidden>
                    <h3>Likely cause</h3>
                    <p id="ai-cause"></p>
                    <ul id="ai-checks"></ul>
                </div>
                <div id="ai-order" hidden>
                    <h3 id="ai-order-title"></h3>
                    <p id="ai-priority"></p>
                    <ol id="ai-steps"></ol>
                </div>
                <p class="ai-note" id="ai-meta"></p>
            </div>
            <div class="ai-feedback" id="ai-feedback" hidden>
                <p id="ai-feedback-q">Was this answer correct and useful?</p>
                <div class="ai-fb-row" role="group" aria-labelledby="ai-feedback-q">
                    <button type="button" class="btn" id="ai-fb-helpful">Helpful</button>
                    <button type="button" class="btn" id="ai-fb-wrong">Wrong</button>
                </div>
                <p class="ai-note" id="ai-fb-status" role="status"></p>
            </div>
        </div>
    </dialog>
    <script>
    (function () {
        'use strict';
        const panel = document.getElementById('ai-panel');
        const $ = (id) => document.getElementById(id);
        const btn = $('ai-run');
        const modal = $('ai-modal');

        const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        let rated = null; // { latest, diagnosis, order } for the answer on screen

        // The label says what produced the answer. It never claims AI when PRISM used its built-in text.
        function showLabel(d, w) {
            const usedAi = (r) => r.source === 'ai' || r.source === 'cache';
            const label = $('ai-label');
            const warn = $('ai-warn');
            if (usedAi(d) && usedAi(w)) {
                label.textContent = 'AI-GENERATED';
                warn.textContent = 'AI can be wrong. A qualified technician decides what to do. The score above comes from fixed rules, not from the AI.';
            } else if (!usedAi(d) && !usedAi(w)) {
                label.textContent = 'RULE-BASED, NO AI USED';
                warn.textContent = 'This answer comes from PRISM\'s fixed rules, not from the AI. A qualified technician decides what to do.';
            } else {
                label.textContent = 'PART AI-GENERATED';
                warn.textContent = 'One part of this answer is AI-generated and one part comes from fixed rules. AI can be wrong. A qualified technician decides what to do.';
            }
            label.hidden = false;
            warn.hidden = false;
        }

        function sourceOf(result) {
            return (result.source === 'ai' || result.source === 'cache') ? result.source : 'rules';
        }

        async function sendRating(rating) {
            if (!rated) { return; }
            const status = $('ai-fb-status');
            const buttons = [$('ai-fb-helpful'), $('ai-fb-wrong')];
            buttons.forEach((b) => { b.disabled = true; });
            const post = (step, result) => fetch(panel.dataset.feedback, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ step: step, rating: rating, source: sourceOf(result), scenario: rated.latest.scenario, tick: rated.latest.tick })
            });
            try {
                const [a, b] = await Promise.all([post('diagnosis', rated.diagnosis), post('work_order', rated.order)]);
                if (!a.ok || !b.ok) { throw new Error('HTTP ' + a.status + '/' + b.status); }
                status.textContent = 'Thank you. Your rating is saved. PRISM stores no name or text with it.';
            } catch (err) {
                status.textContent = 'Your rating was not saved. Try again later.';
                buttons.forEach((x) => { x.disabled = false; });
            }
        }

        $('ai-fb-helpful').addEventListener('click', () => sendRating('helpful'));
        $('ai-fb-wrong').addEventListener('click', () => sendRating('wrong'));

        $('ai-close').addEventListener('click', () => modal.close());
        // Click on the dimmed backdrop closes the pop-up.
        modal.addEventListener('click', (e) => { if (e.target === modal) { modal.close(); } });
        modal.addEventListener('close', () => btn.focus());

        async function get(base, latest) {
            const url = base + '?scenario=' + encodeURIComponent(latest.scenario) + '&tick=' + latest.tick;
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 12000);
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' }, signal: ctrl.signal });
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return await res.json();
            } finally {
                clearTimeout(timer);
            }
        }

        function fill(list, items) {
            list.textContent = '';
            items.forEach((text) => {
                const li = document.createElement('li');
                li.textContent = text;
                list.appendChild(li);
            });
        }

        function label(result) {
            if (result.source === 'ai') {
                return 'AI answer, ' + result.tokens.input + ' tokens in, ' + result.tokens.output + ' tokens out';
            }
            if (result.source === 'cache') { return 'AI answer (saved)'; }
            const why = {
                no_key: 'AI is not connected',
                low_risk: 'reading is low risk, so no AI call was made',
                timeout: 'the AI did not answer in time',
                api_error: 'the AI service returned an error',
                bad_reply: 'the AI reply failed checks'
            };
            return 'Rule-based answer, ' + (why[result.reason] || 'AI was not used');
        }

        btn.addEventListener('click', async () => {
            const latest = window.prismLatest;
            if (!modal.open) { modal.showModal(); }
            if (!latest) { $('ai-status').textContent = 'Wait for the first reading, then try again.'; return; }
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            $('ai-diagnosis').hidden = true;
            $('ai-order').hidden = true;
            $('ai-meta').textContent = '';
            $('ai-feedback').hidden = true;
            $('ai-label').hidden = true;
            $('ai-warn').hidden = true;
            rated = null;
            $('ai-status').textContent = 'Reading the numbers...';
            try {
                const d = await get(panel.dataset.diagnosis, latest);
                $('ai-cause').textContent = d.data.cause;
                fill($('ai-checks'), d.data.checks);
                $('ai-diagnosis').hidden = false;

                $('ai-status').textContent = 'Drafting a work order...';
                const w = await get(panel.dataset.order, latest);
                $('ai-order-title').textContent = w.data.title;
                $('ai-priority').textContent = 'Priority: ' + w.data.priority;
                fill($('ai-steps'), w.data.steps);
                $('ai-order').hidden = false;

                $('ai-status').textContent = '';
                $('ai-meta').textContent = 'Likely cause: ' + label(d) + '. Work order: ' + label(w) + '.';
                showLabel(d, w);
                rated = { latest: latest, diagnosis: d, order: w };
                $('ai-fb-status').textContent = '';
                $('ai-fb-helpful').disabled = false;
                $('ai-fb-wrong').disabled = false;
                $('ai-feedback').hidden = false;
            } catch (err) {
                $('ai-status').textContent = 'This step is not available right now. The score and reason above still apply.';
            } finally {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
            }
        });
    })();
    </script>
</section>
