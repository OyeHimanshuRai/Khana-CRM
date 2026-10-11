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

            function say(message) {
                if (note) { note.textContent = message; }
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
                say('Opening the payment window…');

                post(box.dataset.start).then(function (result) {
                    if (!result.success) {
                        button.disabled = false;
                        say(result.message || 'That did not work. Please ask a member of staff.');
                        return;
                    }

                    var checkout = result.data.checkout;

                    if (!checkout || typeof window.Razorpay !== 'function') {
                        button.disabled = false;
                        say('The payment window would not open. Please ask a member of staff.');
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
                                 | Reloaded either way. On success the table is
                                 | settled and the page should say so; on
                                 | failure the server has the truth and the
                                 | guest should see it rather than a stale
                                 | screen - and the webhook may well have
                                 | settled it anyway.
                                 */
                                say(confirmed.message || 'Thank you.');
                                window.location.reload();
                            });
                        },
                        modal: {
                            ondismiss: function () {
                                button.disabled = false;
                                say('Payment cancelled. Or ask a member of staff to bring the card machine.');
                            }
                        }
                    });

                    rzp.open();
                }).catch(function () {
                    button.disabled = false;
                    say('That did not work. Please ask a member of staff.');
                });
            });
        })();
    </script>
@endpush
