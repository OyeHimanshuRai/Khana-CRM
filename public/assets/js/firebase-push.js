/*
|------------------------------------------------------------------------------
| "Notify me on this device" - Firebase Cloud Messaging for the admin panel
|------------------------------------------------------------------------------
|
| Only runs when the platform has Firebase configured (Settings > Firebase):
| the layout prints window.__firebase then, and nothing otherwise.
|
| Never asks for permission on page load. A browser prompt nobody asked for
| is the one most people click Block on, and a block cannot be undone from
| the page. So:
|
|   permission "default"  -> a [data-push-enable] button appears; clicking it
|                            asks, and registers this device
|   permission "granted"  -> the device is registered quietly on every load,
|                            which also refreshes a token Google rotated
|   permission "denied"   -> nothing; the bell still works by polling
|
| A message that arrives while this tab is open asks the bell to check now
| (alerts-live.js) rather than drawing a second, system notification on top
| of the toast it is about to show.
*/
(function (window, document) {
    'use strict';

    var cfg = window.__firebase;

    if (!cfg || !cfg.config || !('serviceWorker' in navigator) || !('Notification' in window)) {
        return;
    }

    var SDK = 'https://www.gstatic.com/firebasejs/10.14.1/';
    var buttons = document.querySelectorAll('[data-push-enable]');

    function load(src) {
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function swUrl() {
        var query = Object.keys(cfg.config).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(cfg.config[key]);
        }).join('&');

        return cfg.swUrl + '?' + query;
    }

    var ready = null;

    /** The SDK, the app and the worker - once, on demand. */
    function boot() {
        if (ready) { return ready; }

        ready = load(SDK + 'firebase-app-compat.js')
            .then(function () { return load(SDK + 'firebase-messaging-compat.js'); })
            .then(function () {
                if (!window.firebase.apps.length) {
                    window.firebase.initializeApp(cfg.config);
                }

                return navigator.serviceWorker.register(swUrl(), { scope: cfg.scope });
            })
            .then(function (registration) {
                var messaging = window.firebase.messaging();

                messaging.onMessage(function () {
                    window.dispatchEvent(new CustomEvent('erp:alert'));
                });

                return { messaging: messaging, registration: registration };
            });

        return ready;
    }

    function register() {
        return boot().then(function (fb) {
            return fb.messaging.getToken({
                vapidKey: cfg.vapidKey,
                serviceWorkerRegistration: fb.registration
            });
        }).then(function (token) {
            if (!token) { throw new Error('No token'); }

            return window.fetch(cfg.registerUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ token: token, label: navigator.platform || null })
            });
        }).then(function (response) {
            if (!response.ok) { throw new Error(String(response.status)); }
            return response.json();
        });
    }

    function showButtons(show) {
        Array.prototype.forEach.call(buttons, function (button) {
            button.hidden = !show;
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-push-enable]');
        if (!button) { return; }

        event.preventDefault();

        window.Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                showButtons(false);
                if (window.Toast) {
                    window.Toast.warning('Notifications are blocked for this site. You can allow them from the padlock in the address bar.');
                }
                return;
            }

            return register().then(function (payload) {
                showButtons(false);
                if (window.Toast) {
                    window.Toast.success((payload && payload.message) || 'This device will now get notifications.');
                }
            });
        }).catch(function () {
            if (window.Toast) {
                window.Toast.error('Could not turn on notifications on this device. Please try again.');
            }
        });
    });

    if (window.Notification.permission === 'granted') {
        showButtons(false);
        register().catch(function () { /* the bell still polls */ });
    } else {
        showButtons(window.Notification.permission === 'default');
    }
})(window, document);
