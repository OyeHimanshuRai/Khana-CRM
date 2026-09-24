{{--
    The sticky cart bar (§14: "sticky cart").

    Computed here rather than passed in, so every page that includes it gets
    the same answer without each controller remembering to supply one. It is
    two cheap aggregates on an indexed column.

    Hidden entirely when the cart is empty: a permanent "₹0.00" bar steals a
    thumb's worth of a phone screen to say nothing.

    Hidden with the attribute rather than left out of the page, because adding
    the first dish without a reload has to be able to bring it back - and a
    guest who added something and has no way to reach their order is the worst
    outcome this screen has. See assets/js/table-forms.js.
--}}

@php
    $barCount = (int) $session->cartItems()->sum('quantity');
@endphp

<a class="t-bar" href="{{ route('table.cart') }}" data-cart-bar {{ $barCount > 0 ? '' : 'hidden' }}>
    <span class="t-bar-count" data-cart-count>
        {{ $barCount }} item{{ $barCount === 1 ? '' : 's' }}
    </span>
    <span class="t-bar-go">View order &rarr;</span>
</a>
