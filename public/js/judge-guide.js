(function () {
    var dlg = document.getElementById('guide-modal');
    var openBtn = document.getElementById('guide-open');
    if (!dlg || typeof dlg.showModal !== 'function') { return; }
    var KEY = 'prism-guide-seen';
    function seen() { try { return sessionStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
    function mark() { try { sessionStorage.setItem(KEY, '1'); } catch (e) {} }
    function open() { if (!dlg.open) { dlg.showModal(); } }
    function close() { dlg.close(); }
    // Opened on load by the script, so focus has no earlier owner. Send it to the page content.
    var autoOpened = false;
    dlg.addEventListener('close', function () {
        mark();
        if (autoOpened) {
            autoOpened = false;
            var main = document.getElementById('main');
            if (main) { main.focus(); }
        } else if (openBtn) {
            openBtn.focus();
        }
    });
    document.getElementById('guide-close').addEventListener('click', close);
    document.getElementById('guide-close-x').addEventListener('click', close);
    if (openBtn) { openBtn.addEventListener('click', open); }
    // The Judge Demo opens the guide on every load. Other visitors see it once per session.
    var always = dlg.getAttribute('data-open-every-load') === '1';
    if (always || !seen()) { autoOpened = true; open(); }
})();
