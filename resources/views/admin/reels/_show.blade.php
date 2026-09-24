{{--
    Read-only detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from an edit.
--}}

@php $thumb = $reel->thumbnailUrl(); @endphp

<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
    <span class="badge {{ $reel->is_active ? 'badge-success' : 'badge-danger' }}">
        <span class="badge-dot"></span> {{ $reel->is_active ? 'Active' : 'Inactive' }}
    </span>
    <span class="badge badge-info">Order {{ $reel->sort_order }}</span>
</div>

<div class="cat-view">
    <div class="cat-view-image">
        @if ($thumb)
            <img src="{{ $thumb }}" alt="{{ $reel->title }}">
        @else
            <x-icon name="heart" :size="40" class="text-muted" />
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $reel->title }}</div>

        @if ($reel->description)
            <p class="text-sm text-muted" style="margin:8px 0 0">{{ $reel->description }}</p>
        @endif

        <dl class="sec-facts" style="margin-top:14px">
            <div><dt>Reel ID</dt><dd class="list-ref" style="font-size:13px">{{ $reel->shortcode() ?? '—' }}</dd></div>
            <div><dt>Publish date</dt><dd>{{ $reel->published_at?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt>Created</dt><dd>{{ $reel->created_at?->format('d M Y') ?? '—' }}</dd></div>
        </dl>
    </div>
</div>

<div class="form-section">
    <div class="form-section-title">Links</div>

    <dl class="sec-facts">
        <div>
            <dt>Original</dt>
            <dd style="font-size:12.5px;word-break:break-all;font-weight:400">
                {{-- rel on an outbound link the admin pasted, not one we vetted. --}}
                <a href="{{ $reel->reel_url }}" target="_blank" rel="noopener noreferrer"
                   style="color:var(--brand)">{{ $reel->reel_url }}</a>
            </dd>
        </div>
        <div>
            <dt>Embed URL</dt>
            <dd style="font-size:12.5px;word-break:break-all;font-weight:400">
                {{ $reel->embedUrl() ?? '—' }}
            </dd>
        </div>
    </dl>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('content.reels.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.reels.edit', $reel) }}"
           data-modal="{{ route('admin.reels.edit', $reel) }}"
           data-modal-title="Edit Reel"
           data-modal-sub="{{ $reel->title }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
