{{--
    Add / Edit product, rendered straight into the modal body.

    No <script> here — markup injected via innerHTML never runs its scripts.
    The image preview and remove buttons are delegated from crud-forms.js.
--}}

@php
    $isNew = ! $product->exists;
    $image = $isNew ? null : $product->imageUrl();
    $gallery = $isNew ? collect() : $product->images;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.products.store') : route('admin.products.update', $product) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Identity</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="prod-name">Product name</label>
                <input id="prod-name" type="text" name="name" class="form-control" required
                       value="{{ $product->name }}" autocomplete="off" aria-invalid="false"
                       data-slug-source>
            </div>

            <div class="field">
                <label for="prod-sku">SKU</label>
                <input id="prod-sku" type="text" name="sku" class="form-control"
                       value="{{ $product->sku }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $suggestedSku ?? $product->sku }}" style="text-transform:uppercase">
                <div class="form-hint">
                    {{ $isNew ? 'Leave blank and one is generated from the name.' : 'Printed on shelf labels.' }}
                </div>
            </div>

            <div class="field">
                <label for="prod-barcode">Barcode</label>
                <input id="prod-barcode" type="text" name="barcode" class="form-control"
                       value="{{ $product->barcode }}" autocomplete="off" aria-invalid="false">
                <div class="form-hint">
                    The manufacturer's code, scanned at the counter. Must be unique across the catalogue.
                </div>
            </div>

            <div class="field">
                <label for="prod-category">Category</label>
                <select id="prod-category" name="category_id" class="form-control" aria-invalid="false">
                    <option value="">No category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($product->category_id === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{--
                Routing (§9). The per-dish exception, not the usual way -
                which is why the default option says where it would otherwise
                go rather than "none". Left off the form altogether where a
                branch runs no stations.
            --}}
            @if ($stations->isNotEmpty())
                <div class="field">
                    <label for="prod-station">Cooked at</label>
                    <select id="prod-station" name="kitchen_station_id" class="form-control" aria-invalid="false">
                        <option value="">Wherever the category goes</option>
                        @foreach ($stations as $station)
                            <option value="{{ $station->id }}"
                                @selected((int) $product->kitchen_station_id === (int) $station->id)>
                                {{ $station->name }}@if ($station->is_default) (default)@endif
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">
                        Only for the dishes that do not follow their section — the
                        one dessert off the tandoor.
                    </div>
                </div>
            @endif

            <div class="field">
                <label for="prod-brand">Brand</label>
                <select id="prod-brand" name="brand_id" class="form-control" aria-invalid="false">
                    <option value="">No brand</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected($product->brand_id === $brand->id)>
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="prod-unit">Unit</label>
                <select id="prod-unit" name="unit_id" class="form-control" required aria-invalid="false">
                    <option value="">Choose a unit…</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected($product->unit_id === $unit->id)>
                            {{ $unit->name }} ({{ $unit->code }}){{ $unit->allow_decimal ? ' · fractional' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="prod-manufacturer">Manufacturer</label>
                <input id="prod-manufacturer" type="text" name="manufacturer" class="form-control"
                       value="{{ $product->manufacturer }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="prod-slug">Slug</label>
                <input id="prod-slug" type="text" name="slug" class="form-control"
                       value="{{ $product->slug }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $isNew ? 'Filled in from the name' : $product->slug }}"
                       data-slug-target>
                <div class="form-hint">Used in the online store's address.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Pricing &amp; tax</div>

        <div class="settings-grid">
            <div class="field">
                <label for="prod-purchase">Purchase price (₹)</label>
                <input id="prod-purchase" type="number" name="purchase_price" class="form-control"
                       value="{{ $product->exists ? (float) $product->purchase_price : 0 }}"
                       min="0" step="0.01" aria-invalid="false">
                <div class="form-hint">A default. Actual cost comes from what each receipt paid.</div>
            </div>

            <div class="field">
                <label for="prod-mrp">MRP (₹)</label>
                <input id="prod-mrp" type="number" name="mrp" class="form-control"
                       value="{{ $product->exists ? (float) $product->mrp : 0 }}"
                       min="0" step="0.01" aria-invalid="false">
            </div>

            <div class="field">
                <label for="prod-selling">Selling price (₹)</label>
                <input id="prod-selling" type="number" name="selling_price" class="form-control" required
                       value="{{ $product->exists ? (float) $product->selling_price : '' }}"
                       min="0" step="0.01" aria-invalid="false">
            </div>

            <div class="field">
                <label for="prod-takeaway">Takeaway price (₹)</label>
                <input id="prod-takeaway" type="number" name="takeaway_price" class="form-control"
                       value="{{ $product->takeaway_price !== null ? (float) $product->takeaway_price : '' }}"
                       min="0" step="0.01" aria-invalid="false"
                       placeholder="Same as selling price">
            </div>

            <div class="field">
                <label for="prod-delivery">Delivery price (₹)</label>
                <input id="prod-delivery" type="number" name="delivery_price" class="form-control"
                       value="{{ $product->delivery_price !== null ? (float) $product->delivery_price : '' }}"
                       min="0" step="0.01" aria-invalid="false"
                       placeholder="Same as selling price">
                <div class="form-hint">
                    Blank means the same price. Delivery is usually dearer — the aggregator takes a cut.
                </div>
            </div>

            <div class="field">
                <label for="prod-discount">Standard discount (%)</label>
                <input id="prod-discount" type="number" name="discount_percent" class="form-control"
                       value="{{ $product->exists ? (float) $product->discount_percent : 0 }}"
                       min="0" max="100" step="0.01" aria-invalid="false">
            </div>

            <div class="field">
                <label for="prod-tax">GST slab</label>
                <select id="prod-tax" name="tax_rate_id" class="form-control" aria-invalid="false">
                    <option value="">No tax</option>
                    @foreach ($taxRates as $rate)
                        <option value="{{ $rate->id }}" @selected($defaultTaxRateId === $rate->id)>
                            {{ $rate->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="prod-hsn">HSN code</label>
                <input id="prod-hsn" type="text" name="hsn_code" class="form-control"
                       value="{{ $product->hsn_code }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label class="check">
                    <input type="hidden" name="tax_inclusive" value="0">
                    <input type="checkbox" name="tax_inclusive" value="1"
                           @checked($isNew ? true : $product->tax_inclusive)>
                    The selling price already includes GST
                </label>
                <div class="form-hint">
                    On for shelf prices a customer pays as marked; off when tax is added at the till.
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Stock control</div>

        <div class="settings-grid">
            <div class="field">
                <label for="prod-reorder">Reorder level</label>
                <input id="prod-reorder" type="number" name="reorder_level" class="form-control"
                       value="{{ $product->exists ? (float) $product->reorder_level : 0 }}"
                       min="0" step="0.001" aria-invalid="false">
                <div class="form-hint">At or below this, the product is flagged as low. 0 turns the alert off.</div>
            </div>

            <div class="field">
                <label for="prod-min">Minimum stock</label>
                <input id="prod-min" type="number" name="min_stock" class="form-control"
                       value="{{ $product->exists ? (float) $product->min_stock : 0 }}"
                       min="0" step="0.001" aria-invalid="false">
            </div>

            {{--
                What kind of thing this is. Both flags decide whether the two
                fields above them mean anything at all, so they come first.
            --}}
            <div class="field field-full">
                <label class="check">
                    <input type="hidden" name="is_ingredient" value="0">
                    <input type="checkbox" name="is_ingredient" value="1"
                           @checked($product->is_ingredient)>
                    An ingredient — bought and stocked, never sold
                </label>
                <div class="form-hint">
                    Flour, butter, chicken. Kept off the menu, the storefront and the
                    till, and available to put in a recipe. Everything else about it —
                    purchasing, stock, cost, low-stock alerts — works exactly as it
                    does for anything you sell.
                </div>
            </div>
            {{--
                First in this section on purpose: it decides whether the two
                fields above it mean anything at all. A dish has no minimum
                stock, because there is no count of it to be below.
            --}}
            <div class="field field-full">
                <label class="check">
                    <input type="hidden" name="is_made_to_order" value="0">
                    <input type="checkbox" name="is_made_to_order" value="1"
                           @checked($product->exists ? $product->is_made_to_order : true)>
                    Made to order — cooked, not taken off a shelf
                </label>
                <div class="form-hint">
                    On for anything the kitchen makes. Selling it moves no stock,
                    and a bill is never refused because a count says zero — a
                    restaurant has no stock of Butter Naan. Turn it off for
                    things sold as they are: bottled water, a packet of crisps.
                </div>
            </div>

            <div class="field field-full">
                <label class="check">
                    <input type="hidden" name="track_batches" value="0">
                    <input type="checkbox" name="track_batches" value="1" @checked($product->track_batches)>
                    Track this product batch by batch
                </label>
                <div class="form-hint">
                    Turn on for anything with an expiry date. Receipts then capture a batch number and
                    dates, and the counter sells oldest-expiry first.
                </div>
            </div>
        </div>
    </div>

    @if ($overrideShop)
        <div class="form-section">
            <div class="form-section-title">Price at {{ $overrideShop->name }}</div>

            <p class="text-xs text-muted" style="margin:-4px 0 10px">
                Leave a field blank to use the catalogue value above. Filling one in overrides it for
                this shop only — other branches keep following the catalogue.
            </p>

            <div class="settings-grid">
                <div class="field">
                    <label for="ov-purchase">Purchase price (₹)</label>
                    <input id="ov-purchase" type="number" name="override[purchase_price]" class="form-control"
                           value="{{ $override?->purchase_price }}" min="0" step="0.01" aria-invalid="false"
                           placeholder="{{ number_format((float) $product->purchase_price, 2) }}">
                </div>

                <div class="field">
                    <label for="ov-mrp">MRP (₹)</label>
                    <input id="ov-mrp" type="number" name="override[mrp]" class="form-control"
                           value="{{ $override?->mrp }}" min="0" step="0.01" aria-invalid="false"
                           placeholder="{{ number_format((float) $product->mrp, 2) }}">
                </div>

                <div class="field">
                    <label for="ov-selling">Selling price (₹)</label>
                    <input id="ov-selling" type="number" name="override[selling_price]" class="form-control"
                           value="{{ $override?->selling_price }}" min="0" step="0.01" aria-invalid="false"
                           placeholder="{{ number_format((float) $product->selling_price, 2) }}">
                </div>

                <div class="field">
                    <label for="ov-discount">Discount (%)</label>
                    <input id="ov-discount" type="number" name="override[discount_percent]" class="form-control"
                           value="{{ $override?->discount_percent }}" min="0" max="100" step="0.01"
                           aria-invalid="false"
                           placeholder="{{ (float) $product->discount_percent }}">
                </div>

                <div class="field">
                    <label for="ov-reorder">Reorder level</label>
                    <input id="ov-reorder" type="number" name="override[reorder_level]" class="form-control"
                           value="{{ $override?->reorder_level }}" min="0" step="0.001" aria-invalid="false"
                           placeholder="{{ (float) $product->reorder_level }}">
                </div>

                <div class="field">
                    <label for="ov-min">Minimum stock</label>
                    <input id="ov-min" type="number" name="override[min_stock]" class="form-control"
                           value="{{ $override?->min_stock }}" min="0" step="0.001" aria-invalid="false"
                           placeholder="{{ (float) $product->min_stock }}">
                </div>

                <div class="field field-full">
                    <label class="check">
                        <input type="hidden" name="override[is_active]" value="0">
                        <input type="checkbox" name="override[is_active]" value="1"
                               @checked($override ? (bool) $override->is_active : true)>
                        Sell this product at {{ $overrideShop->name }}
                    </label>
                    <div class="form-hint">Untick to withdraw it from this branch without touching the others.</div>
                </div>
            </div>
        </div>
    @endif

    {{--
        Sizes (§8). Prices are absolute, not deltas - "Half 180, Full 320" is
        how a menu is written and how a cook quotes it. See the migration.

        Same repeating-row wiring as the add-on answers: js/modifier-options.js
        drives both, because they are the same control.
    --}}
    <div class="form-section" data-modifier-options>
        <div class="form-section-title">Sizes</div>

        <div class="form-hint" style="margin-bottom:8px">
            Leave this empty for a dish that comes one way. Where there are sizes, an order must
            name one — there is no fallback, because that is how a table gets billed for a Full
            and served a Half.
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:34%">Size</th>
                        <th style="width:20%">Price (₹)</th>
                        <th style="width:20%">Code</th>
                        <th style="width:12%">Opens on</th>
                        <th style="width:12%">Available</th>
                        <th class="col-action"></th>
                    </tr>
                </thead>

                <tbody data-option-body>
                    @foreach ($variants as $index => $variant)
                        <tr data-option-row>
                            <td>
                                <label class="sr-only" for="var-name-{{ $index }}">Size name</label>
                                <input id="var-name-{{ $index }}" type="text"
                                       name="options[{{ $index }}][name]" class="form-control"
                                       value="{{ $variant->name }}" autocomplete="off" aria-invalid="false"
                                       maxlength="60" placeholder="Full">
                            </td>
                            <td>
                                <label class="sr-only" for="var-price-{{ $index }}">Price</label>
                                <input id="var-price-{{ $index }}" type="number" step="0.01" min="0"
                                       name="options[{{ $index }}][price]" class="form-control"
                                       value="{{ (float) $variant->price }}" aria-invalid="false">
                            </td>
                            <td>
                                <label class="sr-only" for="var-sku-{{ $index }}">Code</label>
                                <input id="var-sku-{{ $index }}" type="text"
                                       name="options[{{ $index }}][sku]" class="form-control"
                                       value="{{ $variant->sku }}" autocomplete="off" aria-invalid="false"
                                       maxlength="60" placeholder="Optional">
                            </td>
                            <td>
                                <label class="check">
                                    <input type="hidden" name="options[{{ $index }}][is_default]" value="0">
                                    <input type="checkbox" name="options[{{ $index }}][is_default]" value="1"
                                           @checked($variant->is_default)>
                                    <span class="sr-only">Opens on this size</span>
                                </label>
                            </td>
                            <td>
                                <label class="check">
                                    <input type="hidden" name="options[{{ $index }}][is_available]" value="0">
                                    <input type="checkbox" name="options[{{ $index }}][is_available]" value="1"
                                           @checked($variant->is_available)>
                                    <span class="sr-only">Available</span>
                                </label>
                            </td>
                            <td class="col-action">
                                <button type="button" class="btn btn-icon is-danger" data-option-remove
                                        aria-label="Remove this size">
                                    <x-icon name="trash" :size="15" />
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="padding:10px 0">
            <button type="button" class="btn btn-sm" data-option-add>
                <x-icon name="plus" :size="15" /> Add a size
            </button>
        </div>

        <template data-option-template>
            <tr data-option-row>
                <td>
                    <input type="text" name="options[@{{i}}][name]" class="form-control"
                           autocomplete="off" aria-invalid="false" maxlength="60"
                           aria-label="Size name" placeholder="Full">
                </td>
                <td>
                    <input type="number" step="0.01" min="0" name="options[@{{i}}][price]"
                           class="form-control" value="0" aria-invalid="false" aria-label="Price">
                </td>
                <td>
                    <input type="text" name="options[@{{i}}][sku]" class="form-control"
                           autocomplete="off" aria-invalid="false" maxlength="60"
                           aria-label="Code" placeholder="Optional">
                </td>
                <td>
                    <label class="check">
                        <input type="hidden" name="options[@{{i}}][is_default]" value="0">
                        <input type="checkbox" name="options[@{{i}}][is_default]" value="1">
                        <span class="sr-only">Opens on this size</span>
                    </label>
                </td>
                <td>
                    <label class="check">
                        <input type="hidden" name="options[@{{i}}][is_available]" value="0">
                        <input type="checkbox" name="options[@{{i}}][is_available]" value="1" checked>
                        <span class="sr-only">Available</span>
                    </label>
                </td>
                <td class="col-action">
                    <button type="button" class="btn btn-icon is-danger" data-option-remove
                            aria-label="Remove this size">
                        <x-icon name="trash" :size="15" />
                    </button>
                </td>
            </tr>
        </template>
    </div>

    {{--
        What this dish asks the guest. Read-only here on purpose: a question
        belongs to many dishes, and editing its answers from one of them would
        change the other twenty without saying so. The Add-ons screen owns it.
    --}}
    @if ($product->exists && $product->modifiers->isNotEmpty())
        <div class="form-section">
            <div class="form-section-title">Add-ons asked</div>

            <div style="display:flex;flex-wrap:wrap;gap:6px">
                @foreach ($product->modifiers as $modifier)
                    <span class="badge">{{ $modifier->name }} · {{ $modifier->ruleLabel() }}</span>
                @endforeach
            </div>

            @allows('inventory.modifiers.view')
                <div class="form-hint" style="margin-top:8px">
                    Edited on <a href="{{ route('admin.modifiers.index') }}">Add-ons &amp; Modifiers</a> —
                    a question is shared by every dish that asks it.
                </div>
            @endallows
        </div>
    @endif

    <div class="form-section">
        <div class="form-section-title">Menu detail</div>

        <div class="settings-grid">
            <div class="field">
                <label for="prod-food-type">Food type</label>
                <select id="prod-food-type" name="food_type" class="form-control" aria-invalid="false">
                    {{--
                        Blank is a real answer, not a missing one: a bottle of
                        water is not vegetarian food, it is not food, and it
                        must not get a green dot.
                    --}}
                    <option value="">Not food (no mark)</option>
                    @foreach (App\Models\Product::FOOD_TYPES as $key => $meta)
                        <option value="{{ $key }}" @selected($product->food_type === $key)>
                            {{ $meta['label'] }}
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">Prints the green or brown mark on the menu and the bill.</div>
            </div>

            <div class="field">
                <label for="prod-spice">Spice level</label>
                <select id="prod-spice" name="spice_level" class="form-control" aria-invalid="false">
                    @foreach (App\Models\Product::SPICE_LEVELS as $level => $label)
                        <option value="{{ $level }}" @selected((int) $product->spice_level === $level)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="prod-serves">Serves</label>
                <input id="prod-serves" type="number" name="serves" class="form-control"
                       value="{{ $product->serves }}" min="1" max="50" aria-invalid="false"
                       placeholder="2">
                <div class="form-hint">Leave blank if it does not apply.</div>
            </div>

            <div class="field">
                <label for="prod-prep">Kitchen time (minutes)</label>
                <input id="prod-prep" type="number" name="prep_minutes" class="form-control"
                       value="{{ $product->prep_minutes }}" min="0" max="600" aria-invalid="false"
                       placeholder="15">
                <div class="form-hint">Ages the ticket on the kitchen screen.</div>
            </div>

            <div class="field field-full">
                <label for="prod-tags">Tags</label>
                <input id="prod-tags" type="text" name="food_tags" class="form-control"
                       value="{{ collect($product->food_tags ?? [])->implode(', ') }}"
                       autocomplete="off" aria-invalid="false"
                       placeholder="Chef&#39;s special, Contains nuts, Gluten free">
                <div class="form-hint">Comma separated. Printed next to the dish on the menu.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Availability</div>

        <div class="settings-grid">
            <div class="field field-full">
                <div class="form-label">Sold out</div>
                <label class="check">
                    <input type="hidden" name="is_sold_out" value="0">
                    <input type="checkbox" name="is_sold_out" value="1" @checked($product->is_sold_out)>
                    Off the menu right now
                </label>
                <div class="form-hint">
                    The kitchen can throw this in one tap from the menu list. Set a time below to have it
                    come back on its own.
                </div>
            </div>

            <div class="field">
                <label for="prod-sold-until">Back on at</label>
                <input id="prod-sold-until" type="datetime-local" name="sold_out_until" class="form-control"
                       value="{{ $product->sold_out_until?->format('Y-m-d\TH:i') }}" aria-invalid="false">
                <div class="form-hint">Blank means sold out until somebody says otherwise.</div>
            </div>

            <div class="field"></div>

            <div class="field">
                <label for="prod-from">Served from</label>
                <input id="prod-from" type="time" name="available_from" class="form-control"
                       value="{{ $product->available_from ? substr($product->available_from, 0, 5) : '' }}"
                       aria-invalid="false">
            </div>

            <div class="field">
                <label for="prod-to">Served until</label>
                <input id="prod-to" type="time" name="available_to" class="form-control"
                       value="{{ $product->available_to ? substr($product->available_to, 0, 5) : '' }}"
                       aria-invalid="false">
                <div class="form-hint">
                    Both blank means all day. A window may cross midnight — 23:00 to 02:00 works.
                </div>
            </div>

            <div class="field field-full">
                <div class="form-label">Served on</div>
                <div style="display:flex;flex-wrap:wrap;gap:12px">
                    @php
                        $days = collect($product->available_days ?? [])->map(fn ($d) => (int) $d);
                        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                    @endphp
                    @foreach ($names as $iso => $label)
                        <label class="check">
                            <input type="checkbox" name="available_days[]" value="{{ $iso }}"
                                   @checked($days->contains($iso))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <div class="form-hint">None ticked means every day.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Description &amp; media</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="prod-short">Short description</label>
                <textarea id="prod-short" name="short_description" class="form-control"
                          style="min-height:60px" aria-invalid="false">{{ $product->short_description }}</textarea>
            </div>

            <div class="field field-full">
                <label for="prod-description">Full description</label>
                <textarea id="prod-description" name="description" class="form-control"
                          style="min-height:110px" aria-invalid="false">{{ $product->description }}</textarea>
            </div>

            <div class="field field-full">
                <div class="form-label">Main image</div>

                <div class="setting-image" data-image-field>
                    <div class="setting-image-preview" data-file-preview>
                        @if ($image)
                            <img src="{{ $image }}" alt="{{ $product->name }}">
                        @else
                            <span class="text-xs text-muted">No image</span>
                        @endif
                    </div>

                    <div class="setting-image-controls">
                        <label for="prod-image" class="sr-only">Choose an image</label>
                        <input id="prod-image" type="file" name="image"
                               accept="image/jpeg,image/png,image/webp" data-file-input>

                        <div class="form-hint">JPG, PNG or WebP · up to 3&nbsp;MB</div>

                        @unless ($isNew)
                            <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                    data-remove-url="{{ route('admin.products.image.destroy', $product) }}"
                                    @unless ($image) hidden @endunless>
                                <x-icon name="trash" :size="13" /> Remove image
                            </button>
                        @endunless
                    </div>
                </div>
            </div>

            <div class="field field-full">
                <div class="form-label">Gallery</div>

                @if ($gallery->isNotEmpty())
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
                        @foreach ($gallery as $item)
                            <div style="position:relative">
                                <span class="cat-thumb" style="width:64px;height:64px">
                                    @if ($item->url())
                                        <img src="{{ $item->url() }}" alt="">
                                    @else
                                        <span class="text-xs text-muted">?</span>
                                    @endif
                                </span>

                                {{-- Its own endpoint, so an image can go
                                     without saving the rest of the form. --}}
                                <button type="button" class="btn btn-icon is-danger"
                                        style="position:absolute;top:-6px;right:-6px"
                                        data-image-remove
                                        data-remove-url="{{ route('admin.products.gallery.destroy', [$product, $item]) }}"
                                        aria-label="Remove gallery image">
                                    <x-icon name="x" :size="12" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif

                <label for="prod-gallery" class="sr-only">Add gallery images</label>
                <input id="prod-gallery" type="file" name="gallery[]" multiple
                       accept="image/jpeg,image/png,image/webp">
                <div class="form-hint">
                    Up to {{ $maxGallery }} images in total{{ $gallery->count() ? ' — '.$gallery->count().' already added' : '' }}.
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Online store &amp; status</div>

        <div class="settings-grid">
            <div class="field">
                <div class="form-label">Status</div>
                <label class="check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $product->is_active)>
                    Active
                </label>
                <div class="form-hint">An inactive product cannot be billed or listed.</div>
            </div>

            <div class="field">
                <div class="form-label">Online store</div>
                <label class="check">
                    <input type="hidden" name="is_published" value="0">
                    <input type="checkbox" name="is_published" value="1" @checked($product->is_published)>
                    List in the online store
                </label>
                <div class="form-hint">Ignored while the product is inactive.</div>
            </div>

            <div class="field">
                <div class="form-label">Featured</div>
                <label class="check">
                    <input type="hidden" name="is_featured" value="0">
                    <input type="checkbox" name="is_featured" value="1" @checked($product->is_featured)>
                    Show on the storefront homepage
                </label>
            </div>

            <div class="field">
                <label for="prod-order">Display order</label>
                <input id="prod-order" type="number" name="sort_order" class="form-control"
                       value="{{ $product->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="prod-meta-title">Meta title</label>
                <input id="prod-meta-title" type="text" name="meta_title" class="form-control"
                       value="{{ $product->meta_title }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="prod-meta-desc">Meta description</label>
                <textarea id="prod-meta-desc" name="meta_description" class="form-control"
                          style="min-height:56px" aria-invalid="false">{{ $product->meta_description }}</textarea>
            </div>

            <div class="field field-full">
                <label for="prod-meta-keys">Meta keywords</label>
                <input id="prod-meta-keys" type="text" name="meta_keywords" class="form-control"
                       value="{{ $product->meta_keywords }}" autocomplete="off" aria-invalid="false">
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create product' : 'Save changes' }}
        </button>
    </div>
</form>
