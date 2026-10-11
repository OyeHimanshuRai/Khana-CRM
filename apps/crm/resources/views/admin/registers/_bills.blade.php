{{--
    The supplier bills ERP says are still owed, for the paid-out card.

    A fragment. The register's page asks for it once it has drawn and puts it
    inside the paid-out form, so the fields here are posted with that form.

    $bills is null when ERP could not be read. That is a state of this card
    and not an error: the form around it still pays out, without a bill.

    No <script> here - markup that arrives by innerHTML never runs one. What
    picking a bill does to the rest of the form is in assets/js/erp-panels.js.
--}}

@if ($bills === null)
    <div class="field">
        <x-khana::unreachable style="margin-bottom:0" />
        <div class="form-hint">
            You can still pay out below, without naming a bill. It will wait in ERP to be filed.
        </div>
    </div>
@elseif ($bills === [])
    <div class="field">
        <div class="form-label">Supplier bill</div>
        <p class="text-sm text-muted" style="margin:0">ERP has no unpaid supplier bills for this outlet.</p>
    </div>
@else
    <div class="field">
        <label for="po-bill">Supplier bill</label>
        <select id="po-bill" name="supplier_bill_id" class="form-control" aria-invalid="false" data-paid-out-bill>
            <option value="">Not for a bill</option>
            @foreach ($bills as $bill)
                {{-- The supplier and number travel back with the form, as
                     they read here: the day's list must be able to name the
                     bill with ERP switched off. --}}
                <option value="{{ $bill['id'] }}"
                        data-due="{{ $bill['due'] }}"
                        data-supplier="{{ $bill['supplier'] }}"
                        data-number="{{ $bill['number'] }}">
                    {{ $bill['supplier'] }}{{ $bill['number'] !== '' ? ' · '.$bill['number'] : '' }}
                    · {{ $bill['due_on'] ? 'due '.$bill['due_on']->format('d M Y') : 'no due date' }}
                    · ₹{{ number_format((float) $bill['due'], 2) }} still due
                </option>
            @endforeach
        </select>
        <input type="hidden" name="supplier_name" value="" data-paid-out-supplier>
        <input type="hidden" name="supplier_bill_no" value="" data-paid-out-number>
        <div class="form-hint">
            The unpaid bills in ERP, oldest due first. Picking one fills in what is still due —
            change the amount for a part payment.
        </div>
    </div>
@endif
