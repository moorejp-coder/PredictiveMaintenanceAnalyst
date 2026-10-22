<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>How PRISM Uses AI, Limits and Accessibility | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Where PRISM uses AI, what it sends, who decides, what the tests show, the sources PRISM draws on, and how the dashboard supports people who use assistive technology.">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @include('partials.brand-head')
    <style>
        html { scroll-behavior: smooth; }
        body { line-height: 1.6; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 24px max(16px, env(safe-area-inset-right)) max(56px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
        h1 { margin: 0 0 4px; font-size: clamp(1.75rem, 1.5rem + 1vw, 2rem); }
        h2 { margin: 36px 0 12px; font-size: 1.25rem; padding-top: 16px; border-top: 1px solid var(--prism-border); }
        p, li { color: var(--prism-muted); }
        li { margin-bottom: 6px; }
        strong { color: var(--prism-text); }
        a { color: var(--prism-accent); }
        code { color: var(--prism-text); font-size: 0.9em; overflow-wrap: anywhere; }
        .meta { color: var(--prism-muted); font-size: 0.875rem; margin: 0 0 16px; }
        .summary { background: var(--prism-surface); border: 1px solid var(--prism-border); border-radius: 10px; padding: 4px 18px 12px; }
        .safety { background: var(--prism-surface); border: 1px solid var(--prism-accent); border-radius: 10px; padding: 12px 18px; margin: 16px 0; }
        .safety p { margin: 0; color: var(--prism-text); }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 0.9rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--prism-border); vertical-align: top; color: var(--prism-muted); }
        th { color: var(--prism-text); }
        @media (max-width: 560px) {
            table { font-size: 0.8125rem; }
            th, td { padding: 6px 6px; overflow-wrap: anywhere; }
        }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
