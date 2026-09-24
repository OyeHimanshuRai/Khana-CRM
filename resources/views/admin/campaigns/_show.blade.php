{{--
    One campaign: what it says, who it went to, and what happened.

    Failures are listed first — they are the only rows anybody opens this
    screen to look at.
--}}

<div class="sec-name">{{ $campaign->name }}</div>
<div class="text-sm text-muted">
    {{ $campaign->channelLabel() }} · drafted by {{ $campaign->creator?->name ?? 'somebody' }}
    on {{ $campaign->created_at?->format('j M Y') }}
</div>

<div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
    <span class="badge badge-{{ $campaign->statusTone() }}">
        <span class="badge-dot"></span> {{ $campaign->statusLabel() }}
    </span>
    @if ($campaign->audience_count > 0)
        <span class="badge badge-info">{{ number_format($campaign->audience_count) }} people</span>
    @endif
    @if ($campaign->failed_count > 0)
        <span class="badge badge-danger">{{ number_format($campaign->failed_count) }} failed</span>
    @endif
</div>

<div class="alert alert-info" style="margin-bottom:14px">
    {{ $campaign->renderFor('Mrs Mehta') }}
    <span class="text-xs text-muted" style="display:block; margin-top:6px">
        Shown with a name filled in, the way a guest would see it.
    </span>
</div>

<dl class="sec-facts">
    <div><dt>Sent</dt><dd>{{ number_format($campaign->sent_count) }}</dd></div>
    <div><dt>Failed</dt><dd>{{ number_format($campaign->failed_count) }}</dd></div>
    <div>
        <dt>Started</dt>
        <dd>{{ $campaign->started_at?->format('j M, g:i a') ?? '—' }}</dd>
    </div>
    <div>
        <dt>Finished</dt>
        <dd>{{ $campaign->finished_at?->format('j M, g:i a') ?? '—' }}</dd>
    </div>
    @if ($campaign->scheduled_for)
        <div><dt>Scheduled for</dt><dd>{{ $campaign->scheduled_for->format('j M, g:i a') }}</dd></div>
    @endif
</dl>

@allows('crm.campaigns.approve')
    @if ($campaign->status === App\Models\Campaign::DRAFT)
        <div class="form-section" style="margin-top:14px">
            <div class="form-section-title">Send it</div>

            <form method="POST" action="{{ route('admin.campaigns.send', $campaign) }}"
                  data-ajax data-close-modal data-refresh-list
                  onsubmit="return confirm('Queue this campaign? Once it starts going out it cannot be recalled.')">
                @csrf

                <div class="settings-grid">
                    <div class="field">
                        <label for="cp-when">When</label>
                        <input id="cp-when" type="datetime-local" name="scheduled_for" class="form-control"
                               aria-invalid="false">
                        <div class="form-hint">Leave empty to start within ten minutes.</div>
                    </div>

                    <div class="field" style="display:flex; align-items:flex-end">
                        <button type="submit" class="btn btn-primary">Queue campaign</button>
                    </div>
                </div>

                <p class="text-xs text-muted" style="margin-top:8px">
                    The audience is worked out and frozen the moment you press this, so a customer who
                    visits tomorrow will not slip in or out of it half way through.
                </p>
            </form>
        </div>
    @endif
@endallows

@if ($recipients->isNotEmpty())
    <div class="form-section">
        <div class="form-section-title">
            Recipients
            @if ($campaign->audience_count > $recipients->count())
                <span class="text-xs text-muted">
                    (first {{ number_format($recipients->count()) }} of
                    {{ number_format($campaign->audience_count) }})
                </span>
            @endif
        </div>

        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr><th>Who</th><th>Number</th><th>Status</th><th>When</th></tr>
                </thead>
                <tbody>
                    @foreach ($recipients as $row)
                        <tr>
                            <td class="text-sm">{{ $row->customer?->name ?: ($row->name ?: '—') }}</td>
                            <td class="text-sm">{{ $row->destination }}</td>
                            <td>
                                <span class="badge badge-{{ $row->statusTone() }}">{{ ucfirst($row->status) }}</span>
                                @if ($row->error)
                                    <span class="text-xs text-danger" style="display:block">{{ $row->error }}</span>
                                @endif
                            </td>
                            <td class="text-sm">{{ $row->sent_at?->format('j M, g:i a') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('crm.campaigns.approve')
        @unless ($campaign->isFinished())
            <form method="POST" action="{{ route('admin.campaigns.cancel', $campaign) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline"
                  onsubmit="return confirm('Stop this campaign? Anything already sent cannot be recalled.')">
                @csrf
                @method('PUT')
                <button type="submit" class="btn is-danger">Stop</button>
            </form>
        @endunless
    @endallows

    @allows('crm.campaigns.edit')
        @if ($campaign->isEditable())
            <a class="btn btn-primary" href="{{ route('admin.campaigns.edit', $campaign) }}"
               data-modal="{{ route('admin.campaigns.edit', $campaign) }}"
               data-modal-title="Edit Campaign"
               data-modal-sub="{{ $campaign->name }}"
               data-modal-size="lg">Edit</a>
        @endif
    @endallows
</div>
