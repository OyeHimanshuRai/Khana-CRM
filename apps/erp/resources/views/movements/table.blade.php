{{-- Ledger rows. $movements, and $withItem to show the item column. --}}
@php use App\Support\Units; @endphp

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>When</th>
                @if ($withItem) <th>Item</th> @endif
                <th>What happened</th>
                <th>Store</th>
                <th style="text-align:right">Quantity</th>
                <th style="text-align:right">Worth</th>
                <th style="text-align:right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($movements as $movement)
                <tr>
                    <td class="text-sm">
                        {{ App\Support\Outlets::local($movement->occurred_at, $movement->outlet_id)->format('d M, H:i') }}
                        <div class="text-xs text-muted">{{ $movement->user?->name ?? 'From an order' }}</div>
                    </td>
                    @if ($withItem)
                        <td><a href="{{ route('stock.show', $movement->stock_item_id) }}">{{ $movement->item?->name }}</a></td>
                    @endif
                    <td>
                        {{ $movement->typeLabel() }}
                        @if ($movement->reason) <div class="text-xs text-muted">{{ $movement->reason }}</div> @endif
                    </td>
                    <td>
                        {{ $movement->store?->name }}
                        @if ($movement->batch) <div class="text-xs text-muted">Batch {{ $movement->batch->batch_no }}</div> @endif
                    </td>
                    <td style="text-align:right;{{ $movement->qty < 0 ? 'color:var(--danger)' : '' }}">
                        {{ $movement->qty > 0 ? '+' : '' }}{{ Units::show($movement->qty, $movement->item?->base_unit ?? 'pc') }}
                    </td>
                    <td style="text-align:right">&#8377;{{ Units::rupees($movement->value_paise) }}</td>
                    <td style="text-align:right" class="text-muted">{{ Units::show($movement->balance_qty, $movement->item?->base_unit ?? 'pc') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
