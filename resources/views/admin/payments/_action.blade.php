{{--
    Bounce or reverse, rendered straight into the modal body.

    One template for two actions, because the shape is identical - a reason,
    a warning about what changes, a confirm - and only the consequence
    differs. Spelling that consequence out is the whole point of the screen.
--}}

@php $isBounce = $action === 'bounce'; @endphp

<form method="POST"
      action="{{ $isBounce
        ? route('admin.payments.bounce', $payment)
        : route('admin.payments.reverse', $payment) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @method('PUT')

    <p class="text-sm">
        {{ $isBounce ? 'Marking' : 'Reversing' }}
        <strong>{{ $payment->number }}</strong>
        (₹{{ number_format((float) $payment->amount, 2) }} from {{ $payment->party_name }}) will:
    </p>

    <ul class="text-sm text-muted" style="margin:8px 0 14px;padding-left:20px;line-height:1.7">
        @if ($payment->isEffective())
            <li>put ₹{{ number_format((float) $payment->amount, 2) }} back onto the customer's balance</li>
            <li>reopen the invoices it settled, newest first</li>
        @else
            <li>leave balances untouched — this payment never cleared</li>
        @endif

        @if ($isBounce)
            <li>keep the payment on the record, marked bounced</li>
        @else
            <li>record a mirror entry pointing back at it; neither is deleted</li>
        @endif

        <li>show on the customer's statement with the reason below</li>
    </ul>

    <div class="field">
        <label for="action-reason">Reason</label>
        <textarea id="action-reason" name="reason" class="form-control" required
                  style="min-height:70px" maxlength="250" aria-invalid="false"
                  placeholder="{{ $isBounce
                    ? 'Insufficient funds, signature mismatch, account closed…'
                    : 'Entered against the wrong customer, duplicate entry…' }}"></textarea>
        <div class="form-hint">The customer will be told this, so write it for them.</div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Leave it alone</button>
        <button type="submit" class="btn btn-primary is-danger">
            {{ $isBounce ? 'Mark as bounced' : 'Reverse the payment' }}
        </button>
    </div>
</form>
