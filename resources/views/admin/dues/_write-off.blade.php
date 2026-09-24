{{--
    Write off an outstanding balance, in the modal.

    The bluntest operation in the system: money the shop is owed stops being
    owed, and nobody paid it. So the screen says so plainly and asks for a
    reason worth reading back years later.
--}}

<form method="POST" action="{{ route('admin.dues.write-off', $customer) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf

    <div class="pos-chosen">
        <div>
            <strong>{{ $customer->name }}</strong>
            <span class="text-xs text-muted" style="display:block">
                {{ $customer->reference() }} · owes ₹{{ number_format((float) $customer->balance, 2) }}
            </span>
        </div>
    </div>

    <p class="pos-warning" style="margin-top:12px">
        A write-off cannot be undone from this screen. It posts a credit to the account with its own
        entry type, so it shows separately from a real payment on every report — which is the point.
        If the customer later pays, record that as an ordinary payment.
    </p>

    <div class="settings-grid" style="margin-top:12px">
        <div class="field">
            <label for="wo-amount">Amount to write off (₹)</label>
            <input id="wo-amount" type="number" name="amount" class="form-control" required
                   min="0.01" max="{{ (float) $customer->balance }}" step="0.01"
                   value="{{ (float) $customer->balance }}" aria-invalid="false">
            <div class="form-hint">
                At most ₹{{ number_format((float) $customer->balance, 2) }} — the balance outstanding.
            </div>
        </div>

        <div class="field field-full">
            <label for="wo-reason">Reason</label>
            <textarea id="wo-reason" name="reason" class="form-control" required
                      style="min-height:74px" maxlength="250" aria-invalid="false"
                      placeholder="Bad debt after two years of follow-up, settled at a discount, customer deceased…"></textarea>
            <div class="form-hint">
                Written for whoever audits this later, not for you today.
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary is-danger">Write it off</button>
    </div>
</form>
