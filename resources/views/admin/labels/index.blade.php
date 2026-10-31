@extends('admin.layouts.app')

@section('title', 'Barcode Labels')

@section('content')
    <x-page-header
        title="Barcode Labels"
        subtitle="Print price/barcode stickers for the shelf"
        :crumbs="['POS' => null, 'Barcode Labels' => null]"
    />

    {{--
        Posts straight to a new tab: nothing here is a document to save, so
        there is no data-ajax round trip - the response IS the print sheet.
    --}}
    <form method="POST" action="{{ route('admin.labels.print') }}" target="_blank">
        @csrf

        <div class="card">
            <div class="card-header">
                <div class="card-title">Label options</div>
            </div>

            <div class="card-body">
                <div class="settings-grid">
                    <div class="field">
                        <label for="label-size">Label size</label>
                        <select id="label-size" name="size" class="form-control" required aria-invalid="false">
                            @foreach ($sizes as $key => $size)
                                <option value="{{ $key }}" @selected($key === 'medium')>{{ $size['label'] }}</option>
                            @endforeach
                        </select>
                        <div class="form-hint">Matches common sticker-roll and A4 sheet sizes.</div>
                    </div>

                    <div class="field field-full">
                        <div class="form-label">Fields on the label</div>
                        <div style="display:flex;flex-wrap:wrap;gap:16px">
                            <label class="check">
                                <input type="checkbox" name="show_name" value="1" checked>
                                Product name
                            </label>
                            <label class="check">
                                <input type="checkbox" name="show_price" value="1" checked>
                                Selling price
                            </label>
                            <label class="check">
                                <input type="checkbox" name="show_mrp" value="1">
                                MRP
                            </label>
                            <label class="check">
                                <input type="checkbox" name="show_shop" value="1">
                                Shop name
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{--
            The line editor. Every attribute here is read by
            public/assets/js/line-items.js - see the contract at the top of
            that file. There is no batch/stock column: a label sheet is not a
            stock document, just a print run over the catalogue.
        --}}
        <div class="card" style="margin-top:16px"
             data-line-items
             data-lookup-url="{{ route('admin.products.lookup') }}">

            <div class="card-header">
                <div>
                    <div class="card-title">Products</div>
                    <div class="text-xs text-muted">
                        <span data-line-count>0</span> product(s) ·
                        <span data-line-total-quantity>0</span> label(s) total
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="line-search field" style="max-width:520px">
                    <label for="label-search" class="sr-only">Find a product</label>
                    <input id="label-search" type="search" class="form-control" data-line-search
                           placeholder="Scan a barcode, or search by name or SKU…" autocomplete="off">
                    <div class="line-results" data-line-results hidden></div>
                </div>

                <div class="table-wrap" style="margin-top:14px">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="width:120px;text-align:right">Price</th>
                                <th style="width:140px;text-align:right">Labels to print</th>
                                <th style="width:1%"></th>
                            </tr>
                        </thead>
                        <tbody data-line-body></tbody>
                    </table>
                </div>

                <div class="empty" data-line-empty>
                    <x-icon name="tag" :size="26" />
                    <h3>No products added</h3>
                    <p class="text-sm">Scan or search above to add products to this print run.</p>
                </div>
            </div>

            <template data-line-template>
                {{-- @{{token}} is Blade's escape for a literal brace pair. --}}
                <tr data-line-row data-product-id="@{{product_id}}">
                    <td>
                        <input type="hidden" name="items[@{{index}}][product_id]" value="@{{product_id}}">
                        <strong>@{{name}}</strong>
                        <span class="text-xs text-muted" style="display:block">@{{sku}}</span>
                    </td>

                    <td style="text-align:right" class="text-sm">₹@{{price}}</td>

                    <td>
                        <input type="number" name="items[@{{index}}][quantity]"
                               class="form-control" data-line-quantity
                               value="1" min="1" max="500" step="1" required
                               aria-label="Labels to print">
                    </td>

                    <td>
                        <button type="button" class="btn btn-icon is-danger" data-line-remove
                                aria-label="Remove line">
                            <x-icon name="x" :size="14" />
                        </button>
                    </td>
                </tr>
            </template>

            <div class="card-footer" style="display:flex;justify-content:flex-end">
                <button type="submit" class="btn btn-primary">
                    <x-icon name="file" :size="15" /> Print labels
                </button>
            </div>
        </div>
    </form>
@endsection
