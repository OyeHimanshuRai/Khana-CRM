{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. Drag & drop, previews and the media buttons are delegated from
    crud-forms.js; the submit, the toasts and the inline field errors come
    from app.js.
--}}

@php
    $isNew = ! $slider->exists;
    $media = $isNew ? [] : $slider->mediaUrls();

    /*
     | Grouped so the four slots render as "Desktop Media" / "Mobile Media".
     | preserveKeys, because the loop below needs the column name to look up
     | the stored URL - groupBy renumbers otherwise.
     */
    $groups = collect(\App\Models\Slider::MEDIA)->groupBy('group', preserveKeys: true);
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.sliders.store') : route('admin.sliders.update', $slider) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Basic Information</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="sld-title">Title</label>
                <input id="sld-title" type="text" name="title" class="form-control" required
                       value="{{ $slider->title }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="sld-description">Description</label>
                <textarea id="sld-description" name="description" class="form-control"
                          style="min-height:80px" aria-invalid="false">{{ $slider->description }}</textarea>
            </div>

            <div class="field">
                <label for="sld-layout">Layout</label>
                <select id="sld-layout" name="layout" class="form-control" required aria-invalid="false">
                    @foreach ($layouts as $key => $label)
                        <option value="{{ $key }}" @selected($slider->layout === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="sld-item">Item No</label>
                <input id="sld-item" type="number" name="item_no" class="form-control"
                       value="{{ $slider->item_no }}" min="1" max="65535"
                       placeholder="{{ $isNew ? 'Added at the end' : '' }}" aria-invalid="false">
                <div class="form-hint">Position within the layout. Leave blank to add it last.</div>
            </div>

            <div class="field field-full">
                <label for="sld-url">Redirect URL</label>
                <input id="sld-url" type="url" name="redirect_url" class="form-control"
                       value="{{ $slider->redirect_url }}" placeholder="https://…"
                       autocomplete="off" aria-invalid="false">
                <div class="form-hint">Where the slide links to. Leave blank for a slide that is not clickable.</div>
            </div>

            <div class="field field-full">
                <div class="form-label">Status</div>
                <label class="check">
                    {{-- The hidden field is what makes an unticked box submit
                         a 0 rather than nothing at all. --}}
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $slider->is_active)>
                    Active
                </label>
            </div>
        </div>
    </div>

    @foreach ($groups as $groupName => $slots)
        <div class="form-section">
            <div class="form-section-title">{{ $groupName }}</div>

            <div class="media-grid">
                @foreach ($slots as $column => $slot)
                    <x-media-field
                        :name="$slot['field']"
                        :label="$slot['label']"
                        :kind="$slot['kind']"
                        :accept="$slot['accept']"
                        :formats="$slot['formats']"
                        :note="$slot['note']"
                        :url="$media[$column] ?? null"
                        :remove-url="$isNew ? null : route('admin.sliders.media.destroy', [$slider, $slot['field']])"
                    />
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create slider' : 'Save changes' }}
        </button>
    </div>
</form>
