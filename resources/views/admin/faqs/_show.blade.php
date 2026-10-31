{{--
    Read-only detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from an edit.
--}}

<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
    <span class="badge {{ $faq->is_active ? 'badge-success' : 'badge-danger' }}">
        <span class="badge-dot"></span> {{ $faq->is_active ? 'Active' : 'Inactive' }}
    </span>
    <span class="badge badge-info">{{ $faq->categoryLabel() }}</span>
    <span class="badge">Order {{ $faq->sort_order }}</span>
</div>

<div class="form-label">Question</div>
<p class="sec-name" style="margin:0 0 18px;font-size:15px;line-height:1.45">{{ $faq->question }}</p>

<div class="form-label">Answer</div>
{{-- nl2br on escaped output: paragraph breaks survive, markup does not. --}}
<p class="text-sm text-muted" style="margin:0">{!! nl2br(e($faq->answer)) !!}</p>

<dl class="sec-facts" style="margin-top:20px">
    <div><dt>Created</dt><dd>{{ $faq->created_at?->format('d M Y') ?? '—' }}</dd></div>
    <div><dt>Updated</dt><dd>{{ $faq->updated_at?->format('d M Y') ?? '—' }}</dd></div>
</dl>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('content.faqs.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.faqs.edit', $faq) }}"
           data-modal="{{ route('admin.faqs.edit', $faq) }}"
           data-modal-title="Edit FAQ"
           data-modal-sub="{{ Str::limit($faq->question, 60) }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
