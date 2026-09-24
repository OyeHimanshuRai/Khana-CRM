@extends('admin.layouts.app')

@php $isNew = ! $order->exists; @endphp

@section('title', $isNew ? 'New Purchase Order' : 'Edit '.$order->reference)

@section('content')
    <x-page-header
        :title="$isNew ? 'New Purchase Order' : 'Edit '.$order->reference"
        subtitle="What to ask the supplier for. Nothing moves until the goods actually arrive."
        :crumbs="['Purchasing' => null, 'Purchase Orders' => route('admin.purchase-orders.index'), ($isNew ? 'New' : $order->reference) => null]"
    />

    <form method="POST"
          action="{{ $isNew ? route('admin.purchase-orders.store') : route('admin.purchase-orders.update', $order) }}"
          data-ajax data-redirect-delay="500">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Order</div>
                    <div class="text-xs text-muted">
                        Reference <span class="list-ref">{{ $reference }}</span> · {{ $shop?->name }}
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="settings-grid">
                    <div class="field">
                        <label for="po-supplier">Supplier</label>
                        <select id="po-supplier" name="supplier_id" class="form-control" required
                                aria-invalid="false">
                            <option value="">Choose a supplier…</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}"
                                    @selected($order->supplier_id === $supplier->id)>
                                    {{ $supplier->company ?: $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="po-warehouse">Deliver to</label>
                        <select id="po-warehouse" name="warehouse_id" class="form-control"
                                aria-invalid="false">
                            <option value="">Decide on arrival</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}"
                                    @selected($order->warehouse_id === $warehouse->id)>
                                    {{ $warehouse->name }} ({{ $warehouse->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="po-ordered">Ordered on</label>
                        <input id="po-ordered" type="date" name="ordered_on" class="form-control" required
                               value="{{ $order->ordered_on?->toDateString() ?? today()->toDateString() }}"
                               aria-invalid="false">
                    </div>

                    <div class="field">
                        <label for="po-expected">Expected on</label>
                        <input id="po-expected" type="date" name="expected_on" class="form-control"
                               value="{{ $order->expected_on?->toDateString() }}" aria-invalid="false">
                        <div class="form-hint">An order past this date is flagged as late in the list.</div>
                    </div>

                    <div class="field field-full">
                        <label for="po-notes">Notes</label>
                        <textarea id="po-notes" name="notes" class="form-control"
                                  style="min-height:56px" aria-invalid="false">{{ $order->notes }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:16px"
             data-line-items
             data-lookup-url="{{ route('admin.products.lookup') }}">

            <div class="card-header">
                <div>
                    <div class="card-title">Lines</div>
                    <div class="text-xs text-muted">
                        <span data-line-count>0</span> line(s) ·
                        goods value ₹<span data-line-total-value>0.00</span>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="line-search field" style="max-width:520px">
                    <label for="po-search" class="sr-only">Find a product</label>
                    <input id="po-search" type="search" class="form-control" data-line-search
                           placeholder="Scan a barcode, or search by name or SKU…" autocomplete="off">
                    <div class="line-results" data-line-results hidden></div>
                </div>

                <div class="table-wrap" style="margin-top:14px">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="width:120px;text-align:right">Quantity</th>
                                <th style="width:130px;text-align:right">Unit cost</th>
                                <th style="width:90px;text-align:right">Tax %</th>
                                <th style="width:120px;text-align:right">Value</th>
                                <th>Note</th>
                                <th style="width:1%"></th>
                            </tr>
                        </thead>

                        <tbody data-line-body>
                            @foreach ($items as $index => $item)
                                <tr data-line-row data-product-id="{{ $item->product_id }}"
                                    data-allow-decimal="{{ $item->product?->unit?->allow_decimal ? '1' : '0' }}">
                                    <td>
                                        <input type="hidden" name="items[{{ $index }}][product_id]"
                                               value="{{ $item->product_id }}">
                                        <strong>{{ $item->product_name }}</strong>
                                        <span class="text-xs text-muted" style="display:block">
                                            {{ $item->sku }}
                                        </span>
                                    </td>

                                    <td>
                                        <label class="sr-only" for="po-qty-{{ $index }}">Quantity</label>
                                        <input id="po-qty-{{ $index }}" type="number"
                                               name="items[{{ $index }}][quantity]" class="form-control"
                                               data-line-quantity value="{{ (float) $item->quantity }}"
                                               min="{{ $item->product?->unit?->allow_decimal ? '0.001' : '1' }}" step="{{ $item->product?->unit?->allow_decimal ? '0.001' : '1' }}"
                                               required>
                                    </td>

                                    <td>
                                        <label class="sr-only" for="po-cost-{{ $index }}">Unit cost</label>
                                        <input id="po-cost-{{ $index }}" type="number"
                                               name="items[{{ $index }}][unit_cost]" class="form-control"
                                               data-line-price value="{{ (float) $item->unit_cost }}"
                                               min="0" step="0.01" required>
                                    </td>

                                    <td>
                                        <label class="sr-only" for="po-tax-{{ $index }}">Tax rate</label>
                                        <input id="po-tax-{{ $index }}" type="number"
                                               name="items[{{ $index }}][tax_rate]" class="form-control"
                                               value="{{ (float) $item->tax_rate }}" min="0" max="100" step="0.01">
                                    </td>

                                    <td style="text-align:right"><strong data-line-amount>0.00</strong></td>

                                    <td>
                                        <label class="sr-only" for="po-note-{{ $index }}">Line note</label>
                                        <input id="po-note-{{ $index }}" type="text"
                                               name="items[{{ $index }}][note]" class="form-control"
                                               value="{{ $item->note }}" maxlength="250">
                                    </td>

                                    <td>
                                        <button type="button" class="btn btn-icon is-danger" data-line-remove
                                                aria-label="Remove line">
                                            <x-icon name="x" :size="14" />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="empty" data-line-empty @if ($items->isNotEmpty()) hidden @endif>
                    <x-icon name="inbox" :size="26" />
                    <h3>No lines yet</h3>
                    <p class="text-sm">Search above to add what you want to order.</p>
                </div>
            </div>

            <template data-line-template>
                {{-- @{{token}} is Blade's escape for a literal brace pair. --}}
                <tr data-line-row data-product-id="@{{product_id}}">
                    <td>
                        <input type="hidden" name="items[@{{index}}][product_id]" value="@{{product_id}}">
                        <strong>@{{name}}</strong>
                        <span class="text-xs text-muted" style="display:block">
                            @{{sku}} · @{{on_hand}} @{{unit}} in stock
                        </span>
                    </td>

                    <td>
                        <input type="number" name="items[@{{index}}][quantity]" class="form-control"
                               data-line-quantity value="1" min="@{{step}}" step="@{{step}}" required
                               aria-label="Quantity">
                    </td>

                    <td>
                        <input type="number" name="items[@{{index}}][unit_cost]" class="form-control"
                               data-line-price value="@{{cost}}" min="0" step="0.01" required
                               aria-label="Unit cost">
                    </td>

                    <td>
                        <input type="number" name="items[@{{index}}][tax_rate]" class="form-control"
                               value="0" min="0" max="100" step="0.01" aria-label="Tax rate">
                    </td>

                    <td style="text-align:right"><strong data-line-amount>0.00</strong></td>

                    <td>
                        <input type="text" name="items[@{{index}}][note]" class="form-control"
                               maxlength="250" aria-label="Line note">
                    </td>

                    <td>
                        <button type="button" class="btn btn-icon is-danger" data-line-remove
                                aria-label="Remove line">
                            <x-icon name="x" :size="14" />
                        </button>
                    </td>
                </tr>
            </template>

            <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
                <a class="btn" href="{{ route('admin.purchase-orders.index') }}">Cancel</a>
                <button type="submit" class="btn" name="intent" value="save">Save draft</button>
                <button type="submit" class="btn btn-primary" name="intent" value="submit">
                    Submit for approval
                </button>
            </div>
        </div>
    </form>
@endsection

{{--
    No extra script here. An order is not warehouse-scoped - it is a request
    to a supplier, not a count of a shelf - so line-items.js on its own is
    the whole of what this form needs.
--}}
