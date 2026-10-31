{{--
    Add / edit a trust number (§19), rendered into the modal body.

    The one form here that argues with the person filling it in. A typed number
    is right on the day it is typed and slowly stops being; a counted one
    cannot be. So the counted sources come first and the free-text box is
    framed as the fallback.
--}}

@php
    $isNew = ! $row->exists;
    $currentSource = $row->source;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.landing-stats.store') : route('admin.landing-stats.update', $row->id) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="ls-label">Label</label>
            <input id="ls-label" type="text" name="label" class="form-control" required
                   value="{{ $row->label }}" maxlength="90" autocomplete="off" aria-invalid="false"
                   placeholder="Outlets running on it, Years in business…">
            <div class="form-hint">The words under the number.</div>
        </div>

        <div class="field">
            <label for="ls-order">Display order</label>
            <input id="ls-order" type="number" name="sort_order" class="form-control"
                   value="{{ $row->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
        </div>

        <div class="field field-full">
            <label for="ls-source">Count it automatically</label>
            <select id="ls-source" name="source" class="form-control" aria-invalid="false">
                <option value="">No — I will type the number myself</option>
                @foreach ($sources as $key => $label)
                    <option value="{{ $key }}" @selected($currentSource === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="form-hint">
                Pick a source and the page counts it fresh on every visit. A number the
                software works out cannot be out of date by the time somebody reads it.
            </div>
        </div>

        <div class="field field-full">
            <label for="ls-value">Or type it</label>
            <input id="ls-value" type="text" name="value" class="form-control"
                   value="{{ $row->value }}" maxlength="40" autocomplete="off" aria-invalid="false"
                   placeholder="24/7, 99.9%, 1,50,000+">
            <div class="form-hint">
                Used when no source is chosen above — and as the fallback if a counted
                source cannot be read. Text is fine: “24/7” is not a number.
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
            {{ $isNew ? 'Add number' : 'Save changes' }}
        </button>
    </div>
</form>
