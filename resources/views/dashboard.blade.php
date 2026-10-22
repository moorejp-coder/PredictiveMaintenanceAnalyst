<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Milling Machine Failure Risk Dashboard | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="PRISM shows a failure-risk score and a plain-English reason for one milling machine, using simulated temperature, vibration and run-hour readings.">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.brand-head')
    <style nonce="{{ $cspNonce ?? '' }}">
        :root { --band: var(--prism-risk-low); }
        .wrap { max-width: 1120px; margin: 0 auto; padding: var(--prism-space-6) max(16px, env(safe-area-inset-right)) max(40px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }

        .page-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--prism-space-2) var(--prism-space-6); }
        .page-head h1 { margin: 0; font-size: clamp(1.75rem, 1.4rem + 1.2vw, 2.25rem); letter-spacing: -0.02em; }

        .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: var(--prism-space-3) var(--prism-space-4); margin: var(--prism-space-4) 0 var(--prism-space-2); }
        .toolbar .label { color: var(--prism-muted); font-size: 0.875rem; font-weight: 500; }
        .spacer { flex: 1; }
        .status { display: inline-flex; align-items: center; gap: var(--prism-space-2); color: var(--prism-muted); font-size: 0.875rem; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--prism-accent); }
        .dot.paused { background: var(--prism-muted); }
        .dot.error { background: transparent; border: 2px solid var(--prism-muted); }
        .blurb { margin: 0 0 var(--prism-space-4); color: var(--prism-muted); font-size: 0.9375rem; min-height: 1.5em; max-width: 80ch; }
        .notice { margin-bottom: var(--prism-space-3); }

        .grid { display: grid; gap: var(--prism-space-4); }

        /* One instrument panel in two halves: the readout on the raised tone, the reasons beside it. */
        .row-1 { grid-template-columns: minmax(300px, 400px) 1fr; gap: 0; border: 1px solid var(--prism-border); border-radius: var(--prism-radius-lg); overflow: hidden; }
        @media (max-width: 800px) { .row-1 { grid-template-columns: 1fr; } }

        .risk { background: var(--prism-surface-raised); border: 0; border-radius: 0; padding: var(--prism-space-6); text-align: left; }
        .risk[data-band="none"] { --band: var(--prism-muted); }
        .risk[data-band="low"] { --band: var(--prism-risk-low); }
        .risk[data-band="elevated"] { --band: var(--prism-risk-elevated); }
        .risk[data-band="high"] { --band: var(--prism-risk-high); box-shadow: inset 4px 0 0 var(--prism-risk-high); }
        .ring { position: relative; width: 176px; height: 176px; margin: 0 0 var(--prism-space-4); }
        .ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .ring .track { stroke: var(--prism-border); }
        .ring .value { stroke: var(--band); transition: stroke-dashoffset 0.6s ease, stroke 0.3s ease; }
        .ring .mark { stroke: var(--prism-muted); stroke-width: 1.5; }
        .ring .num { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .ring .num b { font-family: var(--prism-font-data); font-weight: 600; font-size: 3.75rem; line-height: 1; letter-spacing: -0.04em; font-variant-numeric: tabular-nums; }
        .ring .num small { margin-top: 2px; color: var(--prism-muted); font-size: 0.8125rem; }
        .chip { display: inline-flex; align-items: center; gap: 10px; padding: 0; border: 0; font-size: 1.1875rem; font-weight: 600; color: var(--band); }
        .chip::before { content: ""; width: 10px; height: 10px; background: var(--band); }
        .estimate { margin: var(--prism-space-2) 0 0; font-size: 0.875rem; color: var(--prism-muted); }
        .estimate strong { color: var(--prism-text); font-family: var(--prism-font-data); }
        .alert-title { margin: var(--prism-space-3) 0 0; font-weight: 600; color: var(--prism-risk-high); }
        /* Hidden but still in the layout, so the panel keeps one height when the band changes. */
        .alert-title[hidden] { display: block; visibility: hidden; }
        .action { margin: var(--prism-space-2) 0 0; font-size: 1rem; }
        .disclaimer { margin: var(--prism-space-4) 0 0; padding-top: var(--prism-space-3); border-top: 1px solid var(--prism-border); color: var(--prism-muted); font-size: 0.8125rem; }

        .why { background: var(--prism-surface); border: 0; border-radius: 0; padding: var(--prism-space-6) var(--prism-space-8); }
        .why #why { margin: 0 0 var(--prism-space-2); font-size: 1.0625rem; }
        /* Room for three lines, so the bars below do not move when the text changes. */
        .why #why { min-height: 4.95em; max-width: 62ch; }
        .drivers { display: grid; grid-template-columns: 1fr 1fr; gap: 10px var(--prism-space-8); margin-top: var(--prism-space-4); }
        @media (max-width: 1000px) { .drivers { grid-template-columns: 1fr; } }
        .driver { display: grid; grid-template-columns: 112px 1fr 2.25ch; align-items: center; gap: 12px; font-size: 0.875rem; }
        /* Notches mark the 40 and 70 band edges on every bar. */
        .driver .bar { position: relative; height: 8px; background: var(--prism-surface-raised); border-radius: 1px; overflow: hidden; }
        .driver .bar::after { content: ""; position: absolute; inset: 0; background: linear-gradient(to right, transparent calc(40% - 1px), var(--prism-surface) 0 calc(40% + 1px), transparent 0 calc(70% - 1px), var(--prism-surface) 0 calc(70% + 1px), transparent 0); }
        .driver .bar i { display: block; height: 100%; width: 0; background: var(--prism-risk-low); transition: width 0.5s ease, background 0.3s ease; }
        .driver .bar i.elevated { background: var(--prism-risk-elevated); }
        .driver .bar i.high { background: var(--prism-risk-high); }
        .driver .bar i.context { background: var(--prism-muted); }
        .driver .stress { text-align: right; font-family: var(--prism-font-data); font-weight: 500; font-variant-numeric: tabular-nums; color: var(--prism-text); }
        .why .hint { color: var(--prism-muted); font-size: 0.8125rem; margin: var(--prism-space-4) 0 0; max-width: 72ch; }

        .section-title { display: list-item; margin: var(--prism-space-8) 0 var(--prism-space-1); font-size: 1rem; }
        .section-note { margin: 0; color: var(--prism-muted); font-size: 0.875rem; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(124px, 1fr)); gap: var(--prism-space-4) var(--prism-space-6); margin-top: var(--prism-space-4); }
        .tile { border-top: 1px solid var(--prism-control); padding-top: var(--prism-space-2); }
        .tile.flag { border-top-color: var(--prism-risk-elevated); box-shadow: inset 0 2px 0 var(--prism-risk-elevated); }
        .tile.flag.high { border-top-color: var(--prism-risk-high); box-shadow: inset 0 2px 0 var(--prism-risk-high); }
        .tile .name { color: var(--prism-muted); font-size: 0.8125rem; font-weight: 500; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0 8px; min-height: 2.6em; align-content: start; }
        .tile .tag { border: 0; padding: 0; color: var(--prism-risk-elevated); }
        .tile.high .tag { color: var(--prism-risk-high); }
        .tile .val { font-family: var(--prism-font-data); font-size: 1.625rem; font-weight: 500; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; line-height: 1.3; }
        .tile .val small { font-family: var(--prism-font-ui); font-size: 0.8125rem; letter-spacing: 0; color: var(--prism-muted); font-weight: 400; margin-left: 4px; }
        .tile .sub { color: var(--prism-muted); font-size: 0.75rem; min-height: 1.2em; }
        .tile svg { width: 100%; height: 28px; margin-top: 4px; display: block; }
        .tile polyline { fill: none; stroke: var(--prism-accent); stroke-width: 1.8; stroke-linejoin: round; stroke-linecap: round; }
        .tile circle { fill: var(--prism-accent); }

        @media (max-width: 800px) { .row-2 { grid-template-columns: 1fr; } }
        .chart { padding: var(--prism-space-6); }
        .chart svg { width: 100%; height: auto; display: block; }
        .chart text { font-family: var(--prism-font-data); }
        .chart .cap { color: var(--prism-muted); font-size: 0.8125rem; margin: 6px 0 0; }

        .override { margin-top: var(--prism-space-4); }
        .override[hidden] { display: block; visibility: hidden; }
        .override .note { margin: var(--prism-space-2) 0 0; color: var(--prism-muted); font-size: 0.8125rem; }
        .override .logged { margin: var(--prism-space-2) 0 0; font-size: 0.875rem; overflow-wrap: anywhere; }
        .ov-modal { width: min(560px, calc(100vw - 32px)); max-height: calc(100vh - 48px); }
        .ov-inner { padding: 24px 28px 28px; }
        .ov-inner h2 { margin: 0 0 var(--prism-space-2); font-size: 1.375rem; }
        .ov-inner p { margin: 0 0 var(--prism-space-4); }
        .ov-inner [hidden] { display: none; }
        .ov-field { display: grid; gap: 4px; margin-bottom: var(--prism-space-4); }
        .ov-field textarea { min-height: 104px; resize: vertical; }
        .ov-field .help { color: var(--prism-muted); font-size: 0.8125rem; }
        .ov-error { color: var(--prism-risk-high); font-weight: 600; margin: 0 0 var(--prism-space-3); }
        .ov-actions { display: flex; flex-wrap: wrap; gap: var(--prism-space-2); justify-content: flex-end; }

        .confidence { margin: var(--prism-space-3) 0 0; font-size: 0.8125rem; color: var(--prism-muted); max-width: 72ch; }
        .confidence strong { color: var(--prism-text); }
        .chart-data { margin-top: var(--prism-space-2); }
        .chart-data > summary { cursor: pointer; min-height: 44px; display: flex; align-items: center; color: var(--prism-accent); font-size: 0.875rem; }
        .chart-data .table-scroll { max-height: 240px; overflow: auto; border: 1px solid var(--prism-border); border-radius: var(--prism-radius-md); }
        .chart-data table { font-size: 0.875rem; }
        .chart-data th, .chart-data td { padding: 6px 12px; }
        .chart-data th { position: sticky; top: 0; background: var(--prism-surface-raised); }
        .fold > summary { cursor: pointer; }
        footer { margin-top: var(--prism-space-12); padding-top: var(--prism-space-4); border-top: 1px solid var(--prism-border); color: var(--prism-muted); font-size: 0.875rem; display: grid; gap: var(--prism-space-2); }
        footer p { margin: 0; max-width: 100ch; }
        footer strong { color: var(--prism-text); }
        @media (max-width: 480px) {
            .card { padding: var(--prism-space-4); }
            .risk, .why { padding: var(--prism-space-4); }
            .driver { grid-template-columns: 100px 1fr 2.25ch; gap: var(--prism-space-2); }
            .tile .val { font-size: 1.375rem; }
            .spacer { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .ring .value, .driver .bar i { transition: none; }
        }

        /* Layout zones. Below the compact breakpoint they stack and the page scrolls as before. */
        .dash { display: grid; gap: var(--prism-space-4); margin-top: var(--prism-space-2); }
        .head-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--prism-space-2) var(--prism-space-3); }
        .blurb { margin: 0; }
        .side { display: grid; grid-template-columns: 1fr 1fr; gap: var(--prism-space-4); }
        @media (max-width: 800px) { .side { grid-template-columns: 1fr; } }
        .side .ai { margin: 0; }
        .section-title .section-note { margin-left: var(--prism-space-3); font-weight: 400; }
        .about > summary { cursor: pointer; min-height: 44px; display: flex; align-items: center; color: var(--prism-accent); font-size: 0.875rem; }
        .about-body p { max-width: 72ch; }

        /* One screen. Needs a window at least 960 wide and 680 tall. The root type size follows window height, so every rem-sized part shrinks together on a short screen. */
        @media (min-width: 960px) and (min-height: 680px) {
            html:has(body.dash-page) { font-size: clamp(13px, calc(0.9vh + 7.5px), 16px); }
            body.dash-page { height: 100dvh; display: flex; flex-direction: column; }
            .dash-page .prism-brand, .dash-page .prism-nav a { min-height: 48px; }
            .dash-page .wrap { flex: 1; min-height: 0; width: 100%; max-width: 1360px; display: flex; flex-direction: column; padding-top: 0.75rem; padding-bottom: 0.5rem; }
            .dash-page main { flex: 1; min-height: 0; display: flex; flex-direction: column; gap: 0.625rem; }
            .top { display: grid; gap: 0.5rem; }
            .page-head h1 { font-size: 1.5rem; }
            .head-actions .btn, .seg .btn { min-height: 2.25rem; padding-top: 4px; padding-bottom: 4px; }
            .toolbar { margin: 0; flex-wrap: nowrap; gap: 0.75rem 1rem; }
            .toolbar .label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
            .blurb { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.875rem; }
            .notice { margin: 0; }

            .dash { flex: 1; min-height: 0; margin-top: 0; grid-template-columns: minmax(0, 1fr) minmax(15rem, 19rem); grid-template-rows: minmax(0, 1fr) auto; gap: 0.625rem 0.75rem; }
            .row-1 { grid-column: 1; grid-row: 1; grid-template-columns: minmax(13.5rem, 16rem) 1fr; min-height: 0; }
            .side { grid-column: 2; grid-row: 1; grid-template-columns: 1fr; grid-template-rows: minmax(0, 1fr) auto; gap: 0.625rem; min-height: 0; }
            .fold { grid-column: 1 / -1; grid-row: 2; }

            .risk { padding: 0.875rem 1rem; }
            .risk .card-title, .risk > h2:first-child, .why > h2:first-child { margin-bottom: 0.5rem; font-size: 0.875rem; }
            .ring { width: 8rem; height: 8rem; margin-bottom: 0.5rem; }
            .ring .num b { font-size: 2.375rem; }
            .ring .num small { font-size: 0.75rem; }
            .chip { font-size: 1.0625rem; }
            .alert-title { margin-top: 0.5rem; font-size: 0.9375rem; }
            .action { font-size: 0.9375rem; margin-top: 0.25rem; }
            .override { margin-top: 0.625rem; }
            .override .btn { min-height: 2.25rem; }
            .override .note { font-size: 0.75rem; margin-top: 0.375rem; }

            .why { padding: 0.875rem 1.25rem; min-width: 0; position: relative; display: flex; flex-direction: column; }
            .why #why { font-size: 1rem; min-height: 0; max-width: none; }
            .estimate { margin: 0.25rem 0 0; font-size: 0.8125rem; }
            .drivers { grid-template-columns: 1fr 1fr; gap: 0.5rem 1.5rem; margin-top: auto; padding-top: 0.75rem; }
            .driver { grid-template-columns: 6.5rem 1fr 2.25ch; gap: 0.5rem; font-size: 0.8125rem; }
            .about { margin-top: 0.5rem; position: relative; }
            .about > summary { min-height: 2rem; }
            .about[open] .about-body { position: absolute; left: 0; right: 0; bottom: 100%; z-index: 5; padding: 0.75rem 1rem; background: var(--prism-surface-raised); border: 1px solid var(--prism-control); border-radius: var(--prism-radius-md); box-shadow: var(--prism-dialog-shadow); }
            .about-body p { margin: 0 0 0.5rem; font-size: 0.8125rem; }
            .about-body p:first-child { margin-top: 0; }
            .about-body p:last-child { margin-bottom: 0; }
            .disclaimer { margin: 0.375rem 0 0; padding-top: 0.5rem; font-size: 0.75rem; }

            .chart { padding: 0.875rem 1rem; display: flex; flex-direction: column; min-height: 0; }
            .chart h2 { margin-bottom: 0.5rem; font-size: 0.875rem; }
            .chart svg { flex: 1 1 0; height: 0; min-height: 3rem; width: 100%; }
            .chart .cap { font-size: 0.75rem; }
            .chart-data > summary { min-height: 2rem; }
            .ai { padding: 0.875rem 1rem; }
            .dash .ai-head { flex-direction: column; align-items: stretch; gap: 0.5rem; }
            .dash .ai-head h2 { font-size: 1.0625rem; }
            .dash .ai-head p { font-size: 0.75rem; margin-top: 0.25rem; max-width: none; }
            .dash .ai-cta { width: 100%; min-height: 2.5rem; font-size: 0.9375rem; }

            .section-title { margin: 0; font-size: 0.875rem; }
            .tiles { grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.5rem 1rem; margin-top: 0.25rem; }
            .tile { padding-top: 0.375rem; }
            .tile .name { min-height: 2.4em; font-size: 0.75rem; flex-direction: column; justify-content: flex-start; }
            .tile .val { font-size: 1.25rem; line-height: 1.25; }
            .tile .sub { font-size: 0.6875rem; min-height: 1.1em; }
            .tile svg { height: 1.5rem; margin-top: 2px; }

            .dash-page footer { margin-top: 0; padding-top: 0.375rem; font-size: 0.75rem; gap: 0; }
        }
    </style>
