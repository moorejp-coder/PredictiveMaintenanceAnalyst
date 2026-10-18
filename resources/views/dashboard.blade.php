<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Milling Machine Failure Risk Dashboard | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="PRISM shows a failure-risk score and a plain-English reason for one milling machine, using simulated temperature, vibration and run-hour readings.">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @include('partials.brand-head')
    <style>
        :root { --band: var(--prism-risk-low); }
        .wrap { max-width: 1120px; margin: 0 auto; padding: var(--prism-space-6) max(16px, env(safe-area-inset-right)) max(40px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }

        .page-head h1 { margin: 0 0 var(--prism-space-1); font-size: clamp(1.75rem, 1.5rem + 1vw, 2rem); }
        .page-head p { margin: 0; color: var(--prism-muted); max-width: 70ch; }

        .demo {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--prism-space-3) var(--prism-space-6);
            margin: var(--prism-space-6) 0 0; padding: var(--prism-space-4) var(--prism-space-6); background: var(--prism-surface-raised);
            border: 1px solid var(--prism-accent); border-radius: var(--prism-radius-lg);
        }
        .demo h2 { margin: 0; font-size: 1.125rem; }
        .demo p { margin: var(--prism-space-1) 0 0; color: var(--prism-muted); font-size: 0.875rem; }

        .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: var(--prism-space-3) var(--prism-space-4); margin: var(--prism-space-4) 0; }
        .toolbar .label { color: var(--prism-muted); font-size: 0.875rem; font-weight: 500; }
        .seg { display: flex; flex-wrap: wrap; gap: var(--prism-space-2); }
        .spacer { flex: 1; }
        .status { display: inline-flex; align-items: center; gap: var(--prism-space-2); color: var(--prism-muted); font-size: 0.875rem; }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--prism-accent); }
        .dot.paused { background: var(--prism-muted); }
        .dot.error { background: transparent; border: 2px solid var(--prism-muted); }
        .blurb { margin: 0 0 var(--prism-space-4); color: var(--prism-muted); font-size: 0.875rem; min-height: 1.3em; }
        .notice { margin-bottom: var(--prism-space-4); }

        .grid { display: grid; gap: var(--prism-space-4); }
        .row-1 { grid-template-columns: minmax(280px, 360px) 1fr; }
        @media (max-width: 800px) { .row-1 { grid-template-columns: 1fr; } }

        .risk { text-align: center; }
        .risk[data-band="none"] { --band: var(--prism-muted); }
        .risk[data-band="low"] { --band: var(--prism-risk-low); }
        .risk[data-band="elevated"] { --band: var(--prism-risk-elevated); }
        .risk[data-band="high"] { --band: var(--prism-risk-high); border-color: var(--prism-risk-high); }
        .ring { position: relative; width: 168px; height: 168px; margin: var(--prism-space-1) auto var(--prism-space-3); }
        .ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .ring .track { stroke: var(--prism-border); }
        .ring .value { stroke: var(--band); transition: stroke-dashoffset 0.6s ease, stroke 0.3s ease; }
        .ring .num { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .ring .num b { font-family: var(--prism-font-data); font-weight: 600; font-size: 3.5rem; line-height: 1; font-variant-numeric: tabular-nums; }
        .ring .num small { color: var(--prism-muted); font-size: 0.875rem; }
        .chip {
            display: inline-block; padding: 4px 14px; border-radius: var(--prism-radius-sm); font-weight: 600;
            border: 2px solid var(--band); color: var(--band);
        }
        .alert-title { margin: var(--prism-space-3) 0 0; padding: var(--prism-space-2) var(--prism-space-3); text-align: left; font-weight: 600;
            color: var(--prism-risk-high); border-left: 4px solid var(--prism-risk-high); background: var(--prism-surface-raised); border-radius: var(--prism-radius-sm); }
        .alert-title[hidden] { display: none; }
        .action { margin: var(--prism-space-3) 0 0; font-size: 1rem; text-align: left; }
        .disclaimer { margin: var(--prism-space-4) 0 0; padding-top: var(--prism-space-3); border-top: 1px solid var(--prism-border); color: var(--prism-muted); font-size: 0.875rem; text-align: left; }

        .why p { margin: 0 0 var(--prism-space-3); font-size: 1.0625rem; }
        .drivers { display: grid; gap: 10px; margin-top: var(--prism-space-4); }
        .driver { display: grid; grid-template-columns: 130px 1fr 44px; align-items: center; gap: 10px; font-size: 0.9375rem; }
        .driver .bar { height: 10px; background: var(--prism-surface-raised); border: 1px solid var(--prism-border); border-radius: var(--prism-radius-sm); overflow: hidden; }
        .driver .bar i { display: block; height: 100%; width: 0; background: var(--prism-risk-low); transition: width 0.5s ease, background 0.3s ease; }
        .driver .bar i.elevated { background: var(--prism-risk-elevated); }
        .driver .bar i.high { background: var(--prism-risk-high); }
        .driver .stress { text-align: right; font-family: var(--prism-font-data); font-weight: 500; font-variant-numeric: tabular-nums; color: var(--prism-text); }
        .why .hint { color: var(--prism-muted); font-size: 0.875rem; margin: var(--prism-space-4) 0 0; }

        .section-title { margin: var(--prism-space-8) 0 var(--prism-space-1); font-size: 1.25rem; }
        .section-note { margin: 0; color: var(--prism-muted); font-size: 0.875rem; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--prism-space-3); margin-top: var(--prism-space-4); }
        .tile { background: var(--prism-surface); border: 1px solid var(--prism-border); border-radius: var(--prism-radius-lg); padding: 14px 16px; }
        .tile.flag { border-color: var(--prism-risk-elevated); }
        .tile.flag.high { border-color: var(--prism-risk-high); }
        .tile .name { color: var(--prism-muted); font-size: 0.875rem; font-weight: 500; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 8px; }
        .tile .tag { color: var(--prism-risk-elevated); font-weight: 600; font-size: 0.8125rem; }
        .tile.high .tag { color: var(--prism-risk-high); }
        .tile .val { font-family: var(--prism-font-data); font-size: 1.75rem; font-weight: 500; font-variant-numeric: tabular-nums; }
        .tile .val small { font-family: var(--prism-font-ui); font-size: 0.875rem; color: var(--prism-muted); font-weight: 400; margin-left: 4px; }
        .tile .sub { color: var(--prism-muted); font-size: 0.8125rem; min-height: 1.1em; }
        .tile svg { width: 100%; height: 34px; margin-top: 6px; display: block; }
        .tile polyline { fill: none; stroke: var(--prism-accent); stroke-width: 1.8; stroke-linejoin: round; stroke-linecap: round; }
        .tile circle { fill: var(--prism-accent); }

        .chart { margin-top: var(--prism-space-4); }
        .chart svg { width: 100%; height: auto; display: block; }
        .chart text { font-family: var(--prism-font-data); }
        .chart .cap { color: var(--prism-muted); font-size: 0.875rem; margin: 6px 0 0; }

        footer { margin-top: var(--prism-space-6); color: var(--prism-muted); font-size: 0.875rem; display: grid; gap: var(--prism-space-2); }
        footer p { margin: 0; }
        footer strong { color: var(--prism-text); }
        @media (max-width: 480px) {
            .card { padding: var(--prism-space-4); }
            .demo { padding: 14px var(--prism-space-4); }
            .demo .btn { width: 100%; }
            .driver { grid-template-columns: 100px 1fr 40px; gap: var(--prism-space-2); }
            .tile { padding: var(--prism-space-3); }
            .tile .val { font-size: 1.5rem; }
            .spacer { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .ring .value, .driver .bar i { transition: none; }
        }
    </style>
</head>
<body data-endpoint="{{ url('/api/readings') }}" data-cycle="{{ $cycle }}">
@include('partials.skip-link')
@include('partials.app-header', ['active' => 'dashboard', 'machine' => true])
<div class="wrap">
    <main id="main" tabindex="-1">
    <div class="page-head">
        <h1>Machine Risk Analysis</h1>
        <p>Simulated readings for one milling machine. Choose a situation to see the predicted failure risk and the reason for it.</p>
    </div>

    <section class="demo" aria-labelledby="demo-title">
        <div>
            <h2 id="demo-title">Worn tool demo</h2>
            <p>Run the worn tool situation. Watch the predicted risk score climb and read the plain-English reason.</p>
        </div>
        <button type="button" class="btn btn--primary" id="cta-run">Run the failure demo</button>
    </section>

    <div class="toolbar">
        <span class="label" id="scenario-label">Simulated situation</span>
        <div class="seg" role="group" aria-labelledby="scenario-label">
            @foreach ($scenarios as $key => $scenario)
                <button type="button" class="btn" data-scenario="{{ $key }}" data-blurb="{{ $scenario['blurb'] }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $scenario['label'] }}</button>
            @endforeach
        </div>
        <span class="spacer"></span>
        <span class="badge">SIMULATED DATA</span>
        <span class="status"><i class="dot" id="dot"></i><span id="status-text">Starting</span></span>
        <button type="button" class="btn" id="pause">Pause</button>
    </div>
    <p class="blurb" id="blurb"></p>

    <div class="notice" id="error-notice" role="status" hidden>
        <strong>Unable to Complete Analysis</strong>
        <p>Check the connection and try again. PRISM retries automatically.</p>
    </div>

    <div class="grid row-1">
        <section class="card risk" id="risk" data-band="none" aria-labelledby="risk-title">
            <h2 id="risk-title">Failure risk score</h2>
            <div class="ring">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                    <circle class="track" cx="60" cy="60" r="52" fill="none" stroke-width="10"></circle>
                    <circle class="value" id="ring" cx="60" cy="60" r="52" fill="none" stroke-width="10" stroke-linecap="round" stroke-dasharray="326.73" stroke-dashoffset="326.73"></circle>
                </svg>
                <div class="num"><b id="score">--</b><small>out of 100</small></div>
            </div>
            <span class="chip" id="band">No Assessment Yet</span>
            <p class="alert-title" id="alert-title" hidden>High Predicted Failure Risk</p>
            <p class="action" id="action">Waiting for the first simulated reading.</p>
            <p class="disclaimer">PRISM supports technician decisions. Confirm findings through inspection.</p>
        </section>

        <section class="card why" aria-labelledby="why-title">
            <h2 id="why-title">Why this score</h2>
            <p id="why">Analyzing readings...</p>
            <div class="drivers" id="drivers" role="group" aria-label="Stress on each failure mode"></div>
            <p class="hint">Each bar shows how close the readings are to the limit for that failure mode. 100 means the limit is crossed. The score is the highest bar.</p>
        </section>
    </div>

    <h2 class="section-title" id="sensors-title">Sensor Readings</h2>
    <p class="section-note">Simulated values. Temperatures show in °C with the kelvin value beneath.</p>
    <div class="tiles" id="tiles" role="group" aria-labelledby="sensors-title"></div>

    <section class="card chart" aria-labelledby="chart-title">
        <h2 id="chart-title">Risk score over the last 30 readings</h2>
        <svg id="chart" viewBox="0 0 640 170" role="img" aria-label="Line chart of the risk score for the last 30 readings. Waiting for the first reading." aria-describedby="cycle"></svg>
        <p class="cap" id="cycle">Reading 0 of {{ $cycle }}. The demo restarts after reading {{ $cycle }}, as if the tool were changed.</p>
    </section>


    <div class="sr-only" role="status" id="sr-status"></div>
    <div class="sr-only" role="alert" id="sr-alert"></div>
    </main>

    <footer>
        <p><strong>How the score works.</strong> PRISM runs six checks: tool wear, heat buildup, power draw, overstrain, vibration and machine run hours. The score is the highest result, from 0 to 100. The first four checks were tested on the 10,000 AI4I records (18 random-failure records left out, because no reading predicts them): none of the 7,448 Low records failed, 2.1% of the 1,889 Elevated records failed, and 45.0% of the 645 High records failed.</p>
        <p><strong>Data.</strong> Every reading on this page is simulated. Baselines and limits for temperature, speed, torque and tool wear come from the AI4I 2020 Predictive Maintenance Dataset (Matzka, 2020, UCI Machine Learning Repository, CC BY 4.0). AI4I has no vibration or machine run hours. Those two readings are invented for this demo and their checks are not tested on real failures. The vibration limits (1.4, 2.8 and 4.5 mm/s RMS) are the ISO 10816-3 Group 2 zone boundaries. The 8,000 hour overhaul interval is a placeholder.</p>
        <p><strong>Safety.</strong> PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern.</p>
        <p><strong>About PRISM.</strong> Predictive Real-time Infrastructure Sensor Monitoring. Machine Risk, Made Clear.</p>
        <p><strong>Next.</strong> More machines, live sensor feeds and alerts are on the roadmap.</p>
        <p><a href="{{ url('/terms') }}">Terms and Conditions</a> &middot; <a href="{{ url('/privacy') }}">Privacy Policy</a> &middot; <a href="{{ url('/faq') }}">FAQ</a></p>
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
        srAlert: $('sr-alert'), alertTitle: $('alert-title'), errorNotice: $('error-notice'),
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
            row.innerHTML = '<span></span><div class="bar"><i></i></div><span class="stress"><span class="n">0</span><span class="sr-only"></span></span>';
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

    let chartWidth = 640;
    function measureChart() {
        const w = Math.round(el.chart.getBoundingClientRect().width);
        chartWidth = Math.max(260, Math.min(640, w || 640));
        el.chart.setAttribute('viewBox', '0 0 ' + chartWidth + ' 170');
    }

    function chartSvg(scores) {
        const W = chartWidth, H = 170, L = 34, R = 10, T = 10, B = 14;
        const x = (i) => L + (i * (W - L - R)) / (MAX_POINTS - 1);
        const y = (v) => T + (1 - v / 100) * (H - T - B);
        let out = '';
        out += '<rect x="' + L + '" y="' + y(100) + '" width="' + (W - L - R) + '" height="' + (y(70) - y(100)) + '" fill="#F87171" fill-opacity="0.12"></rect>';
        out += '<rect x="' + L + '" y="' + y(70) + '" width="' + (W - L - R) + '" height="' + (y(40) - y(70)) + '" fill="#FBBF24" fill-opacity="0.10"></rect>';
        out += '<rect x="' + L + '" y="' + y(40) + '" width="' + (W - L - R) + '" height="' + (y(0) - y(40)) + '" fill="#34D399" fill-opacity="0.08"></rect>';
        [[0, '0'], [40, '40'], [70, '70'], [100, '100']].forEach((t) => {
            out += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(t[0]) + '" y2="' + y(t[0]) + '" stroke="#26355A" stroke-width="1"></line>';
            out += '<text x="' + (L - 6) + '" y="' + (y(t[0]) + 4) + '" text-anchor="end" font-size="12" fill="#A3B3D1">' + t[1] + '</text>';
        });
        const pts = scores.map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1));
        if (scores.length > 1) {
            out += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="#8AB4F8" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"></polyline>';
        }
        const i = scores.length - 1;
        out += '<circle cx="' + x(i).toFixed(1) + '" cy="' + y(scores[i]).toFixed(1) + '" r="4" fill="#8AB4F8"></circle>';
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

    function render() {
        const latest = state.history[state.history.length - 1];
        if (!latest) { return; }
        const risk = latest.risk;

        el.risk.dataset.band = risk.band;
        el.score.textContent = risk.score;
        el.ring.style.strokeDashoffset = (RING * (1 - risk.score / 100)).toFixed(2);
        el.band.textContent = risk.band_label + ' risk';
        el.alertTitle.hidden = risk.band !== 'high';
        el.action.textContent = risk.action;
        el.why.textContent = risk.explanation;

        risk.drivers.forEach((d) => {
            const row = $('driver-' + d.key);
            row.children[0].textContent = d.label;
            const bar = row.querySelector('i');
            bar.style.width = d.stress + '%';
            bar.className = bandOf(d.stress) === 'low' ? '' : bandOf(d.stress);
            row.querySelector('.stress .n').textContent = d.stress;
            row.querySelector('.stress .sr-only').textContent = ' out of 100, ' + bandOf(d.stress);
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

        const scores = state.history.map((h) => h.risk.score);
        measureChart();
        el.chart.innerHTML = chartSvg(scores);
        el.chart.setAttribute('aria-label', chartLabel(scores, risk.band_label));
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
        el.dot.className = 'dot' + (kind === 'live' ? '' : ' ' + kind);
        el.statusText.textContent = kind === 'live' ? 'Simulated feed running' : (kind === 'paused' ? 'Paused' : 'Connection lost, retrying');
        el.errorNotice.hidden = kind !== 'error';
    }

    async function step() {
        if (state.paused || inflight || document.hidden) { return; }
        inflight = true;
        const mine = generation;
        try {
            const url = endpoint + '?scenario=' + encodeURIComponent(state.scenario) + '&tick=' + state.tick;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
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
        setStatus(state.paused ? 'paused' : 'live');
        if (!state.paused) { step(); }
    });

    $('cta-run').addEventListener('click', () => {
        if (state.paused) { el.pause.click(); }
        selectScenario('worn_tool');
        el.risk.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(render, 150);
    });

    // Stop polling while the tab is hidden. Fetch again as soon as it is visible.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { step(); } });

    buildStatic();
    selectScenario(state.scenario);
    window.setInterval(step, INTERVAL_MS);
})();
</script>
@include('partials.cookie-notice')
</body>
</html>
