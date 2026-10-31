{{-- Read-only detail, rendered straight into the modal body. --}}

@php $logo = $brand->logoUrl(); @endphp

<div class="cat-view">
    <div class="cat-view-image">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $brand->name }}">
        @else
            <span class="text-xs text-muted">No logo</span>
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $brand->name }}</div>

        <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
            <span class="badge {{ $brand->is_active ? 'badge-success' : 'badge-danger' }}">
                <span class="badge-dot"></span> {{ $brand->is_active ? 'Active' : 'Inactive' }}
            </span>
            <span class="badge badge-info">{{ number_format($brand->products_count) }} products</span>
            <span class="badge">Order {{ $brand->sort_order }}</span>
        </div>

        <dl class="sec-facts">
            <div><dt>Slug</dt><dd class="list-ref" style="font-size:13px">{{ $brand->slug }}</dd></div>
            <div><dt>Manufacturer</dt><dd>{{ $brand->manufacturer ?: '—' }}</dd></div>
            <div>
                <dt>Website</dt>
                <dd>
                    @if ($brand->website)
                        <a href="{{ $brand->website }}" target="_blank" rel="noopener noreferrer">
                            {{ Str::limit(preg_replace('#^https?://#', '', $brand->website), 32) }}
                        </a>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div><dt>Created</dt><dd>{{ $brand->created_at?->format('d M Y') ?? '—' }}</dd></div>
        </dl>

        @if ($brand->description)
            <div style="margin-top:16px">
                <div class="form-label">Description</div>
                <p class="text-sm text-muted">{{ $brand->description }}</p>
            </div>
        @endif
    </div>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('inventory.brands.edit')
        <a class="btn btn-primary" href="{{ route('admin.brands.edit', $brand) }}"
           data-modal="{{ route('admin.brands.edit', $brand) }}"
           data-modal-title="Edit Brand"
           data-modal-sub="{{ $brand->name }}">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
