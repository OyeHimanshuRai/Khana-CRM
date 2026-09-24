/*
|------------------------------------------------------------------------------
| The tender rows on a table bill
|------------------------------------------------------------------------------
|
| Two small jobs on the settle form, both of them about the same thing: a
| table that is paying partly one way and partly another.
|
|   1. The second row's amount follows the first. A bill of 260 with 200
|      typed into cash leaves 60, and sixty is what the second tender is for.
|      The cashier was going to work that out and type it; doing it here is
|      one less number to get wrong while a guest waits.
|
|   2. Anything going on UPI gets a code to scan, for that amount. Fetched
|      rather than rendered with the page, because the amount is not known
|      until step 1 has happened.
|
| Markup contract:
|
|   <form data-table-bill
|         data-tb-payable="260.00"
|         data-tb-qr-url="/admin/table-bills/12/upi-qr">   (absent: no VPA)
|     <div data-tb-row>
|       <select data-tb-method>  <input data-tb-amount>  <input data-tb-ref>
|     </div>
|     <div data-tb-qr hidden>
|       <div data-tb-qr-canvas></div>
|       <span data-tb-qr-amount></span>
|       <p data-tb-qr-payee></p>
|     </div>
|   </form>
|
| Scanning the code settles nothing. The platform is not in the payment path -
| see App\Support\UpiQr - so the form still has to be submitted by somebody
| who has watched the money arrive. Nothing here submits it.
|
| Delegated from document, so a bill swapped into a modal from the floor plan
| needs no init call. That is also why it is loaded globally rather than with
| a <script> beside the form: a script that arrives in a fragment never runs.
|
| Depends on: nothing.
*/
(function (window, document) {
    'use strict';

    /** How long to wait after the last keystroke before asking for a code. */
    var QR_DEBOUNCE_MS = 350;

    var timers = new WeakMap();
    var inFlight = new WeakMap();

    /* --------------------------------------------------------------- util */

    function form(element) {
        return element && element.closest ? element.closest('[data-table-bill]') : null;
    }

    function rows(scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('[data-tb-row]'));
    }

    function field(row, name) {
        return row.querySelector('[data-tb-' + name + ']');
    }

    /**
     * A number out of an input, or zero.
     *
     * An empty box means "nothing on this tender", which is zero for the
     * arithmetic below - not a reason to stop doing it.
     */
    function amountOf(row) {
        var input = field(row, 'amount');
        var value = input ? parseFloat(input.value) : NaN;

        return isFinite(value) && value > 0 ? value : 0;
    }

    function money(value) {
        return value.toFixed(2);
    }

    /* ------------------------------------------------------ the remainder */

    /**
     * Put what the first tender leaves behind into the second.
     *
     * Skipped the moment the cashier has typed in the second box themselves.
     * Overwriting a number somebody entered on purpose is worse than leaving
     * them to do the subtraction, and a till that fights the person using it
     * gets switched off.
     */
    function fillRemainder(scope) {
        var list = rows(scope);

        if (list.length < 2) {
            return;
        }

        var second = field(list[1], 'amount');

        if (!second || second.dataset.tbTouched === '1') {
            return;
        }

        var payable = parseFloat(scope.getAttribute('data-tb-payable'));

        if (!isFinite(payable)) {
            return;
        }

        var left = payable - amountOf(list[0]);

        // Rounded to the paisa before it is compared to zero: floating point
        // makes 260 - 200 into 59.999999999999996 often enough to matter, and
        // a till that offers to take 59.999999999999996 looks broken.
        left = Math.round(left * 100) / 100;

        second.value = left > 0 ? money(left) : '';
    }

    /* ----------------------------------------------------------- the code */

    /** What the table is putting on UPI, across every tender row. */
    function upiTotal(scope) {
        return rows(scope).reduce(function (sum, row) {
            var method = field(row, 'method');

            return method && method.value === 'upi' ? sum + amountOf(row) : sum;
        }, 0);
    }

    function panel(scope) {
        return scope.querySelector('[data-tb-qr]');
    }

    function hideQr(scope) {
        var box = panel(scope);

        if (box) {
            box.hidden = true;
            box.removeAttribute('data-tb-qr-for');
        }
    }

    function showQr(scope, code, amount) {
        var box = panel(scope);

        if (!box) {
            return;
        }

        var canvas = box.querySelector('[data-tb-qr-canvas]');
        var shown = box.querySelector('[data-tb-qr-amount]');
        var payee = box.querySelector('[data-tb-qr-payee]');

        // The SVG comes from our own endpoint, which built it from a VPA this
        // shop's settings hold. It is markup rather than text by the time it
        // is any use, so innerHTML is the only way in.
        if (canvas) { canvas.innerHTML = code.svg || ''; }
        if (shown) { shown.textContent = '₹' + (code.amount || money(amount)); }
        if (payee) { payee.textContent = code.payee || ''; }

        box.setAttribute('data-tb-qr-for', money(amount));
        box.hidden = false;
    }

    /**
     * Ask the server for a code covering the UPI part of this bill.
     *
     * Debounced, because the amount changes on every keystroke and a code per
     * keystroke is a request per keystroke. The last one wins: an older reply
     * arriving late is dropped rather than painted over a newer code, which
     * is the bug that shows a guest a QR for the amount before the one they
     * are looking at.
     */
    function refreshQr(scope) {
        var url = scope.getAttribute('data-tb-qr-url');

        if (!url) {
            return;
        }

        window.clearTimeout(timers.get(scope));

        var amount = Math.round(upiTotal(scope) * 100) / 100;

        if (amount <= 0) {
            hideQr(scope);

            return;
        }

        timers.set(scope, window.setTimeout(function () {
            var token = {};
            inFlight.set(scope, token);

            var query = url + (url.indexOf('?') === -1 ? '?' : '&') + 'amount=' + encodeURIComponent(money(amount));

            window.fetch(query, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (response) { return response.json(); })
                .then(function (body) {
                    if (inFlight.get(scope) !== token) {
                        return;
                    }

                    var code = body && body.data;

                    if (body && body.success !== false && code && code.svg) {
                        showQr(scope, code, amount);
                    } else {
                        hideQr(scope);
                    }
                })
                .catch(function () {
                    if (inFlight.get(scope) === token) {
                        // A code that cannot be fetched is not an error worth
                        // interrupting a settle for. The reference field is
                        // still there and the bill still raises.
                        hideQr(scope);
                    }
                });
        }, QR_DEBOUNCE_MS));
    }

    /* ------------------------------------------------------------ wiring */

    document.addEventListener('input', function (event) {
        var scope = form(event.target);

        if (!scope) {
            return;
        }

        if (event.target.hasAttribute('data-tb-amount')) {
            var list = rows(scope);

            // Typing in the second box is what makes it the cashier's number
            // rather than ours, from now until the bill is raised.
            if (list.length > 1 && event.target === field(list[1], 'amount')) {
                event.target.dataset.tbTouched = '1';
            } else {
                fillRemainder(scope);
            }

            refreshQr(scope);
        }
    });

    document.addEventListener('change', function (event) {
        var scope = form(event.target);

        if (scope && event.target.hasAttribute('data-tb-method')) {
            refreshQr(scope);
        }
    });

    /*
     | First paint.
     |
     | The first row arrives holding the whole payable, so the remainder is
     | zero and there is nothing to fill - but a bill opened on a table that
     | is already part-settled is not that, and running once here means the
     | second box is right before anybody touches it.
     */
    function prime(scope) {
        fillRemainder(scope);
        refreshQr(scope);
    }

    function primeAll(root) {
        var scope = root && root.querySelectorAll ? root : document;

        Array.prototype.forEach.call(scope.querySelectorAll('[data-table-bill]'), prime);
    }

    document.addEventListener('DOMContentLoaded', function () { primeAll(document); });

    /*
     | Bills arrive after load too - swapped into a modal from the floor plan,
     | or refreshed in place by ajax-list.js once a bill has been raised
     | against a table that still owes something.
     */
    if (window.MutationObserver) {
        new window.MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType !== 1) {
                        return;
                    }

                    if (node.matches && node.matches('[data-table-bill]')) {
                        prime(node);
                    } else {
                        primeAll(node);
                    }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
})(window, document);
