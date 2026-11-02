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
    function open() { box.hidden = false; ok.focus(); }
    window.prismCookieNotice = { open: open };
    // The privacy page's "Show the cookie notice again" button. The content
    // policy blocks inline onclick handlers, so the listener lives here.
    var reopen = document.getElementById('cookie-notice-reopen');
    if (reopen) { reopen.addEventListener('click', open); }

    if (!seen()) { box.hidden = false; }
})();
