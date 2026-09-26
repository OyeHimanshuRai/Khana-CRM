@extends('table.layout')

@section('title', 'Your order · Table '.($table?->name ?? ''))

@section('content')
    <header class="t-head">
        <div class="t-head-brand">{{ $shop?->name ?: $company }}</div>
        <h1 class="t-head-table">Your order</h1>
        <p class="t-head-area">Table {{ $table?->name }}</p>
    </header>

    @include('table._flash')

    @if ($lines->isEmpty())
        <section class="t-card t-notice">
            <h2>Nothing chosen yet</h2>
            <p>Have a look at the menu and add what you fancy.</p>
            <a class="t-add-btn" href="{{ route('table.show') }}">Open the menu</a>
        </section>
    @else
        <section class="t-card">
            @foreach ($lines as $line)
                @php $blockedReason = $line->unavailableReason(); @endphp

                <div class="t-line @if ($blockedReason) is-off @endif">
                    <div class="t-line-main">
                        <div class="t-line-name">{{ $line->title() }}</div>

                        @foreach ($line->options() as $option)
                            <div class="t-line-opt">
                                {{ $option->name }}
                                @if ($option->priceLabel()) · {{ $option->priceLabel() }} @endif
                            </div>
                        @endforeach

                        @if ($line->note)
                            <div class="t-line-note">“{{ $line->note }}”</div>
                        @endif

                        @if ($blockedReason)
                            <div class="t-line-off">{{ $blockedReason }} — remove it to send the rest</div>
                        @endif
                    </div>

                    <div class="t-line-side">
                        <div class="t-line-total">
                            ₹{{ number_format($line->lineTotal('dine_in', $session->shop_id), 2) }}
                        </div>

                        {{--
                            Quantity as a small form of its own rather than a
                            stepper: no JavaScript, and a guest who mistypes
                            can fix it in one edit instead of tapping minus
                            eleven times.

                            This one and Remove below post and reload on
                            purpose. Everything around them is derived from
                            the thing they change - the line's own total, the
                            order total, the cart bar, and whether "Send to
                            the kitchen" may be pressed at all - and a screen
                            that answers with a toast while still showing the
                            old total is lying about money. A reload is the
                            cheaper mistake.
                        --}}
                        <form method="POST" action="{{ route('table.cart.update', $line) }}" class="t-line-qty">
                            @csrf
                            @method('PUT')
                            <label class="sr-only" for="q-{{ $line->id }}">
                                Quantity of {{ $line->title() }}
                            </label>
                            <input id="q-{{ $line->id }}" type="number" name="quantity" class="t-qty"
                                   value="{{ $line->quantity }}" min="0" max="30" inputmode="numeric">
                            <button type="submit" class="t-line-btn">Update</button>
                        </form>

                        <form method="POST" action="{{ route('table.cart.remove', $line) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="t-line-btn is-danger">Remove</button>
                        </form>
                    </div>
                </div>
            @endforeach

            <div class="t-total">
                <span>Total</span>
                <strong>₹{{ number_format($total, 2) }}</strong>
            </div>

            <p class="t-total-note">
                Taxes are included in these prices. Your bill will add anything the restaurant
                charges on top.
            </p>
        </section>

        <section class="t-card">
            {{--
                Upgraded, unlike the two above it, because nothing on this
                screen has to survive the answer: whichever way it goes the
                guest leaves this page - for their orders, or for the number
                check - and the server names where.
            --}}
            <form method="POST" action="{{ route('table.order.place') }}"
                  data-ajax data-busy="Sending…">
                @csrf

                {{--
                    Asked once per sitting. A table that gave its name with
                    the first round is not asked again for the second.
                --}}
                @if (blank($session->guest_name))
                    <div class="t-group">
                        <label class="t-group-title" for="guest-name">Your name (optional)</label>
                        <input id="guest-name" type="text" name="guest_name" class="t-input"
                               maxlength="120" autocomplete="name" placeholder="So we know whose table this is">
                    </div>

                    <div class="t-group">
                        <label class="t-group-title" for="guest-mobile">Mobile (optional)</label>
                        <input id="guest-mobile" type="tel" name="guest_mobile" class="t-input"
                               maxlength="30" autocomplete="tel" inputmode="tel">
                    </div>
                @endif

                <div class="t-group">
                    <label class="t-group-title" for="order-note">Anything for the kitchen?</label>
                    <input id="order-note" type="text" name="note" class="t-input"
                           maxlength="250" autocomplete="off" placeholder="Allergies, timing…">
                </div>

                <button type="submit" class="t-send"
                        @disabled($blocked->isNotEmpty())>
                    Send to the kitchen
                </button>

                @if ($blocked->isNotEmpty())
                    <p class="t-total-note">
                        Something on this list is no longer available. Remove it and the rest can go.
                    </p>
                @endif
            </form>
        </section>
    @endif

    @if ($placed->isNotEmpty())
        <p class="t-foot">
            <a href="{{ route('table.orders') }}">
                You have already sent {{ $placed->count() }}
                order{{ $placed->count() === 1 ? '' : 's' }} — see where they are &rarr;
            </a>
        </p>
    @endif

    <footer class="t-foot">
        <p><a href="{{ route('table.show') }}">&larr; Back to the menu</a></p>
    </footer>
@endsection
