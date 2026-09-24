{{-- Add / edit an outlet type (§19), rendered into the modal body. --}}

@php
    $isNew = ! $row->exists;
    $currentIcon = $row->icon ?: 'cart';
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.outlet-types.store') : route('admin.outlet-types.update', $row->id) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="ot-name">Name</label>
            <input id="ot-name" type="text" name="name" class="form-control" required
                   value="{{ $row->name }}" maxlength="90" autocomplete="off" aria-invalid="false"
                   placeholder="Quick service, Cloud kitchen, Cafe…">
        </div>

        <div class="field">
            <label for="ot-order">Display order</label>
            <input id="ot-order" type="number" name="sort_order" class="form-control"
                   value="{{ $row->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
        </div>

        <div class="field field-full">
            <label for="ot-blurb">One line about it</label>
            <input id="ot-blurb" type="text" name="blurb" class="form-control"
                   value="{{ $row->blurb }}" maxlength="200" autocomplete="off" aria-invalid="false"
                   placeholder="Counter queues, token numbers and fast turnaround.">
        </div>

        <div class="field field-full">
            <label for="ot-icon">Icon</label>
            {{--
                A select over the icon map, not a free-text box and not an
                upload. These render six or nine at a time and want to look
                like one set; uploaded images never do. The value is validated
                against the same map server-side, because the icon component
                echoes its markup unescaped.
            --}}
            <select id="ot-icon" name="icon" class="form-control" required aria-invalid="false">
                @foreach ($icons as $iconName)
                    <option value="{{ $iconName }}" @selected($currentIcon === $iconName)>{{ $iconName }}</option>
                @endforeach
            </select>

            <div class="form-hint">Pick one that reads at a glance — the label does the explaining.</div>

            <div class="icon-picker-source" hidden aria-hidden="true">
                @foreach ($icons as $iconName)
                    <span data-icon-option="{{ $iconName }}"><x-icon :name="$iconName" :size="20" /></span>
                @endforeach
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
            {{ $isNew ? 'Add outlet type' : 'Save changes' }}
        </button>
    </div>
</form>
