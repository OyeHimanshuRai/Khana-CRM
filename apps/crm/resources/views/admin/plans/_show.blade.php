{{--
    Read-only plan detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from re-pricing everything on sale.
--}}

@php
    $everything = $plan->modules === null;
    $keys = $plan->moduleKeys();
@endphp

<div class="sec-name">{{ $plan->name }}</div>

@if ($plan->blurb)
    <div class="text-sm text-muted">{{ $plan->blurb }}</div>
@endif

<div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
    <span class="badge {{ $plan->is_active ? 'badge-success' : 'badge-muted' }}">
        <span class="badge-dot"></span> {{ $plan->is_active ? 'On sale' : 'Withdrawn from sale' }}
    </span>
    <span class="badge badge-info">{{ $plan->code }}</span>

    @if ($live > 0)
        <span class="badge badge-brand">{{ number_format($live) }} subscriber{{ $live === 1 ? '' : 's' }}</span>
    @endif
</div>

@unless ($plan->is_active)
    <div class="alert alert-warning" style="margin-bottom:14px">
        <strong>Withdrawn from sale.</strong>
        Businesses already on it keep it and carry on being billed; it is simply not offered
        to anybody new.
    </div>
@endunless

<dl class="sec-facts">
    <div><dt>Monthly</dt><dd>{{ $plan->currency }} {{ number_format((float) $plan->monthly_price, 2) }}</dd></div>
    <div>
        <dt>Yearly</dt>
        <dd>
            {{ $plan->currency }} {{ number_format($plan->priceFor('yearly'), 2) }}
            @if ($plan->yearly_price === null)
                <span class="text-xs text-muted">(twelve months)</span>
            @endif
        </dd>
    </div>
    <div><dt>Trial</dt><dd>{{ $plan->trial_days > 0 ? $plan->trial_days.' days' : 'None' }}</dd></div>
    <div><dt>Branches</dt><dd>{{ $plan->max_shops === null ? 'Unlimited' : number_format($plan->max_shops) }}</dd></div>
    <div><dt>Staff accounts</dt><dd>{{ $plan->max_users === null ? 'Unlimited' : number_format($plan->max_users) }}</dd></div>
    <div>
        <dt>Orders a month</dt>
        <dd>{{ $plan->max_orders_per_month === null ? 'Unlimited' : number_format($plan->max_orders_per_month) }}</dd>
    </div>
    <div><dt>Display order</dt><dd>{{ $plan->sort_order }}</dd></div>
    <div><dt>Created</dt><dd>{{ $plan->created_at?->format('d M Y') ?? '—' }}</dd></div>
</dl>

<div style="margin-top:14px">
    <div class="form-section-title">Includes</div>

    @if ($everything)
        <p class="text-sm">
            <span class="badge badge-success">Every module</span>
        </p>
        <p class="text-xs text-muted">
            Stored as "no restriction" rather than a ticked list, so a module added next year
            belongs to this plan without anybody editing it.
        </p>
    @elseif ($keys === [])
        <p class="text-sm text-muted">No modules. A business on this plan can reach nothing.</p>
    @else
        <div style="display:flex;flex-wrap:wrap;gap:6px">
            @foreach ($keys as $key)
                <span class="badge badge-info">{{ $modules[$key]['label'] ?? $key }}</span>
            @endforeach
        </div>
    @endif

    <p class="text-xs text-muted" style="margin-top:10px">
        A plan is a ceiling, not a switch. A branch still chooses its own modules, and what it
        gets is the overlap — selling a restaurant the inventory module does not mean its
        takeaway counter wants a stock ledger.
    </p>

    <p class="text-xs text-muted">
        Branch and staff limits are checked when one is added, never when one is read: a
        business is never locked out of records it already owns.
    </p>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('settings.plans.edit')
        <a class="btn btn-primary" href="{{ route('admin.plans.edit', $plan) }}"
           data-modal="{{ route('admin.plans.edit', $plan) }}"
           data-modal-title="Edit Plan"
           data-modal-sub="{{ $plan->name }}"
           data-modal-size="lg">Edit</a>
    @endallows
</div>
