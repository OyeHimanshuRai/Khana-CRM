{{--
    Book a demo, on a page of its own (it was the landing page's #demo section).

    With JavaScript: demo-form.js checks each field as it is left, and app.js
    posts without a reload - errors on the fields, the thank-you as a toast and
    above the form. Without it, the same form as a plain POST and a redirect
    back here, so a lead is never lost to a script. See DemoRequestController.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('site._seo', [
        'title' => 'Book a Restaurant POS Demo · '.$site['name'],
        'description' => $site['contact_description'] ?: 'See '.$site['name'].' on your own menu. Tell us about your restaurant and we will set up a trial for you.',
        'site' => $site,
    ])

    @include('site._favicon')
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ filemtime(public_path('assets/css/landing.css')) }}">
    {{-- Toasts and field-error states for the form; literal colours, no admin theme. --}}
    <link rel="stylesheet"
          href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
</head>
<body class="lp">

@include('site._header', [
    'actions' => [['Sign in', route('login'), 'ghost'], ['Start free trial', route('register'), 'solid']],
])

<main>
    <section class="lp-section lp-section-alt lp-demo-page">
        <div class="lp-wrap lp-demo">
            <div class="lp-demo-copy">
                <h1 class="lp-page-title">{{ $site['contact_heading'] ?: 'See it on your own menu' }}</h1>

                <p>
                    {{ $site['contact_description']
                        ?: 'Tell us how many outlets you run and we will set up a trial with your menu already in it.' }}
                </p>

                <div class="lp-demo-contacts">
                    @if ($site['phone'])
                        <a href="tel:{{ preg_replace('/\s+/', '', $site['phone']) }}">
                            <x-icon name="phone" :size="16" /> {{ $site['phone'] }}
                        </a>
                    @endif

                    @if ($site['whatsapp'])
                        <a href="https://wa.me/{{ preg_replace('/\D+/', '', $site['whatsapp']) }}"
                           rel="noopener" target="_blank">
                            <x-icon name="zap" :size="16" /> WhatsApp
                        </a>
                    @endif

                    @if ($site['email'])
                        <a href="mailto:{{ $site['email'] }}">
                            <x-icon name="mail" :size="16" /> {{ $site['email'] }}
                        </a>
                    @endif
                </div>

                @if ($site['address'])
                    <p class="lp-meta">{{ $site['address'] }}</p>
                @endif
            </div>

            <div class="lp-demo-form">
                <p class="lp-demo-thanks" role="status" data-demo-thanks
                   @unless (session('demo_status')) hidden @endunless>{{ session('demo_status') }}</p>

                @if ($errors->any())
                    <ul class="lp-demo-errors" role="alert">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ route('demo-request.store') }}"
                      data-ajax data-demo-form data-reset-on-success novalidate>
                    @csrf

                    {{--
                        The honeypot. Hidden from people, irresistible to bots,
                        and free — unlike a CAPTCHA, which would cost this form
                        real enquiries to stop a problem somebody reads past in
                        an inbox anyway.
                    --}}
                    <div class="lp-hp" aria-hidden="true">
                        <label for="d-website">Website</label>
                        <input id="d-website" type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="lp-demo-grid">
                        <p class="lp-field lp-field-full field">
                            <label for="d-name">Your name</label>
                            <input id="d-name" type="text" name="name" required maxlength="120"
                                   value="{{ old('name') }}" autocomplete="name">
                        </p>

                        <p class="lp-field field">
                            <label for="d-phone">Phone</label>
                            <input id="d-phone" type="tel" name="phone" maxlength="30"
                                   value="{{ old('phone') }}" autocomplete="tel">
                        </p>

                        <p class="lp-field field">
                            <label for="d-email">Email</label>
                            <input id="d-email" type="email" name="email" maxlength="150"
                                   value="{{ old('email') }}" autocomplete="email">
                        </p>

                        <p class="lp-field field">
                            <label for="d-business">Restaurant name</label>
                            <input id="d-business" type="text" name="business_name" maxlength="150"
                                   value="{{ old('business_name') }}" autocomplete="organization">
                        </p>

                        <p class="lp-field field">
                            <label for="d-city">City</label>
                            <input id="d-city" type="text" name="city" maxlength="90"
                                   value="{{ old('city') }}" autocomplete="address-level2">
                        </p>

                        <p class="lp-field lp-field-full field">
                            <label for="d-outlets">How many outlets</label>
                            <input id="d-outlets" type="number" name="outlets" min="1" max="65535"
                                   value="{{ old('outlets') }}" placeholder="1">
                        </p>

                        <p class="lp-field lp-field-full field">
                            <label for="d-message">Anything else</label>
                            <textarea id="d-message" name="message" rows="3" maxlength="2000">{{ old('message') }}</textarea>
                        </p>
                    </div>

                    {{-- Either will do — insisting on an email loses the owner
                         who only has a phone, and that is the likelier buyer. --}}
                    <p class="lp-demo-note">Give us a phone number or an email — whichever you prefer.</p>

                    <button type="submit" class="lp-btn lp-btn-solid lp-btn-lg">Book a demo</button>
                </form>
            </div>
        </div>
    </section>
</main>

@include('site._footer')

<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
<script src="{{ asset('assets/js/demo-form.js') }}?v={{ filemtime(public_path('assets/js/demo-form.js')) }}"></script>
<script src="{{ asset('assets/js/info.js') }}?v={{ filemtime(public_path('assets/js/info.js')) }}"></script>
<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
