{{-- What was collected, and how. Cleared payments only. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $total = (float) $rows->sum('total');
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Method</th>
            <th style="text-align:right">Entries</th>
            <th style="text-align:right">Collected</th>
            <th style="text-align:right">Share</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <strong>{{ App\Models\Payment::METHODS[$row->method]['label'] ?? Str::headline($row->method) }}</strong>
                    @unless (App\Models\Payment::settlesImmediately($row->method))
                        <span class="text-xs text-muted" style="display:block">
                            cleared entries only
                        </span>
                    @endunless
                </td>
                <td style="text-align:right" class="text-sm">{{ number_format($row->entries) }}</td>
                <td style="text-align:right"><strong>{{ $money($row->total) }}</strong></td>
                <td style="text-align:right" class="text-sm">
                    {{ $total > 0 ? number_format((float) $row->total / $total * 100, 1).'%' : '—' }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4">
                    <div class="empty">
                        <x-icon name="wallet" :size="26" />
                        <h3>Nothing collected in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>Total</th>
                <th style="text-align:right">{{ number_format($rows->sum('entries')) }}</th>
                <th style="text-align:right">{{ $money($total) }}</th>
                <th style="text-align:right">100%</th>
            </tr>
        </tfoot>
    @endif
</table>
