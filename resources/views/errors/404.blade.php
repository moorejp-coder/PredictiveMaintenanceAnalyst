<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Page not found | {{ config('app.name', 'PRISM') }}</title>
    @include('partials.brand-head')
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; line-height: 1.6; }
        .panel { width: 100%; max-width: 520px; margin: 24px 16px; padding: var(--prism-space-8) var(--prism-space-6); text-align: center; }
        .panel .prism-logo { margin-bottom: var(--prism-space-6); }
        .code { margin: 0 0 var(--prism-space-2); font-family: var(--prism-font-data); font-weight: 500; font-size: 0.875rem; letter-spacing: 0.08em; color: var(--prism-muted); }
        h1 { margin: 0 0 var(--prism-space-2); font-size: clamp(1.75rem, 1.5rem + 1vw, 2rem); }
        p.lead { margin: 0 0 var(--prism-space-6); color: var(--prism-muted); }
        .links { display: flex; flex-wrap: wrap; gap: var(--prism-space-2); justify-content: center; }
        .secondary-links { margin-top: var(--prism-space-3); }
    </style>
</head>
<body>
    <main class="card panel" id="main">
        <x-prism-logo variant="stacked" />
        <p class="code">Error 404</p>
        <h1>Page Not Found</h1>
        <p class="lead">The page you're looking for isn't available.</p>
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
