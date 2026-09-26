{{--
    Read-only detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from an edit.
--}}

@php
    use App\Models\CollectionMedia;

    $stored = $collection->mediaBySlot();
    $groups = collect($slots)->groupBy('device');
@endphp

<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
    <span class="badge {{ $collection->is_active ? 'badge-success' : 'badge-danger' }}">
        <span class="badge-dot"></span> {{ $collection->is_active ? 'Active' : 'Inactive' }}
    </span>

    @if ($collection->is_featured)
        <span class="badge badge-brand">Featured</span>
    @endif

    <span class="badge badge-info">Order {{ $collection->sort_order }}</span>
    <span class="badge">{{ $collection->mediaSummary() }} media</span>
</div>

<div class="sec-name" style="font-size:17px">{{ $collection->name }}</div>

@if ($collection->short_description)
    <p class="text-sm text-muted" style="margin:8px 0 0">{{ $collection->short_description }}</p>
@endif

<dl class="sec-facts" style="margin-top:16px">
    <div><dt>Slug</dt><dd class="list-ref" style="font-size:13px">{{ $collection->slug }}</dd></div>
    <div><dt>Created</dt><dd>{{ $collection->created_at?->format('d M Y') ?? '—' }}</dd></div>
    <div><dt>Updated</dt><dd>{{ $collection->updated_at?->format('d M Y') ?? '—' }}</dd></div>
</dl>

@if ($collection->description)
    <div class="form-section">
        <div class="form-section-title">Description</div>
        {{-- nl2br on escaped output: paragraph breaks survive, markup does not. --}}
        <p class="text-sm text-muted" style="margin:0">{!! nl2br(e($collection->description)) !!}</p>
    </div>
@endif

@foreach ($groups as $device => $cells)
    <div class="form-section">
        <div class="form-section-title">{{ CollectionMedia::DEVICES[$device] }} media</div>

        <div class="media-grid">
            @foreach ($cells as $slot)
                @php $piece = $stored[$device.'.'.$slot['position']] ?? null; @endphp

                <div class="media-field">
                    <div class="media-field-head">
                        <span class="form-label" style="margin:0">
                            {{ CollectionMedia::POSITIONS[$slot['position']] }}
                        </span>
                        @if ($piece)
                            <span class="media-formats">{{ strtoupper($piece->type) }}</span>
                        @endif
                    </div>

                    <div class="media-dropzone {{ $piece?->url() ? 'has-media' : '' }}" style="cursor:default">
                        <span class="media-preview">
                            @if ($piece?->url() && $piece->isVideo())
                                <video src="{{ $piece->url() }}" controls preload="metadata" playsinline></video>
                            @elseif ($piece?->url())
                                <img src="{{ $piece->url() }}" alt="{{ $piece->label() }}">
                            @endif
                        </span>

                        @unless ($piece?->url())
                            <span class="media-empty">
                                <x-icon name="inbox" :size="20" />
                                <span class="text-xs text-muted">Not set</span>
                            </span>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endforeach

@if ($collection->meta_title || $collection->meta_description)
    <div class="form-section">
        <div class="form-section-title">SEO</div>
        <dl class="sec-facts">
            <div><dt>Meta title</dt><dd style="font-size:13px">{{ $collection->meta_title ?: '—' }}</dd></div>
        </dl>
        @if ($collection->meta_description)
            <p class="text-sm text-muted" style="margin:10px 0 0">{{ $collection->meta_description }}</p>
        @endif
    </div>
@endif

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('content.collections.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.collections.edit', $collection) }}"
           data-modal="{{ route('admin.collections.edit', $collection) }}"
           data-modal-title="Edit Collection"
           data-modal-sub="{{ $collection->name }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
