<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b1220">
    <title>Terms and Conditions | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="The terms for using PRISM, a demo predictive maintenance dashboard that runs on simulated readings.">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <style>
        :root { --bg:#0b1220; --panel:#111a2e; --line:#26355a; --text:#e8eefb; --muted:#a3b3d1; --accent:#38bdf8; }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; color-scheme: dark; }
        body { margin:0; background:var(--bg); color:var(--text); line-height:1.6;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 24px max(16px, env(safe-area-inset-right)) max(56px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
        h1 { margin: 0 0 4px; font-size: 1.6rem; letter-spacing: 0.04em; }
        h2 { margin: 32px 0 8px; font-size: 1.1rem; padding-top: 16px; border-top: 1px solid var(--line); }
        p, li { color: var(--muted); }
        li { margin-bottom: 6px; }
        strong { color: var(--text); }
        a { color: var(--accent); }
        .meta { color: var(--muted); font-size: 0.875rem; margin: 0 0 16px; }
        .summary { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 4px 18px 8px; }
        .safety { background: var(--panel); border: 1px solid var(--accent); border-radius: 10px; padding: 12px 18px; margin: 16px 0; }
        .safety p { margin: 0; color: var(--text); }
    </style>
</head>
<body>
<div class="wrap">
    <p><a href="{{ url('/') }}">Back to the dashboard</a></p>
    <h1>PRISM Terms and Conditions</h1>
    <p class="meta">Effective October 18, 2026</p>

    <div class="summary">
        <h2 style="border:0;margin-top:12px">Summary</h2>
        <ul>
            <li>PRISM is a student demo. Every sensor reading on the dashboard is simulated.</li>
            <li>PRISM supports a qualified technician. It does not replace an inspection.</li>
            <li>Do not use PRISM to make safety, repair or production decisions about real equipment.</li>
            <li>PRISM is provided as is, with no warranty.</li>
            <li>PRISM has no accounts. By using the site you accept these terms.</li>
        </ul>
    </div>

    <div class="safety">
        <p><strong>Safety.</strong> Stop the machine and call a qualified maintenance professional for any safety concern.</p>
    </div>

    <h2>1. Acceptance of these terms</h2>
    <p>By opening or using PRISM, you agree to these terms and to the <a href="{{ url('/privacy') }}">Privacy Policy</a>. If you do not agree, do not use PRISM.</p>

    <h2>2. Who runs PRISM</h2>
    <p>PRISM (Predictive Real-time Infrastructure Sensor Monitoring) is a student project built by James Moore for the CUA Busch School AI Vibe Coding Contest, Fall 2026. James Moore operates it. The Catholic University of America does not operate this site, and these terms do not bind it.</p>

    <h2>3. What PRISM is</h2>
    <p>PRISM is a demonstration of a predictive maintenance dashboard for an industrial milling machine. The dashboard shows a failure-risk score from 0 to 100, a plain-English reason and a set of sensor readings.</p>
    <ul>
        <li><strong>Readings are simulated.</strong> PRISM does not connect to any real machine or sensor.</li>
        <li><strong>The score is rule-based.</strong> It uses limits drawn from the AI4I 2020 Predictive Maintenance Dataset, which is synthetic data.</li>
        <li><strong>Some checks are untested.</strong> AI4I has no vibration or machine run hours column. Those two readings are invented for this demo, and their checks are not tested on real failures.</li>
        <li><strong>PRISM flags risk under current conditions.</strong> It does not forecast a failure date.</li>
    </ul>

    <h2>4. Not professional or safety advice</h2>
    <p>PRISM supports a qualified technician and does not replace an inspection. Nothing on PRISM is engineering, maintenance, safety or professional advice. Do not rely on PRISM to decide when to run, stop, repair or service real equipment. A Low score does not mean a machine is safe. A High score does not prove a machine is about to fail.</p>

    <h2>5. Acceptable use</h2>
    <p>You agree not to:</p>
    <ul>
        <li>Use PRISM for any unlawful purpose.</li>
        <li>Probe, scan or test the site for weaknesses, or try to get around any security measure.</li>
        <li>Overload the site, including with automated or repeated requests to the readings endpoint.</li>
        <li>Copy, scrape or resell the site as a service, or present it as your own work.</li>
        <li>Upload or transmit malicious code.</li>
    </ul>
    <p>PRISM may block access to anyone who breaks these rules.</p>

    <h2>6. Intellectual property</h2>
    <p>The PRISM software, design and text are copyright 2026 James Moore. All rights reserved. These terms give you permission to view and use the site. They give you no license to copy, modify or distribute the software.</p>
    <p>PRISM uses the AI4I 2020 Predictive Maintenance Dataset (Matzka, 2020, UCI Machine Learning Repository), which is licensed under <a href="https://creativecommons.org/licenses/by/4.0/">Creative Commons Attribution 4.0 International (CC BY 4.0)</a>. The dataset keeps its own license. Source: <a href="https://archive.ics.uci.edu/dataset/601/ai4i+2020+predictive+maintenance+dataset">UCI Machine Learning Repository</a>.</p>

    <h2>7. No warranty</h2>
    <p>PRISM is provided "as is" and "as available." James Moore makes no promise that PRISM is accurate, complete, reliable, secure or always available. James Moore disclaims all warranties, express or implied, including warranties of merchantability, fitness for a particular purpose and non-infringement, to the fullest extent the law allows.</p>

    <h2>8. Limitation of liability</h2>
    <p>To the fullest extent the law allows, James Moore is not liable for any loss or damage that comes from your use of PRISM or your inability to use it. This includes equipment damage, lost production, injury, lost profit and any indirect or consequential loss. PRISM is free, and the total liability of James Moore for any claim is zero dollars, except where the law does not allow that limit.</p>

    <h2>9. Availability and changes to PRISM</h2>
    <p>PRISM may change, pause or shut down at any time without notice. The live site is a contest entry and may be taken offline when the contest ends.</p>

    <h2>10. Third-party services and links</h2>
    <p>PRISM runs on Amazon Web Services and cites outside sources. Those services and sites have their own terms. James Moore is not responsible for them.</p>

    <h2>11. Changes to these terms</h2>
    <p>These terms may change. The effective date at the top shows the latest version. If you keep using PRISM after a change, you accept the new terms.</p>

    <h2>12. Governing law</h2>
    <p>The laws of the District of Columbia, United States, govern these terms, without regard to conflict of law rules. Any dispute goes to the courts located in the District of Columbia, unless the law requires otherwise.</p>

    <h2>13. Contact</h2>
    <p>James Moore, [CONTACT EMAIL]</p>
</div>
@include('partials.cookie-notice')
</body>
</html>
