<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Privacy Policy | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="How PRISM handles data. PRISM has {{ config('prism.require_login') ? 'an invite-only sign-in' : 'no accounts' }} and no analytics. One form records a name and a reason when an operator overrides a stop warning.">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @include('partials.brand-head')
    <style nonce="{{ $cspNonce ?? '' }}">
        html { scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
</head>
<body class="doc-page">
@include('partials.skip-link')
@include('partials.app-header')
<main class="wrap" id="main" tabindex="-1">
    <p><a href="{{ url('/') }}">Back to the dashboard</a></p>
    <h1>PRISM Privacy Policy</h1>
    <p class="meta">Effective {{ config('prism.policy_dates.privacy.date') }}</p>

    <div class="summary">
        <h2 style="border:0;margin-top:12px">Summary</h2>
        <ul>
            <li>@if (config('prism.require_login'))PRISM has no sign-up. The dashboard needs a sign-in. Contest judges use the read-only Judge Demo. Other people get an invited account when they ask the entrant for a link.@else PRISM has no accounts or sign-in.@endif One form, the override log, asks for a name and a reason.</li>
            <li>Every sensor reading on the dashboard is simulated. The override form asks for your name. Enter nothing else personal.</li>
            <li>PRISM uses no analytics, advertising, tracking pixels or third-party scripts.</li>
            <li>If you press Explain this reading, PRISM's server sends the simulated score result (numbers and labels, no personal data) to Anthropic's API and shows the reply.</li>
            <li>PRISM does not sell or share your data.</li>
            <li>The site loads over HTTPS, which encrypts traffic between your browser and PRISM.</li>
        </ul>
    </div>

    <h2>1. Who runs PRISM</h2>
    <p>PRISM (Predictive Real-time Infrastructure Sensor Monitoring) is a student project built by James Moore for the CUA Busch School AI Vibe Coding Contest, Fall 2026. James Moore operates it. The Catholic University of America does not operate this site and does not receive data from it.</p>

    <h2>2. What PRISM collects</h2>
    <p><strong>Information you give PRISM.</strong> The dashboard has demo controls that send a scenario name and a reading number to the server so it can return a simulated reading. These two values travel in the web address of each request, so server logs record them with the rest of the request. The demo controls themselves save nothing, but the override log and the AI answer ratings below keep the scenario and the reading number. When PRISM says to stop the machine and you choose to keep it running, a form asks for your name and your reason. PRISM stores both, with the time, the machine, the risk score and the simulated reading. PRISM does not check that the name is yours.</p>
    <p><strong>Information collected automatically.</strong></p>
    <table>
        <tr><th scope="col">Item</th><th scope="col">What it is</th><th scope="col">Why</th></tr>
        <tr><td>Server logs</td><td>Your IP address, the date and time, the page requested, your browser type and the response code. The web server and the hosting platform record these when you load a page.</td><td>To run the site, fix errors and protect against abuse.</td></tr>
        <tr><td>Session cookie</td><td>A cookie named <code>prism-session</code>. The framework (Laravel) sets it. It holds a random session ID. It expires after 120 minutes of inactivity.</td><td>Standard framework behavior. PRISM does not use it to identify you or track you.</td></tr>
        <tr><td>Rate-limit counters</td><td>A counter tied to your IP address that counts requests to the sign-in, invite, override, rating and Explain this reading routes. The server keeps it in its cache for about a minute.</td><td>To stop abuse and keep the AI bill down.</td></tr>
        <tr><td>Security cookie</td><td>A cookie named <code>XSRF-TOKEN</code>. The framework sets it to guard against forged requests.</td><td>Security.</td></tr>
    </table>
    <p>@if (config('prism.require_login'))PRISM stores three kinds of record. The first is invited accounts. Each holds a name, an email address and a password stored as a hash. The entrant adds each one by typing the person's email address on a private page, and nobody can sign up alone. Each invite also stores a one-time link token (stored as a hash) next to the email address until the person uses it or it expires after 7 days. The second is the override log.@else PRISM stores two kinds of record. The first is the override log.@endif It holds the name and reason you type. A password protects the page that lists the log. @if (config('prism.require_login'))The third @else The second @endif is a rating of an AI answer, if you press Helpful or Wrong. It holds the rating, the step, the source, the scenario, the reading number and the time. It holds no name, no free text and no IP address.</p>
    <p>Both cookies are strictly necessary for the site to work, so PRISM does not ask for cookie consent. A short notice tells you about them. Your choice to dismiss the notice is stored in your browser (local storage), not in a cookie, and is never sent to PRISM. The same is true of your light or dark theme choice (local storage, key <code>prism-theme</code>)@if (config('prism.require_login')) and a note that you closed the judge guide (session storage, key <code>prism-guide-seen</code>, cleared when you close the tab)@endif. <button type="button" onclick="window.prismCookieNotice &amp;&amp; window.prismCookieNotice.open()" style="background:none;border:0;padding:0;color:var(--prism-accent);text-decoration:underline;cursor:pointer;font:inherit">Show the cookie notice again</button>.</p>

    <h2>3. How PRISM uses information</h2>
    <ul>
        <li>To serve the dashboard and return simulated readings.</li>
        <li>To keep a record of who kept a machine running after a stop warning, when, and why.</li>
        <li>To keep the site secure and working.</li>
    </ul>
    <p>PRISM does not use information for advertising, profiling or automated decisions about you.</p>

    <h2>4. Who receives information</h2>
    <p>PRISM runs on Amazon Web Services (AWS Elastic Beanstalk, region us-east-2). AWS hosts the server and its logs. See the <a href="https://aws.amazon.com/privacy/">AWS Privacy Notice</a> for how AWS handles data.</p>
    <p>PRISM does not sell your information. PRISM does not share it with anyone else, except where the law requires disclosure. @if (config('prism.require_login'))PRISM also gives an invited person's email address to Amazon Simple Email Service (Amazon SES), part of AWS, which delivers the invite. @endif</p>

    <h2>5. Retention</h2>
    <p>PRISM sets no custom retention period for server logs. Logs stay on the hosting server until the platform rotates them or the server is replaced or shut down. Session cookies expire after 120 minutes of inactivity. You can delete cookies in your browser settings at any time. Override log entries have no deletion schedule. They stay until James Moore removes them or the server is replaced or shut down.</p>

    <h2>6. Security</h2>
    <p>The live site loads over HTTPS with a free Let's Encrypt certificate, so traffic between your browser and PRISM is encrypted. Do not enter sensitive information into any page. The only fields ask for a name and a reason. Enter nothing else personal.</p>

    <h2>7. Your choices and rights</h2>
    <ul>
        <li>Block or delete cookies in your browser. The dashboard still works.</li>
        <li>Contact the address below to ask what PRISM holds about you, or to ask for deletion of override log entries you made. Override log entries hold no IP address, so James Moore finds them by the name you typed. The hosting platform holds the server logs, and PRISM has no tool to search them by IP address. PRISM will respond within 30 days.</li>
        <li>Residents of some places (such as California, Virginia and the European Economic Area) have additional legal rights over personal data. Contact the address below to exercise them.</li>
    </ul>

    <h2>8. Children</h2>
    <p>PRISM is built for maintenance technicians and is not directed to children under 13. PRISM does not knowingly collect information from children.</p>

    <h2>9. Third-party links</h2>
    <p>The dashboard cites outside sources, including the UCI Machine Learning Repository. Those sites have their own privacy policies.</p>
    <p>When you press Explain this reading, PRISM's server sends the simulated score result to Anthropic's API. PRISM sends no text typed by a visitor. Anthropic has its own privacy policy.@if (config('prism.require_login')) When the entrant invites a person, PRISM's server sends the invited person's email address to Amazon SES, which delivers the invite. The AWS Privacy Notice linked above covers that service.@endif</p>

    <h2>10. Changes</h2>
    <p>The roadmap includes live sensor feeds and alerts. Those features would collect new data. PRISM will update this policy before it collects anything new. The effective date at the top shows the latest version.</p>

    <h2>11. Contact</h2>
    <p>James Moore, <a href="mailto:moorejp@cua.edu">moorejp@cua.edu</a></p>
    <p>See also the <a href="{{ url('/terms') }}">Terms and Conditions</a>.</p>
</main>
@include('partials.cookie-notice')
</body>
</html>
