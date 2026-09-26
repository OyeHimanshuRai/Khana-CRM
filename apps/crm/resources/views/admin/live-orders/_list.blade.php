{{--
    The fragment the screen re-fetches every ten seconds.

    Oldest first and no pagination: this is a list of things somebody is
    waiting for, and a second page of it would mean the restaurant has bigger
    problems than a missing page link. The controller caps it at 200.
--}}

@php
    use App\Models\Order;

    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<div data-kds-board data-kds-latest="{{ $latest }}" data-kds-tickets="{{ $orders->count() }}">
    <div class="table-wrap">
        <table class="table table-list">
            <thead>
                <tr>
                    <th>Waiting</th>
                    <th>Order</th>
                    <th>Channel</th>
                    <th>Who</th>
                    <th>Stage</th>
                    <th style="text-align:right">Total</th>
                    <th class="col-action">Actions</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($orders as $order)
                    @php
                        $minutes = $order->kitchenMinutes();
                        $table = $order->tableSession?->table;
                    @endphp

                    {{-- data-placed lets kds.js tick the minutes between
                         fetches, so a screen nobody has touched for nine
                         seconds is not quietly nine seconds out of date. --}}
                    <tr data-kds-ticket="{{ $order->id }}"
                        data-placed="{{ optional($order->placed_at ?? $order->created_at)->timestamp }}"
                        data-allowed="{{ $late }}">

                        <td>
                            <strong data-kds-timer
                                    style="{{ $minutes >= $late ? 'color:var(--danger)' : '' }}">
                                {{ $minutes }}m
                            </strong>
                            <span class="text-xs text-muted" style="display:block">
                                {{ optional($order->placed_at ?? $order->created_at)->format('g:i a') }}
                            </span>
                        </td>

                        <td>
                            <a href="{{ route('admin.orders.show', $order) }}">
                                <strong>{{ $order->order_number }}</strong>
                            </a>
                            <span class="text-xs text-muted" style="display:block">
                                {{ $order->items_count }} {{ Str::plural('item', $order->items_count) }}
                            </span>
                        </td>

                        <td class="text-sm">
                            {{ $order->typeLabel() }}
                            @if ($table)
                                <span class="text-xs text-muted" style="display:block">
                                    {{ $table->floor?->name }} · {{ $table->code }}
                                </span>
                            @endif
                        </td>

                        <td class="text-sm">
                            {{-- A dine-in guest need not sign in, so the party's
                                 own name stands in for the account. --}}
                            {{ $order->customer?->name ?: ($order->guest_name ?: '—') }}
                            @php $mobile = $order->customer?->mobile ?: $order->guest_mobile; @endphp
                            @if ($mobile)
                                <span class="text-xs text-muted" style="display:block">{{ $mobile }}</span>
                            @endif
                        </td>

                        <td>
                            <span class="badge {{ $order->status === Order::READY ? 'badge-success' : '' }}">
                                {{ $order->statusLabel() }}
                            </span>
                        </td>

                        <td style="text-align:right">{{ $money($order->grand_total) }}</td>

                        <td class="col-action">
                            <div class="row-actions">
                                @if ($order->isDineIn() && $order->tableSession)
                                    @allows('pos.tables.view')
                                        <a class="btn btn-icon"
                                           href="{{ route('admin.table-bills.show', $order->tableSession) }}"
                                           title="Open the table's bill"
                                           aria-label="Bill for {{ $order->order_number }}">
                                            <x-icon name="wallet" :size="15" />
                                        </a>
                                    @endallows
                                @endif

                                <a class="btn btn-icon" href="{{ route('admin.orders.show', $order) }}"
                                   aria-label="Open {{ $order->order_number }}">
                                    <x-icon name="search" :size="15" />
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty">
                                <x-icon name="user-check" :size="28" />
                                <h3>Nobody is waiting</h3>
                                <p class="text-sm">
                                    Every order has been served, delivered or settled.
                                    New ones appear here on their own.
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
