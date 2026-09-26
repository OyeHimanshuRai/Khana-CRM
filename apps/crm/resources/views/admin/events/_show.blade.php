{{--
    Read-only detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from an edit.
--}}

@php $image = $event->imageUrl(); @endphp

<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
    <span class="badge {{ $event->is_active ? 'badge-success' : 'badge-danger' }}">
        <span class="badge-dot"></span> {{ $event->is_active ? 'Active' : 'Inactive' }}
    </span>
    {{-- Calendar position, which is separate from the on/off switch. --}}
    <span class="badge {{ $event->phaseTone() }}">{{ $event->phaseLabel() }}</span>
</div>

<div class="cat-view">
    <div class="cat-view-image">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $event->title }}">
        @else
            <x-icon name="calendar" :size="40" class="text-muted" />
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $event->title }}</div>

        @if ($event->name)
            <p class="text-sm text-muted" style="margin:6px 0 0">{{ $event->name }}</p>
        @endif

        <dl class="sec-facts" style="margin-top:14px">
            <div><dt>Dates</dt><dd style="font-size:13px">{{ $event->dateRange() }}</dd></div>
            <div><dt>Timing</dt><dd style="font-size:13px">{{ $event->timing ?: '—' }}</dd></div>
            <div>
                <dt>Booth No</dt>
                <dd class="{{ $event->booth_no ? 'list-ref' : '' }}" style="font-size:13px">
                    {{ $event->booth_no ?: '—' }}
                </dd>
            </div>
        </dl>
    </div>
</div>

<dl class="sec-facts" style="margin-top:20px">
    <div><dt>From</dt><dd>{{ $event->from_date?->format('d M Y') ?? '—' }}</dd></div>
    <div><dt>To</dt><dd>{{ $event->to_date?->format('d M Y') ?? '—' }}</dd></div>
    <div><dt>Created</dt><dd>{{ $event->created_at?->format('d M Y') ?? '—' }}</dd></div>
    <div><dt>Updated</dt><dd>{{ $event->updated_at?->format('d M Y') ?? '—' }}</dd></div>
</dl>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('content.events.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.events.edit', $event) }}"
           data-modal="{{ route('admin.events.edit', $event) }}"
           data-modal-title="Edit Event"
           data-modal-sub="{{ $event->title }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
