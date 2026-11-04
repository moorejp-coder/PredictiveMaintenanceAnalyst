<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Account deleted | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Confirmation that a PRISM account and its data were deleted.">
    @include('partials.brand-head')
    <style nonce="{{ $cspNonce ?? '' }}">
        .wrap { max-width: 440px; margin: 0 auto; padding: var(--prism-space-12) max(16px, env(safe-area-inset-right)) max(48px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
        .wrap > .card { padding: var(--prism-space-8); border-top: 3px solid var(--prism-accent); }
        h1 { margin: 0 0 var(--prism-space-2); font-size: 1.75rem; letter-spacing: -0.02em; }
        p.lead { margin: 0 0 var(--prism-space-6); color: var(--prism-muted); }
        .actions { margin-top: var(--prism-space-6); }
        .actions .btn { width: 100%; }
        .danger { margin-top: var(--prism-space-8); padding-top: var(--prism-space-6); border-top: 1px solid var(--prism-border); }
        .danger ul { margin: 0 0 var(--prism-space-4); padding-left: 1.25rem; color: var(--prism-muted); }
        .check { display: flex; gap: var(--prism-space-2); align-items: flex-start; }
        @media (max-width: 480px) { .wrap > .card { padding: var(--prism-space-6) var(--prism-space-4); } }
    </style>
</head>
<body>
@include('partials.skip-link')
@include('partials.app-header', ['active' => null])
<main class="wrap" id="main" tabindex="-1">
    @php($deletion = session('deletion'))
    <div class="card">
        <h1>Account deleted</h1>
        @if (is_array($deletion))
            @php($r = $deletion['removed'])
            <p class="lead" role="status">Your account and all data tied to it are deleted for good.</p>
            <table>
                <caption class="sr-only">What PRISM removed</caption>
                <tbody>
                    <tr><th scope="row">Account (email address and password hash)</th><td class="num">{{ $r['account'] }}</td></tr>
                    <tr><th scope="row">Override entries</th><td class="num">{{ $r['overrides'] }}</td></tr>
                    <tr><th scope="row">Unused invite links</th><td class="num">{{ $r['links'] }}</td></tr>
                    <tr><th scope="row">Signed-in sessions</th><td class="num">{{ $r['sessions'] }}</td></tr>
                    <tr><th scope="row">Rate-limit counters</th><td class="num">{{ $r['counters'] }}</td></tr>
                    <tr><th scope="row">Lines in PRISM's own log files</th><td class="num">{{ $r['log_lines'] }}</td></tr>
                </tbody>
            </table>
            <p class="lead">{{ $deletion['emailed'] ? 'A confirmation email is on its way to the address the account used.' : 'The confirmation email could not be sent. This page is your confirmation.' }}</p>
            <p class="lead">PRISM stores no uploaded files, and AI answer ratings hold no account, so neither held your data. Server logs kept by the hosting platform (AWS) record IP addresses and request times. PRISM cannot edit them; they are removed when the platform rotates them or the server is replaced.</p>
        @else
            <p class="lead">This page shows the receipt right after an account is deleted. There is nothing to show here now.</p>
        @endif
        <p><a href="{{ url('/login') }}">Back to sign in</a></p>
    </div>
</main>
@include('partials.cookie-notice')
</body>
</html>
