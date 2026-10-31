@extends('admin.layouts.app')

@php
    $isNew = ! $receipt->exists;

    /*
     | Receiving against an order pre-fills the lines with what is still
     | outstanding - the whole point of having ordered. They are rendered as
     | ordinary rows, so the receiver can change any quantity before posting:
     | what arrived is what arrived, not what was asked for.
     */
    $prefill = $items->isNotEmpty() ? $items : $orderLines;
@endphp

@section('title', $isNew ? 'Receive Goods' : 'Edit '.$receipt->reference)

@section('content')
    <x-page-header
        :title="$isNew ? 'Receive Goods' : 'Edit '.$receipt->reference"
        subtitle="Nothing lands on the shelf until this is posted."
        :crumbs="['Purchasing' => null, 'Goods Receipts' => route('admin.receipts.index'), ($isNew ? 'New' : $receipt->reference) => null]"
    />

    <form method="POST"
          action="{{ $isNew ? route('admin.receipts.store') : route('admin.receipts.update', $receipt) }}"
          data-ajax data-redirect-delay="500">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        @if ($order)
            <input type="hidden" name="purchase_order_id" value="{{ $order->id }}">
        @endif

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Consignment</div>
                    <div class="text-xs text-muted">
                        Reference <span class="list-ref">{{ $reference }}</span> · {{ $shop?->name }}
                        @if ($order)
                            · against order <span class="list-ref">{{ $order->reference }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="settings-grid">
                    <div class="field">
                        <label for="grn-supplier">Supplier</label>
                        <select id="grn-supplier" name="supplier_id" class="form-control" required
                                aria-invalid="false">
                            <option value="">Choose a supplier…</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}"
                                    @selected($receipt->supplier_id === $supplier->id)>
                                    {{ $supplier->company ?: $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="grn-warehouse">Into warehouse</label>
                        <select id="grn-warehouse" name="warehouse_id" class="form-control" required
                                aria-invalid="false">
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}"
                                    @selected($receipt->warehouse_id === $warehouse->id)>
                                    {{ $warehouse->name }} ({{ $warehouse->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="grn-received">Received on</label>
                        <input id="grn-received" type="date" name="received_on" class="form-control" required
                               value="{{ $receipt->received_on?->toDateString() ?? today()->toDateString() }}"
                               max="{{ today()->toDateString() }}" aria-invalid="false">
                    </div>

                    <div class="field">
                        <label for="grn-bill">Supplier's bill number</label>
                        <input id="grn-bill" type="text" name="bill_number" class="form-control"
                               value="{{ $receipt->bill_number }}" autocomplete="off" aria-invalid="false"
                               maxlength="60">
                        <div class="form-hint">
                            Refused if this supplier has already been entered with the same number —
                            which is nearly always a duplicate.
                        </div>
                    </div>

                    <div class="field">
                        <label for="grn-bill-date">Bill date</label>
                        <input id="grn-bill-date" type="date" name="bill_date" class="form-control"
                               value="{{ $receipt->bill_date?->toDateString() }}"
                               max="{{ today()->toDateString() }}" aria-invalid="false">
                    </div>

                    <div class="field">
                        <label for="grn-due">Payment due</label>
                        <input id="grn-due" type="date" name="due_date" class="form-control"
                               value="{{ $receipt->due_date?->toDateString() }}" aria-invalid="false">
                        <div class="form-hint">Blank uses the supplier's agreed credit days.</div>
                    </div>

                    <div class="field">
                        <label for="grn-charges">Freight &amp; other charges (₹)</label>
                        <input id="grn-charges" type="number" name="other_charges" class="form-control"
                               value="{{ $receipt->exists ? (float) $receipt->other_charges : 0 }}"
                               min="0" step="0.01" aria-invalid="false">
                        <div class="form-hint">
                            Spread across the lines by value, so the stock is carried at what it
                            really cost to get here.
                        </div>
                    </div>

                    <div class="field">
                        <div class="form-label">Tax basis</div>
                        <label class="check">
                            <input type="hidden" name="is_inter_state" value="0">
                            <input type="checkbox" name="is_inter_state" value="1"
                                   @checked($receipt->is_inter_state)>
                            Inter-state purchase (IGST)
                        </label>
                        <div class="form-hint">Leave off for a supplier in the same state.</div>
                    </div>

                    <div class="field field-full">
                        <label for="grn-notes">Notes</label>
                        <textarea id="grn-notes" name="notes" class="form-control"
                                  style="min-height:56px" aria-invalid="false">{{ $receipt->notes }}</textarea>
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
                    <label for="grn-search" class="sr-only">Find a product</label>
                    <input id="grn-search" type="search" class="form-control" data-line-search
                           placeholder="Scan a barcode, or search by name or SKU…" autocomplete="off">
                    <div class="line-results" data-line-results hidden></div>
                </div>

                <div class="table-wrap" style="margin-top:14px">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="width:150px">Batch &amp; expiry</th>
                                <th style="width:100px;text-align:right">Qty</th>
                                <th style="width:90px;text-align:right">Free</th>
                                <th style="width:110px;text-align:right">Cost</th>
                                <th style="width:80px;text-align:right">Tax %</th>
                                <th style="width:110px;text-align:right">Sell at</th>
                                <th style="width:110px;text-align:right">Amount</th>
                                <th style="width:1%"></th>
                            </tr>
                        </thead>

                        <tbody data-line-body>
                            @foreach ($prefill as $index => $item)
                                @php
                                    // An order line and a receipt line differ
                                    // in a couple of fields; normalise here so
                                    // the row markup stays one thing.
                                    $isOrderLine = $item instanceof App\Models\PurchaseOrderItem;
                                    $quantity = $isOrderLine
                                        ? $item->outstandingQuantity()
                                        : (float) $item->quantity;
                                    $product = $item->product;
                                @endphp
                                <tr data-line-row data-product-id="{{ $item->product_id }}"
                                    data-allow-decimal="{{ $product?->unit?->allow_decimal ? '1' : '0' }}">
                                    <td>
                                        <input type="hidden" name="items[{{ $index }}][product_id]"
                                               value="{{ $item->product_id }}">
                                        <strong>{{ $item->product_name }}</strong>
                                        <span class="text-xs text-muted" style="display:block">
                                            {{ $item->sku }}
                                        </span>
                                    </td>

                                    <td>
                                        <label class="sr-only" for="batch-{{ $index }}">Batch number</label>
                                        <input id="batch-{{ $index }}" type="text"
                                               name="items[{{ $index }}][batch_no]" class="form-control"
                                               value="{{ $isOrderLine ? '' : $item->batch_no }}"
                                               placeholder="Batch" maxlength="80">

                                        <label class="sr-only" for="expiry-{{ $index }}">Expiry date</label>
                                        <input id="expiry-{{ $index }}" type="date"
                                               name="items[{{ $index }}][expiry_date]" class="form-control"
                                               style="margin-top:4px"
                                               value="{{ $isOrderLine ? '' : $item->expiry_date?->toDateString() }}">
                                    </td>

                                    <td>
                                        <label class="sr-only" for="qty-{{ $index }}">Quantity</label>
                                        <input id="qty-{{ $index }}" type="number"
                                               name="items[{{ $index }}][quantity]" class="form-control"
                                               data-line-quantity value="{{ $quantity }}"
                                               min="{{ $product?->unit?->allow_decimal ? '0.001' : '1' }}" step="{{ $product?->unit?->allow_decimal ? '0.001' : '1' }}"
                                               required>
                                    </td>

                                    <td>
                                        <label class="sr-only" for="free-{{ $index }}">Free quantity</label>
                                        <input id="free-{{ $index }}" type="number"
                                               name="items[{{ $index }}][free_quantity]" class="form-control"
                                               value="{{ $isOrderLine ? 0 : (float) $item->free_quantity }}"
                                               min="0" step="0.001">
                                    </td>

                                    <td>
                                        <label class="sr-only" for="cost-{{ $index }}">Unit cost</label>
                                        <input id="cost-{{ $index }}" type="number"
                                               name="items[{{ $index }}][unit_cost]" class="form-control"
                                               data-line-price value="{{ (float) $item->unit_cost }}"
                                               min="0" step="0.01" required>
                                    </td>

                                    <td>
                                        <label class="sr-only" for="tax-{{ $index }}">Tax rate</label>
                                        <input id="tax-{{ $index }}" type="number"
                                               name="items[{{ $index }}][tax_rate]" class="form-control"
                                               value="{{ (float) $item->tax_rate }}" min="0" max="100" step="0.01">
                                    </td>

                                    <td>
                                        <label class="sr-only" for="sell-{{ $index }}">Selling price</label>
                                        <input id="sell-{{ $index }}" type="number"
                                               name="items[{{ $index }}][selling_price]" class="form-control"
                                               value="{{ $isOrderLine ? (float) ($product?->selling_price ?? 0) : (float) $item->selling_price }}"
                                               min="0" step="0.01">
                                    </td>

                                    <td style="text-align:right"><strong data-line-amount>0.00</strong></td>

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

                <div class="empty" data-line-empty @if ($prefill->isNotEmpty()) hidden @endif>
                    <x-icon name="truck" :size="26" />
                    <h3>No lines yet</h3>
                    <p class="text-sm">Scan or search above to add what turned up.</p>
                </div>

                <p class="text-xs text-muted" style="margin-top:10px">
                    The amount column is the goods value before tax and freight. The full bill,
                    including both, is worked out when the receipt is posted.
                </p>
            </div>

            <template data-line-template>
                {{-- @{{token}} is Blade's escape for a literal brace pair. --}}
                <tr data-line-row data-product-id="@{{product_id}}">
                    <td>
                        <input type="hidden" name="items[@{{index}}][product_id]" value="@{{product_id}}">
                        <strong>@{{name}}</strong>
                        <span class="text-xs text-muted" style="display:block">@{{sku}}</span>
                    </td>

                    <td>
                        <input type="text" name="items[@{{index}}][batch_no]" class="form-control"
                               placeholder="Batch" maxlength="80" aria-label="Batch number">
                        <input type="date" name="items[@{{index}}][expiry_date]" class="form-control"
                               style="margin-top:4px" aria-label="Expiry date">
                    </td>

                    <td>
                        <input type="number" name="items[@{{index}}][quantity]" class="form-control"
                               data-line-quantity value="1" min="@{{step}}" step="@{{step}}" required
                               aria-label="Quantity">
                    </td>

                    <td>
                        <input type="number" name="items[@{{index}}][free_quantity]" class="form-control"
                               value="0" min="0" step="0.001" aria-label="Free quantity">
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

                    <td>
                        <input type="number" name="items[@{{index}}][selling_price]" class="form-control"
                               value="@{{price}}" min="0" step="0.01" aria-label="Selling price">
                    </td>

                    <td style="text-align:right"><strong data-line-amount>0.00</strong></td>

                    <td>
                        <button type="button" class="btn btn-icon is-danger" data-line-remove
                                aria-label="Remove line">
                            <x-icon name="x" :size="14" />
                        </button>
                    </td>
                </tr>
            </template>

            <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
                <a class="btn" href="{{ route('admin.receipts.index') }}">Cancel</a>

                <button type="submit" class="btn" name="intent" value="save">Save draft</button>

                @allows('purchasing.receipts.approve')
                    <button type="submit" class="btn btn-primary" name="intent" value="post"
                            onclick="return confirm('Post this receipt? The stock goes on the shelf and the supplier is billed. It cannot be edited afterwards.')">
                        Post &amp; put on the shelf
                    </button>
                @endallows
            </div>
        </div>
    </form>
@endsection
