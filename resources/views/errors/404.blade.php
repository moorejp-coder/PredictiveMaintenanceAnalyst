<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b1220">
    <meta name="robots" content="noindex">
    <title>Page not found | {{ config('app.name', 'PRISM') }}</title>
    <style>
        :root { --bg:#0b1220; --panel:#111a2e; --line:#26355a; --text:#e8eefb; --muted:#a3b3d1; --accent:#38bdf8; }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; color-scheme: dark; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
               background:var(--bg); color:var(--text); line-height:1.6;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .card { width:100%; max-width:520px; margin:24px 16px; padding:32px 24px; text-align:center;
                background:var(--panel); border:1px solid var(--line); border-radius:12px; }
        .code { margin:0; font-size:4rem; font-weight:700; letter-spacing:0.04em; color:var(--accent); line-height:1; }
        h1 { margin:12px 0 8px; font-size:1.4rem; }
        p { margin:0 0 20px; color:var(--muted); }
        .links { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; }
        a { color:var(--accent); }
        .btn { display:inline-block; padding:8px 16px; border:1px solid var(--line); border-radius:8px;
               color:var(--text); text-decoration:none; font-size:0.9rem; }
        .btn:hover { border-color:var(--accent); }
        .btn.primary { border-color:var(--accent); color:var(--accent); font-weight:600; }
        @media (pointer: coarse) { .btn { padding: 12px 18px; } }
        a:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
    </style>
</head>
<body>
    <main class="card">
        <p class="code">404</p>
        <h1>Page not found</h1>
        <p>This page does not exist or has moved. Check the address, or use a link below.</p>
        <div class="links">
            <a class="btn primary" href="{{ url('/') }}">Dashboard</a>
            <a class="btn" href="{{ url('/faq') }}">FAQ</a>
            <a class="btn" href="{{ url('/privacy') }}">Privacy</a>
            <a class="btn" href="{{ url('/terms') }}">Terms</a>
        </div>
    </main>
</body>
</html>
