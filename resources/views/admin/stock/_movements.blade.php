{{--
    The stock ledger for one product, rendered into the modal body.

    Newest first and capped at 200 rows: this is a "what just happened"
    screen, not an archive. The full history belongs in a report with a date
    range, where it can be paged and exported.
--}}

<div class="cat-view-body">
    <div class="sec-name">{{ $product->name }}</div>
    <div class="text-sm text-muted">
        <span class="list-ref">{{ $product->sku }}</span> ·
        on hand {{ $product->unit?->format($onHand) ?? number_format($onHand, 3) }}
    </div>
</div>

<div class="table-wrap" style="margin-top:14px">
    <table class="table">
        <thead>
            <tr>
                <th>When</th>
                <th>Movement</th>
                <th>Warehouse</th>
                <th>Batch</th>
                <th style="text-align:right">Change</th>
                <th style="text-align:right">Balance</th>
                <th>Reason</th>
                <th>By</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($movements as $movement)
                @php $inward = $movement->isInward(); @endphp
                <tr>
                    <td class="text-sm" style="white-space:nowrap">
                        {{ $movement->created_at?->format('d M Y') }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $movement->created_at?->format('H:i') }}
                        </span>
                    </td>

                    <td class="text-sm">{{ $movement->label() }}</td>

                    <td class="text-sm">{{ $movement->warehouse?->name ?? '—' }}</td>

                    <td class="text-sm">{{ $movement->batch?->batch_no ?? '—' }}</td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong style="color:var({{ $inward ? '--success' : '--danger' }})">
                            {{ $movement->signedQuantity() }}
                        </strong>
                    </td>

                    <td style="text-align:right" class="text-sm">
                        {{ number_format((float) $movement->balance_after, 3) }}
                    </td>

                    <td class="text-sm text-muted">
                        {{ $movement->reason ? Str::limit($movement->reason, 40) : '—' }}
                    </td>

                    <td class="text-sm text-muted">{{ $movement->user_name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <div class="empty">
                            <x-icon name="inbox" :size="26" />
                            <h3>No movements yet</h3>
                            <p class="text-sm">Nothing has moved this product in or out.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($movements->count() >= 200)
    <p class="text-xs text-muted" style="margin-top:10px">
        Showing the most recent 200 movements. Use the stock report for the full history.
    </p>
@endif

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>
</div>
