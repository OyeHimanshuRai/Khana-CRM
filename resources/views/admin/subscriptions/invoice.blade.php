{{--
    Receipt for one subscription payment.

    A bare printable page, like the customer statement: a receipt of money
    received, with the GST inside it itemised (taxable value, rate, tax) so the
    restaurant can claim it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $payment->reference }}</title>

    <link rel="stylesheet" href="{{ asset('assets/css/print.css') }}?v={{ filemtime(public_path('assets/css/print.css')) }}">
</head>
<body class="print-a4">

@php
    $tenant = $payment->tenant;
    $plan = $payment->subscription?->plan;
    $buyerAddress = collect([
        $tenant?->address_line1, $tenant?->address_line2, $tenant?->city,
        $tenant?->state, $tenant?->pincode,
    ])->filter()->implode(', ');
    $currency = $payment->currency ?: 'INR';
    // The GST inside the amount received; 0 on payments from before it was charged.
    $tax = (float) $payment->tax_amount;
    $taxable = round((float) $payment->amount - $tax, 2);
    $rate = (float) ($order?->gst_rate ?: ($tax > 0 && $taxable > 0 ? round($tax / $taxable * 100, 2) : 0));
@endphp

<div class="doc">
    <header class="doc-head">
        <div class="doc-shop">
            <div class="doc-shop-name">{{ $seller['name'] }}</div>
            @if (! empty($seller['address']))<div>{{ $seller['address'] }}</div>@endif
            <div>
                @if (! empty($seller['email'])) {{ $seller['email'] }} @endif
                @if (! empty($seller['gstin'])) · GSTIN {{ $seller['gstin'] }} @endif
            </div>
        </div>

        <div class="doc-title">Payment Receipt</div>
    </header>

    <section class="doc-meta">
        <div>
            <span class="doc-label">Receipt no.</span>
            <strong>{{ $payment->reference }}</strong>
        </div>
        <div>
            <span class="doc-label">Date</span>
            {{ $payment->paid_at?->format('d M Y') }}
        </div>
        <div>
            <span class="doc-label">Method</span>
            {{ $payment->methodLabel() }}
        </div>
        @if ($payment->provider_reference)
            <div>
                <span class="doc-label">Transaction</span>
                {{ $payment->provider_reference }}
            </div>
        @endif
    </section>

    <section class="doc-party">
        <span class="doc-label">Received from</span>
        <div><strong>{{ $tenant?->legal_name ?: $tenant?->name }}</strong></div>
        @if ($buyerAddress)<div>{{ $buyerAddress }}</div>@endif
        @if ($tenant?->gstin)<div>GSTIN {{ $tenant->gstin }}</div>@endif
    </section>

    <table class="doc-items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="col-num">Amount</th>
            </tr>
        </thead>

        <tbody>
            <tr>
                <td>
                    {{ $plan?->name ?? 'Subscription' }} plan
                    <span class="doc-sub">{{ $payment->periodLine() }}</span>
                </td>
                <td class="col-num">{{ number_format((float) ($order?->list_price ?? $taxable), 2) }}</td>
            </tr>

            @if ($order && (float) $order->credit > 0)
                <tr>
                    <td>Credit for unused time on the previous plan</td>
                    <td class="col-num">-{{ number_format((float) $order->credit, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <section class="doc-totals">
        @if ($tax > 0)
            <div>
                <span>Taxable value</span>
                <span>{{ $currency }} {{ number_format($taxable, 2) }}</span>
            </div>
            <div>
                <span>GST @ {{ rtrim(rtrim(number_format($rate, 2), '0'), '.') }}%</span>
                <span>{{ $currency }} {{ number_format($tax, 2) }}</span>
            </div>
        @endif
        <div class="is-grand">
            <span>Total received</span>
            <span>{{ $currency }} {{ number_format((float) $payment->amount, 2) }}</span>
        </div>
    </section>

    <section class="doc-words">
        <span class="doc-label">In words</span>
        {{ App\Support\Money::inWords((float) $payment->amount) }}
    </section>

    <footer class="doc-foot">
        <p class="doc-sub">This is a receipt for payment received. Keep it for your records.</p>
        <p class="doc-thanks">Thank you.</p>
    </footer>
</div>

<div class="doc-actions no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('admin.subscription.mine') }}">Back to your subscription</a>
</div>

</body>
</html>
