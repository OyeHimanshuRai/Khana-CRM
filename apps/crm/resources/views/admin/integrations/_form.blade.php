{{-- Add / edit an integration (§19), rendered into the modal body. --}}

@php
    $isNew = ! $row->exists;
    $image = $isNew ? null : $row->imageUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.integrations.store') : route('admin.integrations.update', $row->id) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="ig-name">Name</label>
            <input id="ig-name" type="text" name="name" class="form-control" required
                   value="{{ $row->name }}" maxlength="90" autocomplete="off" aria-invalid="false"
                   placeholder="Razorpay, Swiggy, Tally…">
        </div>

        <div class="field">
            <label for="ig-category">Category</label>
            {{-- Free text with a datalist: what already exists is one
                 keystroke away, a new one needs no separate screen. --}}
            <input id="ig-category" type="text" name="category" class="form-control"
                   value="{{ $row->category }}" list="ig-category-options" maxlength="40"
                   placeholder="Payments, Delivery, Accounting…" autocomplete="off" aria-invalid="false">
            <datalist id="ig-category-options">
                @foreach ($categories as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
        </div>

        <div class="field">
            <label for="ig-url">Link</label>
            <input id="ig-url" type="url" name="url" class="form-control"
                   value="{{ $row->url }}" maxlength="255" autocomplete="off" aria-invalid="false"
                   placeholder="https://…">
            <div class="form-hint">Optional. Leave blank and the logo is not a link.</div>
        </div>

        <div class="field">
            <label for="ig-order">Display order</label>
            <input id="ig-order" type="number" name="sort_order" class="form-control"
                   value="{{ $row->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
        </div>

        <div class="field field-full">
            <div class="form-label">Logo</div>

            <div class="setting-image" data-image-field>
                <div class="setting-image-preview" data-file-preview>
                    @if ($image)
                        <img src="{{ $image }}" alt="{{ $row->name }}">
                    @else
                        <span class="text-xs text-muted">No logo</span>
                    @endif
                </div>

                <div class="setting-image-controls">
                    <label for="ig-image" class="sr-only">Choose a logo</label>
                    <input id="ig-image" type="file" name="image"
                           accept="image/jpeg,image/png,image/webp" data-file-input>

                    {{-- The logo is the content here: nobody reads the name of
                         a gateway they already use, they recognise the mark. --}}
                    <div class="form-hint">
                        PNG or WebP with a transparent background works best. Without one,
                        the name is shown as a wordmark.
                    </div>

                    @unless ($isNew)
                        <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                data-remove-url="{{ route('admin.integrations.image.destroy', $row->id) }}"
                                @unless ($image) hidden @endunless>
                            <x-icon name="trash" :size="13" /> Remove logo
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
            {{ $isNew ? 'Add integration' : 'Save changes' }}
        </button>
    </div>
</form>
