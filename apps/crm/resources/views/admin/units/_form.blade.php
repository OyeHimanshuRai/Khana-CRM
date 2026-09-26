{{-- Add / Edit unit, rendered straight into the modal body. --}}

@php $isNew = ! $unit->exists; @endphp

<form method="POST"
      action="{{ $isNew ? route('admin.units.store') : route('admin.units.update', $unit) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="unit-name">Name</label>
            <input id="unit-name" type="text" name="name" class="form-control" required
                   value="{{ $unit->name }}" autocomplete="off" aria-invalid="false"
                   placeholder="Kilogram">
        </div>

        <div class="field">
            <label for="unit-code">Code</label>
            <input id="unit-code" type="text" name="code" class="form-control" required
                   value="{{ $unit->code }}" autocomplete="off" aria-invalid="false"
                   maxlength="12" placeholder="KG" style="text-transform:uppercase">
            <div class="form-hint">Short. This is what prints on invoices and shelf labels.</div>
        </div>

        <div class="field field-full">
            <div class="form-label">Quantities</div>
            <label class="check">
                <input type="hidden" name="allow_decimal" value="0">
                <input type="checkbox" name="allow_decimal" value="1" @checked($unit->allow_decimal)>
                Allow fractional quantities
            </label>
            <div class="form-hint">
                Leave off for things counted one by one. With it off the counter cannot bill
                &ldquo;2.5 sprayers&rdquo;; with it on it can bill 2.5&nbsp;kg of urea.
            </div>
        </div>

        <div class="field">
            <label for="unit-precision">Decimal places</label>
            <input id="unit-precision" type="number" name="precision" class="form-control"
                   value="{{ $unit->precision ?? 2 }}" min="0" max="4" aria-invalid="false">
            <div class="form-hint">Ignored unless fractional quantities are allowed.</div>
        </div>

        <div class="field">
            <label for="unit-order">Display order</label>
            <input id="unit-order" type="number" name="sort_order" class="form-control"
                   value="{{ $unit->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
        </div>

        <div class="field field-full">
            <div class="form-label">Status</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $unit->is_active)>
                Active
            </label>
            <div class="form-hint">Inactive units stay on existing products but cannot be chosen for new ones.</div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create unit' : 'Save changes' }}
        </button>
    </div>
</form>