</head>
<body class="dash-page" data-endpoint="{{ url('/api/readings') }}" data-overrides="{{ url('/api/overrides') }}" data-cycle="{{ $cycle }}">
@include('partials.skip-link')
@include('partials.app-header', ['active' => 'dashboard', 'machine' => true])
<div class="wrap">
    <main id="main" tabindex="-1">
    <div class="top">
        <div class="page-head">
            <h1>Machine Risk Analysis</h1>
            <div class="head-actions">
                <span class="badge">Simulated data</span>
                <span class="status"><i class="dot" id="dot"></i><span id="status-text">Starting</span></span>
                <button type="button" class="btn" id="pause">Pause</button>
                <button type="button" class="btn" id="guide-open" aria-haspopup="dialog">Open the guide</button>
            </div>
        </div>
        <div class="toolbar">
            <span class="label" id="scenario-label">Simulated situation</span>
            <div class="seg" role="group" aria-labelledby="scenario-label">
                @foreach ($scenarios as $key => $scenario)
                    <button type="button" class="btn" data-scenario="{{ $key }}" data-blurb="{{ $scenario['blurb'] }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $scenario['label'] }}</button>
                @endforeach
            </div>
            <p class="blurb" id="blurb"></p>
        </div>
    </div>

    <div class="notice" id="error-notice" role="status" hidden>
        <strong>Unable to Complete Analysis</strong>
        <p>Check the connection and try again. PRISM retries automatically.</p>
    </div>

    <div class="dash">
        <div class="grid row-1">
            <section class="card risk" id="risk" data-band="none" aria-labelledby="risk-title">
                <h2 id="risk-title">Failure risk score</h2>
                <div class="ring">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="track" cx="60" cy="60" r="52" fill="none" stroke-width="5"></circle>
                        <circle class="value" id="ring" cx="60" cy="60" r="52" fill="none" stroke-width="5" stroke-linecap="butt" stroke-dasharray="326.73" stroke-dashoffset="326.73"></circle>
                        <line class="mark" x1="23.6" y1="86.4" x2="12.3" y2="94.7"></line>
                        <line class="mark" x1="46.1" y1="17.2" x2="41.8" y2="3.9"></line>
                    </svg>
                    <div class="num"><b id="score">--</b><small>out of 100</small></div>
                </div>
                <span class="chip" id="band">No Assessment Yet</span>
                <p class="alert-title" id="alert-title" hidden>High Predicted Failure Risk</p>
                <p class="action" id="action">Waiting for the first simulated reading.</p>
                <div class="override" id="override" hidden>
                    <button type="button" class="btn" id="override-open" aria-haspopup="dialog">Keep running anyway</button>
                    <p class="note">If you keep the machine running, PRISM records your name, the time, and your reason.</p>
                    <p class="logged" id="override-logged" role="status"></p>
                </div>
            </section>

            <section class="card why" aria-labelledby="why-title">
                <h2 id="why-title">Why this score</h2>
                <p id="why">Analyzing readings...</p>
                <p class="estimate" id="estimate" hidden></p>
                <div class="drivers" id="drivers" role="group" aria-label="Stress on each failure mode"></div>
                @php($hb = config('prism.backtest.high'))
                <details class="about">
                    <summary>How to read the bars, and how often High is right</summary>
                    <div class="about-body">
                        <p class="hint">The score is the highest of the six colored bars. The gray rotational speed bar is context only and is not scored. It shows how close speed is to the {{ number_format(config('prism.heat.speed_limit_rpm')) }} rpm heat limit.</p>
                        <p class="confidence"><strong>How often a High score is right.</strong> On the AI4I test data, {{ number_format($hb['failed']) }} of {{ number_format($hb['total']) }} High readings ({{ number_format($hb['failed'] / $hb['total'] * 100, 1) }}%) were real failures. Some High scores are false alarms. This describes the test data and is not a forecast for this machine. <a href="{{ url('/ai#limits') }}">Limits</a></p>
                    </div>
                </details>
                <p class="disclaimer">PRISM supports technician decisions. Confirm findings through inspection.</p>
            </section>
        </div>

        <div class="side">
            <section class="card chart" aria-labelledby="chart-title">
                <h2 id="chart-title">Risk score over the last 30 readings</h2>
                <svg id="chart" viewBox="0 0 640 84" role="img" aria-label="Line chart of the risk score for the last 30 readings. Waiting for the first reading." aria-describedby="cycle"></svg>
                <p class="cap" id="cycle">Reading 0 of {{ $cycle }}. The demo restarts after reading {{ $cycle }}, as if the tool were changed.</p>
                <details class="chart-data" id="chart-data">
                    <summary>View the chart data as a table</summary>
                    <div class="table-scroll" tabindex="0" role="region" aria-label="Risk scores for the last 30 readings, newest first">
                        <table>
                            <caption class="sr-only">Risk score and band for each of the last 30 readings, newest first</caption>
                            <thead><tr><th scope="col">Reading</th><th scope="col">Score</th><th scope="col">Band</th></tr></thead>
                            <tbody id="chart-rows"></tbody>
                        </table>
                    </div>
                </details>
            </section>
            @include('partials.ai-panel')
        </div>

        <details class="fold" id="fold-sensors" open>
            <summary class="section-title" id="sensors-title">Sensor readings <span class="section-note">Simulated values. Temperatures show in °C with the kelvin value beneath.</span></summary>
            <div class="tiles" id="tiles" role="group" aria-label="Sensor readings"></div>
        </details>
    </div>

    <dialog class="ov-modal" id="override-modal" aria-labelledby="override-title">
        <div class="ov-inner">
            <h2 id="override-title">Keep the machine running?</h2>
            <p id="override-summary"></p>
            <form id="override-form" novalidate>
                <div class="ov-field">
                    <label for="override-name">Your name</label>
                    <input type="text" id="override-name" name="operator_name" autocomplete="name" maxlength="100" required>
                </div>
                <div class="ov-field">
                    <label for="override-reason">Why are you keeping it running?</label>
                    <textarea id="override-reason" name="reason" maxlength="500" required aria-describedby="override-reason-help"></textarea>
                    <span class="help" id="override-reason-help">At least 10 characters. Up to 500.</span>
                </div>
                <p class="ov-error" id="override-error" role="alert" hidden></p>
                <div class="ov-actions">
                    <button type="button" class="btn" id="override-cancel">Cancel. I will stop the machine.</button>
                    <button type="submit" class="btn btn--primary" id="override-submit">Record and keep running</button>
                </div>
            </form>
            <div id="override-done" hidden>
                <p id="override-done-text" role="status"></p>
                <div class="ov-actions">
                    <button type="button" class="btn btn--primary" id="override-close">Close</button>
                </div>
            </div>
        </div>
    </dialog>

    @include('partials.judge-guide')

    <div class="sr-only" role="status" id="sr-status"></div>
    <div class="sr-only" role="alert" id="sr-alert"></div>
    </main>

    <footer>
        <p><strong>Safety.</strong> PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern. Every reading on this page is simulated. <a href="{{ url('/ai') }}">How PRISM uses AI</a> &middot; <a href="{{ url('/faq') }}">FAQ</a> &middot; <a href="{{ url('/terms') }}">Terms and Conditions</a> &middot; <a href="{{ url('/privacy') }}">Privacy Policy</a></p>
    </footer>
</div>

<script src="{{ asset('js/dashboard.js') }}?v={{ filemtime(public_path('js/dashboard.js')) }}"></script>
@include('partials.cookie-notice')
</body>
</html>
