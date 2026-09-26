{{--
    Read-only detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from an edit.
--}}

@php $image = $post->imageUrl(); @endphp

<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
    <span class="badge {{ $post->statusTone() }}">
        <span class="badge-dot"></span> {{ $post->statusLabel() }}
    </span>

    @if ($post->isScheduled())
        <span class="badge badge-warning">Scheduled</span>
    @endif

    @if ($post->is_featured)
        <span class="badge badge-brand">Featured</span>
    @endif

    @if ($post->category)
        <span class="badge badge-info">{{ $post->category }}</span>
    @endif
</div>

@if ($image)
    <div class="cat-view-image" style="width:100%;height:210px;margin-bottom:16px">
        <img src="{{ $image }}" alt="{{ $post->title }}">
    </div>
@endif

<div class="sec-name" style="font-size:17px;line-height:1.35">{{ $post->title }}</div>

@if ($post->short_description)
    <p class="text-sm text-muted" style="margin:8px 0 0">{{ $post->short_description }}</p>
@endif

<dl class="sec-facts" style="margin-top:16px">
    <div><dt>Author</dt><dd>{{ $post->authorLabel() }}</dd></div>
    <div><dt>Publish date</dt><dd>{{ $post->published_at?->format('d M Y, H:i') ?? '—' }}</dd></div>
    <div><dt>Slug</dt><dd class="list-ref" style="font-size:13px">{{ $post->slug }}</dd></div>
    <div><dt>Created</dt><dd>{{ $post->created_at?->format('d M Y') ?? '—' }}</dd></div>
</dl>

@if ($post->tagList()->isNotEmpty())
    <div style="margin-top:16px">
        <div class="form-label">Tags</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
            @foreach ($post->tagList() as $tag)
                <span class="badge">{{ $tag }}</span>
            @endforeach
        </div>
    </div>
@endif

@if ($post->content)
    <div class="form-section">
        <div class="form-section-title">Content</div>
        {{-- nl2br on escaped output: paragraph breaks survive, markup does not. --}}
        <p class="text-sm text-muted" style="margin:0">{!! nl2br(e($post->content)) !!}</p>
    </div>
@endif

@if ($post->seo_title || $post->seo_description)
    <div class="form-section">
        <div class="form-section-title">SEO</div>
        <dl class="sec-facts">
            <div><dt>SEO title</dt><dd style="font-size:13px">{{ $post->seo_title ?: '—' }}</dd></div>
        </dl>
        @if ($post->seo_description)
            <p class="text-sm text-muted" style="margin:10px 0 0">{{ $post->seo_description }}</p>
        @endif
    </div>
@endif

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('content.blogs.edit')
        {{-- Replaces what is in the modal rather than stacking a second one. --}}
        <a class="btn btn-primary" href="{{ route('admin.blogs.edit', $post) }}"
           data-modal="{{ route('admin.blogs.edit', $post) }}"
           data-modal-title="Edit Post"
           data-modal-sub="{{ $post->title }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
