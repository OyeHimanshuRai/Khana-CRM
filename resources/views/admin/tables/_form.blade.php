{{-- Add / Edit table, rendered straight into the modal body. --}}

@php
    $isNew = ! $table->exists;
@endphp

@if ($floors->isEmpty())
    <div class="empty">
        <x-icon name="building" :size="28" />
        <h3>No dining areas yet</h3>
        <p class="text-sm">Every table belongs to one. Create an area first.</p>
        @allows('dining.floors.create')
            <a class="btn btn-primary btn-sm" href="{{ route('admin.floors.index') }}">Go to Dining Areas</a>
        @endallows
    </div>
@else
    <form method="POST"
          action="{{ $isNew ? route('admin.tables.store') : route('admin.tables.update', $table) }}"
          data-ajax data-close-modal data-refresh-list>
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        {{-- The area carries the outlet, so there is no separate shop field. --}}
        @if ($isNew)
            <input type="hidden" name="shop_id" value="{{ App\Support\CurrentShop::idForWrite() }}">
        @endif

        <div class="settings-grid">
            <div class="field">
                <label for="tb-floor">Dining area</label>
                <select id="tb-floor" name="floor_id" class="form-control" required aria-invalid="false">
                    @foreach ($floors as $floor)
                        <option value="{{ $floor->id }}" @selected((int) $table->floor_id === $floor->id)>
                            {{ $floor->name }}@unless ($floor->is_active) (closed)@endunless
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="tb-name">Table</label>
                <input id="tb-name" type="text" name="name" class="form-control" required
                       value="{{ $table->name }}" autocomplete="off" aria-invalid="false"
                       maxlength="40" placeholder="12">
                <div class="form-hint">What the staff call out. Unique within the area.</div>
            </div>

            <div class="field">
                <label for="tb-code">Code</label>
                <input id="tb-code" type="text" name="code" class="form-control" required
                       value="{{ $table->code }}" autocomplete="off" aria-invalid="false"
                       maxlength="24" placeholder="GF-12" style="text-transform:uppercase">
                <div class="form-hint">
                    Goes in the QR and on the KOT. Never reuse one — an old sticker would resolve here.
                </div>
            </div>

            <div class="field">
                <label for="tb-capacity">Seats</label>
                <input id="tb-capacity" type="number" name="capacity" class="form-control" required
                       value="{{ $table->capacity ?? 4 }}" min="1" max="60" aria-invalid="false">
            </div>

            <div class="field">
                <label for="tb-status">Status</label>
                <select id="tb-status" name="status" class="form-control" aria-invalid="false">
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}"
                            @selected(($table->status ?? App\Models\RestaurantTable::AVAILABLE) === $key)>
                            {{ $meta['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="tb-order">Display order</label>
                <input id="tb-order" type="number" name="sort_order" class="form-control"
                       value="{{ $table->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="tb-note">Note</label>
                <input id="tb-note" type="text" name="note" class="form-control"
                       value="{{ $table->note }}" autocomplete="off" aria-invalid="false"
                       maxlength="250" placeholder="Window seat · booked 8pm">
            </div>

            <div class="field field-full">
                <div class="form-label">Service</div>
                <label class="check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $table->is_active)>
                    In service
                </label>
                <div class="form-hint">
                    Out of service hides the table from the plan and the POS. Its QR code keeps working —
                    withdraw that separately from the QR screen if the table is gone for good.
                </div>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">
                {{ $isNew ? 'Create table' : 'Save changes' }}
            </button>
        </div>
    </form>
@endif
