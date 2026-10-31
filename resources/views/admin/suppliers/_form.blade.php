{{-- Add / Edit supplier, rendered straight into the modal body. --}}

@php
    $isNew = ! $supplier->exists;
    $canChooseShop = $isNew && $shops->count() > 1;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.suppliers.store') : route('admin.suppliers.update', $supplier) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Who</div>

        <div class="settings-grid">
            @if ($canChooseShop)
                <div class="field field-full">
                    <label for="sup-shop">Shop</label>
                    <select id="sup-shop" name="shop_id" class="form-control" required aria-invalid="false">
                        @foreach ($shops as $shop)
                            <option value="{{ $shop->id }}"
                                @selected(App\Support\CurrentShop::id() === $shop->id)>
                                {{ $shop->name }} ({{ $shop->code }})
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">
                        Fixed once saved — each branch settles its own purchase ledger.
                    </div>
                </div>
            @elseif ($isNew)
                <input type="hidden" name="shop_id" value="{{ App\Support\CurrentShop::idForWrite() ?? $shops->first()?->id }}">
            @endif

            <div class="field">
                <label for="sup-name">Name</label>
                <input id="sup-name" type="text" name="name" class="form-control" required
                       value="{{ $supplier->name }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="sup-company">Company / trading name</label>
                <input id="sup-company" type="text" name="company" class="form-control"
                       value="{{ $supplier->company }}" autocomplete="off" aria-invalid="false">
                <div class="form-hint">Shown in lists when it is filled in.</div>
            </div>

            <div class="field">
                <label for="sup-code">Supplier code</label>
                <input id="sup-code" type="text" name="code" class="form-control"
                       value="{{ $supplier->code }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $suggestedCode ?? $supplier->code }}">
                <div class="form-hint">
                    {{ $isNew ? 'Leave blank and the next code is assigned automatically.' : 'Change with care.' }}
                </div>
            </div>

            <div class="field">
                <label for="sup-contact">Contact person</label>
                <input id="sup-contact" type="text" name="contact_person" class="form-control"
                       value="{{ $supplier->contact_person }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="sup-mobile">Mobile</label>
                <input id="sup-mobile" type="text" name="mobile" class="form-control"
                       value="{{ $supplier->mobile }}" autocomplete="off" aria-invalid="false" inputmode="tel">
            </div>

            <div class="field">
                <label for="sup-alt">Alternate mobile</label>
                <input id="sup-alt" type="text" name="alt_mobile" class="form-control"
                       value="{{ $supplier->alt_mobile }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="sup-email">Email</label>
                <input id="sup-email" type="email" name="email" class="form-control"
                       value="{{ $supplier->email }}" autocomplete="off" aria-invalid="false">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Tax &amp; address</div>

        <div class="settings-grid">
            <div class="field">
                <label for="sup-gstin">GSTIN</label>
                <input id="sup-gstin" type="text" name="gstin" class="form-control"
                       value="{{ $supplier->gstin }}" autocomplete="off" aria-invalid="false"
                       maxlength="15" style="text-transform:uppercase">
            </div>

            <div class="field">
                <label for="sup-pan">PAN</label>
                <input id="sup-pan" type="text" name="pan" class="form-control"
                       value="{{ $supplier->pan }}" autocomplete="off" aria-invalid="false"
                       maxlength="10" style="text-transform:uppercase">
            </div>

            <div class="field field-full">
                <label for="sup-addr1">Address line 1</label>
                <input id="sup-addr1" type="text" name="address_line1" class="form-control"
                       value="{{ $supplier->address_line1 }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="sup-addr2">Address line 2</label>
                <input id="sup-addr2" type="text" name="address_line2" class="form-control"
                       value="{{ $supplier->address_line2 }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="sup-city">City</label>
                <input id="sup-city" type="text" name="city" class="form-control"
                       value="{{ $supplier->city }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="sup-state">State</label>
                <input id="sup-state" type="text" name="state" class="form-control"
                       value="{{ $supplier->state }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="sup-state-code">GST state code</label>
                <input id="sup-state-code" type="text" name="state_code" class="form-control"
                       value="{{ $supplier->state_code }}" autocomplete="off" aria-invalid="false" maxlength="4">
            </div>

            <div class="field">
                <label for="sup-pincode">PIN code</label>
                <input id="sup-pincode" type="text" name="pincode" class="form-control"
                       value="{{ $supplier->pincode }}" autocomplete="off" aria-invalid="false">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Settlement</div>

        <div class="settings-grid">
            <div class="field">
                <label for="sup-days">Credit days</label>
                <input id="sup-days" type="number" name="credit_days" class="form-control"
                       value="{{ $supplier->credit_days ?? 0 }}" min="0" max="3650" aria-invalid="false">
                <div class="form-hint">Sets the due date on a purchase invoice from this supplier.</div>
            </div>

            <div class="field">
                <label for="sup-limit">Credit limit (₹)</label>
                <input id="sup-limit" type="number" name="credit_limit" class="form-control"
                       value="{{ $supplier->exists ? (float) $supplier->credit_limit : 0 }}"
                       min="0" step="0.01" aria-invalid="false">
                <div class="form-hint">How much they will let the shop carry.</div>
            </div>

            @if ($isNew)
                <div class="field">
                    <label for="sup-opening">Opening balance (₹)</label>
                    <input id="sup-opening" type="number" name="opening_balance" class="form-control"
                           value="0" step="0.01" aria-invalid="false">
                    <div class="form-hint">
                        What the shop already owed before this system. Cannot be changed later —
                        correct it with a payment instead.
                    </div>
                </div>
            @else
                <div class="field">
                    <div class="form-label">Balance</div>
                    <div class="form-control" style="background:var(--panel-alt);pointer-events:none">
                        ₹{{ number_format((float) $supplier->balance, 2) }}
                    </div>
                    <div class="form-hint">
                        Maintained by the purchase ledger — opened at
                        ₹{{ number_format((float) $supplier->opening_balance, 2) }}.
                    </div>
                </div>
            @endif

            <div class="field">
                <label for="sup-bank">Bank name</label>
                <input id="sup-bank" type="text" name="bank_name" class="form-control"
                       value="{{ $supplier->bank_name }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="sup-account">Account number</label>
                <input id="sup-account" type="text" name="bank_account" class="form-control"
                       value="{{ $supplier->bank_account }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="sup-ifsc">IFSC</label>
                <input id="sup-ifsc" type="text" name="bank_ifsc" class="form-control"
                       value="{{ $supplier->bank_ifsc }}" autocomplete="off" aria-invalid="false"
                       maxlength="11" style="text-transform:uppercase">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Other</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="sup-notes">Notes</label>
                <textarea id="sup-notes" name="notes" class="form-control"
                          style="min-height:70px" aria-invalid="false">{{ $supplier->notes }}</textarea>
            </div>

            <div class="field">
                <div class="form-label">Status</div>
                <label class="check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $supplier->is_active)>
                    Active
                </label>
                <div class="form-hint">An inactive supplier cannot be chosen on a new purchase order.</div>
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create supplier' : 'Save changes' }}
        </button>
    </div>
</form>
