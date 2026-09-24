{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The image preview, the remove button and the slug auto-fill are
    all delegated from crud-forms.js; the submit, the toasts and the inline
    field errors come from app.js.
--}}

@php
    $isNew = ! $service->exists;
    $image = $isNew ? null : $service->imageUrl();
    $currentIcon = $service->icon ?: 'tool';
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.services.store') : route('admin.services.update', $service) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="svc-name">Service name</label>
            <input id="svc-name" type="text" name="name" class="form-control" required
                   value="{{ $service->name }}" autocomplete="off" aria-invalid="false"
                   data-slug-source>
        </div>

        <div class="field">
            <label for="svc-slug">Slug</label>
            <input id="svc-slug" type="text" name="slug" class="form-control"
                   value="{{ $service->slug }}" autocomplete="off" aria-invalid="false"
                   placeholder="{{ $isNew ? 'Filled in from the name' : $service->slug }}"
                   data-slug-target>
            <div class="form-hint">
                {{ $isNew
                    ? 'Leave blank to build it from the name.'
                    : 'Leave blank to keep the current slug — anything linking to it stays valid.' }}
            </div>
        </div>

        <div class="field field-full">
            <label for="svc-short">Short description</label>
            <textarea id="svc-short" name="short_description" class="form-control"
                      style="min-height:60px" maxlength="300"
                      aria-invalid="false">{{ $service->short_description }}</textarea>
            <div class="form-hint">The one-line blurb shown on cards and in the list. Up to 300 characters.</div>
        </div>

        <div class="field field-full">
            <label for="svc-full">Full description</label>
            <textarea id="svc-full" name="full_description" class="form-control"
                      style="min-height:130px" aria-invalid="false">{{ $service->full_description }}</textarea>
        </div>

        <div class="field">
            <label for="svc-price">Price</label>
            <input id="svc-price" type="number" name="price" class="form-control"
                   value="{{ $service->price }}" step="0.01" min="0" placeholder="Leave blank if not priced"
                   aria-invalid="false">
            <div class="form-hint">
                Shown in the currency set under Settings &rarr; General.
            </div>
        </div>

        <div class="field">
            <div class="form-label">Price display</div>
            <label class="check">
                {{-- The hidden field is what makes an unticked box submit a 0
                     rather than nothing at all. --}}
                <input type="hidden" name="price_from" value="0">
                <input type="checkbox" name="price_from" value="1" @checked($service->price_from)>
                Starting price
            </label>
            <div class="form-hint">Ticked, the price reads “From ₹4,999” rather than a flat figure.</div>
        </div>

        <div class="field">
            <label for="svc-order">Display order</label>
            <input id="svc-order" type="number" name="sort_order" class="form-control"
                   value="{{ $service->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            <div class="form-hint">Lower numbers appear first.</div>
        </div>

        <div class="field">
            <div class="form-label">Status</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $service->is_active)>
                Active
            </label>
        </div>

        <div class="field field-full">
            <label for="svc-icon">Icon</label>
            {{--
                A picker rather than a text box: the names come from
                config/icons.php, and the server validates against the same
                list, so a typo cannot store something that renders blank.
            --}}
            <div class="icon-picker">
                <span class="icon-picker-preview" data-icon-preview>
                    <x-icon :name="$currentIcon" :size="20" />
                </span>

                <select id="svc-icon" name="icon" class="form-control" data-icon-select>
                    <option value="">No icon</option>
                    @foreach ($icons as $iconName)
                        <option value="{{ $iconName }}" @selected($service->icon === $iconName)>
                            {{ Str::headline($iconName) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-hint">Used on the public site wherever there is no image.</div>

            {{-- Every icon, hidden, so the preview can swap without a request. --}}
            <div class="icon-picker-source" hidden aria-hidden="true">
                @foreach ($icons as $iconName)
                    <span data-icon-option="{{ $iconName }}"><x-icon :name="$iconName" :size="20" /></span>
                @endforeach
            </div>
        </div>

        <div class="field field-full">
            <div class="form-label">Service image</div>

            <div class="setting-image" data-image-field>
                <div class="setting-image-preview" data-file-preview>
                    @if ($image)
                        <img src="{{ $image }}" alt="{{ $service->name }}">
                    @else
                        <span class="text-xs text-muted">No image</span>
                    @endif
                </div>

                <div class="setting-image-controls">
                    <label for="svc-image" class="sr-only">Choose an image</label>
                    <input id="svc-image" type="file" name="image"
                           accept="image/jpeg,image/png,image/webp" data-file-input>

                    <div class="form-hint">
                        JPG, PNG or WebP · up to 2&nbsp;MB · between 100×100 and 4000×4000&nbsp;px
                    </div>

                    @unless ($isNew)
                        {{-- Its own endpoint, so the image can go without
                             saving the rest of the form. --}}
                        <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                data-remove-url="{{ route('admin.services.image.destroy', $service) }}"
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
            {{ $isNew ? 'Create service' : 'Save changes' }}
        </button>
    </div>
</form>
