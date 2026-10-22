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
        <div class="prism-header__actions">
            <button type="button" class="btn theme-toggle" id="theme-toggle">Light theme</button>
            @auth
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="btn">Sign out</button>
                </form>
            @endauth
        </div>
    </div>
    <script>
        (function () {
            var btn = document.getElementById('theme-toggle');
            if (!btn) { return; }
            var root = document.documentElement;
            var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;
            function isLight() {
                var t = root.getAttribute('data-theme');
                return t ? t === 'light' : !!(mq && mq.matches);
            }
            function paint() {
                btn.textContent = isLight() ? 'Dark theme' : 'Light theme';
                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) { meta.setAttribute('content', isLight() ? '#F6F8FC' : '#0b1220'); }
                window.dispatchEvent(new Event('prism-theme'));
            }
            btn.addEventListener('click', function () {
                var next = isLight() ? 'dark' : 'light';
                root.setAttribute('data-theme', next);
                try { localStorage.setItem('prism-theme', next); } catch (e) {}
                paint();
            });
            // With no saved choice, follow the system when it changes.
            if (mq && mq.addEventListener) {
                mq.addEventListener('change', function () { if (!root.getAttribute('data-theme')) { paint(); } });
            }
            paint();
        })();
    </script>
</header>
