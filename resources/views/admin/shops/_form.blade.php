{{--
    Add / Edit shop, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The logo preview and the remove button are delegated from
    crud-forms.js; the submit, the toasts and the inline field errors come
    from app.js.
--}}

@php
    $isNew = ! $shop->exists;
    $logo = $isNew ? null : $shop->logoUrl();
    $assigned = collect($assigned);
    $enabled = collect($enabled);
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.shops.store') : route('admin.shops.update', $shop) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Identity</div>

        <div class="settings-grid">
            <div class="field">
                <label for="shop-name">Shop name</label>
                <input id="shop-name" type="text" name="name" class="form-control" required
                       value="{{ $shop->name }}" autocomplete="off" aria-invalid="false"
                       data-slug-source>
            </div>

            <div class="field">
                <label for="shop-code">Shop code</label>
                <input id="shop-code" type="text" name="code" class="form-control" required
                       value="{{ $shop->code }}" autocomplete="off" aria-invalid="false"
                       maxlength="20" placeholder="MAIN" style="text-transform:uppercase">
                <div class="form-hint">
                    Short and permanent — it becomes part of every invoice number this shop raises.
                </div>
            </div>

            <div class="field">
                <label for="shop-legal">Registered name</label>
                <input id="shop-legal" type="text" name="legal_name" class="form-control"
                       value="{{ $shop->legal_name }}" autocomplete="off" aria-invalid="false">
                <div class="form-hint">Only if it differs from the trading name.</div>
            </div>

            <div class="field">
                <label for="shop-slug">Slug</label>
                <input id="shop-slug" type="text" name="slug" class="form-control"
                       value="{{ $shop->slug }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $isNew ? 'Filled in from the name' : $shop->slug }}"
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
        <div class="form-section-title">Tax &amp; licence</div>

        <div class="settings-grid">
            <div class="field">
                <label for="shop-gstin">GSTIN</label>
                <input id="shop-gstin" type="text" name="gstin" class="form-control"
                       value="{{ $shop->gstin }}" autocomplete="off" aria-invalid="false"
                       maxlength="15" placeholder="22AAAAA0000A1Z5" style="text-transform:uppercase">
            </div>

            <div class="field">
                <label for="shop-pan">PAN</label>
                <input id="shop-pan" type="text" name="pan" class="form-control"
                       value="{{ $shop->pan }}" autocomplete="off" aria-invalid="false"
                       maxlength="10" placeholder="ABCDE1234F" style="text-transform:uppercase">
            </div>

            <div class="field">
                <label for="shop-licence">Dealer licence no.</label>
                <input id="shop-licence" type="text" name="licence_no" class="form-control"
                       value="{{ $shop->licence_no }}" autocomplete="off" aria-invalid="false">
                <div class="form-hint">Fertiliser, pesticide or seed licence, where it applies.</div>
            </div>

            <div class="field">
                <label for="shop-state-code">GST state code</label>
                <input id="shop-state-code" type="text" name="state_code" class="form-control"
                       value="{{ $shop->state_code }}" autocomplete="off" aria-invalid="false"
                       maxlength="4" placeholder="27">
                <div class="form-hint">Decides whether a sale is taxed as CGST+SGST or IGST.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Contact &amp; address</div>

        <div class="settings-grid">
            <div class="field">
                <label for="shop-phone">Phone</label>
                <input id="shop-phone" type="text" name="phone" class="form-control"
                       value="{{ $shop->phone }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="shop-email">Email</label>
                <input id="shop-email" type="email" name="email" class="form-control"
                       value="{{ $shop->email }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="shop-addr1">Address line 1</label>
                <input id="shop-addr1" type="text" name="address_line1" class="form-control"
                       value="{{ $shop->address_line1 }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="shop-addr2">Address line 2</label>
                <input id="shop-addr2" type="text" name="address_line2" class="form-control"
                       value="{{ $shop->address_line2 }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="shop-city">City</label>
                <input id="shop-city" type="text" name="city" class="form-control"
                       value="{{ $shop->city }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="shop-state">State</label>
                <input id="shop-state" type="text" name="state" class="form-control"
                       value="{{ $shop->state }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="shop-pincode">PIN code</label>
                <input id="shop-pincode" type="text" name="pincode" class="form-control"
                       value="{{ $shop->pincode }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="shop-country">Country</label>
                <input id="shop-country" type="text" name="country" class="form-control"
                       value="{{ $shop->country ?? 'India' }}" autocomplete="off" aria-invalid="false">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Billing &amp; rules</div>

        <div class="settings-grid">
            <div class="field">
                <label for="shop-inv-prefix">Invoice prefix</label>
                <input id="shop-inv-prefix" type="text" name="invoice_prefix" class="form-control"
                       value="{{ $shop->invoice_prefix ?? 'INV' }}" autocomplete="off" aria-invalid="false"
                       maxlength="20" style="text-transform:uppercase">
                <div class="form-hint">
                    Numbers read <span class="list-ref">{{ $shop->invoice_prefix ?? 'INV' }}/{{ $shop->code ?: 'CODE' }}/{{ now()->format('Y') }}/00001</span>.
                </div>
            </div>

            <div class="field">
                <label for="shop-pos-prefix">POS receipt prefix</label>
                <input id="shop-pos-prefix" type="text" name="pos_prefix" class="form-control"
                       value="{{ $shop->pos_prefix ?? 'POS' }}" autocomplete="off" aria-invalid="false"
                       maxlength="20" style="text-transform:uppercase">
            </div>

            <div class="field">
                <label for="shop-currency">Currency</label>
                <input id="shop-currency" type="text" name="currency" class="form-control"
                       value="{{ $shop->currency ?? 'INR' }}" autocomplete="off" aria-invalid="false"
                       maxlength="8" style="text-transform:uppercase">
            </div>

            {{--
                Where this branch's UPI money lands (§8).

                Per outlet on purpose: the bill screen prints a code from
                whatever is here, and a franchise settling its own tables must
                not credit the branch down the road. Left blank, the bill
                screen offers no code and UPI is recorded by reference only -
                which is exactly right for a branch that takes none.
            --}}
            <div class="field">
                <label for="shop-upi-id">UPI ID</label>
                <input id="shop-upi-id" type="text" name="upi_id" class="form-control"
                       value="{{ old('upi_id', $shop->upi_id) }}" autocomplete="off" aria-invalid="false"
                       maxlength="120" placeholder="restaurant@okhdfcbank">
                <div class="form-hint">
                    The bill screen turns this into a scannable code for the guest.
                    Leave it empty if this branch takes no UPI.
                </div>
            </div>

            <div class="field">
                <label for="shop-upi-name">UPI payee name</label>
                <input id="shop-upi-name" type="text" name="upi_name" class="form-control"
                       value="{{ old('upi_name', $shop->upi_name) }}" autocomplete="off" aria-invalid="false"
                       maxlength="120" placeholder="{{ $shop->name ?: 'Name the guest will see' }}">
                <div class="form-hint">
                    What the guest's banking app shows before they confirm. Blank
                    falls back to the branch name.
                </div>
            </div>

            <div class="field">
                <label for="shop-timezone">Timezone</label>
                <select id="shop-timezone" name="timezone" class="form-control" aria-invalid="false">
                    @foreach (['Asia/Kolkata', 'Asia/Dubai', 'Asia/Kathmandu', 'Asia/Colombo', 'Asia/Dhaka', 'UTC'] as $tz)
                        <option value="{{ $tz }}" @selected(($shop->timezone ?? 'Asia/Kolkata') === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
            </div>

            {{--
                When the kitchen is deemed to have used its ingredients (§10).
                Per outlet, because the answer really does differ: a central
                kitchen counts at the pass, a small cafe does not count at all.
            --}}
            <div class="field field-full">
                <label for="sh-recipe">Ingredients come off stock</label>
                <select id="sh-recipe" name="recipe_deduction" class="form-control" aria-invalid="false">
                    @foreach (App\Services\RecipeService::MOMENTS as $key => $label)
                        <option value="{{ $key }}"
                            @selected(($shop->recipe_deduction ?: 'ready') === $key)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">
                    A dish moves no stock when it is sold — a restaurant has no count of
                    Butter Naan. Its recipe's ingredients come off here instead. "When the
                    dish is ready" is the safest: a ticket that never gets there was never
                    cooked.
                </div>
            </div>

            <div class="field field-full">
                <label class="check">
                    <input type="hidden" name="allow_negative_stock" value="0">
                    <input type="checkbox" name="allow_negative_stock" value="1"
                           @checked($shop->allow_negative_stock)>
                    Allow stock to go negative
                </label>
                <div class="form-hint">
                    Off by default. Leave it off unless the counter genuinely bills ahead of receiving.
                </div>
            </div>

            <div class="field field-full">
                <label class="check">
                    <input type="hidden" name="block_expired_sale" value="0">
                    <input type="checkbox" name="block_expired_sale" value="1"
                           @checked($isNew ? true : $shop->block_expired_sale)>
                    Block the sale of expired batches
                </label>
                <div class="form-hint">
                    Agri inputs past their expiry date should not leave the counter.
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Business modules</div>

        <div class="settings-grid">
            <div class="field field-full">
                <div class="form-hint" style="margin-bottom:10px">
                    What this branch actually does. A counter that only takes orders has no
                    use for purchase orders, and a central kitchen has no till — so what is
                    switched off here disappears from this shop's dashboard, its sidebar and its
                    URLs, for everybody.
                    <strong>This is not a permission.</strong> It says whether the branch does this
                    kind of business at all; who may do it is still the role's answer, and both
                    have to say yes.
                </div>

                {{--
                    An unticked checkbox posts nothing, so "no modules" and
                    "the caller never mentioned modules" would look identical.
                    This flag is what tells the controller the question was
                    actually asked. See ShopController::attributes().
                --}}
                <input type="hidden" name="modules_configured" value="1">

                <div class="check-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px">
                    @foreach ($modules as $key => $module)
                        <label class="check" style="align-items:flex-start">
                            <input type="checkbox" name="modules[]" value="{{ $key }}"
                                   @checked($enabled->contains($key))>
                            <span>
                                {{ $module['label'] }}
                                <span class="text-xs text-muted" style="display:block">
                                    {{ $module['blurb'] }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">QR ordering</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label class="check" style="align-items:flex-start">
                    <input type="checkbox" name="requires_otp" value="1" @checked($shop->requires_otp)>
                    <span>
                        Confirm the guest's mobile number before sending an order to the kitchen
                        <span class="text-xs text-muted" style="display:block">
                            A guest who scans the QR is texted a code, once per sitting — not once
                            per round. Off by default. Turn it on if you have had prank orders;
                            otherwise it is friction between a hungry guest and their food.
                        </span>
                    </span>
                </label>

                @if (! app(App\Services\Sms\SmsManager::class)->isLive())
                    <div class="form-hint">
                        No SMS provider is configured, so nothing can be sent. Until one is set up
                        this step is skipped rather than blocking orders — codes are written to the
                        log, which is enough to try it out.
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Staff access</div>

        <div class="settings-grid">
            <div class="field field-full">
                <div class="form-label">Who may work in this shop</div>
                <div class="form-hint" style="margin-bottom:8px">
                    This list <em>is</em> the authorisation: nobody outside it can read or write this
                    shop's data. Super Admins reach every shop regardless.
                </div>

                <div class="check-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px">
                    @forelse ($staff as $person)
                        <label class="check">
                            <input type="checkbox" name="users[]" value="{{ $person->id }}"
                                   @checked($assigned->contains($person->id))>
                            <span>
                                {{ $person->name }}
                                <span class="text-xs text-muted" style="display:block">{{ $person->email }}</span>
                            </span>
                        </label>
                    @empty
                        <div class="text-sm text-muted">No admin accounts to assign yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Presentation</div>

        <div class="settings-grid">
            <div class="field">
                <label for="shop-order">Display order</label>
                <input id="shop-order" type="number" name="sort_order" class="form-control"
                       value="{{ $shop->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
                <div class="form-hint">Lower numbers appear first in the shop switcher.</div>
            </div>

            <div class="field">
                <div class="form-label">Status</div>
                <label class="check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $shop->is_active)>
                    Active
                </label>
                <div class="form-hint">An inactive shop keeps its history but cannot be traded from.</div>
            </div>

            <div class="field field-full">
                <div class="form-label">Logo</div>

                <div class="setting-image" data-image-field>
                    <div class="setting-image-preview" data-file-preview>
                        @if ($logo)
                            <img src="{{ $logo }}" alt="{{ $shop->name }}">
                        @else
                            <span class="text-xs text-muted">No logo</span>
                        @endif
                    </div>

                    <div class="setting-image-controls">
                        <label for="shop-logo" class="sr-only">Choose a logo</label>
                        <input id="shop-logo" type="file" name="logo"
                               accept="image/jpeg,image/png,image/webp" data-file-input>

                        <div class="form-hint">
                            JPG, PNG or WebP · up to 2&nbsp;MB · printed on this shop's invoices
                        </div>

                        @unless ($isNew)
                            <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                    data-remove-url="{{ route('admin.shops.logo.destroy', $shop) }}"
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
            {{ $isNew ? 'Create shop' : 'Save changes' }}
        </button>
    </div>
</form>
