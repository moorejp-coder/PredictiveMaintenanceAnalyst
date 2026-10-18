<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'PRISM') }} FAQ</title>
    <meta name="description" content="Plain answers on what PRISM does, what PRISM does not do, who owns PRISM, and what PRISM does not promise.">
    <style>
        :root { --bg:#0b1220; --panel:#111a2e; --line:#26355a; --text:#e8eefb; --muted:#a3b3d1; --accent:#38bdf8;
                --ok:#34d399; --partial:#fbbf24; --none:#f87171; }
        * { box-sizing: border-box; }
        html { color-scheme: dark; scroll-behavior: smooth; }
        body { margin:0; background:var(--bg); color:var(--text); line-height:1.6;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 56px; }
        h1 { margin: 0 0 4px; font-size: 1.6rem; letter-spacing: 0.04em; }
        h2 { margin: 36px 0 12px; font-size: 1.1rem; padding-top: 16px; border-top: 1px solid var(--line); }
        p, li { color: var(--muted); }
        li { margin-bottom: 6px; }
        strong { color: var(--text); }
        a { color: var(--accent); }
        code { color: var(--text); font-size: 0.9em; }
        .meta { color: var(--muted); font-size: 0.875rem; margin: 0 0 16px; }
        .summary { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 4px 18px 12px; }
        .safety { background: var(--panel); border: 1px solid var(--accent); border-radius: 10px; padding: 12px 18px; margin: 16px 0; }
        .safety p { margin: 0; color: var(--text); }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 0.9rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; color: var(--muted); }
        th { color: var(--text); }
        .tag { display: inline-block; font-size: 0.75rem; font-weight: 600; padding: 1px 8px; border-radius: 999px; border: 1px solid; white-space: nowrap; }
        .tag.ok { color: var(--ok); border-color: var(--ok); }
        .tag.partial { color: var(--partial); border-color: var(--partial); }
        .tag.none { color: var(--none); border-color: var(--none); }
        details { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; margin: 8px 0; }
        summary { cursor: pointer; padding: 12px 16px; font-weight: 600; color: var(--text); list-style: none; display: flex; gap: 10px; align-items: baseline; }
        summary::-webkit-details-marker { display: none; }
        summary::before { content: "+"; color: var(--accent); font-weight: 700; width: 1ch; flex: none; }
        details[open] > summary::before { content: "-"; }
        summary:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .answer { padding: 0 16px 12px 40px; }
        .answer p { margin: 8px 0; }
        .src { font-size: 0.8125rem; }
        .tools { display: flex; gap: 8px; margin: 16px 0 0; }
        button { background: var(--panel); color: var(--text); border: 1px solid var(--line); border-radius: 8px; padding: 6px 12px; font: inherit; font-size: 0.875rem; cursor: pointer; }
        button:hover { border-color: var(--accent); }
    </style>
