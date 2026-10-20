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
        </nav>
        @if (!empty($machine))
            <div class="prism-header__machine">
                <strong>Milling Machine M-01</strong>
                <span>Product quality variant L</span>
            </div>
        @endif
    </div>
</header>
