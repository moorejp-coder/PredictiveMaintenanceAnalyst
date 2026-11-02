<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Change your password | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Change the password of a PRISM account after repeated failed sign-ins, or unlock it.">
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
        .unlock-only { margin-top: var(--prism-space-6); padding-top: var(--prism-space-4); border-top: 1px solid var(--prism-border); }
        .unlock-only h2 { margin: 0 0 var(--prism-space-2); font-size: 1.125rem; }
        .unlock-only p { margin: 0 0 var(--prism-space-4); color: var(--prism-muted); }
        @media (max-width: 480px) { .wrap > .card { padding: var(--prism-space-6) var(--prism-space-4); } }
    </style>
</head>
<body>
@include('partials.skip-link')
@include('partials.app-header', ['active' => null])
<main class="wrap" id="main" tabindex="-1">
    <div class="card">
        <h1>Change your password</h1>
        @unless ($valid)
            <div class="error" id="unlock-error" role="alert">
                <strong>This link is not valid.</strong>
                It was already used or it expired. A locked account unlocks on its own 30 minutes after the lock began.
            </div>
        @else
        <p class="lead">Someone tried to log into your account multiple times. If this wasn't you, choose a new password. Saving it also unlocks your account and signs out every other browser.</p>

        @if ($errors->any())
            <div class="error" id="unlock-error" role="alert">
                <strong>Could not change the password.</strong>
                @foreach ($errors->all() as $message)
                    {{ $message }}
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ url('/unlock-account/password') }}">
            @csrf
            @include('partials.honeypot', ['hpId' => 'newpw'])
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ $email }}" autocomplete="username" required readonly>
            </div>
            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required autofocus
                       aria-describedby="password-hint"@if ($errors->has('password')) aria-invalid="true"@endif>
                <p class="hint" id="password-hint">At least 12 characters. A long phrase works well. No symbols or digits are required. A password from a known data breach or a common-password list is refused.</p>
            </div>
            <div class="field">
                <label for="password_confirmation">Type the new password again</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn--primary">Change password</button>
            </div>
        </form>

        <section class="unlock-only" aria-labelledby="unlock-only-title">
            <h2 id="unlock-only-title">It was you?</h2>
            <p>Unlock the account and keep your current password.</p>
            <form method="POST" action="{{ url('/unlock-account') }}">
                @csrf
                @include('partials.honeypot', ['hpId' => 'unlock'])
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="email" value="{{ $email }}">
                <button type="submit" class="btn">Unlock without changing password</button>
            </form>
        </section>
        @endunless

        <div class="help">
            <p>This link works once and expires 60 minutes after the email was sent.</p>
            <p><a href="{{ url('/login') }}">Back to sign in</a></p>
        </div>
    </div>
</main>
@include('partials.cookie-notice')
</body>
</html>
