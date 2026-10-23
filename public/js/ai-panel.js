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
            if (a.status === 403 || b.status === 403) {
                status.textContent = 'This is the read-only demo. Ratings are not saved.';
                return;
            }
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
    modal.addEventListener('close', () => {
        if (window.prismFeed) { window.prismFeed.release(); }
        btn.focus();
    });

    async function get(base, latest) {
        const url = base + '?scenario=' + encodeURIComponent(latest.scenario) + '&tick=' + latest.tick;
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 24000);
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
            bad_reply: 'the AI reply failed checks',
            ungrounded: 'the AI answer used a number or a tool it had not been given'
        };
        return 'Rule-based answer, ' + (why[result.reason] || 'AI was not used');
    }

    btn.addEventListener('click', async () => {
        // Freeze the feed first, so the reading explained is the reading on screen.
        if (window.prismFeed) { window.prismFeed.hold(); }
        const latest = window.prismLatest;
        if (!modal.open) { modal.showModal(); }
        if (!latest) { $('ai-status').textContent = 'Wait for the first reading, then try again.'; return; }
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        $('ai-diagnosis').hidden = true;
        $('ai-order').hidden = true;
        $('ai-tested').hidden = true;
        $('ai-trace').hidden = true;
        $('ai-meta').textContent = '';
        $('ai-feedback').hidden = true;
        $('ai-label').hidden = true;
        $('ai-warn').hidden = true;
        rated = null;
        $('ai-status').textContent = 'Checking the readings with PRISM\'s tools...';
        try {
            const d = await get(panel.dataset.diagnosis, latest);
            $('ai-cause').textContent = d.data.cause;
            fill($('ai-checks'), d.data.checks);
            const tested = d.data.tested_fix;
            $('ai-tested').hidden = !(tested && tested.text);
            if (tested && tested.text) { $('ai-tested-text').textContent = tested.text; }
            const trace = Array.isArray(d.trace) ? d.trace : [];
            fill($('ai-trace-list'), trace.map((t) => t.summary));
            $('ai-trace').hidden = trace.length === 0;
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
