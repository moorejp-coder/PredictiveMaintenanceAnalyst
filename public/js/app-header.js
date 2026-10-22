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
        if (meta) { meta.setAttribute('content', isLight() ? '#F3F5F9' : '#101725'); }
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
