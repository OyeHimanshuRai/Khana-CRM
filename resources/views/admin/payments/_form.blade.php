{{--
    Record a collection, rendered straight into the modal body.

    Two ways in: from a customer, to settle their oldest debts, and from an
    invoice, to settle that one. The form defaults differently for each,
    because the two are different intentions.
--}}

<form method="POST" action="{{ route('admin.payments.store') }}"
      data-ajax data-close-modal data-refresh-list data-payment-form>
    @csrf

    @if (! $customer)
        {{-- No customer yet: the picker is the whole form until there is one. --}}
        <div class="field pos-customer" data-customer-picker
             data-lookup-url="{{ route('admin.customers.lookup') }}">
            <label for="pay-customer">Customer</label>
            <input id="pay-customer" type="search" class="form-control"
                   placeholder="Search by name or mobile…" autocomplete="off" data-customer-search>
            <div class="line-results" data-customer-results hidden></div>
            <input type="hidden" name="customer_id" data-customer-id required>
            <div class="form-hint">
                A payment always belongs to an account. For a counter sale, take the money on the
                invoice itself.
            </div>
        </div>

        <div class="pos-chosen" data-customer-chosen hidden style="margin-top:10px">
            <div>
                <strong data-customer-name></strong>
                <span class="text-xs text-muted" style="display:block" data-customer-meta></span>
            </div>
            <button type="button" class="btn btn-icon" data-customer-clear aria-label="Change customer">
                <x-icon name="x" :size="14" />
            </button>
        </div>
    @else
        <input type="hidden" name="customer_id" value="{{ $customer->id }}">

        <div class="pos-chosen">
            <div>
                <strong>{{ $customer->name }}</strong>
                <span class="text-xs text-muted" style="display:block">
                    {{ $customer->reference() }} ·
                    owes ₹{{ number_format((float) $customer->balance, 2) }}
                </span>
            </div>
        </div>
    @endif

    <div class="settings-grid" style="margin-top:14px">
        <div class="field">
            <label for="pay-amount">Amount (₹)</label>
            <input id="pay-amount" type="number" name="amount" class="form-control" required
                   min="0.01" step="0.01" aria-invalid="false"
                   value="{{ $invoice ? (float) $invoice->due_total : ($customer && (float) $customer->balance > 0 ? (float) $customer->balance : '') }}">
            @if ($customer)
                <div class="form-hint">
                    Anything above what is owed stays on the account as an advance.
                </div>
            @endif
        </div>

        <div class="field">
            <label for="pay-method">Method</label>
            <select id="pay-method" name="method" class="form-control" required aria-invalid="false"
                    data-payment-method>
                @foreach ($methods as $key => $meta)
                    <option value="{{ $key }}" data-instant="{{ $meta['instant'] ? '1' : '0' }}">
                        {{ $meta['label'] }}
                    </option>
                @endforeach
            </select>
            <div class="form-hint" data-payment-method-hint>
                Cash settles immediately.
            </div>
        </div>

        <div class="field">
            <label for="pay-at">Received on</label>
            <input id="pay-at" type="datetime-local" name="paid_at" class="form-control"
                   value="{{ now()->format('Y-m-d\TH:i') }}" max="{{ now()->format('Y-m-d\TH:i') }}"
                   aria-invalid="false">
        </div>

        <div class="field">
            <label for="pay-ref">Reference</label>
            <input id="pay-ref" type="text" name="transaction_ref" class="form-control"
                   maxlength="120" autocomplete="off" aria-invalid="false"
                   placeholder="UPI ref, cheque number, last four digits">
            <div class="form-hint">Whatever the shop will be asked to quote if this is disputed.</div>
        </div>

        {{-- Cheque-only fields, shown by the form's own script. --}}
        <div class="field" data-cheque-only hidden>
            <label for="pay-bank">Bank</label>
            <input id="pay-bank" type="text" name="bank_name" class="form-control"
                   maxlength="120" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field" data-cheque-only hidden>
            <label for="pay-cheque-date">Cheque date</label>
            <input id="pay-cheque-date" type="date" name="cheque_date" class="form-control"
                   aria-invalid="false">
        </div>

        <div class="field field-full">
            <label for="pay-notes">Notes</label>
            <textarea id="pay-notes" name="notes" class="form-control"
                      style="min-height:56px" maxlength="1000" aria-invalid="false"></textarea>
        </div>
    </div>

    @if ($outstanding->isNotEmpty())
        <div class="form-section" style="margin-top:6px">
            <div class="form-section-title">Apply to</div>

            <p class="text-xs text-muted" style="margin:-4px 0 10px">
                Leave these blank and the payment settles the oldest invoices first, which is what
                keeps the ageing report meaningful. Fill one in to settle a specific bill.
            </p>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th style="text-align:right">Total</th>
                            <th style="text-align:right">Outstanding</th>
                            <th style="width:140px;text-align:right">Apply</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($outstanding as $bill)
                            <tr>
                                <td>
                                    <span class="list-ref">{{ $bill->number }}</span>
                                    @if ($bill->isOverdue())
                                        <span class="badge badge-danger" style="margin-left:5px">
                                            {{ $bill->daysOverdue() }}d over
                                        </span>
                                    @endif
                                </td>
                                <td class="text-sm">{{ $bill->invoiced_at?->format('d M Y') }}</td>
                                <td style="text-align:right" class="text-sm">
                                    ₹{{ number_format((float) $bill->grand_total, 2) }}
                                </td>
                                <td style="text-align:right" class="text-sm">
                                    <strong>₹{{ number_format((float) $bill->due_total, 2) }}</strong>
                                </td>
                                <td>
                                    <label class="sr-only" for="alloc-{{ $bill->id }}">
                                        Amount to apply to {{ $bill->number }}
                                    </label>
                                    <input id="alloc-{{ $bill->id }}" type="number"
                                           name="allocations[{{ $bill->id }}]" class="form-control"
                                           min="0" max="{{ (float) $bill->due_total }}" step="0.01"
                                           value="{{ $invoice && $invoice->id === $bill->id ? (float) $bill->due_total : '' }}"
                                           placeholder="0.00">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Record payment</button>
    </div>
</form>
