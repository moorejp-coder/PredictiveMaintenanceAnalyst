<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Sign in | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Sign in to the PRISM milling machine failure-risk dashboard. Contest judges receive their sign-in details by a one-time secret link.">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @include('partials.brand-head')
    <style nonce="{{ $cspNonce ?? '' }}">
        .wrap { max-width: 440px; margin: 0 auto; padding: var(--prism-space-12) max(16px, env(safe-area-inset-right)) max(48px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
        .wrap > .card { padding: var(--prism-space-8); border-top: 3px solid var(--prism-accent); }
        h1 { margin: 0 0 var(--prism-space-2); font-size: 1.75rem; letter-spacing: -0.02em; }
        p.lead { margin: 0 0 var(--prism-space-6); color: var(--prism-muted); }
        .actions { margin-top: var(--prism-space-6); }
        .actions .btn { width: 100%; }
        .help { margin-top: var(--prism-space-6); padding-top: var(--prism-space-4); border-top: 1px solid var(--prism-border); color: var(--prism-muted); font-size: 0.875rem; }
        .help p { margin: 0 0 var(--prism-space-2); }
        @media (max-width: 480px) { .wrap > .card { padding: var(--prism-space-6) var(--prism-space-4); } }
    </style>
</head>
<body>
@include('partials.skip-link')
@include('partials.app-header', ['active' => null])
<main class="wrap" id="main" tabindex="-1">
    <div class="card">
        <h1>Sign in</h1>
        <p class="lead">Sign in to open the PRISM dashboard for milling machine M-01.</p>

        @if (session('status'))
            <div class="notice" role="status" style="margin-bottom: var(--prism-space-4)"><strong>{{ session('status') }}</strong></div>
        @endif

        @if ($errors->any())
            <div class="error" id="login-error" role="alert">
                <strong>Could not sign in.</strong>
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ url('/login') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus
                       @if ($errors->any()) aria-invalid="true" aria-describedby="login-error" @endif>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required
                       @if ($errors->any()) aria-invalid="true" aria-describedby="login-error" @endif>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn--primary">Sign in</button>
            </div>
        </form>

        <div class="help">
            <p>Contest judges: sign in with the email address that received your invite. If you have not chosen a password yet, open the link in that email first. PRISM has no sign-up page.</p>
            <p>Every reading in the dashboard is simulated. Use the form fields with made-up names only.</p>
            <p>Read about <a href="{{ url('/ai') }}">how PRISM uses AI</a>, the <a href="{{ url('/privacy') }}">privacy policy</a> or the <a href="{{ url('/faq') }}">FAQ</a> without signing in.</p>
        </div>
    </div>
</main>
@include('partials.cookie-notice')
</body>
</html>
