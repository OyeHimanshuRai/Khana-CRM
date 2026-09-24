{{--
    The printable invoice.

    A bare page rather than the admin shell: what comes out of the printer
    should be the document, not a screenshot of the application. Two widths
    from one template - an 80mm till roll and an A4 sheet - because the
    content is identical and only the furniture differs.

    Everything printed here is read from the invoice's own copied fields.
    Nothing is looked up, so a reprint years later shows what the customer
    was actually handed.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->number }}</title>

    <link rel="stylesheet" href="{{ asset('assets/css/print.css') }}?v={{ filemtime(public_path('assets/css/print.css')) }}">
</head>
<body class="print-{{ $format }}">

@php
    $logo = $shop?->logoUrl();
    $isReceipt = $format === 'receipt';
    $showTax = (float) $invoice->tax_total > 0;
@endphp

<div class="doc">

    {{-- ----------------------------------------------------------- head --}}
    <header class="doc-head">
        @if ($logo && ! $isReceipt)
            <img class="doc-logo" src="{{ $logo }}" alt="">
        @endif

        <div class="doc-shop">
            <div class="doc-shop-name">{{ $shop?->name }}</div>

            @if ($shop?->legal_name && $shop->legal_name !== $shop->name)
                <div>{{ $shop->legal_name }}</div>
            @endif

            @if ($shop?->addressLine())
                <div>{{ $shop->addressLine() }}</div>
            @endif

            <div>
                @if ($shop?->phone) Ph {{ $shop->phone }} @endif
                @if ($shop?->email) · {{ $shop->email }} @endif
            </div>

            @if ($shop?->gstin)
                <div><strong>GSTIN {{ $shop->gstin }}</strong></div>
            @endif

            @if ($shop?->licence_no)
                <div>Licence {{ $shop->licence_no }}</div>
            @endif
        </div>

        <div class="doc-title">
            {{ $isReceipt ? 'Sales Receipt' : 'Tax Invoice' }}
            @if ($invoice->isCancelled())
                <span class="doc-void">CANCELLED</span>
            @endif
        </div>
    </header>

    {{-- ---------------------------------------------------------- meta --}}
    <section class="doc-meta">
        <div>
            <span class="doc-label">Invoice</span>
            <strong>{{ $invoice->number }}</strong>
        </div>
        <div>
            <span class="doc-label">Date</span>
            {{ $invoice->invoiced_at?->format('d M Y, H:i') }}
        </div>

        @unless ($isReceipt)
            <div>
                <span class="doc-label">Place of supply</span>
                {{ $invoice->place_of_supply ?: '—' }}
            </div>
            @if ($invoice->due_date)
                <div>
                    <span class="doc-label">Payment due</span>
                    {{ $invoice->due_date->format('d M Y') }}
                </div>
            @endif
        @endunless
    </section>

    {{-- ------------------------------------------------------ customer --}}
    <section class="doc-party">
        <span class="doc-label">Billed to</span>
        <div class="doc-party-name">{{ $invoice->billedTo() }}</div>

        @if ($invoice->customer_mobile)
            <div>{{ $invoice->customer_mobile }}</div>
        @endif

        @unless ($isReceipt)
            @if ($invoice->billing_address)
                <div>{{ $invoice->billing_address }}</div>
            @endif
            @if ($invoice->customer_gstin)
                <div>GSTIN {{ $invoice->customer_gstin }}</div>
            @endif
        @endunless
    </section>

    {{-- ---------------------------------------------------------- items --}}
    <table class="doc-items">
        <thead>
            <tr>
                <th class="col-sn">#</th>
                <th>Item</th>
                @unless ($isReceipt)<th class="col-hsn">HSN</th>@endunless
                <th class="col-num">Qty</th>
                <th class="col-num">Rate</th>
                @if ($showTax && ! $isReceipt)<th class="col-num">Taxable</th>@endif
                @if ($showTax && ! $isReceipt)<th class="col-num">Tax</th>@endif
                <th class="col-num">Amount</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($invoice->items as $index => $item)
                <tr>
                    <td class="col-sn">{{ $index + 1 }}</td>

                    <td>
                        {{ $item->product_name }}
                        @if ($item->batch_no)
                            <span class="doc-sub">
                                Batch {{ $item->batch_no }}@if ($item->expiry_date) · Exp {{ $item->expiry_date->format('m/Y') }}@endif
                            </span>
                        @endif
                        @if ((float) $item->discount_amount > 0)
                            <span class="doc-sub">Less ₹{{ number_format((float) $item->discount_amount, 2) }}</span>
                        @endif
                    </td>

                    @unless ($isReceipt)
                        <td class="col-hsn">{{ $item->hsn_code ?: '—' }}</td>
                    @endunless

                    <td class="col-num">{{ $item->quantityLabel() }}</td>
                    <td class="col-num">{{ number_format((float) $item->unit_price, 2) }}</td>

                    @if ($showTax && ! $isReceipt)
                        <td class="col-num">{{ number_format((float) $item->taxable_value, 2) }}</td>
                        <td class="col-num">
                            {{ number_format($item->taxAmount(), 2) }}
                            <span class="doc-sub">{{ rtrim(rtrim(number_format((float) $item->tax_rate, 2), '0'), '.') }}%</span>
                        </td>
                    @endif

                    <td class="col-num"><strong>{{ number_format((float) $item->line_total, 2) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- --------------------------------------------------------- totals --}}
    <section class="doc-totals">
        <div><span>Sub-total</span><span>{{ number_format((float) $invoice->subtotal, 2) }}</span></div>

        @if ((float) $invoice->line_discount_total > 0)
            <div>
                <span>Line discounts</span>
                <span>− {{ number_format((float) $invoice->line_discount_total, 2) }}</span>
            </div>
        @endif

        @if ((float) $invoice->invoice_discount > 0)
            <div>
                <span>Bill discount</span>
                <span>− {{ number_format((float) $invoice->invoice_discount, 2) }}</span>
            </div>
        @endif

        @if ($showTax)
            @if ($invoice->is_inter_state)
                <div><span>IGST</span><span>{{ number_format((float) $invoice->igst_total, 2) }}</span></div>
            @else
                <div><span>CGST</span><span>{{ number_format((float) $invoice->cgst_total, 2) }}</span></div>
                <div><span>SGST</span><span>{{ number_format((float) $invoice->sgst_total, 2) }}</span></div>
            @endif

            @if ((float) $invoice->cess_total > 0)
                <div><span>Cess</span><span>{{ number_format((float) $invoice->cess_total, 2) }}</span></div>
            @endif
        @endif

        @if (abs((float) $invoice->round_off) > 0.004)
            <div><span>Round off</span><span>{{ number_format((float) $invoice->round_off, 2) }}</span></div>
        @endif

        <div class="is-grand">
            <span>Total</span>
            <span>₹{{ number_format((float) $invoice->grand_total, 2) }}</span>
        </div>

        @foreach ($payments as $payment)
            <div>
                <span>{{ $payment->methodLabel() }}{{ $payment->status === 'pending' ? ' (pending)' : '' }}</span>
                <span>{{ number_format((float) $payment->amount, 2) }}</span>
            </div>
        @endforeach

        @if ((float) $invoice->due_total > 0)
            <div class="is-due">
                <span>Balance due</span>
                <span>₹{{ number_format((float) $invoice->due_total, 2) }}</span>
            </div>
        @endif
    </section>

    {{-- The amount in words, which a tax invoice is expected to carry. --}}
    <section class="doc-words">
        <span class="doc-label">Amount in words</span>
        {{ App\Support\Money::inWords((float) $invoice->grand_total) }}
    </section>

    {{-- ----------------------------------------------------- hsn summary --}}
    @if ($showTax && ! $isReceipt && $hsnSummary->isNotEmpty())
        <section class="doc-hsn">
            <span class="doc-label">Tax summary</span>

            <table class="doc-items">
                <thead>
                    <tr>
                        <th>HSN</th>
                        <th class="col-num">Rate</th>
                        <th class="col-num">Taxable</th>
                        @if ($invoice->is_inter_state)
                            <th class="col-num">IGST</th>
                        @else
                            <th class="col-num">CGST</th>
                            <th class="col-num">SGST</th>
                        @endif
                        <th class="col-num">Total tax</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($hsnSummary as $row)
                        <tr>
                            <td>{{ $row['hsn'] }}</td>
                            <td class="col-num">{{ rtrim(rtrim(number_format($row['rate'], 2), '0'), '.') }}%</td>
                            <td class="col-num">{{ number_format($row['taxable'], 2) }}</td>
                            @if ($invoice->is_inter_state)
                                <td class="col-num">{{ number_format($row['igst'], 2) }}</td>
                            @else
                                <td class="col-num">{{ number_format($row['cgst'], 2) }}</td>
                                <td class="col-num">{{ number_format($row['sgst'], 2) }}</td>
                            @endif
                            <td class="col-num">
                                {{ number_format($row['cgst'] + $row['sgst'] + $row['igst'] + $row['cess'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- ---------------------------------------------------------- foot --}}
    <footer class="doc-foot">
        @if ($invoice->notes)
            <p>{{ $invoice->notes }}</p>
        @endif

        <p class="doc-sub">
            Billed by {{ $invoice->created_by_name ?? '—' }}
            @if ($invoice->isCancelled())
                · Cancelled {{ $invoice->cancelled_at?->format('d M Y') }}: {{ $invoice->cancel_reason }}
            @endif
        </p>

        @unless ($isReceipt)
            <div class="doc-sign">
                <span>Customer's signature</span>
                <span>For {{ $shop?->name }}</span>
            </div>
        @endunless

        <p class="doc-thanks">Thank you. Goods once sold are subject to the shop's return policy.</p>
    </footer>
</div>

{{-- Not printed: a toolbar for the person looking at it on screen. --}}
<div class="doc-actions no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('admin.invoices.print', [$invoice, 'format' => $isReceipt ? 'a4' : 'receipt']) }}">
        Switch to {{ $isReceipt ? 'A4 invoice' : '80mm receipt' }}
    </a>
    <a href="{{ route('admin.invoices.show', $invoice) }}">Back to the invoice</a>
</div>

</body>
</html>
