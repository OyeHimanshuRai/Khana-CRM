{{--
    One business's subscription, its history and its ledger.

    Rendered into the modal body; no <script> may live here. The cancel and
    resume forms post through data-ajax like every other write in the admin.
--}}

@php
    $plan = $subscription?->plan;
    $left = $subscription?->daysLeft();
@endphp

<div class="sec-name">{{ $tenant->name }}</div>
<div class="text-sm text-muted">{{ $tenant->code }}</div>

<div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
    @if ($subscription)
        <span class="badge badge-{{ $subscription->stateTone() }}">
            <span class="badge-dot"></span> {{ $subscription->stateLabel() }}
        </span>
        @if ($plan)
            <span class="badge badge-info">{{ $plan->name }}</span>
        @endif
    @else
        <span class="badge badge-muted">No plan</span>
    @endif

    <span class="badge {{ $tenant->is_active ? 'badge-success' : 'badge-danger' }}">
        <span class="badge-dot"></span> {{ $tenant->is_active ? 'Trading' : 'Suspended' }}
    </span>
</div>

@if ($subscription === null)
    <div class="alert alert-info">
        <strong>Not on a plan.</strong>
        Nothing is limited: every module its branches have chosen is available, and it can add
        as many branches and staff as it likes. That is the right state for a single restaurant
        that is not being sold a subscription.
    </div>
@else
    @if ($subscription->needsAttention())
        <div class="alert alert-warning" style="margin-bottom:14px">
            <strong>Payment due.</strong>
            The term ended {{ $subscription->ends_at?->format('j M Y') }}. They keep trading until
            {{ $subscription->graceEndsAt()?->format('j M Y') }} — {{ $subscription->grace_days }}
            days of grace — and are locked out after that.
        </div>
    @elseif (! $subscription->isUsable())
        <div class="alert alert-danger" style="margin-bottom:14px">
            <strong>{{ $subscription->stateLabel() }}.</strong>
            Staff cannot sign in. Every invoice, payment and stock balance is untouched and comes
            back the moment a payment is recorded.
        </div>
    @endif

    <dl class="sec-facts">
        <div><dt>Plan</dt><dd>{{ $plan?->name ?? '—' }}</dd></div>
        <div>
            <dt>Price</dt>
            <dd>
                {{ $subscription->currency }} {{ number_format((float) $subscription->price, 2) }}
                per {{ $subscription->billing_period === 'yearly' ? 'year' : 'month' }}
            </dd>
        </div>
        <div><dt>Started</dt><dd>{{ $subscription->starts_at?->format('j M Y') ?? '—' }}</dd></div>
        <div>
            <dt>Runs until</dt>
            <dd>
                {{ $subscription->ends_at?->format('j M Y') ?? 'No end date' }}
                @if ($left !== null)
                    <span class="text-xs text-muted">
                        ({{ $left < 0 ? abs($left).' days ago' : 'in '.$left.' days' }})
                    </span>
                @endif
            </dd>
        </div>
        @if ($subscription->trial_ends_at)
            <div><dt>Trial ends</dt><dd>{{ $subscription->trial_ends_at->format('j M Y') }}</dd></div>
        @endif
        <div><dt>Grace</dt><dd>{{ $subscription->grace_days }} days</dd></div>
        @if ($subscription->cancelled_at)
            <div>
                <dt>Cancelled</dt>
                <dd>
                    {{ $subscription->cancelled_at->format('j M Y') }}
                    @if ($subscription->cancelled_at->isFuture())
                        <span class="text-xs text-muted">(takes effect then)</span>
                    @endif
                </dd>
            </div>
        @endif
        @if ($subscription->note)
            <div><dt>Note</dt><dd>{{ $subscription->note }}</dd></div>
        @endif
    </dl>
@endif

<div style="margin-top:14px">
    <div class="form-section-title">Usage</div>
    <dl class="sec-facts">
        @foreach ($usage as $row)
            <div>
                <dt>{{ $row['label'] }}</dt>
                <dd>
                    {{ number_format($row['used']) }}
                    @if ($row['cap'] === null)
                        <span class="text-xs text-muted">of unlimited</span>
                    @else
                        of {{ number_format($row['cap']) }}
                        @if ($row['used'] >= $row['cap'])
                            <span class="badge badge-warning">full</span>
                        @endif
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</div>

@if ($payments->isNotEmpty())
    <div style="margin-top:14px">
        <div class="form-section-title">Payments</div>

        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Received</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Term bought</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            <td><span class="list-ref">{{ $payment->reference }}</span></td>
                            <td class="text-sm">{{ $payment->paid_at->format('j M Y') }}</td>
                            <td class="text-sm {{ $payment->isRefund() ? 'text-danger' : '' }}">
                                {{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}
                            </td>
                            <td class="text-sm">{{ $payment->methodLabel() }}</td>
                            <td class="text-sm">
                                {{ $payment->isRefund() ? '—' : $payment->periodLine() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($history->count() > 1)
    <div style="margin-top:14px">
        <div class="form-section-title">Plan history</div>
        <ul class="text-sm" style="margin:0; padding-left:18px">
            @foreach ($history as $row)
                <li>
                    {{ $row->plan?->name ?? 'Unknown plan' }}
                    — from {{ $row->starts_at?->format('j M Y') ?? '?' }}
                    @if ($row->id === $subscription?->id)
                        <span class="badge badge-brand">current</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @if ($canEdit)
        @if ($subscription && $subscription->cancelled_at !== null)
            <form method="POST" action="{{ route('admin.subscriptions.resume', $tenant) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline">
                @csrf
                @method('PUT')
                <button type="submit" class="btn">Undo cancellation</button>
            </form>
        @elseif ($subscription)
            <form method="POST" action="{{ route('admin.subscriptions.cancel', $tenant) }}"
                  data-ajax data-close-modal data-refresh-list style="display:inline"
                  onsubmit="return confirm('Cancel the subscription for “{{ $tenant->name }}”? They keep working until the term they have already paid for runs out.')">
                @csrf
                @method('PUT')
                <button type="submit" class="btn is-danger">Cancel subscription</button>
            </form>
        @endif

        <a class="btn" href="{{ route('admin.subscriptions.payment', $tenant) }}"
           data-modal="{{ route('admin.subscriptions.payment', $tenant) }}"
           data-modal-title="Record Payment"
           data-modal-sub="{{ $tenant->name }}">Record payment</a>

        <a class="btn btn-primary" href="{{ route('admin.subscriptions.assign', $tenant) }}"
           data-modal="{{ route('admin.subscriptions.assign', $tenant) }}"
           data-modal-title="{{ $subscription ? 'Change Plan' : 'Put on a Plan' }}"
           data-modal-sub="{{ $tenant->name }}">{{ $subscription ? 'Change plan' : 'Put on a plan' }}</a>
    @endif
</div>
