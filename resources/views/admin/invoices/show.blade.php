@extends('admin.layouts.app')

@section('title', $invoice->number)

@section('content')
    <x-page-header
        :title="$invoice->number"
        :subtitle="$invoice->billedTo().' · '.$invoice->invoiced_at?->format('d M Y, H:i')"
        :crumbs="['Sales' => null, 'Invoices' => route('admin.invoices.index'), $invoice->number => null]"
    >
        <x-slot:actions>
            @allows('sales.invoices.print')
                <a class="btn btn-sm" href="{{ route('admin.invoices.print', $invoice) }}"
                   target="_blank" rel="noopener">
                    <x-icon name="file" :size="15" /> Print
                </a>

                <a class="btn btn-sm" href="{{ route('admin.invoices.print', [$invoice, 'format' => 'receipt']) }}"
                   target="_blank" rel="noopener">
                    <x-icon name="file" :size="15" /> Receipt
                </a>
            @endallows

            @if (! $invoice->isCancelled())
                @allows('sales.returns.create')
                    <a class="btn btn-sm"
                       href="{{ route('admin.sales-returns.create', ['invoice' => $invoice->id]) }}">
                        <x-icon name="inbox" :size="15" /> Take a return
                    </a>
                @endallows

                @if ((float) $invoice->due_total > 0 && $invoice->customer)
                    @allows('finance.payments.create')
                        <a class="btn btn-primary btn-sm"
                           href="{{ route('admin.payments.create', ['invoice' => $invoice->id]) }}"
                           data-modal="{{ route('admin.payments.create', ['invoice' => $invoice->id]) }}"
                           data-modal-title="Record a Payment"
                           data-modal-sub="{{ $invoice->number }}"
                           data-modal-size="lg">
                            <x-icon name="wallet" :size="15" /> Take payment
                        </a>
                    @endallows
                @endif

                @allows('sales.invoices.cancel')
                    <a class="btn btn-sm is-danger"
                       href="{{ route('admin.invoices.cancel.form', $invoice) }}"
                       data-modal="{{ route('admin.invoices.cancel.form', $invoice) }}"
                       data-modal-title="Cancel {{ $invoice->number }}"
                       data-modal-sub="This puts the stock back and reverses the account">
                        <x-icon name="x" :size="15" /> Cancel
                    </a>
                @endallows
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($invoice->isCancelled())
        <div class="pos-warning" style="margin-bottom:16px">
            <strong>Cancelled</strong>
            {{ $invoice->cancelled_at?->format('d M Y, H:i') }} —
            {{ $invoice->cancel_reason }}.
            The stock has been returned and the customer's account reversed.
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="badge {{ $invoice->statusTone() ? 'badge-'.$invoice->statusTone() : '' }}">
                    <span class="badge-dot"></span> {{ $invoice->statusLabel() }}
                </span>

                <span class="badge" style="margin-left:6px">{{ $invoice->channelLabel() }}</span>

                @if ($invoice->is_credit)
                    <span class="badge badge-warning" style="margin-left:6px">Credit sale</span>
                @endif

                @if ($invoice->isOverdue())
                    <span class="badge badge-danger" style="margin-left:6px">
                        {{ $invoice->daysOverdue() }} days overdue
                    </span>
                @endif
            </div>
        </div>

        <div class="card-body">
            <dl class="sec-facts">
                <div><dt>Shop</dt><dd>{{ $shop?->name ?? '—' }}</dd></div>
                <div><dt>Sold from</dt><dd>{{ $invoice->warehouse?->name ?? '—' }}</dd></div>
                <div>
                    <dt>Customer</dt>
                    <dd>
                        @if ($invoice->customer)
                            <a href="{{ route('admin.customers.show', $invoice->customer) }}"
                               data-modal="{{ route('admin.customers.show', $invoice->customer) }}"
                               data-modal-title="{{ $invoice->customer->name }}"
                               data-modal-sub="Customer details"
                               data-modal-size="lg">{{ $invoice->customer_name }}</a>
                        @else
                            {{ $invoice->billedTo() }}
                        @endif
                    </dd>
                </div>
                <div><dt>Mobile</dt><dd>{{ $invoice->customer_mobile ?: '—' }}</dd></div>
                <div><dt>GSTIN</dt><dd>{{ $invoice->customer_gstin ?: '—' }}</dd></div>
                <div><dt>Place of supply</dt><dd>{{ $invoice->place_of_supply ?: '—' }}</dd></div>
                <div>
                    <dt>Tax basis</dt>
                    <dd>{{ $invoice->is_inter_state ? 'Inter-state (IGST)' : 'Within state (CGST + SGST)' }}</dd>
                </div>
                <div><dt>Billed by</dt><dd>{{ $invoice->created_by_name ?? '—' }}</dd></div>

                @if ($invoice->due_date)
                    <div><dt>Due date</dt><dd>{{ $invoice->due_date->format('d M Y') }}</dd></div>
                @endif
            </dl>

            @if ($invoice->notes)
                <div style="margin-top:16px">
                    <div class="form-label">Notes</div>
                    <p class="text-sm text-muted">{{ $invoice->notes }}</p>
                </div>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header">
            <div>
                <div class="card-title">Items</div>
                <div class="text-xs text-muted">{{ $invoice->items->count() }} line(s)</div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Batch</th>
                        <th style="text-align:right">Qty</th>
                        <th style="text-align:right">Rate</th>
                        <th style="text-align:right">Discount</th>
                        <th style="text-align:right">Taxable</th>
                        <th style="text-align:right">Tax</th>
                        <th style="text-align:right">Total</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td>
                                <strong>{{ $item->product_name }}</strong>
                                <span class="text-xs text-muted" style="display:block">
                                    {{ $item->sku }}@if ($item->hsn_code) · HSN {{ $item->hsn_code }}@endif
                                </span>
                            </td>

                            <td class="text-sm">
                                {{ $item->batch_no ?: '—' }}
                                @if ($item->expiry_date)
                                    <span class="text-xs text-muted" style="display:block">
                                        exp {{ $item->expiry_date->format('M Y') }}
                                    </span>
                                @endif
                            </td>

                            <td style="text-align:right" class="text-sm">{{ $item->quantityLabel() }}</td>

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format((float) $item->unit_price, 2) }}
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ (float) $item->discount_amount > 0
                                    ? '₹'.number_format((float) $item->discount_amount, 2)
                                    : '—' }}
                            </td>

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format((float) $item->taxable_value, 2) }}
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
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card-body">
            <dl class="pos-totals" style="max-width:340px;margin-left:auto">
                <div><dt>Sub-total</dt><dd>₹{{ number_format((float) $invoice->subtotal, 2) }}</dd></div>

                @if ((float) $invoice->line_discount_total > 0)
                    <div>
                        <dt>Line discounts</dt>
                        <dd>− ₹{{ number_format((float) $invoice->line_discount_total, 2) }}</dd>
                    </div>
                @endif

                @if ((float) $invoice->invoice_discount > 0)
                    <div>
                        <dt>Bill discount</dt>
                        <dd>− ₹{{ number_format((float) $invoice->invoice_discount, 2) }}</dd>
                    </div>
                @endif

                @if ($invoice->is_inter_state)
                    <div><dt>IGST</dt><dd>₹{{ number_format((float) $invoice->igst_total, 2) }}</dd></div>
                @else
                    <div><dt>CGST</dt><dd>₹{{ number_format((float) $invoice->cgst_total, 2) }}</dd></div>
                    <div><dt>SGST</dt><dd>₹{{ number_format((float) $invoice->sgst_total, 2) }}</dd></div>
                @endif

                @if ((float) $invoice->cess_total > 0)
                    <div><dt>Cess</dt><dd>₹{{ number_format((float) $invoice->cess_total, 2) }}</dd></div>
                @endif

                @if (abs((float) $invoice->round_off) > 0.004)
                    <div><dt>Round off</dt><dd>₹{{ number_format((float) $invoice->round_off, 2) }}</dd></div>
                @endif

                <div class="is-total">
                    <dt>Total</dt>
                    <dd>₹{{ number_format((float) $invoice->grand_total, 2) }}</dd>
                </div>

                <div><dt>Paid</dt><dd>₹{{ number_format((float) $invoice->paid_total, 2) }}</dd></div>

                @if ((float) $invoice->due_total > 0)
                    <div>
                        <dt>Outstanding</dt>
                        <dd style="color:var(--danger)">₹{{ number_format((float) $invoice->due_total, 2) }}</dd>
                    </div>
                @endif
            </dl>

            @allows('reports.profit_report.view')
                <p class="text-xs text-muted" style="margin-top:14px;text-align:right">
                    Estimated gross profit ₹{{ number_format($invoice->grossProfit(), 2) }}, at the cost
                    captured when the sale was made.
                </p>
            @endallows
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header">
            <div>
                <div class="card-title">Payments</div>
                <div class="text-xs text-muted">
                    Money taken against this invoice. Payments are never edited — a correction is a
                    reversing entry.
                </div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>When</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th style="text-align:right">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($payments as $payment)
                        <tr>
                            <td><span class="list-ref">{{ $payment->number }}</span></td>
                            <td class="text-sm">{{ $payment->paid_at?->format('d M Y, H:i') }}</td>
                            <td class="text-sm">{{ $payment->methodLabel() }}</td>
                            <td class="text-sm text-muted">{{ $payment->transaction_ref ?: '—' }}</td>
                            <td style="text-align:right">
                                <strong>₹{{ number_format((float) $payment->amount, 2) }}</strong>
                            </td>
                            <td>
                                <span class="badge {{ $payment->statusTone() ? 'badge-'.$payment->statusTone() : '' }}">
                                    {{ $payment->statusLabel() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty">
                                    <x-icon name="wallet" :size="26" />
                                    <h3>Nothing taken yet</h3>
                                    <p class="text-sm">This invoice is entirely on account.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
