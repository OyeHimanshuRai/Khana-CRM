{{--
    "Restock", in the modal: add stock to an item from the item itself.

    For the everyday top-up (baked this morning, a crate came in) that does not
    need a purchase order. What it writes is recorded as a Restock movement with
    who and why - see StockRestockController.

    A dish is made to order and has no shelf, so for one this says so and offers
    what does work instead of a form that could only fail.
--}}

@php
    $unit = $product->unit?->code;
    $tracked = $product->tracksStock();
@endphp

<div class="pos-chosen">
    <div>
        <strong>{{ $product->name }}</strong>
        <span class="text-xs text-muted" style="display:block">
            {{ $product->sku }}
            @if ($tracked)
                · {{ rtrim(rtrim(number_format((float) $onHand, 3, '.', ''), '0'), '.') ?: '0' }} {{ $unit }} on hand now
            @endif
        </span>
    </div>
</div>

@if (! $tracked)
    <div class="empty" style="padding:28px 12px 10px">
        <h3>Made to order - no stock to add</h3>
        <p>
            {{ $product->name }} is cooked when it is ordered, so there is no shelf count to top up.
            To take it off the menu, mark it sold out. To keep it available, restock what it is made from.
        </p>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Close</button>
    </div>
@elseif ($shop === null)
    <p class="pos-warning" style="margin-top:12px">
        You are viewing all shops. Choose one shop from the switcher at the top, then restock - stock is added to one shop's shelf.
    </p>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Close</button>
    </div>
@else
    <form method="POST" action="{{ route('admin.stock.restock', $product) }}"
          data-ajax data-close-modal data-refresh-list>
        @csrf

        <div class="settings-grid" style="margin-top:12px">
            <div class="field">
                <label for="rs-qty">Quantity to add @if ($unit)({{ $unit }})@endif</label>
                <input id="rs-qty" type="number" name="quantity" class="form-control" required
                       min="0.001" step="any" inputmode="decimal" autofocus aria-invalid="false">
            </div>

            {{-- One store (most outlets): nothing to choose, so nothing to ask. --}}
            @if ($warehouses->count() === 1)
                <input type="hidden" name="warehouse_id" value="{{ $warehouses->first()->id }}">
            @else
                <div class="field">
                    <label for="rs-wh">Into warehouse</label>
                    <select id="rs-wh" name="warehouse_id" class="form-control" required>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected($warehouse->is_default || $loop->first && ! $warehouses->contains('is_default', true))>
                                {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($product->track_batches)
                <div class="field">
                    <label for="rs-batch">Batch number</label>
                    <input id="rs-batch" type="text" name="batch_no" class="form-control" required
                           maxlength="60" aria-invalid="false" placeholder="From the pack or delivery note">
                </div>

                <div class="field">
                    <label for="rs-exp">Best before</label>
                    <input id="rs-exp" type="date" name="expiry_date" class="form-control" aria-invalid="false">
                </div>
            @endif

            <div class="field">
                <label for="rs-cost">
                    Cost per {{ $unit ?: 'unit' }} (₹)
                    <x-info label="About cost">Leave blank to use this item's usual cost (₹{{ number_format((float) $product->purchase_price, 2) }}). The cost feeds the average that stock value and margins are worked out from, so enter it when it differs.</x-info>
                </label>
                <input id="rs-cost" type="number" name="unit_cost" class="form-control" min="0" step="0.01"
                       inputmode="decimal" placeholder="{{ number_format((float) $product->purchase_price, 2, '.', '') }}" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="rs-note">Note <span class="text-muted">(optional)</span></label>
                <input id="rs-note" type="text" name="note" class="form-control" maxlength="250"
                       aria-invalid="false" placeholder="Baked this morning, delivery from the bakery…">
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Add stock</button>
        </div>
    </form>
@endif
