{{-- Add / Edit warehouse, rendered straight into the modal body. --}}

@php
    $isNew = ! $warehouse->exists;
    // The shop is only choosable on create: moving a warehouse afterwards
    // would move its stock to another tenant without saying so.
    $canChooseShop = $isNew && $shops->count() > 1;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.warehouses.store') : route('admin.warehouses.update', $warehouse) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        @if ($canChooseShop)
            <div class="field field-full">
                <label for="wh-shop">Shop</label>
                <select id="wh-shop" name="shop_id" class="form-control" required aria-invalid="false">
                    @foreach ($shops as $shop)
                        <option value="{{ $shop->id }}"
                            @selected(App\Support\CurrentShop::id() === $shop->id)>
                            {{ $shop->name }} ({{ $shop->code }})
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">Fixed once saved — stock filed here belongs to this shop.</div>
            </div>
        @elseif ($isNew)
            <input type="hidden" name="shop_id" value="{{ $shops->first()?->id }}">
        @endif

        <div class="field">
            <label for="wh-name">Name</label>
            <input id="wh-name" type="text" name="name" class="form-control" required
                   value="{{ $warehouse->name }}" autocomplete="off" aria-invalid="false"
                   placeholder="Main Store">
        </div>

        <div class="field">
            <label for="wh-code">Code</label>
            <input id="wh-code" type="text" name="code" class="form-control" required
                   value="{{ $warehouse->code }}" autocomplete="off" aria-invalid="false"
                   maxlength="20" placeholder="MAIN" style="text-transform:uppercase">
            <div class="form-hint">Unique within the shop.</div>
        </div>

        <div class="field field-full">
            <label for="wh-address">Address</label>
            <input id="wh-address" type="text" name="address" class="form-control"
                   value="{{ $warehouse->address }}" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field">
            <label for="wh-city">City</label>
            <input id="wh-city" type="text" name="city" class="form-control"
                   value="{{ $warehouse->city }}" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field">
            <label for="wh-phone">Phone</label>
            <input id="wh-phone" type="text" name="phone" class="form-control"
                   value="{{ $warehouse->phone }}" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field">
            <label for="wh-order">Display order</label>
            <input id="wh-order" type="number" name="sort_order" class="form-control"
                   value="{{ $warehouse->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
        </div>

        <div class="field">
            <div class="form-label">Status</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $warehouse->is_active)>
                Active
            </label>
        </div>

        <div class="field field-full">
            <div class="form-label">Default</div>
            <label class="check">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" name="is_default" value="1"
                       @checked($isNew ? false : $warehouse->is_default)>
                Stock lands here when no location is chosen
            </label>
            <div class="form-hint">
                Only one warehouse per shop can be the default; setting this clears the other.
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create warehouse' : 'Save changes' }}
        </button>
    </div>
</form>
