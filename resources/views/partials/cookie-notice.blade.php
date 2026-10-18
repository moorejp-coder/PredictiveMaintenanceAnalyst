<div id="cookie-notice" role="region" aria-label="Cookie notice" hidden>
    <style>
        #cookie-notice { position: fixed; left: 12px; right: 12px; bottom: 12px; z-index: 1000; max-width: 560px;
                         margin: 0 auto; background: #111a2e; color: #e8eefb; border: 1px solid #26355a;
                         border-radius: 10px; padding: 14px 16px; font: 0.875rem/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
                         box-shadow: 0 8px 24px rgba(0,0,0,.4); }
        #cookie-notice[hidden] { display: none; }
        #cookie-notice p { margin: 0 0 10px; color: #a3b3d1; }
        #cookie-notice a { color: #38bdf8; }
        #cookie-notice button { background: #38bdf8; color: #0b1220; border: 0; border-radius: 6px; padding: 8px 16px;
                                font: inherit; font-weight: 600; cursor: pointer; }
        #cookie-notice button:focus-visible { outline: 2px solid #e8eefb; outline-offset: 2px; }
    </style>
    <p>PRISM sets two cookies, a session cookie and a security cookie. Both are required for the site to work. PRISM sets no analytics, advertising or tracking cookies. <a href="{{ url('/privacy') }}">Read the privacy policy</a>.</p>
    <button type="button" id="cookie-notice-ok">Got it</button>
</div>
<script>
(function () {
    var KEY = 'prism_cookie_notice_v1';
    var box = document.getElementById('cookie-notice');
    var ok = document.getElementById('cookie-notice-ok');
    if (!box || !ok) { return; }

    function seen() {
        try { return window.localStorage.getItem(KEY) === 'seen'; } catch (e) { return false; }
    }
    function remember() {
        try { window.localStorage.setItem(KEY, 'seen'); } catch (e) {}
    }

    ok.addEventListener('click', function () { remember(); box.hidden = true; });
    window.prismCookieNotice = { open: function () { box.hidden = false; ok.focus(); } };

    if (!seen()) { box.hidden = false; }
})();
</script>
</div>
