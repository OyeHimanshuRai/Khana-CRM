{{--
    One booking, and the things a host does with it.

    The seat form is a table picker rather than a plain button, because the
    commonest booking has no table on it until the party is standing there.
--}}

@php
    $overdue = $reservation->isOverdue($grace);
@endphp

<div class="sec-name">{{ $reservation->guest_name }}</div>
<div class="text-sm text-muted">
    {{ $reservation->reserved_for->format('l, j F') }} · {{ $reservation->windowLabel() }}
</div>

<div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
    <span class="badge badge-{{ $reservation->statusTone() }}">
        <span class="badge-dot"></span> {{ $reservation->statusLabel() }}
    </span>
    <span class="badge badge-info">{{ $reservation->party_size }} covers</span>
    <span class="badge badge-muted">{{ $reservation->sourceLabel() }}</span>
</div>

@if ($overdue)
    <div class="alert alert-warning" style="margin-bottom:14px">
        <strong>Past their time.</strong>
        Booked for {{ $reservation->reserved_for->format('g:i a') }}, which was
        {{ $reservation->reserved_for->diffForHumans() }}. Nothing has been decided for them —
        seat them if they are here, or record a no-show. The system will not guess.
    </div>
@endif

@if ($reservation->notes)
    <div class="alert alert-info" style="margin-bottom:14px">
        {{ $reservation->notes }}
    </div>
@endif

<dl class="sec-facts">
    <div><dt>Mobile</dt><dd>{{ $reservation->guest_mobile ?: '—' }}</dd></div>
    <div><dt>Email</dt><dd>{{ $reservation->guest_email ?: '—' }}</dd></div>
    <div>
        <dt>Table</dt>
        <dd>{{ $reservation->table?->name ?? 'Decided on the night' }}</dd>
    </div>
    <div><dt>Holding for</dt><dd>{{ $reservation->duration_minutes }} minutes</dd></div>
    @if ($reservation->seated_at)
        <div><dt>Seated</dt><dd>{{ $reservation->seated_at->format('g:i a') }}</dd></div>
    @endif
    @if ($reservation->closed_at)
        <div><dt>Closed</dt><dd>{{ $reservation->closed_at->format('g:i a') }}</dd></div>
    @endif
    @if ($reservation->outcome_note)
        <div><dt>What happened</dt><dd>{{ $reservation->outcome_note }}</dd></div>
    @endif
    <div><dt>Taken by</dt><dd>{{ $reservation->creator?->name ?? '—' }}</dd></div>
</dl>

@allows('dining.reservations.adjust')
    @unless ($reservation->isClosed())
        @if ($reservation->status !== App\Models\Reservation::SEATED)
            <div class="form-section" style="margin-top:14px">
                <div class="form-section-title">They have arrived</div>

                {{--
                    The booked table can have somebody else at it by the time
                    the party walks in - a sitting that overran, or a table
                    nobody closed. Seating onto it would rename their bill, so
                    the service refuses it; this says so before the host picks.
                --}}
                @if ($bookedSitting)
                    <div class="alert alert-warning" style="margin-bottom:12px">
                        <strong>{{ $reservation->table?->name ?? 'That table' }} is not free.</strong>
                        {{ $bookedSitting->partyName() }} has been sitting there
                        {{ $bookedSitting->seatedMinutes() }} minutes with the bill still open.
                        Settle and close that sitting from
                        <a href="{{ route('admin.table-bills.show', $bookedSitting) }}">Table Bills</a>,
                        or seat {{ $reservation->guest_name }} at another table below.
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.reservations.seat', $reservation) }}"
                      data-ajax data-close-modal data-refresh-list>
                    @csrf @method('PUT')

                    <div class="settings-grid">
                        <div class="field">
                            <label for="seat-table">Seat them at</label>
                            <select id="seat-table" name="restaurant_table_id" class="form-control">
                                @if ($reservation->table && ! $bookedSitting)
                                    <option value="{{ $reservation->table->id }}" selected>
                                        {{ $reservation->table->code }} · {{ $reservation->table->name }} (as booked)
                                    </option>
                                @endif
                                @foreach ($free as $table)
                                    @continue($table->id === $reservation->restaurant_table_id && ! $bookedSitting)
                                    <option value="{{ $table->id }}">
                                        {{ $table->code }} · {{ $table->name }} ({{ $table->capacity }} seats)
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-hint">
                                Only tables free for this window with nobody sitting at them,
                                big enough for {{ $reservation->party_size }}.
                            </div>
                        </div>

                        <div class="field" style="display:flex; align-items:flex-end">
                            <button type="submit" class="btn btn-primary">Seat them</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="form-section">
                <div class="form-section-title">They did not come</div>

                <form method="POST" action="{{ route('admin.reservations.no-show', $reservation) }}"
                      data-ajax data-close-modal data-refresh-list
                      onsubmit="return confirm('Record {{ $reservation->guest_name }} as a no-show? The table is freed for somebody else.')">
                    @csrf @method('PUT')

                    <div class="field field-full">
                        <label for="ns-note">Anything worth remembering</label>
                        <input id="ns-note" type="text" name="outcome_note" class="form-control"
                               maxlength="190" placeholder="Rang at 8:40 — no answer">
                    </div>

                    <div style="margin-top:10px; display:flex; gap:8px">
                        <button type="submit" class="btn is-danger">Record a no-show</button>
                    </div>
                </form>
            </div>
        @else
            <div class="alert alert-info" style="margin-top:14px">
                <strong>Seated{{ $reservation->table ? ' at table '.$reservation->table->name : '' }}.</strong>
                Their sitting is open, so anything they order lands on one bill.
            </div>
        @endif
    @endunless
@endallows

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('dining.reservations.adjust')
        @if ($reservation->status === App\Models\Reservation::REQUESTED)
            <form method="POST" action="{{ route('admin.reservations.confirm', $reservation) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline">
                @csrf @method('PUT')
                <button type="submit" class="btn">Accept the request</button>
            </form>
        @endif

        @if ($reservation->status === App\Models\Reservation::SEATED)
            <form method="POST" action="{{ route('admin.reservations.complete', $reservation) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline">
                @csrf @method('PUT')
                <button type="submit" class="btn">Close the booking</button>
            </form>
        @endif

        @unless ($reservation->isClosed() || $reservation->status === App\Models\Reservation::SEATED)
            <form method="POST" action="{{ route('admin.reservations.cancel', $reservation) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline"
                  onsubmit="return confirm('Cancel {{ $reservation->guest_name }}’s booking?')">
                @csrf @method('PUT')
                <button type="submit" class="btn is-danger">Cancel booking</button>
            </form>
        @endunless
    @endallows

    @allows('dining.reservations.edit')
        @unless ($reservation->isClosed())
            <a class="btn btn-primary" href="{{ route('admin.reservations.edit', $reservation) }}"
               data-modal="{{ route('admin.reservations.edit', $reservation) }}"
               data-modal-title="Edit Booking"
               data-modal-sub="{{ $reservation->guest_name }}"
               data-modal-size="lg">Edit</a>
        @endunless
    @endallows
</div>
