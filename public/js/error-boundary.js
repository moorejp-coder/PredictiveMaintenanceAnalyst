/*
 * Last-resort error boundary for every PRISM page.
 *
 * When a script from this site throws an error nothing else caught, or leaves a
 * promise rejection unhandled, this shows a plain "Something went wrong" panel
 * over the page instead of leaving a half-working screen. The panel names no
 * error, file, URL or stack, and the browser log gets no entry from this file.
 *
 * It ignores noise that is not PRISM's own code: failed image or font loads,
 * browser extensions, scripts from other sites, and the harmless
 * "ResizeObserver loop" notice.
 */
(function () {
    var shown = false;

    function ours(text) {
        // True when the text names a file served by this site's /js or /build folders.
        return typeof text === 'string' && text.indexOf(location.origin + '/') !== -1 &&
            /\/(js|build)\//.test(text);
    }

    function show() {
        if (shown) { return; }
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', show, { once: true });
            return;
        }
        shown = true;

        var panel = document.createElement('div');
        panel.className = 'prism-crash';
        panel.setAttribute('role', 'alertdialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-labelledby', 'prism-crash-title');
        panel.setAttribute('aria-describedby', 'prism-crash-text');

        var card = document.createElement('div');
        card.className = 'card prism-crash__card';

        var code = document.createElement('p');
        code.className = 'prism-crash__code';
        code.textContent = 'Error';

        var h = document.createElement('h1');
        h.id = 'prism-crash-title';
        h.textContent = 'Something Went Wrong';

        var p = document.createElement('p');
        p.id = 'prism-crash-text';
        p.className = 'prism-crash__text';
        p.textContent = 'This page hit a problem and stopped. Nothing you did caused it, and no reading or score was changed. Reload the page to try again.';

        var actions = document.createElement('div');
        actions.className = 'prism-crash__actions';

        var reload = document.createElement('button');
        reload.type = 'button';
        reload.className = 'btn btn--primary';
        reload.textContent = 'Reload Page';
        reload.addEventListener('click', function () { location.reload(); });

        var home = document.createElement('a');
        home.className = 'btn btn--tertiary';
        home.href = '/';
        home.textContent = 'Return to Dashboard';

        actions.appendChild(reload);
        actions.appendChild(home);
        card.appendChild(code);
        card.appendChild(h);
        card.appendChild(p);
        card.appendChild(actions);
        panel.appendChild(card);

        // Keep keyboard and screen reader users inside the panel.
        Array.prototype.forEach.call(document.body.children, function (el) {
            if ('inert' in el) { el.inert = true; }
        });
        document.body.appendChild(panel);
        reload.focus();
    }

    window.addEventListener('error', function (event) {
        // A failed image, font or stylesheet fires on the element, not the window.
        if (event.target && event.target !== window) { return; }
        if (typeof event.message === 'string' && event.message.indexOf('ResizeObserver') === 0) { return; }
        if (!ours(event.filename)) { return; }
        show();
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event.reason;
        var stack = reason && typeof reason.stack === 'string' ? reason.stack : '';
        if (!ours(stack)) { return; }
        show();
    });
})();
