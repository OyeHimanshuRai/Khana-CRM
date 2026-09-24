@extends('admin.layouts.app')

@section('title', 'Products')

@section('content')
    <x-page-header
        title="Products"
        :subtitle="$stats['total'].' product'.($stats['total'] === 1 ? '' : 's').' in the catalogue'"
        :crumbs="['Inventory' => null, 'Products' => null]"
    >
        <x-slot:actions>
            <div class="view-toggle" role="group" aria-label="Layout" data-view-toggle data-target="[data-ajax-list-content]">
                <button type="button" class="btn btn-icon is-active" data-view-option="list" aria-label="List view" aria-pressed="true" title="List view">
                    <x-icon name="list" :size="16" />
                </button>
                <button type="button" class="btn btn-icon" data-view-option="grid" aria-label="Grid view" aria-pressed="false" title="Grid view">
                    <x-icon name="grid" :size="16" />
                </button>
            </div>

            @allows('inventory.products.export')
                <a class="btn btn-sm" href="{{ route('admin.products.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('inventory.products.import')
                <a class="btn btn-sm" href="{{ route('admin.products.import-form') }}"
                   data-modal="{{ route('admin.products.import-form') }}"
                   data-modal-title="Import a menu"
                   data-modal-sub="One bad row stops the whole file">
                    <x-icon name="inbox" :size="15" /> Import
                </a>
            @endallows

            @allows('inventory.products.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.products.create') }}"
                   data-modal="{{ route('admin.products.create') }}"
                   data-modal-title="Add Product"
                   data-modal-sub="Create a catalogue entry"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Product
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Catalogue</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="globe" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">In Store</div>
                <div class="stat-value">{{ number_format($stats['published']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Stock Value</div>
                <div class="stat-value">₹{{ number_format($stats['stock_value'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.products.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="prod-search" class="sr-only">Search products</label>
                <input id="prod-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, SKU, barcode, HSN or brand…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-category" class="sr-only">Category</label>
                <select id="f-category" name="category" data-ajax-filter>
                    <option value="">Any category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($categoryId === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>

                <label for="f-brand" class="sr-only">Brand</label>
                <select id="f-brand" name="brand" data-ajax-filter>
                    <option value="">Any brand</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected($brandId === $brand->id)>
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>

                <label for="f-stock" class="sr-only">Stock</label>
                <select id="f-stock" name="stock" data-ajax-filter>
                    <option value="">Any stock</option>
                    <option value="in" @selected($stock === 'in')>In stock</option>
                    <option value="out" @selected($stock === 'out')>Out of stock</option>
                    <option value="low" @selected($stock === 'low')>At or below reorder</option>
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                    <option value="published" @selected($status === 'published')>In online store</option>
                    <option value="unpublished" @selected($status === 'unpublished')>Not in store</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="name_asc" @selected($sort === 'name_asc')>Name A–Z</option>
                    <option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option>
                    <option value="sku" @selected($sort === 'sku')>SKU</option>
                    <option value="price_asc" @selected($sort === 'price_asc')>Price low to high</option>
                    <option value="price_desc" @selected($sort === 'price_desc')>Price high to low</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.products.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content data-view="list">
            @include('admin.products._list')
        </div>
    </div>
@endsection

@push('styles')
    <style>
        /* View toggle (List / Grid), scoped to the products listing header. */
        .view-toggle { display: inline-flex; gap: 6px; }
        .view-toggle .btn-icon.is-active { background: var(--brand); color: var(--on-brand); border-color: var(--brand); }

        /* Grid view is hidden until the toggle activates it; the table stays
           the default so a no-JS visit keeps today's behaviour untouched. */
        [data-ajax-list-content] .product-grid { display: none; }
        [data-ajax-list-content][data-view="grid"] .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 18px;
            padding: 20px;
        }
        [data-ajax-list-content][data-view="grid"] .table-wrap { display: none; }

        .product-card {
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-width: 0;
            padding: 16px;
            background: var(--panel);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }

        .product-card-top { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
        .product-card-thumb { width: 52px; height: 52px; font-size: 15px; }
        .product-card-heading { min-width: 0; flex: 1; }

        .product-card-title {
            display: block;
            font-size: 13.5px;
            font-weight: 650;
            color: var(--heading);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .product-card-title a { color: inherit; }
        .product-card-title a:hover { color: var(--brand); }

        .product-card-sub {
            display: block;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .product-card-facts {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin: 0;
            padding: 12px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .product-card-facts > div { min-width: 0; }
        .product-card-facts dt {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .03em;
            font-weight: 650;
            color: var(--muted);
            margin-bottom: 3px;
        }
        .product-card-facts dd {
            margin: 0;
            font-size: 12.5px;
            color: var(--text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .product-card-toggles { display: flex; flex-wrap: wrap; gap: 8px; }
        .product-card-toggles form { margin: 0; }

        .product-card-footer { margin-top: auto; padding-top: 4px; }

        @media (max-width: 991.98px) {
            [data-ajax-list-content][data-view="grid"] .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 14px;
                padding: 16px;
            }
        }

        @media (max-width: 767.98px) {
            [data-ajax-list-content][data-view="grid"] .product-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
                padding: 14px;
            }
            .product-card-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 480px) {
            [data-ajax-list-content][data-view="grid"] .product-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (function () {
            var STORAGE_KEY = 'erp.products.view';
            var toggle = document.querySelector('[data-view-toggle]');
            if (!toggle) { return; }

            var content = document.querySelector(toggle.dataset.target);
            if (!content) { return; }

            var buttons = toggle.querySelectorAll('[data-view-option]');

            function apply(view) {
                content.setAttribute('data-view', view);
                buttons.forEach(function (btn) {
                    var active = btn.getAttribute('data-view-option') === view;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-pressed', String(active));
                });
            }

            var stored = null;
            try { stored = window.localStorage.getItem(STORAGE_KEY); } catch (e) {}
            apply(stored === 'grid' ? 'grid' : 'list');

            toggle.addEventListener('click', function (event) {
                var button = event.target.closest('[data-view-option]');
                if (!button) { return; }

                var view = button.getAttribute('data-view-option');
                apply(view);

                try { window.localStorage.setItem(STORAGE_KEY, view); } catch (e) {}
            });
        })();
    </script>
@endpush
