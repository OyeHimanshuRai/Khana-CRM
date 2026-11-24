{{--
    One license: where it stands, what can be done to it, and its history.
    Rendered into the modal body; every action is a data-ajax form.
--}}
@php
    use App\Models\License;
    $badge = $license->badge();
    $left = $license->daysLeft();
    $sub = $license->tenant?->subscription;
    $act = fn (string $a) => route('admin.licenses.act', [$license, $a]);
    $status = $license->status;
@endphp

<div class="sec-name"><code>{{ $license->key_hint }}</code></div>
<div class="text-sm text-muted">{{ $license->tenant?->name ?? $tenant->name }}</div>

<div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
    <span class="badge badge-{{ $badge['tone'] }}"><span class="badge-dot"></span> {{ $badge['label'] }}</span>
    @if ($license->plan)<span class="badge badge-info">{{ $license->plan->name }}</span>@endif
    @if ($license->tenant?->isSuspended())<span class="badge badge-danger">Customer blocked</span>@endif
</div>

@if ($status === License::PENDING)
    <div class="alert alert-warning" style="margin-bottom:14px">
        <strong>Waiting for approval.</strong>
        {{ $license->tenant?->name }} entered this key on {{ $license->requested_at?->format('j M Y, g:i a') }}.
        They cannot use the product until you approve it.
    </div>
@endif

<dl class="sec-facts lic-facts">
    <div><dt>Customer</dt><dd>{{ $license->tenant?->name ?? '—' }}</dd></div>
    <div><dt>Plan</dt><dd>{{ $license->plan?->name ?? '—' }}</dd></div>
    <div><dt>Validity</dt><dd>{{ $license->validity_days }} days</dd></div>
    <div>
        <dt>Expires</dt>
        <dd>
            {{ $license->expires_at?->format('j M Y') ?? 'Set on approval' }}
            @if ($left !== null)<span class="text-xs text-muted">({{ $left < 0 ? abs($left).' days ago' : 'in '.$left.' days' }})</span>@endif
        </dd>
    </div>
    <div><dt>Activated</dt><dd>{{ $license->activated_at?->format('j M Y, g:i a') ?? '—' }}</dd></div>
    <div><dt>Approved by</dt><dd>{{ $license->approver?->name ?? '—' }}</dd></div>
    <div><dt>Entered by</dt><dd>{{ $license->activatedBy?->name ?? '—' }}</dd></div>
    <div><dt>Last renewed</dt><dd>{{ $license->last_renewed_at?->format('j M Y') ?? '—' }}</dd></div>
    @if ($sub)
        <div><dt>Subscription</dt><dd>{{ $sub->stateLabel() }} · until {{ $sub->ends_at?->format('j M Y') ?? 'no end' }}</dd></div>
    @endif
    @if ($license->notes)<div><dt>Note</dt><dd>{{ $license->notes }}</dd></div>@endif
</dl>

