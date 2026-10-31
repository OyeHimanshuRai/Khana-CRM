{{--
    Cancelled orders (§13).

    Rows rather than a total, because the only useful version of this report is
    the one somebody reads line by line. A pattern in the reasons, or in who
    cancelled, is the entire point — and "14 cancellations" says nothing
    anybody can act on.
--}}

@php
    $rows = $data['rows'];
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Order</th>
            <th>Cancelled</th>
            <th>By</th>
            <th>Reason</th>
            <th style="text-align:right">Value</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $order)
            <tr>
                <td>
                    <strong>{{ $order->order_number }}</strong>
                    <span class="text-xs text-muted" style="display:block">
                        {{ $order->customer?->name ?: ($order->guest_name ?: 'Walk-in') }}
                        · {{ ucfirst(str_replace('_', ' ', (string) $order->order_type)) }}
                    </span>
                </td>

                <td class="text-sm">
                    {{ $order->cancelled_at?->format('j M, g:i a') ?? '—' }}
                    @if ($order->cancelled_at && $order->placed_at)
                        <span class="text-xs text-muted" style="display:block">
                            {{-- How long it lived. A ticket cancelled after
                                 forty minutes was probably cooked. --}}
                            {{ $order->placed_at->diffForHumans($order->cancelled_at, true) }} after placing
                        </span>
                    @endif
                </td>

                <td class="text-sm">{{ $order->cancelledBy?->name ?? '—' }}</td>

                <td class="text-sm">
                    @if ($order->cancel_reason)
                        {{ $order->cancel_reason }}
                    @else
                        {{-- Worth showing as an absence: a cancellation with no
                             reason is the one an auditor asks about. --}}
                        <span style="color:var(--warning)">No reason recorded</span>
                    @endif
                </td>

                <td style="text-align:right"><strong>{{ number_format((float) $order->grand_total, 2) }}</strong></td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <div class="empty">
                        <x-icon name="user-check" :size="28" />
                        <h3>Nothing was cancelled</h3>
                        <p class="text-sm">Every order in this range went through.</p>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>{{ number_format($rows->count()) }} cancelled</th>
                <th></th>
                <th></th>
                <th></th>
                <th style="text-align:right">{{ number_format($rows->sum('grand_total'), 2) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
