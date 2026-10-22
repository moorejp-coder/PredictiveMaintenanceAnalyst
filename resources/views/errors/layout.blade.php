{{--
  Shared page for the error screens (404, 419, 429, 500, 503). It uses no
  session, database or sign-in data, so it still renders when one of those is
  the thing that failed. It never prints the exception message.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title') | {{ config('app.name', 'PRISM') }}</title>
    @include('partials.brand-head')
    <style nonce="{{ $cspNonce ?? '' }}">
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; line-height: 1.6; }
        .panel { width: 100%; max-width: 520px; margin: 24px 16px; padding: var(--prism-space-8); text-align: left; border-top: 3px solid var(--prism-accent); }
        .panel .prism-logo { margin-bottom: var(--prism-space-8); align-items: flex-start; }
        .panel .prism-logo--stacked { text-align: left; }
        .panel .prism-logo__text { align-items: flex-start; }
        .code { margin: 0 0 var(--prism-space-1); font-family: var(--prism-font-data); font-weight: 500; font-size: 0.875rem; color: var(--prism-muted); }
        h1 { margin: 0 0 var(--prism-space-2); font-size: clamp(1.75rem, 1.4rem + 1.2vw, 2.25rem); letter-spacing: -0.02em; }
        p.lead { margin: 0 0 var(--prism-space-6); color: var(--prism-muted); }
        .links { display: flex; flex-wrap: wrap; gap: var(--prism-space-2); }
        .secondary-links { margin-top: var(--prism-space-3); margin-left: calc(-1 * var(--prism-space-3)); }
    </style>
</head>
<body>
    <main class="card panel" id="main">
        <x-prism-logo variant="stacked" />
        <p class="code">Error @yield('code')</p>
        <h1>@yield('heading')</h1>
        <p class="lead">@yield('lead')</p>
        <div class="links">
            <a class="btn btn--primary" href="{{ url('/') }}">Return to Dashboard</a>
        </div>
        <div class="links secondary-links">
            <a class="btn btn--tertiary" href="{{ url('/faq') }}">FAQ</a>
            <a class="btn btn--tertiary" href="{{ url('/privacy') }}">Privacy</a>
            <a class="btn btn--tertiary" href="{{ url('/terms') }}">Terms</a>
        </div>
    </main>
</body>
</html>
