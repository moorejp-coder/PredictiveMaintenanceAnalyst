<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Privacy Policy | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="How PRISM handles data. PRISM has {{ config('prism.require_login') ? 'an invite-only sign-in' : 'no accounts' }} and no analytics. One form records a reason when an operator overrides a stop warning.">
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
            <li>@if (config('prism.require_login'))PRISM has no sign-up. The dashboard needs a sign-in. Contest judges use the read-only Judge Demo. Other people get an invited account when they ask the entrant for a link.@else PRISM has no accounts or sign-in.@endif One form, the override log, asks for a reason.</li>
            <li>Every sensor reading on the dashboard is simulated. The override form asks only for your reason. Enter nothing personal in it.</li>
            <li>PRISM uses no analytics, advertising, tracking pixels or third-party scripts.</li>
            <li>If you press Explain this reading, PRISM's server sends the simulated score result (numbers and labels, no personal data) to Anthropic's API and shows the reply.</li>
            @if (config('prism.require_login'))
            <li>When an invited person chooses a password, PRISM's server sends the first five characters of a one-way hash of that password to the Have I Been Pwned range service, to check the password against known data breaches. The service receives those five characters and the server's address. It never receives the password, the full hash or the email address.</li>
            @endif
            <li>PRISM does not sell or share your data.</li>
            <li>The site loads over HTTPS, which encrypts traffic between your browser and PRISM.</li>
        </ul>
    </div>

    <h2>1. Who runs PRISM</h2>
    <p>PRISM (Predictive Real-time Infrastructure Sensor Monitoring) is a student project built by James Moore for the CUA Busch School AI Vibe Coding Contest, Fall 2026. James Moore operates it. The Catholic University of America does not operate this site and does not receive data from it.</p>

    <h2>2. What PRISM collects</h2>
    <p><strong>Information you give PRISM.</strong> The dashboard has demo controls that send a scenario name and a reading number to the server so it can return a simulated reading. These two values travel in the web address of each request, so server logs record them with the rest of the request. The demo controls themselves save nothing, but the override log and the AI answer ratings below keep the scenario and the reading number. When PRISM says to stop the machine and you choose to keep it running, a form asks for your reason. PRISM stores it with the account you are signed in with, the time, the machine, the risk score and the simulated reading. PRISM asks for no name.</p>
    <p><strong>Information collected automatically.</strong></p>
    <table>
        <tr><th scope="col">Item</th><th scope="col">What it is</th><th scope="col">Why</th></tr>
        <tr><td>Server logs</td><td>Your IP address, the date and time, the page requested, your browser type and the response code. The web server and the hosting platform record these when you load a page.</td><td>To run the site, fix errors and protect against abuse.</td></tr>
        <tr><td>Session cookie</td><td>A cookie named <code>prism-session</code>. The framework (Laravel) sets it. It holds a random session ID, encrypted. Scripts on the page cannot read it. The browser sends it only over HTTPS, and not with forms or background requests that come from other sites. It expires after 30 minutes of inactivity, or 7 days after sign-in at most.</td><td>Keeps you signed in and guards forms against forged requests. PRISM does not use it to track you.</td></tr>
        <tr><td>Rejected-input log</td><td>When the server rejects a request as an attack or a bot (script tags, SQL syntax, a text over 1,000 characters, or a filled hidden form field), the application log records the time, your IP address, the page, the name of the field, the reason, and, for script tags, SQL syntax or a filled hidden field, the first 100 characters of the text. A text that is only too long is logged by its length alone. Passwords and tokens are never logged.</td><td>To spot and study abuse. Logs stay on the hosting server as described under retention below.</td></tr>
        @if (config('prism.require_login'))
        <tr><td>Sign-in security log</td><td>When a sign-in fails or locks, a password page is guessed at, or a signed-in session ends or changes IP address, the application log records the time, your IP address, and either a keyed one-way code made from the email address (it cannot be turned back into the address or matched against a list of addresses without a secret key that stays on the server) or your account number. Passwords and session cookies are never logged.</td><td>To spot password guessing and stolen sessions. Logs stay on the hosting server as described under retention below.</td></tr>
        @endif
        <tr><td>Security audit log</td><td>A separate log records each security event: a sign-in or a failed sign-in, a refused request, an account created, changed, locked or deleted, an admin opening an admin page, a data export or a list of many records, and a burst of API errors. Each line holds the event, the time to the millisecond, your IP address, your browser type (the User-Agent text, cut to 255 characters), the page address without its query string, and either your account number or a keyed one-way code made from the email address. A visitor with no account has neither. Passwords, tokens, session cookies and what you type into a form are never logged.</td><td>To spot and study attacks and misuse of accounts.</td></tr>
        <tr><td>Rate-limit counters</td><td>Counters tied to your account, or to your IP address if you have no account, that count requests to each route and the cost of your AI answers. The server keeps each one in its cache for its time window: one minute, one hour or one day.</td><td>To stop abuse and keep the AI and email bills down.</td></tr>
    </table>
    <p>@if (config('prism.require_login'))PRISM stores three kinds of record. The first is invited accounts. Each holds an email address, a password stored as a hash and the fixed label "Contest Judge". The invite asks for no name. The entrant adds each one by typing the person's email address on a private page, and nobody can sign up alone. Each invite also stores a one-time link token (stored as a hash) next to the email address until the person uses it. The link expires after 30 minutes, and PRISM removes expired links each time the site is updated. The link in the email keeps the token and the email address after a "#", the part of a web address a browser never sends to a server, so they do not appear in server logs. When someone types a wrong password for an account, the account also stores the number of wrong tries in a row, the time of the first one and up to 10 IP addresses they came from. A good sign-in clears them. After 10 wrong tries the account locks for 30 minutes, and PRISM emails the owner those times and IP addresses with a one-time link (stored as a hash) to change the password. Once the email is built, the account stops keeping the IP addresses. PRISM does not look up where an IP address is. The second is the override log.@else PRISM stores two kinds of record. The first is the override log.@endif It holds the reason you type and the account you were signed in with. Only the entrant's admin account opens the page that lists the log. @if (config('prism.require_login'))The third @else The second @endif is a rating of an AI answer, if you press Helpful or Wrong. It holds the rating, the step, the source, the scenario, the reading number and the time. It holds no name, no free text and no IP address.</p>
    <p>The session cookie is strictly necessary for the site to work, so PRISM does not ask for cookie consent. A short notice tells you about them. Your choice to dismiss the notice is stored in your browser (local storage), not in a cookie, and is never sent to PRISM. The same is true of your light or dark theme choice (local storage, key <code>prism-theme</code>)@if (config('prism.require_login')) and a note that you closed the judge guide (session storage, key <code>prism-guide-seen</code>, cleared when you close the tab)@endif. <button type="button" id="cookie-notice-reopen" style="background:none;border:0;padding:0;color:var(--prism-accent);text-decoration:underline;cursor:pointer;font:inherit">Show the cookie notice again</button>.</p>

    <h2>3. How PRISM uses information</h2>
    <ul>
        <li>To serve the dashboard and return simulated readings.</li>
        <li>To keep a record of who kept a machine running after a stop warning, when, and why.</li>
        <li>To keep the site secure and working.</li>
    </ul>
    <p>PRISM does not use information for advertising, profiling or automated decisions about you.</p>

    <h2>4. Who receives information</h2>
    <p>PRISM runs on Amazon Web Services (AWS Elastic Beanstalk, region us-east-2). AWS hosts the server and its logs. See the <a href="https://aws.amazon.com/privacy/">AWS Privacy Notice</a> for how AWS handles data.</p>
    <p>PRISM does not sell your information. PRISM does not share it with anyone else, except where the law requires disclosure. @if (config('prism.require_login'))PRISM also gives an invited person's email address to Amazon Simple Email Service (Amazon SES), part of AWS, which delivers the invite and any account lock email. @endif</p>

    <h2>5. Retention</h2>
    <p>PRISM sets no custom retention period for server logs. Logs stay on the hosting server until the platform rotates them or the server is replaced or shut down. The security audit log follows the same rule. PRISM sets no period for it, and it does not delete lines from it when an account is deleted. A sign-in session ends after 30 minutes of inactivity and 7 days after sign-in at most. You can delete cookies in your browser settings at any time. Override log entries have no deletion schedule. They stay until James Moore erases them or the server is replaced or shut down. When the account that wrote an entry deletes it, the entry is only marked deleted, so the log keeps a record of the change, until James Moore erases it.</p>

    <h2>6. Security</h2>
    <p>The live site loads over HTTPS with a free Let's Encrypt certificate, so traffic between your browser and PRISM is encrypted. On the server, PRISM stores override reasons, the IP addresses of failed sign-ins and session data encrypted (AES-256), and passwords only as one-way hashes. Do not enter sensitive information into any page. The override form asks only for a reason. Enter nothing personal in it.</p>

    <h2>7. Your choices and rights</h2>
    <ul>
        <li>Block or delete cookies in your browser. The dashboard still works.</li>
        <li>Contact the address below to ask what PRISM holds about you, or to ask for deletion of override log entries you made@if (config('prism.require_login')) or of your account@endif. Override log entries hold no IP address and no name, so James Moore finds them by your account, or by the entry number if you made them with the sign-in off. Erasing an entry removes it from the database for good, with any earlier version of its reason.@if (config('prism.require_login')) On the Account page you can also download, with your password, one JSON file with everything PRISM holds about your account: your profile, every override entry you wrote, your sessions, the lines in PRISM's own log files about your account and what each connected service received. It leaves out other people's data and security material such as your password hash. It also leaves out the security audit log. Ask the contact address below for the lines about your account number. IP addresses of wrong passwords and failed sign-ins on your account are counted but not listed, because they may belong to someone else. You can also delete your account yourself on the Account page, with your password. Deleting an account removes, for good, the account, every override entry it wrote, its unused invite link, its sessions in every browser, the rate-limit counters kept for it and the lines in PRISM's own log files that name it. The security audit log is the one exception. PRISM keeps it apart from those files and never edits it, so a line about the account stays after the account is gone. That line names the account only by its number, which then matches no one, and by a keyed one-way code made from the email address. PRISM then emails a confirmation to the address the account used and shows what it removed. PRISM stores no uploaded files, and AI answer ratings hold no account, so neither holds data to remove.@endif The hosting platform holds the server logs, and PRISM has no tool to search them by IP address. PRISM will respond within 30 days.</li>
        <li>Residents of some places (such as California, Virginia and the European Economic Area) have additional legal rights over personal data. Contact the address below to exercise them.</li>
    </ul>

    <h2>8. Children</h2>
    <p>PRISM is built for maintenance technicians and is not directed to children under 13. PRISM does not knowingly collect information from children.</p>

    <h2>9. Third-party links</h2>
    <p>The dashboard cites outside sources, including the UCI Machine Learning Repository. Those sites have their own privacy policies.</p>
    <p>When you press Explain this reading, PRISM's server sends the simulated score result to Anthropic's API. PRISM sends no text typed by a visitor. Anthropic has its own privacy policy.@if (config('prism.require_login')) When the entrant invites a person, PRISM's server sends the invited person's email address to Amazon SES, which delivers the invite. An account lock email, with the times and IP addresses of the failed sign-ins, goes through Amazon SES too. The AWS Privacy Notice linked above covers that service.@endif</p>

    <h2>10. Changes</h2>
    <p>The roadmap includes live sensor feeds and alerts. Those features would collect new data. PRISM will update this policy before it collects anything new. The effective date at the top shows the latest version.</p>

    <h2>11. Contact</h2>
    <p>James Moore, <a href="mailto:moorejp@cua.edu">moorejp@cua.edu</a></p>
    <p>See also the <a href="{{ url('/terms') }}">Terms and Conditions</a>.</p>
</main>
@include('partials.cookie-notice')
</body>
</html>
