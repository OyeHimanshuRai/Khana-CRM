@extends('admin.layouts.app')

@section('title', 'Billing')

@section('content')
    {{--
        Paying for your own account (§11, §21).

        The customer's side of billing. Settings > Subscription reads what the
        account is on; this is where the money is handed over, and it is
        reachable when the outlet is locked out - see the route group, which
        leaves `shop.subscribed` off on purpose.

        Nothing on this page writes a date. The provider's callback does, and
        the webhook does it again if the browser never comes back. See
        App\Services\PaymentIntentService.
    --}}
    <x-page-header
        title="Billing"
        :subtitle="$shop?->name ?? $tenant?->name ?? 'Your account'"
        :crumbs="['Billing' => null]"
    />

    @if ($subscription === null)
        <div class="card" style="padding:18px">
            <div class="sec-name">There is nothing to pay</div>
            <p class="text-sm text-muted" style="margin-top:8px">
                This outlet is not on a subscription plan, so nothing is limited and nothing is due.
            </p>
        </div>
    @else
        @php
            $left = $subscription->daysLeft();
            $monthly = $plan?->priceFor(\App\Models\Plan::MONTHLY) ?? (float) $subscription->price;
            $yearly = $plan?->priceFor(\App\Models\Plan::YEARLY) ?? ((float) $subscription->price * 12);
            $yearly_is_default = $subscription->billing_period === \App\Models\Plan::YEARLY;
        @endphp

        {{-- One line at the top saying where this account stands, because it
             is the only thing most people open this page to find out. --}}
        @if (! $subscription->isUsable())
            <div class="alert alert-danger" style="margin-bottom:14px">
                <strong>
                    {{ $subscription->state() === \App\Models\Subscription::CANCELLED
                        ? 'Your subscription is cancelled.'
                        : 'Your term has ended.' }}
                </strong>
                Billing, the kitchen screen and the table QR stay closed until this is paid.
                Every record you have is kept exactly as it was.
            </div>
        @elseif ($subscription->needsAttention())
            <div class="alert alert-warning" style="margin-bottom:14px">
                <strong>Your payment is due.</strong>
                Your term ended on {{ $subscription->ends_at?->format('j M Y') }}. You can carry on
                working until {{ $subscription->graceEndsAt()?->format('j M Y') }}.
            </div>
        @elseif ($subscription->onTrial())
            <div class="alert alert-info" style="margin-bottom:14px">
                <strong>You are on a free trial</strong>
                until {{ $subscription->trial_ends_at->format('j M Y') }}. Nothing has been charged.
                Paying now ends the trial and starts a full term.
            </div>
        @elseif ($left !== null && $left >= 0 && $left <= 7)
            <div class="alert alert-info" style="margin-bottom:14px">
                <strong>Your plan renews in {{ $left }} day{{ $left === 1 ? '' : 's' }}</strong>,
                on {{ $subscription->ends_at->format('j M Y') }}.
            </div>
        @endif

        <div class="stat-grid">
            <div class="stat">
                <div class="stat-icon"><x-icon name="tag" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Your plan</div>
                    <div class="stat-value" style="font-size:1.1rem">{{ $plan?->name ?? '—' }}</div>
                    <span class="text-xs text-muted">
                        {{ $subscription->currency }} {{ number_format((float) $subscription->price, 2) }}
                        per {{ $subscription->billing_period === 'yearly' ? 'year' : 'month' }}
                    </span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon is-info"><x-icon name="calendar" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Paid until</div>
                    <div class="stat-value" style="font-size:1.1rem">
                        {{ $subscription->ends_at?->format('j M Y') ?? 'Never' }}
                    </div>
                    @if ($left !== null)
                        <span class="text-xs text-muted">
                            {{ $left < 0 ? abs($left).' days ago' : 'in '.$left.' days' }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon {{ $subscription->isUsable() ? 'is-success' : '' }}"
                     @unless ($subscription->isUsable()) style="background: var(--danger-soft); color: var(--danger)" @endunless>
                    <x-icon name="shield" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Status</div>
                    <div class="stat-value" style="font-size:1.1rem">{{ $subscription->stateLabel() }}</div>
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------- pay -- --}}
        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">Pay for your account</div>

            @if ($online)
                <p class="text-sm text-muted" style="margin:8px 0 14px">
                    Pay by UPI, card or netbanking. The term starts from the day you pay, or from
                    the end of the one you already have — paying early never costs you the days you
                    have left.
                </p>

                <div class="bill-pay" data-pay
                     data-start="{{ route('admin.billing.checkout') }}">

                    <div class="bill-pay-terms">
                        <label class="bill-pay-term">
                            <input type="radio" name="bill-period" value="monthly"
                                   @unless ($yearly_is_default) checked @endunless>
                            <span>
                                <strong>₹{{ number_format($monthly, 0) }}</strong>
                                <span class="text-xs text-muted">for a month</span>
                            </span>
                        </label>

                        <label class="bill-pay-term">
                            <input type="radio" name="bill-period" value="yearly"
                                   @if ($yearly_is_default) checked @endif>
                            <span>
                                <strong>₹{{ number_format($yearly, 0) }}</strong>
                                <span class="text-xs text-muted">
                                    for a year
                                    @if ($monthly > 0 && $yearly < $monthly * 12)
                                        — saves ₹{{ number_format($monthly * 12 - $yearly, 0) }}
                                    @endif
                                </span>
                            </span>
                        </label>
                    </div>

                    <button type="button" class="btn btn-primary" data-pay-button>
                        <x-icon name="wallet" :size="16" /> Pay now
                    </button>

                    <p class="text-xs text-muted" style="margin:10px 0 0" data-pay-note>
                        You will be taken to the payment window. GST is charged on the invoice.
                    </p>
                </div>
            @else
                {{--
                    No keys, no button. False is a working state here, not an
                    error - the same rule the guest's table page follows - so
                    the page says how to pay instead of drawing a control that
                    cannot open anything.
                --}}
                <p class="text-sm" style="margin:8px 0 6px">
                    Online payment is not switched on for this platform yet, so this one has to go
                    through us. Send us a note and we will take the payment and extend your term
                    the same day.
                </p>

                <ul class="text-sm" style="margin:0; padding-left:18px">
                    <li>Amount: ₹{{ number_format($yearly_is_default ? $yearly : $monthly, 2) }}
                        ({{ $yearly_is_default ? 'a year' : 'a month' }}), plus GST</li>
                    @if ($support['email'])
                        <li>Email: <a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a></li>
                    @endif
                    @if ($support['phone'])
                        <li>Phone: {{ $support['phone'] }}</li>
                    @endif
                    <li>Quote your account: <strong>{{ $tenant?->code ?? '—' }}</strong>
                        @if ($shop) / outlet <strong>{{ $shop->code }}</strong> @endif
                    </li>
                </ul>
            @endif
        </div>

        {{-- -------------------------------------------------- receipts -- --}}
        @if ($payments->isNotEmpty())
            <div class="card">
                <div style="padding:14px 18px 0">
                    <div class="form-section-title">Your payments</div>
                </div>

                <div class="table-wrap">
                    <table class="table table-list">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Received</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Term</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payments as $payment)
                                <tr>
                                    <td><span class="list-ref">{{ $payment->reference }}</span></td>
                                    <td class="text-sm">{{ $payment->paid_at->format('j M Y') }}</td>
                                    <td class="text-sm {{ $payment->isRefund() ? 'text-danger' : '' }}">
                                        {{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}
                                    </td>
                                    <td class="text-sm">{{ $payment->methodLabel() }}</td>
                                    <td class="text-sm">{{ $payment->isRefund() ? '—' : $payment->periodLine() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
@endsection

@push('styles')
    <style>
        .bill-pay-terms { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }

        .bill-pay-term {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 12px 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            cursor: pointer;
        }
        .bill-pay-term:has(input:checked) { border-color: var(--primary); background: var(--primary-soft); }
        .bill-pay-term span { display: grid; }
        .bill-pay-term strong { font-size: 1.05rem; }
    </style>
@endpush

@if ($subscription !== null && $online)
    @push('scripts')
        <script src="{{ $checkoutScript }}"></script>
        <script>
            /*
             | The owner's checkout.
             |
             | Deliberately inline and small, like the guest's one: it runs on
             | one page, for one button, and only when the platform has
             | payment keys at all.
             |
             | Three steps - open an order, let the provider take the money,
             | tell us it happened. The third races the provider's webhook on
             | every payment; both are idempotent server-side, so nothing here
             | has to care which one wins.
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

                function period() {
                    var picked = box.querySelector('input[name="bill-period"]:checked');

                    return picked ? picked.value : 'monthly';
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

                    post(box.dataset.start, { period: period() }).then(function (result) {
                        if (!result.success) {
                            button.disabled = false;
                            say(result.message || 'That did not work. Please try again.');
                            return;
                        }

                        var checkout = result.data.checkout;

                        if (!checkout || typeof window.Razorpay !== 'function') {
                            button.disabled = false;
                            say('The payment window would not open. Please try again, or contact us.');
                            return;
                        }

                        var rzp = new window.Razorpay({
                            key: checkout.key,
                            order_id: checkout.order_id,
                            amount: checkout.amount,
                            currency: checkout.currency,
                            name: {!! json_encode(config('app.name')) !!},
                            description: {!! json_encode(($plan?->name ?? 'Subscription').' — '.($shop?->name ?? '')) !!},
                            handler: function (response) {
                                say('Confirming…');

                                post(result.data.confirm_url, {
                                    reference: result.data.reference,
                                    razorpay_order_id: response.razorpay_order_id,
                                    razorpay_payment_id: response.razorpay_payment_id,
                                    razorpay_signature: response.razorpay_signature
                                }).then(function (confirmed) {
                                    /*
                                     | Reloaded either way. On success the term
                                     | has moved and the page should say so; on
                                     | failure the server has the truth and the
                                     | owner should see it rather than a stale
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
                                    say('Payment cancelled. Nothing has been charged.');
                                }
                            }
                        });

                        rzp.open();
                    }).catch(function () {
                        button.disabled = false;
                        say('That did not work. Please try again.');
                    });
                });
            })();
        </script>
    @endpush
@endif
