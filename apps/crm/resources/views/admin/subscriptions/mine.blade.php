@extends('admin.layouts.app')

@section('title', 'Subscription')

@section('content')
    <x-page-header
        title="Subscription"
        :subtitle="$tenant?->name ?? 'Your account'"
        :crumbs="['Settings' => null, 'Subscription' => null]"
    />

    @if ($tenant === null)
        <div class="card" style="padding:18px">
            <div class="empty">
                <x-icon name="inbox" :size="28" />
                <h3>No company on this account</h3>
                <p class="text-sm">Ask an administrator to assign one.</p>
            </div>
        </div>
    @elseif ($subscription === null)
        {{--
            The normal state of a single-restaurant install, and it deserves a
            plain answer rather than an empty billing screen.
        --}}
        <div class="card" style="padding:18px">
            <div class="sec-name">You are not on a subscription plan</div>
            <p class="text-sm text-muted" style="margin-top:8px">
                Nothing is limited. Every module your branches have switched on is available,
                and you can add as many branches and staff accounts as you need.
            </p>
        </div>
    @else
        @php
            $plan = $subscription->plan;
            $left = $subscription->daysLeft();
        @endphp

        @if ($subscription->needsAttention())
            <div class="alert alert-warning" style="margin-bottom:14px">
                <strong>Your payment is due.</strong>
                Your term ended on {{ $subscription->ends_at?->format('j M Y') }}. You can carry on
                working until {{ $subscription->graceEndsAt()?->format('j M Y') }}. After that,
                sign-in stops until the account is settled — every record you have is kept.
            </div>
        @elseif ($subscription->onTrial())
            <div class="alert alert-info" style="margin-bottom:14px">
                <strong>You are on a free trial</strong>
                until {{ $subscription->trial_ends_at->format('j M Y') }}.
            </div>
        @elseif ($left !== null && $left >= 0 && $left <= 7)
            <div class="alert alert-info" style="margin-bottom:14px">
                <strong>Your plan renews in {{ $left }} day{{ $left === 1 ? '' : 's' }}</strong>,
                on {{ $subscription->ends_at->format('j M Y') }}.
            </div>
        @endif

        @if ($subscription->cancelled_at)
            <div class="alert alert-danger" style="margin-bottom:14px">
                <strong>Cancelled.</strong>
                {{ $subscription->cancelled_at->isFuture()
                    ? 'Your account runs until '.$subscription->cancelled_at->format('j M Y').', which you have already paid for.'
                    : 'Contact support to start again.' }}
            </div>
        @endif

        <div class="stat-grid">
            <div class="stat">
                <div class="stat-icon"><x-icon name="tag" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Your plan</div>
                    <div class="stat-value" style="font-size:1.1rem">{{ $plan?->name ?? '—' }}</div>
                    <span class="text-xs text-muted">
                        {{ $subscription->currency }} {{ number_format((float) $subscription->price, 2) }}
                        per {{ $subscription->billing_period === 'yearly' ? 'year' : 'month' }}
                    </span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon is-info"><x-icon name="calendar" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Renews</div>
                    <div class="stat-value" style="font-size:1.1rem">
                        {{ $subscription->ends_at?->format('j M Y') ?? 'Never' }}
                    </div>
                    @if ($left !== null)
                        <span class="text-xs text-muted">
                            {{ $left < 0 ? abs($left).' days ago' : 'in '.$left.' days' }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon {{ $subscription->isUsable() ? 'is-success' : '' }}"
                     @unless ($subscription->isUsable()) style="background: var(--danger-soft); color: var(--danger)" @endunless>
                    <x-icon name="shield" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Status</div>
                    <div class="stat-value" style="font-size:1.1rem">{{ $subscription->stateLabel() }}</div>
                </div>
            </div>
        </div>

        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">What you are using</div>

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

            @if ($plan)
                <div class="form-section-title" style="margin-top:14px">What your plan includes</div>
                <ul class="text-sm" style="margin:0; padding-left:18px">
                    @foreach ($plan->limitLines() as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="text-xs text-muted" style="margin-top:12px">
                Limits are checked when you add a branch or a staff account, never when you read
                one. Nothing you have already recorded is ever put out of reach.
            </p>

            {{-- This screen says what the account is on; paying for it is the
                 other half, and it is one click rather than a phone call. --}}
            <a href="{{ route('admin.billing.show') }}" class="btn btn-primary" style="margin-top:14px">
                <x-icon name="wallet" :size="16" /> Pay for your account
            </a>
        </div>

        @if ($payments->isNotEmpty())
            <div class="card">
                <div style="padding:14px 18px 0">
                    <div class="form-section-title">Your payments</div>
                </div>

                <div class="table-wrap">
                    <table class="table table-list">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Received</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Term</th>
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
                                    <td class="text-sm">{{ $payment->isRefund() ? '—' : $payment->periodLine() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
@endsection
