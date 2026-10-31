/*
 * Subscription page: pick a plan, see the price, pay.
 *
 * The page never decides money. It asks the server for a quote, shows it, and
 * on confirmation asks for a checkout; the price the server returns is the one
 * Razorpay is opened with, and the server re-verifies the signed result.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-billing]');
    if (!root) {
        return;
    }

    var confirmBox = root.querySelector('[data-billing-confirm]');
    var summary = root.querySelector('[data-billing-summary]');
    var payButton = root.querySelector('[data-billing-pay]');
    var pending = null; // { planId, period, quote }

    function toast(type, message) {
        if (window.Toast && window.Toast[type]) {
            window.Toast[type](message);
        } else {
            window.alert(message);
        }
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function money(amount, currency) {
        return (currency || 'INR') + ' ' + new Intl.NumberFormat('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    }

    function formatDate(iso) {
        return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function busy(on) {
        root.setAttribute('aria-busy', on ? 'true' : 'false');
        root.querySelectorAll('button').forEach(function (b) { b.disabled = !!on; });
    }

    /* JSON in, JSON out; a failed request always ends in a readable message. */
    function request(method, url, body) {
        var options = {
            method: method,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf()
            },
            credentials: 'same-origin'
        };

        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok || payload.success === false) {
                    var firstError = payload.errors && Object.values(payload.errors)[0];
                    var message = (Array.isArray(firstError) ? firstError[0] : firstError)
                        || payload.message
                        || 'Something went wrong. Please try again.';
                    throw new Error(message);
                }
                return payload;
            });
        });
    }

    function hideConfirm() {
        pending = null;
        confirmBox.hidden = true;
        summary.innerHTML = '';
    }

    /* ------------------------------------------------------------ period */

    root.querySelectorAll('[data-billing-period]').forEach(function (button) {
        button.addEventListener('click', function () {
            root.setAttribute('data-period', button.getAttribute('data-billing-period'));
            root.querySelectorAll('[data-billing-period]').forEach(function (b) {
                b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
            });
            // A renewal request asks for the period showing, like a payment would.
            root.querySelectorAll('[data-renewal-period]').forEach(function (input) {
                input.value = button.getAttribute('data-billing-period');
            });
            hideConfirm();
        });
    });

    /* ------------------------------------------------------------- quote */

    root.addEventListener('click', function (event) {
        var select = event.target.closest('[data-plan-select]');
        if (!select) {
            return;
        }

        var planId = parseInt(select.getAttribute('data-plan-select'), 10);
        var period = root.getAttribute('data-period');

        busy(true);

        request('GET', root.getAttribute('data-quote-url') + '?plan_id=' + planId + '&period=' + period)
            .then(function (payload) {
                var q = payload.data;
                pending = { planId: planId, period: period, quote: q };

                var lines = '<div class="billing-total">' + (q.amount > 0 ? 'Pay ' + money(q.amount, q.currency) : 'No payment needed') + '</div>';

                if (q.credit > 0) {
                    lines += '<div class="billing-note">' + money(q.list_price, q.currency) + ' less '
                        + money(q.credit, q.currency) + ' credit for the unused part of your current term.</div>';
                }
                if (q.gst > 0) {
                    lines += '<div class="billing-note">' + money(q.taxable, q.currency) + ' plus '
                        + money(q.gst, q.currency) + ' GST (' + q.gst_rate + '%).</div>';
                }
                lines += '<div class="billing-note">'
                    + (q.renewal ? 'Your term will run until ' : 'Your new term will run until ')
                    + formatDate(q.new_ends_at) + '.</div>';

                summary.innerHTML = lines;
                payButton.textContent = q.amount > 0 ? 'Pay now' : 'Switch plan';
                confirmBox.hidden = false;
                confirmBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(function (error) { toast('error', error.message); })
            .then(function () { busy(false); });
    });

    root.querySelector('[data-billing-dismiss]').addEventListener('click', hideConfirm);

    /* ---------------------------------------------------------- checkout */

    function loadCheckoutScript(src) {
        return new Promise(function (resolve, reject) {
            if (window.Razorpay) {
                resolve();
                return;
            }
            var script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = function () { reject(new Error('Could not load the payment window. Check your connection and try again.')); };
            document.head.appendChild(script);
        });
    }

    function openCheckout(reference, checkout) {
        return loadCheckoutScript(checkout.script).then(function () {
            return new Promise(function (resolve, reject) {
                var instance = new window.Razorpay({
                    key: checkout.key,
                    order_id: checkout.order_id,
                    amount: checkout.amount,
                    currency: checkout.currency,
                    name: root.getAttribute('data-company') || 'Subscription',
                    description: 'Subscription payment',
                    handler: function (result) {
                        resolve({
                            reference: reference,
                            razorpay_order_id: result.razorpay_order_id,
                            razorpay_payment_id: result.razorpay_payment_id,
                            razorpay_signature: result.razorpay_signature
                        });
                    },
                    modal: {
                        ondismiss: function () { reject(new Error('The payment window was closed. Nothing was charged.')); }
                    }
                });

                instance.on('payment.failed', function (response) {
                    reject(new Error((response.error && response.error.description) || 'The payment failed. Please try again.'));
                });

                instance.open();
            });
        });
    }

    payButton.addEventListener('click', function () {
        if (!pending) {
            return;
        }

        busy(true);

        request('POST', root.getAttribute('data-checkout-url'), { plan_id: pending.planId, period: pending.period })
            .then(function (payload) {
                if (payload.data.paid) {
                    toast('success', payload.message);
                    window.location.href = payload.redirect || window.location.href;
                    return null;
                }

                // Unlock the page while the payment window is open; it is
                // modal, and a stuck "busy" state would outlive it on error.
                busy(false);

                return openCheckout(payload.data.reference, payload.data.checkout).then(function (result) {
                    busy(true);
                    toast('info', 'Confirming your payment...');
                    return request('POST', root.getAttribute('data-verify-url'), result);
                }).then(function (verified) {
                    toast('success', verified.message);
                    window.setTimeout(function () { window.location.reload(); }, 900);
                });
            })
            .catch(function (error) { toast('error', error.message); })
            .then(function () { busy(false); });
    });

    /* ----------------------------------------------------- cancel / resume */

    var cancel = root.querySelector('[data-billing-cancel]');
    var resume = root.querySelector('[data-billing-resume]');

    function simple(url, confirmText) {
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }

        busy(true);

        request('PUT', url)
            .then(function (payload) {
                toast('success', payload.message);
                window.setTimeout(function () { window.location.reload(); }, 900);
            })
            .catch(function (error) { toast('error', error.message); busy(false); });
    }

    if (cancel) {
        cancel.addEventListener('click', function () {
            simple(root.getAttribute('data-cancel-url'),
                'Stop renewing? You can keep working until the end of the term you have paid for.');
        });
    }

    if (resume) {
        resume.addEventListener('click', function () { simple(root.getAttribute('data-resume-url')); });
    }
})();
