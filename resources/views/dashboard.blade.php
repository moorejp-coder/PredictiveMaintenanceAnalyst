<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'PRISM') }} Failure Risk Dashboard</title>
    <meta name="description" content="PRISM shows a failure-risk score and a plain-English reason for one milling machine, using simulated temperature, vibration and run-hour readings.">
    <style>
        :root {
            --bg: #0b1220;
            --panel: #111a2e;
            --panel-2: #172340;
            --line: #26355a;
            --text: #e8eefb;
            --muted: #a3b3d1;
            --accent: #38bdf8;
            --low: #34d399;
            --elevated: #fbbf24;
            --high: #f87171;
            --band: var(--low);
        }
        * { box-sizing: border-box; }
        html { color-scheme: dark; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 20px 16px 40px; }

        header.top {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 12px 24px; padding-bottom: 16px; border-bottom: 1px solid var(--line);
        }
        .brand h1 { margin: 0; font-size: 1.5rem; letter-spacing: 0.14em; }
        .brand p { margin: 2px 0 0; color: var(--muted); font-size: 0.8125rem; }
        .machine { text-align: right; }
        .machine strong { display: block; font-size: 1rem; }
        .machine span { color: var(--muted); font-size: 0.8125rem; }
        @media (max-width: 640px) { .machine { text-align: left; } }


        .cta {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 24px;
            margin: 16px 0 0; padding: 16px 20px; background: var(--panel-2);
            border: 1px solid var(--accent); border-radius: 12px;
        }
        .cta h2 { margin: 0; font-size: 1.125rem; }
        .cta p { margin: 4px 0 0; color: var(--muted); font-size: 0.875rem; }
        button.cta-btn {
            background: var(--accent); border-color: var(--accent); color: #04121f;
            font-weight: 700; font-size: 1rem; padding: 10px 22px; min-height: 44px;
        }
        button.cta-btn:hover { background: #7dd3fc; border-color: #7dd3fc; }

        .toolbar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; margin: 16px 0;
        }
        .toolbar .label { color: var(--muted); font-size: 0.8125rem; }
        .seg { display: flex; flex-wrap: wrap; gap: 8px; }
        button {
            font: inherit; color: var(--text); background: var(--panel); border: 1px solid var(--line);
            border-radius: 8px; padding: 8px 14px; cursor: pointer; min-height: 40px;
        }
        button:hover { border-color: var(--accent); }
        button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        button[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: #04121f; font-weight: 600; }
        .spacer { flex: 1; }
        .status { display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-size: 0.8125rem; }
        .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--low); }
        .dot.paused { background: var(--elevated); }
        .dot.error { background: var(--high); }
        .badge {
            border: 1px solid var(--accent); color: var(--accent); border-radius: 999px;
            padding: 2px 10px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em;
        }
        .blurb { margin: 0 0 16px; color: var(--muted); font-size: 0.875rem; min-height: 1.3em; }

        .grid { display: grid; gap: 16px; }
        .row-1 { grid-template-columns: minmax(260px, 340px) 1fr; }
        @media (max-width: 800px) { .row-1 { grid-template-columns: 1fr; } }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        .card h2 { margin: 0 0 12px; font-size: 0.8125rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); font-weight: 600; }

        .risk { text-align: center; }
        .risk[data-band="low"] { --band: var(--low); }
        .risk[data-band="elevated"] { --band: var(--elevated); }
        .risk[data-band="high"] { --band: var(--high); }
        .ring { position: relative; width: 168px; height: 168px; margin: 4px auto 12px; }
        .ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .ring .track { stroke: var(--line); }
        .ring .value { stroke: var(--band); transition: stroke-dashoffset 0.6s ease, stroke 0.3s ease; }
        .ring .num { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .ring .num b { font-size: 3rem; line-height: 1; font-variant-numeric: tabular-nums; }
        .ring .num small { color: var(--muted); font-size: 0.75rem; }
        .chip {
            display: inline-block; padding: 4px 14px; border-radius: 999px; font-weight: 700;
            border: 2px solid var(--band); color: var(--band);
        }
        .action { margin: 12px 0 0; font-size: 0.9375rem; }

        .why p { margin: 0 0 12px; font-size: 1.0625rem; }
        .drivers { display: grid; gap: 10px; margin-top: 16px; }
        .driver { display: grid; grid-template-columns: 120px 1fr 44px; align-items: center; gap: 10px; font-size: 0.875rem; }
        .driver .bar { height: 10px; background: var(--panel-2); border-radius: 999px; overflow: hidden; }
        .driver .bar i { display: block; height: 100%; width: 0; background: var(--low); border-radius: 999px; transition: width 0.5s ease, background 0.3s ease; }
        .driver .bar i.elevated { background: var(--elevated); }
        .driver .bar i.high { background: var(--high); }
        .driver output { text-align: right; font-variant-numeric: tabular-nums; color: var(--muted); }
        .why .hint { color: var(--muted); font-size: 0.8125rem; margin: 18px 0 0; }

        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-top: 16px; }
        .tile { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; }
        .tile.flag { border-color: var(--elevated); }
        .tile.flag.high { border-color: var(--high); }
        .tile .name { color: var(--muted); font-size: 0.8125rem; display: flex; justify-content: space-between; gap: 8px; }
        .tile .tag { color: var(--elevated); font-weight: 600; font-size: 0.75rem; }
        .tile.high .tag { color: var(--high); }
        .tile .val { font-size: 1.75rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .tile .val small { font-size: 0.875rem; color: var(--muted); font-weight: 400; margin-left: 4px; }
        .tile .sub { color: var(--muted); font-size: 0.75rem; min-height: 1.1em; }
        .tile svg { width: 100%; height: 34px; margin-top: 6px; display: block; }
        .tile polyline { fill: none; stroke: var(--accent); stroke-width: 1.8; stroke-linejoin: round; stroke-linecap: round; }
        .tile circle { fill: var(--accent); }

        .chart { margin-top: 16px; }
        .chart svg { width: 100%; height: auto; display: block; }
        .chart .cap { color: var(--muted); font-size: 0.75rem; margin: 6px 0 0; }

        footer { margin-top: 24px; color: var(--muted); font-size: 0.8125rem; display: grid; gap: 8px; }
        footer p { margin: 0; }
        footer strong { color: var(--text); }
        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        @media (prefers-reduced-motion: reduce) {
            .ring .value, .driver .bar i { transition: none; }
        }
    </style>
</head>
<body data-endpoint="{{ url('/api/readings') }}" data-cycle="{{ $cycle }}">
<div class="wrap">
    <header class="top">
        <div class="brand">
            <h1>{{ config('app.name', 'PRISM') }}</h1>
            <p>Predictive Real-time Infrastructure Sensor Monitoring</p>
        </div>
        <div class="machine">
            <strong>Milling Machine M-01</strong>
            <span>Product quality variant L</span>
        </div>
    </header>

    <section class="cta" aria-labelledby="cta-title">
        <div>
            <h2 id="cta-title">See PRISM catch a failure before it happens.</h2>
            <p>Run the worn tool demo. Watch the risk score climb and read the plain-English reason.</p>
        </div>
        <button type="button" class="cta-btn" id="cta-run">Run the failure demo</button>
    </section>

    <div class="toolbar">
        <span class="label" id="scenario-label">Simulated situation</span>
        <div class="seg" role="group" aria-labelledby="scenario-label">
            @foreach ($scenarios as $key => $scenario)
                <button type="button" data-scenario="{{ $key }}" data-blurb="{{ $scenario['blurb'] }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $scenario['label'] }}</button>
            @endforeach
        </div>
        <span class="spacer"></span>
        <span class="badge">SIMULATED DATA</span>
        <span class="status"><i class="dot" id="dot"></i><span id="status-text">Starting</span></span>
        <button type="button" id="pause" aria-pressed="false">Pause</button>
    </div>
    <p class="blurb" id="blurb"></p>

    <div class="grid row-1">
        <section class="card risk" id="risk" data-band="low" aria-labelledby="risk-title">
            <h2 id="risk-title">Failure risk score</h2>
            <div class="ring">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                    <circle class="track" cx="60" cy="60" r="52" fill="none" stroke-width="10"></circle>
                    <circle class="value" id="ring" cx="60" cy="60" r="52" fill="none" stroke-width="10" stroke-linecap="round" stroke-dasharray="326.73" stroke-dashoffset="326.73"></circle>
                </svg>
                <div class="num"><b id="score">0</b><small>out of 100</small></div>
            </div>
            <span class="chip" id="band">Low</span>
            <p class="action" id="action"></p>
        </section>

        <section class="card why" aria-labelledby="why-title">
            <h2 id="why-title">Why this score</h2>
            <p id="why">Waiting for the first reading.</p>
            <div class="drivers" id="drivers" aria-label="Stress on each failure mode"></div>
            <p class="hint">Each bar shows how close the readings are to the limit for that failure mode. 100 means the limit is crossed. The score is the highest bar.</p>
        </section>
    </div>

    <div class="tiles" id="tiles"></div>

    <section class="card chart" aria-labelledby="chart-title">
        <h2 id="chart-title">Risk score over the last 30 readings</h2>
        <svg id="chart" viewBox="0 0 640 170" role="img" aria-label="Line chart of the risk score for the last 30 readings"></svg>
        <p class="cap" id="cycle">Reading 0 of {{ $cycle }}. The demo restarts after reading {{ $cycle }}, as if the tool were changed.</p>
    </section>

    <div class="sr-only" role="status" id="sr-status"></div>

    <footer>
        <p><strong>How the score works.</strong> PRISM runs six checks: tool wear, heat buildup, power draw, overstrain, vibration and machine run hours. The score is the highest result, from 0 to 100. The first four checks were tested on the 10,000 AI4I records (18 random-failure records left out, because no reading predicts them): none of the 7,448 Low records failed, 2.1% of the 1,889 Elevated records failed, and 45.0% of the 645 High records failed.</p>
        <p><strong>Data.</strong> Every reading on this page is simulated. Baselines and limits for temperature, speed, torque and tool wear come from the AI4I 2020 Predictive Maintenance Dataset (Matzka, 2020, UCI Machine Learning Repository, CC BY 4.0). AI4I has no vibration or machine run hours. Those two readings are invented for this demo and their checks are not tested on real failures. The vibration limits (1.4, 2.8 and 4.5 mm/s RMS) are the ISO 10816-3 Group 2 zone boundaries. The 8,000 hour overhaul interval is a placeholder.</p>
        <p><strong>Safety.</strong> PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern.</p>
        <p><strong>Next.</strong> More machines, live sensor feeds and alerts are on the roadmap.</p>
        <p><a href="{{ url('/terms') }}" style="color: var(--accent)">Terms and Conditions</a> &middot; <a href="{{ url('/privacy') }}" style="color: var(--accent)">Privacy Policy</a></p>
    </footer>
</div>

<script>
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
        { key: 'rotational_speed_rpm', label: 'Rotational speed', unit: 'rpm', drivers: ['heat', 'power'],
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
    };
    const scenarioButtons = Array.from(document.querySelectorAll('[data-scenario]'));

    const state = { scenario: scenarioButtons[0].dataset.scenario, tick: 0, history: [], paused: false };
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
        ['tool_wear', 'heat', 'power', 'overstrain', 'vibration', 'run_hours'].forEach((k) => {
            const row = document.createElement('div');
            row.className = 'driver';
            row.id = 'driver-' + k;
            row.innerHTML = '<span></span><div class="bar"><i></i></div><output>0</output>';
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

    function chartSvg(scores) {
        const W = 640, H = 170, L = 34, R = 10, T = 10, B = 14;
        const x = (i) => L + (i * (W - L - R)) / (MAX_POINTS - 1);
        const y = (v) => T + (1 - v / 100) * (H - T - B);
        let out = '';
        out += '<rect x="' + L + '" y="' + y(100) + '" width="' + (W - L - R) + '" height="' + (y(70) - y(100)) + '" fill="#f87171" fill-opacity="0.12"></rect>';
        out += '<rect x="' + L + '" y="' + y(70) + '" width="' + (W - L - R) + '" height="' + (y(40) - y(70)) + '" fill="#fbbf24" fill-opacity="0.10"></rect>';
        out += '<rect x="' + L + '" y="' + y(40) + '" width="' + (W - L - R) + '" height="' + (y(0) - y(40)) + '" fill="#34d399" fill-opacity="0.08"></rect>';
        [[0, '0'], [40, '40'], [70, '70'], [100, '100']].forEach((t) => {
            out += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(t[0]) + '" y2="' + y(t[0]) + '" stroke="#26355a" stroke-width="1"></line>';
            out += '<text x="' + (L - 6) + '" y="' + (y(t[0]) + 4) + '" text-anchor="end" font-size="11" fill="#a3b3d1">' + t[1] + '</text>';
        });
        const pts = scores.map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1));
        if (scores.length > 1) {
            out += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="#38bdf8" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"></polyline>';
        }
        const i = scores.length - 1;
        out += '<circle cx="' + x(i).toFixed(1) + '" cy="' + y(scores[i]).toFixed(1) + '" r="4" fill="#38bdf8"></circle>';
        return out;
    }

    function render() {
        const latest = state.history[state.history.length - 1];
        if (!latest) { return; }
        const risk = latest.risk;

        el.risk.dataset.band = risk.band;
        el.score.textContent = risk.score;
        el.ring.style.strokeDashoffset = (RING * (1 - risk.score / 100)).toFixed(2);
        el.band.textContent = risk.band_label + ' risk';
        el.action.textContent = risk.action;
        el.why.textContent = risk.explanation;

        risk.drivers.forEach((d) => {
            const row = $('driver-' + d.key);
            row.children[0].textContent = d.label;
            const bar = row.querySelector('i');
            bar.style.width = d.stress + '%';
            bar.className = bandOf(d.stress) === 'low' ? '' : bandOf(d.stress);
            row.querySelector('output').textContent = d.stress;
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
            tile.querySelector('.tag').textContent = b === 'low' ? '' : 'Driving the risk';
        });

        el.chart.innerHTML = chartSvg(state.history.map((h) => h.risk.score));
        el.cycle.textContent = 'Reading ' + ((latest.tick % CYCLE) + 1) + ' of ' + CYCLE +
            '. The demo restarts after reading ' + CYCLE + ', as if the tool were changed.';

        if (risk.band !== lastBand) {
            lastBand = risk.band;
            el.sr.textContent = 'Risk is now ' + risk.band_label + '. ' + risk.action;
        }
    }

    function setStatus(kind) {
        el.dot.className = 'dot' + (kind === 'live' ? '' : ' ' + kind);
        el.statusText.textContent = kind === 'live' ? 'Live' : (kind === 'paused' ? 'Paused' : 'Connection lost, retrying');
    }

    async function step() {
        if (state.paused || inflight) { return; }
        inflight = true;
        const mine = generation;
        try {
            const url = endpoint + '?scenario=' + encodeURIComponent(state.scenario) + '&tick=' + state.tick;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            const data = await res.json();
            if (mine !== generation) { return; }
            state.history.push(data);
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
    el.pause.addEventListener('click', () => {
        state.paused = !state.paused;
        el.pause.textContent = state.paused ? 'Resume' : 'Pause';
        el.pause.setAttribute('aria-pressed', state.paused ? 'true' : 'false');
        setStatus(state.paused ? 'paused' : 'live');
        if (!state.paused) { step(); }
    });

    $('cta-run').addEventListener('click', () => {
        if (state.paused) { el.pause.click(); }
        selectScenario('worn_tool');
        el.risk.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    });

    buildStatic();
    selectScenario(state.scenario);
    window.setInterval(step, INTERVAL_MS);
})();
</script>
</body>
</html>
