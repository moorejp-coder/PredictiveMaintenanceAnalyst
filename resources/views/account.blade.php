<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Your account | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Sign out of every other browser, download your data, or delete your PRISM account and all its data.">
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

        <section class="danger" aria-labelledby="export-title">
            <h2 id="export-title">Download your data</h2>
            <p class="lead">One JSON file with everything PRISM holds about your account: your profile, every override entry you wrote, your sessions, the lines in PRISM's own log files about your account, and what each connected service received. It leaves out other people's data and security material such as your password hash.</p>
            @if ($errors->export->any())
                <div class="error" id="export-error" role="alert">
                    <strong>The file was not created.</strong>
                    {{ $errors->export->first() }}
                </div>
            @endif
            <form method="POST" action="{{ url('/account/export') }}">
                @csrf
                @include('partials.honeypot', ['hpId' => 'export'])
                <div class="field">
                    <label for="export-password">Password</label>
                    <input id="export-password" name="password" type="password" autocomplete="current-password" required
                           @if ($errors->export->any()) aria-invalid="true" aria-describedby="export-error" @endif>
                </div>
                <div class="actions">
                    <button type="submit" class="btn">Download my data (JSON)</button>
                </div>
            </form>
        </section>

        <section class="danger" aria-labelledby="delete-title">
            <h2 id="delete-title">Delete your account</h2>
            <p class="lead">Deletes your account and all data tied to it, for good. It cannot be undone. PRISM removes:</p>
            <ul>
                <li>your email address and password hash</li>
                <li>every override entry you wrote, including ones you deleted</li>
                <li>your unused invite links and your sessions in every browser</li>
                <li>the counters and lines in PRISM's own log files that name your account</li>
            </ul>
            <p class="lead">PRISM then emails you a confirmation. Server logs kept by the hosting platform (AWS) are outside PRISM's reach; they are removed when the platform rotates them.</p>
            @if ($errors->delete->any())
                <div class="error" id="delete-error" role="alert">
                    <strong>The account was not deleted.</strong>
                    {{ $errors->delete->first() }}
                </div>
            @endif
            <form method="POST" action="{{ url('/account/delete') }}">
                @csrf
                @include('partials.honeypot', ['hpId' => 'delete'])
                <div class="field">
                    <label for="delete-password">Password</label>
                    <input id="delete-password" name="password" type="password" autocomplete="current-password" required
                           @if ($errors->delete->any()) aria-invalid="true" aria-describedby="delete-error" @endif>
                </div>
                <div class="field check">
                    <input id="delete-confirm" name="confirm" type="checkbox" value="1" required>
                    <label for="delete-confirm">I understand my account and its data are deleted for good.</label>
                </div>
                <div class="actions">
                    <button type="submit" class="btn">Delete my account and data</button>
                </div>
            </form>
        </section>
    </div>
</main>
@include('partials.cookie-notice')
</body>
</html>
