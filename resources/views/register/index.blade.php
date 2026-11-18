{{--
    Self-service sign-up.

    One wide card on a three-column grid (two on a tablet, one on a phone):
    plan, billing and business name; the address; the location and logo; then
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
    $blurbOf = $chosen
        ? trim(((int) $chosen->trial_days > 0 ? (int) $chosen->trial_days.'-day free trial. ' : '').$chosen->blurb)
        : '';
@endphp

<main class="rg-page">
    <div class="rg-card">
        <header class="rg-head">
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

        <form method="POST" action="{{ route('register.store') }}" class="rg-form" enctype="multipart/form-data"
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
                    <label for="r-plan">Plan</label>
                    <select id="r-plan" name="plan" required data-plan-select
                            aria-invalid="{{ $errors->has('plan') ? 'true' : 'false' }}">
                        @foreach ($plans as $p)
                            @php
                                // Short enough not to be cut off in the box; the trial
                                // and the description go in the line under it.
                                $optionText = $p->name.' — ₹'.number_format($p->priceFor('monthly'), 0).'/month';
                                $optionNote = trim(((int) $p->trial_days > 0 ? (int) $p->trial_days.'-day free trial. ' : '').$p->blurb);
                            @endphp
                            <option value="{{ $p->slug }}" @selected($chosenPlan === $p->slug) data-blurb="{{ $optionNote }}">{{ $optionText }}</option>
                        @endforeach
                    </select>
                    <span class="rg-hint" data-plan-blurb @if (! $blurbOf) hidden @endif>{{ $blurbOf }}</span>
                    @error('plan')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-period">Billing after trial</label>
                    <select id="r-period" name="period" required data-period-select>
                        <option value="monthly" @selected($chosenPeriod === 'monthly')>Monthly</option>
                        <option value="yearly" @selected($chosenPeriod === 'yearly')>Yearly (cheaper per month)</option>
                    </select>
                    <span class="rg-hint">GST extra. Cancel anytime.</span>
                </p>

                <p class="lp-field field rg-field-business">
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
                           placeholder="Business address (street, building, area)">
                    @error('address_line1')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-address2" class="rg-sr">Address line 2</label>
                    <input id="r-address2" type="text" name="address_line2" maxlength="190"
                           value="{{ old('address_line2') }}" autocomplete="address-line2"
                           placeholder="Address line 2 / landmark">
                    @error('address_line2')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-city" class="rg-sr">City</label>
                    <input id="r-city" type="text" name="city" maxlength="90" placeholder="City"
                           value="{{ old('city') }}" autocomplete="address-level2">
                    @error('city')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-state" class="rg-sr">State</label>
                    <input id="r-state" type="text" name="state" maxlength="90" placeholder="State"
                           value="{{ old('state') }}" autocomplete="address-level1">
                    @error('state')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <p class="lp-field field">
                    <label for="r-pincode" class="rg-sr">PIN code</label>
                    <input id="r-pincode" type="text" name="pincode" maxlength="12" placeholder="PIN code"
                           value="{{ old('pincode') }}" autocomplete="postal-code" inputmode="numeric">
                    @error('pincode')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                <div class="lp-field field rg-field-wide rg-location">
                    <label for="r-location">Current location</label>
                    <div class="rg-location-controls">
                        <input id="r-location" type="text" readonly
                               value="{{ old('latitude') !== null && old('longitude') !== null ? old('latitude').', '.old('longitude') : '' }}"
                               placeholder="Location not detected" aria-describedby="r-location-hint">
                        <button class="lp-btn lp-btn-ghost rg-location-button" type="button" data-get-location>
                            <span>Use my location</span>
                        </button>
                    </div>
                    <input type="hidden" name="latitude" value="{{ old('latitude') }}" data-location-latitude>
                    <input type="hidden" name="longitude" value="{{ old('longitude') }}" data-location-longitude>
                    <span class="rg-hint" id="r-location-hint" data-location-status role="status">
                        Optional. Saves the business GPS coordinates; the postal address above is what counts.
                    </span>
                    @error('latitude')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                    @error('longitude')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </div>

                <p class="lp-field field rg-field-wide">
                    <label for="r-logo">Business logo <span class="rg-optional">(optional)</span></label>
                    <input id="r-logo" type="file" name="logo" accept="image/jpeg,image/png,image/webp"
                           aria-describedby="r-logo-hint" data-logo-input>
                    <span class="rg-hint" id="r-logo-hint" data-logo-name>JPG, PNG or WebP · up to 2 MB · used on invoices.</span>
                    @error('logo')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

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
                    <span class="rg-hint">You will sign in with this.</span>
                    @error('email')<span class="rg-error" role="alert">{{ $message }}</span>@enderror
                </p>

                {{-- +91 is shown beside the box; a ten-digit number is stored
                     with it (RegisterController), anything else as typed. --}}
                <p class="lp-field field">
                    <label for="r-mobile" class="rg-sr">Mobile no</label>
                    <span class="rg-phone">
                        <span class="rg-prefix" aria-hidden="true">
                            <svg class="rg-flag" width="20" height="14" viewBox="0 0 20 14" focusable="false">
                                <rect width="20" height="4.67" fill="#ff9933"/>
                                <rect y="4.67" width="20" height="4.67" fill="#fff"/>
                                <rect y="9.33" width="20" height="4.67" fill="#138808"/>
                                <circle cx="10" cy="7" r="1.8" fill="none" stroke="#000080" stroke-width=".6"/>
                            </svg>
                            +91
                        </span>
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
                               autocomplete="new-password" placeholder="Password * (8+ characters)"
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

            <button type="submit" class="lp-btn lp-btn-solid lp-btn-lg rg-submit">
                <span>Register</span>
            </button>
        </form>

        <p class="rg-foot">Already have an account? <a href="{{ route('login') }}">Log in</a></p>
    </div>
</main>

@include('site._footer')

<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
<script src="{{ asset('assets/js/register.js') }}?v={{ filemtime(public_path('assets/js/register.js')) }}"></script>
<script src="{{ asset('assets/js/info.js') }}?v={{ filemtime(public_path('assets/js/info.js')) }}"></script>
<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
