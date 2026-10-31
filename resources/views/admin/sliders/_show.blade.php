{{--
    Read-only detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from an edit.
--}}

@php
    $media = $slider->mediaUrls();
    $groups = collect(\App\Models\Slider::MEDIA)->groupBy('group', preserveKeys: true);
@endphp

<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
    <span class="badge {{ $slider->is_active ? 'badge-success' : 'badge-danger' }}">
        <span class="badge-dot"></span> {{ $slider->is_active ? 'Active' : 'Inactive' }}
    </span>
    <span class="badge badge-info">{{ $slider->layoutLabel() }}</span>
    <span class="badge">Item {{ $slider->item_no }}</span>
</div>

<div class="sec-name">{{ $slider->title }}</div>

@if ($slider->description)
    <p class="text-sm text-muted" style="margin:8px 0 0">{{ $slider->description }}</p>
@endif

@if ($slider->redirect_url)
    <p class="text-sm" style="margin:10px 0 0">
        <span class="form-label" style="display:inline;margin:0">Links to:</span>
        {{-- rel on an external link the admin did not necessarily vet. --}}
        <a href="{{ $slider->redirect_url }}" target="_blank" rel="noopener noreferrer"
           style="color:var(--brand)">{{ $slider->redirect_url }}</a>
    </p>
@endif

@foreach ($groups as $groupName => $slots)
    <div class="form-section">
        <div class="form-section-title">{{ $groupName }}</div>

        <div class="media-grid">
            @foreach ($slots as $column => $slot)
                @php $url = $media[$column] ?? null; @endphp
                <div class="media-field">
                    <div class="media-field-head">
                        <span class="form-label" style="margin:0">{{ $slot['label'] }}</span>
                        <span class="media-formats">{{ $slot['formats'] }}</span>
                    </div>

                    <div class="media-dropzone {{ $url ? 'has-media' : '' }}" style="cursor:default">
                        <span class="media-preview">
                            @if ($url && $slot['kind'] === 'video')
                                <video src="{{ $url }}" controls preload="metadata" playsinline></video>
                            @elseif ($url)
                                <img src="{{ $url }}" alt="{{ $slot['label'] }}">
                            @endif
                        </span>

                        @unless ($url)
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

<dl class="sec-facts" style="margin-top:20px">
    <div><dt>Created</dt><dd>{{ $slider->created_at?->format('d M Y') ?? '—' }}</dd></div>
    <div><dt>Updated</dt><dd>{{ $slider->updated_at?->format('d M Y') ?? '—' }}</dd></div>
</dl>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('content.sliders.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.sliders.edit', $slider) }}"
           data-modal="{{ route('admin.sliders.edit', $slider) }}"
           data-modal-title="Edit Slider"
           data-modal-sub="{{ $slider->title }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
