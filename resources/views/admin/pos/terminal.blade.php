@extends('admin.layouts.app')

@php
    use App\Models\Invoice;
    use App\Models\Product;
    use App\Support\ScannerSettings;

    $isCounter = $channel === Invoice::POS;

    /*
    | Settings > Company Settings > Barcode Scanner, already resolved - see
    | App\Support\ScannerSettings for how Scanner Mode narrows the two
    | per-method switches. Asked here rather than in JS so a shop that has
    | never opened that screen still gets the documented defaults, and so the
    | camera button and the camera itself can never disagree about whether
    | the camera is on.
    |
    | Desktop wireless scanner, mobile camera and a tap on the grid all end at
    | window.LineItems.add() and this one form. There is no second billing
    | page and no second cart.
    */
    $cameraScanner = ScannerSettings::cameraEnabled();
    $wirelessScanner = ScannerSettings::wirelessEnabled();

    /*
    | The food-type filter, built from what is actually on the grid. A
    | restaurant with no egg dishes gets no Egg button rather than one that
    | filters to nothing.
    */
    $foodTypes = $menu
        ->map(fn (array $row) => $row['product']->food_type)
        ->filter()
        ->unique()
        ->sortBy(fn (string $type) => array_search($type, array_keys(Product::FOOD_TYPES), true))
        ->values();
@endphp

@section('title', $isCounter ? 'POS Billing' : 'New Invoice')
@section('body-class', 'pos-mode')

@push('styles')
    <link
        rel="stylesheet"
        href="{{ asset('assets/css/pos-terminal.css') }}?v={{ filemtime(public_path('assets/css/pos-terminal.css')) }}"
    >
@endpush

