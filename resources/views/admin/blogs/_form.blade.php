{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The image preview, the remove button and the slug auto-fill are
    delegated from crud-forms.js; the submit, the toasts and the inline field
    errors come from app.js.
--}}

@php
    $isNew = ! $post->exists;
    $image = $isNew ? null : $post->imageUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.blogs.store') : route('admin.blogs.update', $post) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Post</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="blog-title">Title</label>
                <input id="blog-title" type="text" name="title" class="form-control" required
                       value="{{ $post->title }}" autocomplete="off" aria-invalid="false"
                       data-slug-source>
            </div>

            <div class="field field-full">
                <label for="blog-slug">Slug</label>
                <input id="blog-slug" type="text" name="slug" class="form-control"
                       value="{{ $post->slug }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $isNew ? 'Filled in from the title' : $post->slug }}"
                       data-slug-target>
                <div class="form-hint">
                    {{ $isNew
                        ? 'Leave blank to build it from the title.'
                        : 'Leave blank to keep the current slug — anything linking to it stays valid.' }}
                </div>
            </div>

            <div class="field field-full">
                <label for="blog-short">Short description</label>
                <textarea id="blog-short" name="short_description" class="form-control"
                          style="min-height:60px" maxlength="400"
                          aria-invalid="false">{{ $post->short_description }}</textarea>
                <div class="form-hint">The blurb shown on cards and in listings. Up to 400 characters.</div>
            </div>

            <div class="field field-full">
                <label for="blog-content">Full content</label>
                <textarea id="blog-content" name="content" class="form-control"
                          style="min-height:220px" aria-invalid="false">{{ $post->content }}</textarea>
                <div class="form-hint">Line breaks are kept; other formatting is not.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Details</div>

        <div class="settings-grid">
            <div class="field">
                <label for="blog-author">Author</label>
                <select id="blog-author" name="author_id" class="form-control" aria-invalid="false">
                    <option value="">{{ $isNew ? 'You' : 'Unchanged' }}</option>
                    @foreach ($users as $person)
                        <option value="{{ $person->id }}" @selected($post->author_id === $person->id)>
                            {{ $person->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="blog-category">Category</label>
                {{--
                    Free text with a datalist rather than a select: the labels
                    that already exist are one keystroke away, but a new one
                    needs no trip to a separate screen to create first.
                --}}
                <input id="blog-category" type="text" name="category" class="form-control"
                       value="{{ $post->category }}" list="blog-category-options"
                       placeholder="Guides, News…" autocomplete="off" aria-invalid="false">
                <datalist id="blog-category-options">
                    @foreach ($categories as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div class="field field-full">
                <label for="blog-tags">Tags</label>
                <input id="blog-tags" type="text" name="tags" class="form-control"
                       value="{{ $post->tagList()->implode(', ') }}"
                       placeholder="recipes, offers, hygiene" autocomplete="off" aria-invalid="false">
                <div class="form-hint">Separate with commas. Up to 20; duplicates are dropped.</div>
            </div>

            <div class="field">
                <label for="blog-status">Status</label>
                <select id="blog-status" name="status" class="form-control" required aria-invalid="false">
                    @foreach (\App\Models\Blog::STATUSES as $key => $label)
                        <option value="{{ $key }}"
                            @selected(($post->status ?? \App\Models\Blog::DRAFT) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="blog-published">Publish date</label>
                <input id="blog-published" type="datetime-local" name="published_at" class="form-control"
                       value="{{ $post->published_at?->format('Y-m-d\TH:i') }}" aria-invalid="false">
                <div class="form-hint">A future date schedules it: published, but not live until then.</div>
            </div>

            <div class="field field-full">
                <div class="form-label">Featured</div>
                <label class="check">
                    {{-- The hidden field is what makes an unticked box submit
                         a 0 rather than nothing at all. --}}
                    <input type="hidden" name="is_featured" value="0">
                    <input type="checkbox" name="is_featured" value="1" @checked($post->is_featured)>
                    Show this post in featured slots
                </label>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Featured image</div>

        <div class="setting-image" data-image-field>
            <div class="setting-image-preview" data-file-preview>
                @if ($image)
                    <img src="{{ $image }}" alt="{{ $post->title }}">
                @else
                    <span class="text-xs text-muted">No image</span>
                @endif
            </div>

            <div class="setting-image-controls">
                <label for="blog-image" class="sr-only">Choose an image</label>
                <input id="blog-image" type="file" name="featured_image"
                       accept="image/jpeg,image/png,image/webp" data-file-input>

                <div class="form-hint">
                    JPG, PNG or WebP · up to 2&nbsp;MB · at least 200 × 120&nbsp;px
                </div>

                @unless ($isNew)
                    {{-- Its own endpoint, so the image can go without saving
                         the rest of the form. --}}
                    <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                            data-remove-url="{{ route('admin.blogs.image.destroy', $post) }}"
                            @unless ($image) hidden @endunless>
                        <x-icon name="trash" :size="13" /> Remove image
                    </button>
                @endunless
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">SEO</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="blog-seo-title">SEO title</label>
                <input id="blog-seo-title" type="text" name="seo_title" class="form-control"
                       value="{{ $post->seo_title }}" maxlength="200"
                       placeholder="Falls back to the post title" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="blog-seo-description">SEO description</label>
                <textarea id="blog-seo-description" name="seo_description" class="form-control"
                          style="min-height:60px" maxlength="320"
                          placeholder="Falls back to the short description"
                          aria-invalid="false">{{ $post->seo_description }}</textarea>
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
