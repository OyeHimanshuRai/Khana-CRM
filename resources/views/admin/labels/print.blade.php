<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Barcode Labels</title>

    <link rel="stylesheet" href="{{ asset('assets/css/labels.css') }}?v={{ filemtime(public_path('assets/css/labels.css')) }}">
    <style>
        :root { --label-w: {{ $size['width'] }}; --label-h: {{ $size['height'] }}; }
    </style>
</head>
<body class="labels-body" onload="window.print()">

<div class="labels-sheet">
    @foreach ($labels as $item)
        @php $product = $item['product']; @endphp
        <div class="label">
            @if ($showShop && $shop)
                <div class="label-shop">{{ $shop->name }}</div>
            @endif

            @if ($showName)
                <div class="label-name">{{ $product->name }}</div>
            @endif

            @if ($showPrice || $showMrp)
                <div class="label-prices">
                    @if ($showPrice)
                        <span class="label-price">₹{{ number_format($product->counterPriceFor($shopId), 2) }}</span>
                    @endif
                    @if ($showMrp)
                        <span class="label-mrp">₹{{ number_format($product->mrpFor($shopId), 2) }}</span>
                    @endif
                </div>
            @endif

            <div class="label-barcode">{!! $item['svg'] !!}</div>
            <div class="label-code">{{ $item['code'] }}</div>
        </div>
    @endforeach
</div>

</body>
</html>
