@extends('shop.layouts.app')

@section('title', 'Your cart')

@section('content')
    <h1 class="shop-h1">Your cart</h1>

    @if ($cart['items']->isEmpty())
        <div class="shop-empty">
            Your cart is empty. <a href="{{ route('shop.catalog', $shop) }}" style="color:var(--shop-brand-strong);font-weight:600;">Browse products</a>
        </div>
    @else
        <div class="shop-layout is-side-320">
            <div class="shop-panel shop-panel-pad">
                @foreach ($cart['items'] as $line)
                    @php $product = $line['product']; @endphp
                    <div class="shop-line">
                        <div class="shop-line-thumb">
                            @if ($product->images->first())
                                <img src="{{ $product->images->first()->url() }}" alt="{{ $product->name }}">
                            @endif
                        </div>

                        <div>
                            <a href="{{ route('shop.product', [$shop, $product]) }}" class="shop-card-name">{{ $product->name }}</a>
                            <div class="shop-card-meta">₹{{ number_format($line['unit_price'], 2) }} / unit</div>
                            @if ($line['quantity'] > $line['available_stock'])
                                <div class="shop-stock-out">Only {{ rtrim(rtrim(number_format($line['available_stock'], 2), '0'), '.') ?: '0' }} available</div>
                            @endif
                        </div>

                        <form action="{{ route('shop.cart.update', [$shop, $product]) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <div class="shop-qty">
                                <input type="number" name="quantity" value="{{ rtrim(rtrim(number_format($line['quantity'], 3), '0'), '.') }}" min="0" step="1" onchange="this.form.submit()">
                            </div>
                        </form>

                        <div style="text-align:right;">
                            <div class="shop-price">₹{{ number_format($line['line_total'], 2) }}</div>
                            <form action="{{ route('shop.cart.remove', [$shop, $product]) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="shop-btn shop-btn-outline shop-btn-sm" style="margin-top:6px;border-color:var(--shop-danger);color:var(--shop-danger);">Remove</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="shop-panel shop-panel-pad" style="align-self:start;">
                <h2 class="shop-h2">Order summary</h2>
                <div class="shop-summary-row"><span>Subtotal</span><span>₹{{ number_format($cart['subtotal'], 2) }}</span></div>
                <div class="shop-summary-row total"><span>Total</span><span>₹{{ number_format($cart['subtotal'], 2) }}</span></div>

                @auth('customer')
                    <a href="{{ route('shop.checkout', $shop) }}" class="shop-btn shop-btn-block" style="margin-top:16px;">Proceed to checkout</a>
                @else
                    <a href="{{ route('shop.login', $shop) }}" class="shop-btn shop-btn-block" style="margin-top:16px;">Sign in to checkout</a>
                @endauth
            </div>
        </div>
    @endif
@endsection
