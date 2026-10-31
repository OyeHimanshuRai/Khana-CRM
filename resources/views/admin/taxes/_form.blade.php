{{-- Add / Edit tax slab, rendered straight into the modal body. --}}

@php $isNew = ! $rate->exists; @endphp

<form method="POST"
      action="{{ $isNew ? route('admin.taxes.store') : route('admin.taxes.update', $rate) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="tax-name">Name</label>
            <input id="tax-name" type="text" name="name" class="form-control" required
                   value="{{ $rate->name }}" autocomplete="off" aria-invalid="false"
                   placeholder="GST 18%">
        </div>

        <div class="field">
            <label for="tax-rate">Total rate (%)</label>
            <input id="tax-rate" type="number" name="rate" class="form-control" required
                   value="{{ $rate->exists ? rtrim(rtrim((string) $rate->rate, '0'), '.') : '' }}"
                   min="0" max="100" step="0.001" aria-invalid="false">
            <div class="form-hint">
                Leave the three fields below blank and they will be worked out from this —
                half as CGST, half as SGST, the whole as IGST.
            </div>
        </div>

        <div class="field">
            <label for="tax-cgst">CGST (%)</label>
            <input id="tax-cgst" type="number" name="cgst" class="form-control"
                   value="{{ $rate->exists ? rtrim(rtrim((string) $rate->cgst, '0'), '.') : '' }}"
                   min="0" max="100" step="0.001" aria-invalid="false">
        </div>

        <div class="field">
            <label for="tax-sgst">SGST (%)</label>
            <input id="tax-sgst" type="number" name="sgst" class="form-control"
                   value="{{ $rate->exists ? rtrim(rtrim((string) $rate->sgst, '0'), '.') : '' }}"
                   min="0" max="100" step="0.001" aria-invalid="false">
        </div>

        <div class="field">
            <label for="tax-igst">IGST (%)</label>
            <input id="tax-igst" type="number" name="igst" class="form-control"
                   value="{{ $rate->exists ? rtrim(rtrim((string) $rate->igst, '0'), '.') : '' }}"
                   min="0" max="100" step="0.001" aria-invalid="false">
            <div class="form-hint">Charged when the place of supply is outside the shop's state.</div>
        </div>

        <div class="field">
            <label for="tax-cess">Cess (%)</label>
            <input id="tax-cess" type="number" name="cess" class="form-control"
                   value="{{ $rate->exists ? rtrim(rtrim((string) $rate->cess, '0'), '.') : '0' }}"
                   min="0" max="100" step="0.001" aria-invalid="false">
        </div>

        <div class="field">
            <label for="tax-order">Display order</label>
            <input id="tax-order" type="number" name="sort_order" class="form-control"
                   value="{{ $rate->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
        </div>

        <div class="field">
            <div class="form-label">Status</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $rate->is_active)>
                Active
            </label>
        </div>

        <div class="field field-full">
            <div class="form-label">Default</div>
            <label class="check">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" name="is_default" value="1" @checked($rate->is_default)>
                Use this slab for new products
            </label>
            <div class="form-hint">Only one slab can be the default; setting this clears the other.</div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create slab' : 'Save changes' }}
        </button>
    </div>
</form>
