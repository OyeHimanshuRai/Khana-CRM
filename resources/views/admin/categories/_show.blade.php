{{--
    Read-only detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from an edit.
--}}

@php $image = $category->imageUrl(); @endphp

<div class="cat-view">
    <div class="cat-view-image">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $category->name }}">
        @else
            <span class="text-xs text-muted">No image</span>
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $category->name }}</div>

        <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
            <span class="badge {{ $category->is_active ? 'badge-success' : 'badge-danger' }}">
                <span class="badge-dot"></span> {{ $category->is_active ? 'Active' : 'Inactive' }}
            </span>
            <span class="badge badge-info">Order {{ $category->sort_order }}</span>
        </div>

        <dl class="sec-facts">
            <div><dt>Slug</dt><dd class="list-ref" style="font-size:13px">{{ $category->slug }}</dd></div>
            <div><dt>Created</dt><dd>{{ $category->created_at?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt>Updated</dt><dd>{{ $category->updated_at?->format('d M Y') ?? '—' }}</dd></div>
        </dl>

        @if ($category->description)
            <div style="margin-top:16px">
                <div class="form-label">Description</div>
                <p class="text-sm text-muted">{{ $category->description }}</p>
            </div>
        @endif
    </div>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('inventory.categories.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.categories.edit', $category) }}"
           data-modal="{{ route('admin.categories.edit', $category) }}"
           data-modal-title="Edit Category"
           data-modal-sub="{{ $category->name }}">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
