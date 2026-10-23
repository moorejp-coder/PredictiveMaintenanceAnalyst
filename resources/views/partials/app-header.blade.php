@php($active = $active ?? null)
<header class="prism-header">
    <div class="prism-header__inner">
        <a class="prism-brand" href="{{ url('/') }}">
            <x-prism-logo variant="compact" />
            <span class="prism-brand__descriptor">Machine Risk Monitoring</span>
        </a>
        <nav class="prism-nav" aria-label="Primary">
            <a href="{{ url('/') }}"@if ($active === 'dashboard') aria-current="page"@endif>Dashboard</a>
            <a href="{{ url('/ai') }}"@if ($active === 'ai') aria-current="page"@endif>AI use</a>
            <a href="{{ url('/faq') }}"@if ($active === 'faq') aria-current="page"@endif>FAQ</a>
            @if (\App\Support\DemoMode::active(request()))
                <a href="{{ url('/overrides') }}"@if ($active === 'overrides') aria-current="page"@endif>Override log (sample)</a>
            @endif
        </nav>
        @if (!empty($machine))
            <div class="prism-header__machine">
                <strong>Milling Machine M-01</strong>
                <span>Product quality variant L</span>
            </div>
        @endif
        <div class="prism-header__actions">
            <button type="button" class="btn theme-toggle" id="theme-toggle">Light theme</button>
            @if (\App\Support\DemoMode::active(request()))
                <span class="demo-badge" role="status">Read-only demo</span>
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="btn">Exit demo</button>
                </form>
            @endif
            @auth
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="btn">Sign out</button>
                </form>
            @endauth
        </div>
    </div>
    <script src="{{ asset('js/app-header.js') }}?v={{ filemtime(public_path('js/app-header.js')) }}"></script>
</header>
