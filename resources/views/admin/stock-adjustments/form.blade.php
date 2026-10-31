@extends('admin.layouts.app')

@php $isNew = ! $adjustment->exists; @endphp

@section('title', $isNew ? 'New Stock Adjustment' : 'Edit '.$adjustment->reference)

@section('content')
    <x-page-header
        :title="$isNew ? 'New Stock Adjustment' : 'Edit '.$adjustment->reference"
        :subtitle="'Count what is actually on the shelf. Nothing moves until the adjustment is approved.'"
        :crumbs="['Inventory' => null, 'Stock Adjustments' => route('admin.stock-adjustments.index'), ($isNew ? 'New' : $adjustment->reference) => null]"
    />

    {{--
        A full page rather than a modal: line items need room, and the
        product picker's dropdown has nowhere to go inside a dialog.
    --}}
    <form method="POST"
          action="{{ $isNew ? route('admin.stock-adjustments.store') : route('admin.stock-adjustments.update', $adjustment) }}"
          data-ajax data-redirect-delay="500">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Document</div>
                    <div class="text-xs text-muted">
                        Reference <span class="list-ref">{{ $reference }}</span> ·
                        {{ $shop?->name }}
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="settings-grid">
                    <div class="field">
                        <label for="adj-warehouse">Warehouse</label>
                        <select id="adj-warehouse" name="warehouse_id" class="form-control" required
                                aria-invalid="false" data-warehouse-select>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}"
                                    @selected($adjustment->warehouse_id === $warehouse->id)>
                                    {{ $warehouse->name }} ({{ $warehouse->code }})
                                </option>
                            @endforeach
                        </select>
                        <div class="form-hint">
                            The count is against this location. Change it before adding lines — the
                            expected quantities are read per warehouse.
                        </div>
                    </div>

                    <div class="field">
                        <label for="adj-date">Count date</label>
                        <input id="adj-date" type="date" name="adjustment_date" class="form-control" required
                               value="{{ $adjustment->adjustment_date?->toDateString() ?? today()->toDateString() }}"
                               max="{{ today()->toDateString() }}" aria-invalid="false">
                    </div>

                    <div class="field">
                        <label for="adj-reason-code">Reason</label>
                        <select id="adj-reason-code" name="reason_code" class="form-control" required
                                aria-invalid="false">
                            @foreach (App\Models\StockAdjustment::REASONS as $key => $label)
                                <option value="{{ $key }}" @selected($adjustment->reason_code === $key)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field field-full">
                        <label for="adj-reason">Notes</label>
                        <textarea id="adj-reason" name="reason" class="form-control"
                                  style="min-height:62px" aria-invalid="false"
                                  placeholder="What happened, in enough detail that this still makes sense in a year.">{{ $adjustment->reason }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        {{--
            The line editor. Every attribute here is read by
            public/assets/js/line-items.js - see the contract at the top of
            that file.
        --}}
        <div class="card" style="margin-top:16px"
             data-line-items
             data-lookup-url="{{ route('admin.stock-adjustments.lookup') }}">

            <div class="card-header">
                <div>
                    <div class="card-title">Counted lines</div>
                    <div class="text-xs text-muted">
                        <span data-line-count>0</span> line(s) ·
                        counted total <span data-line-total-quantity>0</span>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="line-search field" style="max-width:520px">
                    <label for="adj-search" class="sr-only">Find a product</label>
                    <input id="adj-search" type="search" class="form-control" data-line-search
                           placeholder="Scan a barcode, or search by name or SKU…" autocomplete="off">
                    <div class="line-results" data-line-results hidden></div>
                </div>

                <div class="table-wrap" style="margin-top:14px">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="width:150px">Batch</th>
                                <th style="width:120px;text-align:right">Expected</th>
                                <th style="width:130px;text-align:right">Counted</th>
                                <th style="width:110px;text-align:right">Difference</th>
                                <th>Note</th>
                                <th style="width:1%"></th>
                            </tr>
                        </thead>

                        <tbody data-line-body>
                            @foreach ($items as $index => $item)
                                <tr data-line-row
                                    data-product-id="{{ $item->product_id }}"
                                    data-batch-id="{{ $item->batch_id }}"
                                    data-allow-decimal="{{ $item->product?->unit?->allow_decimal ? '1' : '0' }}">
                                    <td>
                                        <input type="hidden" name="items[{{ $index }}][product_id]"
                                               value="{{ $item->product_id }}">
                                        <strong>{{ $item->product?->name }}</strong>
                                        <span class="text-xs text-muted" style="display:block">
                                            {{ $item->product?->sku }}
                                        </span>
                                    </td>

                                    <td class="text-sm">
                                        <input type="hidden" name="items[{{ $index }}][batch_id]"
                                               value="{{ $item->batch_id }}">
                                        {{ $item->batch?->batch_no ?? '—' }}
                                    </td>

                                    <td style="text-align:right" class="text-sm">
                                        {{ number_format((float) $item->system_quantity, 3) }}
                                    </td>

                                    <td>
                                        <label class="sr-only" for="counted-{{ $index }}">Counted quantity</label>
                                        <input id="counted-{{ $index }}" type="number"
                                               name="items[{{ $index }}][counted_quantity]"
                                               class="form-control" data-line-quantity
                                               value="{{ (float) $item->counted_quantity }}"
                                               min="0" step="{{ $item->product?->unit?->allow_decimal ? '0.001' : '1' }}"
                                               required>
                                    </td>

                                    <td style="text-align:right" class="text-sm">
                                        <strong style="color:var({{ (float) $item->difference < 0 ? '--danger' : '--success' }})">
                                            {{ (float) $item->difference > 0 ? '+' : '' }}{{ number_format((float) $item->difference, 3) }}
                                        </strong>
                                    </td>

                                    <td>
                                        <label class="sr-only" for="note-{{ $index }}">Line note</label>
                                        <input id="note-{{ $index }}" type="text"
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
                    <p class="text-sm">Scan or search above to add the products you have counted.</p>
                </div>
            </div>

            {{--
                Row template for line-items.js. Kept in a <template> so the
                browser does not try to render or submit it.
            --}}
            <template data-line-template>
                {{-- @{{token}} is Blade's escape for a literal brace pair. --}}
                <tr data-line-row data-product-id="@{{product_id}}" data-batch-id="">
                    <td>
                        <input type="hidden" name="items[@{{index}}][product_id]" value="@{{product_id}}">
                        <strong>@{{name}}</strong>
                        <span class="text-xs text-muted" style="display:block">@{{sku}}</span>
                    </td>

                    <td class="text-sm">
                        <input type="hidden" name="items[@{{index}}][batch_id]" value="">
                        —
                    </td>

                    <td style="text-align:right" class="text-sm" data-line-expected>@{{on_hand}}</td>

                    <td>
                        <input type="number" name="items[@{{index}}][counted_quantity]"
                               class="form-control" data-line-quantity
                               value="@{{on_hand}}" min="0" step="@{{step}}" required
                               aria-label="Counted quantity">
                    </td>

                    <td style="text-align:right" class="text-sm" data-line-difference>—</td>

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
                <a class="btn" href="{{ route('admin.stock-adjustments.index') }}">Cancel</a>

                <button type="submit" class="btn" name="intent" value="save">Save draft</button>

                <button type="submit" class="btn btn-primary" name="intent" value="submit">
                    Submit for approval
                </button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/warehouse-lines.js') }}?v={{ filemtime(public_path('assets/js/warehouse-lines.js')) }}"></script>
@endpush
