{{-- Cancel a posted receipt, in the modal. --}}

<form method="POST" action="{{ route('admin.receipts.cancel', $receipt) }}"
      data-ajax data-close-modal data-redirect-delay="600">
    @csrf
    @method('PUT')

    <p class="text-sm">
        Cancelling <strong>{{ $receipt->reference }}</strong> will:
    </p>

    <ul class="text-sm text-muted" style="margin:8px 0 14px;padding-left:20px;line-height:1.7">
        <li>take {{ $receipt->items->count() }} line(s) of stock back off the shelf</li>
        <li>
            reverse ₹{{ number_format((float) $receipt->grand_total - (float) $receipt->paid_total, 2) }}
            on {{ $receipt->supplier?->displayName() }}'s account
        </li>
        @if ((float) $receipt->paid_total > 0)
            <li>void ₹{{ number_format((float) $receipt->paid_total, 2) }} of payments made against it</li>
        @endif
    </ul>

    <p class="pos-warning" style="margin-bottom:14px">
        This is refused if any of the stock has already been sold — taking back units that are no
        longer there would drive the shelf negative. Raise a purchase return instead.
    </p>

    <div class="field">
        <label for="grn-cancel-reason">Reason</label>
        <textarea id="grn-cancel-reason" name="reason" class="form-control" required
                  style="min-height:70px" maxlength="250" aria-invalid="false"
                  placeholder="Entered twice, wrong supplier, consignment refused at the door…"></textarea>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Keep the receipt</button>
        <button type="submit" class="btn btn-primary is-danger">Cancel {{ $receipt->reference }}</button>
    </div>
</form>
