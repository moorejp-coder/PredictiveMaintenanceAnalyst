<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Set your password | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Choose a password for your PRISM contest judge account, then sign in to the milling machine failure-risk dashboard.">
    @include('partials.brand-head')
    <style nonce="{{ $cspNonce ?? '' }}">
        body { line-height: 1.6; }
        .wrap { max-width: 480px; margin: 0 auto; padding: var(--prism-space-8) max(16px, env(safe-area-inset-right)) max(40px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
        h1 { margin: 0 0 var(--prism-space-2); font-size: clamp(1.75rem, 1.5rem + 1vw, 2rem); }
        p.lead { margin: 0 0 var(--prism-space-4); color: var(--prism-muted); }
        .field { margin-bottom: var(--prism-space-4); }
        label { display: block; margin-bottom: var(--prism-space-1); font-weight: 600; }
        input[type="email"], input[type="password"] {
            width: 100%; min-height: 44px; padding: 8px 12px; font: inherit; color: var(--prism-text);
            background: var(--prism-bg); border: 1px solid var(--prism-border); border-radius: var(--prism-radius-md);
        }
        input[readonly] { color: var(--prism-muted); }
        input[aria-invalid="true"] { border-color: var(--prism-risk-high); border-width: 2px; padding: 7px 11px; }
        .hint { margin: var(--prism-space-1) 0 0; color: var(--prism-muted); font-size: 0.875rem; }
        .error { margin: 0 0 var(--prism-space-4); padding: var(--prism-space-3) var(--prism-space-4); border: 1px solid var(--prism-risk-high); border-left-width: 4px; border-radius: var(--prism-radius-md); color: var(--prism-text); }
        .error strong { color: var(--prism-risk-high); }
        .actions { margin-top: var(--prism-space-2); }
        .actions .btn { width: 100%; }
        .help { margin-top: var(--prism-space-6); color: var(--prism-muted); font-size: 0.9rem; }
        .help p { margin: 0 0 var(--prism-space-2); }
    </style>
</head>
<body>
@include('partials.skip-link')
@include('partials.app-header', ['active' => null])
<main class="wrap" id="main" tabindex="-1">
    <div class="card">
        <h1>Set your password</h1>
        <p class="lead">Choose a password for your PRISM judge account. Then sign in with this email address and your password.</p>

        @if ($errors->any())
            <div class="error" id="set-error" role="alert">
                <strong>Could not set the password.</strong>
                @foreach ($errors->all() as $message)
                    {{ $message }}
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ url('/set-password') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required readonly>
            </div>
            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required autofocus
                       aria-describedby="password-hint"@if ($errors->has('password')) aria-invalid="true"@endif>
                <p class="hint" id="password-hint">At least 12 characters. Use a password you use nowhere else.</p>
            </div>
            <div class="field">
                <label for="password_confirmation">Type the new password again</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn--primary">Set password</button>
            </div>
        </form>

        <div class="help">
            <p>This link works once. After you set a password, sign in at the login page.</p>
        </div>
    </div>
</main>
@include('partials.cookie-notice')
</body>
</html>
