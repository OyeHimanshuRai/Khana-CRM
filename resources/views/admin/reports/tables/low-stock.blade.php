{{-- Products at or below their reorder level - the buying list. --}}

@php
    $rows = $data['rows'];
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Product</th>
            <th style="text-align:right">On hand</th>
            <th style="text-align:right">Reorder level</th>
            <th style="text-align:right">Short by</th>
            <th style="text-align:right">Last cost</th>
            <th class="col-action">Order</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php
                $onHand = (float) ($row->on_hand ?? 0);
                $short = max(0, (float) $row->reorder_level - $onHand);
            @endphp
            <tr>
                <td>
                    <strong>{{ $row->name }}</strong>
                    <span class="text-xs text-muted" style="display:block">{{ $row->sku }}</span>
                </td>
                <td style="text-align:right">
                    <strong style="color:var({{ $onHand <= 0 ? '--danger' : '--warning' }})">
                        {{ $qty($onHand) }}
                    </strong>
                </td>
                <td style="text-align:right" class="text-sm">{{ $qty($row->reorder_level) }}</td>
                <td style="text-align:right" class="text-sm">{{ $qty($short) }}</td>
                <td style="text-align:right" class="text-sm">
                    ₹{{ number_format((float) $row->purchase_price, 2) }}
                </td>
                <td class="col-action">
                    @allows('purchasing.purchase_orders.create')
                        {{-- Straight from the shortfall to the order that
                             fixes it, which is the only reason this report
                             is opened. --}}
                        <a class="btn btn-icon" href="{{ route('admin.purchase-orders.create') }}"
                           title="Raise a purchase order"
                           aria-label="Order {{ $row->name }}">
                            <x-icon name="truck" :size="15" />
                        </a>
                    @endallows
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <div class="empty">
                        <x-icon name="user-check" :size="26" />
                        <h3>Nothing needs reordering</h3>
                        <p class="text-sm">
                            Every product with a reorder level is above it.
                        </p>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
