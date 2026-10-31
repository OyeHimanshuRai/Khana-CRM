/*
|------------------------------------------------------------------------------
| Products page - scan anywhere
|------------------------------------------------------------------------------
|
| A USB/Bluetooth scanner is a keyboard that types a code very fast, usually
| ending with Enter (some are set up without it). Even with the search box
| focused, this file catches that burst and:
|
|   - code matches a product  -> opens that product's edit form
|   - code matches nothing    -> saves a draft product at once, then opens it
|
| Markup contract (products/index.blade.php):
|
|   <div data-product-scan
|        data-lookup-url="/admin/products/lookup"
|        data-quick-url="/admin/products/quick-scan"
|        data-edit-url="/admin/products/:id/edit"></div>
|
| Depends on: modal.js (Modal.open), app.js (Toast)
*/
(function (window, document) {
    'use strict';

    // Humans do not type this fast between keys; scanners do.
    var MAX_KEY_GAP = 80;
    var MIN_LENGTH = 4;
    // Quiet time after a burst that counts as "scan finished" when the
    // scanner sends no Enter.
    var IDLE_MS = 120;
    // A held-down key repeats as fast as a scanner, and a quick typist can
    // match one over a short word. Barcodes carry digits; "paneer" does not.
    var LOOKS_LIKE_A_CODE = /\d/;

    var root = document.querySelector('[data-product-scan]');

    if (!root) {
        return;
    }

    var buffer = '';
    var fast = 0;
    var lastKey = 0;
    var idleTimer = null;

    function modalOpen() {
        return document.body.classList.contains('modal-open');
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function reset() {
        buffer = '';
        fast = 0;
        window.clearTimeout(idleTimer);
    }

    function handle(code) {
        var url = root.getAttribute('data-lookup-url')
            + '?q=' + encodeURIComponent(code);

        window.fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        }).then(function (payload) {
            var exact = payload && payload.data && payload.data.exact;

            if (exact) {
                window.Modal.open(
                    root.getAttribute('data-edit-url').replace(':id', exact.id),
                    { title: 'Edit Product', size: 'lg', compact: true }
                );

                return;
            }

            // Nothing matches: save a draft right now so the scan is never
            // lost, then open it to complete the details.
            return window.fetch(root.getAttribute('data-quick-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken()
                },
                body: JSON.stringify({ barcode: code })
            }).then(function (response) {
                return response.json().then(function (body) {
                    return { ok: response.ok, body: body };
                });
            }).then(function (result) {
                if (!result.ok || !result.body.success) {
                    var refused = new Error(result.body.message || 'failed');
                    refused.shown = !!result.body.message;

                    throw refused;
                }

                var list = document.querySelector('[data-ajax-list]');

                if (list && window.AjaxList) {
                    window.AjaxList.reload(list);
                }

                if (window.Toast) {
                    window.Toast.success(result.body.message || 'Saved.');
                }

                // complete=1 ticks Active on the form, so saving the details
                // puts the product on sale rather than leaving it a draft.
                window.Modal.open(
                    root.getAttribute('data-edit-url').replace(':id', result.body.data.id) + '?complete=1',
                    { title: 'Complete Product', sub: 'Scanned barcode: ' + code, size: 'lg', compact: true }
                );
            });
        }).catch(function (error) {
            if (window.Toast) {
                window.Toast.error(error.shown ? error.message : 'Could not save that barcode. Try again.');
            }
        });
    }

    // The scanner typed into a focused field (the search box): take it back out.
    function wipe(target, code) {
        if (target && target.matches && target.matches('input, textarea') && target.value) {
            target.value = target.value.split(code).join('');
            target.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function finish(target) {
        var code = buffer;
        var burst = fast >= MIN_LENGTH - 1;

        reset();

        if (code.length >= MIN_LENGTH && burst && LOOKS_LIKE_A_CODE.test(code)) {
            wipe(target, code);
            handle(code);

            return true;
        }

        return false;
    }

    document.addEventListener('keydown', function (event) {
        // Inside an open form the barcode field handles its own scan.
        if (modalOpen() || event.repeat || event.ctrlKey || event.altKey || event.metaKey) {
            reset();

            return;
        }

        var target = event.target;
        var now = Date.now();

        if (event.key === 'Enter') {
            if (finish(target)) {
                event.preventDefault();
            }

            return;
        }

        if (event.key.length !== 1) {
            return;
        }

        if (now - lastKey > MAX_KEY_GAP) {
            reset();
        } else {
            fast++;
        }

        lastKey = now;
        buffer += event.key;

        window.clearTimeout(idleTimer);
        idleTimer = window.setTimeout(function () {
            if (fast >= 5) {
                finish(target);
            } else {
                reset();
            }
        }, IDLE_MS);
    });
})(window, document);
