{{--
    Take or change a booking, rendered into the modal body.

    No <script> here - markup injected via innerHTML never runs its scripts.

    The table is optional and says so. That is not laziness: most restaurants
    decide which table a party sits at on the night, by looking at the room,
    and a form that insisted would either invent a false booking on a table
    that then gets moved, or make the booking impossible to take.
--}}

@php
    $isNew = ! $reservation->exists;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.reservations.store') : route('admin.reservations.update', $reservation) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Who</div>

        <div class="settings-grid">
            <div class="field">
                <label for="res-name">Name</label>
                <input id="res-name" type="text" name="guest_name" class="form-control" required
                       value="{{ $reservation->guest_name }}" maxlength="120"
                       autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="res-mobile">Mobile</label>
                <input id="res-mobile" type="tel" name="guest_mobile" class="form-control"
                       value="{{ $reservation->guest_mobile }}" maxlength="30"
                       autocomplete="off" aria-invalid="false">
                <div class="form-hint">So somebody can ring if the evening runs over.</div>
            </div>

            <div class="field">
                <label for="res-email">Email</label>
                <input id="res-email" type="email" name="guest_email" class="form-control"
                       value="{{ $reservation->guest_email }}" maxlength="150"
                       autocomplete="off" aria-invalid="false">
            </div>

            <div class="field">
                <label for="res-party">Party size</label>
                <input id="res-party" type="number" name="party_size" class="form-control" required
                       value="{{ $reservation->party_size ?? 2 }}" min="1" max="200" aria-invalid="false">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">When</div>

        <div class="settings-grid">
            <div class="field">
                <label for="res-at">Date and time</label>
                <input id="res-at" type="datetime-local" name="reserved_for" class="form-control" required
                       value="{{ optional($reservation->reserved_for)->format('Y-m-d\TH:i') }}"
                       aria-invalid="false">
            </div>

            <div class="field">
                <label for="res-duration">Hold the table for</label>
                <select id="res-duration" name="duration_minutes" class="form-control" required aria-invalid="false">
                    @foreach ([60, 90, 120, 150, 180, 240] as $minutes)
                        <option value="{{ $minutes }}" @selected(($reservation->duration_minutes ?? 90) === $minutes)>
                            {{ $minutes >= 60 ? intdiv($minutes, 60).'h' : '' }}{{ $minutes % 60 ? ' '.($minutes % 60).'m' : '' }}
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">
                    Used to work out whether a table is double-booked. Back-to-back sittings are
                    fine — a table freed at 9:30 can be promised again from 9:30.
                </div>
            </div>

            <div class="field">
                <label for="res-table">Table</label>
                {{--
                    Grouped by floor, and named by code.

                    Every floor starts numbering at one, so a bare "1" is three
                    different tables in this building. The host is on the phone
                    and cannot go and look at the plan, so the option has to say
                    which one it is on its own.
                --}}
                <select id="res-table" name="restaurant_table_id" class="form-control" aria-invalid="false">
                    <option value="">Decide on the night</option>
                    @foreach ($tables->groupBy(fn ($table) => $table->floor?->name ?: 'Unassigned') as $floorName => $floorTables)
                        <optgroup label="{{ $floorName }}">
                            @foreach ($floorTables as $table)
                                <option value="{{ $table->id }}"
                                        @selected($reservation->restaurant_table_id === $table->id)>
                                    {{ $table->code }} · {{ $table->name }} ({{ $table->capacity }} seats)@if ($table->is_occupied_now) — occupied now @endif
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <div class="form-hint">
                    Optional. A booking with no table is a real booking and clashes with nobody.
                    A table marked <strong>occupied now</strong> has a party at it with an open
                    bill — it can be promised for later, but not for the next few minutes.
                </div>
            </div>

            <div class="field">
                <label for="res-source">Taken by</label>
                <select id="res-source" name="source" class="form-control" aria-invalid="false">
                    <option value="phone" @selected(($reservation->source ?? 'phone') === 'phone')>Phone</option>
                    <option value="walk_in" @selected(($reservation->source ?? '') === 'walk_in')>Walk-in</option>
                    <option value="online" @selected(($reservation->source ?? '') === 'online')>Online</option>
                    <option value="staff" @selected(($reservation->source ?? '') === 'staff')>Staff</option>
                </select>
            </div>

            @if ($isNew)
                <div class="field">
                    <label for="res-status">Status</label>
                    <select id="res-status" name="status" class="form-control" aria-invalid="false">
                        <option value="confirmed" selected>Confirmed — the table is promised</option>
                        <option value="requested">Requested — not promised yet</option>
                    </select>
                </div>
            @endif
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Notes</div>

        <div class="field field-full">
            <label for="res-notes" class="sr-only">Booking notes</label>
            <textarea id="res-notes" name="notes" class="form-control" rows="3" maxlength="2000"
                      placeholder="Window seat if possible · birthday, candle on the cake · wheelchair access"
                      aria-invalid="false">{{ $reservation->notes }}</textarea>
            <div class="form-hint">
                What the host needs to know before the party walks in.
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Take booking' : 'Save changes' }}
        </button>
    </div>
</form>
