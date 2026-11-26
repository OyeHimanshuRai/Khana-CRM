@extends('shop.layouts.app')

@section('title', 'Booking #'.$reservation->id)

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/shop-booking.css') }}?v={{ filemtime(public_path('assets/css/shop-booking.css')) }}">
@endpush

@section('content')
    @php
        $R = \App\Models\Reservation::class;
        $waiting = $reservation->awaitingPayment();
        $expired = $reservation->status === $R::CANCELLED && $reservation->outcome_note === \App\Services\OnlineBookingService::EXPIRED_NOTE;
        $paid = $payments->firstWhere('status', \App\Models\PaymentIntent::PAID) ?? $payments->firstWhere('status', \App\Models\PaymentIntent::REFUNDED);
        $lastFailure = $payments->firstWhere('status', \App\Models\PaymentIntent::FAILED);
        $canCancel = in_array($reservation->status, [$R::REQUESTED, $R::CONFIRMED], true);
        $hoursLeft = \App\Support\OnlineBooking::now($shop)->diffInMinutes($reservation->reserved_for, false) / 60;
        $insideWindow = $reservation->status === $R::CONFIRMED && $hoursLeft < (int) $settings['cancel_hours'];
    @endphp

    <div class="shop-panel shop-panel-pad" style="max-width:640px;margin:0 auto;">
        <h1 class="shop-h1" style="margin-top:0;">Booking #{{ $reservation->id }}</h1>

        @if ($waiting)
            <div class="book-status book-status-wait">
                Your table is held until {{ $reservation->hold_expires_at->setTimezone($shop->timezone ?: 'Asia/Kolkata')->format('h:i A') }}. Pay the advance to confirm it.
                @if ($lastFailure && $lastFailure->failure_reason) <br>Last attempt: {{ $lastFailure->failure_reason }} @endif
            </div>
        @elseif ($reservation->status === $R::CONFIRMED)
            <div class="book-status book-status-ok">Confirmed. We look forward to seeing you!</div>
        @elseif ($expired)
            <div class="book-status book-status-bad">The advance was not paid in time, so the table was released. Please book again.</div>
        @elseif ($reservation->status === $R::CANCELLED)
            <div class="book-status book-status-bad">Cancelled. {{ $reservation->outcome_note }}</div>
        @else
            <div class="book-status book-status-ok">{{ $reservation->statusLabel() }}</div>
        @endif

        <div class="book-status" data-message hidden></div>

        <div class="book-meta">
            <div><small>Date</small><b>{{ $reservation->reserved_for->format('l, d M Y') }}</b></div>
            <div><small>Time</small><b>{{ $reservation->reserved_for->format('h:i A') }}</b></div>
            <div><small>Guests</small><b>{{ $reservation->party_size }}</b></div>
            <div><small>Name</small><b>{{ $reservation->guest_name }}</b></div>
            <div><small>Mobile</small><b>{{ $reservation->guest_mobile }}</b></div>
            @if ((float) $reservation->deposit_amount > 0)
                <div><small>Advance</small><b>₹{{ number_format((float) $reservation->deposit_amount, 2) }}</b></div>
            @endif
            @if ($paid)
                <div><small>Payment</small><b>{{ $paid->status === \App\Models\PaymentIntent::REFUNDED ? 'Refunded' : 'Paid' }} · {{ $paid->provider_payment_id }}</b></div>
            @endif
        </div>

        @if (filled($reservation->notes))
            <p class="book-note">Request: {{ $reservation->notes }}</p>
        @endif

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
            @if ($waiting)
                <button type="button" class="shop-btn" data-pay-now
                        data-url="{{ route('shop.bookings.pay', [$shop, $reservation->id]) }}"
                        data-shop-name="{{ $shop->name }}"
                        data-name="{{ $reservation->guest_name }}" data-mobile="{{ $reservation->guest_mobile }}" data-email="{{ $reservation->guest_email }}">
                    Pay ₹{{ number_format((float) $reservation->deposit_amount, 2) }} now
                </button>
            @endif
            @if ($canCancel && ! $insideWindow)
                <button type="button" class="shop-btn shop-btn-outline" data-cancel data-url="{{ route('shop.bookings.cancel', [$shop, $reservation->id]) }}"
                        data-confirm="{{ $paid && $reservation->status === $R::CONFIRMED ? 'Cancel this booking? Your advance will be refunded in full.' : 'Cancel this booking?' }}">
                    Cancel booking
                </button>
            @elseif ($insideWindow)
                <p class="book-note">Online cancellation closes {{ (int) $settings['cancel_hours'] }} hours before the booking. Please call us to make changes.</p>
            @endif
            <a href="{{ route('shop.bookings.index', $shop) }}" class="shop-btn shop-btn-outline">All bookings</a>
        </div>
    </div>
@endsection

@push('scripts')
    @if ($waiting && filled($checkoutScript))
        <script src="{{ $checkoutScript }}"></script>
    @endif
    <script src="{{ asset('assets/js/shop-booking.js') }}?v={{ filemtime(public_path('assets/js/shop-booking.js')) }}"></script>
    <script>
        (function () {
            var box = document.querySelector('[data-message]');
            function say(text, tone) { box.hidden = !text; box.textContent = text || ''; box.className = 'book-status book-status-' + (tone || 'wait'); }

            var pay = document.querySelector('[data-pay-now]');
            if (pay) pay.addEventListener('click', function () {
                pay.disabled = true;
                say('Opening secure payment…', 'wait');
                window.ShopBooking.call('POST', pay.dataset.url).then(function (result) {
                    if (!result.success) { say(result.message, 'bad'); pay.disabled = false; return; }
                    window.ShopBooking.pay(result.data, {
                        shopName: pay.dataset.shopName,
                        prefill: { name: pay.dataset.name, contact: pay.dataset.mobile, email: pay.dataset.email },
                        say: say,
                        onDone: function () { pay.disabled = false; }
                    });
                });
            });

            var cancel = document.querySelector('[data-cancel]');
            if (cancel) cancel.addEventListener('click', function () {
                if (!window.confirm(cancel.dataset.confirm)) return;
                cancel.disabled = true;
                window.ShopBooking.call('POST', cancel.dataset.url).then(function (result) {
                    if (!result.success) { say(result.message, 'bad'); cancel.disabled = false; return; }
                    window.location.reload();
                });
            });
        })();
    </script>
@endpush
