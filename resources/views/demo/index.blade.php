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
    <link rel="stylesheet" href="{{ asset('assets/css/ui.css') }}?v={{ filemtime(public_path('assets/css/ui.css')) }}">
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
                @include('demo._form', ['idp' => 'd'])
            </div>
        </div>
    </section>
</main>

@include('site._footer')

<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
<script src="{{ asset('assets/js/demo-form.js') }}?v={{ filemtime(public_path('assets/js/demo-form.js')) }}"></script>
<script src="{{ asset('assets/js/demo-otp.js') }}?v={{ filemtime(public_path('assets/js/demo-otp.js')) }}"></script>
<script src="{{ asset('assets/js/info.js') }}?v={{ filemtime(public_path('assets/js/info.js')) }}"></script>
<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
