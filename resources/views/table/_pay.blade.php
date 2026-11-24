{{--
    Paying from the table (§11).

    The one part of a guest's journey that needs JavaScript: every provider's
    checkout is a script, and there is no way around that. So it is confined to
    this partial and it degrades honestly - without JS the button does nothing
    and the sentence beneath it already told them to ask a member of staff,
    which is what they would have done anyway.

    Included only when the gateway is configured. An outlet without keys never
    renders any of this, and the page reads exactly as it did before online
    payment existed.
--}}

<section class="t-card t-pay" data-pay
         data-start="{{ route('table.pay.start') }}"
         data-failed="{{ route('table.pay.failed') }}"
         data-receipt="{{ route('table.receipt') }}"
         data-amount="{{ number_format($total, 2, '.', '') }}">

    <button type="button" class="t-add-btn t-pay-btn" data-pay-button>
        Pay ₹{{ number_format($total, 2) }} now
    </button>

    <p class="t-total-note" data-pay-note>
        Or ask a member of staff to bring the card machine.
    </p>
</section>

@push('scripts')
    <script src="{{ $checkoutScript }}"></script>
    <script>
        /*
         | The guest's checkout.
         |
         | Deliberately inline and tiny rather than another file in the asset
         | list: it runs on one page, for one button, and only when the outlet
         | has payment keys at all.
         |
         | The flow is two requests and one provider dialog:
         |
         |   1. POST start   - we open an order with the provider
         |   2. the dialog   - the guest pays; the provider signs the result
         |   3. POST confirm - we check the signature and settle the table
         |
         | Step 3 races the provider's webhook on every payment. Both are
         | idempotent server-side; nothing here needs to know that.
         */
        (function () {
            'use strict';

            var box = document.querySelector('[data-pay]');
            if (!box) { return; }

            var button = box.querySelector('[data-pay-button]');
            var note = box.querySelector('[data-pay-note]');
            var token = document.querySelector('meta[name="csrf-token"]');

            var idle = button.textContent;

            function say(message) {
                if (note) { note.textContent = message; note.classList.remove('t-pay-retry'); }
            }

            /*
             | A payment that did not go through. The button becomes
             | "Retry Payment" and the reason stays on screen: the guest
             | should never have to wonder whether they were charged or what
             | to press next. The failed attempt is reported so the next try
             | opens a fresh provider order.
             */
            function failed(message, reference) {
                button.disabled = false;
                button.textContent = 'Retry Payment';
                if (note) {
                    note.textContent = (message || 'The payment did not go through.')
                        + ' If money was deducted it is returned by your bank automatically.';
                    note.classList.add('t-pay-retry');
                }
                if (reference) {
                    post(box.dataset.failed, { reference: reference, reason: message || '' }).catch(function () {});
                }
            }

            function post(url, body) {
                return fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': token ? token.content : ''
                    },
                    body: JSON.stringify(body || {})
                }).then(function (response) { return response.json(); });
            }

            button.addEventListener('click', function () {
                button.disabled = true;
                button.textContent = idle;
                say('Opening the payment window…');

                post(box.dataset.start).then(function (result) {
                    if (!result.success) {
                        failed(result.message || 'That did not work. Please try again or ask a member of staff.');
                        return;
                    }

                    var checkout = result.data.checkout;

                    if (!checkout || typeof window.Razorpay !== 'function') {
                        failed('The payment window would not open.');
                        return;
                    }

                    var rzp = new window.Razorpay({
                        key: checkout.key,
                        order_id: checkout.order_id,
                        amount: checkout.amount,
                        currency: checkout.currency,
                        name: {!! json_encode($shop?->name ?: $company) !!},
                        description: {!! json_encode('Table '.($table?->name ?? '')) !!},
                        handler: function (response) {
                            say('Confirming…');

                            post(result.data.confirm_url, {
                                reference: result.data.reference,
                                razorpay_order_id: response.razorpay_order_id,
                                razorpay_payment_id: response.razorpay_payment_id,
                                razorpay_signature: response.razorpay_signature
                            }).then(function (confirmed) {
                                /*
                                 | Paid: straight to the receipt, nothing for
                                 | the guest to press. Not paid: the server
                                 | has already marked the attempt failed, so
                                 | show Retry Payment.
                                 */
                                if (confirmed.success) {
                                    say(confirmed.message || 'Paid. Opening your receipt…');
                                    window.location.assign(confirmed.redirect || box.dataset.receipt);
                                    return;
                                }
                                failed(confirmed.message);
                            }).catch(function () {
                                // The network dropped after paying: the
                                // webhook settles it, and the page moves to
                                // the receipt by itself when it has.
                                say('Confirming your payment…');
                                window.setTimeout(function () { window.location.assign(box.dataset.receipt); }, 4000);
                            });
                        },
                        modal: {
                            ondismiss: function () {
                                button.disabled = false;
                                button.textContent = 'Retry Payment';
                                say('Payment cancelled. Tap Retry Payment, or ask a member of staff to bring the card machine.');
                            }
                        }
                    });

                    rzp.on('payment.failed', function (response) {
                        var reason = response && response.error ? response.error.description : '';
                        failed(reason, result.data.reference);
                    });

                    rzp.open();
                }).catch(function () {
                    failed('That did not work. Please check your connection.');
                });
            });
        })();
    </script>
@endpush
