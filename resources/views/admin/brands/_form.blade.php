{{--
    Add / Edit brand, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The logo preview and remove button are delegated from
    crud-forms.js; submit, toasts and inline errors come from app.js.
--}}

@php
    $isNew = ! $brand->exists;
    $logo = $isNew ? null : $brand->logoUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.brands.store') : route('admin.brands.update', $brand) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="brand-name">Name</label>
            <input id="brand-name" type="text" name="name" class="form-control" required
                   value="{{ $brand->name }}" autocomplete="off" aria-invalid="false"
                   data-slug-source>
        </div>

        <div class="field">
            <label for="brand-slug">Slug</label>
            <input id="brand-slug" type="text" name="slug" class="form-control"
                   value="{{ $brand->slug }}" autocomplete="off" aria-invalid="false"
                   placeholder="{{ $isNew ? 'Filled in from the name' : $brand->slug }}"
                   data-slug-target>
            <div class="form-hint">
                {{ $isNew
                    ? 'Leave blank to build it from the name.'
                    : 'Leave blank to keep the current slug — anything linking to it stays valid.' }}
            </div>
        </div>

        <div class="field">
            <label for="brand-manufacturer">Manufacturer</label>
            <input id="brand-manufacturer" type="text" name="manufacturer" class="form-control"
                   value="{{ $brand->manufacturer }}" autocomplete="off" aria-invalid="false">
            <div class="form-hint">The company behind the label, if it differs.</div>
        </div>

        <div class="field">
            <label for="brand-website">Website</label>
            <input id="brand-website" type="url" name="website" class="form-control"
                   value="{{ $brand->website }}" autocomplete="off" aria-invalid="false"
                   placeholder="https://example.com">
        </div>

        <div class="field field-full">
            <label for="brand-description">Description</label>
            <textarea id="brand-description" name="description" class="form-control"
                      style="min-height:74px" aria-invalid="false">{{ $brand->description }}</textarea>
        </div>

        <div class="field">
            <label for="brand-order">Display order</label>
            <input id="brand-order" type="number" name="sort_order" class="form-control"
                   value="{{ $brand->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            <div class="form-hint">Lower numbers appear first.</div>
        </div>

        <div class="field">
            <div class="form-label">Status</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $brand->is_active)>
                Active
            </label>
            <div class="form-hint">Inactive brands stay in the list but are hidden elsewhere.</div>
        </div>

        <div class="field field-full">
            <div class="form-label">Logo</div>

            <div class="setting-image" data-image-field>
                <div class="setting-image-preview" data-file-preview>
                    @if ($logo)
                        <img src="{{ $logo }}" alt="{{ $brand->name }}">
                    @else
                        <span class="text-xs text-muted">No logo</span>
                    @endif
                </div>

                <div class="setting-image-controls">
                    <label for="brand-logo" class="sr-only">Choose a logo</label>
                    <input id="brand-logo" type="file" name="logo"
                           accept="image/jpeg,image/png,image/webp" data-file-input>

                    <div class="form-hint">
                        JPG, PNG or WebP · up to 2&nbsp;MB · at least 64×64&nbsp;px
                    </div>

                    @unless ($isNew)
                        <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                data-remove-url="{{ route('admin.brands.logo.destroy', $brand) }}"
                                @unless ($logo) hidden @endunless>
                            <x-icon name="trash" :size="13" /> Remove logo
                        </button>
                    @endunless
                </div>
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create brand' : 'Save changes' }}
        </button>
    </div>
</form>
