{{--
    Swappable fragment: the refund log.

    Failures are kept and shown with the provider's own wording. A row that
    vanished on failure would leave somebody certain they had refunded a guest
    who is still out of pocket.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Against</th>
                <th>Amount</th>
                <th>Why</th>
                <th>By</th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($refunds as $refund)
                <tr @if ($refund->status === App\Models\PaymentRefund::FAILED) style="background: var(--danger-soft)" @endif>
                    <td>
                        <span class="list-ref">{{ $refund->reference }}</span>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $refund->created_at?->format('j M, g:i a') }}
                        </span>
                    </td>

                    <td class="text-sm">
                        {{ $refund->intent?->reference ?? '—' }}
                        @if ($refund->provider_refund_id)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $refund->provider_refund_id }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        <strong>{{ $refund->currency }} {{ number_format((float) $refund->amount, 2) }}</strong>
                    </td>

                    <td class="text-sm">{{ $refund->reason ?: '—' }}</td>

                    <td class="text-sm">{{ $refund->user?->name ?? '—' }}</td>

                    <td>
                        <span class="badge badge-{{ $refund->statusTone() }}">
                            <span class="badge-dot"></span> {{ $refund->statusLabel() }}
                        </span>
                        @if ($refund->error)
                            <span class="text-xs text-danger" style="display:block">{{ $refund->error }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>Nothing has been refunded</h3>
                            <p class="text-sm">Which is the way it should stay.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$refunds" :per-page="$perPage" :page-sizes="$pageSizes" label="refunds" />
