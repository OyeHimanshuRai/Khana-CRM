{{--
    Swappable fragment: what came in.

    "Left to refund" is computed for the whole page in one grouped query by
    the controller — asking per row would be a query per line on a screen
    whose entire job is to be scanned quickly.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>When</th>
                <th>Amount</th>
                <th>Left to refund</th>
                <th>Provider id</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($intents as $intent)
                @php
                    $given = (float) ($refunded[$intent->id] ?? 0);
                    $left = max(0, (float) $intent->amount - $given);
                    $refundable = in_array($intent->status, ['paid', 'refunded'], true) && $left > 0.009;
                @endphp
                <tr>
                    <td><span class="list-ref">{{ $intent->reference }}</span></td>

                    <td class="text-sm">
                        {{ ($intent->paid_at ?? $intent->created_at)?->format('j M, g:i a') }}
                    </td>

                    <td class="text-sm">
                        <strong>{{ $intent->currency }} {{ number_format((float) $intent->amount, 2) }}</strong>
                    </td>

                    <td class="text-sm">
                        @if ($given > 0)
                            {{ number_format($left, 2) }}
                            <span class="text-xs text-muted" style="display:block">
                                {{ number_format($given, 2) }} already back
                            </span>
                        @elseif ($refundable)
                            {{ number_format($left, 2) }}
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $intent->provider_payment_id ?: '—' }}
                        <span class="text-xs text-muted" style="display:block">{{ $intent->provider }}</span>
                    </td>

                    <td>
                        <span class="badge badge-{{ $intent->statusTone() }}">
                            <span class="badge-dot"></span> {{ $intent->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @if ($canRefund && $refundable)
                                <a class="btn btn-sm" href="{{ route('admin.online-payments.refund-form', $intent) }}"
                                   data-modal="{{ route('admin.online-payments.refund-form', $intent) }}"
                                   data-modal-title="Refund"
                                   data-modal-sub="{{ $intent->reference }}"
                                   data-modal-size="lg">Refund</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="wallet" :size="28" />
                            <h3>Nothing taken online yet</h3>
                            <p class="text-sm">
                                Payments appear here as guests pay from the QR menu. Nothing is wrong if
                                this stays empty — plenty of restaurants take everything at the counter.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$intents" :per-page="$perPage" :page-sizes="$pageSizes" label="payments" />
