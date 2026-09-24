{{-- Cancel confirmation, rendered straight into the modal body. --}}

<form method="POST" action="{{ route('admin.invoices.cancel', $invoice) }}"
      data-ajax data-close-modal data-redirect-delay="600">
    @csrf
    @method('PUT')

    <p class="text-sm">
        Cancelling <strong>{{ $invoice->number }}</strong> will:
    </p>

    <ul class="text-sm text-muted" style="margin:8px 0 14px;padding-left:20px;line-height:1.7">
        <li>put {{ $invoice->items->count() }} line(s) of stock back on the shelf</li>
        @if ($invoice->customer)
            <li>
                reverse ₹{{ number_format((float) $invoice->grand_total - (float) $invoice->paid_total, 2) }}
                on {{ $invoice->customer_name }}'s account
            </li>
        @endif
        @if ((float) $invoice->paid_total > 0)
            <li>void ₹{{ number_format((float) $invoice->paid_total, 2) }} of payments taken against it</li>
        @endif
        <li>keep the number {{ $invoice->number }} in the series, marked cancelled</li>
    </ul>

    <p class="text-xs text-muted" style="margin-bottom:14px">
        The invoice is never deleted. It stays readable, and the reversal shows on both the stock
        ledger and the customer's statement.
    </p>

    <div class="field">
        <label for="cancel-reason">Reason</label>
        <textarea id="cancel-reason" name="reason" class="form-control" required
                  style="min-height:70px" maxlength="250" aria-invalid="false"
                  placeholder="Rung up twice, wrong customer, customer changed their mind…"></textarea>
        <div class="form-hint">
            This goes on the invoice, in the activity log and on the customer's statement.
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Keep the invoice</button>
        <button type="submit" class="btn btn-primary is-danger">Cancel {{ $invoice->number }}</button>
    </div>
</form>
