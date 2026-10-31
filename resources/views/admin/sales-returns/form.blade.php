@extends('admin.layouts.app')

@section('title', 'Take a Return')

@section('content')
    <x-page-header
        title="Take a Return"
        :subtitle="$invoice
            ? 'Against '.$invoice->number.' · '.$invoice->billedTo()
            : 'Find the invoice the goods were sold on.'"
        :crumbs="['Sales' => null, 'Sales Returns' => route('admin.sales-returns.index'), 'New' => null]"
    />

    @unless ($invoice)
        {{--
            No invoice yet, so the screen is a search for one. A return
            raised against the original bill gets its prices and costs right
            for free; guessing them is how a credit note ends up disagreeing
            with what the customer actually paid.
        --}}
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Which invoice?</div>
                    <div class="text-xs text-muted">
                        Search by number, customer or mobile, or pick a recent one below.
                    </div>
                </div>
            </div>

            <div class="card-body">
                <form method="GET" action="{{ route('admin.invoices.index') }}" class="list-search"
                      style="max-width:460px">
                    <x-icon name="search" :size="15" />
                    <label for="sr-invoice" class="sr-only">Search invoices</label>
                    <input id="sr-invoice" type="search" name="q"
                           placeholder="Invoice number, customer or mobile…" autocomplete="off">
                </form>
                <div class="form-hint" style="margin-top:6px">
                    The invoice list opens with a <strong>Take a return</strong> action on each row.
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>When</th>
                            <th>Customer</th>
                            <th style="text-align:right">Total</th>
                            <th class="col-action">Return</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recent as $row)
                            <tr>
                                <td><span class="list-ref">{{ $row->number }}</span></td>
                                <td class="text-sm">{{ $row->invoiced_at?->format('d M Y, H:i') }}</td>
                                <td class="text-sm">{{ $row->billedTo() }}</td>
                                <td style="text-align:right">
                                    <strong>₹{{ number_format((float) $row->grand_total, 2) }}</strong>
                                </td>
                                <td class="col-action">
                                    <a class="btn btn-sm"
                                       href="{{ route('admin.sales-returns.create', ['invoice' => $row->id]) }}">
                                        Take a return
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="empty">
                                        <x-icon name="file" :size="26" />
                                        <h3>No invoices yet</h3>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('admin.sales-returns.store') }}"
              data-ajax data-redirect-delay="500">
            @csrf
            <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Return</div>
                        <div class="text-xs text-muted">
                            Reference <span class="list-ref">{{ $reference }}</span> ·
                            against {{ $invoice->number }} of
                            {{ $invoice->invoiced_at?->format('d M Y') }}
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="settings-grid">
                        <div class="field">
                            <label for="sr-warehouse">Back into</label>
                            <select id="sr-warehouse" name="warehouse_id" class="form-control" required
                                    aria-invalid="false">
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}"
                                        @selected($invoice->warehouse_id === $warehouse->id)>
                                        {{ $warehouse->name }} ({{ $warehouse->code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label for="sr-date">Returned on</label>
                            <input id="sr-date" type="date" name="returned_on" class="form-control" required
                                   value="{{ today()->toDateString() }}"
                                   max="{{ today()->toDateString() }}" aria-invalid="false">
                        </div>

                        <div class="field">
                            <label for="sr-reason-code">Reason</label>
                            <select id="sr-reason-code" name="reason_code" class="form-control" required
                                    aria-invalid="false">
                                @foreach (App\Models\SalesReturn::REASONS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label for="sr-settlement">Settlement</label>
                            <select id="sr-settlement" name="settlement" class="form-control" required
                                    aria-invalid="false">
                                @foreach (App\Models\SalesReturn::SETTLEMENTS as $key => $label)
                                    <option value="{{ $key }}"
                                        @selected($key === App\Models\SalesReturn::CREDIT)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-hint">
                                @if ($invoice->customer)
                                    Crediting comes off what {{ $invoice->customer->name }} owes.
                                @else
                                    A walk-in has no account, so this will be a refund whatever is
                                    chosen here.
                                @endif
                            </div>
                        </div>

                        <div class="field field-full">
                            <label for="sr-reason">Notes</label>
                            <textarea id="sr-reason" name="reason" class="form-control"
                                      style="min-height:56px" aria-invalid="false"
                                      placeholder="What the customer said, what condition it arrived in."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top:16px">
                <div class="card-header">
                    <div>
                        <div class="card-title">What came back</div>
                        <div class="text-xs text-muted">
                            Leave a quantity at zero for anything the customer kept. Condition decides
                            whether the goods go back on the shelf.
                        </div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Batch</th>
                                <th style="text-align:right">Sold</th>
                                <th style="text-align:right">Already back</th>
                                <th style="width:130px;text-align:right">Returning</th>
                                <th style="width:190px">Condition</th>
                                <th style="text-align:right">Credit</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse ($lines as $index => $line)
                                @php
                                    $returnable = $line->returnableQuantity();
                                    // The per-unit credit is the line's taxable
                                    // value spread over its quantity, so any
                                    // discount given comes back too.
                                    $perUnit = (float) $line->quantity > 0
                                        ? (float) $line->line_total / (float) $line->quantity
                                        : 0;
                                @endphp
                                <tr>
                                    <td>
                                        <input type="hidden" name="items[{{ $index }}][invoice_item_id]"
                                               value="{{ $line->id }}">
                                        <strong>{{ $line->product_name }}</strong>
                                        <span class="text-xs text-muted" style="display:block">
                                            {{ $line->sku }}
                                        </span>
                                    </td>

                                    <td class="text-sm">{{ $line->batch_no ?: '—' }}</td>

                                    <td style="text-align:right" class="text-sm">
                                        {{ $line->quantityLabel() }}
                                    </td>

                                    <td style="text-align:right" class="text-sm">
                                        {{ (float) $line->returned_quantity > 0
                                            ? rtrim(rtrim(number_format((float) $line->returned_quantity, 3), '0'), '.')
                                            : '—' }}
                                    </td>

                                    <td>
                                        <label class="sr-only" for="sr-qty-{{ $index }}">
                                            Quantity returning for {{ $line->product_name }}
                                        </label>
                                        <input id="sr-qty-{{ $index }}" type="number"
                                               name="items[{{ $index }}][quantity]" class="form-control"
                                               value="0" min="0" max="{{ $returnable }}" step="0.001">
                                        <div class="form-hint">at most {{ rtrim(rtrim(number_format($returnable, 3), '0'), '.') }}</div>
                                    </td>

                                    <td>
                                        <label class="sr-only" for="sr-cond-{{ $index }}">Condition</label>
                                        <select id="sr-cond-{{ $index }}"
                                                name="items[{{ $index }}][condition]" class="form-control">
                                            @foreach (App\Models\SalesReturnItem::CONDITIONS as $key => $meta)
                                                <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </td>

                                    <td style="text-align:right" class="text-sm">
                                        ₹{{ number_format($perUnit, 2) }}
                                        <span class="text-xs text-muted" style="display:block">per unit</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <div class="empty">
                                            <x-icon name="user-check" :size="26" />
                                            <h3>Everything on this invoice has already come back</h3>
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
                        <a class="btn" href="{{ route('admin.sales-returns.index') }}">Cancel</a>

                        <button type="submit" class="btn" name="intent" value="save">
                            Save for approval
                        </button>

                        @allows('sales.returns.approve')
                            <button type="submit" class="btn btn-primary" name="intent" value="approve"
                                    onclick="return confirm('Accept this return now? Resalable goods go back on the shelf and the customer is settled.')">
                                Accept the return
                            </button>
                        @endallows
                    </div>
                @endif
            </div>
        </form>
    @endunless
@endsection
