{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. Drag & drop, the preview and the media buttons are delegated
    from crud-forms.js; the submit, the toasts and the inline field errors
    come from app.js.
--}}

@php
    $isNew = ! $event->exists;
    $image = $isNew ? null : $event->imageUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.events.store') : route('admin.events.update', $event) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Event</div>

        <div class="settings-grid">
            <div class="field">
                <label for="evt-title">Title</label>
                <input id="evt-title" type="text" name="title" class="form-control" required
                       value="{{ $event->title }}" autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="evt-name">Name</label>
                <input id="evt-name" type="text" name="name" class="form-control"
                       value="{{ $event->name }}" autocomplete="off" aria-invalid="false"
                       placeholder="Exhibition or venue name">
            </div>

            <div class="field">
                <label for="evt-timing">Timing</label>
                <input id="evt-timing" type="text" name="timing" class="form-control"
                       value="{{ $event->timing }}" autocomplete="off" aria-invalid="false"
                       placeholder="10:00 AM – 6:00 PM">
                <div class="form-hint">Free text, so wording like “Daily, 11am onwards” is kept as written.</div>
            </div>

            <div class="field">
                <label for="evt-booth">Booth No</label>
                <input id="evt-booth" type="text" name="booth_no" class="form-control"
                       value="{{ $event->booth_no }}" autocomplete="off" aria-invalid="false"
                       placeholder="A-14">
            </div>

            <div class="field">
                <label for="evt-from">From date</label>
                <input id="evt-from" type="date" name="from_date" class="form-control"
                       value="{{ $event->from_date?->format('Y-m-d') }}" aria-invalid="false">
            </div>

            <div class="field">
                <label for="evt-to">To date</label>
                <input id="evt-to" type="date" name="to_date" class="form-control"
                       value="{{ $event->to_date?->format('Y-m-d') }}" aria-invalid="false">
                <div class="form-hint">Must not be before the start date.</div>
            </div>

            <div class="field field-full">
                <div class="form-label">Status</div>
                <label class="check">
                    {{-- The hidden field is what makes an unticked box submit
                         a 0 rather than nothing at all. --}}
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $event->is_active)>
                    Active
                </label>
                <div class="form-hint">
                    Separate from the dates: a past event can stay active, and a future one can be hidden.
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Event image</div>

        {{-- The same drag & drop widget the Slider module uses. --}}
        <x-media-field
            name="image"
            label="Event image"
            kind="image"
            accept="image/svg+xml,image/png,image/jpeg"
            formats="SVG, PNG, JPG"
            note="Maximum 800 × 400px · up to 2 MB"
            :url="$image"
            :remove-url="$isNew ? null : route('admin.events.image.destroy', $event)"
        />
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create event' : 'Save changes' }}
        </button>
    </div>
</form>
