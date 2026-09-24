{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. Drag & drop, previews and the media buttons are delegated from
    crud-forms.js; the submit, the toasts and the inline field errors come
    from app.js.
--}}

@php
    use App\Models\CollectionMedia;

    $isNew = ! $collection->exists;
    $stored = $isNew ? [] : $collection->mediaBySlot();

    // Grouped so the six cells render under "Desktop" and "Mobile".
    $groups = collect($slots)->groupBy('device');
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.collections.store') : route('admin.collections.update', $collection) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Basic Info</div>

        <div class="settings-grid">
            <div class="field">
                <label for="col-name">Collection name</label>
                <input id="col-name" type="text" name="name" class="form-control" required
                       value="{{ $collection->name }}" autocomplete="off" aria-invalid="false"
                       data-slug-source>
            </div>

            <div class="field">
                <label for="col-slug">Slug</label>
                <input id="col-slug" type="text" name="slug" class="form-control"
                       value="{{ $collection->slug }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $isNew ? 'Filled in from the name' : $collection->slug }}"
                       data-slug-target>
                <div class="form-hint">
                    {{ $isNew
                        ? 'Leave blank to build it from the name.'
                        : 'Leave blank to keep the current slug — anything linking to it stays valid.' }}
                </div>
            </div>

            <div class="field field-full">
                <label for="col-short">Short description</label>
                <textarea id="col-short" name="short_description" class="form-control"
                          style="min-height:60px" maxlength="400"
                          aria-invalid="false">{{ $collection->short_description }}</textarea>
                <div class="form-hint">The blurb shown on cards. Up to 400 characters.</div>
            </div>

            <div class="field field-full">
                <label for="col-description">Description</label>
                <textarea id="col-description" name="description" class="form-control"
                          style="min-height:150px" aria-invalid="false">{{ $collection->description }}</textarea>
            </div>

            <div class="field">
                <label for="col-order">Sort order</label>
                <input id="col-order" type="number" name="sort_order" class="form-control"
                       value="{{ $collection->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
                <div class="form-hint">Lower numbers appear first.</div>
            </div>

            <div class="field">
                <div class="form-label">Flags</div>

                <label class="check" style="margin-bottom:8px">
                    {{-- The hidden field is what makes an unticked box submit
                         a 0 rather than nothing at all. --}}
                    <input type="hidden" name="is_featured" value="0">
                    <input type="checkbox" name="is_featured" value="1" @checked($collection->is_featured)>
                    Featured
                </label>

                <label class="check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $collection->is_active)>
                    Active
                </label>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">SEO</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="col-meta-title">Meta title</label>
                <input id="col-meta-title" type="text" name="meta_title" class="form-control"
                       value="{{ $collection->meta_title }}" maxlength="200"
                       placeholder="Falls back to the collection name" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="col-meta-description">Meta description</label>
                <textarea id="col-meta-description" name="meta_description" class="form-control"
                          style="min-height:60px" maxlength="320"
                          placeholder="Falls back to the short description"
                          aria-invalid="false">{{ $collection->meta_description }}</textarea>
            </div>
        </div>
    </div>

    {{--
        Media is a device x position matrix. Each cell takes either an image
        or a video - the type is read off whatever is uploaded, so a cell can
        never claim to hold one and actually hold the other.
    --}}
    @foreach ($groups as $device => $cells)
        <div class="form-section">
            <div class="form-section-title">
                {{ CollectionMedia::DEVICES[$device] }} media
                <span style="float:right;font-weight:600;letter-spacing:0;text-transform:none;color:var(--faint)">
                    {{ CollectionMedia::GUIDANCE[$device]['note'] ?? '' }}
                </span>
            </div>

            <div class="media-grid">
                @foreach ($cells as $slot)
                    @php $piece = $stored[$device.'.'.$slot['position']] ?? null; @endphp

                    <x-media-field
                        :name="$slot['field']"
                        :label="CollectionMedia::POSITIONS[$slot['position']]"
                        :kind="$piece?->isVideo() ? 'video' : 'image'"
                        accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime"
                        formats="JPG, PNG, WebP, MP4, MOV"
                        :note="($piece ? ucfirst($piece->type).' · ' : '').'Images up to 4 MB, video up to 20 MB'"
                        :url="$piece?->url()"
                        :remove-url="$isNew
                            ? null
                            : route('admin.collections.media.destroy', [$collection, $device, $slot['position']])"
                    />
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create collection' : 'Save changes' }}
        </button>
    </div>
</form>
