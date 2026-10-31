{{--
    The product's own landing page.

    Not a restaurant's website — this is the page a restaurant owner reads
    before signing up. It answers four questions in the order they get asked:
    what is it, what can it do, what else do you do for me, what does it cost.

    The feature grid is the real module catalogue and the prices are the real
    `plans` rows, so neither can drift from the software. See LandingController.

    No JavaScript is required for anything to be readable: the FAQ list is
    <details> and the pricing is plain markup.
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

    @include('site._seo', [
        'title' => $site['meta_title'],
        'description' => $site['meta_description'] ?: 'Restaurant POS for billing, QR table ordering, kitchen tickets, GST invoices, inventory and reports. Start a free trial with '.$site['name'].'.',
        'site' => $site,
    ])

    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('site._favicon')

    @php $landingCss = public_path('assets/css/landing.css'); @endphp
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ file_exists($landingCss) ? filemtime($landingCss) : 1 }}">
    {{-- Toasts and field-error states for the demo form; literal colours, no admin theme. --}}
    <link rel="stylesheet"
          href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
</head>
<body class="lp">

{{-- ------------------------------------------------------------- strip -- --}}
@if ($strip)
    <div class="lp-strip">
        @if ($strip->url)
            <a href="{{ $strip->url }}">{{ $strip->text }}</a>
        @else
            <span>{{ $strip->text }}</span>
        @endif
    </div>
@endif

{{-- ------------------------------------------------------------ header -- --}}
@include('site._header', [
    'actions' => [['Sign in', route('login'), 'ghost'], ['See pricing', route('pricing'), 'solid']],
])

