{{--
    One table's bill: what it ate, what it owes, and how it is paying.

    Two columns, because a cashier reads them in that order - check the food
    against the table, then take the money. The form posts the whole thing at
    once; there is no basket to build, because the food is already eaten.

    No JavaScript is required to settle in full. Splitting needs the modal,
    which is an ordinary data-modal form.
--}}

@php
    use App\Models\Order;

    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');

    $lines = $summary['lines'];
@endphp

<div class="settings-grid" style="gap:16px">

    {{-- --------------------------------------------------- what they ate --}}
    <div class="field field-full" style="grid-column:1 / -1">
        @if ($summary['in_kitchen'] > 0)
            {{--
                Said plainly and not enforced. A guest asking for the bill
                while the last dish is on the pass is a normal Tuesday; a
                table where nothing has been accepted usually means somebody
                pressed the wrong one. The cashier can tell which - a rule
                here would only get in the way of somebody leaving.
            --}}
            <div class="dash-alert is-warning" style="margin-bottom:12px">
                <x-icon name="clock" :size="17" />
                <span class="dash-alert-text">
                    {{ $qty($summary['in_kitchen']) }} item(s) on this bill are still in the kitchen.
                </span>
                @allows('kitchen.tickets.view')
                    <a class="dash-alert-action" href="{{ route('admin.kitchen.index') }}">Kitchen →</a>
                @endallows
            </div>
        @endif

        {{--
            Picked but not sent - a captain's pad, or a phone mid-order. Not
            part of the bill: nothing has been cooked and nothing is owed. It
            is here so a cashier about to settle can see a round is still being
            written and ask before printing.
        --}}
        @if ($picked->isNotEmpty())
            <div class="dash-alert is-info" style="margin-bottom:12px">
                <x-icon name="inbox" :size="17" />
                <span class="dash-alert-text">
                    {{ $picked->sum('quantity') }} item(s) picked but not sent to the kitchen yet.
                </span>
                @if ($canOrder)
                    <a class="dash-alert-action"
                       href="{{ route('admin.table-bills.order-form', $session) }}"
                       data-modal="{{ route('admin.table-bills.order-form', $session) }}"
                       data-modal-title="Order for table {{ $session->table?->code }}"
                       data-modal-sub="Add to the pad, then send it in one ticket">Open the pad →</a>
                @endif
            </div>
        @endif

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Round</th>
                        <th style="text-align:right">Qty</th>
                        <th style="text-align:right">Price</th>
                        <th style="text-align:right">Total</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($lines as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->title() }}</strong>

                                @if ($line->modifiers->isNotEmpty())
                                    <span class="text-xs text-muted" style="display:block">
                                        {{ $line->modifiers->map(fn ($m) => $m->option_name)->implode(', ') }}
                                    </span>
                                @endif

                                @if ($line->note)
                                    <span class="text-xs" style="display:block; color:var(--warning)">
                                        {{ $line->note }}
                                    </span>
                                @endif

                                {{-- Shown only when part of this line has
                                     already gone onto somebody else's bill. --}}
                                @if ((float) $line->settled_quantity > 0)
                                    <span class="text-xs text-muted" style="display:block">
                                        {{ $qty($line->settled_quantity) }} already billed
                                    </span>
                                @endif
                            </td>

                            <td class="text-sm">
                                <span class="list-ref">{{ $line->order?->order_number }}</span>
                                @if ($line->kitchen_status && $line->kitchen_status !== Order::SERVED)
                                    <span class="text-xs text-muted" style="display:block">
                                        {{ $line->kitchenStatusLabel() }}
                                    </span>
                                @endif
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ $qty($line->unsettledQuantity()) }}
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ $money($line->unit_price) }}
                            </td>

                            <td style="text-align:right">
                                <strong>{{ $money((float) $line->unit_price * $line->unsettledQuantity()) }}</strong>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <x-icon name="user-check" :size="26" />
                                    <h3>Nothing left to bill</h3>
                                    <p class="text-sm">
                                        @if ($session->invoices->isNotEmpty())
                                            Everything this table ate has been billed.
                                        @else
                                            This table has not ordered anything yet.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Bills already raised against this sitting - a split, or a second
             round settled separately. --}}
        @if ($session->invoices->isNotEmpty())
            <div style="margin-top:14px">
                <div class="form-label">Already billed</div>
                <div class="table-wrap">
                    <table class="table table-list">
                        <thead>
                            <tr>
                                <th>Bill</th>
                                <th>Raised</th>
                                <th style="text-align:right">Total</th>
                                <th style="text-align:right">Unpaid</th>
                                <th class="col-action"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($session->invoices as $invoice)
                                <tr>
                                    <td><span class="list-ref">{{ $invoice->number }}</span></td>
                                    <td class="text-sm">{{ $invoice->invoiced_at?->format('g:i a') }}</td>
                                    <td style="text-align:right">{{ $money($invoice->grand_total) }}</td>
                                    <td style="text-align:right" class="text-sm">
                                        @if ((float) $invoice->due_total > 0)
                                            <span style="color:var(--danger)">{{ $money($invoice->due_total) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="col-action">
                                        @allows('pos.tables.print')
                                            <a class="btn btn-icon" target="_blank" rel="noopener"
                                               href="{{ route('admin.invoices.print', $invoice) }}"
                                               aria-label="Print {{ $invoice->number }}">
                                                <x-icon name="file" :size="15" />
                                            </a>
                                        @endallows
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    {{-- -------------------------------------------------------- the money --}}
    @if ($lines->isNotEmpty() && $canSettle && ! $session->isClosed())
        <div class="field field-full" style="grid-column:1 / -1">
            {{--
                The tender rows are wired by js/table-bill.js - it keeps the
                second row's amount equal to what the first one leaves behind,
                and fetches the scannable code for whatever is going on UPI.
                See the contract at the top of that file.
            --}}
            {{--
                `data-close-modal` is a no-op on this page - there is no modal
                around the bill when it is reached at its own URL, and
                Modal.close() returns immediately when nothing is open. It is
                here for the floor plan, where this same fragment IS the modal:
                without it a settled table left its old bill on screen while
                the plan refreshed behind it, still showing a payable that had
                just been charged.
            --}}
            <form method="POST" action="{{ route('admin.table-bills.settle', $session) }}"
                  data-ajax data-refresh-list data-close-modal
                  data-table-bill
                  data-tb-payable="{{ number_format($summary['unbilled'], 2, '.', '') }}"
                  @if ($upiReady ?? false) data-tb-qr-url="{{ route('admin.table-bills.upi-qr', $session) }}" @endif>
                @csrf

                <div class="settings-grid">
                    <div class="field field-full">
                        <dl class="pos-totals">
                            <div class="is-total">
                                <dt>Payable</dt>
                                <dd>{{ $money($summary['unbilled']) }}</dd>
                            </div>
                        </dl>
                        <p class="text-xs text-muted" style="margin:6px 0 0">
                            Menu prices include GST. The exact split is worked out on the invoice.
                        </p>
                    </div>

                    @if ($warehouses->count() > 1)
                        <div class="field">
                            <label for="tb-warehouse">Serve from</label>
                            <select id="tb-warehouse" name="warehouse_id" class="form-control">
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected($warehouse->is_default)>
                                        {{ $warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if ($canDiscount)
                        <div class="field">
                            <label for="tb-discount">Bill discount (₹)</label>
                            <input id="tb-discount" type="number" name="invoice_discount" class="form-control"
                                   min="0" step="0.01" value="0">
                            <div class="form-hint">
                                Given once, on the bill — not per round, or four rounds of
                                drinks would take four discounts.
                            </div>
                        </div>
                    @endif

                    {{--
                        One tender row, plus a second for a split payment. Two
                        is what a table actually does - part card, part cash -
                        and a builder for N rows is a script this page does not
                        otherwise need.
                    --}}
                    @foreach ([0, 1] as $i)
                        <div class="field" data-tb-row>
                            <label for="tb-method-{{ $i }}">
                                {{ $i === 0 ? 'Payment' : 'Second payment (optional)' }}
                            </label>
                            <select id="tb-method-{{ $i }}" name="payments[{{ $i }}][method]"
                                    class="form-control" data-tb-method>
                                @foreach ($methods as $key => $meta)
                                    <option value="{{ $key }}" @selected($key === 'cash')>{{ $meta['label'] }}</option>
                                @endforeach
                            </select>

                            <label class="sr-only" for="tb-amount-{{ $i }}">Amount</label>
                            <input id="tb-amount-{{ $i }}" type="number" name="payments[{{ $i }}][amount]"
                                   class="form-control" min="0" step="0.01" style="margin-top:6px"
                                   placeholder="0.00" data-tb-amount
                                   value="{{ $i === 0 ? number_format($summary['unbilled'], 2, '.', '') : '' }}">

                            <label class="sr-only" for="tb-ref-{{ $i }}">Reference</label>
                            <input id="tb-ref-{{ $i }}" type="text" name="payments[{{ $i }}][transaction_ref]"
                                   class="form-control" style="margin-top:6px" maxlength="120"
                                   data-tb-ref
                                   placeholder="Card / UPI reference">
                        </div>
                    @endforeach

                    {{--
                        The guest's half of a split (§8).

                        Hidden until something is actually going on UPI, and
                        left out of the markup altogether when the branch has
                        no VPA - see TableBillController::show. The amount in
                        the code is the UPI part, never the bill: a table
                        paying ₹200 in cash and ₹60 by phone needs a code for
                        sixty rupees.

                        Scanning it does not settle anything. The money moves
                        between the guest's bank and the branch's without
                        passing through here, so the cashier still presses
                        Settle once they have seen it land - see
                        App\Support\UpiQr.
                    --}}
                    @if ($upiReady ?? false)
                        <div class="field field-full" style="grid-column:1 / -1" data-tb-qr hidden>
                            <div class="empty" style="padding:14px">
                                <div data-tb-qr-canvas style="line-height:0"></div>
                                <h3 style="margin-top:10px">Scan to pay <span data-tb-qr-amount>₹0.00</span></h3>
                                <p class="text-sm text-muted" data-tb-qr-payee></p>
                                <p class="text-xs text-muted" style="margin-top:6px">
                                    The guest pays their bank directly. Press Settle once it has landed.
                                </p>
                            </div>
                        </div>
                    @endif

                    <div class="field field-full">
                        <label for="tb-notes">Note on the bill</label>
                        <input id="tb-notes" type="text" name="notes" class="form-control" maxlength="500">
                    </div>
                </div>

                <div class="modal-actions" style="justify-content:space-between">
                    <a class="btn"
                       href="{{ route('admin.table-bills.split-form', $session) }}"
                       data-modal="{{ route('admin.table-bills.split-form', $session) }}"
                       data-modal-title="Split the bill"
                       data-modal-sub="Choose what goes on this one; the rest stays on the table">
                        <x-icon name="filter" :size="15" /> Split
                    </a>

                    <button type="submit" class="btn btn-primary">
                        Settle {{ $money($summary['unbilled']) }}
                    </button>
                </div>
            </form>
        </div>
    @elseif ($session->isClosed())
        <div class="field field-full" style="grid-column:1 / -1">
            <div class="empty">
                <x-icon name="user-check" :size="26" />
                <h3>This sitting is closed</h3>
                <p class="text-sm">
                    {{ $session->closed_reason ?: 'The party has left.' }}
                </p>
            </div>
        </div>
    @endif
</div>
