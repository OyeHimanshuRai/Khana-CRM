{{--
    The printable customer statement.

    A bare page, like the invoice: what gets handed or posted to a customer
    should be the statement itself.

    A date-filtered statement opens with the balance the account already
    stood at, because without it the page reads as though the customer
    started from zero - which is exactly how a disagreement starts.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement · {{ $customer->name }}</title>

    <link rel="stylesheet" href="{{ asset('assets/css/print.css') }}?v={{ filemtime(public_path('assets/css/print.css')) }}">
</head>
<body class="print-a4">

@php
    $shop = $customer->shop;
    $logo = $shop?->logoUrl();

    $totalDebit = (float) $entries->sum('debit');
    $totalCredit = (float) $entries->sum('credit');
    $closing = $entries->isNotEmpty()
        ? (float) $entries->last()->balance_after
        : $opening;

    $running = $opening;
@endphp

<div class="doc">
    <header class="doc-head">
        @if ($logo)
            <img class="doc-logo" src="{{ $logo }}" alt="">
        @endif

        <div class="doc-shop">
            <div class="doc-shop-name">{{ $shop?->name }}</div>
            @if ($shop?->addressLine())<div>{{ $shop->addressLine() }}</div>@endif
            <div>
                @if ($shop?->phone) Ph {{ $shop->phone }} @endif
                @if ($shop?->gstin) · GSTIN {{ $shop->gstin }} @endif
            </div>
        </div>

        <div class="doc-title">Statement of Account</div>
    </header>

    <section class="doc-meta">
        <div>
            <span class="doc-label">Customer</span>
            <strong>{{ $customer->name }}</strong>
        </div>
        <div>
            <span class="doc-label">Account</span>
            {{ $customer->reference() }}
        </div>
        <div>
            <span class="doc-label">Period</span>
            {{ $from ? \Illuminate\Support\Carbon::parse($from)->format('d M Y') : 'From the beginning' }}
            —
            {{ $to ? \Illuminate\Support\Carbon::parse($to)->format('d M Y') : today()->format('d M Y') }}
        </div>
        <div>
            <span class="doc-label">Printed</span>
            {{ now()->format('d M Y, H:i') }}
        </div>
    </section>

    @if ($customer->addressLine())
        <section class="doc-party">
            <span class="doc-label">Address</span>
            <div>{{ $customer->addressLine() }}</div>
            @if ($customer->mobile)<div>{{ $customer->mobile }}</div>@endif
        </section>
    @endif

    <table class="doc-items">
        <thead>
            <tr>
                <th>Date</th>
                <th>Particulars</th>
                <th class="col-num">Debit</th>
                <th class="col-num">Credit</th>
                <th class="col-num">Balance</th>
            </tr>
        </thead>

        <tbody>
            <tr>
                <td>—</td>
                <td><em>Opening balance</em></td>
                <td class="col-num"></td>
                <td class="col-num"></td>
                <td class="col-num">{{ number_format($opening, 2) }}{{ $opening < 0 ? ' Cr' : '' }}</td>
            </tr>

            @foreach ($entries as $entry)
                @php $running = (float) $entry->balance_after; @endphp
                <tr>
                    <td>{{ $entry->entered_at?->format('d M Y') }}</td>
                    <td>
                        {{ $entry->description ?: $entry->typeLabel() }}
                        <span class="doc-sub">{{ $entry->typeLabel() }}</span>
                    </td>
                    <td class="col-num">
                        {{ (float) $entry->debit > 0 ? number_format((float) $entry->debit, 2) : '' }}
                    </td>
                    <td class="col-num">
                        {{ (float) $entry->credit > 0 ? number_format((float) $entry->credit, 2) : '' }}
                    </td>
                    <td class="col-num">
                        {{ number_format(abs($running), 2) }}{{ $running < 0 ? ' Cr' : '' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="doc-totals">
        <div><span>Total debits</span><span>{{ number_format($totalDebit, 2) }}</span></div>
        <div><span>Total credits</span><span>{{ number_format($totalCredit, 2) }}</span></div>
        <div class="is-grand">
            <span>{{ $closing < 0 ? 'In credit' : 'Balance due' }}</span>
            <span>₹{{ number_format(abs($closing), 2) }}</span>
        </div>
    </section>

    <section class="doc-words">
        <span class="doc-label">In words</span>
        {{ App\Support\Money::inWords(abs($closing)) }}
    </section>

    <footer class="doc-foot">
        <p class="doc-sub">
            Please quote the account number when paying. If anything here looks wrong, tell us within
            seven days.
        </p>
        <p class="doc-thanks">Thank you for your custom.</p>
    </footer>
</div>

<div class="doc-actions no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('admin.ledger.index', ['customer' => $customer->id]) }}">Back to the ledger</a>
</div>

</body>
</html>
