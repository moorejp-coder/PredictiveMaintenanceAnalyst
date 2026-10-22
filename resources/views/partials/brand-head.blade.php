<meta name="theme-color" content="#101725">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="icon" href="{{ asset('brand/favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('brand/site.webmanifest') }}">
<link rel="preload" href="{{ asset('fonts/ibm-plex-sans-latin-400-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fonts/ibm-plex-sans-latin-600-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('css/prism.css') }}?v={{ @filemtime(public_path('css/prism.css')) }}">
<script src="{{ asset('js/theme-init.js') }}?v={{ filemtime(public_path('js/theme-init.js')) }}"></script>
