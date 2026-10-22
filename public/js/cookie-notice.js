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
