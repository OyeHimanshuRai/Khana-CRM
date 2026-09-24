{{--
    Add / Edit company, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The logo preview and the remove button are delegated from
    crud-forms.js; the submit, the toasts and the inline field errors come
    from app.js.

    There is no "active" checkbox: suspending a business is its own audited
    action with its own reason, and an ordinary save must not be able to do
    it as a side effect.
--}}

@php
    $isNew = ! $tenant->exists;
    $logo = $isNew ? null : $tenant->logoUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.tenants.store') : route('admin.tenants.update', $tenant) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Identity</div>

        <div class="settings-grid">
            <div class="field">
                <label for="tenant-name">Company name</label>
                <input id="tenant-name" type="text" name="name" class="form-control" required
                       value="{{ $tenant->name }}" autocomplete="off" aria-invalid="false"
                       data-slug-source>
                <div class="form-hint">The name above the door, on every branch's paperwork.</div>
            </div>

            <div class="field">
                <label for="tenant-code">Company code</label>
                <input id="tenant-code" type="text" name="code" class="form-control" required
                       value="{{ $tenant->code }}" autocomplete="off" aria-invalid="false"
                       maxlength="20" placeholder="ACME" style="text-transform:uppercase">
                <div class="form-hint">Short and permanent — it identifies the business across the platform.</div>
            </div>

            <div class="field">
                <label for="tenant-legal">Registered name</label>
                <input id="tenant-legal" type="text" name="legal_name" class="form-control"
                       value="{{ $tenant->legal_name }}" autocomplete="off" aria-invalid="false">
                <div class="form-hint">Only if it differs from the trading name.</div>
            </div>

            <div class="field">
                <label for="tenant-slug">Slug</label>
                <input id="tenant-slug" type="text" name="slug" class="form-control"
                       value="{{ $tenant->slug }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $isNew ? 'Filled in from the name' : $tenant->slug }}"
                       data-slug-target>
                <div class="form-hint">
                    {{ $isNew
                        ? 'Leave blank to build it from the name.'
                        : 'Leave blank to keep the current slug.' }}
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Tax identity</div>

        <div class="settings-grid">
            <div class="field">
                <label for="tenant-gstin">GSTIN</label>
                <input id="tenant-gstin" type="text" name="gstin" class="form-control"
                       value="{{ $tenant->gstin }}" autocomplete="off" aria-invalid="false"
                       maxlength="15" placeholder="22AAAAA0000A1Z5" style="text-transform:uppercase">
                <div class="form-hint">A separately registered branch can override this on its own record.</div>
            </div>

            <div class="field">
                <label for="tenant-pan">PAN</label>
                <input id="tenant-pan" type="text" name="pan" class="form-control"
                       value="{{ $tenant->pan }}" autocomplete="off" aria-invalid="false"
                       maxlength="10" placeholder="ABCDE1234F" style="text-transform:uppercase">
            </div>

            <div class="field">
                <label for="tenant-state-code">GST state code</label>
                <input id="tenant-state-code" type="text" name="state_code" class="form-control"
                       value="{{ $tenant->state_code }}" autocomplete="off" aria-invalid="false"
                       maxlength="4" placeholder="27">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Contact &amp; address</div>

        <div class="settings-grid">
            <div class="field">
                <label for="tenant-phone">Phone</label>
                <input id="tenant-phone" type="text" name="phone" class="form-control"
                       value="{{ $tenant->phone }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="tenant-email">Email</label>
                <input id="tenant-email" type="email" name="email" class="form-control"
                       value="{{ $tenant->email }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="tenant-addr1">Address line 1</label>
                <input id="tenant-addr1" type="text" name="address_line1" class="form-control"
                       value="{{ $tenant->address_line1 }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="tenant-addr2">Address line 2</label>
                <input id="tenant-addr2" type="text" name="address_line2" class="form-control"
                       value="{{ $tenant->address_line2 }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="tenant-city">City</label>
                <input id="tenant-city" type="text" name="city" class="form-control"
                       value="{{ $tenant->city }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="tenant-state">State</label>
                <input id="tenant-state" type="text" name="state" class="form-control"
                       value="{{ $tenant->state }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="tenant-pincode">PIN code</label>
                <input id="tenant-pincode" type="text" name="pincode" class="form-control"
                       value="{{ $tenant->pincode }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="tenant-country">Country</label>
                <input id="tenant-country" type="text" name="country" class="form-control"
                       value="{{ $tenant->country ?? 'India' }}" autocomplete="off" aria-invalid="false">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Defaults &amp; presentation</div>

        <div class="settings-grid">
            <div class="field">
                <label for="tenant-currency">Currency</label>
                <input id="tenant-currency" type="text" name="currency" class="form-control"
                       value="{{ $tenant->currency ?? 'INR' }}" autocomplete="off" aria-invalid="false"
                       maxlength="8" style="text-transform:uppercase">
            </div>

            <div class="field">
                <label for="tenant-timezone">Timezone</label>
                <select id="tenant-timezone" name="timezone" class="form-control" aria-invalid="false">
                    @foreach (['Asia/Kolkata', 'Asia/Dubai', 'Asia/Kathmandu', 'Asia/Colombo', 'Asia/Dhaka', 'UTC'] as $tz)
                        <option value="{{ $tz }}" @selected(($tenant->timezone ?? 'Asia/Kolkata') === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="tenant-order">Display order</label>
                <input id="tenant-order" type="number" name="sort_order" class="form-control"
                       value="{{ $tenant->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
                <div class="form-hint">Lower numbers appear first in the company switcher.</div>
            </div>

            <div class="field field-full">
                <div class="form-label">Logo</div>

                <div class="setting-image" data-image-field>
                    <div class="setting-image-preview" data-file-preview>
                        @if ($logo)
                            <img src="{{ $logo }}" alt="{{ $tenant->name }}">
                        @else
                            <span class="text-xs text-muted">No logo</span>
                        @endif
                    </div>

                    <div class="setting-image-controls">
                        <label for="tenant-logo" class="sr-only">Choose a logo</label>
                        <input id="tenant-logo" type="file" name="logo"
                               accept="image/jpeg,image/png,image/webp" data-file-input>

                        <div class="form-hint">
                            JPG, PNG or WebP · up to 2&nbsp;MB · printed on every branch's invoices
                            unless the branch sets its own
                        </div>

                        @unless ($isNew)
                            <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                    data-remove-url="{{ route('admin.tenants.logo.destroy', $tenant) }}"
                                    @unless ($logo) hidden @endunless>
                                <x-icon name="trash" :size="13" /> Remove logo
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
            {{ $isNew ? 'Create company' : 'Save changes' }}
        </button>
    </div>
</form>
