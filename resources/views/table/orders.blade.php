@extends('table.layout')

@section('title', 'Your orders · Table '.($table?->name ?? ''))

@section('content')
    <header class="t-head">
        <div class="t-head-brand">{{ $shop?->name ?: $company }}</div>
        <h1 class="t-head-table">Your orders</h1>
        <p class="t-head-area">Table {{ $table?->name }}</p>
    </header>

    @include('table._flash')

    @if ($orders->isEmpty())
        <section class="t-card t-notice">
            <h2>Nothing sent yet</h2>
            <p>Your order will show here once it is with the kitchen.</p>
            <a class="t-add-btn" href="{{ route('table.show') }}">Open the menu</a>
        </section>
    @else
        {{--
            Every round, not just the last (§3.11): they all land on one bill,
            and a guest checking on their starters should not lose sight of
            the mains they ordered after.
        --}}
        @foreach ($orders as $order)
            <section class="t-card">
                <div class="t-order-head">
                    <div>
                        <div class="t-order-no">{{ $order->order_number }}</div>
                        <div class="t-order-time">
                            {{ $order->placed_at?->format('g:i a') }}
                            @if ($order->kitchenMinutes() > 0)
                                · {{ $order->kitchenMinutes() }} min ago
                            @endif
                        </div>
                    </div>

                    {{--
                        The stage, in the kitchen's words. A guest reading
                        "Preparing" knows what is happening; "confirmed" tells
                        them nothing about their food.
                    --}}
                    <span class="t-stage is-{{ $order->status }}">{{ $order->statusLabel() }}</span>
                </div>

                @foreach ($order->items as $item)
                    <div class="t-line">
                        <div class="t-line-main">
                            <div class="t-line-name">
                                {{ (int) $item->quantity }} ×
                                {{ $item->product_name }}@if ($item->variant_name) — {{ $item->variant_name }}@endif
                            </div>

                            @foreach ($item->modifiers as $modifier)
                                <div class="t-line-opt">
                                    {{ $modifier->option_name }}
                                    @if ($modifier->priceLabel()) · {{ $modifier->priceLabel() }} @endif
                                </div>
                            @endforeach

                            @if ($item->note)
                                <div class="t-line-note">“{{ $item->note }}”</div>
                            @endif
                        </div>

                        <div class="t-line-side">
                            <div class="t-line-total">₹{{ number_format((float) $item->line_total, 2) }}</div>
                        </div>
                    </div>
                @endforeach

                <div class="t-total">
                    <span>This round</span>
                    <strong>₹{{ number_format((float) $order->grand_total, 2) }}</strong>
                </div>
            </section>
        @endforeach

        <section class="t-card">
            <div class="t-total">
                <span>Running total</span>
                <strong>₹{{ number_format($total, 2) }}</strong>
            </div>

            <p class="t-total-note">
                Everything from this table is on one bill.
                @unless ($canPayOnline)
                    Ask a member of staff when you are ready to pay.
                @endunless
            </p>
        </section>

        {{-- Only where the outlet has payment keys. Without them the page
             reads exactly as it did before online payment existed. --}}
        @if ($canPayOnline && $total > 0)
            @include('table._pay')
        @endif
    @endif

    <footer class="t-foot">
        <p><a href="{{ route('table.show') }}">&larr; Order something else</a></p>

        {{-- §15. One line, at the bottom, after the food has arrived - not a
             pop-up over a menu somebody is still reading. --}}
        <p><a href="{{ route('table.feedback') }}">How was it? Tell us &rarr;</a></p>
    </footer>

    @if ($cartCount > 0)
        @include('table._cartbar')
    @endif
@endsection