@section('content')
    <div class="till">
        <x-page-header
            :title="$isCounter ? 'POS Billing' : 'New Invoice'"
            :subtitle="$shop->name.' · next '.$nextNumber"
        >
            <x-slot:actions>
                {{--
                    The till fills the screen: sidebar, header and footer put away.
                    Hidden until pos-fullscreen.js finds the browser can do it - an
                    iPhone cannot, and a button that does nothing is worse than none.
                --}}
                <button type="button" class="btn btn-sm" data-fullscreen-toggle aria-pressed="false" hidden>
                    <span data-fs-enter><x-icon name="maximize" :size="15" /> Full screen</span>
                    <span data-fs-exit hidden><x-icon name="minimize" :size="15" /> Exit full screen</span>
                </button>

                @if ($isCounter)
                    @allows('sales.invoices.create')
                        <a class="btn btn-sm" href="{{ route('admin.pos.manual') }}">
                            <x-icon name="file" :size="15" /> Manual invoice
                        </a>
                    @endallows
                @else
                    @allows('pos.terminal.view')
                        <a class="btn btn-sm" href="{{ route('admin.pos.terminal') }}">
                            <x-icon name="cart" :size="15" /> Counter mode
                        </a>
                    @endallows
                @endif

                <a class="btn btn-sm" href="{{ route('admin.pos.parked') }}">
                    Held sales
                    @if ($heldCount > 0)
                        <span class="badge badge-warning">{{ $heldCount }}</span>
                    @endif
                </a>
            </x-slot:actions>
        </x-page-header>

        {{--
            One form, posted by app.js. Everything inside is driven by
            public/assets/js/pos.js, which owns the cart, the tender split and
            the running total; the grid beside it is pos-menu.js, which does
            nothing but call into the same cart.
        --}}
        <form method="POST" action="{{ route('admin.pos.store') }}"
              data-ajax data-toast-success="true" data-pos-form
              data-can-discount="{{ $canDiscount ? '1' : '0' }}"
              data-can-credit="{{ $canCredit ? '1' : '0' }}">
            @csrf
            <input type="hidden" name="channel" value="{{ $channel }}">

            <div class="till-grid"
                 data-line-items
                 data-pos-menu
                 {{--
                     The till is the one line editor that sells, so it is the
                     one that caps a quantity at what is on the shelf - by the
                     keyboard as well as the +/- buttons. Left off for a shop
                     that allows negative stock, where selling past the count
                     is a decision somebody has already made.

                     A dish is exempt whatever this says: see tracksStock().
                 --}}
                 @unless ($shop->allow_negative_stock) data-line-stock-cap @endunless
                 data-lookup-url="{{ route('admin.products.lookup') }}"
                 data-scanner-product-url="{{ route('admin.scanner.product') }}"
                 data-scanner-settings='{{ json_encode(ScannerSettings::forJs()) }}'
                 @unless (ScannerSettings::autoDetect())
                     data-scanner-auto-detect="0"
                 @endunless
                 @unless (ScannerSettings::autoEnter())
                     data-scanner-auto-enter="0"
                 @endunless>

                {{-- ================================================= menu --}}
                <section class="till-menu">
                    <div class="till-menu-head">
                        <div class="till-menu-title">
                            <h2>
                                Menu
                                <x-info label="How to add items">Tap a dish to add it to the bill. The + and − on each card change its quantity.</x-info>
                            </h2>
                            <p class="till-menu-sub">
                                <span data-menu-shown>{{ $menu->count() }}</span> of
                                {{ $menu->count() }} item(s)
                            </p>
                        </div>

                        {{--
                            The food mark is the Indian convention and is not
                            decoration: filtering to "Veg" has to mean it.
                        --}}
                        @if ($foodTypes->isNotEmpty())
                            <div class="till-marks" role="group" aria-label="Filter by food type">
                                <button type="button" class="mark-btn is-on" data-food-filter="">All</button>

                                @foreach ($foodTypes as $type)
                                    <button type="button" class="mark-btn" data-food-filter="{{ $type }}"
                                            style="--mark: {{ Product::FOOD_TYPES[$type]['dot'] }}">
                                        <span class="mark-dot"></span>
                                        {{ Product::FOOD_TYPES[$type]['short'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="till-search">
                        <div class="line-search field">
                            <label for="pos-scan" class="sr-only">Scan or search</label>
                            <input id="pos-scan" type="search" class="form-control form-control-lg"
                                   data-line-search autocomplete="off"
                                   @if (ScannerSettings::autoFocus()) autofocus @endif
                                   placeholder="{{ $wirelessScanner
                                       ? 'Scan a barcode, or search by name or SKU…'
                                       : 'Search by name or SKU…' }}">

                            {{-- F2 jumps here from anywhere on the till (pos.js). Drawn, so it is found. --}}
                            <span class="kbd-group pos-kbd" data-kbd-hint aria-hidden="true"><kbd class="kbd">F2</kbd></span>

                            @if ($cameraScanner)
                                {{--
                                    The compact trigger, for a counter screen
                                    where the camera is the exception. Its
                                    full-width twin below takes over on a phone
                                    - see pos.css; only ever one of the two is
                                    visible, so a screen reader is never
                                    offered the same action twice.
                                --}}
                                <button type="button" class="btn btn-icon scanner-camera-btn" data-scanner-camera
                                        title="Scan with camera" aria-label="Scan barcode with camera">
                                    <x-icon name="camera" :size="18" />
                                </button>
                            @endif

                            <div class="line-results" data-line-results hidden></div>
                        </div>

                        @if ($cameraScanner)
                            {{--
                                The phone's primary way into the cart. A camera
                                cannot be reached without a user gesture - the
                                browser asks permission on the tap - so this
                                button is not decoration, it is the permission
                                prompt.
                            --}}
                            <button type="button" class="btn btn-primary scanner-camera-cta" data-scanner-camera>
                                <x-icon name="camera" :size="18" /> Scan Barcode
                            </button>
                        @endif
                    </div>

                    @if ($menuCategories->isNotEmpty())
                        <div class="till-chips" role="group" aria-label="Menu categories">
                            <button type="button" class="chip is-on" data-category-filter="">
                                <span class="chip-face">All</span>
                                <span class="chip-text">
                                    All items
                                    <small>{{ $menu->count() }} items</small>
                                </span>
                            </button>

                            @foreach ($menuCategories as $row)
                                @php $category = $row['category']; @endphp

                                <button type="button" class="chip" data-category-filter="{{ $category->id }}">
                                    @if ($category->imageUrl())
                                        <img class="chip-face" src="{{ $category->imageUrl() }}" alt="" loading="lazy">
                                    @else
                                        <span class="chip-face">{{ $category->initials() }}</span>
                                    @endif

                                    <span class="chip-text">
                                        {{ $category->name }}
                                        <small>{{ $row['count'] }} item(s)</small>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <div class="dish-grid" data-dish-grid>
                        @foreach ($menu as $row)
                            @php
                                $product = $row['product'];
                                $reason = $row['reason'];
                                $dot = $product->foodTypeDot();
                            @endphp

                            {{--
                                Not a <button> wrapping the card: the card
                                carries its own +/- and a button inside a
                                button is invalid, so the card takes a click
                                for the pointer and the stepper's two real
                                buttons carry the keyboard. Every action here
                                is reachable both ways.
                            --}}
                            <article
                                class="dish @if ($reason) is-off @endif"
                                data-dish
                                data-dish-id="{{ $product->id }}"
                                data-category="{{ $product->category_id }}"
                                data-food-type="{{ $product->food_type }}"
                                data-name="{{ Str::lower($product->name) }}"
                                data-sku="{{ Str::lower((string) $product->sku) }}"
                                data-payload='@json($row['payload'])'
                                @if ($reason) data-off="{{ $reason }}" aria-disabled="true" @endif
                            >
                                <span class="dish-face" @if ($dot) style="--mark: {{ $dot }}" @endif>
                                    @if ($product->imageUrl())
                                        <img src="{{ $product->imageUrl() }}" alt="" loading="lazy">
                                    @else
                                        {{-- No photo uploaded: the dish still
                                             needs to be told apart at a glance,
                                             so it gets its initials on its own
                                             food-type tint rather than a grey
                                             box that looks like a failed
                                             image. --}}
                                        <span class="dish-initials">
                                            {{ Str::of($product->name)->explode(' ')->filter()->take(2)
                                                ->map(fn ($part) => Str::substr($part, 0, 1))->implode('') }}
                                        </span>
                                    @endif

                                    @if ($product->is_featured)
                                        <span class="dish-flag"><x-icon name="star" :size="10" /> Featured</span>
                                    @endif

                                    @if ($reason)
                                        <span class="dish-off">{{ $reason }}</span>
                                    @endif
                                </span>

                                <span class="dish-body">
                                    <span class="dish-cat">{{ $product->category?->name ?: 'Uncategorised' }}</span>

                                    <span class="dish-name">
                                        @if ($dot)
                                            <span class="mark-dot" style="--mark: {{ $dot }}"
                                                  role="img" title="{{ $product->foodTypeLabel() }}"
                                                  aria-label="{{ $product->foodTypeLabel() }}"></span>
                                        @endif
                                        {{ $product->name }}
                                    </span>

                                    <span class="dish-foot">
                                        <span class="dish-price">
                                            ₹{{ number_format((float) $row['payload']['selling_price'], 0) }}
                                        </span>

                                        @unless ($reason)
                                            {{-- The count between the buttons is
                                                 read back off the bill, never
                                                 kept here: two places holding
                                                 the same number is how a till
                                                 ends up billing what is not on
                                                 screen. --}}
                                            <span class="dish-step">
                                                <button type="button" data-dish-step="-1" disabled
                                                        aria-label="One less {{ $product->name }}">−</button>

                                                <output data-dish-qty
                                                        aria-label="{{ $product->name }} on the bill">0</output>

                                                <button type="button" data-dish-step="1"
                                                        aria-label="Add {{ $product->name }}">+</button>
                                            </span>
                                        @endunless
                                    </span>
                                </span>
                            </article>
                        @endforeach
                    </div>

                    {{-- Shown by pos-menu.js when a filter leaves nothing. --}}
                    <div class="dish-empty" data-dish-empty hidden>
                        <x-icon name="search" :size="26" />
                        <h3>Nothing matches that</h3>
                        <p>Try another category, or search by name, SKU or barcode above.</p>
                    </div>
                </section>

                {{-- ================================================ order --}}
                <aside class="till-order">
                    <div class="till-order-head">
                        <div>
                            <div class="till-order-title">{{ $isCounter ? 'Current order' : 'New invoice' }}</div>
                            <div class="till-order-ref">{{ $nextNumber }}</div>
                        </div>

                        <span class="pill is-brand" data-line-count-pill>
                            <span data-line-count>0</span> item(s)
                        </span>
                    </div>

                    {{-- ------------------------------------------ customer --}}
                    {{-- Side by side: a name on a bill is a few letters, not a row of its own. --}}
                    <div class="till-block till-who">
                        <div class="field pos-customer" data-customer-picker
                             data-lookup-url="{{ route('admin.customers.lookup') }}">
                            <label for="pos-customer" class="sr-only">Find a customer</label>
                            <input id="pos-customer" type="search" class="form-control"
                                   placeholder="Find customer" autocomplete="off"
                                   data-customer-search>
                            <div class="line-results" data-customer-results hidden></div>
                            <input type="hidden" name="customer_id" data-customer-id>
                        </div>

                        {{-- Filled in by pos.js once a customer is chosen. --}}
                        <div class="pos-chosen" data-customer-chosen hidden>
                            <div>
                                <strong data-customer-name></strong>
                                <span class="text-xs text-muted" style="display:block"
                                      data-customer-meta></span>
                            </div>
                            <button type="button" class="btn btn-icon" data-customer-clear
                                    aria-label="Remove customer">
                                <x-icon name="x" :size="14" />
                            </button>
                        </div>

                        {{-- No account: a name on the receipt is still useful. --}}
                        <div data-walkin>
                            <div class="field">
                                <label for="pos-walkin" class="sr-only">Name on the bill</label>
                                <input id="pos-walkin" type="text" name="walk_in_name"
                                       class="form-control" autocomplete="off" maxlength="150"
                                       placeholder="Name on bill">
                            </div>
                        </div>
                    </div>

                    {{-- ---------------------------------------------- cart --}}
                    <div class="till-lines" data-line-body></div>

                    <div class="till-empty" data-line-empty>
                        <x-icon name="cart" :size="26" />
                        <h3>Nothing on the bill yet</h3>
                        <p>
                            @if ($wirelessScanner && $cameraScanner)
                                Tap a dish, scan a barcode, or search above.
                                <x-info label="About scanning">A scanner's Enter finishes the line, so you never have to leave the search box.</x-info>
                            @elseif ($wirelessScanner)
                                Tap a dish or scan a barcode.
                                <x-info label="About scanning">A scanner's Enter finishes the line, so you never have to leave the search box.</x-info>
                            @elseif ($cameraScanner)
                                Tap a dish, or use <strong>Scan Barcode</strong> to add one with the camera.
                            @else
                                Tap a dish on the left, or search for it by name, SKU or barcode.
                            @endif
                        </p>
                    </div>

                    {{--
                        The panel never scrolls: everything from here down stays
                        in view and only the list of dishes above moves. So this
                        is one line per job - nothing here earns a row of its own.
                    --}}
                    <div class="till-foot">
                        {{-- --------------------------------------------- bill --}}
                        <div class="till-block till-bill">
                            <dl class="till-totals">
                                <div>
                                    <dt>Sub-total <small><span data-line-total-quantity>0</span> unit(s)</small></dt>
                                    <dd>₹<span data-line-total-value>0.00</span></dd>
                                </div>

                                @if ($canDiscount)
                                    <div class="till-discount">
                                        <dt>Discount</dt>
                                        <dd>
                                            <label for="pos-disc-amount" class="sr-only">Bill discount in rupees</label>
                                            <span class="till-mini">
                                                <span aria-hidden="true">₹</span>
                                                <input id="pos-disc-amount" type="number" name="invoice_discount"
                                                       class="form-control" min="0" step="0.01" value="0"
                                                       data-pos-discount-amount>
                                            </span>
                                            <label for="pos-disc-percent" class="sr-only">Bill discount in percent</label>
                                            <span class="till-mini">
                                                <input id="pos-disc-percent" type="number"
                                                       name="invoice_discount_percent" class="form-control"
                                                       min="0" max="100" step="0.01" value="0"
                                                       data-pos-discount-percent>
                                                <span aria-hidden="true">%</span>
                                            </span>
                                            <span class="till-discount-value">− ₹<span data-pos-discount>0.00</span></span>
                                        </dd>
                                    </div>
                                @else
                                    <div>
                                        <dt>
                                            Discount
                                            <x-info label="About discounts">Discounts need the override right. Ask a supervisor.</x-info>
                                        </dt>
                                        <dd>− ₹<span data-pos-discount>0.00</span></dd>
                                    </div>
                                @endif

                                <div class="is-total">
                                    <dt>
                                        To be paid
                                        <x-info label="About tax">Prices include GST. The exact tax split is worked out on the invoice.</x-info>
                                    </dt>
                                    <dd>₹<span data-pos-payable>0.00</span></dd>
                                </div>
                            </dl>
                        </div>

                        {{-- ------------------------------------------ payment --}}
                        <div class="till-block till-pay">
                            <div class="till-pay-row">
                                <div class="till-pay-label">
                                    Pay
                                    {{-- F4 jumps to the amount (pos.js). --}}
                                    <span class="kbd-group" data-kbd-hint aria-hidden="true"><kbd class="kbd">F4</kbd></span>
                                </div>

                                {{--
                                    A split tender is normal at a counter - part cash,
                                    part UPI - so the payment is a list, not a single
                                    method. One row is enough for the common case, and
                                    its amount follows the bill until somebody types.
                                --}}
                                <div class="till-tenders" data-tender-body></div>

                                <button type="button" class="btn btn-icon till-pay-add" data-tender-add
                                        aria-label="Split the payment" title="Split the payment">
                                    <x-icon name="plus" :size="14" />
                                </button>
                            </div>

                            <template data-tender-template>
                                <div class="pos-tender" data-tender-row>
                                    <label class="sr-only" for="tender-method-@{{index}}">Payment method</label>
                                    <select id="tender-method-@{{index}}" name="payments[@{{index}}][method]"
                                            class="form-control" data-tender-method>
                                        @foreach ($methods as $key => $meta)
                                            <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                        @endforeach
                                    </select>

                                    <label class="sr-only" for="tender-amount-@{{index}}">Amount</label>
                                    <input id="tender-amount-@{{index}}" type="number"
                                           name="payments[@{{index}}][amount]" class="form-control"
                                           min="0" step="0.01" placeholder="0.00" data-tender-amount>

                                    <button type="button" class="btn btn-icon is-danger" data-tender-remove
                                            aria-label="Remove this payment">
                                        <x-icon name="x" :size="13" />
                                    </button>

                                    {{-- Shown only for a cheque, which is the one
                                         method that does not settle on the spot. --}}
                                    <div class="pos-tender-extra" data-tender-extra hidden>
                                        <label class="sr-only" for="tender-ref-@{{index}}">Reference</label>
                                        <input id="tender-ref-@{{index}}" type="text"
                                               name="payments[@{{index}}][transaction_ref]"
                                               class="form-control" placeholder="Cheque / UPI reference"
                                               maxlength="120">
                                    </div>
                                </div>
                            </template>

                            <dl class="till-totals">
                                <div data-pos-change-row hidden>
                                    <dt>Change</dt>
                                    <dd>₹<span data-pos-change>0.00</span></dd>
                                </div>
                                <div data-pos-due-row hidden>
                                    <dt>On account</dt>
                                    <dd>₹<span data-pos-due>0.00</span></dd>
                                </div>
                            </dl>

                            {{-- Warns before the server refuses, so the cashier
                                 is not surprised at the last keystroke. --}}
                            <p class="pos-warning" data-pos-credit-warning hidden></p>
                        </div>

                        {{-- ------------------------------------------- the rest --}}
                        @if ($warehouses->count() === 1)
                            {{-- One place to sell from is not a choice worth a row. --}}
                            <input type="hidden" name="warehouse_id" value="{{ $warehouses->first()->id }}">
                        @else
                            <div class="till-block till-from">
                                <label for="pos-warehouse">Sell from</label>
                                <select id="pos-warehouse" name="warehouse_id" class="form-control" required>
                                    @foreach ($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}">
                                            {{ $warehouse->name }} ({{ $warehouse->code }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @unless ($isCounter)
                            <details class="till-block till-more">
                                <summary>Dates and notes</summary>

                                <div class="till-fields">
                                    <div class="field">
                                        <label for="pos-date">Invoice date</label>
                                        <input id="pos-date" type="datetime-local" name="invoiced_at"
                                               class="form-control" value="{{ now()->format('Y-m-d\TH:i') }}"
                                               max="{{ now()->format('Y-m-d\TH:i') }}">
                                    </div>
                                    <div class="field">
                                        <label for="pos-due">Due date</label>
                                        <input id="pos-due" type="date" name="due_date" class="form-control"
                                               min="{{ today()->toDateString() }}">
                                        <div class="form-hint">Blank uses the customer's credit days.</div>
                                    </div>
                                    <div class="field field-full">
                                        <label for="pos-notes">Notes</label>
                                        <textarea id="pos-notes" name="notes" class="form-control"
                                                  style="min-height:52px" maxlength="2000"></textarea>
                                    </div>
                                </div>
                            </details>
                        @endunless
                    </div>

                    {{--
                        pos-actions pins this to the bottom of a phone screen.
                        The button carries the payable, so the one number the
                        cashier needs is on screen without scrolling.
                    --}}
                    <div class="till-actions pos-actions">
                        <button type="submit" class="btn btn-primary btn-lg pos-complete" data-pos-submit>
                            <x-icon name="user-check" :size="17" />
                            {{ $isCounter ? 'Place the order' : 'Raise the invoice' }} ·
                            ₹<span data-pos-payable>0.00</span>
                            {{-- Ctrl/Cmd+Enter submits from anywhere on the till (pos.js). --}}
                            <span class="kbd-group pos-submit-kbd" data-kbd-hint aria-hidden="true">
                                <kbd class="kbd" data-kbd-mod>Ctrl</kbd><kbd class="kbd">↵</kbd>
                            </span>
                        </button>

                        {{--
                            Hold (§6).
                            Not an invoice: nothing is numbered, no stock moves
                            and no ledger is written. It is a note the till can
                            pick up again - which is why it sits beside the
                            complete button rather than looking like a second
                            way to finish a sale.
                        --}}
                        <div class="till-actions-row">
                            <button type="button" class="btn" data-pos-hold
                                    data-hold-url="{{ route('admin.pos.park') }}">
                                <x-icon name="clock" :size="14" /> Hold
                            </button>

                            <a class="btn btn-ghost" href="{{ route('admin.pos.parked') }}">
                                Held sales
                                @if ($heldCount > 0)
                                    <span class="badge badge-warning">{{ $heldCount }}</span>
                                @endif
                            </a>
                        </div>
                    </div>
                </aside>

                {{--
                    Row template for line-items.js.

                    One compact row per dish, so a long bill fits the panel: the
                    rate sits under the name, where five columns of numbers would
                    not fit. Every data- hook the cart reads is unchanged,
                    so the scanner, the search box and a resumed hold all still
                    build exactly this.
                --}}
                <template data-line-template>
                    <div class="till-line" data-line-row data-product-id="@{{product_id}}" data-batch-id="">
                        <input type="hidden" name="items[@{{index}}][product_id]" value="@{{product_id}}">

                        <div class="till-line-name">
                            <strong>@{{name}}</strong>
                            <small>
                                <span aria-hidden="true">@</span><input type="number" name="items[@{{index}}][unit_price]"
                                       class="till-line-rate" data-line-price value="@{{price}}" min="0" step="0.01"
                                       inputmode="decimal" aria-label="Rate"
                                       @if (! $canDiscount) readonly @endif>
                                <span class="till-line-note">@{{stock_note}}</span>
                            </small>
                        </div>

                        <div class="qty-stepper">
                            <button type="button" data-qty-step="-1" aria-label="Decrease quantity" tabindex="-1">−</button>
                            <input type="number" name="items[@{{index}}][quantity]" class="form-control"
                                   data-line-quantity value="1" min="@{{step}}" step="@{{step}}" required
                                   inputmode="decimal" aria-label="Quantity">
                            <button type="button" data-qty-step="1" aria-label="Increase quantity" tabindex="-1">+</button>
                        </div>

                        <strong class="till-line-amount" data-line-amount>0.00</strong>

                        <button type="button" class="till-line-remove" data-line-remove aria-label="Remove line">
                            <x-icon name="x" :size="13" />
                        </button>
                    </div>
                </template>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    @if ($cameraScanner)
        {{--
            The one third-party dependency the scanner module needs - see
            public/assets/js/scanner.js's header comment for why (no
            cross-browser barcode API exists without one). Loaded only when
            the camera is actually live, so a shop on Scanner Mode "Wireless
            Scanner only" never pays for it.

            Self-host this file under public/assets/vendor/ instead if this
            install must not depend on a CDN in production - see the
            scanner module's setup notes.
        --}}
        <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    @endif

    <script src="{{ asset('assets/js/scanner.js') }}?v={{ filemtime(public_path('assets/js/scanner.js')) }}"></script>
    <script src="{{ asset('assets/js/pos.js') }}?v={{ filemtime(public_path('assets/js/pos.js')) }}"></script>
    <script src="{{ asset('assets/js/pos-menu.js') }}?v={{ filemtime(public_path('assets/js/pos-menu.js')) }}"></script>
    <script src="{{ asset('assets/js/pos-fullscreen.js') }}?v={{ filemtime(public_path('assets/js/pos-fullscreen.js')) }}"></script>
@endpush
