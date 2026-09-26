{{-- Batches expiring soon, or already gone, that still hold stock. --}}

@php
    $rows = $data['rows'];
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Product</th>
            @if ($showsShop)<th>Shop</th>@endif
            <th>Batch</th>
            <th>Expiry</th>
            <th style="text-align:right">Days left</th>
            <th style="text-align:right">On hand</th>
            <th class="col-action">Act</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php
                $days = $row->daysToExpiry();
                $tone = $row->expiryTone();
            @endphp
            <tr>
                <td>
                    <strong>{{ $row->product?->name ?? '—' }}</strong>
                    <span class="text-xs text-muted" style="display:block">{{ $row->product?->sku }}</span>
                </td>

                @if ($showsShop)
                    <td class="text-sm">{{ $row->shop?->name ?? '—' }}</td>
                @endif

                <td class="text-sm"><span class="list-ref">{{ $row->batch_no }}</span></td>

                <td class="text-sm">
                    <span @if ($tone) class="badge badge-{{ $tone }}" @endif>
                        {{ $row->expiryLabel() }}
                    </span>
                </td>

                <td style="text-align:right">
                    @if ($days < 0)
                        <strong style="color:var(--danger)">{{ abs($days) }} days ago</strong>
                    @elseif ($days === 0)
                        <strong style="color:var(--danger)">today</strong>
                    @else
                        <strong style="color:var(--warning)">{{ $days }}</strong>
                    @endif
                </td>

                <td style="text-align:right" class="text-sm">{{ $qty($row->on_hand ?? 0) }}</td>

                <td class="col-action">
                    @allows('inventory.batches.edit')
                        @if ($row->is_active)
                            {{-- Blocking it stops the counter selling it while
                                 leaving the stock countable, which is what a
                                 stock-take needs. --}}
                            <form method="POST" action="{{ route('admin.batches.status', $row) }}"
                                  data-ajax style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-icon is-danger"
                                        title="Block this lot from sale"
                                        aria-label="Block batch {{ $row->batch_no }}">
                                    <x-icon name="lock" :size="15" />
                                </button>
                            </form>
                        @else
                            <span class="badge badge-danger">Blocked</span>
                        @endif
                    @endallows
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $showsShop ? 7 : 6 }}">
                    <div class="empty">
                        <x-icon name="user-check" :size="26" />
                        <h3>Nothing expiring</h3>
                        <p class="text-sm">
                            No batch holding stock falls due within the window.
                        </p>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
