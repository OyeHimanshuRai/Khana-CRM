{{-- Add / Edit customer, rendered straight into the modal body. --}}

@php
    $isNew = ! $customer->exists;
    $photo = $isNew ? null : $customer->imageUrl();
    $canChooseShop = $isNew && $shops->count() > 1;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.customers.store') : route('admin.customers.update', $customer) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Who</div>

        <div class="settings-grid">
            @if ($canChooseShop)
                <div class="field field-full">
                    <label for="cust-shop">Shop</label>
                    <select id="cust-shop" name="shop_id" class="form-control" required aria-invalid="false">
                        @foreach ($shops as $shop)
                            <option value="{{ $shop->id }}"
                                @selected(App\Support\CurrentShop::id() === $shop->id)>
                                {{ $shop->name }} ({{ $shop->code }})
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">
                        Fixed once saved — a customer's invoices, ledger and credit limit belong to one shop.
                    </div>
                </div>
            @elseif ($isNew)
                <input type="hidden" name="shop_id" value="{{ App\Support\CurrentShop::idForWrite() ?? $shops->first()?->id }}">
            @endif

            <div class="field">
                <label for="cust-name">Name</label>
                <input id="cust-name" type="text" name="name" class="form-control" required
                       value="{{ $customer->name }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-mobile">Mobile</label>
                <input id="cust-mobile" type="text" name="mobile" class="form-control"
                       value="{{ $customer->mobile }}" autocomplete="off" aria-invalid="false"
                       inputmode="tel">
                <div class="form-hint">The counter looks people up by this, so it has to be unique in the shop.</div>
            </div>

            <div class="field">
                <label for="cust-code">Customer code</label>
                <input id="cust-code" type="text" name="code" class="form-control"
                       value="{{ $customer->code }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $suggestedCode ?? $customer->code }}">
                <div class="form-hint">
                    {{ $isNew ? 'Leave blank and the next code is assigned automatically.' : 'Change with care — it may be printed on old paperwork.' }}
                </div>
            </div>

            <div class="field">
                <label for="cust-type">Type</label>
                <select id="cust-type" name="type" class="form-control" required aria-invalid="false">
                    @foreach (App\Models\Customer::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(($customer->type ?? 'retail') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="cust-alt">Alternate mobile</label>
                <input id="cust-alt" type="text" name="alt_mobile" class="form-control"
                       value="{{ $customer->alt_mobile }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-email">Email</label>
                <input id="cust-email" type="email" name="email" class="form-control"
                       value="{{ $customer->email }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-gstin">GSTIN</label>
                <input id="cust-gstin" type="text" name="gstin" class="form-control"
                       value="{{ $customer->gstin }}" autocomplete="off" aria-invalid="false"
                       maxlength="15" style="text-transform:uppercase">
                <div class="form-hint">Needed for a B2B invoice.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Where</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="cust-addr1">Address line 1</label>
                <input id="cust-addr1" type="text" name="address_line1" class="form-control"
                       value="{{ $customer->address_line1 }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="cust-addr2">Address line 2</label>
                <input id="cust-addr2" type="text" name="address_line2" class="form-control"
                       value="{{ $customer->address_line2 }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-village">Village</label>
                <input id="cust-village" type="text" name="village" class="form-control"
                       value="{{ $customer->village }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-taluka">Taluka</label>
                <input id="cust-taluka" type="text" name="taluka" class="form-control"
                       value="{{ $customer->taluka }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-district">District</label>
                <input id="cust-district" type="text" name="district" class="form-control"
                       value="{{ $customer->district }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-city">City / Town</label>
                <input id="cust-city" type="text" name="city" class="form-control"
                       value="{{ $customer->city }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-state">State</label>
                <input id="cust-state" type="text" name="state" class="form-control"
                       value="{{ $customer->state }}" autocomplete="off" aria-invalid="false">
                <div class="form-hint">Decides whether GST is charged as CGST+SGST or IGST.</div>
            </div>

            <div class="field">
                <label for="cust-pincode">PIN code</label>
                <input id="cust-pincode" type="text" name="pincode" class="form-control"
                       value="{{ $customer->pincode }}" autocomplete="off" aria-invalid="false">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Farm</div>

        <div class="settings-grid">
            <div class="field">
                <label for="cust-land">Land area (acres)</label>
                <input id="cust-land" type="number" name="land_area" class="form-control"
                       value="{{ $customer->land_area }}" min="0" step="0.01" aria-invalid="false">
            </div>

            <div class="field">
                <label for="cust-crops">Primary crops</label>
                <input id="cust-crops" type="text" name="primary_crops" class="form-control"
                       value="{{ $customer->primary_crops }}" autocomplete="off" aria-invalid="false"
                       placeholder="Cotton, Soybean">
                <div class="form-hint">Comma separated. Useful for targeted advice and offers.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Credit</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label class="check">
                    <input type="hidden" name="allow_credit" value="0">
                    <input type="checkbox" name="allow_credit" value="1" @checked($customer->allow_credit)>
                    Allow credit (udhaar) sales
                </label>
                <div class="form-hint">
                    With this off the counter can only take payment in full, whatever the limit below says.
                </div>
            </div>

            <div class="field">
                <label for="cust-limit">Credit limit (₹)</label>
                <input id="cust-limit" type="number" name="credit_limit" class="form-control"
                       value="{{ $customer->exists ? (float) $customer->credit_limit : 0 }}"
                       min="0" step="0.01" aria-invalid="false">
                <div class="form-hint">The most this customer may owe at any one time.</div>
            </div>

            <div class="field">
                <label for="cust-days">Credit days</label>
                <input id="cust-days" type="number" name="credit_days" class="form-control"
                       value="{{ $customer->credit_days ?? 0 }}" min="0" max="3650" aria-invalid="false">
                <div class="form-hint">Sets the due date on a credit invoice, and when reminders start.</div>
            </div>

            @if ($isNew)
                <div class="field">
                    <label for="cust-opening">Opening balance (₹)</label>
                    <input id="cust-opening" type="number" name="opening_balance" class="form-control"
                           value="0" step="0.01" aria-invalid="false">
                    <div class="form-hint">
                        What they already owed before this system. Positive means they owe the shop.
                        This cannot be changed later — correct it with a payment or an adjustment instead.
                    </div>
                </div>
            @else
                <div class="field">
                    <div class="form-label">Balance</div>
                    <div class="form-control" style="background:var(--panel-alt);pointer-events:none">
                        ₹{{ number_format((float) $customer->balance, 2) }}
                    </div>
                    <div class="form-hint">
                        Maintained by the ledger — opened at ₹{{ number_format((float) $customer->opening_balance, 2) }}.
                        Change it by recording a payment, not by editing this form.
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Other</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="cust-notes">Notes</label>
                <textarea id="cust-notes" name="notes" class="form-control"
                          style="min-height:70px" aria-invalid="false">{{ $customer->notes }}</textarea>
            </div>

            <div class="field">
                <div class="form-label">Status</div>
                <label class="check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $customer->is_active)>
                    Active
                </label>
                <div class="form-hint">An inactive customer stops appearing in the counter's lookup.</div>
            </div>

            <div class="field field-full">
                <div class="form-label">Photo</div>

                <div class="setting-image" data-image-field>
                    <div class="setting-image-preview" data-file-preview>
                        @if ($photo)
                            <img src="{{ $photo }}" alt="{{ $customer->name }}">
                        @else
                            <span class="text-xs text-muted">No photo</span>
                        @endif
                    </div>

                    <div class="setting-image-controls">
                        <label for="cust-image" class="sr-only">Choose a photo</label>
                        <input id="cust-image" type="file" name="image"
                               accept="image/jpeg,image/png,image/webp" data-file-input>

                        <div class="form-hint">JPG, PNG or WebP · up to 2&nbsp;MB</div>

                        @unless ($isNew)
                            <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                    data-remove-url="{{ route('admin.customers.image.destroy', $customer) }}"
                                    @unless ($photo) hidden @endunless>
                                <x-icon name="trash" :size="13" /> Remove photo
                            </button>
                        @endunless
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create customer' : 'Save changes' }}
        </button>
    </div>
</form>
