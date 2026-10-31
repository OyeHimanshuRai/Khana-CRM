{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Date</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Customer</th>
                <th style="text-align:right">Total</th>
                <th style="text-align:right">Paid</th>
                <th style="text-align:right">Due</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($invoices as $invoice)
                @php
                    $due = (float) $invoice->due_total;
                    $overdue = $invoice->isOverdue();
                @endphp
                <tr @if ($invoice->isCancelled()) style="opacity:.6" @endif>
                    <td>
                        <strong>
                            <a href="{{ route('admin.invoices.show', $invoice) }}"
                               class="list-ref">{{ $invoice->number }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $invoice->channelLabel() }} · {{ $invoice->items_count }} item(s)
                        </span>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $invoice->invoiced_at?->format('d M Y') }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $invoice->invoiced_at?->format('H:i') }}
                        </span>
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $invoice->shop?->name ?? '—' }}</td>
                    @endif

                    <td>
                        {{ $invoice->billedTo() }}
                        @if ($invoice->customer_mobile)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $invoice->customer_mobile }}
                            </span>
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong>₹{{ number_format((float) $invoice->grand_total, 2) }}</strong>
                    </td>

                    <td style="text-align:right" class="text-sm">
                        ₹{{ number_format((float) $invoice->paid_total, 2) }}
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if ($due > 0)
                            <strong style="color:var({{ $overdue ? '--danger' : '--warning' }})">
                                ₹{{ number_format($due, 2) }}
                            </strong>
                            @if ($invoice->due_date)
                                <span class="text-xs" style="display:block;color:var({{ $overdue ? '--danger' : '--muted' }})">
                                    {{ $overdue
                                        ? $invoice->daysOverdue().' days over'
                                        : 'due '.$invoice->due_date->format('d M') }}
                                </span>
                            @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        <span class="badge {{ $invoice->statusTone() ? 'badge-'.$invoice->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $invoice->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.invoices.show', $invoice) }}"
                               aria-label="Open {{ $invoice->number }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('sales.invoices.print')
                                <a class="btn btn-icon" href="{{ route('admin.invoices.print', $invoice) }}"
                                   target="_blank" rel="noopener"
                                   aria-label="Print {{ $invoice->number }}">
                                    <x-icon name="file" :size="15" />
                                </a>
                            @endallows

                            @if (! $invoice->isCancelled())
                                @allows('sales.invoices.cancel')
                                    <a class="btn btn-icon is-danger"
                                       href="{{ route('admin.invoices.cancel.form', $invoice) }}"
                                       data-modal="{{ route('admin.invoices.cancel.form', $invoice) }}"
                                       data-modal-title="Cancel {{ $invoice->number }}"
                                       data-modal-sub="This puts the stock back and reverses the account"
                                       aria-label="Cancel {{ $invoice->number }}">
                                        <x-icon name="x" :size="15" />
                                    </a>
                                @endallows
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 9 : 8 }}">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No invoices found</h3>
                            <p class="text-sm">Adjust the filters, or ring up the first sale at the counter.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$invoices" :per-page="$perPage" :page-sizes="$pageSizes" label="invoices" />
