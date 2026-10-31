@extends('admin.layouts.app')

@section('title', 'Take a Return')

@section('content')
    <x-page-header
        title="Take a Return"
        :subtitle="$receipt
            ? 'Against '.$receipt->reference.' · '.$receipt->supplier?->displayName()
            : 'Find the receipt the goods arrived on.'"
        :crumbs="['Purchasing' => null, 'Purchase Returns' => route('admin.purchase-returns.index'), 'New' => null]"
    />

    @unless ($receipt)
        {{--
            No receipt yet, so the screen is a search for one. A return
            raised against the original receipt gets its costs right for
            free; guessing them is how a credit note ends up disagreeing
            with what was actually billed.
        --}}
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Which receipt?</div>
                    <div class="text-xs text-muted">
                        Search by reference, bill number or supplier, or pick a recent one below.
                    </div>
                </div>
            </div>

            <div class="card-body">
                <form method="GET" action="{{ route('admin.receipts.index') }}" class="list-search"
                      style="max-width:460px">
                    <x-icon name="search" :size="15" />
                    <label for="pr-receipt" class="sr-only">Search receipts</label>
                    <input id="pr-receipt" type="search" name="q"
                           placeholder="Receipt reference, bill number or supplier…" autocomplete="off">
                </form>
                <div class="form-hint" style="margin-top:6px">
                    The receipts list opens with a <strong>Take a return</strong> action on each row.
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>When</th>
                            <th>Supplier</th>
                            <th style="text-align:right">Total</th>
                            <th class="col-action">Return</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recent as $row)
                            <tr>
                                <td><span class="list-ref">{{ $row->reference }}</span></td>
                                <td class="text-sm">{{ $row->received_on?->format('d M Y') }}</td>
                                <td class="text-sm">{{ $row->supplier?->displayName() }}</td>
                                <td style="text-align:right">
                                    <strong>₹{{ number_format((float) $row->grand_total, 2) }}</strong>
                                </td>
                                <td class="col-action">
                                    <a class="btn btn-sm"
                                       href="{{ route('admin.purchase-returns.create', ['receipt' => $row->id]) }}">
                                        Take a return
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="empty">
                                        <x-icon name="file" :size="26" />
                                        <h3>No receipts yet</h3>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('admin.purchase-returns.store') }}"
              data-ajax data-redirect-delay="500">
            @csrf
            <input type="hidden" name="goods_receipt_id" value="{{ $receipt->id }}">

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Return</div>
                        <div class="text-xs text-muted">
                            Reference <span class="list-ref">{{ $reference }}</span> ·
                            against {{ $receipt->reference }} of
                            {{ $receipt->received_on?->format('d M Y') }}
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="settings-grid">
                        <div class="field">
                            <label for="pr-warehouse">Issuing from</label>
                            <select id="pr-warehouse" name="warehouse_id" class="form-control" required
                                    aria-invalid="false">
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}"
                                        @selected($receipt->warehouse_id === $warehouse->id)>
                                        {{ $warehouse->name }} ({{ $warehouse->code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label for="pr-date">Returned on</label>
                            <input id="pr-date" type="date" name="returned_on" class="form-control" required
                                   value="{{ today()->toDateString() }}"
                                   max="{{ today()->toDateString() }}" aria-invalid="false">
                        </div>

                        <div class="field">
                            <label for="pr-reason-code">Reason</label>
                            <select id="pr-reason-code" name="reason_code" class="form-control" required
                                    aria-invalid="false">
                                @foreach (App\Models\PurchaseReturn::REASONS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label for="pr-settlement">Settlement</label>
                            <select id="pr-settlement" name="settlement" class="form-control" required
                                    aria-invalid="false">
                                @foreach (App\Models\PurchaseReturn::SETTLEMENTS as $key => $label)
                                    <option value="{{ $key }}"
                                        @selected($key === App\Models\PurchaseReturn::CREDIT)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-hint">
                                Crediting comes off what we owe {{ $receipt->supplier?->displayName() }}.
                            </div>
                        </div>

                        <div class="field field-full">
                            <label for="pr-reason">Notes</label>
                            <textarea id="pr-reason" name="reason" class="form-control"
                                      style="min-height:56px" aria-invalid="false"
                                      placeholder="What was wrong with it, and anything the supplier said."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top:16px">
                <div class="card-header">
                    <div>
                        <div class="card-title">What is going back</div>
                        <div class="text-xs text-muted">
                            Leave a quantity at zero for anything the shop is keeping.
                        </div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Batch</th>
                                <th style="text-align:right">Received</th>
                                <th style="text-align:right">Already back</th>
                                <th style="width:130px;text-align:right">Returning</th>
                                <th style="text-align:right">Cost</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse ($lines as $index => $line)
                                @php
                                    $returnable = $line->returnableQuantity();
                                    $unitCost = (float) ($line->landed_cost ?: $line->unit_cost);
                                @endphp
                                <tr>
                                    <td>
                                        <input type="hidden" name="items[{{ $index }}][goods_receipt_item_id]"
                                               value="{{ $line->id }}">
                                        <strong>{{ $line->product_name }}</strong>
                                        <span class="text-xs text-muted" style="display:block">
                                            {{ $line->sku }}
                                        </span>
                                    </td>

                                    <td class="text-sm">{{ $line->batch_no ?: '—' }}</td>

                                    <td style="text-align:right" class="text-sm">
                                        {{ rtrim(rtrim(number_format($line->totalQuantity(), 3), '0'), '.') }}
                                        {{ $line->unit_code }}
                                    </td>

                                    <td style="text-align:right" class="text-sm">
                                        {{ (float) $line->returned_quantity > 0
                                            ? rtrim(rtrim(number_format((float) $line->returned_quantity, 3), '0'), '.')
                                            : '—' }}
                                    </td>

                                    <td>
                                        <label class="sr-only" for="pr-qty-{{ $index }}">
                                            Quantity returning for {{ $line->product_name }}
                                        </label>
                                        <input id="pr-qty-{{ $index }}" type="number"
                                               name="items[{{ $index }}][quantity]" class="form-control"
                                               value="0" min="0" max="{{ $returnable }}" step="0.001">
                                        <div class="form-hint">at most {{ rtrim(rtrim(number_format($returnable, 3), '0'), '.') }}</div>
                                    </td>

                                    <td style="text-align:right" class="text-sm">
                                        ₹{{ number_format($unitCost, 2) }}
                                        <span class="text-xs text-muted" style="display:block">per unit</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
                                        <div class="empty">
                                            <x-icon name="user-check" :size="26" />
                                            <h3>Everything on this receipt has already gone back</h3>
                                            <p class="text-sm">There is nothing left to return.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($lines->isNotEmpty())
                    <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
                        <a class="btn" href="{{ route('admin.purchase-returns.index') }}">Cancel</a>

                        <button type="submit" class="btn" name="intent" value="save">
                            Save for approval
                        </button>

                        @allows('purchasing.returns.approve')
                            <button type="submit" class="btn btn-primary" name="intent" value="approve"
                                    onclick="return confirm('Accept this return now? The goods leave the shelf and the supplier is settled.')">
                                Accept the return
                            </button>
                        @endallows
                    </div>
                @endif
            </div>
        </form>
    @endunless
@endsection
