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
    if (meta && light) { meta.setAttribute('content', '#F3F5F9'); }
})();
