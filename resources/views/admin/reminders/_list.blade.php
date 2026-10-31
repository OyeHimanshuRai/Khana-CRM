{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>When</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Customer</th>
                <th>Invoice</th>
                <th style="text-align:right">Amount</th>
                <th>Trigger</th>
                <th>Sent to</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($reminders as $reminder)
                <tr>
                    <td class="text-sm" style="white-space:nowrap">
                        {{ ($reminder->sent_at ?? $reminder->scheduled_for)?->format('d M Y') }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ ($reminder->sent_at ?? $reminder->scheduled_for)?->format('H:i') }}
                            {{ $reminder->sent_at ? '' : '· due' }}
                        </span>
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $reminder->shop?->name ?? '—' }}</td>
                    @endif

                    <td>
                        @if ($reminder->customer)
                            <a href="{{ route('admin.ledger.index', ['customer' => $reminder->customer_id]) }}">
                                {{ $reminder->customer->name }}
                            </a>
                        @else
                            —
                        @endif
                    </td>

                    <td class="text-sm">
                        @if ($reminder->invoice)
                            <a href="{{ route('admin.invoices.show', $reminder->invoice) }}" class="list-ref">
                                {{ $reminder->invoice->number }}
                            </a>
                            @if ($reminder->due_date)
                                <span class="text-xs text-muted" style="display:block">
                                    due {{ $reminder->due_date->format('d M Y') }}
                                </span>
                            @endif
                        @else
                            —
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        ₹{{ number_format((float) $reminder->amount_due, 2) }}
                        @if ($reminder->invoice && (float) $reminder->invoice->due_total !== (float) $reminder->amount_due)
                            {{-- The amount when the reminder was raised is not
                                 always what is owed now. Both matter. --}}
                            <span class="text-xs text-muted" style="display:block">
                                now ₹{{ number_format((float) $reminder->invoice->due_total, 2) }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">{{ $reminder->triggerLabel() }}</td>

                    <td class="text-sm text-muted">
                        {{ $reminder->recipient ?: '—' }}
                        <span class="text-xs" style="display:block">{{ $reminder->channelLabel() }}</span>
                    </td>

                    <td>
                        <span class="badge {{ $reminder->statusTone() ? 'badge-'.$reminder->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $reminder->statusLabel() }}
                        </span>
                        @if ($reminder->skip_reason)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($reminder->skip_reason, 34) }}
                            </span>
                        @elseif ($reminder->last_error)
                            <span class="text-xs" style="display:block;color:var(--danger)">
                                {{ Str::limit($reminder->last_error, 34) }}
                            </span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.reminders.show', $reminder) }}"
                               data-modal="{{ route('admin.reminders.show', $reminder) }}"
                               data-modal-title="{{ $reminder->triggerLabel() }}"
                               data-modal-sub="{{ $reminder->customer?->name }}"
                               aria-label="View reminder">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if (in_array($reminder->status, ['pending', 'failed'], true))
                                @allows('crm.reminders.edit')
                                    <form method="POST" action="{{ route('admin.reminders.send', $reminder) }}"
                                          data-ajax data-refresh-list style="display:inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-icon"
                                                title="Send now"
                                                aria-label="Send this reminder now">
                                            <x-icon name="mail" :size="15" />
                                        </button>
                                    </form>
                                @endallows
                            @endif

                            @if ($reminder->status === 'pending')
                                @allows('crm.reminders.delete')
                                    <form method="POST" action="{{ route('admin.reminders.cancel', $reminder) }}"
                                          data-ajax data-refresh-list style="display:inline"
                                          onsubmit="return confirm('Cancel this reminder? It will not be sent.')">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Cancel this reminder">
                                            <x-icon name="x" :size="15" />
                                        </button>
                                    </form>
                                @endallows
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 9 : 8 }}">
                        <div class="empty">
                            <x-icon name="mail" :size="28" />
                            <h3>No reminders</h3>
                            <p class="text-sm">
                                Reminders are raised automatically for unpaid invoices with a due date.
                                Run the scheduler to see what is waiting.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$reminders" :per-page="$perPage" :page-sizes="$pageSizes" label="reminders" />
