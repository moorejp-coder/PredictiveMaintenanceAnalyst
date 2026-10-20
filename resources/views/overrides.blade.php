<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Override Log | {{ config('app.name', 'PRISM') }}</title>
    @include('partials.brand-head')
    <style>
        body { line-height: 1.6; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 24px max(16px, env(safe-area-inset-right)) max(56px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
        h1 { margin: 0 0 4px; font-size: clamp(1.5rem, 1.3rem + 0.8vw, 1.75rem); }
        p { color: var(--prism-muted); }
        a { color: var(--prism-accent); }
        .table-wrap { overflow-x: auto; margin-top: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 760px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--prism-border); vertical-align: top; }
        th { color: var(--prism-text); white-space: nowrap; }
        td { color: var(--prism-muted); }
        td.reason { color: var(--prism-text); overflow-wrap: anywhere; max-width: 42ch; }
        td.num { font-family: var(--prism-font-data); font-variant-numeric: tabular-nums; white-space: nowrap; }
    </style>
</head>
<body>
@include('partials.skip-link')
@include('partials.app-header')
<main class="wrap" id="main" tabindex="-1">
    <p><a href="{{ url('/') }}">Back to the dashboard</a></p>
    <h1>Override Log</h1>
    <p>Each row is a time an operator chose to keep a machine running after PRISM said "Stop the machine and inspect it now." The name is what the operator typed. The name is not tied to the sign-in account, so PRISM does not verify who typed it. The score and reading were rebuilt by the server. Times are UTC. Showing the newest 200 entries.</p>

    <p><strong>AI answer ratings:</strong> {{ number_format($helpful) }} Helpful, {{ number_format($wrong) }} Wrong. Visitors rate answers from "Explain this reading". See <a href="{{ url('/ai') }}">How PRISM uses AI</a>.</p>

    @if ($logs->isEmpty())
        <p><strong>No overrides recorded yet.</strong></p>
    @else
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Operator overrides, newest first</caption>
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">When (UTC)</th>
                        <th scope="col">Operator</th>
                        <th scope="col">Why they kept running</th>
                        <th scope="col">Machine</th>
                        <th scope="col">Risk score</th>
                        <th scope="col">Reading</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($logs as $log)
                    <tr>
                        <td class="num">{{ $log->id }}</td>
                        <td class="num"><time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->utc()->format('Y-m-d H:i:s') }}</time></td>
                        <td>{{ $log->operator_name }}</td>
                        <td class="reason">{{ $log->reason }}</td>
                        <td>{{ $log->machine }}</td>
                        <td class="num">{{ $log->risk_score }} / 100</td>
                        <td>{{ \App\Services\SensorSimulator::SCENARIOS[$log->scenario]['label'] ?? $log->scenario }}, reading {{ ($log->tick % $cycle) + 1 }} of {{ $cycle }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</main>
</body>
</html>
