<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'PRISM') }} Privacy Policy</title>
    <meta name="description" content="How PRISM handles data. PRISM has no accounts, no forms and no analytics.">
    <style>
        :root { --bg:#0b1220; --panel:#111a2e; --line:#26355a; --text:#e8eefb; --muted:#a3b3d1; --accent:#38bdf8; }
        * { box-sizing: border-box; }
        html { color-scheme: dark; }
        body { margin:0; background:var(--bg); color:var(--text); line-height:1.6;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 56px; }
        h1 { margin: 0 0 4px; font-size: 1.6rem; letter-spacing: 0.04em; }
        h2 { margin: 32px 0 8px; font-size: 1.1rem; padding-top: 16px; border-top: 1px solid var(--line); }
        p, li { color: var(--muted); }
        li { margin-bottom: 6px; }
        strong { color: var(--text); }
        a { color: var(--accent); }
        .meta { color: var(--muted); font-size: 0.875rem; margin: 0 0 16px; }
        .summary { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 4px 18px 8px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 0.9rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; color: var(--muted); }
        th { color: var(--text); }
    </style>
</head>
<body>
<div class="wrap">
    <p><a href="{{ url('/') }}">Back to the dashboard</a></p>
    <h1>PRISM Privacy Policy</h1>
    <p class="meta">Effective October 18, 2026</p>

    <div class="summary">
        <h2 style="border:0;margin-top:12px">Summary</h2>
        <ul>
            <li>PRISM has no accounts, sign-in, forms or comment boxes.</li>
            <li>You do not enter personal data. Every sensor reading on the dashboard is simulated.</li>
            <li>PRISM uses no analytics, advertising, tracking pixels or third-party scripts.</li>
            <li>PRISM does not sell or share your data.</li>
            <li>The site currently loads over HTTP, which is not encrypted.</li>
        </ul>
    </div>

    <h2>1. Who runs PRISM</h2>
    <p>PRISM (Predictive Real-time Infrastructure Sensor Monitoring) is a student project built by James Moore for the CUA Busch School AI Vibe Coding Contest, Fall 2026. James Moore operates it. The Catholic University of America does not operate this site and does not receive data from it.</p>

    <h2>2. What PRISM collects</h2>
    <p><strong>Information you give PRISM.</strong> None. The dashboard has demo controls that send a scenario name and a reading number to the server so it can return a simulated reading. These values contain no personal information. PRISM does not store them.</p>
    <p><strong>Information collected automatically.</strong></p>
    <table>
        <tr><th>Item</th><th>What it is</th><th>Why</th></tr>
        <tr><td>Server logs</td><td>Your IP address, the date and time, the page requested, your browser type and the response code. The web server and the hosting platform record these when you load a page.</td><td>To run the site, fix errors and protect against abuse.</td></tr>
        <tr><td>Session cookie</td><td>A cookie named <code>prism-session</code>. The framework (Laravel) sets it. It holds a random session ID. It expires after 120 minutes of inactivity.</td><td>Standard framework behavior. PRISM does not use it to identify you or track you.</td></tr>
        <tr><td>Security cookie</td><td>A cookie named <code>XSRF-TOKEN</code>. The framework sets it to guard against forged requests.</td><td>Security.</td></tr>
    </table>
    <p>PRISM has no database. It stores no user records.</p>

    <h2>3. How PRISM uses information</h2>
    <ul>
        <li>To serve the dashboard and return simulated readings.</li>
        <li>To keep the site secure and working.</li>
    </ul>
    <p>PRISM does not use information for advertising, profiling or automated decisions about you.</p>

    <h2>4. Who receives information</h2>
    <p>PRISM runs on Amazon Web Services (AWS Elastic Beanstalk, region us-east-2). AWS hosts the server and its logs. See the <a href="https://aws.amazon.com/privacy/">AWS Privacy Notice</a> for how AWS handles data.</p>
    <p>PRISM does not sell your information. PRISM does not share it with anyone else, except where the law requires disclosure.</p>

    <h2>5. Retention</h2>
    <p>PRISM sets no custom retention period for server logs. Logs stay on the hosting server until the platform rotates them or the server is replaced or shut down. Session cookies expire after 120 minutes of inactivity. You can delete cookies in your browser settings at any time.</p>

    <h2>6. Security</h2>
    <p>The live site currently loads over HTTP, not HTTPS. Anyone on your network path could read traffic between your browser and PRISM. Do not enter sensitive information into any page. PRISM has no field that asks for it.</p>

    <h2>7. Your choices and rights</h2>
    <ul>
        <li>Block or delete cookies in your browser. The dashboard still works.</li>
        <li>Contact the address below to ask what PRISM holds about you, or to ask for deletion of log entries tied to your IP address. PRISM will respond within 30 days.</li>
        <li>Residents of some places (such as California, Virginia and the European Economic Area) have additional legal rights over personal data. Contact the address below to exercise them.</li>
    </ul>

    <h2>8. Children</h2>
    <p>PRISM is built for maintenance technicians and is not directed to children under 13. PRISM does not knowingly collect information from children.</p>

    <h2>9. Third-party links</h2>
    <p>The dashboard cites outside sources, including the UCI Machine Learning Repository. Those sites have their own privacy policies.</p>

    <h2>10. Changes</h2>
    <p>The roadmap includes live sensor feeds and alerts. Those features would collect new data. PRISM will update this policy before it collects anything new. The effective date at the top shows the latest version.</p>

    <h2>11. Contact</h2>
    <p>James Moore, [CONTACT EMAIL]</p>
</div>
</body>
</html>