</head>
<body>
@include('partials.skip-link')
@include('partials.app-header', ['active' => 'ai'])
@php($b = config('prism.backtest'))
<main class="wrap" id="main" tabindex="-1">
    <p><a href="{{ url('/') }}">Back to the dashboard</a></p>
    <h1>How PRISM uses AI</h1>
    <p class="meta">Updated October 20, 2026</p>

    <div class="summary">
        <h2 style="border:0;margin-top:12px">Read this first</h2>
        <ul>
            <li>Fixed rules calculate the risk score. The AI does not.</li>
            <li>The AI runs only when you press "Explain this reading", and only for Elevated or High scores.</li>
            <li>The AI writes a likely cause and a drafted work order. A qualified technician decides what to do.</li>
            <li>If the AI is slow, off or wrong in format, PRISM shows a built-in explanation written by the checks.</li>
            <li>Every sensor reading is simulated. PRISM is a student demo and is not ready for real equipment.</li>
        </ul>
    </div>

    <div class="safety">
        <p><strong>Safety.</strong> PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern.</p>
    </div>

    <h2 id="where">1. Where AI is used and where it is not</h2>
    <table>
        <tr><th scope="col">Part of PRISM</th><th scope="col">Uses AI?</th><th scope="col">Detail</th></tr>
        <tr><td>Risk score and band</td><td>No</td><td>Six rule-based checks. The score is the highest of the six. Limits live in <code>config/prism.php</code>.</td></tr>
        <tr><td>"Why this score" text</td><td>No</td><td>Fixed sentence templates written by the checks.</td></tr>
        <tr><td>Likely cause</td><td>Yes, on request</td><td>Call 1 in <code>app/Services/Ai/Diagnoser.php</code>.</td></tr>
        <tr><td>Drafted work order</td><td>Yes, on request</td><td>Call 2 in <code>app/Services/Ai/WorkOrderStep.php</code>. Code sets the priority from the band. The model does not.</td></tr>
        <tr><td>Override log</td><td>No</td><td>Records who kept a machine running after a stop warning, when and why.</td></tr>
    </table>

    <h2 id="data">2. What the AI receives</h2>
    <ul>
        <li><strong>Sent:</strong> the score, the band, the drivers scored at 40 or above, the fact sentences the checks already wrote, and derived metrics. Call 2 also receives the diagnosis from call 1.</li>
        <li><strong>Never sent:</strong> text typed by a visitor, names, IP addresses, the scoring rules, or the reading history. The server rebuilds the reading from the scenario and reading number.</li>
        <li><strong>Provider and model:</strong> Anthropic's API. The model name is set by the <code>ANTHROPIC_MODEL</code> property. The default in <code>config/services.php</code> is <code>{{ config('services.anthropic.model') }}</code>.</li>
        <li><strong>Limits:</strong> each call has an 8 second timeout and is not retried. The page shows the token counts the API reports.</li>
    </ul>
    <p class="src">Sources: <code>README.md</code> section "AI steps", <a href="{{ url('/privacy') }}">Privacy Policy</a> section 9.</p>

    <h2 id="oversight">3. Human oversight</h2>
    <ul>
        <li>Every AI answer carries an "AI-GENERATED" label and a reminder that a technician decides. When PRISM shows its built-in explanation instead, the label reads "RULE-BASED, NO AI USED".</li>
        <li>The AI cannot change the score, the band or the action. Those come from the rules.</li>
        <li>PRISM has no tool to act on a machine. It sends no alerts and changes nothing outside its own page.</li>
        <li>When PRISM says to stop and an operator keeps running, the override log records a name, a reason and the time. Someone with the password reviews the log.</li>
        <li>You can rate an AI answer "Helpful" or "Wrong". PRISM stores the rating, the step, the source, the scenario, the reading number and the time. It stores no name, no text and no IP address in that row.</li>
    </ul>

    <h2 id="limits">4. Limits and how well the checks work</h2>
    <p>The four checks built on AI4I limits (tool wear, heat, power and overstrain) were tested on the 10,000 AI4I records. 18 random-failure records were left out. Reproduce the figures with <code>php artisan prism:backtest</code>.</p>
    <table>
        <tr><th scope="col">Band</th><th scope="col">Records</th><th scope="col">Failed</th><th scope="col">Share failed</th></tr>
        @foreach (['low' => 'Low', 'elevated' => 'Elevated', 'high' => 'High'] as $key => $label)
            <tr>
                <td>{{ $label }}</td>
                <td>{{ number_format($b[$key]['total']) }}</td>
                <td>{{ number_format($b[$key]['failed']) }}</td>
                <td>{{ number_format($b[$key]['failed'] / $b[$key]['total'] * 100, 1) }}%</td>
            </tr>
        @endforeach
    </table>
    <ul>
        <li>The limits come from AI4I and the test runs on AI4I. The test shows the rules agree with the dataset labels. It does not show how they perform on a real machine.</li>
        <li>AI4I is synthetic data. It was generated to mirror real milling data.</li>
        <li>{{ number_format($b['high']['total'] - $b['high']['failed']) }} of the {{ number_format($b['high']['total']) }} High records did not fail. Some High scores are false alarms.</li>
        <li>9 of the 339 labeled failures have no failure mode flagged in the dataset. No check can explain a failure with no recorded cause.</li>
        <li>Random failure cannot be predicted from any reading. PRISM does not score it.</li>
        <li>AI4I has no vibration or run-hours column. Those two checks are simulated and are not backtested.</li>
        <li>The AI can be wrong. It sees the score and the fact sentences, not the machine.</li>
    </ul>
    <p class="src">Source: <code>README.md</code> section "How the score works".</p>

    <h2 id="fairness">5. Data, privacy and fairness</h2>
    <ul>
        <li>PRISM scores machines. It scores no person and builds no profile of any person.</li>
        <li>The readings are simulated, so PRISM holds no real operational data.</li>
        <li>The only personal data is the name an operator types in the override log. PRISM does not verify it.</li>
        <li>PRISM uses no analytics, advertising or tracking scripts.</li>
        <li>Rate limits protect the AI routes from automated use.</li>
    </ul>
    <p class="src">Source: <a href="{{ url('/privacy') }}">Privacy Policy</a>.</p>

    <h2 id="nist">6. PRISM mapped to the NIST AI Risk Management Framework</h2>
    <p>NIST names four core functions: Govern, Map, Measure and Manage. This table is PRISM's own mapping. NIST has not reviewed or certified PRISM.</p>
    <table>
        <tr><th scope="col">Function</th><th scope="col">What PRISM does</th></tr>
        <tr><td>Govern</td><td>A named owner (James Moore). Terms, Privacy Policy and FAQ. A password on the override log. Rate limits on the AI routes.</td></tr>
        <tr><td>Map</td><td>A defined scope: one simulated milling machine and a maintenance technician as the user. Known limits listed in section 4.</td></tr>
        <tr><td>Measure</td><td>A repeatable backtest. Automated tests in <code>tests/</code>. Token counts shown on each AI answer. Helpful and Wrong ratings.</td></tr>
        <tr><td>Manage</td><td>A built-in fallback when the AI fails. Code sets the priority. The AI cannot change the score. An override log. Unbuilt features listed as not built.</td></tr>
    </table>
    <p class="src">Source: <a href="https://www.nist.gov/itl/ai-risk-management-framework">NIST AI Risk Management Framework</a>, released January 26, 2023.</p>

    <h2 id="sources">7. Fields PRISM draws on</h2>
    <table>
        <tr><th scope="col">Field</th><th scope="col">How PRISM uses it</th><th scope="col">Source</th></tr>
        <tr><td>Data science</td><td>Limits and the backtest come from the AI4I 2020 dataset.</td><td><a href="https://doi.org/10.24432/C5HS5C">Matzka, S. (2020). AI4I 2020 Predictive Maintenance Dataset. UCI Machine Learning Repository.</a></td></tr>
        <tr><td>Mechanical and reliability engineering</td><td>Vibration limits (1.4, 2.8 and 4.5 mm/s RMS) are the ISO 10816-3 Group 2 zone boundaries.</td><td>ISO 10816-3, cited in <code>config/prism.php</code></td></tr>
        <tr><td>Occupational safety</td><td>The "Stop the machine" instruction sits beside lockout/tagout practice. PRISM does not lock out or tag out anything. A qualified person follows the plant's procedure.</td><td><a href="https://osha.gov/control-hazardous-energy/standards">OSHA 29 CFR 1910.147, Control of Hazardous Energy</a></td></tr>
        <tr><td>Human factors and ethics</td><td>The score is one number with a plain reason. A person makes the call, and a keep-running choice leaves a record.</td><td>Design choices in this app</td></tr>
        <tr><td>Law and privacy</td><td>Terms, Privacy Policy and an AI disclosure.</td><td><a href="{{ url('/terms') }}">Terms</a>, <a href="{{ url('/privacy') }}">Privacy</a></td></tr>
    </table>

    <h2 id="accessibility">8. Accessibility</h2>
    <p>The goal is WCAG 2.2 Level AA. PRISM has not had an independent audit, and this page does not claim conformance. On October 20, 2026, Lighthouse gave the live pages the author tested, including the dashboard and this page, an accessibility score of 100 in desktop and mobile modes and in both the light and dark themes. The same day, the author walked the dashboard with VoiceOver on macOS and with the keyboard only, in Chrome, and found no issues.</p>
    <p><strong>Built in today:</strong></p>
    <ul>
        <li>A skip link and a main landmark on every page.</li>
        <li>A visible focus outline on every control.</li>
        <li>Risk bands shown in words (Low, Elevated, High), not by color alone.</li>
        <li>Each stress bar is a labeled meter with a value and a band, for screen readers.</li>
        <li>The risk chart has a text description and a "View the chart data as a table" control.</li>
        <li>Band changes are announced through a status region. A High score is announced as an alert.</li>
        <li>Pop-ups use the native dialog element, return focus when closed, and close with the Escape key.</li>
        <li>Light and dark themes. PRISM follows your system setting, and a switch in the header overrides it. Your choice is saved in your browser.</li>
        <li>Animations stop when your system asks for reduced motion.</li>
        <li>Pause control for the simulated feed.</li>
        <li>Layouts fit narrow phone screens. At 200% and 400% browser zoom the layout reflows, and the two pop-ups scroll up and down inside the window.</li>
    </ul>
    <p><strong>Known gaps:</strong></p>
    <ul>
        <li>The Lighthouse scores are the author's own runs, not an independent audit. The light theme's contrast ratios were calculated (see docs/BRAND.md).</li>
        <li>No independent audit and no test with people who use assistive technology. Only VoiceOver on macOS was tried. Lighthouse checks only a subset of issues.</li>
    </ul>
    <p>To report a barrier, write to <a href="mailto:moorejp@cua.edu">moorejp@cua.edu</a>.</p>
    <p class="src">Source: <a href="https://www.w3.org/TR/WCAG22/">Web Content Accessibility Guidelines (WCAG) 2.2</a>.</p>

    <p style="margin-top:32px"><a href="{{ url('/faq') }}">FAQ</a> &middot; <a href="{{ url('/terms') }}">Terms and Conditions</a> &middot; <a href="{{ url('/privacy') }}">Privacy Policy</a> &middot; <a href="{{ url('/') }}">Dashboard</a></p>
</main>
@include('partials.cookie-notice')
</body>
</html>
