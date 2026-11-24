{{--
    Contact support, on a page of its own (it was the landing page's #demo section).

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
        'title' => 'Contact Support · '.$site['name'],
        'description' => $site['contact_description'] ?: 'Questions about '.$site['name'].' - setup, plans or a problem? Write to our support team and we will call you back in 5 minutes.',
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
                <h1 class="lp-page-title">{{ $site['contact_heading'] ?: 'Contact our support team' }}</h1>

                <p>
                    {{ $site['contact_description']
                        ?: 'Tell us what you need - setup, plans or a problem - and our support team will call you back in 5 minutes.' }}
                </p>

                <p class="lp-support-badge"><x-icon name="phone" :size="16" /> We call you back in 5 minutes</p>

                @if ($site['phone'])
                    <a class="lp-call-card" href="tel:{{ preg_replace('/\s+/', '', $site['phone']) }}">
                        <span class="lp-call-label">Call our support team</span>
                        <span class="lp-call-number">{{ $site['phone'] }}</span>
                        <span class="lp-call-note">Tap to call · or leave your details and we will call you</span>
                    </a>
                @endif

                <div class="lp-demo-contacts">
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

    {{-- ------------------------------------------------ how a request goes -- --}}
    <section class="lp-section">
        <div class="lp-wrap">
            <header class="lp-head lp-head-center">
                <p class="lp-eyebrow">How it works</p>
                <h2>From your message to a solved problem, in minutes</h2>
                <p>You should never wait days to hear back. This is what happens after you send the form or call us.</p>
            </header>

            <div class="lp-grid lp-grid-3">
                <article class="lp-feature">
                    <span class="lp-step-n">Step 1</span>
                    <h3>Tell us what you need</h3>
                    <p>Fill in the short form above - your name, your email and how we can help - or simply pick up the phone and call us. Your email is confirmed with a 6-digit code so the reply reaches you and nobody else.</p>
                </article>
                <article class="lp-feature">
                    <span class="lp-step-n">Step 2</span>
                    <h3>We call you back in 5 minutes</h3>
                    <p>A member of our support team calls the number you gave, usually within five minutes. Not a recorded message and not a ticket number: a person who knows the software and has your request in front of them.</p>
                </article>
                <article class="lp-feature">
                    <span class="lp-step-n">Step 3</span>
                    <h3>We fix it, and write it down</h3>
                    <p>We walk you through the answer, or set it up for you while you are on the line. You also get a confirmation email with a copy of your message, and a follow-up by email if anything is left to do.</p>
                </article>
            </div>
        </div>
    </section>

    {{-- ------------------------------------------------------ what we help with -- --}}
    <section class="lp-section lp-section-alt">
        <div class="lp-wrap lp-support-two">
            <div>
                <p class="lp-eyebrow">What we help with</p>
                <h2 class="lp-support-h2">One team for everything in your restaurant software</h2>
                <p class="lp-support-lede">Whether you are about to sign up or already serving guests, the same support team picks up. Here is what people usually call us for.</p>
            </div>

            <ul class="lp-ticks">
                <li><strong>Getting started</strong> - choosing a plan, creating your account, and loading your menu, tables and staff.</li>
                <li><strong>Menu import</strong> - turning your Excel or CSV menu into dishes, categories and prices, without re-typing.</li>
                <li><strong>Billing and GST</strong> - invoices with your GSTIN, CGST and SGST on every line, split payments, returns and credit notes.</li>
                <li><strong>QR ordering and the floor</strong> - table QR codes, guest orders, reservations and held bills.</li>
                <li><strong>Kitchen display and printers</strong> - kitchen stations, sound alerts, and connecting your thermal printer.</li>
                <li><strong>Stock and purchasing</strong> - recipes, wastage, suppliers, purchase orders and low-stock alerts.</li>
                <li><strong>Reports and your accountant</strong> - daily sales, GST reports and the Tally export.</li>
                <li><strong>Your account</strong> - sign-in problems, staff roles, plan changes, invoices and renewals.</li>
            </ul>
        </div>
    </section>

    {{-- ------------------------------------------------------ keep ready -- --}}
    <section class="lp-section">
        <div class="lp-wrap">
            <header class="lp-head lp-head-center">
                <p class="lp-eyebrow">Before you call</p>
                <h2>Keep these ready, and we will be quicker</h2>
            </header>

            <div class="lp-grid lp-grid-3">
                <article class="lp-feature">
                    <h3>Your restaurant</h3>
                    <p>The name of your restaurant and the email address you signed up with, so we can find your account straight away.</p>
                </article>
                <article class="lp-feature">
                    <h3>What is happening</h3>
                    <p>What you were doing and what you saw on the screen. A photo of the error, or the exact message, helps a great deal.</p>
                </article>
                <article class="lp-feature">
                    <h3>Your device</h3>
                    <p>Whether you are on the counter PC, a tablet or a phone, and which printer or scanner is connected, if it is about hardware.</p>
                </article>
            </div>

            @if ($site['phone'])
                <div class="lp-support-final">
                    <h3>Prefer to talk? Call us now.</h3>
                    <a class="lp-btn lp-btn-solid lp-btn-lg" href="tel:{{ preg_replace('/\s+/', '', $site['phone']) }}">
                        <x-icon name="phone" :size="18" /> {{ $site['phone'] }}
                    </a>
                    @if ($site['email'])
                        <p class="lp-meta">Or write to <a href="mailto:{{ $site['email'] }}">{{ $site['email'] }}</a> and we will reply by email.</p>
                    @endif
                </div>
            @endif
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
