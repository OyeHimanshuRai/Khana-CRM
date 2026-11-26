@extends('shop.layouts.app')

@section('title', 'Book a table')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/shop-booking.css') }}?v={{ filemtime(public_path('assets/css/shop-booking.css')) }}">
@endpush

@section('content')
    <h1 class="shop-h1">Book a table</h1>

    @if (! $open)
        <div class="shop-empty">
            Online table booking is not available right now.
            @if (filled($shop->phone))
                Please call us on <a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop->phone) }}">{{ $shop->phone }}</a> to book.
            @endif
        </div>
    @else
        @php
            $depositText = match ($settings['deposit_mode']) {
                \App\Support\OnlineBooking::DEPOSIT_FIXED => '₹'.number_format((float) $settings['deposit_amount'], 0).' advance per booking',
                \App\Support\OnlineBooking::DEPOSIT_PER_PERSON => '₹'.number_format((float) $settings['deposit_amount'], 0).' advance per guest',
                default => null,
            };
            $loginUrl = route('shop.login', $shop);
        @endphp

        <div class="book-layout" data-book
             data-today="{{ $today }}" data-last="{{ $lastDate }}"
             data-days='@json(array_map('intval', $settings['days']))'
             data-max-party="{{ (int) $settings['max_party'] }}" data-party="2"
             data-slots-url="{{ route('shop.book.slots', $shop) }}"
             data-store-url="{{ route('shop.book.store', $shop) }}"
             data-login-url="{{ $loginUrl }}"
             data-signed-in="{{ $customer ? '1' : '0' }}"
             data-shop-name="{{ $shop->name }}"
             data-email="{{ $customer?->email }}">

            <div>
                <section class="shop-panel shop-panel-pad book-step">
                    <h2>How many guests?</h2>
                    <div class="book-party">
                        <button type="button" data-party-minus aria-label="Fewer guests">−</button>
                        <output data-party-out>2</output>
                        <button type="button" data-party-plus aria-label="More guests">+</button>
                    </div>
                    <p class="book-note">Up to {{ (int) $settings['max_party'] }} guests online. For bigger groups, please call us.</p>
                </section>

                <section class="shop-panel shop-panel-pad book-step">
                    <h2>Choose a date</h2>
                    <div class="book-dates" data-dates></div>
                </section>

                <section class="shop-panel shop-panel-pad book-step">
                    <h2>Choose a time</h2>
                    <div class="book-slots" data-slots><p class="book-note">Pick a date to see free times.</p></div>
                </section>

                <section class="shop-panel shop-panel-pad book-step">
                    <h2>Your details</h2>
                    @guest('customer')
                        <p class="book-note" style="margin-top:0;">You'll sign in (or create an account) before booking. <a href="{{ $loginUrl }}">Sign in now</a></p>
                    @endguest
                    <div data-details>
                        <div class="shop-field-row">
                            <div class="shop-field">
                                <label for="b-name">Name</label>
                                <input type="text" id="b-name" name="name" maxlength="120" value="{{ $customer?->name }}" autocomplete="name">
                            </div>
                            <div class="shop-field">
                                <label for="b-mobile">Mobile</label>
                                <input type="tel" id="b-mobile" name="mobile" maxlength="18" value="{{ $customer?->mobile }}" autocomplete="tel">
                            </div>
                        </div>
                        <div class="shop-field">
                            <label for="b-notes">Special request (optional)</label>
                            <textarea id="b-notes" name="notes" maxlength="500" rows="3" placeholder="Birthday, high chair, window seat…"></textarea>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="shop-panel shop-panel-pad book-summary">
                <h2 class="shop-h2" style="margin-top:0;">Your booking</h2>
                <dl>
                    <div><dt>Date</dt><dd data-s-date>—</dd></div>
                    <div><dt>Time</dt><dd data-s-time>—</dd></div>
                    <div><dt>Guests</dt><dd data-s-party>—</dd></div>
                    <div><dt>Advance</dt><dd data-s-deposit>—</dd></div>
                </dl>
                <div class="book-status" data-message hidden></div>
                <button type="button" class="shop-btn shop-btn-block" data-submit disabled>Book table</button>
                @if ($depositText)
                    <p class="book-note">{{ $depositText }}, paid securely by UPI (Google Pay, PhonePe), card or netbanking. Your table is held for {{ (int) $settings['hold_minutes'] }} minutes while you pay.</p>
                @endif
                @if ((int) $settings['cancel_hours'] > 0)
                    <p class="book-note">Free cancellation{{ $depositText ? ' with full refund' : '' }} up to {{ (int) $settings['cancel_hours'] }} hours before your booking.</p>
                @endif
            </aside>
        </div>
    @endif
@endsection

@push('scripts')
    @if ($open && filled($checkoutScript))
        <script src="{{ $checkoutScript }}"></script>
    @endif
    <script src="{{ asset('assets/js/shop-booking.js') }}?v={{ filemtime(public_path('assets/js/shop-booking.js')) }}"></script>
@endpush
