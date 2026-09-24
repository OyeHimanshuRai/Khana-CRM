{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The image preview and its remove button are delegated from
    crud-forms.js; the submit, the toasts and the inline field errors come
    from app.js.
--}}

@php
    $isNew = ! $post->exists;
    $image = $isNew ? null : $post->imageUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.instagram.store') : route('admin.instagram.update', $post) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Post</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="ig-title">Title</label>
                <input id="ig-title" type="text" name="title" class="form-control" required
                       value="{{ $post->title }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="ig-url">Instagram post URL</label>
                <input id="ig-url" type="url" name="post_url" class="form-control" required
                       value="{{ $post->post_url }}"
                       placeholder="https://www.instagram.com/p/ABC123/"
                       autocomplete="off" aria-invalid="false">
                <div class="form-hint">
                    Paste the full link. The post ID and embed URL are worked out from it.
                </div>
            </div>

            @unless ($isNew)
                {{-- Read-only: both are derived from the URL above, so an
                     editable field would only be a way to break them. --}}
                <div class="field">
                    <div class="form-label">Post ID</div>
                    <div class="text-sm"><span class="list-ref">{{ $post->shortcode() ?? '—' }}</span></div>
                </div>

                <div class="field">
                    <div class="form-label">Embed URL</div>
                    <div class="text-xs text-muted" style="word-break:break-all">
                        {{ $post->embedUrl() ?? '—' }}
                    </div>
                </div>
            @endunless

            <div class="field field-full">
                <label for="ig-description">Description</label>
                <textarea id="ig-description" name="description" class="form-control"
                          style="min-height:80px" aria-invalid="false">{{ $post->description }}</textarea>
            </div>

            <div class="field">
                <label for="ig-order">Sort order</label>
                <input id="ig-order" type="number" name="sort_order" class="form-control"
                       value="{{ $post->sort_order }}" min="0" max="65535"
                       placeholder="{{ $isNew ? 'Added at the end' : '' }}" aria-invalid="false">
                <div class="form-hint">Lower numbers appear first.</div>
            </div>

            <div class="field">
                <div class="form-label">Status</div>
                <label class="check">
                    {{-- The hidden field is what makes an unticked box submit
                         a 0 rather than nothing at all. --}}
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $post->is_active)>
                    Active
                </label>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Image</div>

        <div class="setting-image" data-image-field>
            <div class="setting-image-preview" data-file-preview>
                @if ($image)
                    <img src="{{ $image }}" alt="{{ $post->title }}">
                @else
                    <span class="text-xs text-muted">No image</span>
                @endif
            </div>

            <div class="setting-image-controls">
                <label for="ig-image" class="sr-only">Choose an image</label>
                <input id="ig-image" type="file" name="image"
                       accept="image/jpeg,image/png,image/webp" data-file-input>

                <div class="form-hint">JPG, PNG or WebP · up to 2&nbsp;MB</div>

                @unless ($isNew)
                    {{-- Its own endpoint, so the image can go without saving
                         the rest of the form. --}}
                    <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                            data-remove-url="{{ route('admin.instagram.image.destroy', $post) }}"
                            @unless ($image) hidden @endunless>
                        <x-icon name="trash" :size="13" /> Remove image
                    </button>
                @endunless
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create post' : 'Save changes' }}
        </button>
    </div>
</form>
