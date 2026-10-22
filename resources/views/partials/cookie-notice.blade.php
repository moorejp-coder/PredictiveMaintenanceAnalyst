<div id="cookie-notice" role="region" aria-label="Cookie notice" hidden>
    <style nonce="{{ $cspNonce ?? '' }}">
        #cookie-notice { position: fixed; left: max(12px, env(safe-area-inset-left)); right: max(12px, env(safe-area-inset-right)); bottom: max(12px, env(safe-area-inset-bottom)); z-index: 1000; max-width: 560px;
                         margin: 0 auto; background: var(--prism-surface, #111A2E); color: var(--prism-text, #E8EEFB); border: 1px solid var(--prism-border, #26355A);
                         border-radius: 10px; padding: 14px 16px; font: 0.875rem/1.5 var(--prism-font-ui, system-ui, sans-serif);
                         box-shadow: 0 8px 24px rgba(0,0,0,.4); }
        #cookie-notice[hidden] { display: none; }
        html:has(#cookie-notice:not([hidden])) { scroll-padding-bottom: 200px; }
        #cookie-notice p { margin: 0 0 10px; color: var(--prism-muted, #A3B3D1); }
        #cookie-notice a { color: var(--prism-accent, #8AB4F8); }
        #cookie-notice button { background: var(--prism-accent, #8AB4F8); color: var(--prism-accent-ink, #0B1220); border: 0; border-radius: 8px; padding: 8px 20px; min-height: 44px;
                                font: inherit; font-weight: 600; cursor: pointer; }
        @media (pointer: coarse) { #cookie-notice button { min-height: 44px; } }
        #cookie-notice button:focus-visible { outline: 2px solid var(--prism-text, #E8EEFB); outline-offset: 2px; }
    </style>
    <p>PRISM sets two cookies, a session cookie and a security cookie. Both are required for the site to work. PRISM sets no analytics, advertising or tracking cookies. <a href="{{ url('/privacy') }}">Read the privacy policy</a>.</p>
    <button type="button" id="cookie-notice-ok">Got it</button>
</div>
<script src="{{ asset('js/cookie-notice.js') }}?v={{ filemtime(public_path('js/cookie-notice.js')) }}"></script>
</div>
