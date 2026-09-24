@extends('signup.layout')

@section('title', 'Choose a plan')
@section('step', '1')

@section('content')
    {{--
        Step one, and the only thing on it.

        The business name, the city and the password used to sit underneath
        this — a long form on a phone, with the one decision the visitor had
        actually made scrolling out of sight while they filled it in. They are
        on step two now; this page asks one question.

        Each tab is a link to its own address, so a plan can be linked to,
        read out loud and bookmarked, and switching between them needs no
        JavaScript.
    --}}
    <header class="lp-head">
        <h2>Which plan fits your restaurant?</h2>
        <p>
            Priced per outlet. Start on any plan and change it whenever you like —
            you are not locked in, and nothing is charged during a free trial.
        </p>
    </header>

    <div class="lp-tabs" role="list">
        @foreach ($plans as $plan)
            <a class="lp-tab @if ($plan->is($chosen)) is-on @endif"
               role="listitem"
               href="{{ route('signup', ['plan' => $plan->slug]) }}"
               @if ($plan->is($chosen)) aria-current="true" @endif>
                <span class="lp-tab-name">{{ $plan->name }}</span>
                <span class="lp-tab-price">₹{{ number_format($plan->priceFor(\App\Models\Plan::MONTHLY), 0) }}<span class="lp-meta">/mo</span></span>
            </a>
        @endforeach
    </div>

    <div class="lp-plan-panel">
        <div class="lp-plan-panel-head">
            <div>
                <h3>{{ $chosen->name }}</h3>
                @if ($chosen->blurb)
                    <p class="lp-meta">{{ $chosen->blurb }}</p>
                @endif
            </div>

            <p class="lp-plan-panel-price">
                <strong>₹{{ number_format($chosen->priceFor(\App\Models\Plan::MONTHLY), 0) }}</strong>
                <span class="lp-meta">a month, per outlet</span>
            </p>
        </div>

        @php
            $yearlyPrice = $chosen->priceFor(\App\Models\Plan::YEARLY);
            $saved = ($chosen->priceFor(\App\Models\Plan::MONTHLY) * 12) - $yearlyPrice;
        @endphp

        <p class="lp-meta" style="margin:10px 0 0">
            or ₹{{ number_format($yearlyPrice, 0) }} a year
            @if ($saved > 0)
                <span class="lp-save">save ₹{{ number_format($saved, 0) }}</span>
            @endif
            {{-- Which to pay is settled on the next page, beside the button
                 that actually commits to it. --}}
        </p>

        @if ($chosen->trial_days > 0)
            <p class="lp-plan-trial">
                {{ $chosen->trial_days }} days free first. Nothing is charged until then.
            </p>
        @endif

        {{-- The real limits and modules, off the plans table — the same lines
             the pricing section prints. --}}
        <div class="lp-plan-panel-lists">
            <ul class="lp-plan-list">
                @foreach ($chosen->limitLines() as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>

            <ul class="lp-plan-list lp-plan-mods">
                @foreach ($chosen->moduleKeys() as $key)
                    <li>{{ $modules[$key]['label'] ?? $key }}</li>
                @endforeach
            </ul>
        </div>

        <a class="lp-btn lp-btn-solid lp-signup-submit"
           href="{{ route('signup.details', ['plan' => $chosen->slug]) }}">
            Continue with {{ $chosen->name }}
        </a>
    </div>

    <p class="lp-meta lp-signup-note">
        Not sure? <a href="{{ route('landing') }}#pricing">Compare the plans side by side</a>,
        or <a href="{{ route('landing') }}#demo">book a demo</a> and we will walk you through it.
    </p>
@endsection
