{{--
    Pricing, on a page of its own (it was the landing page's #pricing section).

    The cards are the real `plans` rows - see App\Support\PricingPlans - so a
    price changed under Settings > Plans is changed here.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    {{--
        Speculation rules: the pages a visitor is about to choose between are
        prepared while the pointer is on the way (hover for a moment, or the press
        itself), so Sign up, Pricing and Sign in open instantly. Public pages with
        nothing that writes on load, which is what makes prerender safe here. A
        browser without the API ignores the block.
    --}}
    @php
        $specBase = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
        $specRules = ['prerender' => [[
            'where' => ['or' => [
                ['href_matches' => $specBase.'/register'],
                ['href_matches' => $specBase.'/pricing'],
                ['href_matches' => $specBase.'/login'],
                ['href_matches' => $specBase.'/demo'],
            ]],
            'eagerness' => 'moderate',
        ]]];
    @endphp
    <script type="speculationrules">@json($specRules, JSON_UNESCAPED_SLASHES)</script>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('site._seo', [
        'title' => 'Restaurant POS Pricing · '.$site['name'],
        'description' => 'Compare simple per-outlet restaurant POS plans from '.$site['name'].'. Start with a free trial; GST extra.',
        'site' => $site,
    ])

    @include('site._favicon')
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ filemtime(public_path('assets/css/landing.css')) }}">
</head>
<body class="lp">

@include('site._header', [
    'actions' => [['Sign in', route('login'), 'ghost'], ['Start free trial', route('register'), 'solid']],
])

<main>
    <section class="lp-section lp-pricing-page" id="plans">
        <div class="lp-wrap">
            <header class="lp-head lp-head-center">
                <p class="lp-eyebrow">Pricing</p>
                <h1 class="lp-page-title">Plans</h1>
                <p>Each plan covers the outlets shown, billed monthly or yearly. {{ App\Support\PlanAccess::gstLine() }} Change or cancel whenever.</p>
            </header>

            <div class="lp-plans">
                @foreach ($plans as $row)
                    @php $plan = $row['plan']; @endphp

                    <article class="lp-plan @if ($row['featured']) is-featured @endif">
                        @if ($row['featured'])
                            <span class="lp-plan-flag">Most popular</span>
                        @endif

                        <h2>{{ $plan->name }}</h2>

                        @if ($plan->blurb)
                            <p class="lp-plan-blurb">{{ $plan->blurb }}</p>
                        @endif

                        <p class="lp-plan-price">
                            <span class="lp-plan-amount">₹{{ number_format($row['monthly'], 0) }}</span>
                            <span class="lp-meta">/ month</span>
                        </p>

                        <p class="lp-meta lp-plan-yearly">
                            or ₹{{ number_format($row['yearly'], 0) }} a year
                            @if ($row['months_free'])
                                — {{ $row['months_free'] }} {{ Str::plural('month', $row['months_free']) }} free
                            @endif
                        </p>

                        @if ($plan->trial_days > 0)
                            <p class="lp-plan-trial">{{ $plan->trial_days }}-day free trial</p>
                        @endif

                        <a class="lp-btn {{ $row['featured'] ? 'lp-btn-solid' : 'lp-btn-ghost' }} lp-plan-cta"
                           href="{{ route('register', ['plan' => $plan->slug]) }}">Get started</a>

                        {{-- Limits first: they are what people compare. --}}
                        <ul class="lp-plan-list">
                            @foreach ($row['limits'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>

                        <p class="lp-plan-modhead">Modules included</p>
                        <ul class="lp-plan-list lp-plan-mods">
                            @foreach ($row['modules'] as $label)
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>

            <p class="lp-pricing-foot">
                Not sure which plan fits? <a href="{{ route('demo') }}">Book a demo</a> and we will set one up with your menu in it.
            </p>
        </div>
    </section>
</main>

@include('site._footer')
<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