<main>
    {{-- --------------------------------------------------------- hero -- --}}
    {{--
        What this product is, in the first screen.

        The page used to open straight onto the slider, and a slider is
        whatever a marketer last uploaded - so a visitor's first impression
        of a restaurant POS was a stock photograph and a "Shop now" button
        that belonged to somebody else's campaign. A product page has to say
        what it sells before it shows anything.

        Fixed copy, not content-managed, and that is the point: this is the
        one claim on the page that must be true on the day nobody has logged
        into the admin yet. Everything underneath - banners, screenshots,
        plans, testimonials - is the restaurant's own content and renders
        only when it exists.
    --}}
    {{--
        The background, when Super Admin has given it one.

        Slides from Content > Sliders under "Landing Hero Background", each a
        <picture> so a phone downloads the portrait crop rather than the wide
        one. They stack and crossfade (landing-hero.js, loaded only for two or
        more); with none, the hero keeps its plain warm wash and dark text.
    --}}
    <section class="lp-hero {{ $backdrops->isNotEmpty() ? 'has-photo' : '' }}">
        @if ($backdrops->isNotEmpty())
            <div class="lp-hero-bg" aria-hidden="true" data-hero-slides>
                @foreach ($backdrops as $bg)
                    <picture class="lp-hero-slide {{ $loop->first ? 'is-on' : '' }}">
                        <source media="(max-width: 720px)" srcset="{{ $bg['mobile'] }}">
                        <img src="{{ $bg['desktop'] }}" alt=""
                             @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </picture>
                @endforeach
            </div>
        @endif

        <div class="lp-wrap lp-hero-inner">
            <div class="lp-hero-copy">
                <p class="lp-hero-eyebrow">Restaurant POS &amp; QR ordering</p>

                {{--
                    The product's own sentence, and deliberately not editable.

                    `meta_title_home` holds the company name on a real install,
                    which is right for a browser tab and useless as a headline -
                    so the two are kept apart and only the subheading below reads
                    from settings. See LandingPageTest.
                --}}
                <h1>Run the whole restaurant from one screen</h1>

                <p class="lp-hero-text">
                    {{ $site['meta_description']
                        ?: 'Billing, table orders, KOTs, stock and day close — one system for the
                            floor, the kitchen and the office, with GST invoices and a kitchen
                            display that runs on the wifi you already have.' }}
                </p>

                <div class="lp-hero-cta">
                    <a class="lp-btn lp-btn-solid lp-btn-lg" href="{{ route('register') }}">Start free trial</a>
                    <a class="lp-btn lp-btn-ghost lp-btn-lg" href="{{ route('landing.how') }}">See how it works</a>
                </div>

                {{-- The product's flow in four words, linking to the section that tells it properly. --}}
                <ol class="lp-hero-flow" aria-label="How a table runs">
                    <li><a href="#how"><x-icon name="scan" :size="15" /> Scan QR</a></li>
                    <li><a href="#how"><x-icon name="cart" :size="15" /> Order</a></li>
                    <li><a href="#how"><x-icon name="zap" :size="15" /> Kitchen</a></li>
                    <li><a href="#how"><x-icon name="file" :size="15" /> Bill</a></li>
                </ol>
            </div>

            <div class="lp-hero-media">
                <img src="{{ $images['hero'] }}" width="720" height="520"
                     alt="The billing dashboard on a laptop beside a guest's phone showing the table menu"
                     fetchpriority="high">
            </div>

            {{--
                No trust-number row here.

                Trust Numbers (§19) are still a module - the rows, the admin
                screen and LandingStat::display(), which counts a live figure
                rather than printing a typed one, are all untouched. What is
                deliberate is that they are not in the hero.

                A counted figure is honest and that is exactly the problem on
                a young install: "1 Outlet running on it" is true, and it is
                the worst possible sentence to put under the headline. A proof
                row has to clear a bar before it proves anything, and nothing
                here can know where that bar is.

                They are not loaded either - see LandingController, which
                says how to bring them back and what shape the content test
                expects them in.
            --}}
        </div>
    </section>

    {{-- -------------------------------------------------- highlights -- --}}
    <section class="lp-highlights" aria-label="Highlights">
        <div class="lp-wrap lp-highlights-row">
            <p><x-icon name="scan" :size="20" /> <span><strong>QR table ordering</strong> No app for guests</span></p>
            <p><x-icon name="zap" :size="20" /> <span><strong>Live kitchen tickets</strong> With sound alerts</span></p>
            <p><x-icon name="file" :size="20" /> <span><strong>GST invoices</strong> Cash, UPI and card</span></p>
            <p><x-icon name="chart" :size="20" /> <span><strong>Stock &amp; reports</strong> Tally export</span></p>
        </div>
    </section>

    {{-- -------------------------------------------------- how it works -- --}}
    {{--
        The product's flow, first: it is the thing a restaurant owner
        cannot picture from a feature list. Each picture is the Settings >
        Landing Page upload for that step, else the built-in illustration.
    --}}
    <section class="lp-section" id="how">
        <div class="lp-wrap">
            <header class="lp-head lp-head-center">
                <p class="lp-eyebrow">How it works</p>
                <h2>How a table runs</h2>
                <p>Scan the QR on the table — and here is everything that happens next, without a waiter running back and forth.</p>
            </header>

            <ol class="lp-steps">
                @foreach ($steps as $step)
                    <li class="lp-step">
                        <img class="lp-step-img" src="{{ $images[$step['image']] }}" alt="" width="400" height="280"
                             loading="lazy">
                        <div class="lp-step-body">
                            <span class="lp-step-n">Step {{ $step['n'] }}</span>
                            <h3>{{ $step['title'] }}</h3>
                            <p>{{ $step['body'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ----------------------------------------------------- features -- --}}
    <section class="lp-section lp-section-alt" id="features">
        <div class="lp-wrap">
            <header class="lp-head lp-head-center">
                <p class="lp-eyebrow">Features</p>
                <h2>Everything the floor, the kitchen and the office need</h2>
                <p>
                    Each of these is a module you can switch on per outlet — and it is the
                    same list your plan grants, not a brochure.
                </p>
            </header>

            <div class="lp-grid lp-grid-3">
                @foreach ($modules as $module)
                    <article class="lp-feature">
                        <span class="lp-feature-icon" aria-hidden="true">
                            <x-icon :name="$module['icon']" :size="20" />
                        </span>
                        <h3>{{ $module['label'] }}</h3>
                        <p>{{ $module['blurb'] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- -------------------------------------------------- product tour -- --}}
    {{--
        Three close-ups, picture and copy alternating sides. The pictures are
        Settings > Landing Page uploads (the POS screen, the billing machine,
        the reports screen), else the built-in illustrations - so the section
        is always whole. Every line of copy is something the software does.
    --}}
    <section class="lp-section" id="tour">
        <div class="lp-wrap lp-tour">
            <article class="lp-spot">
                <div class="lp-spot-media">
                    <img src="{{ $images['pos'] }}" alt="The counter billing screen" width="640" height="400" loading="lazy">
                </div>
                <div class="lp-spot-copy">
                    <p class="lp-eyebrow">Counter billing</p>
                    <h2>Bill a table in a few taps</h2>
                    <ul class="lp-ticks">
                        <li>Dine-in, takeaway and delivery from the same screen</li>
                        <li>Barcode scanning and keyboard shortcuts for a busy counter</li>
                        <li>Cash, UPI or card — or split the payment across them</li>
                        <li>Returns and credit notes against the original invoice</li>
                    </ul>
                </div>
            </article>

            <article class="lp-spot lp-spot-flip" id="billing">
                <div class="lp-spot-media">
                    <img src="{{ $images['billing'] }}" alt="A thermal billing printer with a printed GST tax invoice"
                         width="400" height="280" loading="lazy">
                </div>
                <div class="lp-spot-copy">
                    <p class="lp-eyebrow">GST invoices</p>
                    <h2>A proper tax invoice on every bill</h2>
                    <ul class="lp-ticks">
                        <li>CGST and SGST worked out on every line</li>
                        <li>Prints on the thermal billing printer at your counter</li>
                        <li>Your logo, GSTIN and address on the bill</li>
                        <li>An emailed receipt for guests who leave an address</li>
                    </ul>
                </div>
            </article>

            <article class="lp-spot">
                <div class="lp-spot-media">
                    <img src="{{ $images['reports'] }}" alt="The owner's reports screen" width="640" height="400" loading="lazy">
                </div>
                <div class="lp-spot-copy">
                    <p class="lp-eyebrow">For the owner</p>
                    <h2>Know the day's numbers before you lock up</h2>
                    <ul class="lp-ticks">
                        <li>Sales, top dishes and payment-mode totals</li>
                        <li>Stock that moves with every sale</li>
                        <li>Export to Tally for your accountant</li>
                        <li>A live alert, with sound, the moment an order arrives</li>
                    </ul>
                </div>
            </article>
        </div>
    </section>

    {{-- -------------------------------------------------- screenshots -- --}}
    @if ($showcases->isNotEmpty())
        <section class="lp-section lp-section-alt" id="screens">
            <div class="lp-wrap">
                <header class="lp-head lp-head-center">
                    <h2>What it looks like</h2>
                    <p>The screens your staff will actually spend the evening in.</p>
                </header>

                <div class="lp-shots">
                    @foreach ($showcases as $shot)
                        <figure class="lp-shot">
                            <img src="{{ $shot->imageUrl() }}" alt="{{ $shot->title }}" loading="lazy">
                            <figcaption>
                                <strong>{{ $shot->title }}</strong>
                                @if ($shot->caption)
                                    <span>{{ $shot->caption }}</span>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------- outlet types -- --}}
    @if ($outletTypes->isNotEmpty())
        <section class="lp-section" id="who">
            <div class="lp-wrap">
                <header class="lp-head lp-head-center">
                    <h2>Built for how you actually trade</h2>
                    <p>One system, set up differently for each of these.</p>
                </header>

                <div class="lp-types">
                    @foreach ($outletTypes as $type)
                        <article class="lp-type">
                            {{-- iconName() falls back to a known key, so an
                                 icon renamed out of the set cannot reach the
                                 component that echoes its markup unescaped. --}}
                            <x-icon :name="$type->iconName()" :size="22" />
                            <h3>{{ $type->name }}</h3>
                            @if ($type->blurb)
                                <p>{{ $type->blurb }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ----------------------------------------------------- services -- --}}
    @if ($services->isNotEmpty())
        <section class="lp-section" id="services">
            <div class="lp-wrap">
                <header class="lp-head lp-head-center">
                    <h2>What we do for you</h2>
                    <p>Setup, training and the things that are not software.</p>
                </header>

                <div class="lp-grid lp-grid-3">
                    @foreach ($services as $service)
                        <article class="lp-card">
                            @if ($service->imageUrl())
                                <img class="lp-card-img" src="{{ $service->imageUrl() }}"
                                     alt="{{ $service->name }}" loading="lazy">
                            @endif

                            <div class="lp-card-body">
                                {{-- No picture uploaded: the row's icon, when it is one the set has. --}}
                                @if (! $service->imageUrl() && array_key_exists((string) $service->icon, config('icons', [])))
                                    <span class="lp-feature-icon" aria-hidden="true">
                                        <x-icon :name="$service->icon" :size="20" />
                                    </span>
                                @endif
                                <h3>{{ $service->name }}</h3>
                                @if ($service->short_description)
                                    <p>{{ $service->short_description }}</p>
                                @endif
                                @if ($service->price !== null)
                                    <p class="lp-price">
                                        {{-- "from" is a flag on the row, not a guess. --}}
                                        @if ($service->price_from) from @endif
                                        ₹{{ number_format((float) $service->price, 0) }}
                                    </p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------ pricing -- --}}
    @if ($plans->isNotEmpty())
        <section class="lp-section lp-section-alt" id="pricing">
            <div class="lp-wrap">
                <header class="lp-head lp-head-center">
                    <h2>Plans</h2>
                    <p>Each plan covers the outlets shown, billed monthly or yearly. {{ App\Support\PlanAccess::gstLine() }} Change or cancel whenever.</p>
                </header>

                <div class="lp-plans">
                    @foreach ($plans as $row)
                        @php $plan = $row['plan']; @endphp

                        <article class="lp-plan @if ($row['featured']) is-featured @endif">
                            @if ($row['featured'])
                                <span class="lp-plan-flag">Most popular</span>
                            @endif

                            <h3>{{ $plan->name }}</h3>

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
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------- testimonials -- --}}
    @if ($testimonials->isNotEmpty())
        <section class="lp-section" id="said">
            <div class="lp-wrap">
                <header class="lp-head lp-head-center">
                    <h2>What restaurants say</h2>
                </header>

                <div class="lp-grid lp-grid-3">
                    @foreach ($testimonials as $t)
                        <figure class="lp-quote">
                            @if ($t->hasRating())
                                {{-- Drawn only when there is one. Printing
                                     "5/5" against every quote is the fastest
                                     way to make all of them look invented. --}}
                                <div class="lp-stars" aria-label="{{ $t->rating }} out of 5">
                                    <span aria-hidden="true">{{ str_repeat('★', $t->rating) }}</span><span
                                        class="lp-stars-off" aria-hidden="true">{{ str_repeat('★', 5 - $t->rating) }}</span>
                                </div>
                            @endif

                            <blockquote>{{ $t->quote }}</blockquote>

                            <figcaption>
                                @if ($t->imageUrl())
                                    <img src="{{ $t->imageUrl() }}" alt="" loading="lazy">
                                @else
                                    {{-- Most arrive without a photograph, so
                                         initials are the normal case. --}}
                                    <span class="lp-avatar" aria-hidden="true">{{ $t->initials() }}</span>
                                @endif

                                <span>
                                    <strong>{{ $t->author_name }}</strong>
                                    @if ($t->attribution())
                                        <span class="lp-meta">{{ $t->attribution() }}</span>
                                    @endif
                                </span>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- -------------------------------------------------- integrations -- --}}
    @if ($integrations->isNotEmpty())
        <section class="lp-section lp-section-alt" id="integrations">
            <div class="lp-wrap">
                <header class="lp-head lp-head-center">
                    <h2>Works with what you already use</h2>
                    <p>Payments, delivery, accounting and the hardware on your counter.</p>
                </header>

                <div class="lp-logos">
                    @foreach ($integrations as $i)
                        @php $tag = $i->url ? 'a' : 'div'; @endphp

                        <{{ $tag }} class="lp-logo"
                            @if ($i->url) href="{{ $i->url }}" rel="noopener" target="_blank" @endif
                            title="{{ $i->category ? $i->name.' — '.$i->category : $i->name }}">
                            @if ($i->imageUrl())
                                <img src="{{ $i->imageUrl() }}" alt="{{ $i->name }}" loading="lazy">
                            @else
                                {{-- No logo is not a reason to drop it: the
                                     name still says what it works with. --}}
                                <span class="lp-logo-word">{{ $i->name }}</span>
                            @endif
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ---------------------------------------------------------- faq -- --}}
    @if ($faqs->isNotEmpty())
        <section class="lp-section" id="faq">
            <div class="lp-wrap lp-narrow">
                <header class="lp-head lp-head-center"><h2>Asked often</h2></header>

                {{-- <details>, so it opens and closes with no JavaScript. --}}
                @foreach ($faqs as $faq)
                    <details class="lp-faq">
                        <summary>{{ $faq->question }}</summary>
                        <div class="lp-faq-body">{{ $faq->answer }}</div>
                    </details>
                @endforeach
            </div>
        </section>
    @endif

    {{-- --------------------------------------------------- book a demo -- --}}
    <section class="lp-section lp-section-alt" id="demo">
        <div class="lp-wrap lp-demo">
            <div class="lp-demo-copy">
                <h2>{{ $site['contact_heading'] ?: 'See it on your own menu' }}</h2>

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
                {{--
                    With JavaScript: demo-form.js checks each field as it is
                    left, and app.js posts without a reload - errors on the
                    fields, the thank-you as a toast and below. Without it, the
                    same form as a plain POST and a redirect back, so a lead is
                    never lost to a script. See DemoRequestController.
                --}}
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

{{-- ------------------------------------------------------------ footer -- --}}
@include('site._footer')

{{--
    Two scripts, both for the demo form, and the page reads perfectly without
    either: app.js posts the form without a reload and shows the toasts;
    demo-form.js checks the fields before anything is sent.

    assets/js/landing.js is the old banner carousel's and stays unloaded:
    with the banner gone there is nothing for it to drive.
--}}
<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
<script src="{{ asset('assets/js/demo-form.js') }}?v={{ filemtime(public_path('assets/js/demo-form.js')) }}"></script>
@if ($backdrops->count() > 1)
    {{-- Crossfades the hero's background slides; one slide needs no script. --}}
    <script src="{{ asset('assets/js/landing-hero.js') }}?v={{ filemtime(public_path('assets/js/landing-hero.js')) }}"></script>
@endif

<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
