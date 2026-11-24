/*
|------------------------------------------------------------------------------
| License screens
|------------------------------------------------------------------------------
|
| Delegated and dependency-free (apart from app.js's data-ajax forms and
| Toast). Three small jobs:
|
|   [data-license-generate]   after a key is generated, swap the form for the
|                             one-time key box (the plain key is only ever in
|                             that single response).
|   [data-license-copy]       copy that key.
|   [data-license-form]       tidy the key as it is typed (upper case).
|   [data-license-poll]       ask the server whether approval has happened,
|                             and go to the dashboard when it has.
*/
(function (window, document) {
    'use strict';

    document.addEventListener('ajax:success', function (event) {
        var form = event.target;
        if (!form || !form.matches || !form.matches('[data-license-generate]')) { return; }

        var key = event.detail && event.detail.data && event.detail.data.key;
        var wrap = form.closest('[data-license-generate-wrap]');
        if (!key || !wrap) { return; }

        wrap.querySelector('[data-license-key]').textContent = key;
        var panel = wrap.querySelector('[data-license-panel]');
        if (panel) { panel.hidden = true; }
        wrap.querySelector('[data-license-result]').hidden = false;
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-license-copy]');
        if (!button) { return; }

        var box = button.closest('[data-license-result]');
        var key = box && box.querySelector('[data-license-key]').textContent;
        if (!key) { return; }

        function done() {
            if (window.Toast) { window.Toast.success('Key copied.'); }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(key).then(done, function () { window.prompt('Copy the key:', key); });
        } else {
            window.prompt('Copy the key:', key);
        }
    });

    document.addEventListener('input', function (event) {
        var input = event.target;
        if (input && input.matches && input.matches('.license-input')) {
            var at = input.selectionStart;
            input.value = input.value.toUpperCase();
            try { input.setSelectionRange(at, at); } catch (e) { /* not all types support it */ }
        }
    });

    function poll(box) {
        var url = box.getAttribute('data-license-poll');

        window.setTimeout(function tick() {
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    var data = payload && payload.data;
                    if (data && data.redirect) { window.location.assign(data.redirect); return; }
                    if (data && data.state && data.state !== 'pending') { window.location.reload(); return; }
                    window.setTimeout(tick, 8000);
                })
                .catch(function () { window.setTimeout(tick, 15000); });
        }, 8000);
    }

    var waiting = document.querySelector('[data-license-poll]');
    if (waiting) { poll(waiting); }
})(window, document);
