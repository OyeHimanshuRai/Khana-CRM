@extends('shop.layouts.app')

@section('title', $order->order_number)

@section('content')
    <div class="shop-crumbs">
        <a href="{{ route('shop.orders.index', $shop) }}">Your orders</a> &rsaquo; {{ $order->order_number }}
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
        <h1 class="shop-h1" style="margin:0;">Order {{ $order->order_number }}</h1>
        <span class="shop-badge shop-badge-muted">{{ $order->statusLabel() }}</span>
    </div>

    <div class="shop-layout is-side-320">
        <div class="shop-panel shop-panel-pad">
            <h2 class="shop-h2">Items</h2>
            @foreach ($order->items as $item)
                <div class="shop-summary-row">
                    <span>{{ $item->product_name }} &times; {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</span>
                    <span>₹{{ number_format($item->line_total, 2) }}</span>
                </div>
            @endforeach

            <h2 class="shop-h2" style="margin-top:24px;">Delivery address</h2>
            <p style="margin:0;">
                {{ $order->ship_recipient_name }}<br>
                {{ $order->shippingAddressLine() }}<br>
                {{ $order->ship_mobile }}
            </p>
        </div>

        <div class="shop-panel shop-panel-pad" style="align-self:start;">
            <h2 class="shop-h2">Payment</h2>
            <div class="shop-summary-row"><span>Method</span><span>{{ strtoupper($order->payment_method) }}</span></div>
            <div class="shop-summary-row"><span>Status</span><span>{{ ucfirst($order->payment_status) }}</span></div>

            <div class="shop-summary-row" style="margin-top:10px;"><span>Subtotal</span><span>₹{{ number_format($order->subtotal, 2) }}</span></div>
            @if ($order->discount_total > 0)
                <div class="shop-summary-row"><span>Discount {{ $order->coupon_code ? "($order->coupon_code)" : '' }}</span><span>&minus;₹{{ number_format($order->discount_total, 2) }}</span></div>
            @endif
            <div class="shop-summary-row total"><span>Total</span><span>₹{{ number_format($order->grand_total, 2) }}</span></div>

            @if ($order->invoice_id)
                <div class="shop-badge shop-badge-success" style="margin-top:14px;">Invoiced</div>
            @endif
        </div>
    </div>
@endsection
