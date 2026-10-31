{{--
    Write something off.

    Four fields and a Save. This is filled in by somebody holding an empty
    tray, and every extra question is a reason to do it later, which means
    never.
--}}

<form method="POST" action="{{ route('admin.wastage.store') }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf

    @if ($products->isEmpty())
        <p class="text-sm">
            There is nothing to write off. Made-to-order dishes have no stock of their
            own — write off the ingredients that went into them instead.
        </p>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Close</button>
        </div>
    @else
        <div class="settings-grid">
            <div class="field field-full">
                <label for="wa-product">What</label>
                <select id="wa-product" name="product_id" class="form-control" required aria-invalid="false">
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">
                            {{ $product->name }}@if ($product->unit?->code) ({{ $product->unit->code }})@endif
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">
                    Ingredients and stocked goods. A dish the kitchen cooks has no
                    count of its own — write off what went into it.
                </div>
            </div>

            <div class="field">
                <label for="wa-qty">How much</label>
                <input id="wa-qty" type="number" name="quantity" class="form-control" required
                       min="0.0001" step="0.0001" aria-invalid="false" placeholder="0">
                <div class="form-hint">In the item's own unit.</div>
            </div>

            <div class="field">
                <label for="wa-reason">Why</label>
                <select id="wa-reason" name="reason_code" class="form-control" required aria-invalid="false">
                    @foreach ($reasons as $key => $label)
                        {{-- Spoiled first: it is both the commonest and the one
                             that most often means the store is over-ordering. --}}
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if ($warehouses->count() > 1)
                <div class="field">
                    <label for="wa-store">From</label>
                    <select id="wa-store" name="warehouse_id" class="form-control" aria-invalid="false">
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected($warehouse->is_default)>
                                {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="field field-full">
                <label for="wa-note">Note</label>
                <input id="wa-note" type="text" name="note" class="form-control" maxlength="250"
                       autocomplete="off" aria-invalid="false"
                       placeholder="Fridge failed overnight">
                <div class="form-hint">
                    Optional, and worth typing. In a month this is the difference between
                    a number and an explanation.
                </div>
            </div>
        </div>

        <p class="text-xs text-muted" style="margin:10px 0 0">
            It comes off the shelf straight away, valued at what this shop paid for it.
            Nothing here needs approving — waste that waits for a manager is waste
            that never gets written down.
        </p>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Write it off</button>
        </div>
    @endif
</form>
