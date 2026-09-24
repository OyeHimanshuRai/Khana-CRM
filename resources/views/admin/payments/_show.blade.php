{{-- Read-only payment detail, rendered straight into the modal body. --}}

@php
    use App\Models\Payment;

    $isIn = $payment->direction === Payment::IN;
@endphp

<div class="cat-view-body">
    <div class="sec-name">
        <span class="list-ref">{{ $payment->number }}</span>
    </div>
    <div class="text-sm text-muted">
        {{ $isIn ? 'Received from' : 'Paid to' }} {{ $payment->party_name ?: '—' }}
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $payment->statusTone() ? 'badge-'.$payment->statusTone() : '' }}">
            <span class="badge-dot"></span> {{ $payment->statusLabel() }}
        </span>
        <span class="badge">{{ $payment->methodLabel() }}</span>
        <span class="badge {{ $isIn ? 'badge-success' : 'badge-danger' }}">
            {{ $isIn ? '+' : '−' }} ₹{{ number_format((float) $payment->amount, 2) }}
        </span>
        <span class="badge badge-info">{{ $payment->shop?->name ?? 'No shop' }}</span>
    </div>

    <dl class="sec-facts">
        <div><dt>Received on</dt><dd>{{ $payment->paid_at?->format('d M Y, H:i') ?? '—' }}</dd></div>
        <div><dt>Reference</dt><dd>{{ $payment->transaction_ref ?: '—' }}</dd></div>
        <div><dt>Bank</dt><dd>{{ $payment->bank_name ?: '—' }}</dd></div>
        <div><dt>Cheque date</dt><dd>{{ $payment->cheque_date?->format('d M Y') ?? '—' }}</dd></div>
        <div>
            <dt>Against</dt>
            <dd>
                @if ($payment->reference instanceof App\Models\Invoice)
                    <a href="{{ route('admin.invoices.show', $payment->reference) }}">
                        {{ $payment->reference->number }}
                    </a>
                @else
                    On account
                @endif
            </dd>
        </div>
        <div><dt>Recorded by</dt><dd>{{ $payment->created_by_name ?: '—' }}</dd></div>
        <div><dt>Recorded at</dt><dd>{{ $payment->created_at?->format('d M Y, H:i') ?? '—' }}</dd></div>

        @if ($payment->reverses)
            <div>
                <dt>Reverses</dt>
                <dd><span class="list-ref">{{ $payment->reverses->number }}</span></dd>
            </div>
        @endif
    </dl>

    @if ($payment->notes)
        <div style="margin-top:16px">
            <div class="form-label">Notes</div>
            <p class="text-sm text-muted" style="white-space:pre-line">{{ $payment->notes }}</p>
        </div>
    @endif

    @if ($payment->status === Payment::PENDING)
        <p class="text-xs text-muted" style="margin-top:14px">
            This has not reduced anyone's balance yet. It will when it is marked as cleared.
        </p>
    @endif
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @if ($payment->status === Payment::PENDING)
        @allows('finance.payments.approve')
            <form method="POST" action="{{ route('admin.payments.clear', $payment) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline">
                @csrf
                @method('PUT')
                <button type="submit" class="btn btn-primary">
                    <x-icon name="user-check" :size="15" /> Mark as cleared
                </button>
            </form>
        @endallows
    @endif
</div>
