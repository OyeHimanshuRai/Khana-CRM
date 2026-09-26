{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The thumbnail preview and its remove button are delegated from
    crud-forms.js; the submit, the toasts and the inline field errors come
    from app.js.
--}}

@php
    $isNew = ! $reel->exists;
    $thumb = $isNew ? null : $reel->thumbnailUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.reels.store') : route('admin.reels.update', $reel) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Reel</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="reel-title">Title</label>
                <input id="reel-title" type="text" name="title" class="form-control" required
                       value="{{ $reel->title }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="reel-url">Instagram reel URL</label>
                <input id="reel-url" type="url" name="reel_url" class="form-control" required
                       value="{{ $reel->reel_url }}"
                       placeholder="https://www.instagram.com/reel/ABC123/"
                       autocomplete="off" aria-invalid="false">
                <div class="form-hint">
                    Paste the full link. The reel ID and embed URL are worked out from it.
                </div>
            </div>

            @unless ($isNew)
                {{-- Read-only: both are derived from the URL above, so an
                     editable field would only be a way to break them. --}}
                <div class="field">
                    <div class="form-label">Reel ID</div>
                    <div class="text-sm"><span class="list-ref">{{ $reel->shortcode() ?? '—' }}</span></div>
                </div>

                <div class="field">
                    <div class="form-label">Embed URL</div>
                    <div class="text-xs text-muted" style="word-break:break-all">
                        {{ $reel->embedUrl() ?? '—' }}
                    </div>
                </div>
            @endunless

            <div class="field field-full">
                <label for="reel-description">Description</label>
                <textarea id="reel-description" name="description" class="form-control"
                          style="min-height:80px" aria-invalid="false">{{ $reel->description }}</textarea>
            </div>

            <div class="field">
                <label for="reel-order">Sort order</label>
                <input id="reel-order" type="number" name="sort_order" class="form-control"
                       value="{{ $reel->sort_order }}" min="0" max="65535"
                       placeholder="{{ $isNew ? 'Added at the end' : '' }}" aria-invalid="false">
                <div class="form-hint">Lower numbers appear first.</div>
            </div>

            <div class="field">
                <label for="reel-published">Publish date</label>
                <input id="reel-published" type="datetime-local" name="published_at" class="form-control"
                       value="{{ $reel->published_at?->format('Y-m-d\TH:i') }}" aria-invalid="false">
            </div>

            <div class="field field-full">
                <div class="form-label">Status</div>
                <label class="check">
                    {{-- The hidden field is what makes an unticked box submit
                         a 0 rather than nothing at all. --}}
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $reel->is_active)>
                    Active
                </label>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Thumbnail</div>

        <div class="setting-image" data-image-field>
            <div class="setting-image-preview" data-file-preview>
                @if ($thumb)
                    <img src="{{ $thumb }}" alt="{{ $reel->title }}">
                @else
                    <span class="text-xs text-muted">No image</span>
                @endif
            </div>

            <div class="setting-image-controls">
                <label for="reel-thumb" class="sr-only">Choose a thumbnail</label>
                <input id="reel-thumb" type="file" name="thumbnail"
                       accept="image/jpeg,image/png,image/webp" data-file-input>

                <div class="form-hint">JPG, PNG or WebP · up to 2&nbsp;MB</div>

                @unless ($isNew)
                    {{-- Its own endpoint, so the thumbnail can go without
                         saving the rest of the form. --}}
                    <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                            data-remove-url="{{ route('admin.reels.thumbnail.destroy', $reel) }}"
                            @unless ($thumb) hidden @endunless>
                        <x-icon name="trash" :size="13" /> Remove thumbnail
                    </button>
                @endunless
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create reel' : 'Save changes' }}
        </button>
    </div>
</form>
