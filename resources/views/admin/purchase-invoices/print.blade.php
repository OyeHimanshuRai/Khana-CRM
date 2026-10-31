{{--
    The printable bill - a record of what the shop received and what it
    owes for it. Reads only from the receipt's own copied fields, same rule
    admin/invoices/print.blade.php follows: a reprint years later has to
    show what was actually billed, not a re-lookup of today's prices.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $receipt->bill_number ?: $receipt->reference }}</title>

    <link rel="stylesheet" href="{{ asset('assets/css/print.css') }}?v={{ filemtime(public_path('assets/css/print.css')) }}">
</head>
<body class="print-a4">

<div class="doc">
    <header class="doc-head">
        @php $logo = $receipt->shop?->logoUrl(); @endphp
        @if ($logo)
            <img class="doc-logo" src="{{ $logo }}" alt="">
        @endif

        <div class="doc-shop">
            <div class="doc-shop-name">{{ $receipt->shop?->name }}</div>
            @if ($receipt->shop?->addressLine())
                <div>{{ $receipt->shop->addressLine() }}</div>
            @endif
            @if ($receipt->shop?->gstin)
                <div><strong>GSTIN {{ $receipt->shop->gstin }}</strong></div>
            @endif
        </div>

        <div class="doc-title">Purchase Invoice</div>
    </header>

    <section class="doc-meta">
        <div><span class="doc-label">Bill</span><strong>{{ $receipt->bill_number ?: '—' }}</strong></div>
        <div><span class="doc-label">Bill date</span>{{ $receipt->bill_date?->format('d M Y') ?? '—' }}</div>
        <div><span class="doc-label">Due date</span>{{ $receipt->due_date?->format('d M Y') ?? '—' }}</div>
        <div><span class="doc-label">Receipt</span>{{ $receipt->reference }}</div>
    </section>

    <section class="doc-party">
        <span class="doc-label">Supplier</span>
        <div class="doc-party-name">{{ $receipt->supplier?->displayName() ?? '—' }}</div>
        @if ($receipt->supplier?->addressLine())
            <div>{{ $receipt->supplier->addressLine() }}</div>
        @endif
        @if ($receipt->supplier?->gstin)
            <div>GSTIN {{ $receipt->supplier->gstin }}</div>
        @endif
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
            @foreach ($items as $index => $item)
                <tr>
                    <td class="col-sn">{{ $index + 1 }}</td>
                    <td>
                        {{ $item->product_name }}
                        @if ($item->batch_no)
                            <span class="doc-sub">Batch {{ $item->batch_no }}</span>
                        @endif
                    </td>
                    <td class="col-num">{{ rtrim(rtrim(number_format((float) $item->totalQuantity(), 3), '0'), '.') }}</td>
                    <td class="col-num">{{ number_format((float) $item->unit_cost, 2) }}</td>
                    <td class="col-num"><strong>{{ number_format((float) $item->line_total, 2) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="doc-totals">
        <div><span>Sub-total</span><span>{{ number_format((float) $receipt->subtotal, 2) }}</span></div>

        @if ((float) $receipt->discount_total > 0)
            <div><span>Discount</span><span>&minus; {{ number_format((float) $receipt->discount_total, 2) }}</span></div>
        @endif

        @if ((float) $receipt->tax_total > 0)
            @if ($receipt->is_inter_state)
                <div><span>IGST</span><span>{{ number_format((float) $receipt->igst_total, 2) }}</span></div>
            @else
                <div><span>CGST</span><span>{{ number_format((float) $receipt->cgst_total, 2) }}</span></div>
                <div><span>SGST</span><span>{{ number_format((float) $receipt->sgst_total, 2) }}</span></div>
            @endif
        @endif

        @if ((float) $receipt->other_charges > 0)
            <div><span>Other charges</span><span>{{ number_format((float) $receipt->other_charges, 2) }}</span></div>
        @endif

        @if (abs((float) $receipt->round_off) > 0.004)
            <div><span>Round off</span><span>{{ number_format((float) $receipt->round_off, 2) }}</span></div>
        @endif

        <div class="is-grand"><span>Total</span><span>₹{{ number_format((float) $receipt->grand_total, 2) }}</span></div>
        <div><span>Paid</span><span>{{ number_format((float) $receipt->paid_total, 2) }}</span></div>
        <div><span>Due</span><span>₹{{ number_format((float) $receipt->due_total, 2) }}</span></div>
    </section>
</div>

</body>
</html>
