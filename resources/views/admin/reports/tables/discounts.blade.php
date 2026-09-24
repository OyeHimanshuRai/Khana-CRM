{{--
    Discounts and coupons (§13).

    Line discounts and bill discounts are kept apart, exactly as the invoice
    keeps them apart, because they are two different problems:

        line discounts   every bill has one  -> the menu is priced wrong
        bill discounts   whole bills reduced -> somebody is being generous

    A single "discount given" total would hide which of the two is happening,
    and the fix for each is a different person's job.
--}}

@php
    $rows = $data['rows'];
    $coupons = $data['coupons'];
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Day</th>
            <th style="text-align:right">Bills</th>
            <th style="text-align:right">Line discounts</th>
            <th style="text-align:right">Bill discounts</th>
            <th style="text-align:right">Given away</th>
            <th style="text-align:right">Billed</th>
            <th style="text-align:right">Share</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php
                $billed = (float) $row->billed;
                $given = (float) $row->total_discount;
                // Against what was actually charged plus what was not, which
                // is what the bill would have been.
                $share = $billed + $given > 0 ? $given / ($billed + $given) * 100 : 0;
            @endphp
            <tr>
                <td><strong>{{ \Illuminate\Support\Carbon::parse($row->day)->format('j M Y') }}</strong></td>

                <td style="text-align:right" class="text-sm">{{ number_format($row->bills) }}</td>

                <td style="text-align:right" class="text-sm">
                    {{ (float) $row->line_discount > 0 ? number_format((float) $row->line_discount, 2) : '—' }}
                </td>

                <td style="text-align:right" class="text-sm">
                    {{ (float) $row->bill_discount > 0 ? number_format((float) $row->bill_discount, 2) : '—' }}
                </td>

                <td style="text-align:right"><strong>{{ number_format($given, 2) }}</strong></td>

                <td style="text-align:right" class="text-sm">{{ number_format($billed, 2) }}</td>

                <td style="text-align:right" class="text-sm">
                    <span @if ($share >= 15) style="color:var(--danger)" @endif>
                        {{ number_format($share, 1) }}%
                    </span>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <div class="empty">
                        <x-icon name="tag" :size="28" />
                        <h3>Nothing was discounted</h3>
                        <p class="text-sm">Every bill in this range was charged in full.</p>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>Total</th>
                <th style="text-align:right">{{ number_format($rows->sum('bills')) }}</th>
                <th style="text-align:right">{{ number_format($rows->sum('line_discount'), 2) }}</th>
                <th style="text-align:right">{{ number_format($rows->sum('bill_discount'), 2) }}</th>
                <th style="text-align:right">{{ number_format($rows->sum('total_discount'), 2) }}</th>
                <th style="text-align:right">{{ number_format($rows->sum('billed'), 2) }}</th>
                <th style="text-align:right">—</th>
            </tr>
        </tfoot>
    @endif
</table>

<div style="margin-top:20px">
    <div class="form-section-title">Coupons redeemed</div>

    <table class="table">
        <thead>
            <tr>
                <th>Coupon</th>
                <th style="text-align:right">Used</th>
                <th style="text-align:right">Given away</th>
                <th style="text-align:right">Average</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($coupons as $coupon)
                <tr>
                    <td>
                        <strong>{{ $coupon->code }}</strong>
                        @if ($coupon->name)
                            <span class="text-xs text-muted" style="display:block">{{ $coupon->name }}</span>
                        @endif
                    </td>
                    <td style="text-align:right" class="text-sm">{{ number_format($coupon->uses) }}</td>
                    <td style="text-align:right"><strong>{{ number_format((float) $coupon->given, 2) }}</strong></td>
                    <td style="text-align:right" class="text-sm">
                        {{ number_format((float) $coupon->given / max(1, (int) $coupon->uses), 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-sm text-muted" style="padding:14px">
                        No coupon was redeemed in this range.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
