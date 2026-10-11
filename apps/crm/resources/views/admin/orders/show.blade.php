@extends('admin.layouts.app')

@section('title', $order->order_number)

@section('content')
    <x-page-header
        :title="$order->order_number"
        :subtitle="($order->customer?->name ?? 'Guest').' · '.$order->placed_at?->format('d M Y, H:i')"
        :crumbs="['Sales' => null, 'Online Orders' => route('admin.orders.index'), $order->order_number => null]"
    >
        <x-slot:actions>
            @allows('sales.orders.print')
                <a class="btn btn-sm" href="{{ route('admin.orders.print', $order) }}" target="_blank" rel="noopener">
                    <x-icon name="file" :size="15" /> Print
                </a>
            @endallows

            @allows('sales.orders.approve')
                @if ($order->isPending())
                    <form method="POST" action="{{ route('admin.orders.confirm', $order) }}" data-ajax style="display:inline">
                        @csrf @method('PUT')
                        <button type="submit" class="btn btn-primary btn-sm"><x-icon name="check" :size="15" /> Confirm</button>
                    </form>
                @endif

                @if (! $order->hasInvoice() && ! $order->isCancelled())
                    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('mark-paid-panel').scrollIntoView({behavior:'smooth'})">
                        <x-icon name="wallet" :size="15" /> Mark paid
                    </button>
                @endif

                @if ($order->hasInvoice() && $order->status !== \App\Models\Order::DELIVERED && ! $order->isCancelled())
                    <form method="POST" action="{{ route('admin.orders.deliver', $order) }}" data-ajax style="display:inline">
                        @csrf @method('PUT')
                        <button type="submit" class="btn btn-sm"><x-icon name="truck" :size="15" /> Mark delivered</button>
                    </form>
                @endif
            @endallows

            @allows('sales.orders.reject')
                @if (! $order->isCancelled())
                    <a class="btn btn-sm is-danger"
                       href="#"
                       onclick="var r=prompt('Reason for cancelling this order?'); if(r){var f=document.getElementById('cancel-order-form'); f.querySelector('[name=reason]').value=r; f.requestSubmit();} return false;">
                        <x-icon name="x" :size="15" /> Cancel
                    </a>
                    <form id="cancel-order-form" method="POST" action="{{ route('admin.orders.cancel', $order) }}" data-ajax style="display:none">
                        @csrf @method('PUT')
                        <input type="hidden" name="reason">
                    </form>
                @endif
            @endallows
        </x-slot:actions>
    </x-page-header>

    @if ($order->isCancelled())
        <div class="pos-warning" style="margin-bottom:16px">
            <strong>Cancelled</strong>
            {{ $order->cancelled_at?->format('d M Y, H:i') }} — {{ $order->cancel_reason }}.
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="badge">{{ $order->statusLabel() }}</span>
                <span class="badge {{ $order->isPaid() ? 'badge-success' : 'badge-warning' }}" style="margin-left:6px">
                    {{ ucfirst($order->payment_status) }}
                </span>
                <span class="badge" style="margin-left:6px">{{ strtoupper($order->payment_method) }}</span>
            </div>
        </div>

        <div class="card-body">
            <dl class="sec-facts">
                <div><dt>Customer</dt><dd>{{ $order->customer?->name ?? '—' }}</dd></div>
                <div><dt>Mobile</dt><dd>{{ $order->customer?->mobile ?? '—' }}</dd></div>
                <div><dt>Shipping address</dt><dd>{{ $order->ship_recipient_name }}, {{ $order->shippingAddressLine() }} ({{ $order->ship_mobile }})</dd></div>
                @if ($order->customer_note)
                    <div><dt>Note</dt><dd>{{ $order->customer_note }}</dd></div>
                @endif
                @if ($order->invoice)
                    <div><dt>Invoice</dt><dd><a href="{{ route('admin.invoices.show', $order->invoice) }}">{{ $order->invoice->number }}</a></dd></div>
                @endif
            </dl>

            <div class="table-wrap" style="margin-top:16px">
                <table class="table table-list">
                    <thead>
                        <tr><th>Product</th><th>SKU</th><th style="text-align:right">Qty</th><th style="text-align:right">Unit price</th><th style="text-align:right">Line total</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($order->items as $item)
                            <tr>
                                <td>{{ $item->product_name }}</td>
                                <td class="text-sm text-muted">{{ $item->sku }}</td>
                                <td style="text-align:right">{{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }}</td>
                                <td style="text-align:right">₹{{ number_format($item->unit_price, 2) }}</td>
                                <td style="text-align:right">₹{{ number_format($item->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="max-width:280px;margin-left:auto;margin-top:14px">
                <div style="display:flex;justify-content:space-between;padding:4px 0"><span>Subtotal</span><span>₹{{ number_format($order->subtotal, 2) }}</span></div>
                @if ($order->discount_total > 0)
                    <div style="display:flex;justify-content:space-between;padding:4px 0">
                        <span>Discount {{ $order->coupon_code ? "($order->coupon_code)" : '' }}</span>
                        <span>&minus;₹{{ number_format($order->discount_total, 2) }}</span>
                    </div>
                @endif
                <div style="display:flex;justify-content:space-between;padding:8px 0;font-weight:700;border-top:1px solid var(--border)">
                    <span>Total</span><span>₹{{ number_format($order->grand_total, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    @allows('sales.orders.approve')
        @if (! $order->hasInvoice() && ! $order->isCancelled())
            <div class="card" id="mark-paid-panel" style="margin-top:16px">
                <div class="card-header"><div class="card-title">Mark paid &amp; raise invoice</div></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.orders.mark-paid', $order) }}" data-ajax>
                        @csrf @method('PUT')
                        <div class="settings-grid">
                            <div class="field">
                                <label for="paid-amount">Amount collected</label>
                                <input id="paid-amount" type="number" step="0.01" name="amount" class="form-control"
                                       value="{{ number_format((float) $order->grand_total, 2, '.', '') }}" required>
                            </div>
                            <div class="field">
                                <label for="paid-method">Method</label>
                                <select id="paid-method" name="method" class="form-control" required>
                                    @foreach ($paymentMethods as $key => $method)
                                        <option value="{{ $key }}" @selected($key === ($order->payment_method === 'cod' ? 'cash' : 'upi'))>{{ $method['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field field-full">
                                <label for="paid-ref">Reference (optional)</label>
                                <input id="paid-ref" type="text" name="transaction_ref" class="form-control">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Mark paid &amp; raise invoice</button>
                    </form>
                </div>
            </div>
        @endif
    @endallows

    {{--
        Every step this order took (§16).

        The wait column is what this is for. ActivityLog already records that
        somebody changed a status; what it cannot say is how long the order
        sat there, because its payload is a sentence. Here it is a column.
    --}}
    @if ($order->statusLogs->isNotEmpty())
        <div class="card" style="margin-top:14px">
            <div style="padding:14px 18px 0">
                <div class="form-section-title">Timeline</div>
            </div>

            <div class="table-wrap">
                <table class="table table-list">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Step</th>
                            <th>Waited</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->statusLogs as $step)
                            <tr>
                                <td class="text-sm">{{ $step->created_at?->format('j M, g:i a') }}</td>
                                <td class="text-sm">
                                    <strong>{{ $step->toLabel() }}</strong>
                                    @if ($step->from_status)
                                        <span class="text-xs text-muted" style="display:block">
                                            from {{ $step->fromLabel() }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-sm">{{ $step->waitLabel() }}</td>
                                <td class="text-sm">
                                    {{-- Null is honest: a scheduled sweep, a
                                         webhook and a guest's own tap have no
                                         member of staff behind them. --}}
                                    {{ $step->user?->name ?? 'System' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
