(function () {
    'use strict';

    const body = document.body;
    const endpoint = body.dataset.endpoint;
    const CYCLE = parseInt(body.dataset.cycle, 10);
    const INTERVAL_MS = 1200;
    const MAX_POINTS = 30;
    const RING = 326.73;

    const SENSORS = [
        { key: 'process_temp_k', label: 'Process temperature', unit: '°C', drivers: ['heat'],
          main: (v) => (v - 273.15).toFixed(1), sub: (v) => v.toFixed(1) + ' K' },
        { key: 'air_temp_k', label: 'Air temperature', unit: '°C', drivers: ['heat'],
          main: (v) => (v - 273.15).toFixed(1), sub: (v) => v.toFixed(1) + ' K' },
        { key: 'vibration_mm_s', label: 'Vibration', unit: 'mm/s', drivers: ['vibration'],
          main: (v) => v.toFixed(2), sub: () => 'RMS velocity' },
        { key: 'run_hours', label: 'Machine run hours', unit: 'h', drivers: ['run_hours'],
          main: (v) => Math.round(v).toLocaleString('en-US'), sub: () => 'since last overhaul' },
        { key: 'rotational_speed_rpm', label: 'Rotational speed', unit: 'rpm', drivers: ['heat'], tag: 'Feeds heat risk',
          main: (v) => Math.round(v).toLocaleString('en-US'), sub: () => '' },
        { key: 'torque_nm', label: 'Torque', unit: 'Nm', drivers: ['power', 'overstrain'],
          main: (v) => v.toFixed(1), sub: () => '' },
        { key: 'tool_wear_min', label: 'Tool wear', unit: 'min', drivers: ['tool_wear', 'overstrain'],
          main: (v) => String(Math.round(v)), sub: () => '' },
    ];

    const $ = (id) => document.getElementById(id);
    const el = {
        risk: $('risk'), ring: $('ring'), score: $('score'), band: $('band'), action: $('action'),
        why: $('why'), drivers: $('drivers'), tiles: $('tiles'), chart: $('chart'), cycle: $('cycle'),
        dot: $('dot'), statusText: $('status-text'), pause: $('pause'), blurb: $('blurb'), sr: $('sr-status'),
        srAlert: $('sr-alert'), alertTitle: $('alert-title'), errorNotice: $('error-notice'),
    };
    const scenarioButtons = Array.from(document.querySelectorAll('[data-scenario]'));

    // Override log: shown only while PRISM says to stop the machine.
    const ov = {
        box: $('override'), open: $('override-open'), logged: $('override-logged'), modal: $('override-modal'),
        form: $('override-form'), summary: $('override-summary'), name: $('override-name'), reason: $('override-reason'),
        error: $('override-error'), submit: $('override-submit'), cancel: $('override-cancel'),
        done: $('override-done'), doneText: $('override-done-text'), close: $('override-close'),
    };
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    let ovTarget = null;

    const state = { scenario: scenarioButtons[0].dataset.scenario, tick: 0, history: [], paused: false, held: false };
    let generation = 0;
    let inflight = false;
    let lastBand = null;

    function bandOf(stress) { return stress >= 70 ? 'high' : (stress >= 40 ? 'elevated' : 'low'); }

    function buildStatic() {
        el.tiles.innerHTML = '';
        SENSORS.forEach((s) => {
            const tile = document.createElement('div');
            tile.className = 'tile';
            tile.id = 'tile-' + s.key;
            tile.innerHTML =
                '<div class="name"><span></span><span class="tag"></span></div>' +
                '<div class="val"><span class="num">-</span><small></small></div>' +
                '<div class="sub"></div>' +
                '<svg viewBox="0 0 120 34" aria-hidden="true"></svg>';
            tile.querySelector('.name span').textContent = s.label;
            tile.querySelector('small').textContent = s.unit;
            el.tiles.appendChild(tile);
        });
        el.drivers.innerHTML = '';
        ['tool_wear', 'heat', 'power', 'overstrain', 'vibration', 'run_hours', 'speed'].forEach((k) => {
            const row = document.createElement('div');
            row.className = 'driver';
            row.id = 'driver-' + k;
            row.innerHTML = '<span aria-hidden="true"></span><div class="bar" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i></i></div><span class="stress" aria-hidden="true"><span class="n">0</span></span>';
            el.drivers.appendChild(row);
        });
    }

    function sparkSvg(values) {
        const w = 120, h = 34, pad = 3;
        const min = Math.min.apply(null, values), max = Math.max.apply(null, values);
        const span = max - min;
        const pts = values.map((v, i) => {
            const x = pad + (i * (w - 2 * pad)) / (MAX_POINTS - 1);
            const y = span === 0 ? h / 2 : h - pad - ((v - min) / span) * (h - 2 * pad);
            return [x, y];
        });
        const last = pts[pts.length - 1];
        return '<polyline points="' + pts.map((p) => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ') + '"></polyline>' +
            '<circle cx="' + last[0].toFixed(1) + '" cy="' + last[1].toFixed(1) + '" r="2.6"></circle>';
    }

    // The chart is drawn at the size it is shown, so the one-screen layout can give it any height.
    let chartWidth = 640;
    let chartHeight = 84;
    function measureChart() {
        const box = el.chart.getBoundingClientRect();
        chartWidth = Math.max(260, Math.min(1040, Math.round(box.width) || 640));
        chartHeight = Math.max(84, Math.min(320, Math.round(box.height) || 84));
        el.chart.setAttribute('viewBox', '0 0 ' + chartWidth + ' ' + chartHeight);
    }

    // Chart colors come from the active theme's CSS tokens.
    function themeColor(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }

    function chartSvg(scores) {
        const W = chartWidth, H = chartHeight, L = 34, R = 10, T = 8, B = 10;
        const cHigh = themeColor('--prism-risk-high'), cElev = themeColor('--prism-risk-elevated'), cLow = themeColor('--prism-risk-low');
        const cGrid = themeColor('--prism-border'), cMuted = themeColor('--prism-muted'), cLine = themeColor('--prism-accent');
        const x = (i) => L + (i * (W - L - R)) / (MAX_POINTS - 1);
        const y = (v) => T + (1 - v / 100) * (H - T - B);
        let out = '';
        out += '<rect x="' + L + '" y="' + y(100) + '" width="' + (W - L - R) + '" height="' + (y(70) - y(100)) + '" fill="' + cHigh + '" fill-opacity="0.12"></rect>';
        out += '<rect x="' + L + '" y="' + y(70) + '" width="' + (W - L - R) + '" height="' + (y(40) - y(70)) + '" fill="' + cElev + '" fill-opacity="0.10"></rect>';
        out += '<rect x="' + L + '" y="' + y(40) + '" width="' + (W - L - R) + '" height="' + (y(0) - y(40)) + '" fill="' + cLow + '" fill-opacity="0.08"></rect>';
        [[0, '0'], [40, '40'], [70, '70'], [100, '100']].forEach((t) => {
            out += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(t[0]) + '" y2="' + y(t[0]) + '" stroke="' + cGrid + '" stroke-width="1"></line>';
            out += '<text x="' + (L - 6) + '" y="' + (y(t[0]) + 4) + '" text-anchor="end" font-size="12" fill="' + cMuted + '">' + t[1] + '</text>';
        });
        const pts = scores.map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1));
        if (scores.length > 1) {
            out += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + cLine + '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"></polyline>';
        }
        const i = scores.length - 1;
        out += '<circle cx="' + x(i).toFixed(1) + '" cy="' + y(scores[i]).toFixed(1) + '" r="4" fill="' + cLine + '"></circle>';
        return out;
    }

    function chartLabel(scores, bandLabel) {
        const last = scores[scores.length - 1];
        const first = scores[0];
        const min = Math.min.apply(null, scores), max = Math.max.apply(null, scores);
        const trend = scores.length < 2 ? 'one reading so far' :
            (last > first ? 'rising' : (last < first ? 'falling' : 'flat'));
        return 'Line chart of the risk score for the last ' + scores.length + ' readings. ' +
            'Latest score ' + last + ' out of 100, ' + bandLabel + ' risk. ' +
            'Range ' + min + ' to ' + max + '. Trend ' + trend + '. ' +
            'Shaded zones: green below 40, amber 40 to 69, red 70 and above.';
    }

    // The chart's data as a table. Filled only while the table is open, so a
    // screen reader user is not interrupted by a rebuild every 1.2 seconds.
    function fillChartTable() {
        const box = $('chart-data');
        if (!box || !box.open) { return; }
        const rows = $('chart-rows');
        rows.textContent = '';
        state.history.slice().reverse().forEach((h) => {
            const tr = document.createElement('tr');
            [String((h.tick % CYCLE) + 1), String(h.risk.score), h.risk.band_label].forEach((text) => {
                const td = document.createElement('td');
                td.textContent = text;
                tr.appendChild(td);
            });
            rows.appendChild(tr);
        });
    }
    $('chart-data').addEventListener('toggle', fillChartTable);

    function render() {
        const latest = state.history[state.history.length - 1];
        if (!latest) { return; }
        const risk = latest.risk;

        el.risk.dataset.band = risk.band;
        el.score.textContent = risk.score;
        el.ring.style.strokeDashoffset = (RING * (1 - risk.score / 100)).toFixed(2);
        el.band.textContent = risk.band_label + ' risk';
        el.alertTitle.hidden = risk.band !== 'high';
        ov.box.hidden = risk.band !== 'high';
        el.action.textContent = risk.action;
        el.why.textContent = risk.explanation;
        const est = latest.estimate;
        const estEl = $('estimate');
        if (est) {
            estEl.replaceChildren();
            estEl.append('Learned estimate: ');
            const b = document.createElement('strong');
            b.textContent = est.percent;
            estEl.append(b, ' chance of failure.');
            if (est.range && est.range_records) {
                estEl.append(' In the AI4I data, ' + est.range_failed.toLocaleString('en-US') + ' of ' + est.range_records.toLocaleString('en-US') + ' readings estimated at ' + est.range + ' failed.');
            }
            estEl.hidden = false;
        }

        risk.drivers.forEach((d) => {
            const row = $('driver-' + d.key);
            row.children[0].textContent = d.label;
            const bar = row.querySelector('i');
            const scored = d.scored !== false;
            bar.style.width = d.stress + '%';
            // A context bar (rotational speed) is gray and never uses a risk color.
            bar.className = !scored ? 'context' : (bandOf(d.stress) === 'low' ? '' : bandOf(d.stress));
            row.title = scored ? '' : 'Context only. Not part of the score.';
            row.querySelector('.stress .n').textContent = d.stress;
            // The bar is a meter for screen readers. The visible label and number are hidden from them.
            const meter = row.querySelector('.bar');
            meter.setAttribute('aria-label', d.label);
            meter.setAttribute('aria-valuenow', d.stress);
            meter.setAttribute('aria-valuetext', scored
                ? d.stress + ' out of 100, ' + bandOf(d.stress)
                : d.stress + ' out of 100, context only, not part of the score');
        });

        SENSORS.forEach((s) => {
            const tile = $('tile-' + s.key);
            const values = state.history.map((h) => h.reading[s.key]);
            const current = values[values.length - 1];
            tile.querySelector('.num').textContent = s.main(current);
            tile.querySelector('.sub').textContent = s.sub(current);
            tile.querySelector('svg').innerHTML = sparkSvg(values);
            const worst = risk.drivers
                .filter((d) => s.drivers.indexOf(d.key) !== -1)
                .reduce((m, d) => Math.max(m, d.stress), 0);
            const b = bandOf(worst);
            tile.className = 'tile' + (b === 'low' ? '' : ' flag ' + b);
            tile.querySelector('.tag').textContent = b === 'low' ? '' : (s.tag || 'Driving the risk');
        });

        const scores = state.history.map((h) => h.risk.score);
        measureChart();
        el.chart.innerHTML = chartSvg(scores);
        el.chart.setAttribute('aria-label', chartLabel(scores, risk.band_label));
        fillChartTable();
        el.cycle.textContent = 'Reading ' + ((latest.tick % CYCLE) + 1) + ' of ' + CYCLE +
            '. The demo restarts after reading ' + CYCLE + ', as if the tool were changed.';

        if (risk.band !== lastBand) {
            lastBand = risk.band;
            // A new high-risk result is announced as an alert. Other changes use the polite status region.
            // Routine re-renders in the same band announce nothing.
            if (risk.band === 'high') {
                el.sr.textContent = '';
                el.srAlert.textContent = 'High predicted failure risk. ' + risk.action;
            } else {
                el.srAlert.textContent = '';
                el.sr.textContent = 'Risk is now ' + risk.band_label + '. ' + risk.action;
            }
        }
    }

    function setStatus(kind) {
        el.dot.className = 'dot' + (kind === 'live' ? '' : ' ' + (kind === 'held' ? 'paused' : kind));
        el.statusText.textContent = kind === 'live' ? 'Simulated feed running'
            : (kind === 'paused' ? 'Paused'
            : (kind === 'held' ? 'Paused while the explanation is open' : 'Connection lost, retrying'));
        el.errorNotice.hidden = kind !== 'error';
    }

    async function step() {
        if (state.paused || state.held || inflight || document.hidden) { return; }
        inflight = true;
        const mine = generation;
        try {
            const url = endpoint + '?scenario=' + encodeURIComponent(state.scenario) + '&tick=' + state.tick;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (res.status === 401) { window.location.reload(); return; } // session ended: the reload lands on the sign-in page
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            const data = await res.json();
            if (mine !== generation) { return; }
            state.history.push(data);
            window.prismLatest = data; // read by the Explain this reading panel
            if (state.history.length > MAX_POINTS) { state.history.shift(); }
            state.tick += 1;
            render();
            setStatus('live');
        } catch (err) {
            if (mine === generation) { setStatus('error'); }
        } finally {
            if (mine === generation) { inflight = false; }
        }
    }

    function selectScenario(key) {
        generation += 1;
        inflight = false;
        lastBand = null;
        ov.logged.textContent = '';
        state.scenario = key;
        state.tick = 0;
        state.history = [];
        scenarioButtons.forEach((b) => {
            const on = b.dataset.scenario === key;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            if (on) { el.blurb.textContent = b.dataset.blurb; }
        });
        step();
    }

    scenarioButtons.forEach((b) => b.addEventListener('click', () => selectScenario(b.dataset.scenario)));

    function ovShowError(message) {
        ov.error.textContent = message;
        ov.error.hidden = false;
    }

    ov.open.addEventListener('click', () => {
        const latest = state.history[state.history.length - 1];
        if (!latest || latest.risk.band !== 'high') { return; }
        // Log the reading the operator is looking at now, not a later one.
        ovTarget = { scenario: latest.scenario, tick: latest.tick };
        ov.summary.textContent = 'Reading ' + ((latest.tick % CYCLE) + 1) + ' of ' + CYCLE + ', risk score ' +
            latest.risk.score + ' out of 100. PRISM says: ' + latest.risk.action;
        ov.error.hidden = true;
        ov.form.hidden = false;
        ov.done.hidden = true;
        ov.modal.showModal();
        ov.name.focus();
    });
    ov.cancel.addEventListener('click', () => ov.modal.close());
    ov.close.addEventListener('click', () => ov.modal.close());
    ov.modal.addEventListener('click', (e) => { if (e.target === ov.modal) { ov.modal.close(); } });
    ov.modal.addEventListener('close', () => { if (!ov.box.hidden) { ov.open.focus(); } });

    ov.form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!ovTarget) { return; }
        const operator = ov.name.value.trim();
        const reason = ov.reason.value.trim();
        if (operator.length < 2) { ovShowError('Enter your name.'); ov.name.focus(); return; }
        if (reason.length < 10) { ovShowError('Give a reason of at least 10 characters.'); ov.reason.focus(); return; }

        ov.error.hidden = true;
        ov.submit.disabled = true;
        ov.submit.setAttribute('aria-busy', 'true');
        try {
            const res = await fetch(body.dataset.overrides, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ operator_name: operator, reason: reason, scenario: ovTarget.scenario, tick: ovTarget.tick }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                let message = 'The override was not saved. Try again, or stop the machine.';
                if (res.status === 422 && data.errors) { message = Object.values(data.errors)[0][0]; }
                else if (res.status === 422 && data.message) { message = data.message; }
                else if (res.status === 429) { message = 'Too many entries. Wait a minute and try again.'; }
                else if (res.status === 419) { message = 'This page expired. Reload it and try again.'; }
                else if (res.status === 401) { message = 'Your session ended. Reload the page and sign in again.'; }
                ovShowError(message);
                return;
            }
            const when = new Date(data.recorded_at).toLocaleString();
            ov.form.hidden = true;
            ov.done.hidden = false;
            ov.doneText.textContent = 'Recorded as entry ' + data.id + ' at ' + when + '. PRISM still says to stop the machine and inspect it.';
            ov.logged.textContent = 'Override recorded: ' + operator + ', ' + when + '.';
            ov.reason.value = '';
            ov.close.focus();
        } catch (err) {
            ovShowError('The override was not saved. Check the connection, or stop the machine.');
        } finally {
            ov.submit.disabled = false;
            ov.submit.removeAttribute('aria-busy');
        }
    });
    el.pause.addEventListener('click', () => {
        state.paused = !state.paused;
        el.pause.textContent = state.paused ? 'Resume' : 'Pause';
        setStatus(state.paused ? 'paused' : 'live');
        if (!state.paused) { step(); }
    });

    // The Explain this reading pop-up freezes the feed, so the answer matches the
    // reading on screen. hold() drops any reading still on its way, so none lands
    // after the pop-up opens. release() resumes unless the visitor paused the feed.
    window.prismFeed = {
        hold() {
            if (state.held) { return; }
            state.held = true;
            generation += 1;
            inflight = false;
            setStatus('held');
        },
        release() {
            if (!state.held) { return; }
            state.held = false;
            setStatus(state.paused ? 'paused' : 'live');
            if (!state.paused) { step(); }
        }
    };

    // Redraw the chart when the theme changes.
    window.addEventListener('prism-theme', () => { if (state.history.length) { render(); } });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(render, 150);
    });

    // Stop polling while the tab is hidden. Fetch again as soon as it is visible.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { step(); } });

    // On phones the sensor tiles and the chart start collapsed to shorten the page.
    const folds = Array.from(document.querySelectorAll('.fold'));
    if (window.matchMedia('(max-width: 480px)').matches) { folds.forEach((f) => f.removeAttribute('open')); }
    folds.forEach((f) => f.addEventListener('toggle', () => { if (f.open) { render(); } }));

    buildStatic();
    selectScenario(state.scenario);
    window.setInterval(step, INTERVAL_MS);
})();
