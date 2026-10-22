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
        .wrap { max-width: 1120px; margin: 0 auto; padding: var(--prism-space-4) max(16px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }

        .page-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--prism-space-2) var(--prism-space-6); }
        .page-head h1 { margin: 0 0 var(--prism-space-1); font-size: clamp(1.375rem, 1.25rem + 0.6vw, 1.625rem); }
        .page-head p { margin: 0; color: var(--prism-muted); max-width: 70ch; font-size: 0.9375rem; }

        .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: var(--prism-space-3) var(--prism-space-4); margin: var(--prism-space-3) 0 var(--prism-space-2); }
        .toolbar .label { color: var(--prism-muted); font-size: 0.875rem; font-weight: 500; }
        .seg { display: flex; flex-wrap: wrap; gap: var(--prism-space-2); }
        .spacer { flex: 1; }
        .status { display: inline-flex; align-items: center; gap: var(--prism-space-2); color: var(--prism-muted); font-size: 0.875rem; }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--prism-accent); }
        .dot.paused { background: var(--prism-muted); }
        .dot.error { background: transparent; border: 2px solid var(--prism-muted); }
        .blurb { margin: 0 0 var(--prism-space-3); color: var(--prism-muted); font-size: 0.875rem; min-height: 1.3em; }
        .notice { margin-bottom: var(--prism-space-3); }

        .grid { display: grid; gap: var(--prism-space-3); }
        .row-1 { grid-template-columns: minmax(280px, 360px) 1fr; }
        @media (max-width: 800px) { .row-1 { grid-template-columns: 1fr; } }

        .risk { text-align: center; padding: var(--prism-space-4); }
        .risk[data-band="none"] { --band: var(--prism-muted); }
        .risk[data-band="low"] { --band: var(--prism-risk-low); }
        .risk[data-band="elevated"] { --band: var(--prism-risk-elevated); }
        .risk[data-band="high"] { --band: var(--prism-risk-high); border-color: var(--prism-risk-high); }
        .ring { position: relative; width: 128px; height: 128px; margin: 0 auto var(--prism-space-2); }
        .ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .ring .track { stroke: var(--prism-border); }
        .ring .value { stroke: var(--band); transition: stroke-dashoffset 0.6s ease, stroke 0.3s ease; }
        .ring .num { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .ring .num b { font-family: var(--prism-font-data); font-weight: 600; font-size: 2.75rem; line-height: 1; font-variant-numeric: tabular-nums; }
        .ring .num small { color: var(--prism-muted); font-size: 0.875rem; }
        .chip {
            display: inline-block; padding: 4px 14px; border-radius: var(--prism-radius-sm); font-weight: 600;
            border: 2px solid var(--band); color: var(--band);
        }
        .estimate { margin: var(--prism-space-2) 0 0; font-size: 0.875rem; color: var(--prism-muted); }
        .estimate strong { color: inherit; font-family: var(--prism-font-data); }
        .alert-title { margin: var(--prism-space-2) 0 0; padding: var(--prism-space-2) var(--prism-space-3); text-align: left; font-weight: 600;
            color: var(--prism-risk-high); border-left: 4px solid var(--prism-risk-high); background: var(--prism-surface-raised); border-radius: var(--prism-radius-sm); }
        /* Hidden but still in the layout, so the card keeps one height when the band changes. */
        .alert-title[hidden] { display: block; visibility: hidden; }
        .action { margin: var(--prism-space-2) 0 0; font-size: 0.9375rem; text-align: left; }
        .disclaimer { margin: var(--prism-space-3) 0 0; padding-top: var(--prism-space-2); border-top: 1px solid var(--prism-border); color: var(--prism-muted); font-size: 0.8125rem; text-align: left; }

        .why { padding: var(--prism-space-4); }
        .why p { margin: 0 0 var(--prism-space-2); font-size: 1rem; }
        /* Room for three lines, so the bars below do not move when the text changes. */
        .why #why { min-height: 4.65em; }
        .drivers { display: grid; grid-template-columns: 1fr 1fr; gap: 8px var(--prism-space-6); margin-top: var(--prism-space-3); }
        @media (max-width: 480px) { .drivers { grid-template-columns: 1fr; } }
        .driver { display: grid; grid-template-columns: 110px 1fr 36px; align-items: center; gap: 8px; font-size: 0.875rem; }
        .driver .bar { height: 10px; background: var(--prism-surface-raised); border: 1px solid var(--prism-border); border-radius: var(--prism-radius-sm); overflow: hidden; }
        .driver .bar i { display: block; height: 100%; width: 0; background: var(--prism-risk-low); transition: width 0.5s ease, background 0.3s ease; }
        .driver .bar i.elevated { background: var(--prism-risk-elevated); }
        .driver .bar i.high { background: var(--prism-risk-high); }
        .driver .bar i.context { background: var(--prism-muted); }
        .driver .stress { text-align: right; font-family: var(--prism-font-data); font-weight: 500; font-variant-numeric: tabular-nums; color: var(--prism-text); }
        .why .hint { color: var(--prism-muted); font-size: 0.8125rem; margin: var(--prism-space-3) 0 0; }

        .section-title { margin: var(--prism-space-4) 0 var(--prism-space-1); font-size: 1.0625rem; }
        .section-note { margin: 0; color: var(--prism-muted); font-size: 0.875rem; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: var(--prism-space-2); margin-top: var(--prism-space-2); }
        .tile { background: var(--prism-surface); border: 1px solid var(--prism-border); border-radius: var(--prism-radius-lg); padding: 8px 12px; }
        .tile.flag { border-color: var(--prism-risk-elevated); }
        .tile.flag.high { border-color: var(--prism-risk-high); }
        .tile .name { color: var(--prism-muted); font-size: 0.875rem; font-weight: 500; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 8px; }
        .tile .tag { color: var(--prism-risk-elevated); font-weight: 600; font-size: 0.8125rem; }
        .tile.high .tag { color: var(--prism-risk-high); }
        .tile .val { font-family: var(--prism-font-data); font-size: 1.375rem; font-weight: 500; font-variant-numeric: tabular-nums; }
        .tile .val small { font-family: var(--prism-font-ui); font-size: 0.875rem; color: var(--prism-muted); font-weight: 400; margin-left: 4px; }
        .tile .sub { color: var(--prism-muted); font-size: 0.75rem; min-height: 1.1em; }
        .tile svg { width: 100%; height: 24px; margin-top: 2px; display: block; }
        .tile polyline { fill: none; stroke: var(--prism-accent); stroke-width: 1.8; stroke-linejoin: round; stroke-linecap: round; }
        .tile circle { fill: var(--prism-accent); }

        .row-2 { grid-template-columns: 1fr 1fr; margin-top: var(--prism-space-3); align-items: stretch; }
        @media (max-width: 800px) { .row-2 { grid-template-columns: 1fr; } }
        /* Same height as the chart card beside it. Content sits in the middle. */
        .row-2 .ai { margin-top: 0; align-self: stretch; display: flex; flex-direction: column; justify-content: center; }
        .chart { padding: var(--prism-space-4); margin-top: var(--prism-space-3); }
        .row-2 .chart { margin-top: 0; align-self: stretch; }
        .chart svg { width: 100%; height: auto; display: block; }
        .chart text { font-family: var(--prism-font-data); }
        .chart .cap { color: var(--prism-muted); font-size: 0.8125rem; margin: 4px 0 0; }

        .override { margin-top: var(--prism-space-3); text-align: left; }
        .override[hidden] { display: block; visibility: hidden; }
        .override .note { margin: var(--prism-space-1) 0 0; color: var(--prism-muted); font-size: 0.8125rem; }
        .override .logged { margin: var(--prism-space-2) 0 0; font-size: 0.875rem; overflow-wrap: anywhere; }
        .ov-modal { width: min(560px, calc(100vw - 32px)); max-height: calc(100vh - 48px); padding: 0; border: 2px solid var(--prism-risk-high); border-radius: 12px; background: var(--prism-surface-raised, var(--prism-surface, #fff)); color: inherit; overflow: auto; }
        .ov-modal::backdrop { background: rgba(0, 0, 0, 0.6); }
        .ov-inner { padding: 20px 24px 24px; }
        .ov-inner h2 { margin: 0 0 var(--prism-space-2); font-size: 1.25rem; }
        .ov-inner p { margin: 0 0 var(--prism-space-3); }
        .ov-inner [hidden] { display: none; }
        .ov-field { display: grid; gap: 4px; margin-bottom: var(--prism-space-3); }
        .ov-field label { font-weight: 600; font-size: 0.9375rem; }
        .ov-field input, .ov-field textarea { width: 100%; box-sizing: border-box; padding: 10px 12px; font: inherit; color: var(--prism-text); background: var(--prism-surface); border: 1px solid var(--prism-border); border-radius: var(--prism-radius-sm); }
        .ov-field textarea { min-height: 96px; resize: vertical; }
        .ov-field .help { color: var(--prism-muted); font-size: 0.8125rem; }
        .ov-error { color: var(--prism-risk-high); font-weight: 600; margin: 0 0 var(--prism-space-3); }
        .ov-actions { display: flex; flex-wrap: wrap; gap: var(--prism-space-2); justify-content: flex-end; }

        .confidence { margin: var(--prism-space-2) 0 0; font-size: 0.8125rem; color: var(--prism-muted); text-align: left; }
        .confidence strong { color: var(--prism-text); }
        .chart-data { margin-top: var(--prism-space-2); }
        .chart-data > summary { cursor: pointer; min-height: 44px; display: flex; align-items: center; color: var(--prism-accent); font-size: 0.875rem; }
        .chart-data .table-scroll { max-height: 240px; overflow: auto; border: 1px solid var(--prism-border); border-radius: var(--prism-radius-sm); }
        .chart-data table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .chart-data th, .chart-data td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--prism-border); font-variant-numeric: tabular-nums; }
        .chart-data th { position: sticky; top: 0; background: var(--prism-surface-raised); }
        .fold > summary { cursor: pointer; }
        .section-title { display: list-item; }
        footer { margin-top: var(--prism-space-4); color: var(--prism-muted); font-size: 0.875rem; display: grid; gap: var(--prism-space-2); }
        footer p { margin: 0; }
        footer strong { color: var(--prism-text); }
        @media (max-width: 480px) {
            .card { padding: var(--prism-space-4); }
            .driver { grid-template-columns: 100px 1fr 40px; gap: var(--prism-space-2); }
            .tile { padding: var(--prism-space-3); }
            .tile .val { font-size: 1.25rem; }
            .spacer { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .ring .value, .driver .bar i { transition: none; }
        }
    </style>
</head>
<body data-endpoint="{{ url('/api/readings') }}" data-overrides="{{ url('/api/overrides') }}" data-cycle="{{ $cycle }}">
@include('partials.skip-link')
@include('partials.app-header', ['active' => 'dashboard', 'machine' => true])
<div class="wrap">
    <main id="main" tabindex="-1">
    <div class="page-head">
        <h1>Machine Risk Analysis</h1>
        <button type="button" class="btn" id="guide-open" aria-haspopup="dialog">Open the guide</button>
    </div>

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
            <p class="estimate" id="estimate" hidden></p>
            <div class="override" id="override" hidden>
                <button type="button" class="btn" id="override-open" aria-haspopup="dialog">Keep running anyway</button>
                <p class="note">If you keep the machine running, PRISM records your name, the time, and your reason.</p>
                <p class="logged" id="override-logged" role="status"></p>
            </div>
        </section>

        <section class="card why" aria-labelledby="why-title">
            <h2 id="why-title">Why this score</h2>
            <p id="why">Analyzing readings...</p>
            <div class="drivers" id="drivers" role="group" aria-label="Stress on each failure mode"></div>
            <p class="hint">The score is the highest of the six colored bars. The gray rotational speed bar is context only and is not scored. It shows how close speed is to the {{ number_format(config('prism.heat.speed_limit_rpm')) }} rpm heat limit.</p>
            @php($hb = config('prism.backtest.high'))
            <p class="confidence"><strong>How often a High score is right.</strong> On the AI4I test data, {{ number_format($hb['failed']) }} of {{ number_format($hb['total']) }} High readings ({{ number_format($hb['failed'] / $hb['total'] * 100, 1) }}%) were real failures. Some High scores are false alarms. This describes the test data and is not a forecast for this machine. <a href="{{ url('/ai#limits') }}">Limits</a></p>
            <p class="disclaimer">PRISM supports technician decisions. Confirm findings through inspection.</p>
        </section>
    </div>

    <div class="grid row-2">
    @include('partials.ai-panel')
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
    </div>

    <details class="fold" id="fold-sensors" open>
        <summary class="section-title" id="sensors-title">Sensor Readings</summary>
        <p class="section-note">Simulated values. Temperatures show in °C with the kelvin value beneath.</p>
        <div class="tiles" id="tiles" role="group" aria-labelledby="sensors-title"></div>
    </details>


    

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
