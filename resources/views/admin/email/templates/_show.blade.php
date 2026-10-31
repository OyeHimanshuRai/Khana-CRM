{{--
    Read-only template detail, rendered straight into the modal body.

    The preview is an <iframe> rather than inline markup, and that is not a
    stylistic choice: the body is operator-authored HTML, and dropping it into
    the admin DOM would let whoever wrote it run script in the session of
    everyone who later opens this screen. The sandbox attribute below is empty
    on purpose - no scripts, no forms, no same-origin access.
--}}

<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
    <span class="badge {{ $template->statusTone() }}">
        <span class="badge-dot"></span> {{ $template->statusLabel() }}
    </span>

    @if ($template->category)
        <span class="badge badge-info">{{ $template->category }}</span>
    @endif

    <span class="badge">{{ number_format($template->wordCount()) }} words</span>
</div>

<div class="sec-name">{{ $template->name }}</div>
<p class="text-sm text-muted" style="margin:6px 0 0">{{ $template->subject }}</p>

@if ($template->description)
    <p class="text-sm" style="margin:8px 0 0">{{ $template->description }}</p>
@endif

<div class="form-section">
    <div class="form-section-title">Preview</div>

    <iframe src="{{ route('admin.email.templates.preview', $template) }}"
            sandbox
            title="Preview of {{ $template->name }}"
            style="width:100%;height:420px;border:1px solid var(--border);background:#fff"></iframe>

    <div class="form-hint" style="margin-top:8px">
        Rendered through the same mailable a real send uses, with sample values in the placeholders —
        so this is the email, not an approximation of it.
    </div>
</div>

<div class="form-section">
    <div class="form-section-title">Details</div>

    <dl class="sec-facts">
        <div>
            <dt>Handle</dt>
            <dd class="list-ref" style="font-size:12px">{{ $template->slug }}</dd>
        </div>
        <div><dt>Preheader</dt><dd style="font-size:13px">{{ $template->preheader ?: '—' }}</dd></div>
        <div><dt>Created by</dt><dd style="font-size:13px">{{ $template->authorLabel() }}</dd></div>
        <div><dt>Created</dt><dd>{{ $template->created_at?->format('d M Y') ?? '—' }}</dd></div>
        <div><dt>Campaigns started</dt><dd>{{ number_format($template->usage_count) }}</dd></div>
        <div>
            <dt>Last used</dt>
            <dd>{{ $template->last_used_at?->diffForHumans() ?? 'Never' }}</dd>
        </div>
    </dl>
</div>

<div class="form-section">
    <div class="form-section-title">Placeholders in use</div>

    @if ($template->variableList()->isEmpty())
        <p class="text-sm text-muted">
            None. This template sends the same words to everybody — which is fine, but
            {{-- @{{ is Blade's escape for a literal brace pair. --}}
            <span class="list-ref">@{{name}}</span> costs nothing and reads better.
        </p>
    @else
        @foreach ($template->variableList() as $tag)
            <div style="margin-top:4px">
                <span class="list-ref">{{ $tag }}</span>
                <span class="text-muted text-sm">— {{ $mergeTags[$tag] ?? 'Custom' }}</span>
            </div>
        @endforeach
    @endif

    <div class="form-hint" style="margin-top:10px">
        Detected from the content on every save, so this list cannot drift out of step with the body.
    </div>
</div>

<div class="modal-actions" style="flex-wrap:wrap">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('email.templates.edit')
        @if (filled($template->content))
            <a class="btn" href="{{ route('admin.email.templates.test', $template) }}"
               data-modal="{{ route('admin.email.templates.test', $template) }}"
               data-modal-title="Send a Test"
               data-modal-sub="{{ $template->name }}">
                <x-icon name="mail" :size="15" /> Send a test
            </a>
        @endif
    @endallows

    @allows('email.templates.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.email.templates.edit', $template) }}"
           data-modal="{{ route('admin.email.templates.edit', $template) }}"
           data-modal-title="Edit Template"
           data-modal-sub="{{ $template->name }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
