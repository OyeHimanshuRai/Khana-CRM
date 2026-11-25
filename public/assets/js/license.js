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
        wrap.setAttribute('data-license-id', (event.detail.data && event.detail.data.id) || '');
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

    /* Send the key shown above to the customer. The server checks it is the key issued for this license. */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-license-send]');
        if (!button) { return; }

        var wrap = button.closest('[data-license-generate-wrap]');
        var box = button.closest('[data-license-result]');
        var email = box.querySelector('[data-license-email]');
        var key = box.querySelector('[data-license-key]').textContent;
        var id = wrap && wrap.getAttribute('data-license-id');

        if (!id || !key) { return; }

        if (!email.value || !email.checkValidity()) {
            email.focus();
            if (window.Toast) { window.Toast.error('Enter a valid email address.'); }
            return;
        }

        var meta = document.querySelector('meta[name="csrf-token"]');
        var label = button.textContent;
        button.disabled = true;
        button.textContent = 'Sending…';

        fetch(box.getAttribute('data-send-url').replace('__ID__', id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : ''
            },
            body: new URLSearchParams({ key: key, email: email.value })
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (payload) {
            if (payload && payload.success) {
                button.textContent = 'Sent ✓';
                if (window.Toast) { window.Toast.success(payload.message); }
                return;
            }
            button.disabled = false;
            button.textContent = label;
            if (window.Toast) { window.Toast.error((payload && payload.message) || 'Could not send the email.'); }
        }).catch(function () {
            button.disabled = false;
            button.textContent = label;
            if (window.Toast) { window.Toast.error('Could not reach the server. Please try again.'); }
        });
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

    /* "6 days 14 hours" left, kept ticking without a reload. */
    function tickCountdowns() {
        document.querySelectorAll('[data-license-countdown]').forEach(function (el) {
            var left = parseInt(el.getAttribute('data-license-countdown'), 10) - Math.floor(Date.now() / 1000);
            if (isNaN(left)) { return; }
            if (left <= 0) { el.textContent = 'Expired'; el.classList.add('text-danger'); return; }

            var d = Math.floor(left / 86400);
            var h = Math.floor((left % 86400) / 3600);
            var m = Math.floor((left % 3600) / 60);
            var plural = function (n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); };

            el.textContent = d > 0 ? plural(d, 'day') + ' ' + plural(h, 'hour') : plural(h, 'hour') + ' ' + m + ' min';
        });
    }

    if (document.querySelector('[data-license-countdown]')) {
        tickCountdowns();
        window.setInterval(tickCountdowns, 30000);
    }

    var waiting = document.querySelector('[data-license-poll]');
    if (waiting) { poll(waiting); }
})(window, document);
