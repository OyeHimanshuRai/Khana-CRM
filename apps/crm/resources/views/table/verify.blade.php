@extends('table.layout')

@section('title', 'Confirm your number · Table '.($table?->name ?? ''))

@section('content')
    <header class="t-head">
        <div class="t-head-brand">{{ $shop?->name ?: $company }}</div>
        <h1 class="t-head-table">Confirm your number</h1>
        <p class="t-head-area">Table {{ $table?->name }}</p>
    </header>

    @include('table._flash')

    {{--
        Two forms, one screen: ask for a number, then ask for the code.

        Which one leads is decided by whether a live code is waiting, not by a
        step counter - a guest who reloads, comes back from their messages app
        or taps "back" lands on the right one either way. Both stay on the page
        so somebody who mistyped a digit can fix it without starting again.

        Every form here works without JavaScript, like the rest of the
        journey: this is a phone on a restaurant's wifi. data-ajax only saves
        the reload, and it is safe on all three because each one ends by
        sending the guest somewhere the server names - so nothing on this
        screen is left behind out of date by an answer.
    --}}

    @if ($pending)
        <section class="t-card">
            <p class="t-total-note">
                We sent a code to <strong>{{ $pending->maskedDestination() }}</strong>.
                It is good for {{ config('sms.otp.ttl_minutes') }} minutes.
            </p>

            <form method="POST" action="{{ route('table.verify.confirm') }}"
                  data-ajax data-busy="Checking…">
                @csrf
                <input type="hidden" name="mobile" value="{{ $mobile }}">

                <div class="t-group">
                    <label class="t-group-title" for="otp-code">Enter the code</label>
                    <input id="otp-code" type="text" name="code" class="t-input"
                           inputmode="numeric" autocomplete="one-time-code"
                           maxlength="{{ config('sms.otp.length', 6) }}"
                           pattern="[0-9]*" required autofocus
                           placeholder="{{ str_repeat('0', (int) config('sms.otp.length', 6)) }}">
                </div>

                <button type="submit" class="t-send">Confirm</button>
            </form>
        </section>

        <section class="t-card">
            <form method="POST" action="{{ route('table.verify.send') }}"
                  data-ajax data-busy="Sending…">
                @csrf
                <input type="hidden" name="mobile" value="{{ $mobile }}">

                <p class="t-total-note">
                    Did not get it?
                    @if ($wait > 0)
                        You can ask for another in {{ $wait }} second{{ $wait === 1 ? '' : 's' }}.
                    @endif
                </p>

                <button type="submit" class="t-add-btn" @disabled($wait > 0)>
                    Send it again
                </button>
            </form>
        </section>

        <section class="t-card t-notice">
            <p>
                Wrong number? <a href="{{ route('table.verify') }}">Start again</a>, or just ask a
                member of staff to take your order.
            </p>
        </section>
    @else
        <section class="t-card">
            <p class="t-total-note">
                This restaurant confirms your mobile number before sending an order to the
                kitchen. We will text you a code.
            </p>

            <form method="POST" action="{{ route('table.verify.send') }}"
                  data-ajax data-busy="Sending…">
                @csrf

                <div class="t-group">
                    <label class="t-group-title" for="otp-mobile">Your mobile number</label>
                    <input id="otp-mobile" type="tel" name="mobile" class="t-input"
                           value="{{ $mobile }}" inputmode="tel" autocomplete="tel"
                           maxlength="30" required autofocus placeholder="98765 43210">
                </div>

                <button type="submit" class="t-send">Send me a code</button>
            </form>
        </section>

        <section class="t-card t-notice">
            <p>
                Would rather not? Ask a member of staff and they will take your order at the
                table.
            </p>
        </section>
    @endif

    <nav class="t-nav">
        <a href="{{ route('table.cart') }}">Back to your order</a>
    </nav>
@endsection
