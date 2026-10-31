{{-- Add / Edit kitchen station, rendered straight into the modal body. --}}

@php
    $isNew = ! $station->exists;
    // Only choosable on create: a tandoor is a physical thing in one branch,
    // and moving it afterwards would move its routing and its ticket history.
    $canChooseShop = $isNew && $shops->count() > 1;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.kitchen-stations.store') : route('admin.kitchen-stations.update', $station) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        @if ($canChooseShop)
            <div class="field field-full">
                <label for="ks-shop">Outlet</label>
                <select id="ks-shop" name="shop_id" class="form-control" required aria-invalid="false">
                    @foreach ($shops as $shop)
                        <option value="{{ $shop->id }}"
                            @selected(App\Support\CurrentShop::id() === $shop->id)>
                            {{ $shop->name }} ({{ $shop->code }})
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">Fixed once saved — this station's tickets belong to this outlet.</div>
            </div>
        @elseif ($isNew)
            <input type="hidden" name="shop_id" value="{{ $shops->first()?->id }}">
        @endif

        <div class="field">
            <label for="ks-name">Name</label>
            <input id="ks-name" type="text" name="name" class="form-control" required
                   value="{{ $station->name }}" autocomplete="off" aria-invalid="false"
                   maxlength="120" placeholder="Tandoor">
        </div>

        <div class="field">
            <label for="ks-code">Code</label>
            <input id="ks-code" type="text" name="code" class="form-control" required
                   value="{{ $station->code }}" autocomplete="off" aria-invalid="false"
                   maxlength="12" placeholder="TAN" style="text-transform:uppercase">
            <div class="form-hint">Printed on the KOT slip. Short — a cook reads it across a hot line.</div>
        </div>

        <div class="field field-full">
            <label for="ks-description">Description</label>
            <input id="ks-description" type="text" name="description" class="form-control"
                   value="{{ $station->description }}" autocomplete="off" aria-invalid="false"
                   maxlength="250" placeholder="Kebabs, breads and anything off the coal">
        </div>

        <div class="field">
            <label for="ks-prep">Expected time (minutes)</label>
            <input id="ks-prep" type="number" name="prep_minutes" class="form-control"
                   value="{{ $station->prep_minutes ?? 15 }}" min="1" max="600" aria-invalid="false">
            <div class="form-hint">
                After this, a ticket turns red on the board. A bar and a tandoor
                should not share a number.
            </div>
        </div>

        <div class="field">
            <label for="ks-order">Display order</label>
            <input id="ks-order" type="number" name="sort_order" class="form-control"
                   value="{{ $station->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            <div class="form-hint">The order the tabs sit in on the kitchen display.</div>
        </div>

        <div class="field">
            <div class="form-label">Default station</div>
            <label class="check">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" name="is_default" value="1" @checked($station->is_default)>
                Cook anything nobody routed here
            </label>
            <div class="form-hint">
                One per outlet. Without it a dish added on a Friday by somebody
                who did not think about stations would be cooked by nobody.
            </div>
        </div>

        <div class="field">
            <div class="form-label">Status</div>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $station->is_active)>
                In service
            </label>
            <div class="form-hint">
                Closing it keeps its routing intact — new dishes fall through to
                the default station until it reopens.
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create station' : 'Save changes' }}
        </button>
    </div>
</form>