</head>
<body>
<div class="wrap">
    <p><a href="{{ url('/') }}">Back to the dashboard</a></p>
    <h1>PRISM FAQ</h1>
    <p class="meta">Updated October 18, 2026</p>

    <div class="summary">
        <h2 style="border:0;margin-top:12px">Read this first</h2>
        <ul>
            <li>PRISM is a student demo. Every sensor reading on the dashboard is simulated.</li>
            <li>Each answer states what PRISM does today, what PRISM does not do, and where the rule lives.</li>
            <li>Items marked <strong>Not built</strong> are gaps. They are not promises.</li>
        </ul>
    </div>

    <div class="safety">
        <p><strong>Safety.</strong> PRISM supports a qualified technician and does not replace an inspection. Stop the machine and call a qualified maintenance professional for any safety concern.</p>
    </div>

    <h2 style="border:0;margin-top:24px">Status at a glance</h2>
    <table>
        <tr><th>Area</th><th>Status</th><th>Short answer</th></tr>
        <tr><td><a href="#scope">1. In-scope definition</a></td><td><span class="tag ok">Defined</span></td><td>One simulated milling machine. Six checks. One score from 0 to 100.</td></tr>
        <tr><td><a href="#limits">2. Out-of-scope control</a></td><td><span class="tag ok">Enforced by design</span></td><td>PRISM takes no actions and writes no data. Every sentence comes from a fixed template.</td></tr>
        <tr><td><a href="#escalation">3. Escalation rule</a></td><td><span class="tag partial">Partly built</span></td><td>High and Elevated scores point you to a technician. No "unsure" state exists yet.</td></tr>
        <tr><td><a href="#ownership">4. Ownership and maintenance</a></td><td><span class="tag ok">Defined</span></td><td>James Moore owns, runs and maintains PRISM.</td></tr>
        <tr><td><a href="#lifecycle">5. Lifecycle</a></td><td><span class="tag partial">Partly defined</span></td><td>Contest demo. Not ready for real equipment.</td></tr>
        <tr><td><a href="#sla">6. SLA posture</a></td><td><span class="tag none">No SLA</span></td><td>Best effort. No uptime, support or response commitments.</td></tr>
    </table>

    <div class="tools">
        <button type="button" id="expand-all">Expand all</button>
        <button type="button" id="collapse-all">Collapse all</button>
    </div>

    <h2 id="scope">1. In-scope definition</h2>

    <details>
        <summary>What does PRISM do?</summary>
        <div class="answer">
            <p>PRISM scores failure risk for one simulated milling machine, M-01. PRISM runs six checks: tool wear, heat buildup, power draw, overstrain, vibration and machine run hours. Each check gives a stress value from 0 to 1. The score is the highest stress, shown from 0 to 100. PRISM then shows a band, a plain-English reason and one fixed action.</p>
            <table>
                <tr><th>Score</th><th>Band</th><th>Action shown</th></tr>
                <tr><td>0 to 39</td><td>Low</td><td>No action needed. Keep monitoring.</td></tr>
                <tr><td>40 to 69</td><td>Elevated</td><td>Schedule an inspection at the next stop.</td></tr>
                <tr><td>70 to 100</td><td>High</td><td>Stop the machine and inspect it now.</td></tr>
            </table>
            <p class="src">Source: <code>app/Services/RiskScorer.php</code>.</p>
        </div>
    </details>

    <details>
        <summary>What does PRISM not do?</summary>
        <div class="answer">
            <ul>
                <li><strong>Read real machines.</strong> Every reading is simulated.</li>
                <li><strong>Forecast a failure date.</strong> The AI4I dataset is a snapshot of operating conditions, not a time series.</li>
                <li><strong>Score random failure.</strong> In AI4I, random failures occur with a 0.1 percent probability independent of every parameter, so no reading predicts them.</li>
                <li><strong>Learn from data.</strong> The score is rule-based. No trained model runs today.</li>
                <li><strong>Send alerts, email or messages.</strong> Alerts are on the roadmap only.</li>
                <li><strong>Store data.</strong> PRISM has no accounts, no forms and no database.</li>
                <li><strong>Give engineering, maintenance or safety advice.</strong> See Terms section 4.</li>
            </ul>
            <p class="src">Sources: <code>README.md</code>, dashboard footer, <a href="{{ url('/terms') }}">Terms</a> and <a href="{{ url('/privacy') }}">Privacy Policy</a>.</p>
        </div>
    </details>

    <details>
        <summary>How well do the checks work?</summary>
        <div class="answer">
            <p>The four checks built on AI4I limits (tool wear, heat, power and overstrain) were tested on the 10,000 AI4I records. The 18 random-failure records were left out.</p>
            <table>
                <tr><th>Band</th><th>Records</th><th>Failed</th><th>Failure rate</th></tr>
                <tr><td>Low</td><td>7,448</td><td>0</td><td>0 / 7,448 = 0.0%</td></tr>
                <tr><td>Elevated</td><td>1,889</td><td>40</td><td>40 / 1,889 = 2.1%</td></tr>
                <tr><td>High</td><td>645</td><td>290</td><td>290 / 645 = 45.0%</td></tr>
            </table>
            <p>Check: 7,448 + 1,889 + 645 = 9,982, which equals 10,000 minus 18.</p>
            <p>The same dataset supplied the limits. These figures show fit to AI4I. They do not show performance on real machines. The vibration and run hours checks are not tested on any failures.</p>
            <p class="src">Source: <code>README.md</code> and dashboard footer.</p>
        </div>
    </details>

    <h2 id="limits">2. Out-of-scope risk control</h2>

    <details>
        <summary>Will PRISM take actions or make commitments nobody approved?</summary>
        <div class="answer">
            <p>No. PRISM is not a language-model agent. PRISM generates no free text. Every sentence on the dashboard comes from a fixed template in <code>RiskScorer.php</code>. Only three action lines exist (see the table in section 1).</p>
            <p>PRISM has no tool to act with. PRISM has no write endpoint, no database, no email and no link to a machine. The routes are read-only: <code>/</code>, <code>/api/readings</code>, <code>/privacy</code>, <code>/terms</code> and <code>/faq</code>.</p>
            <p class="src">Sources: <code>routes/web.php</code>, <code>app/Http/Controllers/DashboardController.php</code>.</p>
        </div>
    </details>

    <details>
        <summary>What input does PRISM accept?</summary>
        <div class="answer">
            <p>The readings endpoint accepts two values.</p>
            <ul>
                <li><code>scenario</code> must be one of four names: <code>worn_tool</code>, <code>overheating</code>, <code>overload</code> or <code>healthy</code>.</li>
                <li><code>tick</code> must be a whole number from 0 to 1,000,000.</li>
            </ul>
            <p>Anything else returns an HTTP 422 error. PRISM does not guess at bad input. An automated test covers the unknown-scenario case.</p>
            <p class="src">Sources: <code>DashboardController.php</code>, <code>tests/Feature/DashboardTest.php</code>.</p>
        </div>
    </details>

    <details>
        <summary>What limits the claims PRISM makes?</summary>
        <div class="answer">
            <ul>
                <li>Tool wear alone never scores above 60.</li>
                <li>Machine run hours alone never score above 50.</li>
                <li>A Low score does not mean a machine is safe. A High score does not prove a machine is about to fail (Terms section 4).</li>
                <li>Do not use PRISM to make safety, repair or production decisions about real equipment (Terms summary).</li>
            </ul>
            <p class="src">Sources: <code>RiskScorer.php</code> (<code>TOOL_WEAR_CAP</code>, <code>RUN_HOURS_CAP</code>), <a href="{{ url('/terms') }}">Terms</a>.</p>
        </div>
    </details>

    <details>
        <summary>Who decides what PRISM is allowed to do?</summary>
        <div class="answer">
            <p>James Moore owns the code and decides what ships. Changes happen in the GitHub repository <code>moorejp-coder/PredictiveMaintenanceAnalyst</code>. New data collection needs a Privacy Policy update first (Privacy section 10).</p>
        </div>
    </details>

    <h2 id="escalation">3. Escalation rule</h2>

    <details>
        <summary>What does PRISM do when readings look risky?</summary>
        <div class="answer">
            <p>PRISM sends you to a person. A High score says to stop the machine and inspect it now. An Elevated score says to schedule an inspection at the next stop. The dashboard footer adds: stop the machine and call a qualified maintenance professional for any safety concern.</p>
        </div>
    </details>

    <details>
        <summary>What does PRISM do when PRISM is unsure? <span class="tag partial">Not built</span></summary>
        <div class="answer">
            <p>PRISM has no confidence score and no "unsure" state. Every valid input gets a score. Known gaps:</p>
            <ul>
                <li>A Low score reads "No action needed" even for failure types PRISM does not check, such as random failure.</li>
                <li>The vibration and run hours limits are demo values, and both checks affect the score.</li>
            </ul>
            <p>Until an "unsure" state exists, treat every score as a prompt for a technician. Treat no score as a decision. The Terms say the same: do not rely on PRISM to decide when to run, stop, repair or service real equipment.</p>
            <p class="src">Sources: <code>RiskScorer.php</code>, <a href="{{ url('/terms') }}">Terms</a> section 4.</p>
        </div>
    </details>

    <details>
        <summary>Who receives an escalation?</summary>
        <div class="answer">
            <p>A qualified maintenance professional at your site. PRISM has no ticket, page, email or chat route. PRISM shows the instruction on screen and stops there.</p>
        </div>
    </details>

    <h2 id="ownership">4. Ownership and maintenance</h2>

    <details>
        <summary>Who owns PRISM?</summary>
        <div class="answer">
            <p>James Moore built and operates PRISM as a student project for the CUA Busch School AI Vibe Coding Contest, Fall 2026. The Catholic University of America does not operate the site and does not receive data from it.</p>
            <p class="src">Source: <a href="{{ url('/privacy') }}">Privacy Policy</a> section 1.</p>
        </div>
    </details>

    <details>
        <summary>Who maintains PRISM, and how?</summary>
        <div class="answer">
            <p>James Moore maintains PRISM. The code lives in the GitHub repository <code>moorejp-coder/PredictiveMaintenanceAnalyst</code>. The repository holds automated tests for the scoring rules, the sensor simulator and the pages. AWS Elastic Beanstalk (region us-east-2) hosts the site.</p>
            <p class="src">Sources: <code>tests/</code> folder, <a href="{{ url('/privacy') }}">Privacy Policy</a> section 4.</p>
        </div>
    </details>

    <details>
        <summary>What needs regular upkeep?</summary>
        <div class="answer">
            <ul>
                <li><strong>Scoring limits.</strong> The constants in <code>RiskScorer.php</code>. The AI4I limits are fixed. The vibration limits (1.4 and 4.5 mm/s RMS) follow ISO 10816-3 Group 2 zone boundaries. The 8,000 hour overhaul interval is a placeholder.</li>
                <li><strong>Terms and Privacy Policy.</strong> Both carry an effective date of October 18, 2026. Any new data collection means a new Privacy Policy first.</li>
                <li><strong>HTTPS.</strong> Not set up. The live site loads over HTTP.</li>
            </ul>
        </div>
    </details>

    <details>
        <summary>How do I report a problem or ask a question?</summary>
        <div class="answer">
            <p>Contact James Moore. Contact details are in section 11 of the <a href="{{ url('/privacy') }}">Privacy Policy</a>.</p>
        </div>
    </details>

    <h2 id="lifecycle">5. Lifecycle reality</h2>

    <details>
        <summary>How long will PRISM stay online?</summary>
        <div class="answer">
            <p>No guarantee. PRISM is a contest entry. PRISM changes, pauses or shuts down at any time without notice, and the live site might go offline when the contest ends.</p>
            <p class="src">Source: <a href="{{ url('/terms') }}">Terms</a> section 9.</p>
        </div>
    </details>

    <details>
        <summary>Is PRISM ready for real equipment?</summary>
        <div class="answer">
            <p>No. Reasons:</p>
            <ul>
                <li>Every reading is simulated.</li>
                <li>AI4I is synthetic data, built to mirror real milling data. A factory floor did not produce it.</li>
                <li>The vibration and run hours readings are invented for the demo, and their checks are untested.</li>
                <li>The 8,000 hour overhaul interval is a placeholder.</li>
                <li>The limits were checked on the same dataset they came from.</li>
                <li>The site has no HTTPS.</li>
            </ul>
            <p class="src">Sources: <code>README.md</code>, dashboard footer, <a href="{{ url('/terms') }}">Terms</a> section 3.</p>
        </div>
    </details>

    <details>
        <summary>What happens when the limits go stale?</summary>
        <div class="answer">
            <p>PRISM does not retrain, recalibrate or watch for drift. AI4I is a synthetic dataset published in 2020 (Matzka, 2020). Machines in the field are not guaranteed to match AI4I. Changing a limit means editing <code>RiskScorer.php</code> and deploying again.</p>
        </div>
    </details>

    <details>
        <summary>What is on the roadmap?</summary>
        <div class="answer">
            <p>The dashboard footer lists more machines, live sensor feeds and alerts. The scorer class is built to be swapped for a trained model without changing the page. Roadmap items are not commitments. Live feeds and alerts would collect new data, so the Privacy Policy would change first.</p>
            <p class="src">Sources: dashboard footer, <code>RiskScorer.php</code> comments, <a href="{{ url('/privacy') }}">Privacy Policy</a> section 10.</p>
        </div>
    </details>

    <details>
        <summary>What happens to data when PRISM shuts down?</summary>
        <div class="answer">
            <p>PRISM has no database, so no user records exist to move or delete. Server logs stay on the host until the platform rotates them or the server is replaced or shut down.</p>
            <p class="src">Source: <a href="{{ url('/privacy') }}">Privacy Policy</a> sections 2 and 5.</p>
        </div>
    </details>

    <h2 id="sla">6. SLA posture</h2>

    <details>
        <summary>Does PRISM offer a service level agreement? <span class="tag none">No SLA</span></summary>
        <div class="answer">
            <p>No. PRISM offers no uptime target, support hours, response time or accuracy guarantee. PRISM is provided "as is" and "as available," with no promise of accuracy, reliability, security or availability. The total liability of James Moore for any claim is zero dollars, except where the law does not allow that limit.</p>
            <p class="src">Source: <a href="{{ url('/terms') }}">Terms</a> sections 7 and 8.</p>
        </div>
    </details>

    <details>
        <summary>What does PRISM offer instead?</summary>
        <div class="answer">
            <p>Best effort from one person. This site documents no on-call coverage, monitoring, backup or failover plan.</p>
        </div>
    </details>

    <details>
        <summary>Is the connection secure?</summary>
        <div class="answer">
            <p>Not yet. The live site loads over HTTP, which is not encrypted. Do not enter sensitive information. PRISM has no field to ask for any.</p>
            <p class="src">Source: <a href="{{ url('/privacy') }}">Privacy Policy</a> section 6.</p>
        </div>
    </details>

    <details>
        <summary>How much traffic is acceptable?</summary>
        <div class="answer">
            <p>Do not send automated or repeated requests to the readings endpoint. The Terms list this under acceptable use, and PRISM reserves the right to block access for anyone who breaks the rules.</p>
            <p class="src">Source: <a href="{{ url('/terms') }}">Terms</a> section 5.</p>
        </div>
    </details>

    <p style="margin-top:32px"><a href="{{ url('/terms') }}">Terms and Conditions</a> &middot; <a href="{{ url('/privacy') }}">Privacy Policy</a> &middot; <a href="{{ url('/') }}">Dashboard</a></p>
</div>

<script>
    (function () {
        var all = function () { return document.querySelectorAll('details'); };
        document.getElementById('expand-all').addEventListener('click', function () {
            all().forEach(function (d) { d.open = true; });
        });
        document.getElementById('collapse-all').addEventListener('click', function () {
            all().forEach(function (d) { d.open = false; });
        });
    })();
</script>
</body>
</html>
