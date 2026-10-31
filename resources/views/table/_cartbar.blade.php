{{--
    The sticky cart bar (§14: "sticky cart").

    Computed here rather than passed in, so every page that includes it gets
    the same answer without each controller remembering to supply one. It is
    two cheap aggregates on an indexed column.

    Hidden entirely when the cart is empty: a permanent "₹0.00" bar steals a
    thumb's worth of a phone screen to say nothing.
--}}

@php
    $barCount = (int) $session->cartItems()->sum('quantity');
@endphp

@if ($barCount > 0)
    <a class="t-bar" href="{{ route('table.cart') }}">
        <span class="t-bar-count">
            {{ $barCount }} item{{ $barCount === 1 ? '' : 's' }}
        </span>
        <span class="t-bar-go">View order &rarr;</span>
    </a>
@endif
