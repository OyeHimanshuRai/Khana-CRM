{{--
    Swappable fragment: one evening's book, in time order.

    Not paginated. A service has tens of bookings, not thousands, and a host
    reading this at the door needs the whole evening on one screen rather than
    a page control between them and the name somebody just gave them.

    Overdue rows are marked because that is the single thing this screen
    exists to prevent: a party standing in the doorway while nobody can find
    their booking.

    The day's tiles ride along as an out-of-band template - see data-ajax-oob
    in public/assets/js/ajax-list.js - because they are counted from the same
    bookings this table draws and must not be allowed to disagree with it.
--}}

<template data-ajax-oob="[data-reservation-stats]">@include('admin.reservations._stats')</template>

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Time</th>
                <th>Guest</th>
                <th>Party</th>
                <th>Table</th>
                <th>Status</th>
                <th>Notes</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($reservations as $booking)
                @php $overdue = $booking->isOverdue($grace); @endphp
                <tr @if ($overdue) style="background: var(--warning-soft)" @endif>
                    <td class="text-sm">
                        <strong>{{ $booking->reserved_for->format('g:i a') }}</strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $booking->duration_minutes }} min
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.reservations.show', $booking) }}"
                               data-modal="{{ route('admin.reservations.show', $booking) }}"
                               data-modal-title="{{ $booking->guest_name }}"
                               data-modal-sub="{{ $booking->windowLabel() }}"
                               data-modal-size="lg">{{ $booking->guest_name }}</a>
                        </strong>
                        @if ($booking->guest_mobile)
                            <span class="text-xs text-muted" style="display:block">{{ $booking->guest_mobile }}</span>
                        @endif
                    </td>

                    <td class="text-sm">{{ $booking->party_size }}</td>

                    <td class="text-sm">
                        @if ($booking->table)
                            {{ $booking->table->name }}
                        @else
                            {{-- A booking with no table is complete and valid: most
                                 places decide the table on the night. --}}
                            <span class="text-muted">On the night</span>
                        @endif
                    </td>

                    <td>
                        <span class="badge badge-{{ $booking->statusTone() }}">
                            <span class="badge-dot"></span> {{ $booking->statusLabel() }}
                        </span>
                        @if ($overdue)
                            <span class="text-xs" style="display:block; color: var(--warning)">
                                {{ $booking->reserved_for->diffForHumans() }} — still here?
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ Str::limit($booking->notes, 44) ?: '—' }}
                        @if ($booking->outcome_note)
                            <span class="text-xs text-muted" style="display:block">{{ $booking->outcome_note }}</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('dining.reservations.adjust')
                                @if ($booking->status === App\Models\Reservation::REQUESTED)
                                    <form method="POST" action="{{ route('admin.reservations.confirm', $booking) }}"
                                          data-ajax data-refresh-list>
                                        @csrf @method('PUT')
                                        <button type="submit" class="btn btn-sm">Accept</button>
                                    </form>
                                @endif

                                @if ($booking->holdsATable() && $booking->status !== App\Models\Reservation::SEATED)
                                    <form method="POST" action="{{ route('admin.reservations.seat', $booking) }}"
                                          data-ajax data-refresh-list>
                                        @csrf @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-primary"
                                                @disabled($booking->restaurant_table_id === null)
                                                title="{{ $booking->restaurant_table_id === null ? 'Open the booking and choose a table first' : 'Seat them' }}">
                                            Seat
                                        </button>
                                    </form>
                                @endif

                                @if ($booking->status === App\Models\Reservation::SEATED)
                                    <form method="POST" action="{{ route('admin.reservations.complete', $booking) }}"
                                          data-ajax data-refresh-list>
                                        @csrf @method('PUT')
                                        <button type="submit" class="btn btn-sm">Close</button>
                                    </form>
                                @endif
                            @endallows

                            <a class="btn btn-icon" href="{{ route('admin.reservations.show', $booking) }}"
                               data-modal="{{ route('admin.reservations.show', $booking) }}"
                               data-modal-title="{{ $booking->guest_name }}"
                               data-modal-sub="{{ $booking->windowLabel() }}"
                               data-modal-size="lg"
                               aria-label="Open {{ $booking->guest_name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('dining.reservations.edit')
                                @unless ($booking->isClosed())
                                    <a class="btn btn-icon" href="{{ route('admin.reservations.edit', $booking) }}"
                                       data-modal="{{ route('admin.reservations.edit', $booking) }}"
                                       data-modal-title="Edit Booking"
                                       data-modal-sub="{{ $booking->guest_name }}"
                                       data-modal-size="lg"
                                       aria-label="Edit {{ $booking->guest_name }}">
                                        <x-icon name="edit" :size="15" />
                                    </a>
                                @endunless
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="calendar" :size="28" />
                            <h3>Nothing booked{{ $day->isToday() ? ' tonight' : ' that day' }}</h3>
                            <p class="text-sm">Take a booking, or pick another day.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
