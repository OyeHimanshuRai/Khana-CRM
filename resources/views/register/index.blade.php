{{--
    Self-service sign-up.

    One wide card on a three-column grid (two on a tablet, one on a phone):
    plan, billing and business name; the address; the location; then
    the person and the terms. Plan and billing stay real <select>s - the
    billing one is dressed as a Monthly/Yearly switch by register.js.

    With JavaScript: register.js checks every field as it is filled in, and
    app.js posts the form without a reload - server errors land on the field
    that caused them and the result is a toast. Without it, a plain POST that
    renders the same errors server-side. Both hit the same validation in
    App\Http\Controllers\RegisterController.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('site._seo', [
        'title' => 'Start Your Free Trial · '.$site['name'],
        'description' => 'Start a free trial of '.$site['name'].' restaurant POS and set up billing, QR ordering, kitchen and reports.',
        'robots' => 'noindex,nofollow',
        'site' => $site,
    ])

    @include('site._favicon')
    <link rel="stylesheet" href="{{ asset('assets/css/ui.css') }}?v={{ filemtime(public_path('assets/css/ui.css')) }}">
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ filemtime(public_path('assets/css/landing.css')) }}">
    {{-- Toasts and the field-error states; colours fall back to literals,
         so it needs nothing from the admin theme. --}}
    <link rel="stylesheet"
          href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
    <link rel="stylesheet"
          href="{{ asset('assets/css/register.css') }}?v={{ filemtime(public_path('assets/css/register.css')) }}">
</head>
<body class="lp">

@include('site._header', ['actions' => [['Pricing', route('pricing'), 'ghost'], ['Sign in', route('login'), 'solid']]])

@php
    $chosenPlan = old('plan', $selected->slug);
    $chosenPeriod = old('period', $period);
    $chosen = $plans->firstWhere('slug', $chosenPlan);

    // The one short line under the plan box: the trial, or failing that the blurb's first sentence.
    // The whole description is kept as the tooltip.
    $shortNote = function ($plan): string {
        if ((int) $plan->trial_days > 0) {
            return (int) $plan->trial_days.'-day free trial';
        }

        return \Illuminate\Support\Str::limit(trim(strtok((string) $plan->blurb, '.')), 44);
    };
    $blurbOf = $chosen ? $shortNote($chosen) : '';
    $blurbTitle = $chosen ? trim(((int) $chosen->trial_days > 0 ? (int) $chosen->trial_days.'-day free trial. ' : '').$chosen->blurb) : '';
@endphp