@if ($canEdit)
    @php
        $isLive = in_array($status, [License::ACTIVE, License::INACTIVE, License::ISSUED], true);
        $canPlan = $status !== License::REVOKED;
        $canReset = in_array($status, [License::ACTIVE, License::PENDING, License::INACTIVE, License::BLOCKED], true);
    @endphp

    <div class="lic-panel">

        {{-- What needs doing now: the one primary action for this state. --}}
        @if ($status === License::ISSUED || $status === License::PENDING || $status === License::INACTIVE)
            <section class="lic-group">
                <div class="lic-group-title">Next step</div>

                @if ($status === License::ISSUED)
                    <form method="POST" action="{{ $act('rotate') }}" data-ajax data-refresh-list data-license-generate data-toast-success="false">@csrf
                        <button class="btn btn-primary lic-wide" type="submit">Generate key for {{ $tenant->name }}</button>
                        <p class="lic-hint">Shown once. Send it to the customer - they type it and everything starts.</p>
                    </form>
                @elseif ($status === License::PENDING)
                    <div class="lic-pair">
                        <form method="POST" action="{{ $act('approve') }}" data-ajax data-close-modal data-refresh-list>@csrf
                            <button class="btn btn-primary lic-wide" type="submit">Approve</button></form>
                        <form method="POST" action="{{ $act('reject') }}" data-ajax data-close-modal data-refresh-list
                              onsubmit="return confirm('Reject this activation? The key can be entered again.')">@csrf
                            <button class="btn lic-wide" type="submit">Reject</button></form>
                    </div>
                @else
                    <form method="POST" action="{{ $act('reactivate') }}" data-ajax data-close-modal data-refresh-list>@csrf
                        <button class="btn btn-primary lic-wide" type="submit">Activate again</button></form>
                @endif
            </section>
        @endif

        @if ($isLive)
            <section class="lic-group">
                <div class="lic-group-title">Validity</div>

                <form method="POST" action="{{ $act('renew') }}" data-ajax data-close-modal data-refresh-list class="lic-row">@csrf
                    <label for="ren-{{ $license->id }}">Renew for</label>
                    <input id="ren-{{ $license->id }}" type="number" name="days" class="input" min="1" max="3650" value="{{ $license->validity_days }}" required>
                    <span class="lic-unit">days</span>
                    <button class="btn" type="submit">Renew</button>
                </form>

                <form method="POST" action="{{ $act('extend') }}" data-ajax data-close-modal data-refresh-list class="lic-row">@csrf
                    <label for="ext-{{ $license->id }}">Extend by</label>
                    <input id="ext-{{ $license->id }}" type="number" name="days" class="input" min="1" max="3650" value="30" required>
                    <span class="lic-unit">days</span>
                    <button class="btn" type="submit">Extend</button>
                </form>

                <form method="POST" action="{{ $act('expiry') }}" data-ajax data-close-modal data-refresh-list class="lic-row">@csrf
                    <label for="exp-{{ $license->id }}">Expires on</label>
                    <input id="exp-{{ $license->id }}" type="date" name="expires_at" class="input" min="{{ now()->addDay()->toDateString() }}"
                           value="{{ $license->expires_at?->toDateString() }}" required>
                    <span class="lic-unit"></span>
                    <button class="btn" type="submit">Set date</button>
                </form>
            </section>
        @endif

        @if ($canPlan)
            <section class="lic-group">
                <div class="lic-group-title">Plan</div>

                <form method="POST" action="{{ $act('plan') }}" data-ajax data-close-modal data-refresh-list class="lic-row">@csrf
                    <label for="pl-{{ $license->id }}">Move to</label>
                    <select id="pl-{{ $license->id }}" name="plan_id" class="input" required>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected($license->plan_id === $plan->id)>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                    <span class="lic-unit"></span>
                    <button class="btn" type="submit">Upgrade / downgrade</button>
                </form>
            </section>
        @endif

        <section class="lic-group lic-danger">
            <div class="lic-group-title">Access</div>

            <div class="lic-buttons">
                @if ($status === License::ACTIVE)
                    <form method="POST" action="{{ $act('deactivate') }}" data-ajax data-close-modal data-refresh-list
                          onsubmit="return confirm('Deactivate this license? The customer is locked out until you switch it back on.')">@csrf
                        <button class="btn" type="submit">Deactivate</button></form>
                @endif

                @if ($status === License::BLOCKED)
                    <form method="POST" action="{{ $act('unblock') }}" data-ajax data-close-modal data-refresh-list>@csrf
                        <button class="btn btn-primary" type="submit">Unblock license</button></form>
                @elseif ($status !== License::REVOKED)
                    <form method="POST" action="{{ $act('block') }}" data-ajax data-close-modal data-refresh-list
                          onsubmit="return confirm('Block this license?')">@csrf
                        <button class="btn" type="submit">Block license</button></form>
                @endif

                @if ($canReset)
                    <form method="POST" action="{{ $act('reset') }}" data-ajax data-close-modal data-refresh-list
                          onsubmit="return confirm('Reset the activation? The customer will have to enter the key again.')">@csrf
                        <button class="btn" type="submit">Reset activation</button></form>
                @endif

                @if ($license->tenant)
                    @if ($license->tenant->isSuspended())
                        <form method="POST" action="{{ $act('unblock-customer') }}" data-ajax data-close-modal data-refresh-list>@csrf
                            <button class="btn btn-primary" type="submit">Unblock customer</button></form>
                    @else
                        <form method="POST" action="{{ $act('block-customer') }}" data-ajax data-close-modal data-refresh-list
                              onsubmit="var r = prompt('Why is {{ addslashes($license->tenant->name) }} being blocked?'); if (!r) { return false; } this.querySelector('[name=reason]').value = r; return true;">@csrf
                            <input type="hidden" name="reason" value="">
                            <button class="btn is-danger" type="submit">Block customer</button></form>
                    @endif
                @endif

                @if ($status !== License::REVOKED)
                    <form method="POST" action="{{ $act('revoke') }}" data-ajax data-close-modal data-refresh-list
                          onsubmit="return confirm('Revoke this license for good? The key can never be used again.')">@csrf
                        <button class="btn is-danger" type="submit">Revoke license</button></form>
                @endif
            </div>
        </section>
    </div>
@endif

@if ($activations->isNotEmpty())
    <div style="margin-top:14px">
        <div class="form-section-title">Activation attempts</div>
        <div class="table-wrap">
            <table class="table table-list">
                <thead><tr><th>When</th><th>Customer</th><th>By</th><th>Result</th></tr></thead>
                <tbody>
                @foreach ($activations as $a)
                    <tr>
                        <td class="text-sm">{{ $a->requested_at?->format('j M Y, g:i a') }}</td>
                        <td class="text-sm">{{ $a->tenant?->name }}</td>
                        <td class="text-sm">{{ $a->user?->name ?? '—' }}<span class="text-xs text-muted" style="display:block">{{ $a->ip_address }}</span></td>
                        <td class="text-sm">{{ ucfirst($a->status) }}@if ($a->reason)<span class="text-xs text-muted" style="display:block">{{ $a->reason }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div style="margin-top:14px">
    <div class="form-section-title">History</div>
    @if ($logs->isEmpty())
        <p class="text-sm text-muted">Nothing yet.</p>
    @else
        <ul class="text-sm license-history">
            @foreach ($logs as $log)
                <li>
                    <span class="text-muted">{{ $log->created_at?->format('j M Y, g:i a') }}</span>
                    — {{ $log->description }}
                    <span class="text-xs text-muted">({{ $log->user?->name ?? 'System' }})</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>

