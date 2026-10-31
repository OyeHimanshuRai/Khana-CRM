{{-- What the shop owes, and to whom. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Supplier</th>
            <th>Contact</th>
            <th style="text-align:right">Terms</th>
            <th style="text-align:right">We owe</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <strong>{{ $row->displayName() }}</strong>
                    <span class="text-xs text-muted" style="display:block">{{ $row->code }}</span>
                </td>
                <td class="text-sm">
                    {{ $row->contact_person ?: '—' }}
                    @if ($row->mobile)
                        <span class="text-xs text-muted" style="display:block">{{ $row->mobile }}</span>
                    @endif
                </td>
                <td style="text-align:right" class="text-sm">
                    {{ $row->credit_days > 0 ? $row->credit_days.' days' : 'Immediate' }}
                </td>
                <td style="text-align:right">
                    <strong style="color:var(--danger)">{{ $money($row->balance) }}</strong>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4">
                    <div class="empty">
                        <x-icon name="user-check" :size="26" />
                        <h3>Every supplier is settled</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th colspan="3">Total payable</th>
                <th style="text-align:right">{{ $money($rows->sum('balance')) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
