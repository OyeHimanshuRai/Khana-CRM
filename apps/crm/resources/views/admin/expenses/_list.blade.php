{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Date</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>What for</th>
                <th>Category</th>
                <th>Paid by</th>
                <th style="text-align:right">Amount</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($expenses as $expense)
                <tr @if ($expense->status === 'rejected') style="opacity:.6" @endif>
                    <td>
                        <strong>
                            <a href="{{ route('admin.expenses.show', $expense) }}"
                               data-modal="{{ route('admin.expenses.show', $expense) }}"
                               data-modal-title="{{ $expense->reference }}"
                               data-modal-sub="Expense details"
                               class="list-ref">{{ $expense->reference }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $expense->created_by_name ?? '—' }}
                        </span>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $expense->spent_on?->format('d M Y') }}
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $expense->shop?->name ?? '—' }}</td>
                    @endif

                    <td>
                        {{ $expense->title }}
                        @if ($expense->paid_to)
                            <span class="text-xs text-muted" style="display:block">
                                to {{ $expense->paid_to }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $expense->category?->name ?? 'Uncategorised' }}
                    </td>

                    <td class="text-sm">
                        {{ $expense->methodLabel() }}
                        @if ($expense->attachmentUrl())
                            <span class="text-xs text-muted" style="display:block">bill attached</span>
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong>₹{{ number_format((float) $expense->amount, 2) }}</strong>
                    </td>

                    <td>
                        <span class="badge {{ $expense->statusTone() ? 'badge-'.$expense->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $expense->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.expenses.show', $expense) }}"
                               data-modal="{{ route('admin.expenses.show', $expense) }}"
                               data-modal-title="{{ $expense->reference }}"
                               data-modal-sub="Expense details"
                               aria-label="View {{ $expense->reference }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if ($expense->isEditable())
                                @allows('finance.expenses.approve')
                                    <form method="POST" action="{{ route('admin.expenses.approve', $expense) }}"
                                          data-ajax data-refresh-list style="display:inline"
                                          onsubmit="return confirm('Approve {{ $expense->reference }}? It will be recorded as money out.')">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-icon"
                                                title="Approve"
                                                aria-label="Approve {{ $expense->reference }}">
                                            <x-icon name="user-check" :size="15" />
                                        </button>
                                    </form>
                                @endallows

                                @allows('finance.expenses.edit')
                                    <a class="btn btn-icon" href="{{ route('admin.expenses.edit', $expense) }}"
                                       data-modal="{{ route('admin.expenses.edit', $expense) }}"
                                       data-modal-title="Edit Expense"
                                       data-modal-sub="{{ $expense->reference }}"
                                       data-modal-size="lg"
                                       aria-label="Edit {{ $expense->reference }}">
                                        <x-icon name="edit" :size="15" />
                                    </a>
                                @endallows

                                @allows('finance.expenses.delete')
                                    <form method="POST" action="{{ route('admin.expenses.destroy', $expense) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Delete {{ $expense->reference }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Delete {{ $expense->reference }}">
                                            <x-icon name="trash" :size="15" />
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
                            <x-icon name="wallet" :size="28" />
                            <h3>No expenses</h3>
                            <p class="text-sm">Adjust the filters, or record the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$expenses" :per-page="$perPage" :page-sizes="$pageSizes" label="expenses" />
