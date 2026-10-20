<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Invite judges | {{ config('app.name', 'PRISM') }}</title>
    <meta name="description" content="Entrant page for inviting contest judges to PRISM by email. Each judge gets a link to choose a password.">
    @include('partials.brand-head')
    <style>
        body { line-height: 1.6; }
        .wrap { max-width: 760px; margin: 0 auto; padding: var(--prism-space-8) max(16px, env(safe-area-inset-right)) max(40px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
        h1 { margin: 0 0 var(--prism-space-2); font-size: clamp(1.75rem, 1.5rem + 1vw, 2rem); }
        h2 { margin: var(--prism-space-8) 0 var(--prism-space-3); font-size: 1.25rem; }
        p.lead { margin: 0 0 var(--prism-space-4); color: var(--prism-muted); }
        .field { margin-bottom: var(--prism-space-4); }
        label { display: block; margin-bottom: var(--prism-space-1); font-weight: 600; }
        input[type="email"], input[type="text"] {
            width: 100%; min-height: 44px; padding: 8px 12px; font: inherit; color: var(--prism-text);
            background: var(--prism-bg); border: 1px solid var(--prism-border); border-radius: var(--prism-radius-md);
        }
        input[aria-invalid="true"] { border-color: var(--prism-risk-high); border-width: 2px; padding: 7px 11px; }
        .hint { margin: var(--prism-space-1) 0 0; color: var(--prism-muted); font-size: 0.875rem; }
        .error { margin: 0 0 var(--prism-space-4); padding: var(--prism-space-3) var(--prism-space-4); border: 1px solid var(--prism-risk-high); border-left-width: 4px; border-radius: var(--prism-radius-md); color: var(--prism-text); }
        .error strong { color: var(--prism-risk-high); }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--prism-border); vertical-align: top; color: var(--prism-muted); overflow-wrap: anywhere; }
        th { color: var(--prism-text); }
        .tag { display: inline-block; padding: 1px 8px; border: 1px solid var(--prism-border); border-radius: var(--prism-radius-sm); font-size: 0.8125rem; color: var(--prism-text); }
    </style>
</head>
<body>
@include('partials.skip-link')
@include('partials.app-header', ['active' => null])
<main class="wrap" id="main" tabindex="-1">
    <h1>Invite a judge</h1>
    <p class="lead">Type a judge's email address. PRISM creates the account and emails a link to choose a password. The link works once and expires in {{ $days }} days. Type the same address again to send a new link.</p>

    @if (session('status'))
        <div class="notice" role="status"><strong>{{ session('status') }}</strong></div>
    @endif
    @if ($errors->any())
        <div class="error" id="invite-error" role="alert">
            <strong>Not sent.</strong>
            @foreach ($errors->all() as $message)
                {{ $message }}
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/admin/invites') }}" style="margin-top: var(--prism-space-4)">
        @csrf
        <div class="field">
            <label for="email">Judge email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="off" required autofocus
                   @if ($errors->has('email')) aria-invalid="true" aria-describedby="invite-error" @endif>
        </div>
        <div class="field">
            <label for="name">Judge name (optional)</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="off" maxlength="100" aria-describedby="name-hint">
            <p class="hint" id="name-hint">Shown only on this page. Leave blank for "Contest Judge".</p>
        </div>
        <button type="submit" class="btn btn--primary">Send invite</button>
    </form>

    <h2>Accounts</h2>
    @if ($users->isEmpty())
        <p class="lead">No accounts yet.</p>
    @else
        <table>
            <caption class="visually-hidden" style="position:absolute;left:-9999px">Judge accounts and their status</caption>
            <thead><tr><th scope="col">Email</th><th scope="col">Name</th><th scope="col">Status</th><th scope="col">Created (UTC)</th></tr></thead>
            <tbody>
            @foreach ($users as $user)
                <tr>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->name }}</td>
                    <td><span class="tag">{{ $user->email_verified_at ? 'Active' : 'Invited' }}</span></td>
                    <td>{{ $user->created_at?->utc()->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="hint">Invited: no password set yet. Active: the judge set a password and signs in at the login page.</p>
    @endif
</main>
</body>
</html>
