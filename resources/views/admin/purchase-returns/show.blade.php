@extends('admin.layouts.app')

@section('title', $return->reference)

@section('content')
    <x-page-header
        :title="$return->reference"
        :subtitle="($return->supplier_name ?: 'Supplier').' · returned '.$return->returned_on?->format('d M Y')"
        :crumbs="['Purchasing' => null, 'Purchase Returns' => route('admin.purchase-returns.index'), $return->reference => null]"
    >
        <x-slot:actions>
            @if ($return->isEditable())
                @allows('purchasing.returns.approve')
                    <form method="POST" action="{{ route('admin.purchase-returns.approve', $return) }}"
                          data-ajax data-redirect-delay="600" style="display:inline"
                          onsubmit="return confirm('Accept {{ $return->reference }}? The goods leave the shelf and the supplier is settled.')">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-primary btn-sm">
                            <x-icon name="user-check" :size="15" /> Accept
                        </button>
                    </form>
                @endallows

                @allows('purchasing.returns.reject')
                    <a class="btn btn-sm is-danger"
                       href="{{ route('admin.purchase-returns.reject.form', $return) }}"
                       data-modal="{{ route('admin.purchase-returns.reject.form', $return) }}"
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
                <div><dt>Issuing from</dt><dd>{{ $return->warehouse?->name ?? '—' }}</dd></div>
                <div>
                    <dt>Receipt</dt>
                    <dd>
                        @if ($return->goodsReceipt)
                            <a href="{{ route('admin.receipts.show', $return->goodsReceipt) }}" class="list-ref">
                                {{ $return->goodsReceipt->reference }}
                            </a>
                        @else
                            No receipt
                        @endif
                    </dd>
                </div>
                <div><dt>Supplier</dt><dd>{{ $return->supplier_name ?: $return->supplier?->displayName() ?: '—' }}</dd></div>
                <div><dt>Reason</dt><dd>{{ $return->reasonLabel() }}</dd></div>
                <div><dt>Settlement</dt><dd>{{ $return->settlementLabel() }}</dd></div>
                <div><dt>Value</dt><dd>₹{{ number_format((float) $return->grand_total, 2) }}</dd></div>

                @if ((float) $return->refund_amount > 0)
                    <div>
                        <dt>Received back</dt>
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
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header">
            <div>
                <div class="card-title">What is going back</div>
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
                        <th style="text-align:right">Unit cost</th>
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

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format((float) $item->unit_cost, 2) }}
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
                        <th colspan="5">Total credited</th>
                        <th style="text-align:right">₹{{ number_format((float) $return->grand_total, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endsection
