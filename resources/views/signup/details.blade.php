@extends('signup.layout')

@section('title', 'Your details')
@section('step', '2')

@section('content')
    <header class="lp-head">
        <h2>Tell us about your restaurant</h2>
        <p>
            Two minutes, and you are in. Your account opens on this device —
            there is no email link to go and find.
        </p>
    </header>

    <form method="POST" action="{{ route('signup.store') }}" class="lp-signup"
          data-ajax data-busy="Creating your shop…">
        @csrf

        {{--
            The honeypot. Hidden from people, irresistible to bots, and free —
            unlike a CAPTCHA, which would cost real signups to stop something
            the rate limit already handles.
        --}}
        <div class="lp-hp" aria-hidden="true">
            <label for="s-website">Website</label>
            <input id="s-website" type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        {{-- The plan was settled on step one and travels with the form. --}}
        <input type="hidden" name="plan" value="{{ $chosen->slug }}">

        <div class="lp-signup-main">
            @if ($errors->any())
                <ul class="lp-demo-errors" role="alert">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            {{-- --------------------------------------------- the plan -- --}}
            @php $yearly = old('period', $period) === \App\Models\Plan::YEARLY; @endphp

            <div class="lp-signup-block lp-chosen">
                <div class="lp-chosen-head">
                    <div>
                        <span class="lp-meta">Your plan</span>
                        <strong>{{ $chosen->name }}</strong>
                    </div>

                    {{-- Back to step one, keeping the two pages honest: the
                         plan can be changed right up to the moment it is
                         bought, and changing it is one click. --}}
                    <a class="lp-chosen-change" href="{{ route('signup', ['plan' => $chosen->slug]) }}">Change</a>
                </div>

                <div class="lp-signup-period" role="radiogroup" aria-label="How to pay">
                    <label class="@unless ($yearly) is-on @endunless">
                        <input type="radio" name="period" value="{{ \App\Models\Plan::MONTHLY }}"
                               @unless ($yearly) checked @endunless>
                        Monthly — ₹{{ number_format($chosen->priceFor(\App\Models\Plan::MONTHLY), 0) }}
                    </label>

                    <label class="@if ($yearly) is-on @endif">
                        <input type="radio" name="period" value="{{ \App\Models\Plan::YEARLY }}"
                               @if ($yearly) checked @endif>
                        Yearly — ₹{{ number_format($chosen->priceFor(\App\Models\Plan::YEARLY), 0) }}
                        @php
                            $saved = ($chosen->priceFor(\App\Models\Plan::MONTHLY) * 12)
                                - $chosen->priceFor(\App\Models\Plan::YEARLY);
                        @endphp
                        @if ($saved > 0)
                            <span class="lp-save">save ₹{{ number_format($saved, 0) }}</span>
                        @endif
                    </label>
                </div>

                @if ($chosen->trial_days > 0)
                    <p class="lp-plan-trial">
                        {{ $chosen->trial_days }} days free first — nothing is charged today.
                    </p>
                @endif
            </div>

            {{-- ----------------------------------------- the restaurant -- --}}
            <fieldset class="lp-signup-block">
                <legend class="lp-signup-legend">Your restaurant</legend>

                <div class="lp-demo-grid">
                    <p class="lp-field lp-field-full">
                        <label for="s-business">Business name</label>
                        <input id="s-business" type="text" name="business_name" required maxlength="150"
                               value="{{ old('business_name') }}" autocomplete="organization"
                               placeholder="Sharma Restaurants">
                    </p>

                    <p class="lp-field">
                        <label for="s-outlet">Outlet name <span class="lp-meta">(optional)</span></label>
                        <input id="s-outlet" type="text" name="outlet_name" maxlength="150"
                               value="{{ old('outlet_name') }}"
                               placeholder="Same as the business">
                    </p>

                    <p class="lp-field">
                        <label for="s-city">City</label>
                        <input id="s-city" type="text" name="city" maxlength="90"
                               value="{{ old('city') }}" autocomplete="address-level2"
                               placeholder="Jaipur">
                    </p>
                </div>

                <p class="lp-meta lp-signup-note">
                    One outlet is created now. Add your other branches from Settings once you are in —
                    as many as your plan allows.
                </p>
            </fieldset>

            {{-- -------------------------------------------- the sign-in -- --}}
            <fieldset class="lp-signup-block">
                <legend class="lp-signup-legend">Your sign-in</legend>

                <div class="lp-demo-grid">
                    <p class="lp-field">
                        <label for="s-name">Your name</label>
                        <input id="s-name" type="text" name="name" required maxlength="120"
                               value="{{ old('name') }}" autocomplete="name">
                    </p>

                    <p class="lp-field">
                        <label for="s-mobile">Mobile</label>
                        <input id="s-mobile" type="tel" name="mobile" maxlength="30"
                               value="{{ old('mobile') }}" autocomplete="tel">
                    </p>

                    <p class="lp-field lp-field-full">
                        <label for="s-email">Email</label>
                        <input id="s-email" type="email" name="email" required maxlength="150"
                               value="{{ old('email') }}" autocomplete="email">
                    </p>

                    <p class="lp-field">
                        <label for="s-password">Password</label>
                        <input id="s-password" type="password" name="password" required
                               autocomplete="new-password" minlength="8">
                    </p>

                    <p class="lp-field">
                        <label for="s-password2">Repeat password</label>
                        <input id="s-password2" type="password" name="password_confirmation" required
                               autocomplete="new-password" minlength="8">
                    </p>
                </div>

                <p class="lp-signup-terms">
                    <label>
                        <input type="checkbox" name="terms" value="1" @checked(old('terms'))>
                        I agree to the terms of service and the privacy policy.
                    </label>
                </p>

                <button type="submit" class="lp-btn lp-btn-solid lp-signup-submit">
                    Create my shop
                </button>
            </fieldset>
        </div>

        {{-- ---------------------------------------------------- aside -- --}}
        <aside class="lp-signup-side">
            <h3>What happens next</h3>

            <ol class="lp-signup-steps">
                <li><strong>Your account opens straight away.</strong> You are signed in on this device — no email link to go and find.</li>
                <li><strong>Add your menu.</strong> Categories, dishes and prices, or import a list you already have.</li>
                <li><strong>Start billing.</strong> The counter, the kitchen screen and the table QR all work from the same menu.</li>
            </ol>

            <h3>About paying</h3>

            <p class="lp-meta">
                @if ($chosen->trial_days > 0)
                    Nothing is charged for the first {{ $chosen->trial_days }} days. You can pay from the
                    Billing screen whenever you like, and the term starts from the day you pay.
                @else
                    This plan has no trial, so your billing screen opens first — the outlet unlocks the
                    moment the payment clears.
                @endif
            </p>

            <p class="lp-meta">
                Prices are per outlet. GST extra. Change or cancel whenever.
            </p>
        </aside>
    </form>
@endsection
