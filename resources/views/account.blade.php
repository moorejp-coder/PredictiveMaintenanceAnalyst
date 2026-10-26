<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Your account | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Sign out of every other browser on your PRISM account.">
    @include('partials.brand-head')
    <style nonce="{{ $cspNonce ?? '' }}">
        .wrap { max-width: 440px; margin: 0 auto; padding: var(--prism-space-12) max(16px, env(safe-area-inset-right)) max(48px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
        .wrap > .card { padding: var(--prism-space-8); border-top: 3px solid var(--prism-accent); }
        h1 { margin: 0 0 var(--prism-space-2); font-size: 1.75rem; letter-spacing: -0.02em; }
        p.lead { margin: 0 0 var(--prism-space-6); color: var(--prism-muted); }
        .actions { margin-top: var(--prism-space-6); }
        .actions .btn { width: 100%; }
        @media (max-width: 480px) { .wrap > .card { padding: var(--prism-space-6) var(--prism-space-4); } }
    </style>
</head>
<body>
@include('partials.skip-link')
@include('partials.app-header', ['active' => 'account'])
<main class="wrap" id="main" tabindex="-1">
    <div class="card">
        <h1>Your account</h1>
        <p class="lead">Signed in as {{ $email }}.</p>

        @if (session('status'))
            <div class="notice" role="status" style="margin-bottom: var(--prism-space-4)"><strong>{{ session('status') }}</strong></div>
        @endif

        @if ($errors->any())
            <div class="error" id="account-error" role="alert">
                <strong>Could not sign out the other browsers.</strong>
                {{ $errors->first() }}
            </div>
        @endif

        <h2>Sign out everywhere</h2>
        <p class="lead">Ends your session in every other browser and device. This browser stays signed in. Use it if you signed in on a shared computer or think someone else has your session. Type your password to confirm.</p>
        <form method="POST" action="{{ url('/logout-everywhere') }}">
            @csrf
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required
                       @if ($errors->has('password')) aria-invalid="true" aria-describedby="account-error" @endif>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn--primary">Sign out everywhere else</button>
            </div>
        </form>
    </div>
</main>
@include('partials.cookie-notice')
</body>
</html>
