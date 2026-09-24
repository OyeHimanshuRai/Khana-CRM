{{-- Add / edit a product screenshot (§19), rendered into the modal body. --}}

@php
    $isNew = ! $row->exists;
    $image = $isNew ? null : $row->imageUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.showcases.store') : route('admin.showcases.update', $row->id) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="sc-title">Title</label>
            <input id="sc-title" type="text" name="title" class="form-control" required
                   value="{{ $row->title }}" maxlength="120" autocomplete="off" aria-invalid="false"
                   placeholder="The kitchen display, The floor plan…">
        </div>

        <div class="field">
            <label for="sc-order">Display order</label>
            <input id="sc-order" type="number" name="sort_order" class="form-control"
                   value="{{ $row->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
        </div>

        <div class="field field-full">
            <label for="sc-caption">Caption</label>
            <input id="sc-caption" type="text" name="caption" class="form-control"
                   value="{{ $row->caption }}" maxlength="250" autocomplete="off" aria-invalid="false"
                   placeholder="Every ticket routed to the station that cooks it, with a timer running.">
            <div class="form-hint">One line under the picture. Say what it does, not what it is.</div>
        </div>

        <div class="field field-full">
            <div class="form-label">Screenshot</div>

            <div class="setting-image" data-image-field>
                <div class="setting-image-preview" data-file-preview>
                    @if ($image)
                        <img src="{{ $image }}" alt="{{ $row->title }}">
                    @else
                        <span class="text-xs text-muted">No image</span>
                    @endif
                </div>

                <div class="setting-image-controls">
                    <label for="sc-image" class="sr-only">Choose a screenshot</label>
                    <input id="sc-image" type="file" name="image"
                           accept="image/jpeg,image/png,image/webp" data-file-input>

                    {{-- Not required by the form, but the landing page skips a
                         row without one: a screenshot section is its
                         screenshots. Said plainly rather than left to be
                         discovered. --}}
                    <div class="form-hint">
                        Needed before it appears. A row with no image is kept but not shown.
                        Wide shots read better than tall ones.
                    </div>

                    @unless ($isNew)
                        <button type="button" class="btn btn-sm btn-ghost" data-image-remove
                                data-remove-url="{{ route('admin.showcases.image.destroy', $row->id) }}"
                                @unless ($image) hidden @endunless>
                            <x-icon name="trash" :size="13" /> Remove image
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
            {{ $isNew ? 'Add screenshot' : 'Save changes' }}
        </button>
    </div>
</form>
