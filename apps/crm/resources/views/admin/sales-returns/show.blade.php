@extends('admin.layouts.app')

@section('title', $return->reference)

@section('content')
    <x-page-header
        :title="$return->reference"
        :subtitle="($return->customer_name ?: 'Walk-in').' · returned '.$return->returned_on?->format('d M Y')"
        :crumbs="['Sales' => null, 'Sales Returns' => route('admin.sales-returns.index'), $return->reference => null]"
    >
        <x-slot:actions>
            @if ($return->isEditable())
                @allows('sales.returns.approve')
                    <form method="POST" action="{{ route('admin.sales-returns.approve', $return) }}"
                          data-ajax data-redirect-delay="600" style="display:inline"
                          onsubmit="return confirm('Accept {{ $return->reference }}? Resalable goods go back on the shelf and the customer is settled.')">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-primary btn-sm">
                            <x-icon name="user-check" :size="15" /> Accept
                        </button>
                    </form>
                @endallows

                @allows('sales.returns.reject')
                    <a class="btn btn-sm is-danger"
                       href="{{ route('admin.sales-returns.reject.form', $return) }}"
                       data-modal="{{ route('admin.sales-returns.reject.form', $return) }}"
                       data-modal-title="Refuse {{ $return->reference }}"
                       data-modal-sub="Nothing moves">
                        <x-icon name="x" :size="15" /> Refuse
                    </a>
                @endallows
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="badge {{ $return->statusTone() ? 'badge-'.$return->statusTone() : '' }}">
                    <span class="badge-dot"></span> {{ $return->statusLabel() }}
                </span>
            </div>
        </div>

        <div class="card-body">
            <dl class="sec-facts">
                <div><dt>Shop</dt><dd>{{ $return->shop?->name ?? '—' }}</dd></div>
                <div><dt>Back into</dt><dd>{{ $return->warehouse?->name ?? '—' }}</dd></div>
                <div>
                    <dt>Invoice</dt>
                    <dd>
                        @if ($return->invoice)
                            <a href="{{ route('admin.invoices.show', $return->invoice) }}" class="list-ref">
                                {{ $return->invoice->number }}
                            </a>
                        @else
                            No bill
                        @endif
                    </dd>
                </div>
                <div><dt>Customer</dt><dd>{{ $return->customer_name ?: 'Walk-in' }}</dd></div>
                <div><dt>Reason</dt><dd>{{ $return->reasonLabel() }}</dd></div>
                <div><dt>Settlement</dt><dd>{{ $return->settlementLabel() }}</dd></div>
                <div><dt>Value</dt><dd>₹{{ number_format((float) $return->grand_total, 2) }}</dd></div>

                @if ((float) $return->refund_amount > 0)
                    <div>
                        <dt>Refunded</dt>
                        <dd>₹{{ number_format((float) $return->refund_amount, 2) }}</dd>
                    </div>
                @endif

                <div><dt>Raised by</dt><dd>{{ $return->created_by_name ?? '—' }}</dd></div>

                @if ($return->approved_at)
                    <div><dt>Decided by</dt><dd>{{ $return->approved_by_name ?? '—' }}</dd></div>
                    <div><dt>Decided on</dt><dd>{{ $return->approved_at->format('d M Y, H:i') }}</dd></div>
                @endif
            </dl>

            @if ($return->reason)
                <div style="margin-top:16px">
                    <div class="form-label">Notes</div>
                    <p class="text-sm text-muted">{{ $return->reason }}</p>
                </div>
            @endif

            @if ($return->review_note)
                <div style="margin-top:14px">
                    <div class="form-label">Reviewer's note</div>
                    <p class="text-sm text-muted">{{ $return->review_note }}</p>
                </div>
            @endif

            @if ($return->isAccepted() && $return->writtenOffValue() > 0.004)
                <p class="pos-warning" style="margin-top:14px">
                    ₹{{ number_format($return->writtenOffValue(), 2) }} of what came back was not fit to
                    sell and has been written off. It never touched sellable stock.
                </p>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header">
            <div>
                <div class="card-title">What came back</div>
                <div class="text-xs text-muted">{{ $items->count() }} line(s)</div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Batch</th>
                        <th style="text-align:right">Quantity</th>
                        <th>Condition</th>
                        <th style="text-align:right">Unit credit</th>
                        <th style="text-align:right">Tax</th>
                        <th style="text-align:right">Credit</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>
                                <strong>{{ $item->product_name }}</strong>
                                <span class="text-xs text-muted" style="display:block">{{ $item->sku }}</span>
                            </td>

                            <td class="text-sm">{{ $item->batch_no ?: '—' }}</td>

                            <td style="text-align:right" class="text-sm">
                                {{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}
                                <span class="text-xs text-muted">{{ $item->unit_code }}</span>
                            </td>

                            <td>
                                <span class="badge {{ $item->isResalable() ? 'badge-success' : 'badge-danger' }}">
                                    {{ $item->conditionLabel() }}
                                </span>
                            </td>

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format((float) $item->unit_price, 2) }}
                            </td>

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format((float) $item->tax_amount, 2) }}
                            </td>

                            <td style="text-align:right">
                                <strong>₹{{ number_format((float) $item->line_total, 2) }}</strong>
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                <tfoot>
                    <tr>
                        <th colspan="6">Total credited</th>
                        <th style="text-align:right">₹{{ number_format((float) $return->grand_total, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endsection
