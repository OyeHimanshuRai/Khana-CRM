{{--
    Add / edit a testimonial (§19), rendered straight into the modal body.

    No <script> may live here — markup injected via innerHTML never runs its
    scripts. The submit, the image preview and the inline errors all come from
    app.js and crud-forms.js.
--}}

@php
    $isNew = ! $row->exists;
    $image = $isNew ? null : $row->imageUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.testimonials.store') : route('admin.testimonials.update', $row->id) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field field-full">
            <label for="t-quote">What they said</label>
            <textarea id="t-quote" name="quote" class="form-control" required
                      style="min-height:120px" maxlength="1000"
                      aria-invalid="false">{{ $row->quote }}</textarea>
            <div class="form-hint">Their words. Short quotes read better than long ones.</div>
        </div>

        <div class="field">
            <label for="t-name">Name</label>
            <input id="t-name" type="text" name="author_name" class="form-control" required
                   value="{{ $row->author_name }}" maxlength="120" autocomplete="off" aria-invalid="false">
            {{-- The one required attribution: an unattributed quote on a
                 marketing page is worth less than no quote. --}}
            <div class="form-hint">Required — an anonymous quote convinces nobody.</div>
        </div>

        <div class="field">
            <label for="t-role">Their role</label>
            <input id="t-role" type="text" name="author_role" class="form-control"
                   value="{{ $row->author_role }}" maxlength="120"
                   placeholder="Owner, Head chef…" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field">
            <label for="t-company">Restaurant</label>
            <input id="t-company" type="text" name="company" class="form-control"
                   value="{{ $row->company }}" maxlength="120" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field">
            <label for="t-rating">Rating</label>
            <select id="t-rating" name="rating" class="form-control" aria-invalid="false">
                {{-- Blank first, and the default. Printing "5/5" against every
                     testimonial is the fastest way to make all of them look
                     invented. --}}
                <option value="">No rating</option>
                @for ($i = 5; $i >= 1; $i--)
                    <option value="{{ $i }}" @selected((int) $row->rating === $i)>{{ $i }} / 5</option>
                @endfor
            </select>
        </div>

        <div class="field">
            <label for="t-order">Display order</label>
            <input id="t-order" type="number" name="sort_order" class="form-control"
                   value="{{ $row->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            <div class="form-hint">Lower numbers appear first.</div>
        </div>

        <div class="field field-full">
            <div class="form-label">Photograph</div>

            <div class="setting-image" data-image-field>
                <div class="setting-image-preview" data-file-preview>
                    @if ($image)
                        <img src="{{ $image }}" alt="{{ $row->author_name }}">
                    @else
                        <span class="text-xs text-muted">No photo</span>
                    @endif
                </div>

                <div class="setting-image-controls">
                    <label for="t-image" class="sr-only">Choose a photograph</label>
                    <input id="t-image" type="file" name="image"
                           accept="image/jpeg,image/png,image/webp" data-file-input>

                    <div class="form-hint">
                        Optional — initials are shown instead. JPG, PNG or WebP, up to 4&nbsp;MB.
                    </div>

                    @unless ($isNew)
                        <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                data-remove-url="{{ route('admin.testimonials.image.destroy', $row->id) }}"
                                @unless ($image) hidden @endunless>
                            <x-icon name="trash" :size="13" /> Remove photo
                        </button>
                    @endunless
                </div>
            </div>
        </div>

        <div class="field field-full">
            <div class="form-label">Status</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $row->is_active)>
                <span>Show on the landing page</span>
            </label>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Add testimonial' : 'Save changes' }}
        </button>
    </div>
</form>
