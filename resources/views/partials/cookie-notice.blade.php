<div id="cookie-notice" role="region" aria-label="Cookie notice" hidden>
    <style nonce="{{ $cspNonce ?? '' }}">
        #cookie-notice { position: fixed; left: max(12px, env(safe-area-inset-left)); right: max(12px, env(safe-area-inset-right)); bottom: max(12px, env(safe-area-inset-bottom)); z-index: 1000; max-width: 560px;
                         margin: 0 auto 0 0; background: var(--prism-surface); color: var(--prism-text); border: 1px solid var(--prism-control);
                         border-radius: var(--prism-radius-lg); padding: 14px 16px; font: 0.875rem/1.5 var(--prism-font-ui);
                         box-shadow: var(--prism-dialog-shadow); }
        #cookie-notice[hidden] { display: none; }
        html:has(#cookie-notice:not([hidden])) { scroll-padding-bottom: 200px; }
        #cookie-notice p { margin: 0 0 10px; color: var(--prism-muted); }
        #cookie-notice a { color: var(--prism-accent); }
        #cookie-notice button { background: var(--prism-accent); color: var(--prism-accent-ink); border: 0; border-radius: var(--prism-radius-md); padding: 8px 20px; min-height: 44px;
                                font: inherit; font-weight: 600; cursor: pointer; }
        #cookie-notice button:hover { background: var(--prism-accent-hover); }
        #cookie-notice button:focus-visible { outline: 2px solid var(--prism-text); outline-offset: 2px; }
    </style>
    <p>PRISM sets two cookies, a session cookie and a security cookie. Both are required for the site to work. PRISM sets no analytics, advertising or tracking cookies. <a href="{{ url('/privacy') }}">Read the privacy policy</a>.</p>
    <button type="button" id="cookie-notice-ok">Got it</button>
</div>
<script src="{{ asset('js/cookie-notice.js') }}?v={{ filemtime(public_path('js/cookie-notice.js')) }}"></script>
</div>
