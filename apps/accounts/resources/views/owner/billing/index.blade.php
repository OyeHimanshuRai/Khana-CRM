@extends('khana::layouts.app')

@section('title', 'Plan & billing')

@section('content')
    <x-khana::page-header title="Plan &amp; billing" subtitle="What each outlet is on, and what it owes" />

    @foreach ($outlets as $outlet)
        @php $sub = $outlet->subscriptions->sortByDesc('id')->first(); @endphp
        <div class="card" style="margin-bottom:16px">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                <span class="card-title">{{ $outlet->name }} <span class="text-muted">{{ $outlet->code }}</span></span>
                @if ($sub)
                    <span class="badge badge-{{ $sub->stateTone() }}">{{ $sub->stateLabel() }}</span>
                @endif
            </div>

            <div class="card-body">
                @if (! $sub)
                    <p class="text-muted">This outlet has no plan.</p>
                @else
                    <div class="form-row">
                        <div><div class="stat-label">Plan</div><div>{{ $sub->plan->name }}</div></div>
                        <div><div class="stat-label">Price</div><div>₹{{ number_format($sub->price_paise / 100, 2) }} / {{ $sub->billing_period }}</div></div>
                        <div><div class="stat-label">Works until</div><div>{{ $sub->status()[1]?->format('d M Y') ?? 'Perpetual' }}</div></div>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
                        {{-- Record a payment --}}
                        <form method="POST" action="{{ route('billing.pay', $sub) }}" style="display:flex;gap:6px;align-items:center">
                            @csrf
                            <select name="method" class="form-control" style="width:auto">
                                @foreach (\App\Models\PlatformPayment::METHODS as $method)
                                    <option value="{{ $method }}">{{ ucfirst($method) }}</option>
                                @endforeach
                            </select>
                            <input name="reference" class="form-control" style="width:auto" placeholder="Reference (optional)">
                            <button class="btn btn-sm btn-primary">Record payment</button>
                        </form>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                        {{-- Change plan --}}
                        <form method="POST" action="{{ route('billing.plan', $sub) }}" style="display:flex;gap:6px;align-items:center">
                            @csrf
                            <select name="plan" class="form-control" style="width:auto">
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->code }}" @selected($plan->id === $sub->plan_id)>{{ $plan->name }}</option>
                                @endforeach
                            </select>
                            <select name="period" class="form-control" style="width:auto">
                                <option value="monthly" @selected($sub->billing_period === 'monthly')>Monthly</option>
                                <option value="yearly" @selected($sub->billing_period === 'yearly')>Yearly</option>
                            </select>
                            <button class="btn btn-sm btn-ghost">Change plan</button>
                        </form>

                        {{-- Cancel --}}
                        <form method="POST" action="{{ route('billing.cancel', $sub) }}"
                              onsubmit="return confirm('Drop this outlet\'s plan at the end of the paid term?')">
                            @csrf
                            <button class="btn btn-sm btn-ghost is-danger">Cancel plan</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
@endsection
