/*
|------------------------------------------------------------------------------
| Barcode scanner module - mobile camera
|------------------------------------------------------------------------------
|
| USB and Bluetooth scanners need nothing from this file: to the browser they
| are a keyboard, so they already work through [data-line-search] in
| line-items.js, which resolves an exact barcode/SKU match through
| /admin/products/lookup and calls LineItems.add() - see that file's own
| header comment.
|
| What did not exist before is a camera. This file is that: it decodes a
| barcode with the device camera, sends the decoded string to
| POST /admin/api/scanner/product - the same backend logic
| ProductController::lookup now shares via ScannerService - and on a match
| calls window.LineItems.add(), the exact function a keyboard scan or a
| manual picker click also ends in. One cart-add path for all three input
| methods; this file only ever supplies it a code string.
|
| Markup contract:
|
|   <div data-line-items
|        data-scanner-product-url="/admin/api/scanner/product"
|        data-scanner-settings='{"camera_enabled":true,...}'>
|
|     <button type="button" data-scanner-camera>Scan Barcode</button>
|   </div>
|
| data-scanner-settings is the JSON App\Support\ScannerSettings::forJs()
| prints from Settings > Company Settings > Barcode Scanner - read once per
| scan rather than fetched, so opening the camera never waits on a settings
| round trip.
|
| Depends on: public/assets/js/line-items.js (LineItems.add), app.js (Toast)
| Third-party: html5-qrcode, loaded by the page - see terminal.blade.php.
| Falls back to a clear error if the library did not load rather than
| failing silently.
*/
(function (window, document) {
    'use strict';

    /* --------------------------------------------------------------- util */

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function toast(type, message) {
        if (window.Toast && window.Toast[type]) {
            window.Toast[type](message);
        }
    }

    function settings(container) {
        var defaults = {
            camera_enabled: true,
            /* Symbology names - see FALLBACK_FORMATS and formats(). */
            formats: null,
            auto_add: true,
            duplicate_qty_increment: true,
            success_sound: false,
            error_sound: false,
            vibrate: true
        };

        var raw = container.getAttribute('data-scanner-settings');

        if (!raw) {
            return defaults;
        }

        try {
            var parsed = JSON.parse(raw);
            var merged = {};

            Object.keys(defaults).forEach(function (key) {
                merged[key] = parsed[key] !== undefined ? parsed[key] : defaults[key];
            });

            return merged;
        } catch (e) {
            return defaults;
        }
    }

    /* ---------------------------------------------------------- formats */

    /*
     | Settings > Barcode Scanner > "Scanner Type" arrives as symbology
     | names, not as html5-qrcode's numeric enum: the numbers belong to a
     | third-party library that is free to renumber them, and a name it has
     | dropped should fail in exactly one visible place rather than quietly
     | select whatever now sits at that index.
     |
     | Used when the settings blob says nothing - an older page, or a screen
     | that wires the camera up without the settings attribute.
     */
    var FALLBACK_FORMATS = [
        'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E', 'CODE_128', 'CODE_39', 'ITF', 'QR_CODE'
    ];

    /**
     * Resolve symbology names to the enum values html5-qrcode wants.
     *
     * Returns undefined - not an empty array - when nothing resolved, so the
     * library falls back to "every format it supports" rather than being told
     * to look for none of them, which decodes nothing at all.
     */
    function formats(names) {
        var supported = window.Html5QrcodeSupportedFormats;

        if (!supported) {
            return undefined;
        }

        var wanted = (names && names.length) ? names : FALLBACK_FORMATS;
        var resolved = [];

        wanted.forEach(function (name) {
            var value = supported[name];

            // typeof, not truthiness: QR_CODE is 0 in this enum, and a
            // falsy check would drop the one format everybody expects.
            if (typeof value === 'number') {
                resolved.push(value);
            }
        });

        return resolved.length ? resolved : undefined;
    }

    /* ------------------------------------------------------------ sound */

    /*
     | A short Web Audio tone rather than a shipped audio file - nothing to
     | host, nothing to fail to load, and it can start the instant a scan
     | resolves. Wrapped in try/catch because a browser that has not yet
     | seen a user gesture on the page can refuse to start an AudioContext;
     | a missed beep is not worth surfacing as an error of its own.
     */
    var audioCtx = null;

    function tone(frequency, duration, when) {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();

            var oscillator = audioCtx.createOscillator();
            var gain = audioCtx.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;

            gain.gain.setValueAtTime(0.001, audioCtx.currentTime + when);
            gain.gain.exponentialRampToValueAtTime(0.16, audioCtx.currentTime + when + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + when + duration);

            oscillator.connect(gain);
            gain.connect(audioCtx.destination);

            oscillator.start(audioCtx.currentTime + when);
            oscillator.stop(audioCtx.currentTime + when + duration + 0.02);
        } catch (e) {
            // Autoplay/AudioContext restrictions - see comment above.
        }
    }

    function playSuccess() { tone(1760, 0.1, 0); }
    function playError() { tone(300, 0.16, 0); tone(300, 0.16, 0.18); }

    /*
     | The half of "Sound / Vibration" that survives a handset on silent and
     | a shop floor too loud for a beep - which is most of the reason the
     | setting defaults on.
     |
     | navigator.vibrate is simply absent on desktop and on iOS Safari, so
     | this is a no-op there rather than an error; a browser that has it may
     | still refuse before the page has seen a user gesture, and a missed
     | buzz is not worth surfacing.
     */
    function buzz(pattern) {
        try {
            if (window.navigator && typeof window.navigator.vibrate === 'function') {
                window.navigator.vibrate(pattern);
            }
        } catch (e) {
            // Unsupported or blocked - see comment above.
        }
    }

    function feedback(cfg, ok) {
        if (ok) {
            if (cfg.success_sound) { playSuccess(); }
            if (cfg.vibrate) { buzz(45); }

            return;
        }

        if (cfg.error_sound) { playError(); }
        if (cfg.vibrate) { buzz([60, 55, 60]); }
    }

    /* ------------------------------------------------- secure context */

    /** Is there a camera API on this page at all? */
    function hasCameraApi() {
        return !! (window.navigator
            && window.navigator.mediaDevices
            && window.navigator.mediaDevices.getUserMedia);
    }

    /**
     * Say which of the two secure-context problems this actually is.
     *
     * They need opposite fixes and look identical from the shop floor, so
     * one message covering both is a message nobody can act on. Plain HTTP
     * is answered with the exact https:// address to open - retyping a LAN
     * IP on a phone is where this usually goes wrong.
     */
    function insecureReason() {
        if (window.location.protocol !== 'https:') {
            return 'This page is open over HTTP, and phones only allow the camera over HTTPS. '
                + 'Open ' + 'https://' + window.location.host + window.location.pathname
                + ' instead — accept the certificate warning once, then Scan Barcode works.';
        }

        // Already HTTPS, so the page is secure and the browser is still
        // withholding the camera API - a hardened build, a policy, or a
        // browser too old to have it.
        return 'This browser is not offering a camera on this page. '
            + 'Try Chrome, or scan with the wireless scanner — it needs no permission.';
    }

    /** The same page, over HTTPS. */
    function secureUrl() {
        return 'https://' + window.location.host
            + window.location.pathname
            + window.location.search;
    }

    /**
     * Offer the secure address as something to tap.
     *
     * A real <a href>, not a scripted redirect: the operator can see where
     * they are being sent before they go, and the browser's own certificate
     * interstitial - which they must accept once - is part of that journey
     * rather than something that ambushes them.
     *
     * Deliberately not automatic. Leaving the page discards whatever is in
     * the cart, which is a bad surprise mid-sale, so the warning below says
     * so and the operator chooses the moment.
     */
    var securePrompt = null;

    function openSecurePrompt() {
        if (securePrompt) {
            securePrompt.querySelector('[data-scanner-secure-link]').href = secureUrl();
            securePrompt.hidden = false;
            document.body.classList.add('modal-open');
            window.requestAnimationFrame(function () { securePrompt.classList.add('is-open'); });

            return;
        }

        var modal = document.createElement('div');
        modal.className = 'modal scanner-secure-modal';
        modal.hidden = true;
        modal.innerHTML =
            '<div class="modal-backdrop" data-scanner-secure-close></div>' +
            '<div class="modal-dialog is-sm" role="dialog" aria-modal="true" aria-labelledby="scanner-secure-title">' +
                '<div class="modal-head">' +
                    '<div><h2 class="modal-title" id="scanner-secure-title">Open the secure page to scan</h2></div>' +
                    '<button type="button" class="btn btn-icon btn-ghost" data-scanner-secure-close aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="modal-body">' +
                    '<p class="text-sm">' +
                        'Phones only allow the camera on a secure (HTTPS) page. This one is open over ' +
                        'plain HTTP, so there is no camera to ask for.' +
                    '</p>' +
                    '<p class="text-sm">' +
                        'Tap below, then choose <strong>Advanced &rarr; Proceed</strong> on the certificate ' +
                        'warning. You only have to accept it once on this device.' +
                    '</p>' +
                    '<p class="text-xs text-muted">' +
                        'Anything already in the cart is not carried across, so finish the current bill first.' +
                    '</p>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-sm" data-scanner-secure-close>Stay here</button>' +
                        '<a class="btn btn-primary btn-sm" data-scanner-secure-link href="' + secureUrl() + '">' +
                            'Open secure page' +
                        '</a>' +
                    '</div>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);
        securePrompt = modal;

        modal.addEventListener('click', function (event) {
            if (event.target.closest('[data-scanner-secure-close]')) {
                closeSecurePrompt();
            }
        });

        modal.hidden = false;
        document.body.classList.add('modal-open');
        window.requestAnimationFrame(function () { modal.classList.add('is-open'); });
    }

    function closeSecurePrompt() {
        if (!securePrompt || securePrompt.hidden) {
            return;
        }

        securePrompt.classList.remove('is-open');
        document.body.classList.remove('modal-open');

        window.setTimeout(function () {
            if (securePrompt) {
                securePrompt.hidden = true;
            }
        }, 200);
    }

    /* --------------------------------------------------------- resolve */

    /**
     * POST one code to the scanner API and hand the parsed body back.
     *
     * Never rejects - a network failure resolves to { ok: false, data: null }
     * so callers do not need a separate .catch().
     */
    function resolve(container, code) {
        var url = container.getAttribute('data-scanner-product-url');

        return window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: JSON.stringify({ code: code })
        })
            .then(function (response) {
                return response.json()
                    .catch(function () { return null; })
                    .then(function (data) { return { ok: response.ok, data: data }; });
            })
            .catch(function () {
                return { ok: false, data: null };
            });
    }

    /**
     * Resolve a scanned code and put it in the cart - the shared ending
     * for every input method this module drives (today: the camera only).
     */
    function handleScan(container, code) {
        code = (code || '').trim();

        if (!code) {
            return;
        }

        var cfg = settings(container);

        resolve(container, code).then(function (result) {
            var payload = result.data;

            if (result.ok && payload && payload.success && payload.product) {
                var product = payload.product;

                if (!cfg.auto_add) {
                    // Settings > Barcode Scanner > "Auto Add Product" off:
                    // hand the code to the existing search box instead of the
                    // cart, so the cashier reviews and presses Enter
                    // themselves - the same exact-match path a keyboard scan
                    // already uses, just not fired automatically.
                    //
                    // "Auto Enter After Scan" does not override this. Writing
                    // .value in script fires no 'input' event, so line-items.js
                    // never sees this land and the code genuinely waits; the
                    // cashier asked to review, and one setting saying so is
                    // enough.
                    var search = container.querySelector('[data-line-search]');

                    if (search) {
                        search.value = product.sku || code;
                        search.focus();
                    }

                    toast('info', product.name + ' found - press Enter to add it.');
                    feedback(cfg, true);

                    return;
                }

                if (window.LineItems) {
                    window.LineItems.add(container, product, {
                        forceNewRow: ! cfg.duplicate_qty_increment
                    });
                }

                feedback(cfg, true);

                return;
            }

            var message = (payload && payload.message) || 'Barcode not found';
            toast('error', message);
            feedback(cfg, false);
        });
    }

    /* ------------------------------------------------------------ camera */

    var camera = {
        modal: null,
        instance: null,
        container: null,
        closing: false
    };

    function buildModal() {
        if (camera.modal) {
            return camera.modal;
        }

        var modal = document.createElement('div');
        modal.className = 'modal scanner-modal';
        modal.hidden = true;
        modal.innerHTML =
            '<div class="modal-backdrop" data-scanner-close></div>' +
            '<div class="modal-dialog is-sm" role="dialog" aria-modal="true" aria-labelledby="scanner-modal-title">' +
                '<div class="modal-head">' +
                    '<div><h2 class="modal-title" id="scanner-modal-title">Scan Barcode</h2></div>' +
                    '<button type="button" class="btn btn-icon btn-ghost" data-scanner-close aria-label="Close">' +
                        '&times;' +
                    '</button>' +
                '</div>' +
                '<div class="modal-body">' +
                    '<div id="scanner-camera-view" class="scanner-camera-view"></div>' +
                    '<p class="text-xs text-muted scanner-camera-hint">' +
                        'Point the camera at a barcode or QR code. It adds itself once read - nothing to press.' +
                    '</p>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);
        camera.modal = modal;

        modal.addEventListener('click', function (event) {
            if (event.target.closest('[data-scanner-close]')) {
                closeCamera();
            }
        });

        return modal;
    }

    function openCamera(container) {
        var cfg = settings(container);

        if (!cfg.camera_enabled) {
            toast('error', 'Camera scanning is turned off. Enable it in Settings > Barcode Scanner.');

            return;
        }

        if (!window.Html5Qrcode) {
            toast('error', 'Camera scanning could not load. Check your connection and reload the page.');

            return;
        }

        /*
         | Browsers hand out a camera only in a secure context: HTTPS, or
         | localhost. A counter reaching this app over http://<LAN-ip> - which
         | is how a phone on the shop wifi gets here - has no camera API at
         | all, and no permission prompt will ever appear.
         |
         | Checked before opening the modal so the answer is a sentence the
         | shop can act on, rather than the generic "could not start the
         | camera" that the failed start() below would otherwise produce.
         | The wireless scanner is unaffected: a keyboard needs no permission.
         */
        /*
         | The test is whether the camera API is actually there, not whether
         | window.isSecureContext says it should be. An older Android WebView
         | does not define isSecureContext at all, and reading its absence as
         | "insecure" would refuse the camera on a page that has one.
         | getUserMedia is the thing that is about to be called, so its
         | presence is the honest question; isSecureContext is consulted only
         | to word the refusal.
         */
        if (! hasCameraApi()) {
            /*
             | On plain HTTP the fix is a different address, and a toast is a
             | dead end for it: Toast renders with textContent, so the URL
             | cannot be a link, and retyping a LAN IP on a phone keypad is
             | where this reliably goes wrong. Offer the tap instead.
             */
            if (window.location.protocol !== 'https:') {
                openSecurePrompt();

                return;
            }

            toast('error', insecureReason());

            return;
        }

        var modal = buildModal();
        camera.container = container;
        camera.closing = false;

        modal.hidden = false;
        document.body.classList.add('modal-open');
        window.requestAnimationFrame(function () { modal.classList.add('is-open'); });

        camera.instance = new window.Html5Qrcode('scanner-camera-view', {
            // Undefined when nothing resolved, which the library reads as
            // "support everything" - see formats().
            formatsToSupport: formats(cfg.formats),
            verbose: false
        });

        camera.instance.start(
            { facingMode: 'environment' },
            { fps: 12, qrbox: { width: 260, height: 160 } },
            function (decodedText) {
                // One shot: several frames can decode the same code before
                // the stream physically stops, which would otherwise scan
                // the same product two or three times from one look.
                if (camera.closing) {
                    return;
                }

                var code = decodedText;
                var target = camera.container;

                closeCamera();
                handleScan(target, code);
            },
            function () {
                // Per-frame "nothing decoded in this frame yet" - normal,
                // not an error; html5-qrcode calls this continuously.
            }
        ).catch(function (error) {
            closeCamera();

            var text = String(error).toLowerCase();
            var message = text.indexOf('permission') !== -1 || text.indexOf('notallowed') !== -1
                ? 'Camera permission denied.'
                : 'Could not start the camera.';

            toast('error', message);
        });
    }

    function closeCamera() {
        if (!camera.modal || camera.modal.hidden || camera.closing) {
            return;
        }

        camera.closing = true;
        camera.modal.classList.remove('is-open');
        document.body.classList.remove('modal-open');

        window.setTimeout(function () {
            if (camera.modal) {
                camera.modal.hidden = true;
            }
        }, 200);

        var instance = camera.instance;
        camera.instance = null;

        if (instance) {
            instance.stop()
                .catch(function () { /* already stopped/never started */ })
                .then(function () {
                    try { instance.clear(); } catch (e) { /* nothing mounted */ }
                });
        }
    }

    /* --------------------------------------------------------------- wire */

    function boot() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-scanner-camera]');

            if (!trigger) {
                return;
            }

            event.preventDefault();

            var container = trigger.closest('[data-line-items]');

            if (container) {
                openCamera(container);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            if (camera.modal && !camera.modal.hidden) {
                closeCamera();
            }

            if (securePrompt && !securePrompt.hidden) {
                closeSecurePrompt();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.Scanner = {
        openCamera: openCamera,
        closeCamera: closeCamera,
        handleScan: handleScan
    };
})(window, document);
