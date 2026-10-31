@extends('shop.layouts.app')

@section('title', 'Your orders')

@section('content')
    <h1 class="shop-h1">Your orders</h1>

    @if ($orders->isEmpty())
        <div class="shop-empty">You haven't placed any orders yet.</div>
    @else
        <div class="shop-panel">
            @foreach ($orders as $order)
                <div class="shop-order-row">
                    <div>
                        <div style="font-weight:600;">{{ $order->order_number }}</div>
                        <div class="shop-card-meta">{{ $order->placed_at->format('d M Y, h:i A') }} &middot; {{ $order->items->count() }} item(s)</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:14px;">
                        <span class="shop-badge shop-badge-muted">{{ $order->statusLabel() }}</span>
                        <span class="shop-price">₹{{ number_format($order->grand_total, 2) }}</span>
                        <a href="{{ route('shop.orders.show', [$shop, $order]) }}" class="shop-btn shop-btn-outline shop-btn-sm">View</a>
                    </div>
                </div>
            @endforeach
        </div>

        <div style="margin-top:20px;">{{ $orders->links() }}</div>
    @endif
@endsection
