/*
 * The set-password and unlock pages. The emailed link carries the token and
 * the email address after "#", so the browser never sends them to the server
 * in the address and no access log or proxy records them. This script reads
 * them, removes them from the address bar and history, asks the server
 * whether the link is good, then fills the hidden form fields.
 */
(function () {
    var root = document.querySelector('[data-link-check]');
    if (!root) { return; }

    var checking = document.getElementById('link-checking');
    var invalid = document.getElementById('link-invalid');
    var limited = document.getElementById('link-limited');
    var forms = document.getElementById('link-forms');

    // After a form error the server shows the forms again with the values it
    // already had. There is nothing to read from the address then.
    if ((forms && !forms.hidden) || root.hasAttribute('data-link-done')) { return; }

    var params = new URLSearchParams(window.location.hash.slice(1));
    var token = params.get('t') || '';
    var email = params.get('e') || '';
    if (window.location.hash) {
        try { window.history.replaceState(null, '', window.location.pathname); } catch (e) {}
    }

    function show(el) {
        if (checking) { checking.hidden = true; }
        if (el) { el.hidden = false; }
    }

    if (token === '' || email === '') { show(invalid); return; }

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    fetch(root.getAttribute('data-link-check'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ token: token, email: email })
    }).then(function (response) {
        if (response.status === 429) { show(limited); return; }
        if (!response.ok) { show(invalid); return; }

        root.querySelectorAll('input[name="token"]').forEach(function (input) { input.value = token; });
        root.querySelectorAll('input[name="email"]').forEach(function (input) { input.value = email; });
        show(forms);
        var first = document.getElementById('password');
        if (first) { first.focus(); }
    }).catch(function () { show(invalid); });
})();
