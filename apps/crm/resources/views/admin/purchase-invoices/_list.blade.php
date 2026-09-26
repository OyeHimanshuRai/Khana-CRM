{{--
    Swappable fragment: the table plus its pagination.

    Rows link into the receipt's own detail page (admin.receipts.show) -
    that page already shows the bill in full, payments included, so this
    list does not keep a second copy of it. See
    PurchaseInvoiceController's docblock for why.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Bill</th>
                <th>Receipt</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Supplier</th>
                <th>Due</th>
                <th style="text-align:right">Total</th>
                <th style="text-align:right">Outstanding</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($bills as $row)
                @php $due = (float) $row->due_total; @endphp
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.receipts.show', $row) }}" class="list-ref">
                                {{ $row->bill_number ?: 'No bill number' }}
                            </a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $row->bill_date?->format('d M Y') ?? $row->received_on?->format('d M Y') }}
                        </span>
                    </td>

                    <td class="text-sm">
                        <a href="{{ route('admin.receipts.show', $row) }}" class="list-ref">
                            {{ $row->reference }}
                        </a>
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $row->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">{{ $row->supplier?->displayName() ?? '—' }}</td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $row->due_date?->format('d M Y') ?? '—' }}
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong>₹{{ number_format((float) $row->grand_total, 2) }}</strong>
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if ($due > 0)
                            <strong style="color:var(--danger)">₹{{ number_format($due, 2) }}</strong>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        @if ($due <= 0)
                            <span class="badge badge-success"><span class="badge-dot"></span> Paid</span>
                        @elseif ($row->isOverdue())
                            <span class="badge badge-danger"><span class="badge-dot"></span> Overdue</span>
                        @else
                            <span class="badge badge-warning"><span class="badge-dot"></span> Outstanding</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.receipts.show', $row) }}"
                               aria-label="Open {{ $row->reference }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('purchasing.bills.print')
                                <a class="btn btn-icon" href="{{ route('admin.purchase-invoices.print', $row) }}"
                                   target="_blank" rel="noopener" aria-label="Print bill {{ $row->reference }}">
                                    <x-icon name="file" :size="15" />
                                </a>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 9 : 8 }}">
                        <div class="empty">
                            <x-icon name="file" :size="28" />
                            <h3>No bills</h3>
                            <p class="text-sm">Posting a goods receipt with a bill number puts it here.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$bills" :per-page="$perPage" :page-sizes="$pageSizes" label="bills" />
