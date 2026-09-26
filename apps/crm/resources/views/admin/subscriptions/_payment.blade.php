{{--
    Record money received from a business, and move its term forward.

    Rendered into the modal body; no <script> may live here.

    The amount is signed. A negative one is a refund or a correction, and it
    does NOT extend the term - which the hint says out loud, because a refund
    that quietly bought another month would be a strange apology.
--}}

@php
    $plan = $subscription?->plan;
    $suggested = $subscription ? (float) $subscription->price : 0;
@endphp

<form method="POST" action="{{ route('admin.subscriptions.payment.store', $tenant) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf

    @if ($subscription === null)
        <div class="alert alert-warning">
            <strong>{{ $tenant->name }} is not on a plan.</strong>
            Put them on one first — there is nothing for a payment to buy.
        </div>
    @else
        <div class="alert alert-info" style="margin-bottom:14px">
            <strong>{{ $plan?->name ?? 'Current plan' }}</strong> ·
            {{ $subscription->currency }} {{ number_format($suggested, 2) }}
            per {{ $subscription->billing_period === 'yearly' ? 'year' : 'month' }}.
            @if ($subscription->ends_at)
                Runs until {{ $subscription->ends_at->format('j M Y') }}; a payment extends from
                {{ $subscription->ends_at->isFuture() ? 'there' : 'today' }}.
            @else
                No end date is set; a payment will set one.
            @endif
        </div>

        <div class="settings-grid">
            <div class="field">
                <label for="pay-amount">Amount</label>
                <input id="pay-amount" type="number" name="amount" class="form-control" required
                       value="{{ $suggested }}" step="0.01" aria-invalid="false">
                <div class="form-hint">
                    A negative amount records a refund or a correction. It is kept in the
                    ledger and does not change the term.
                </div>
            </div>

            <div class="field">
                <label for="pay-method">How it was paid</label>
                <select id="pay-method" name="method" class="form-control" required aria-invalid="false">
                    @foreach ($methods as $method)
                        <option value="{{ $method }}" @selected($method === 'manual')>
                            {{ ucfirst($method) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="pay-period">Buys</label>
                <select id="pay-period" name="billing_period" class="form-control" aria-invalid="false">
                    <option value="monthly" @selected($subscription->billing_period === 'monthly')>One month</option>
                    <option value="yearly" @selected($subscription->billing_period === 'yearly')>One year</option>
                </select>
            </div>

            <div class="field">
                <label for="pay-date">Received on</label>
                <input id="pay-date" type="date" name="paid_at" class="form-control" aria-invalid="false"
                       value="{{ now()->format('Y-m-d') }}">
                <div class="form-hint">
                    A payment that arrives late still buys the term it was for.
                </div>
            </div>

            <div class="field">
                <label for="pay-ref">Their reference</label>
                <input id="pay-ref" type="text" name="provider_reference" class="form-control"
                       maxlength="120" aria-invalid="false" placeholder="UTR, cheque no. or payment id">
                <div class="form-hint">For reconciling against the bank statement.</div>
            </div>

            <div class="field">
                <label for="pay-note">Note</label>
                <input id="pay-note" type="text" name="note" class="form-control"
                       maxlength="255" aria-invalid="false">
            </div>
        </div>
    @endif

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        @if ($subscription !== null)
            <button type="submit" class="btn btn-primary">Record payment</button>
        @endif
    </div>
</form>
