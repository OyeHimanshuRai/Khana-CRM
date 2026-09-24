{{-- Add / Edit batch, rendered straight into the modal body. --}}

@php $isNew = ! $batch->exists; @endphp

<form method="POST"
      action="{{ $isNew ? route('admin.batches.store') : route('admin.batches.update', $batch) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    @if ($isNew)
        <p class="text-xs text-muted" style="margin:0 0 12px">
            Batches are normally created for you when goods are received. Add one by hand only for
            opening stock, or for a lot that arrived before the system did — this form registers the
            lot but does not put any quantity on the shelf.
        </p>
    @endif

    <div class="settings-grid">
        @if ($isNew)
            <div class="field field-full">
                <label for="batch-product">Product</label>
                <select id="batch-product" name="product_id" class="form-control" required aria-invalid="false">
                    <option value="">Choose a product…</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">
                            {{ $product->name }} ({{ $product->sku }}){{ $product->track_batches ? '' : ' — not batch tracked' }}
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">Fixed once saved — stock rows point at the pair.</div>
            </div>
        @else
            <div class="field field-full">
                <div class="form-label">Product</div>
                <div class="form-control" style="background:var(--panel-alt);pointer-events:none">
                    {{ $batch->product?->name }} ({{ $batch->product?->sku }})
                </div>
            </div>
        @endif

        <div class="field">
            <label for="batch-no">Batch number</label>
            <input id="batch-no" type="text" name="batch_no" class="form-control" required
                   value="{{ $batch->batch_no }}" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field">
            <label for="batch-supplier-ref">Supplier reference</label>
            <input id="batch-supplier-ref" type="text" name="supplier_batch_ref" class="form-control"
                   value="{{ $batch->supplier_batch_ref }}" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field">
            <label for="batch-mfg">Manufactured on</label>
            <input id="batch-mfg" type="date" name="mfg_date" class="form-control"
                   value="{{ $batch->mfg_date?->toDateString() }}" aria-invalid="false">
        </div>

        <div class="field">
            <label for="batch-expiry">Expires on</label>
            <input id="batch-expiry" type="date" name="expiry_date" class="form-control"
                   value="{{ $batch->expiry_date?->toDateString() }}" aria-invalid="false">
            <div class="form-hint">
                Leave blank for anything that does not expire. Dated lots are sold oldest-first.
            </div>
        </div>

        <div class="field">
            <label for="batch-purchase">Purchase price (₹)</label>
            <input id="batch-purchase" type="number" name="purchase_price" class="form-control"
                   value="{{ $batch->exists ? (float) $batch->purchase_price : 0 }}"
                   min="0" step="0.01" aria-invalid="false">
            <div class="form-hint">What this lot cost. Feeds stock valuation and margin.</div>
        </div>

        <div class="field">
            <label for="batch-mrp">MRP (₹)</label>
            <input id="batch-mrp" type="number" name="mrp" class="form-control"
                   value="{{ $batch->exists ? (float) $batch->mrp : 0 }}"
                   min="0" step="0.01" aria-invalid="false">
        </div>

        <div class="field">
            <label for="batch-selling">Selling price (₹)</label>
            <input id="batch-selling" type="number" name="selling_price" class="form-control"
                   value="{{ $batch->exists ? (float) $batch->selling_price : 0 }}"
                   min="0" step="0.01" aria-invalid="false">
            <div class="form-hint">Leave at 0 to use the product's price.</div>
        </div>

        <div class="field field-full">
            <div class="form-label">Sellable</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $batch->is_active)>
                This lot may be sold
            </label>
            <div class="form-hint">
                Untick for a recall or a failed check. The stock still counts and still has to be
                reconciled — it simply cannot leave the counter.
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Register batch' : 'Save changes' }}
        </button>
    </div>
</form>
