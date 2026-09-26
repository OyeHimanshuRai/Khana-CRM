@extends('admin.layouts.app')

@section('title', $order->reference)

@section('content')
    @php
        use App\Models\PurchaseOrder;

        $canApprove = auth()->user()->can('purchasing.purchase_orders.approve');
    @endphp

    <x-page-header
        :title="$order->reference"
        :subtitle="($order->supplier?->displayName() ?? '—').' · ordered '.$order->ordered_on?->format('d M Y')"
        :crumbs="['Purchasing' => null, 'Purchase Orders' => route('admin.purchase-orders.index'), $order->reference => null]"
    >
        <x-slot:actions>
            @if ($order->isEditable())
                @allows('purchasing.purchase_orders.edit')
                    <a class="btn btn-sm" href="{{ route('admin.purchase-orders.edit', $order) }}">
                        <x-icon name="edit" :size="15" /> Edit
                    </a>
                @endallows

                @if ($canApprove)
                    <form method="POST" action="{{ route('admin.purchase-orders.approve', $order) }}"
                          data-ajax data-redirect-delay="600" style="display:inline">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-primary btn-sm">
                            <x-icon name="user-check" :size="15" /> Approve
                        </button>
                    </form>
                @endif
            @endif

            @if ($order->isReceivable())
                @allows('purchasing.receipts.create')
                    <a class="btn btn-primary btn-sm"
                       href="{{ route('admin.receipts.create', ['order' => $order->id]) }}">
                        <x-icon name="truck" :size="15" /> Receive goods
                    </a>
                @endallows
            @endif

            @if (! in_array($order->status, [PurchaseOrder::RECEIVED, PurchaseOrder::CANCELLED, PurchaseOrder::PARTIAL], true))
                @allows('purchasing.purchase_orders.edit')
                    <form method="POST" action="{{ route('admin.purchase-orders.cancel', $order) }}"
                          data-ajax data-redirect-delay="600" style="display:inline"
                          onsubmit="return confirm('Cancel {{ $order->reference }}?')">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-sm is-danger">
                            <x-icon name="x" :size="15" /> Cancel
                        </button>
                    </form>
                @endallows
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="badge {{ $order->statusTone() ? 'badge-'.$order->statusTone() : '' }}">
                    <span class="badge-dot"></span> {{ $order->statusLabel() }}
                </span>
            </div>
        </div>

        <div class="card-body">
            <dl class="sec-facts">
                <div><dt>Shop</dt><dd>{{ $order->shop?->name ?? '—' }}</dd></div>
                <div><dt>Supplier</dt><dd>{{ $order->supplier?->displayName() ?? '—' }}</dd></div>
                <div><dt>Deliver to</dt><dd>{{ $order->warehouse?->name ?? 'Decide on arrival' }}</dd></div>
                <div><dt>Ordered on</dt><dd>{{ $order->ordered_on?->format('d M Y') }}</dd></div>
                <div><dt>Expected</dt><dd>{{ $order->expected_on?->format('d M Y') ?? '—' }}</dd></div>
                <div><dt>Raised by</dt><dd>{{ $order->created_by_name ?? '—' }}</dd></div>

                @if ($order->approved_at)
                    <div><dt>Approved by</dt><dd>{{ $order->approved_by_name ?? '—' }}</dd></div>
                    <div><dt>Approved on</dt><dd>{{ $order->approved_at->format('d M Y, H:i') }}</dd></div>
                @endif

                <div><dt>Order value</dt><dd>₹{{ number_format((float) $order->grand_total, 2) }}</dd></div>
            </dl>

            @if ($order->notes)
                <div style="margin-top:16px">
                    <div class="form-label">Notes</div>
                    <p class="text-sm text-muted">{{ $order->notes }}</p>
                </div>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header">
            <div>
                <div class="card-title">Lines</div>
                <div class="text-xs text-muted">
                    {{ $items->count() }} line(s) ·
                    {{ rtrim(rtrim(number_format($order->outstandingQuantity(), 3), '0'), '.') }} still to come
                </div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:right">Ordered</th>
                        <th style="text-align:right">Received</th>
                        <th style="text-align:right">Outstanding</th>
                        <th style="text-align:right">Unit cost</th>
                        <th style="text-align:right">Value</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        @php $outstanding = $item->outstandingQuantity(); @endphp
                        <tr>
                            <td>
                                <strong>{{ $item->product_name }}</strong>
                                <span class="text-xs text-muted" style="display:block">{{ $item->sku }}</span>
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}
                                <span class="text-xs text-muted">{{ $item->unit_code }}</span>
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ rtrim(rtrim(number_format((float) $item->received_quantity, 3), '0'), '.') }}
                            </td>

                            <td style="text-align:right">
                                @if ($outstanding <= 0.0005)
                                    <span style="color:var(--success)">complete</span>
                                @else
                                    <strong style="color:var(--warning)">
                                        {{ rtrim(rtrim(number_format($outstanding, 3), '0'), '.') }}
                                    </strong>
                                @endif
                            </td>

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format((float) $item->unit_cost, 2) }}
                            </td>

                            <td style="text-align:right">
                                <strong>₹{{ number_format((float) $item->line_total, 2) }}</strong>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($receipts->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <div class="card-header">
                <div>
                    <div class="card-title">Received against this order</div>
                    <div class="text-xs text-muted">{{ $receipts->count() }} consignment(s)</div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Received</th>
                            <th>Bill</th>
                            <th style="text-align:right">Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipts as $receipt)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.receipts.show', $receipt) }}" class="list-ref">
                                        {{ $receipt->reference }}
                                    </a>
                                </td>
                                <td class="text-sm">{{ $receipt->received_on?->format('d M Y') }}</td>
                                <td class="text-sm">{{ $receipt->bill_number ?: '—' }}</td>
                                <td style="text-align:right">
                                    <strong>₹{{ number_format((float) $receipt->grand_total, 2) }}</strong>
                                </td>
                                <td>
                                    <span class="badge {{ $receipt->statusTone() ? 'badge-'.$receipt->statusTone() : '' }}">
                                        {{ $receipt->statusLabel() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
