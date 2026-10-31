@extends('admin.layouts.app')

@php $isNew = ! $transfer->exists; @endphp

@section('title', $isNew ? 'New Stock Transfer' : 'Edit '.$transfer->reference)

@section('content')
    <x-page-header
        :title="$isNew ? 'New Stock Transfer' : 'Edit '.$transfer->reference"
        subtitle="Nothing leaves the shelf until the transfer is approved and dispatched."
        :crumbs="['Inventory' => null, 'Stock Transfers' => route('admin.stock-transfers.index'), ($isNew ? 'New' : $transfer->reference) => null]"
    />

    <form method="POST"
          action="{{ $isNew ? route('admin.stock-transfers.store') : route('admin.stock-transfers.update', $transfer) }}"
          data-ajax data-redirect-delay="500">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Consignment</div>
                    <div class="text-xs text-muted">
                        Reference <span class="list-ref">{{ $reference }}</span> · from {{ $shop?->name }}
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="settings-grid">
                    <div class="field">
                        <label for="trf-from">From warehouse</label>
                        <select id="trf-from" name="from_warehouse_id" class="form-control" required
                                aria-invalid="false" data-warehouse-select>
                            @foreach ($fromWarehouses as $warehouse)
                                <option value="{{ $warehouse->id }}"
                                    @selected($transfer->from_warehouse_id === $warehouse->id)>
                                    {{ $warehouse->name }} ({{ $warehouse->code }})
                                </option>
                            @endforeach
                        </select>
                        <div class="form-hint">
                            Availability below is read from here. Change it before adding lines.
                        </div>
                    </div>

                    <div class="field">
                        <label for="trf-to">To warehouse</label>
                        <select id="trf-to" name="to_warehouse_id" class="form-control" required
                                aria-invalid="false">
                            @foreach ($destinations->groupBy(fn ($w) => $w->shop?->name ?? 'Other') as $shopName => $group)
                                <optgroup label="{{ $shopName }}">
                                    @foreach ($group as $warehouse)
                                        <option value="{{ $warehouse->id }}"
                                            @selected($transfer->to_warehouse_id === $warehouse->id)>
                                            {{ $warehouse->name }} ({{ $warehouse->code }})
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <div class="form-hint">
                            Only shops you have access to. Sending stock somewhere you cannot see
                            would create quantities nobody could account for.
                        </div>
                    </div>

                    <div class="field">
                        <label for="trf-date">Transfer date</label>
                        <input id="trf-date" type="date" name="transfer_date" class="form-control" required
                               value="{{ $transfer->transfer_date?->toDateString() ?? today()->toDateString() }}"
                               aria-invalid="false">
                    </div>

                    <div class="field field-full">
                        <label for="trf-note">Note</label>
                        <textarea id="trf-note" name="note" class="form-control"
                                  style="min-height:60px" aria-invalid="false"
                                  placeholder="Vehicle, driver, why the stock is moving.">{{ $transfer->note }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:16px"
             data-line-items
             data-lookup-url="{{ route('admin.stock-transfers.lookup') }}">

            <div class="card-header">
                <div>
                    <div class="card-title">Lines</div>
                    <div class="text-xs text-muted">
                        <span data-line-count>0</span> line(s) ·
                        total <span data-line-total-quantity>0</span>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="line-search field" style="max-width:520px">
                    <label for="trf-search" class="sr-only">Find a product</label>
                    <input id="trf-search" type="search" class="form-control" data-line-search
                           placeholder="Scan a barcode, or search by name or SKU…" autocomplete="off">
                    <div class="line-results" data-line-results hidden></div>
                </div>

                <div class="table-wrap" style="margin-top:14px">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="width:150px">Batch</th>
                                <th style="width:130px;text-align:right">Available</th>
                                <th style="width:140px;text-align:right">Send</th>
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

                                    <td style="text-align:right" class="text-sm" data-line-expected>—</td>

                                    <td>
                                        <label class="sr-only" for="qty-{{ $index }}">Quantity to send</label>
                                        <input id="qty-{{ $index }}" type="number"
                                               name="items[{{ $index }}][quantity]"
                                               class="form-control" data-line-quantity
                                               value="{{ (float) $item->quantity }}"
                                               min="{{ $item->product?->unit?->allow_decimal ? '0.001' : '1' }}" step="{{ $item->product?->unit?->allow_decimal ? '0.001' : '1' }}"
                                               required>
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
                    <p class="text-sm">Scan or search above to add what is going on the van.</p>
                </div>
            </div>

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

                    <td style="text-align:right" class="text-sm" data-line-expected>@{{available}}</td>

                    <td>
                        <input type="number" name="items[@{{index}}][quantity]"
                               class="form-control" data-line-quantity
                               value="1" min="@{{step}}" step="@{{step}}" required
                               aria-label="Quantity to send">
                    </td>

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
                <a class="btn" href="{{ route('admin.stock-transfers.index') }}">Cancel</a>

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
