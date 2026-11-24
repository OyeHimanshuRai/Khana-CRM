{{--
    The body of the receipt: shared by the page on the phone and the file the
    guest downloads, so the two can never disagree.
--}}
<div class="t-rc-brand">{{ $shop?->name ?: $company }}</div>
@if ($shop?->addressLine())
    <div class="t-rc-addr">{{ $shop->addressLine() }}</div>
@endif
@if ($shop?->gstin)
    <div class="t-rc-addr">GSTIN {{ $shop->gstin }}</div>
@endif

<div class="t-rc-status {{ $isPaid ? 'is-paid' : 'is-due' }}">
    {{ $isPaid ? 'Paid' : 'Payment pending' }}
</div>

<dl class="t-rc-meta">
    <div><dt>Order ID</dt><dd>{{ $orderNumbers->implode(', ') ?: '—' }}</dd></div>
    <div><dt>Table</dt><dd>{{ $table?->name }}</dd></div>
    @foreach ($invoices as $invoice)
        <div><dt>Bill no.</dt><dd>{{ $invoice->number }}</dd></div>
    @endforeach
    <div><dt>Date</dt><dd>{{ ($paidAt ?? now())->format('d M Y, g:i a') }}</dd></div>
    <div><dt>Payment mode</dt><dd>{{ $paymentMode }}</dd></div>
    @if ($transactionId)
        <div><dt>Transaction ID</dt><dd>{{ $transactionId }}</dd></div>
    @endif
    <div><dt>Payment status</dt><dd>{{ $isPaid ? 'Paid' : 'Pending' }}</dd></div>
</dl>

<table class="t-rc-items">
    <thead>
        <tr><th>Item</th><th class="n">Qty</th><th class="n">Amount</th></tr>
    </thead>
    <tbody>
        @foreach ($invoices as $invoice)
            @foreach ($invoice->items as $line)
                <tr>
                    <td>{{ $line->product_name }}</td>
                    <td class="n">{{ $line->quantityLabel() }}</td>
                    <td class="n">₹{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
</table>

<div class="t-rc-sums">
    @php
        $sub = (float) $invoices->sum('subtotal') - (float) $invoices->sum('line_discount_total') - (float) $invoices->sum('invoice_discount');
        $tax = (float) $invoices->sum('tax_total');
    @endphp
    @if ($tax > 0)
        <div><span>Subtotal</span><span>₹{{ number_format($sub, 2) }}</span></div>
        <div><span>Taxes</span><span>₹{{ number_format($tax, 2) }}</span></div>
    @endif
    <div class="t-rc-grand"><span>Total</span><span>₹{{ number_format($grandTotal, 2) }}</span></div>
    <div><span>Paid</span><span>₹{{ number_format($paidTotal, 2) }}</span></div>
</div>

<p class="t-rc-thanks">Thank you for dining with us!</p>