<main class="rg-page">
    <div class="rg-card">
        <header class="rg-head">
            @if (! empty($site['logo']))
                <a href="{{ route('landing') }}" class="rg-logo" aria-label="{{ $site['name'] }}">
                    <img src="{{ $site['logo'] }}" alt="{{ $site['name'] }}">
                </a>
            @endif
            <h1>Create your RestroOne account</h1>
            <p class="lp-meta">Start your free trial. No card needed.</p>
        </header>

        @if ($errors->any())
            <ul class="rg-summary" role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('register.store') }}" class="rg-form"
              data-ajax data-register novalidate data-redirect-delay="1200">
            @csrf

            {{-- Honeypot: hidden from people, irresistible to bots. --}}
            <div class="lp-hp" aria-hidden="true">
                <label for="r-website">Website</label>
                <input id="r-website" type="text" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="rg-grid">
                {{-- The plan, as a dropdown: each option carries its price and
                     trial, and the chosen one's blurb is shown under it. --}}
                <p class="lp-field field">
                    {{-- The chosen plan's one line sits beside the label, so it adds no row of its own. --}}
                    <span class="rg-label-row">
                        <label for="r-plan">Plan</label>
                        <span class="rg-hint" data-plan-blurb title="{{ $blurbTitle }}" @if (! $blurbOf) hidden @endif>{{ $blurbOf }}</span>
                    </span>
                    <select id="r-plan" name="plan" required data-plan-select
                            aria-invalid="{{ $errors->has('plan') ? 'true' : 'false' }}">
                        @foreach ($plans as $p)
                            @php
                                // Short enough not to be cut off in the box; the trial
                                // and the description go in the line under it.
                                $optionText = $p->name.' — ₹'.number_format($p->priceFor('monthly'), 0).'/mo';
                                $optionNote = $shortNote($p);
                                $optionFull = trim(((int) $p->trial_days > 0 ? (int) $p->trial_days.'-day free trial. ' : '').$p->blurb);
                            @endphp
                            <option value="{{ $p->slug }}" @selected($chosenPlan === $p->slug) data-blurb="{{ $optionNote }}" data-full="{{ $optionFull }}">{{ $optionText }}</option>
                        @endforeach
                    </select>
                    @error('plan')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-period">Billing after trial</label>
                    <select id="r-period" name="period" required data-period-select>
                        <option value="monthly" @selected($chosenPeriod === 'monthly')>Monthly</option>
                        <option value="yearly" @selected($chosenPeriod === 'yearly')>Yearly (cheaper per month)</option>
                    </select>
                </p>

                <p class="lp-field field rg-field-business">
                    {{-- Takes the height of the captions over Plan and Billing, so the three boxes start on one line. --}}
                    <span class="rg-label-spacer" aria-hidden="true">&nbsp;</span>
                    <label for="r-business" class="rg-sr">Business name</label>
                    <input id="r-business" type="text" name="business_name" required maxlength="150" placeholder="Business name *"
                           value="{{ old('business_name') }}" autocomplete="organization"
                           aria-invalid="{{ $errors->has('business_name') ? 'true' : 'false' }}">
                    @error('business_name')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field rg-field-address">
                    <label for="r-address1" class="rg-sr">Business address</label>
                    <input id="r-address1" type="text" name="address_line1" maxlength="190"
                           value="{{ old('address_line1') }}" autocomplete="address-line1"
                           placeholder="Business address">
                    @error('address_line1')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field rg-field-wide">
                    <label for="r-address2" class="rg-sr">Address line 2</label>
                    <input id="r-address2" type="text" name="address_line2" maxlength="190"
                           value="{{ old('address_line2') }}" autocomplete="address-line2"
                           placeholder="Address line 2 / landmark">
                    @error('address_line2')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                {{-- Country > State > City: each list is loaded from the database when the one before it
                     changes (register.js, /geo/...). Without JavaScript the first two are plain lists. --}}
                @php($defaultCountry = old('country_id') ?: ($countries->firstWhere('iso_code', 'IN')?->id))
                <p class="lp-field field">
                    <label for="r-country" class="rg-sr">Country</label>
                    <select id="r-country" name="country_id" required data-geo-country
                            data-states-url="{{ url('geo/countries/__ID__/states') }}"
                            aria-invalid="{{ $errors->has('country_id') ? 'true' : 'false' }}">
                        @foreach ($countries as $country)
                            <option value="{{ $country->id }}" data-iso="{{ $country->iso_code }}" data-code="{{ $country->phone_code }}"
                                    data-min="{{ $country->min_length }}" data-max="{{ $country->max_length }}"
                                    @selected((int) $defaultCountry === $country->id)>{{ $country->country_name }}</option>
                        @endforeach
                    </select>
                    @error('country_id')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-state" class="rg-sr">State</label>
                    <select id="r-state" name="state_id" data-geo-state data-cities-url="{{ url('geo/states/__ID__/cities') }}"
                            data-old="{{ old('state_id') }}">
                        <option value="">State</option>
                    </select>
                    @error('state_id')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-city" class="rg-sr">City</label>
                    <select id="r-city" name="city_id" data-geo-city data-old="{{ old('city_id') }}" disabled>
                        <option value="">City</option>
                    </select>
                    @error('city_id')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field rg-field-pin">
                    <label for="r-pincode" class="rg-sr">PIN code</label>
                    <input id="r-pincode" type="text" name="pincode" maxlength="12" placeholder="PIN code"
                           value="{{ old('pincode') }}" autocomplete="postal-code" inputmode="numeric">
                    @error('pincode')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <div class="lp-field field rg-location">
                    <label for="r-location">Current location</label>
                    <div class="rg-location-controls">
                        <input id="r-location" type="text" readonly
                               value="{{ old('latitude') !== null && old('longitude') !== null ? old('latitude').', '.old('longitude') : '' }}"
                               placeholder="Location not detected" aria-describedby="r-location-hint">
                        {{-- An icon, not words: the label stays for screen readers and as the tooltip. --}}
                        <button class="lp-btn lp-btn-ghost rg-location-button" type="button" data-get-location
                                title="Use my location" aria-label="Use my location">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <circle cx="12" cy="12" r="3.2"/>
                                <circle cx="12" cy="12" r="7.5"/>
                                <path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3"/>
                            </svg>
                        </button>
                    </div>
                    <input type="hidden" name="latitude" value="{{ old('latitude') }}" data-location-latitude>
                    <input type="hidden" name="longitude" value="{{ old('longitude') }}" data-location-longitude>
                    {{-- Empty until the button is used; then it says what happened. --}}
                    <span class="rg-hint" id="r-location-hint" data-location-status role="status" hidden></span>
                    @error('latitude')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                    @error('longitude')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </div>

                <p class="lp-field field">
                    <label for="r-name" class="rg-sr">Your name</label>
                    <input id="r-name" type="text" name="name" required maxlength="120" placeholder="Your name *"
                           value="{{ old('name') }}" autocomplete="name"
                           aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                    @error('name')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-email" class="rg-sr">Email</label>
                    <input id="r-email" type="email" name="email" required maxlength="150" placeholder="Email address *"
                           value="{{ old('email') }}" autocomplete="email" inputmode="email"
                           aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
                    @error('email')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                {{-- The email is confirmed with a one-time code before the Register button appears
                     (register.js; the server refuses an unconfirmed address as well). --}}
                <div class="lp-field field rg-otp" data-otp
                     data-send-url="{{ route('register.otp.send') }}" data-verify-url="{{ route('register.otp.verify') }}"
                     data-verified-email="{{ session(\App\Support\SignupOtp::SESSION_KEY) }}">
                    <button type="button" class="lp-btn lp-btn-ghost rg-otp-send" data-otp-send>Send OTP to this email</button>

                    <div class="rg-otp-panel" data-otp-panel hidden>
                        <label for="r-otp" class="rg-sr">6-digit code</label>
                        <div class="rg-otp-row">
                            <input id="r-otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                   pattern="[0-9]{6}" placeholder="Enter 6-digit OTP" data-otp-input>
                            <button type="button" class="lp-btn lp-btn-solid" data-otp-verify>Verify</button>
                        </div>
                        <button type="button" class="rg-otp-resend" data-otp-resend disabled>Resend OTP</button>
                    </div>

                    <p class="rg-otp-msg" role="status" data-otp-msg hidden></p>
                </div>

                {{-- The country's calling code is shown beside the box and changes with the Country list;
                     the number is checked against that country's real numbering plan (libphonenumber)
                     in the browser, and again by the server. --}}
                <p class="lp-field field">
                    <label for="r-mobile" class="rg-sr">Mobile no</label>
                    <span class="rg-phone">
                        <span class="rg-prefix" aria-hidden="true" data-phone-prefix>
                            <svg class="rg-flag" data-phone-flag width="20" height="14" viewBox="0 0 20 14" focusable="false">
                                <rect width="20" height="4.67" fill="#ff9933"/>
                                <rect y="4.67" width="20" height="4.67" fill="#fff"/>
                                <rect y="9.33" width="20" height="4.67" fill="#138808"/>
                                <circle cx="10" cy="7" r="1.8" fill="none" stroke="#000080" stroke-width=".6"/>
                            </svg>
                            <span class="rg-iso" data-phone-iso hidden></span>
                            <span data-phone-code>+91</span>
                            <svg class="rg-prefix-caret" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M2 3.5 5 6.5 8 3.5"/></svg>
                        </span>
                        {{-- The code is a list too: picking a country here picks it in the Country list above. --}}
                        <select class="rg-code-select" data-phone-select aria-label="Country calling code" tabindex="0">
                            @foreach ($countries as $country)
                                <option value="{{ $country->id }}" @selected((int) $defaultCountry === $country->id)>{{ $country->country_name }} (+{{ $country->phone_code }})</option>
                            @endforeach
                        </select>
                        <input id="r-mobile" type="tel" name="mobile" required maxlength="20" placeholder="Mobile no *"
                               value="{{ old('mobile') }}" autocomplete="tel-national" inputmode="tel"
                               aria-invalid="{{ $errors->has('mobile') ? 'true' : 'false' }}">
                    </span>
                    @error('mobile')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-password" class="rg-sr">Password</label>
                    <span class="rg-password">
                        <input id="r-password" type="password" name="password" required minlength="8"
                               autocomplete="new-password" placeholder="Password * (min 8)"
                               aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
                        @include('register._eye', ['for' => 'r-password'])
                    </span>
                    @error('password')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <div class="rg-terms-cell">
                    <label class="rg-terms">
                        <input type="checkbox" name="terms" value="1" @checked(old('terms')) required>
                        <span>
                            I agree to the
                            @if ($terms)<a href="{{ route('page.show', $terms->slug) }}" target="_blank" rel="noopener">{{ $terms->title }}</a>@else<span>terms of use</span>@endif
                            and
                            @if ($privacy)<a href="{{ route('page.show', $privacy->slug) }}" target="_blank" rel="noopener">{{ $privacy->title }}</a>@else<span>privacy policy</span>@endif.
                        </span>
                    </label>
                    @error('terms')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </div>
            </div>

            <p class="rg-gate" data-register-gate>Verify your email with the OTP above to continue.</p>
            <button type="submit" class="lp-btn lp-btn-solid lp-btn-lg rg-submit" data-register-submit hidden>
                <span>Register</span>
            </button>
        </form>

        <p class="rg-foot">Already have an account? <a href="{{ route('login') }}">Log in</a></p>
    </div>
</main>

@include('site._footer')

<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
<script src="{{ asset('assets/vendor/libphonenumber-js.max.js') }}?v={{ filemtime(public_path('assets/vendor/libphonenumber-js.max.js')) }}"></script>
<script src="{{ asset('assets/js/register.js') }}?v={{ filemtime(public_path('assets/js/register.js')) }}"></script>
<script src="{{ asset('assets/js/info.js') }}?v={{ filemtime(public_path('assets/js/info.js')) }}"></script>
<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
