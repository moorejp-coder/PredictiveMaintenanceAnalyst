<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'PRISM') }}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            text-align: center;
            padding: 24px;
            box-sizing: border-box;
        }
        main { max-width: 640px; }
        h1 { font-size: 3rem; margin: 0 0 16px; letter-spacing: 0.1em; }
        p { font-size: 1.125rem; line-height: 1.6; margin: 0 0 32px; color: #cbd5e1; }
        .soon {
            display: inline-block;
            font-size: 1.5rem;
            font-weight: 600;
            padding: 12px 28px;
            border: 2px solid #38bdf8;
            border-radius: 8px;
            color: #38bdf8;
        }
    </style>
</head>
<body>
    <main>
        <h1>{{ config('app.name', 'PRISM') }}</h1>
        <p>PRISM is a predictive maintenance dashboard that uses machine-learning models and real-time sensor data to forecast equipment failures before they occur.</p>
        <div class="soon">Coming Soon</div>
    </main>
</body>
</html>
