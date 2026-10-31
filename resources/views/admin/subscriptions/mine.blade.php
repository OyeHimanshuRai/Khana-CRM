@extends('admin.layouts.app')

@section('title', 'Subscription')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/billing.css') }}?v={{ filemtime(public_path('assets/css/billing.css')) }}">
@endpush

@push('scripts')
    <script src="{{ asset('assets/js/billing.js') }}?v={{ filemtime(public_path('assets/js/billing.js')) }}" defer></script>
@endpush

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
                        {{ $subscription->currency === 'INR' ? '₹' : $subscription->currency.' ' }}{{ number_format((float) $subscription->price, 2) }}
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
            <div class="form-section-title">
                What you are using
                <x-info label="How limits work">Limits are checked when you add a branch or a staff account, never when you read one. Nothing you have already recorded is ever put out of reach.</x-info>
            </div>

            {{-- A figure against a limit is a proportion, so it is drawn as one. --}}
            <dl class="usage">
                @foreach ($usage as $row)
                    <div class="usage-row">
                        <div class="usage-head">
                            <dt>{{ $row['label'] }}</dt>
                            <dd>
                                <strong>{{ number_format($row['used']) }}</strong>
                                @if ($row['cap'] === null)
                                    <span>of unlimited</span>
                                @else
                                    <span>of {{ number_format($row['cap']) }}</span>
                                    @if ($row['used'] >= $row['cap'])
                                        <span class="badge badge-warning">Full</span>
                                    @endif
                                @endif
                            </dd>
                        </div>

                        @if ($row['cap'] !== null && $row['cap'] > 0)
                            <div class="meter {{ $row['used'] >= $row['cap'] ? 'is-full' : '' }}"
                                 role="meter" aria-label="{{ $row['label'] }} in use"
                                 aria-valuemin="0" aria-valuemax="{{ $row['cap'] }}" aria-valuenow="{{ $row['used'] }}">
                                <span style="width: {{ min(100, (int) round($row['used'] / $row['cap'] * 100)) }}%"></span>
                            </div>
                        @endif
                    </div>
                @endforeach
            </dl>

            @if ($plan)
                <div class="form-section-title" style="margin-top:14px">What your plan includes</div>
                <ul class="tick-list">
                    @foreach ($plan->limitLines() as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif

        </div>

        {{-- Choose, renew or change a plan. Prices and credit are worked out on the
             server; the page only asks for a quote and then for a checkout. --}}
        @if ($plans->isNotEmpty())
            @php
                $current = $billingTarget;
                $startPeriod = $current?->billing_period === 'yearly' ? 'yearly' : 'monthly';
            @endphp

            <div class="card billing" id="billing" data-billing
                 data-period="{{ $startPeriod }}"
                 data-company="{{ $tenant->name }}"
                 data-can-pay="{{ $canPay && $onlineBilling ? '1' : '0' }}"
                 data-quote-url="{{ route('admin.subscription.quote') }}"
                 data-checkout-url="{{ route('admin.subscription.checkout') }}"
                 data-verify-url="{{ route('admin.subscription.verify') }}"
                 data-cancel-url="{{ route('admin.subscription.cancel') }}"
                 data-resume-url="{{ route('admin.subscription.resume') }}">

                <div class="billing-head">
                    <div>
                        <div class="form-section-title">Plans</div>
                        @if ($current?->shop)
                            <p class="text-xs text-muted">Paying for {{ $current->shop->name }}</p>
                        @endif
                    </div>

                    <div class="billing-toggle" role="group" aria-label="Billing period">
                        <button type="button" data-billing-period="monthly"
                                aria-pressed="{{ $startPeriod === 'monthly' ? 'true' : 'false' }}">Monthly</button>
                        <button type="button" data-billing-period="yearly"
                                aria-pressed="{{ $startPeriod === 'yearly' ? 'true' : 'false' }}">Yearly</button>
                    </div>
                </div>

                @if ($current === null)
                    <div class="alert alert-info">Plans are shown for reference. Contact support to get started on one.</div>
                @elseif (! $onlineBilling && $canPay)
                    <div class="alert alert-info">Online payment is not set up yet. Pick a plan and request it below — our team will confirm it and get you back to work.</div>
                @elseif (! $onlineBilling)
                    <div class="alert alert-info">Ask your account owner to renew or change the plan.</div>
                @elseif (! $canPay)
                    <div class="alert alert-info">Ask your account owner to pay for or change the plan.</div>
                @endif

                <div class="billing-grid">
                    @foreach ($plans as $p)
                        @php
                            $monthly = $p->priceFor('monthly');
                            $yearly = $p->priceFor('yearly');
                            $saving = $monthly > 0 && $yearly < $monthly * 12
                                ? (int) round((1 - $yearly / ($monthly * 12)) * 100)
                                : 0;
                            $isCurrent = $current && $current->plan_id === $p->id;
                        @endphp

                        <article class="billing-plan {{ $isCurrent ? 'is-current' : '' }}" data-plan="{{ $p->id }}">
                            <header>
                                <h3>{{ $p->name }}</h3>
                                @if ($isCurrent)<span class="badge badge-success">Current</span>@endif
                            </header>

                            @if ($p->blurb)<p class="text-sm text-muted">{{ $p->blurb }}</p>@endif

                            <div class="billing-price">
                                <span data-price="monthly">{{ $p->currency === 'INR' ? '₹' : $p->currency.' ' }}{{ number_format($monthly, 0) }}<small>/month + GST</small></span>
                                <span data-price="yearly">{{ $p->currency === 'INR' ? '₹' : $p->currency.' ' }}{{ number_format($yearly, 0) }}<small>/year + GST</small>
                                    @if ($saving > 0)<em>Save {{ $saving }}%</em>@endif
                                </span>
                            </div>

                            <ul class="text-sm">
                                @foreach ($p->limitLines() as $line)<li>{{ $line }}</li>@endforeach
                            </ul>

                            @if ($current && $canPay && $onlineBilling)
                                <button type="button" class="btn {{ $isCurrent ? '' : 'btn-primary' }}" data-plan-select="{{ $p->id }}">
                                    {{ $isCurrent ? 'Renew' : 'Choose '.$p->name }}
                                </button>
                            @elseif ($current && $canPay)
                                {{-- Nothing is paid online here: ask for it instead, from this screen. --}}
                                <form method="POST" action="{{ route('admin.subscription.renewal-request') }}" data-ajax>
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $p->id }}">
                                    <input type="hidden" name="period" value="{{ $startPeriod }}" data-renewal-period>
                                    <button type="submit" class="btn {{ $isCurrent ? '' : 'btn-primary' }}">
                                        {{ $isCurrent ? 'Request renewal' : 'Request '.$p->name }}
                                    </button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="billing-confirm" data-billing-confirm hidden role="status" aria-live="polite">
                    <div data-billing-summary></div>
                    <div class="billing-actions">
                        <button type="button" class="btn btn-primary" data-billing-pay>Continue</button>
                        <button type="button" class="btn btn-ghost" data-billing-dismiss>Not now</button>
                    </div>
                </div>

                @if ($current && $canPay)
                    <div class="billing-manage">
                        @if ($current->cancelled_at?->isFuture())
                            <button type="button" class="btn btn-sm" data-billing-resume>Keep my subscription</button>
                        @elseif ($current->cancelled_at === null)
                            <button type="button" class="btn btn-sm btn-ghost" data-billing-cancel>Cancel at end of term</button>
                        @endif
                    </div>
                @endif
            </div>
        @endif

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
                                <th><span class="sr-only">Receipt</span></th>
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
                                    <td class="text-sm">
                                        @unless ($payment->isRefund())
                                            <a href="{{ route('admin.subscription.invoice', $payment) }}" target="_blank" rel="noopener">Receipt</a>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
@endsection
