<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>How PRISM Uses AI, Limits and Accessibility | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Where PRISM uses AI, what it sends, who decides, what the tests show, the sources PRISM draws on, and how the dashboard supports people who use assistive technology.">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @include('partials.brand-head')
    <style nonce="{{ $cspNonce ?? '' }}">
        html { scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
</head>
<body class="doc-page">
@include('partials.skip-link')
@include('partials.app-header', ['active' => 'ai'])
@php($b = config('prism.backtest'))
<main class="wrap" id="main" tabindex="-1">
    <p><a href="{{ url('/') }}">Back to the dashboard</a></p>
    <h1>How PRISM uses AI</h1>
    <p class="meta">Updated {{ config('prism.policy_dates.ai.date') }}</p>

    <div class="summary">
        <h2 style="border:0;margin-top:12px">Read this first</h2>
        <ul>
            <li>Fixed rules calculate the risk score. The AI does not.</li>
            <li>The AI runs only when you press "Explain this reading", and only for Elevated or High scores.</li>
            <li>The AI checks the reading with six read-only tools, then writes a likely cause and a drafted work order. A qualified technician decides what to do.</li>
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
        <tr><td>Learned estimate</td><td>Yes, trained model</td><td>A gradient boosting model trained offline on AI4I (<code>ml/train_model.py</code>) and run in PHP from <code>resources/ml/failure_model.json</code>. It shows a chance of failure beside the score and never changes the score, band or action. The investigator can also read a straight-line projection of this chance a few readings ahead, on simulated data. Test results and caveats are in <code>resources/ml/model_card.json</code>.</td></tr>
        <tr><td>"Why this score" text</td><td>No</td><td>Fixed sentence templates written by the checks.</td></tr>
        <tr><td>Likely cause</td><td>Yes, on request</td><td>Call 1 in <code>app/Services/Ai/Investigator.php</code>. Claude calls up to six read-only tools in <code>app/Services/Ai/ToolBox.php</code>, in at most four rounds. If the investigator fails a check, the older single call in <code>Diagnoser.php</code> runs, then the built-in text.</td></tr>
        <tr><td>Drafted work order</td><td>Yes, on request</td><td>Call 2 in <code>app/Services/Ai/WorkOrderStep.php</code>. Code sets the priority from the band. The model does not.</td></tr>
        <tr><td>Override log</td><td>No</td><td>Records who kept a machine running after a stop warning, when and why.</td></tr>
    </table>

    <h2 id="data">2. What the AI receives</h2>
    <ul>
        <li><strong>Sent in call 1:</strong> the score, the band, the action line, the drivers scored at 40 or above with their stress values, the fact sentences the checks already wrote, and four derived metrics (power, temperature gap, strain percent and vibration). The AI then asks for more through six read-only tools: one check, a sensor trend, a straight-line time to limit, a what-if on a copy of the reading, the trained model's chance of failure, and the backtest figures. Each tool result goes back to the AI. The page lists every tool call under "What the AI looked at".</li>
        <li><strong>Sent in call 2:</strong> the machine ID (M-01), the band, the priority, the action line, the names of the drivers scored at 40 or above, and the cause and checks from call 1. Call 2 does not receive the score, the fact sentences or the metrics.</li>
        <li><strong>Never sent:</strong> text typed by a visitor, names, IP addresses, or the scoring rules. The tools return results, not rules, and a what-if shows only the score a changed value would give. The server rebuilds the reading from the scenario and reading number, so a visitor cannot put words in a tool call.</li>
        <li><strong>Provider and model:</strong> Anthropic's API. The model name is set by the <code>ANTHROPIC_MODEL</code> property. The default in <code>config/services.php</code> is <code>{{ config('services.anthropic.model') }}</code>.</li>
        <li><strong>Limits:</strong> the investigation stops after four rounds or 12 seconds. The work order call has an 8 second timeout. No call is retried after a timeout. The page shows the token counts the API reports.</li>
        <li><strong>Checked in code:</strong> the answer must name the tools it used, and every number in its words must appear in the input or in a tool result. An answer that fails this check is dropped.</li>
    </ul>
    <p class="src">Sources: <code>README.md</code> section "AI steps", <a href="{{ url('/privacy') }}">Privacy Policy</a> section 9.</p>

    <h2 id="oversight">3. Human oversight</h2>
    <ul>
        <li>Every AI answer carries an "AI-GENERATED" label and a reminder that a technician decides. When PRISM shows its built-in explanation instead, the label reads "RULE-BASED, NO AI USED".</li>
        <li>The AI cannot change the score, the band or the action. Those come from the rules.</li>
        <li>PRISM has no tool to act on a machine. The AI's six tools only read, and a what-if scores a copy. PRISM sends no alerts and changes nothing outside its own page.</li>
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
        <li>The projection extends the last 10 readings in a straight line and scores each projected reading with the trained model. A check against the simulator found the projected crossing within 3 readings of the real one in 18 of 25 start readings (<code>php artisan prism:forecast-check</code>). The simulator climbs in straight lines, so this does not show how the projection performs on a real machine.</li>
        <li>On October 22, 2026, the investigator ran once on 38 simulated Elevated or High readings. 35 answers passed the code checks (92.1%). 3 fell back to the built-in text (2 with a number the AI was not given, 1 with an unusable reply). Passing the checks does not show the advice is correct, and no technician graded the answers. The full report is <code>docs/EVAL.md</code>.</li>
    </ul>
    <p class="src">Source: <code>README.md</code> section "How the score works".</p>

    <h2 id="fairness">5. Data, privacy and fairness</h2>
    <ul>
        <li>PRISM scores machines. It scores no person and builds no profile of any person.</li>
        <li>The readings are simulated, so PRISM holds no real operational data.</li>
        <li>@if (config('prism.require_login'))The personal data is the name an operator types in the override log (PRISM does not verify it), the name, email address and password hash of each invited judge, and the IP address in server logs.@else The only personal data is the name an operator types in the override log (PRISM does not verify it) and the IP address in server logs.@endif</li>
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
        <tr><td>Mechanical and reliability engineering</td><td>Vibration limits (1.4, 2.8 and 4.5 mm/s RMS) match the Group 2 rigid-foundation zone boundaries listed for ISO 20816-3, which replaced ISO 10816-3. The standard does not list machine tools, so PRISM treats them as demo values.</td><td>ISO 20816-3:2022, cited in <code>config/prism.php</code></td></tr>
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
