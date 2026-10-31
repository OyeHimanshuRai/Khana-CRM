{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Receipt</th>
                <th>When</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Party</th>
                <th>Method</th>
                <th>Against</th>
                <th style="text-align:right">Amount</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($payments as $payment)
                @php $isIn = $payment->direction === App\Models\Payment::IN; @endphp
                <tr @if (in_array($payment->status, ['cancelled', 'bounced'], true)) style="opacity:.65" @endif>
                    <td>
                        <strong>
                            <a href="{{ route('admin.payments.show', $payment) }}"
                               data-modal="{{ route('admin.payments.show', $payment) }}"
                               data-modal-title="{{ $payment->number }}"
                               data-modal-sub="Payment details"
                               class="list-ref">{{ $payment->number }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $payment->created_by_name ?? '—' }}
                        </span>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $payment->paid_at?->format('d M Y') }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $payment->paid_at?->format('H:i') }}
                        </span>
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $payment->shop?->name ?? '—' }}</td>
                    @endif

                    <td>{{ $payment->party_name ?: '—' }}</td>

                    <td class="text-sm">
                        {{ $payment->methodLabel() }}
                        @if ($payment->transaction_ref)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($payment->transaction_ref, 18) }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        @if ($payment->reference instanceof App\Models\Invoice)
                            <a href="{{ route('admin.invoices.show', $payment->reference) }}" class="list-ref">
                                {{ $payment->reference->number }}
                            </a>
                        @else
                            <span class="text-muted">On account</span>
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong style="color:var({{ $isIn ? '--success' : '--danger' }})">
                            {{ $isIn ? '+' : '−' }} ₹{{ number_format((float) $payment->amount, 2) }}
                        </strong>
                    </td>

                    <td>
                        <span class="badge {{ $payment->statusTone() ? 'badge-'.$payment->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $payment->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.payments.show', $payment) }}"
                               data-modal="{{ route('admin.payments.show', $payment) }}"
                               data-modal-title="{{ $payment->number }}"
                               data-modal-sub="Payment details"
                               aria-label="View {{ $payment->number }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if ($payment->status === App\Models\Payment::PENDING)
                                @allows('finance.payments.approve')
                                    <form method="POST" action="{{ route('admin.payments.clear', $payment) }}"
                                          data-ajax data-refresh-list style="display:inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-icon"
                                                title="Mark as cleared"
                                                aria-label="Clear {{ $payment->number }}">
                                            <x-icon name="user-check" :size="15" />
                                        </button>
                                    </form>
                                @endallows
                            @endif

                            @if (in_array($payment->status, ['pending', 'cleared'], true))
                                @allows('finance.payments.adjust')
                                    <a class="btn btn-icon is-danger"
                                       href="{{ route('admin.payments.action', [$payment, 'action' => 'bounce']) }}"
                                       data-modal="{{ route('admin.payments.action', [$payment, 'action' => 'bounce']) }}"
                                       data-modal-title="Bounce {{ $payment->number }}"
                                       data-modal-sub="The debt comes back onto the account"
                                       aria-label="Bounce {{ $payment->number }}">
                                        <x-icon name="trend-down" :size="15" />
                                    </a>

                                    <a class="btn btn-icon is-danger"
                                       href="{{ route('admin.payments.action', [$payment, 'action' => 'reverse']) }}"
                                       data-modal="{{ route('admin.payments.action', [$payment, 'action' => 'reverse']) }}"
                                       data-modal-title="Reverse {{ $payment->number }}"
                                       data-modal-sub="Records a mirror entry; nothing is deleted"
                                       aria-label="Reverse {{ $payment->number }}">
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
                            <x-icon name="wallet" :size="28" />
                            <h3>No payments found</h3>
                            <p class="text-sm">Adjust the filters, or record the first collection.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$payments" :per-page="$perPage" :page-sizes="$pageSizes" label="payments" />
