@extends('admin.layouts.app')

@section('title', $receipt->reference)

@section('content')
    <x-page-header
        :title="$receipt->reference"
        :subtitle="($receipt->supplier?->displayName() ?? '—').' · '.$receipt->received_on?->format('d M Y')"
        :crumbs="['Purchasing' => null, 'Goods Receipts' => route('admin.receipts.index'), $receipt->reference => null]"
    >
        <x-slot:actions>
            @if ($receipt->isEditable())
                @allows('purchasing.receipts.edit')
                    <a class="btn btn-sm" href="{{ route('admin.receipts.edit', $receipt) }}">
                        <x-icon name="edit" :size="15" /> Edit
                    </a>
                @endallows

                @allows('purchasing.receipts.approve')
                    <form method="POST" action="{{ route('admin.receipts.post', $receipt) }}"
                          data-ajax data-redirect-delay="600" style="display:inline"
                          onsubmit="return confirm('Post {{ $receipt->reference }}? The stock goes on the shelf and the supplier is billed.')">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-primary btn-sm">
                            <x-icon name="user-check" :size="15" /> Post
                        </button>
                    </form>
                @endallows
            @endif

            @if ($receipt->isPosted())
                @allows('finance.payments.create')
                    <a class="btn btn-sm" href="{{ route('admin.payments.index') }}">
                        <x-icon name="wallet" :size="15" /> Payments
                    </a>
                @endallows

                @allows('purchasing.returns.create')
                    <a class="btn btn-sm"
                       href="{{ route('admin.purchase-returns.create', ['receipt' => $receipt->id]) }}">
                        <x-icon name="inbox" :size="15" /> Take a return
                    </a>
                @endallows

                @allows('purchasing.receipts.delete')
                    <a class="btn btn-sm is-danger"
                       href="{{ route('admin.receipts.cancel.form', $receipt) }}"
                       data-modal="{{ route('admin.receipts.cancel.form', $receipt) }}"
                       data-modal-title="Cancel {{ $receipt->reference }}"
                       data-modal-sub="Takes the stock back off the shelf">
                        <x-icon name="x" :size="15" /> Cancel
                    </a>
                @endallows
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($receipt->status === App\Models\GoodsReceipt::CANCELLED)
        <div class="pos-warning" style="margin-bottom:16px">
            <strong>Cancelled</strong>
            {{ $receipt->cancelled_at?->format('d M Y, H:i') }} — {{ $receipt->cancel_reason }}.
            The stock has been taken back off the shelf and the supplier's account reversed.
        </div>
    @elseif ($receipt->isEditable())
        <div class="pos-warning" style="margin-bottom:16px;color:var(--warning);background:var(--warning-soft);border-left-color:var(--warning)">
            <strong>Draft.</strong>
            Nothing has landed on the shelf and the supplier has not been billed. Post it when the
            consignment has been checked.
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="badge {{ $receipt->statusTone() ? 'badge-'.$receipt->statusTone() : '' }}">
                    <span class="badge-dot"></span> {{ $receipt->statusLabel() }}
                </span>
                @if ($receipt->is_inter_state)
                    <span class="badge badge-info" style="margin-left:6px">Inter-state</span>
                @endif
            </div>
        </div>

        <div class="card-body">
            <dl class="sec-facts">
                <div><dt>Shop</dt><dd>{{ $receipt->shop?->name ?? '—' }}</dd></div>
                <div><dt>Warehouse</dt><dd>{{ $receipt->warehouse?->name ?? '—' }}</dd></div>
                <div>
                    <dt>Supplier</dt>
                    <dd>
                        @if ($receipt->supplier)
                            <a href="{{ route('admin.suppliers.show', $receipt->supplier) }}"
                               data-modal="{{ route('admin.suppliers.show', $receipt->supplier) }}"
                               data-modal-title="{{ $receipt->supplier->displayName() }}"
                               data-modal-sub="Supplier details"
                               data-modal-size="lg">{{ $receipt->supplier->displayName() }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div><dt>Bill number</dt><dd>{{ $receipt->bill_number ?: '—' }}</dd></div>
                <div><dt>Bill date</dt><dd>{{ $receipt->bill_date?->format('d M Y') ?? '—' }}</dd></div>
                <div><dt>Payment due</dt><dd>{{ $receipt->due_date?->format('d M Y') ?? '—' }}</dd></div>
                <div>
                    <dt>Against order</dt>
                    <dd>
                        @if ($receipt->purchaseOrder)
                            <a href="{{ route('admin.purchase-orders.show', $receipt->purchaseOrder) }}"
                               class="list-ref">{{ $receipt->purchaseOrder->reference }}</a>
                        @else
                            Direct receipt
                        @endif
                    </dd>
                </div>
                <div><dt>Entered by</dt><dd>{{ $receipt->created_by_name ?? '—' }}</dd></div>

                @if ($receipt->posted_at)
                    <div><dt>Posted by</dt><dd>{{ $receipt->posted_by_name ?? '—' }}</dd></div>
                    <div><dt>Posted on</dt><dd>{{ $receipt->posted_at->format('d M Y, H:i') }}</dd></div>
                @endif
            </dl>

            @if ($receipt->notes)
                <div style="margin-top:16px">
                    <div class="form-label">Notes</div>
                    <p class="text-sm text-muted">{{ $receipt->notes }}</p>
                </div>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header">
            <div>
                <div class="card-title">Lines</div>
                <div class="text-xs text-muted">
                    {{ $items->count() }} line(s). Landed cost includes this consignment's share of
                    freight, which is what the stock is valued at.
                </div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Batch</th>
                        <th style="text-align:right">Qty</th>
                        <th style="text-align:right">Free</th>
                        <th style="text-align:right">Cost</th>
                        <th style="text-align:right">Landed</th>
                        <th style="text-align:right">Tax</th>
                        <th style="text-align:right">Amount</th>
                        <th style="text-align:right">Margin</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        @php $margin = $item->marginPercent(); @endphp
                        <tr>
                            <td>
                                <strong>{{ $item->product_name }}</strong>
                                <span class="text-xs text-muted" style="display:block">{{ $item->sku }}</span>
                            </td>

                            <td class="text-sm">
                                {{ $item->batch_no ?: '—' }}
                                @if ($item->expiry_date)
                                    <span class="text-xs text-muted" style="display:block">
                                        exp {{ $item->expiry_date->format('M Y') }}
                                    </span>
                                @endif
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}
                                <span class="text-xs text-muted">{{ $item->unit_code }}</span>
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ (float) $item->free_quantity > 0
                                    ? rtrim(rtrim(number_format((float) $item->free_quantity, 3), '0'), '.')
                                    : '—' }}
                            </td>

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format((float) $item->unit_cost, 2) }}
                            </td>

                            <td style="text-align:right" class="text-sm">
                                <strong>₹{{ number_format((float) $item->landed_cost, 2) }}</strong>
                            </td>

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format($item->taxAmount(), 2) }}
                                <span class="text-xs text-muted" style="display:block">
                                    {{ rtrim(rtrim(number_format((float) $item->tax_rate, 2), '0'), '.') }}%
                                </span>
                            </td>

                            <td style="text-align:right">
                                <strong>₹{{ number_format((float) $item->line_total, 2) }}</strong>
                            </td>

                            <td style="text-align:right" class="text-sm">
                                @if ($margin === null)
                                    <span class="text-muted">—</span>
                                @else
                                    {{-- A supplier's price rise that has quietly
                                         made something unprofitable should be
                                         obvious on this screen. --}}
                                    <span style="color:var({{ $margin < 10 ? '--danger' : ($margin < 20 ? '--warning' : '--success') }})">
                                        {{ number_format($margin, 1) }}%
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card-body">
            <dl class="pos-totals" style="max-width:340px;margin-left:auto">
                <div><dt>Goods value</dt><dd>₹{{ number_format((float) $receipt->subtotal, 2) }}</dd></div>

                @if ((float) $receipt->discount_total > 0)
                    <div>
                        <dt>Discounts</dt>
                        <dd>− ₹{{ number_format((float) $receipt->discount_total, 2) }}</dd>
                    </div>
                @endif

                @if ($receipt->is_inter_state)
                    <div><dt>IGST</dt><dd>₹{{ number_format((float) $receipt->igst_total, 2) }}</dd></div>
                @else
                    <div><dt>CGST</dt><dd>₹{{ number_format((float) $receipt->cgst_total, 2) }}</dd></div>
                    <div><dt>SGST</dt><dd>₹{{ number_format((float) $receipt->sgst_total, 2) }}</dd></div>
                @endif

                @if ((float) $receipt->other_charges > 0)
                    <div>
                        <dt>Freight &amp; charges</dt>
                        <dd>₹{{ number_format((float) $receipt->other_charges, 2) }}</dd>
                    </div>
                @endif

                @if (abs((float) $receipt->round_off) > 0.004)
                    <div><dt>Round off</dt><dd>₹{{ number_format((float) $receipt->round_off, 2) }}</dd></div>
                @endif

                <div class="is-total">
                    <dt>Bill total</dt>
                    <dd>₹{{ number_format((float) $receipt->grand_total, 2) }}</dd>
                </div>

                <div><dt>Paid</dt><dd>₹{{ number_format((float) $receipt->paid_total, 2) }}</dd></div>

                @if ((float) $receipt->due_total > 0)
                    <div>
                        <dt>Still to pay</dt>
                        <dd style="color:var(--danger)">₹{{ number_format((float) $receipt->due_total, 2) }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>

    @if ($payments->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <div class="card-header">
                <div class="card-title">Payments made</div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>When</th>
                            <th>Method</th>
                            <th style="text-align:right">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td><span class="list-ref">{{ $payment->number }}</span></td>
                                <td class="text-sm">{{ $payment->paid_at?->format('d M Y') }}</td>
                                <td class="text-sm">{{ $payment->methodLabel() }}</td>
                                <td style="text-align:right">
                                    <strong>₹{{ number_format((float) $payment->amount, 2) }}</strong>
                                </td>
                                <td>
                                    <span class="badge {{ $payment->statusTone() ? 'badge-'.$payment->statusTone() : '' }}">
                                        {{ $payment->statusLabel() }}
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
