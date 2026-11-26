@extends('shop.layouts.app')

@section('title', 'Your bookings')

@section('content')
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <h1 class="shop-h1" style="margin:0;">Your table bookings</h1>
        <span style="flex:1"></span>
        <a href="{{ route('shop.book', $shop) }}" class="shop-btn shop-btn-sm">Book a table</a>
    </div>

    @if ($reservations->isEmpty())
        <div class="shop-empty">You haven't booked a table yet. <a href="{{ route('shop.book', $shop) }}">Book one now</a>.</div>
    @else
        <div class="shop-panel">
            @foreach ($reservations as $booking)
                @php
                    $waiting = $booking->awaitingPayment();
                    $tone = match (true) {
                        $waiting => 'warning',
                        $booking->status === \App\Models\Reservation::CONFIRMED, $booking->status === \App\Models\Reservation::SEATED, $booking->status === \App\Models\Reservation::COMPLETED => 'success',
                        $booking->status === \App\Models\Reservation::CANCELLED, $booking->status === \App\Models\Reservation::NO_SHOW => 'danger',
                        default => 'muted',
                    };
                    $label = $waiting ? 'Awaiting payment' : ($booking->status === \App\Models\Reservation::CANCELLED && $booking->outcome_note === \App\Services\OnlineBookingService::EXPIRED_NOTE ? 'Not paid' : $booking->statusLabel());
                @endphp
                <div class="shop-order-row">
                    <div>
                        <div style="font-weight:600;">{{ $booking->reserved_for->format('D, d M Y · h:i A') }}</div>
                        <div class="shop-card-meta">{{ $booking->party_size }} {{ $booking->party_size === 1 ? 'guest' : 'guests' }} &middot; Booking #{{ $booking->id }}</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:14px;">
                        <span class="shop-badge shop-badge-{{ $tone }}">{{ $label }}</span>
                        @if ((float) $booking->deposit_amount > 0)
                            <span class="shop-price">₹{{ number_format((float) $booking->deposit_amount, 2) }}</span>
                        @endif
                        <a href="{{ route('shop.bookings.show', [$shop, $booking->id]) }}" class="shop-btn shop-btn-outline shop-btn-sm">{{ $waiting ? 'Pay now' : 'View' }}</a>
                    </div>
                </div>
            @endforeach
        </div>

        <div style="margin-top:20px;">{{ $reservations->links() }}</div>
    @endif
@endsection
