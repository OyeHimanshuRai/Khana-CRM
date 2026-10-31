{{-- Read-only reminder detail, rendered straight into the modal body. --}}

<div class="cat-view-body">
    <div class="sec-name">{{ $reminder->triggerLabel() }}</div>
    <div class="text-sm text-muted">
        {{ $reminder->customer?->name }} · {{ $reminder->invoice?->number }}
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $reminder->statusTone() ? 'badge-'.$reminder->statusTone() : '' }}">
            <span class="badge-dot"></span> {{ $reminder->statusLabel() }}
        </span>
        <span class="badge">{{ $reminder->channelLabel() }}</span>
        <span class="badge badge-info">₹{{ number_format((float) $reminder->amount_due, 2) }}</span>
    </div>

    <dl class="sec-facts">
        <div><dt>Scheduled for</dt><dd>{{ $reminder->scheduled_for?->format('d M Y, H:i') ?? '—' }}</dd></div>
        <div><dt>Sent at</dt><dd>{{ $reminder->sent_at?->format('d M Y, H:i') ?? 'Not sent' }}</dd></div>
        <div><dt>Recipient</dt><dd>{{ $reminder->recipient ?: '—' }}</dd></div>
        <div><dt>Invoice due</dt><dd>{{ $reminder->due_date?->format('d M Y') ?? '—' }}</dd></div>
        <div>
            <dt>Owed when raised</dt>
            <dd>₹{{ number_format((float) $reminder->amount_due, 2) }}</dd>
        </div>
        <div>
            <dt>Owed now</dt>
            <dd>
                {{ $reminder->invoice
                    ? '₹'.number_format((float) $reminder->invoice->due_total, 2)
                    : '—' }}
            </dd>
        </div>
        <div><dt>Attempts</dt><dd>{{ $reminder->attempts }}</dd></div>
        <div><dt>Shop</dt><dd>{{ $reminder->shop?->name ?? '—' }}</dd></div>
    </dl>

    @if ($reminder->subject)
        <div style="margin-top:16px">
            <div class="form-label">Subject</div>
            <p class="text-sm">{{ $reminder->subject }}</p>
        </div>
    @endif

    @if ($reminder->skip_reason)
        <div style="margin-top:14px">
            <div class="form-label">Why it was not sent</div>
            <p class="text-sm text-muted">{{ $reminder->skip_reason }}</p>
        </div>
    @endif

    @if ($reminder->last_error)
        <div style="margin-top:14px">
            <div class="form-label">Last error</div>
            <p class="text-sm" style="color:var(--danger)">{{ $reminder->last_error }}</p>
        </div>
    @endif
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @if (in_array($reminder->status, ['pending', 'failed'], true))
        @allows('crm.reminders.edit')
            <form method="POST" action="{{ route('admin.reminders.send', $reminder) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline">
                @csrf
                @method('PUT')
                <button type="submit" class="btn btn-primary">
                    <x-icon name="mail" :size="15" /> Send now
                </button>
            </form>
        @endallows
    @endif
</div>
