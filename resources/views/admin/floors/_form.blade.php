{{-- Add / Edit dining area, rendered straight into the modal body. --}}

@php
    $isNew = ! $floor->exists;
    // Only choosable on create: moving an area afterwards would move its
    // tables, their QR codes and their history to another branch.
    $canChooseShop = $isNew && $shops->count() > 1;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.floors.store') : route('admin.floors.update', $floor) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        @if ($canChooseShop)
            <div class="field field-full">
                <label for="fl-shop">Outlet</label>
                <select id="fl-shop" name="shop_id" class="form-control" required aria-invalid="false">
                    @foreach ($shops as $shop)
                        <option value="{{ $shop->id }}"
                            @selected(App\Support\CurrentShop::id() === $shop->id)>
                            {{ $shop->name }} ({{ $shop->code }})
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">Fixed once saved — the tables here belong to this outlet.</div>
            </div>
        @elseif ($isNew)
            <input type="hidden" name="shop_id" value="{{ $shops->first()?->id }}">
        @endif

        <div class="field">
            <label for="fl-name">Name</label>
            <input id="fl-name" type="text" name="name" class="form-control" required
                   value="{{ $floor->name }}" autocomplete="off" aria-invalid="false"
                   maxlength="120" placeholder="Rooftop">
        </div>

        <div class="field">
            <label for="fl-code">Code</label>
            <input id="fl-code" type="text" name="code" class="form-control" required
                   value="{{ $floor->code }}" autocomplete="off" aria-invalid="false"
                   maxlength="12" placeholder="RT" style="text-transform:uppercase">
            <div class="form-hint">
                Starts every table code on this area — RT gives RT-01, RT-02. Printed on the KOT.
            </div>
        </div>

        <div class="field field-full">
            <label for="fl-description">Description</label>
            <input id="fl-description" type="text" name="description" class="form-control"
                   value="{{ $floor->description }}" autocomplete="off" aria-invalid="false"
                   maxlength="250" placeholder="Open-air, 6pm onwards">
        </div>

        <div class="field">
            <label for="fl-order">Display order</label>
            <input id="fl-order" type="number" name="sort_order" class="form-control"
                   value="{{ $floor->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
        </div>

        <div class="field">
            <div class="form-label">Status</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $floor->is_active)>
                Open
            </label>
            <div class="form-hint">
                Closing hides the area and its tables from the floor plan and the POS. Nothing is deleted.
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create area' : 'Save changes' }}
        </button>
    </div>
</form>
