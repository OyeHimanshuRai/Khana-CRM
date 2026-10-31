{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The image preview and the remove button are delegated from
    crud-forms.js; the submit, the toasts and the inline field errors come
    from app.js.
--}}

@php
    $isNew = ! $category->exists;
    $image = $isNew ? null : $category->imageUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.categories.store') : route('admin.categories.update', $category) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list data-category-form>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="cat-name">Name</label>
            <input id="cat-name" type="text" name="name" class="form-control" required
                   value="{{ $category->name }}" autocomplete="off" aria-invalid="false"
                   data-slug-source>
        </div>

        <div class="field">
            <label for="cat-slug">Slug</label>
            <input id="cat-slug" type="text" name="slug" class="form-control"
                   value="{{ $category->slug }}" autocomplete="off" aria-invalid="false"
                   placeholder="{{ $isNew ? 'Filled in from the name' : $category->slug }}"
                   data-slug-target>
            <div class="form-hint">
                {{ $isNew
                    ? 'Leave blank to build it from the name.'
                    : 'Leave blank to keep the current slug — anything linking to it stays valid.' }}
            </div>
        </div>

        <div class="field field-full">
            <label for="cat-description">Description</label>
            <textarea id="cat-description" name="description" class="form-control"
                      style="min-height:74px" aria-invalid="false">{{ $category->description }}</textarea>
        </div>

        {{--
            Sub-sections (§8). Offered only where there is something to sit
            under: a restaurant's first category has no parent to pick, and an
            empty select reads as a field somebody forgot to fill in.

            Hidden entirely for a category that already has children, because
            moving it would make grandchildren - see CategoryController.
        --}}
        @if ($parents->isNotEmpty())
            <div class="field">
                <label for="cat-parent">Sits under</label>
                <select id="cat-parent" name="parent_id" class="form-control" aria-invalid="false">
                    <option value="">Top level — its own heading</option>
                    @foreach ($parents as $parent)
                        <option value="{{ $parent->id }}" @selected((int) $category->parent_id === (int) $parent->id)>
                            {{ $parent->name }}
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">
                    A sub-section prints inside its parent on the menu card, not
                    as a heading of its own.
                </div>
            </div>
        @endif

        {{--
            Routing (§9). Left off the form altogether where a branch runs no
            stations - a one-room kitchen has one screen and should not be
            asked a question with one answer.
        --}}
        @if ($stations->isNotEmpty())
            <div class="field">
                <label for="cat-station">Cooked at</label>
                <select id="cat-station" name="kitchen_station_id" class="form-control" aria-invalid="false">
                    <option value="">Wherever the parent section goes</option>
                    @foreach ($stations as $station)
                        <option value="{{ $station->id }}"
                            @selected((int) $category->kitchen_station_id === (int) $station->id)>
                            {{ $station->name }}@if ($station->is_default) (default)@endif
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">
                    Everything in this section goes here unless a dish says
                    otherwise. This is how routing is normally set.
                </div>
            </div>
        @endif

        <div class="field">
            <label for="cat-order">Display order</label>
            <input id="cat-order" type="number" name="sort_order" class="form-control"
                   value="{{ $category->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            <div class="form-hint">Lower numbers appear first.</div>
        </div>

        <div class="field">
            <div class="form-label">Status</div>
            <label class="check">
                {{-- The hidden field is what makes an unticked box submit a 0
                     rather than nothing at all. --}}
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $category->is_active)>
                Active
            </label>
            <div class="form-hint">Inactive categories stay in the list but are hidden elsewhere.</div>
        </div>

        <div class="field field-full">
            <div class="form-label">Image</div>

            <div class="setting-image" data-image-field>
                <div class="setting-image-preview" data-file-preview>
                    @if ($image)
                        <img src="{{ $image }}" alt="{{ $category->name }}">
                    @else
                        <span class="text-xs text-muted">No image</span>
                    @endif
                </div>

                <div class="setting-image-controls">
                    <label for="cat-image" class="sr-only">Choose an image</label>
                    <input id="cat-image" type="file" name="image"
                           accept="image/jpeg,image/png,image/webp" data-file-input>

                    <div class="form-hint">
                        JPG, PNG or WebP · up to 2&nbsp;MB · between 100×100 and 4000×4000&nbsp;px
                    </div>

                    @unless ($isNew)
                        {{-- Its own endpoint, so the image can go without
                             saving the rest of the form. --}}
                        <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                data-remove-url="{{ route('admin.categories.image.destroy', $category) }}"
                                @unless ($image) hidden @endunless>
                            <x-icon name="trash" :size="13" /> Remove image
                        </button>
                    @endunless
                </div>
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create category' : 'Save changes' }}
        </button>
    </div>
</form>
