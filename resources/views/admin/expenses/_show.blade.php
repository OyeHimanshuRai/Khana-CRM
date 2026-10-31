{{-- Read-only expense detail, rendered straight into the modal body. --}}

@php $attachment = $expense->attachmentUrl(); @endphp

<div class="cat-view-body">
    <div class="sec-name">{{ $expense->title }}</div>
    <div class="text-sm text-muted">
        <span class="list-ref">{{ $expense->reference }}</span> ·
        {{ $expense->spent_on?->format('d M Y') }}
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $expense->statusTone() ? 'badge-'.$expense->statusTone() : '' }}">
            <span class="badge-dot"></span> {{ $expense->statusLabel() }}
        </span>
        <span class="badge badge-danger">₹{{ number_format((float) $expense->amount, 2) }}</span>
        <span class="badge">{{ $expense->methodLabel() }}</span>
        <span class="badge badge-info">{{ $expense->category?->name ?? 'Uncategorised' }}</span>
    </div>

    <dl class="sec-facts">
        <div><dt>Shop</dt><dd>{{ $expense->shop?->name ?? '—' }}</dd></div>
        <div><dt>Paid to</dt><dd>{{ $expense->paid_to ?: '—' }}</dd></div>
        <div><dt>Reference no</dt><dd>{{ $expense->transaction_ref ?: '—' }}</dd></div>
        <div><dt>Recorded by</dt><dd>{{ $expense->created_by_name ?? '—' }}</dd></div>

        @if ($expense->approved_at)
            <div><dt>Decided by</dt><dd>{{ $expense->approved_by_name ?? '—' }}</dd></div>
            <div><dt>Decided on</dt><dd>{{ $expense->approved_at->format('d M Y, H:i') }}</dd></div>
        @endif
    </dl>

    @if ($expense->notes)
        <div style="margin-top:16px">
            <div class="form-label">Notes</div>
            <p class="text-sm text-muted">{{ $expense->notes }}</p>
        </div>
    @endif

    @if ($expense->review_note)
        <div style="margin-top:14px">
            <div class="form-label">Reviewer's note</div>
            <p class="text-sm text-muted">{{ $expense->review_note }}</p>
        </div>
    @endif

    <div style="margin-top:16px">
        <div class="form-label">Bill</div>
        @if ($attachment)
            <a class="btn btn-sm" href="{{ $attachment }}" target="_blank" rel="noopener">
                <x-icon name="file" :size="14" /> Open the attachment
            </a>
        @else
            <p class="text-sm text-muted">Nothing attached.</p>
        @endif
    </div>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @if ($expense->isEditable())
        @allows('finance.expenses.approve')
            <form method="POST" action="{{ route('admin.expenses.approve', $expense) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline">
                @csrf
                @method('PUT')
                <button type="submit" class="btn btn-primary">
                    <x-icon name="user-check" :size="15" /> Approve
                </button>
            </form>
        @endallows
    @endif
</div>
