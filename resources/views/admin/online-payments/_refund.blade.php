{{--
    Send money back, in the modal.

    The amount defaults to what is left rather than to nothing: the common
    case is a whole payment going back, and typing a figure somebody has to
    read off the line above is how the wrong number gets refunded.
--}}

<div class="sec-name">{{ $intent->reference }}</div>
<div class="text-sm text-muted">
    {{ $intent->currency }} {{ number_format((float) $intent->amount, 2) }}
    on {{ ($intent->paid_at ?? $intent->created_at)?->format('j M Y, g:i a') }}
</div>

<div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
    <span class="badge badge-{{ $intent->statusTone() }}">{{ $intent->statusLabel() }}</span>
    <span class="badge badge-info">{{ number_format($available, 2) }} left to refund</span>
</div>

@unless ($live)
    <div class="alert alert-danger" style="margin-bottom:14px">
        <strong>No payment provider is switched on.</strong>
        There is nothing to send the refund through. Set the provider up again before refunding,
        or hand the money back at the counter and record it as a sales return.
    </div>
@endunless

@if ($history->isNotEmpty())
    <div class="form-section">
        <div class="form-section-title">Already refunded</div>

        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr><th>Reference</th><th>Amount</th><th>Status</th><th>Why</th></tr>
                </thead>
                <tbody>
                    @foreach ($history as $row)
                        <tr>
                            <td><span class="list-ref">{{ $row->reference }}</span></td>
                            <td class="text-sm">{{ number_format((float) $row->amount, 2) }}</td>
                            <td>
                                <span class="badge badge-{{ $row->statusTone() }}">{{ $row->statusLabel() }}</span>
                            </td>
                            <td class="text-sm">
                                {{ $row->reason ?: '—' }}
                                @if ($row->error)
                                    <span class="text-xs text-danger" style="display:block">{{ $row->error }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($available > 0.009 && $live)
    <form method="POST" action="{{ route('admin.online-payments.refund', $intent) }}"
          data-ajax data-close-modal data-refresh-list
          onsubmit="return confirm('Send this money back to the guest? It cannot be undone from here.')">
        @csrf

        <div class="form-section">
            <div class="form-section-title">Send back</div>

            <div class="settings-grid">
                <div class="field">
                    <label for="rf-amount">Amount</label>
                    <input id="rf-amount" type="number" name="amount" class="form-control" required
                           value="{{ $available }}" min="0.01" max="{{ $available }}" step="0.01"
                           aria-invalid="false">
                    <div class="form-hint">
                        Partial is fine — a dish that never arrived goes back, not the whole evening.
                    </div>
                </div>

                <div class="field">
                    <label for="rf-reason">Why</label>
                    <input id="rf-reason" type="text" name="reason" class="form-control" required
                           maxlength="255" aria-invalid="false"
                           placeholder="Dish never arrived — table 4, 16 Sept">
                    <div class="form-hint">
                        Required. A refund nobody explained is the line an auditor stops at.
                    </div>
                </div>
            </div>

            <p class="text-xs text-muted" style="margin-top:10px">
                Sent at the provider's normal speed, which is free and reaches the guest in three to
                five working days. Instant refunds cost a fee and are not used here.
            </p>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn is-danger">Refund</button>
        </div>
    </form>
@else
    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Close</button>
    </div>
@endif
