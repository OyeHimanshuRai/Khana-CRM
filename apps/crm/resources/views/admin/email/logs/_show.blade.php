{{--
    One log entry, rendered straight into the modal body.

    Metadata only. There is deliberately no body here and none in the table
    either - the welcome email carries a one-time password-set link, and
    storing rendered bodies would put working credentials in front of anyone
    holding email.logs.view. See the migration.
--}}

<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
    <span class="badge {{ $log->statusTone() }}">
        <span class="badge-dot"></span> {{ $log->statusLabel() }}
    </span>
    <span class="badge badge-info">{{ $log->kindLabel() }}</span>

    @if ($log->isStuck())
        <span class="badge badge-warning">Never resolved</span>
    @endif
</div>

<div class="sec-name">{{ $log->subject ?: '(no subject)' }}</div>
<p class="text-sm text-muted" style="margin:6px 0 0">To {{ $log->to_email }}</p>

<div class="form-section">
    <div class="form-section-title">Delivery</div>

    <dl class="sec-facts">
        <div><dt>To</dt><dd style="font-size:13px">{{ $log->to_email }}</dd></div>
        <div><dt>Name</dt><dd style="font-size:13px">{{ $log->displayName() }}</dd></div>
        <div><dt>From</dt><dd style="font-size:13px">{{ $log->from_email ?: '—' }}</dd></div>
        <div><dt>Reply-to</dt><dd style="font-size:13px">{{ $log->reply_to ?: '—' }}</dd></div>
        <div><dt>Mailer</dt><dd style="font-size:13px">{{ $log->mailer ?: '—' }}</dd></div>
        <div>
            <dt>Reference</dt>
            <dd class="list-ref" style="font-size:11.5px">{{ $log->uuid }}</dd>
        </div>
    </dl>

    @if ($log->extraRecipients()->isNotEmpty())
        <dl class="sec-facts" style="margin-top:12px">
            @foreach ($log->extraRecipients() as $label => $addresses)
                <div>
                    <dt>{{ $label }}</dt>
                    <dd style="font-size:12.5px">{{ $addresses }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>

<div class="form-section">
    <div class="form-section-title">Timing</div>

    <dl class="sec-facts">
        <div><dt>Started</dt><dd>{{ $log->created_at?->format('d M Y, H:i:s') ?? '—' }}</dd></div>
        <div><dt>Confirmed</dt><dd>{{ $log->sent_at?->format('d M Y, H:i:s') ?? '—' }}</dd></div>
    </dl>

    @if ($log->status === \App\Models\EmailLog::PENDING)
        <div class="form-hint" style="margin-top:10px">
            Handed to the transport, but nothing came back either way. A row that stays here usually
            means the process died mid-send.
        </div>
    @endif
</div>

@if ($log->error)
    <div class="form-section">
        <div class="form-section-title">Failure</div>
        <p class="text-sm" style="white-space:pre-line;color:var(--danger)">{{ $log->error }}</p>
        <div class="form-hint" style="margin-top:8px">
            Reported by the mail transport. A credentials or connection error usually points at
            Settings &gt; General &gt; Mail Configuration.
        </div>
    </div>
@endif

@if ($log->mailable)
    <div class="form-section">
        <div class="form-section-title">Source</div>
        <p class="text-sm">
            Produced by <span class="list-ref">{{ $log->mailable }}</span>
        </p>
    </div>
@endif

<div class="form-hint" style="margin-top:18px">
    Message contents are deliberately not stored. Several of the app's emails carry one-time
    sign-in links, and keeping their bodies here would hand a working credential to anyone who can
    read this screen.
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>
</div>
