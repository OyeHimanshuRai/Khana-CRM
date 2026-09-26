{{--
    The printable packing slip for an online order.

    Mirrors admin/invoices/print.blade.php's structure and CSS vocabulary
    (.doc/.doc-head/.doc-items/.doc-totals from print.css) - this is a
    fulfilment slip, not a tax document, so there is no tax breakdown or
    HSN column, and it reads from the order's own copied address rather
    than looking anything up.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->order_number }}</title>

    <link rel="stylesheet" href="{{ asset('assets/css/print.css') }}?v={{ filemtime(public_path('assets/css/print.css')) }}">
</head>
<body class="print-a4">

<div class="doc">
    <header class="doc-head">
        @php $logo = $order->shop?->logoUrl(); @endphp
        @if ($logo)
            <img class="doc-logo" src="{{ $logo }}" alt="">
        @endif

        <div class="doc-shop">
            <div class="doc-shop-name">{{ $order->shop?->name }}</div>
            @if ($order->shop?->addressLine())
                <div>{{ $order->shop->addressLine() }}</div>
            @endif
            <div>
                @if ($order->shop?->phone) Ph {{ $order->shop->phone }} @endif
            </div>
        </div>

        <div class="doc-title">
            Order Slip
            @if ($order->isCancelled())
                <span class="doc-void">CANCELLED</span>
            @endif
        </div>
    </header>

    <section class="doc-meta">
        <div><span class="doc-label">Order</span><strong>{{ $order->order_number }}</strong></div>
        <div><span class="doc-label">Placed</span>{{ $order->placed_at?->format('d M Y, H:i') }}</div>
        <div><span class="doc-label">Payment</span>{{ strtoupper($order->payment_method) }} · {{ ucfirst($order->payment_status) }}</div>
        <div><span class="doc-label">Status</span>{{ $order->statusLabel() }}</div>
    </section>

    <section class="doc-party">
        <span class="doc-label">Ship to</span>
        <div class="doc-party-name">{{ $order->ship_recipient_name }}</div>
        <div>{{ $order->ship_mobile }}</div>
        <div>{{ $order->shippingAddressLine() }}</div>
    </section>

    <table class="doc-items">
        <thead>
            <tr>
                <th class="col-sn">#</th>
                <th>Item</th>
                <th class="col-num">Qty</th>
                <th class="col-num">Rate</th>
                <th class="col-num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $index => $item)
                <tr>
                    <td class="col-sn">{{ $index + 1 }}</td>
                    <td>{{ $item->product_name }}</td>
                    <td class="col-num">{{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}</td>
                    <td class="col-num">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="col-num"><strong>{{ number_format((float) $item->line_total, 2) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="doc-totals">
        <div><span>Sub-total</span><span>{{ number_format((float) $order->subtotal, 2) }}</span></div>

        @if ((float) $order->discount_total > 0)
            <div>
                <span>Discount {{ $order->coupon_code ? "($order->coupon_code)" : '' }}</span>
                <span>&minus; {{ number_format((float) $order->discount_total, 2) }}</span>
            </div>
        @endif

        <div class="is-grand"><span>Total</span><span>₹{{ number_format((float) $order->grand_total, 2) }}</span></div>
    </section>
</div>

</body>
</html>
