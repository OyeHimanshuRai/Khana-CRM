{{--
    Read-only detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from an edit.
--}}

@php $image = $service->imageUrl(); @endphp

<div class="cat-view">
    <div class="cat-view-image">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $service->name }}">
        @else
            <x-icon :name="$service->iconName()" :size="44" class="text-muted" />
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $service->name }}</div>

        @if ($service->short_description)
            <p class="text-sm text-muted" style="margin:6px 0 0">{{ $service->short_description }}</p>
        @endif

        <div style="display:flex;flex-wrap:wrap;gap:7px;margin:12px 0 14px">
            <span class="badge {{ $service->is_active ? 'badge-success' : 'badge-danger' }}">
                <span class="badge-dot"></span> {{ $service->is_active ? 'Active' : 'Inactive' }}
            </span>

            @if ($service->formattedPrice())
                <span class="badge badge-brand">{{ $service->formattedPrice() }}</span>
            @endif

            <span class="badge badge-info">Order {{ $service->sort_order }}</span>
        </div>

        <dl class="sec-facts">
            <div><dt>Slug</dt><dd class="list-ref" style="font-size:13px">{{ $service->slug }}</dd></div>
            <div>
                <dt>Icon</dt>
                <dd style="display:flex;align-items:center;gap:7px;font-size:13px">
                    <x-icon :name="$service->iconName()" :size="16" />
                    {{ $service->icon ? Str::headline($service->icon) : 'None' }}
                </dd>
            </div>
            <div><dt>Created</dt><dd>{{ $service->created_at?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt>Updated</dt><dd>{{ $service->updated_at?->format('d M Y') ?? '—' }}</dd></div>
        </dl>
    </div>
</div>

@if ($service->full_description)
    <div style="margin-top:20px">
        <div class="form-label">Full description</div>
        {{-- nl2br on escaped output: paragraph breaks survive, markup does not. --}}
        <p class="text-sm text-muted">{!! nl2br(e($service->full_description)) !!}</p>
    </div>
@endif

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('content.services.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.services.edit', $service) }}"
           data-modal="{{ route('admin.services.edit', $service) }}"
           data-modal-title="Edit Service"
           data-modal-sub="{{ $service->name }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
