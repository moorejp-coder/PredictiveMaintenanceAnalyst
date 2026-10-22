<meta name="theme-color" content="#0b1220">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="icon" href="{{ asset('brand/favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('brand/site.webmanifest') }}">
<link rel="preload" href="{{ asset('fonts/ibm-plex-sans-latin-400-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fonts/ibm-plex-sans-latin-600-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('css/prism.css') }}?v={{ @filemtime(public_path('css/prism.css')) }}">
<script nonce="{{ $cspNonce ?? '' }}">
    // Apply a saved theme before the page paints. Without a saved choice, the CSS follows the system setting.
    (function () {
        var root = document.documentElement;
        try {
            var saved = localStorage.getItem('prism-theme');
            if (saved === 'light' || saved === 'dark') { root.setAttribute('data-theme', saved); }
        } catch (e) {}
        var theme = root.getAttribute('data-theme');
        var light = theme ? theme === 'light' : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta && light) { meta.setAttribute('content', '#F6F8FC'); }
    })();
</script>
