<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Channel</th>
                <th>Customer</th>
                <th>Date</th>
                <th style="text-align:right">Total</th>
                <th>Payment</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td><a href="{{ route('admin.orders.show', $order) }}"><strong>{{ $order->order_number }}</strong></a></td>

                    <td class="text-sm">
                        {{ $order->typeLabel() }}
                        {{-- Which table, for a dine-in ticket. It is the thing
                             anybody looking one of these up already knows. --}}
                        @if ($order->tableSession?->table)
                            <span class="text-xs text-muted" style="display:block">
                                Table {{ $order->tableSession->table->code }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{--
                            A dine-in guest need not sign in, so the party's own
                            name stands in for the account. Better than a dash
                            on every table in the restaurant.
                        --}}
                        {{ $order->customer?->name ?: ($order->guest_name ?: '—') }}
                        @if ($order->customer?->mobile ?? $order->guest_mobile)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $order->customer?->mobile ?: $order->guest_mobile }}
                            </span>
                        @endif
                    </td>
                    <td class="text-sm">{{ $order->placed_at?->format('d M Y, H:i') }}</td>
                    <td style="text-align:right"><strong>₹{{ number_format($order->grand_total, 2) }}</strong></td>
                    <td class="text-sm">
                        <span class="badge {{ $order->isPaid() ? 'badge-success' : 'badge-warning' }}">
                            {{ ucfirst($order->payment_status) }}
                        </span>
                        {{-- Null for a table that has not asked for the bill
                             yet, which is a fact rather than a gap. --}}
                        @if ($order->payment_method)
                            <span class="text-xs text-muted" style="display:block">
                                {{ strtoupper($order->payment_method) }}
                            </span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $order->isCancelled() ? 'badge-danger' : '' }}">{{ $order->statusLabel() }}</span>
                    </td>
                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.orders.show', $order) }}" aria-label="View {{ $order->order_number }}">
                                <x-icon name="search" :size="15" />
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No orders yet</h3>
                            <p class="text-sm">
                                Orders from the storefront, the tables and the counter
                                all show up here.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$orders" label="orders" />
