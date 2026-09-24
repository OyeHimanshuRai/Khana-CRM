{{-- Output tax, grouped the way a GST return wants it. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>HSN</th>
            <th style="text-align:right">Rate</th>
            <th style="text-align:right">Taxable</th>
            <th style="text-align:right">CGST</th>
            <th style="text-align:right">SGST</th>
            <th style="text-align:right">IGST</th>
            <th style="text-align:right">Cess</th>
            <th style="text-align:right">Total tax</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php
                $tax = (float) $row->cgst + (float) $row->sgst + (float) $row->igst + (float) $row->cess;
            @endphp
            <tr>
                <td><strong>{{ $row->hsn }}</strong></td>
                <td style="text-align:right" class="text-sm">
                    {{ rtrim(rtrim(number_format((float) $row->tax_rate, 2), '0'), '.') }}%
                </td>
                <td style="text-align:right">{{ $money($row->taxable) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->cgst) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->sgst) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->igst) }}</td>
                <td style="text-align:right" class="text-sm">
                    {{ (float) $row->cess > 0 ? $money($row->cess) : '—' }}
                </td>
                <td style="text-align:right"><strong>{{ $money($tax) }}</strong></td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <div class="empty">
                        <x-icon name="file" :size="26" />
                        <h3>No taxable sales in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th colspan="2">Total</th>
                <th style="text-align:right">{{ $money($rows->sum('taxable')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('cgst')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('sgst')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('igst')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('cess')) }}</th>
                <th style="text-align:right">
                    {{ $money($rows->sum('cgst') + $rows->sum('sgst') + $rows->sum('igst') + $rows->sum('cess')) }}
                </th>
            </tr>
        </tfoot>
    @endif
</table>
