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
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $site['meta_title'] }}</title>

    @if ($site['meta_description'])
        <meta name="description" content="{{ $site['meta_description'] }}">
    @endif

    {{--
        The address search engines should index, and the one a share should
        carry. Without it, `/?utm_source=...` and `/` are two pages to a
        crawler and the ranking is split between them.
    --}}
    <link rel="canonical" href="{{ route('landing') }}">

    <meta property="og:title" content="{{ $site['meta_title'] }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('landing') }}">
    <meta property="og:site_name" content="{{ $site['name'] }}">
    <meta property="og:locale" content="en_IN">
    @if ($site['meta_description'])
        <meta property="og:description" content="{{ $site['meta_description'] }}">
    @endif

    {{--
        The picture a link shows in WhatsApp, which is where a restaurant
        owner is actually sent this URL. The logo when there is one, and
        nothing at all otherwise: an og:image pointing at a missing file
        renders as a broken box in the preview, which is worse than a
        preview with no picture in it.
    --}}
    @if ($site['logo'])
        <meta property="og:image" content="{{ $site['logo'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $site['logo'] }}">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <meta name="twitter:title" content="{{ $site['meta_title'] }}">
    @if ($site['meta_description'])
        <meta name="twitter:description" content="{{ $site['meta_description'] }}">
    @endif

    <link rel="icon" href="{{ asset('favicon.ico') }}">

    @php $landingCss = public_path('assets/css/landing.css'); @endphp
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ file_exists($landingCss) ? filemtime($landingCss) : 1 }}">

    {{--
        What a search engine reads rather than inferring from the markup: the
        business, the product with its real prices, and the questions from the
        FAQ section below. Built in LandingController::structuredData() off the
        same rows this page draws, so it can never advertise a price that is
        not on the page.
    --}}
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
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
<header class="lp-header">
    <div class="lp-wrap lp-header-row">
        <a href="{{ route('landing') }}" class="lp-brand">
            @if ($site['logo'])
                <img src="{{ $site['logo'] }}" alt="{{ $site['name'] }}" height="34">
            @else
                <span class="lp-brand-text">{{ $site['name'] }}</span>
            @endif
        </a>

        {{-- Anchors only: every destination is a section of this page. --}}
        <nav class="lp-nav" aria-label="Primary">
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
            @if ($outletTypes->isNotEmpty()) <a href="#who">Who it is for</a> @endif
            @if ($plans->isNotEmpty()) <a href="#pricing">Pricing</a> @endif
            @if ($faqs->isNotEmpty()) <a href="#faq">FAQ</a> @endif
            <a href="#demo">Book a demo</a>
        </nav>

        <div class="lp-header-cta">
            <a class="lp-btn lp-btn-ghost" href="{{ route('admin.login') }}">Sign in</a>
            {{-- The one button that opens an account. It points at the form
                 rather than at the price list, because somebody who scrolled
                 to the header to click something has already decided. --}}
            <a class="lp-btn lp-btn-solid" href="{{ route('signup') }}">Open an account</a>
        </div>

        {{--
            The small-screen menu.

            A <details> rather than a button and a script: the nav above is
            hidden below 860px, and until now that left a phone with no way
            to reach any section at all. Built this way it opens and closes
            with no JavaScript on the page, which is the rule the rest of
            this document already follows.
        --}}
        <details class="lp-menu">
            <summary aria-label="Menu">
                <span class="lp-menu-bars" aria-hidden="true"></span>
            </summary>

            <nav class="lp-menu-panel" aria-label="Sections">
                <a href="#features">Features</a>
                <a href="#how">How it works</a>
                @if ($outletTypes->isNotEmpty()) <a href="#who">Who it is for</a> @endif
                @if ($plans->isNotEmpty()) <a href="#pricing">Pricing</a> @endif
                @if ($faqs->isNotEmpty()) <a href="#faq">FAQ</a> @endif
                <a href="#demo">Book a demo</a>
                <a href="{{ route('admin.login') }}">Sign in</a>
                <a class="lp-menu-account" href="{{ route('signup') }}">Open an account <span aria-hidden="true">→</span></a>
            </nav>
        </details>
    </div>
</header>

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
    <section class="lp-hero">
        <div class="lp-wrap lp-hero-inner">
            <div class="lp-hero-copy">
            <p class="lp-hero-eyebrow"><span class="lp-live-dot"></span> Restaurant POS &amp; back office</p>

            {{--
                The product's own sentence, and deliberately not editable.

                `meta_title_home` holds the company name on a real install,
                which is right for a browser tab and useless as a headline -
                so the two are kept apart and only the subheading below reads
                from settings. See LandingPageTest.
            --}}
            <h1>Run your entire restaurant from <em>one powerful system</em></h1>

            <p class="lp-hero-text">
                {{ $site['meta_description']
                    ?: 'Billing, table orders, KOTs, stock and day close — one system for the
                        floor, the kitchen and the office, with GST invoices and a kitchen
                        display that runs on the wifi you already have.' }}
            </p>

            {{--
                Two ways in, and the self-serve one leads.

                A demo is the right answer for a group weighing up five
                outlets; it is a week of waiting for the owner of one, who
                could be billing tonight. So the form comes first and the
                call stays offered beside it.
            --}}
            <div class="lp-hero-cta">
                <a class="lp-btn lp-btn-solid lp-btn-lg" href="{{ route('signup') }}">Start free trial <span aria-hidden="true">→</span></a>
                <a class="lp-btn lp-btn-ghost lp-btn-lg" href="#demo">Book a free demo</a>
            </div>

            <div class="lp-hero-proof"><span class="lp-proof-avatars"><i>R</i><i>A</i><i>V</i></span><span><strong>Built for busy restaurants</strong><br>Setup in days, not weeks</span></div>

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

            @if ($showcases->isNotEmpty())
            <div class="lp-product-stage" aria-label="Dine Flow product preview">
                <img src="{{ $showcases->first()->imageUrl() }}" alt="{{ $showcases->first()->title }}" loading="eager">
                <span class="lp-stage-chip lp-stage-chip-sales">Live POS billing</span>
                <span class="lp-stage-chip lp-stage-chip-kitchen">Kitchen + KOT</span>
                <span class="lp-stage-caption"><strong>{{ $showcases->first()->title }}</strong><small>Built for every service, from first order to final receipt</small></span>
            </div>
            @else
            <div class="lp-dashboard lp-dashboard-hero" aria-label="Dine Flow restaurant dashboard preview">
                <div class="lp-dash-top"><strong><span class="lp-dash-mark">D</span> Dine Flow</strong><span class="lp-dash-date">Today, 24 Jun 2026 <b>⌄</b></span></div>
                <div class="lp-dash-body">
                    <aside class="lp-dash-side"><span class="is-active">▦</span><span>▥</span><span>◫</span><span>◉</span><span>⌁</span></aside>
                    <div class="lp-dash-main">
                        <div class="lp-dash-heading"><div><small>Good morning, Arjun</small><h3>Here's your daily overview</h3></div><span class="lp-dash-user">AS</span></div>
                        <div class="lp-dash-metrics"><div><small>Today's revenue</small><strong>₹84,520</strong><em>↗ 18.4%</em></div><div><small>Total orders</small><strong>126</strong><em>↗ 12.8%</em></div><div><small>Tables active</small><strong>18 <small>/ 24</small></strong><em class="is-warm">● Live now</em></div></div>
                        <div class="lp-dash-grid"><div class="lp-dash-chart"><div class="lp-dash-label"><strong>Revenue overview</strong><span>This week ⌄</span></div><div class="lp-chart-bars"><i style="height:42%"></i><i style="height:57%"></i><i style="height:48%"></i><i style="height:72%"></i><i style="height:62%"></i><i style="height:88%"></i><i class="is-today" style="height:78%"></i></div><div class="lp-chart-days"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div></div><div class="lp-dash-kot"><div class="lp-dash-label"><strong>Kitchen orders</strong><span class="lp-kot-count">12 pending</span></div><p><b><span class="lp-order-dot is-red"></span>#1048</b><span>Butter chicken, Naan</span><strong>08:24</strong></p><p><b><span class="lp-order-dot is-yellow"></span>#1047</b><span>Masala dosa, Lassi</span><strong>06:12</strong></p><p><b><span class="lp-order-dot is-green"></span>#1046</b><span>Paneer tikka, Rice</span><strong>04:48</strong></p></div></div>
                    </div>
                </div>
                <span class="lp-dash-float lp-dash-float-one"><b>₹84,520</b><small>Today's sales <i>↗ 18.4%</i></small></span><span class="lp-dash-float lp-dash-float-two"><b>12</b><small>KOT pending</small></span>
            </div>
            @endif
        </div>
    </section>

    {{--
        No banner carousel here.

        The Sliders module is untouched - the admin screen, the uploads, the
        main_banner layout and landing.js all still exist. What this page no
        longer does is draw them.

        To put it back: render $slides as a .lp-banner section and load
        landing.js when the collection is not empty. The script writes its own
        dots and arrows into [data-lp-ui], so nothing has to be drawn for it.
    --}}

    {{-- ----------------------------------------------------- features -- --}}
    <section class="lp-section" id="features">
        <div class="lp-wrap">
            <header class="lp-head">
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

    {{-- -------------------------------------------------- screenshots -- --}}
    @if ($showcases->isNotEmpty())
        <section class="lp-section lp-section-alt" id="screens">
            <div class="lp-wrap">
                <header class="lp-head">
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
                <header class="lp-head">
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

    {{-- -------------------------------------------------- how it works -- --}}
    <section class="lp-section lp-section-alt" id="how">
        <div class="lp-wrap">
            <header class="lp-head">
                <h2>How a table runs</h2>
                <p>From the sticker on the table to the settled bill.</p>
            </header>

            <ol class="lp-steps">
                @foreach ($steps as $step)
                    <li class="lp-step">
                        <span class="lp-step-n">{{ $step['n'] }}</span>
                        <h3>{{ $step['title'] }}</h3>
                        <p>{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ----------------------------------------------------- services -- --}}
    @if ($services->isNotEmpty())
        <section class="lp-section" id="services">
            <div class="lp-wrap">
                <header class="lp-head">
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
                <header class="lp-head">
                    <h2>Plans</h2>
                    <p>Per outlet, billed monthly or yearly. GST extra. Change or cancel whenever.</p>
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

                            {{--
                                Straight to the form with this plan already
                                chosen, rather than to the enquiry box below.
                                The card is where somebody decides; asking
                                them to type the plan's name into a message
                                and wait for a call back is where that
                                decision used to be lost. See
                                App\Http\Controllers\SignupController.
                            --}}
                            <a class="lp-btn {{ $row['featured'] ? 'lp-btn-solid' : 'lp-btn-ghost' }} lp-plan-cta"
                               href="{{ route('signup', ['plan' => $plan->slug]) }}">
                                {{ $plan->trial_days > 0 ? 'Start free trial' : 'Get started' }}
                            </a>

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
                <header class="lp-head">
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
                <header class="lp-head">
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
                <header class="lp-head"><h2>Asked often</h2></header>

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
                    A plain POST and a redirect back — no JavaScript. The
                    landing page loads no script at all, and a lead form that
                    silently needs one is a lead form that silently loses
                    leads. See App\Http\Controllers\DemoRequestController.
                --}}
                @if (session('demo_status'))
                    <p class="lp-demo-thanks" role="status">{{ session('demo_status') }}</p>
                @endif

                @if ($errors->any())
                    <ul class="lp-demo-errors" role="alert">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ route('demo-request.store') }}"
                      data-ajax data-busy="Sending…">
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
                        <p class="lp-field lp-field-full">
                            <label for="d-name">Your name</label>
                            <input id="d-name" type="text" name="name" required maxlength="120"
                                   value="{{ old('name') }}" autocomplete="name">
                        </p>

                        <p class="lp-field">
                            <label for="d-phone">Phone</label>
                            <input id="d-phone" type="tel" name="phone" maxlength="30"
                                   value="{{ old('phone') }}" autocomplete="tel">
                        </p>

                        <p class="lp-field">
                            <label for="d-email">Email</label>
                            <input id="d-email" type="email" name="email" maxlength="150"
                                   value="{{ old('email') }}" autocomplete="email">
                        </p>

                        <p class="lp-field">
                            <label for="d-business">Restaurant name</label>
                            <input id="d-business" type="text" name="business_name" maxlength="150"
                                   value="{{ old('business_name') }}" autocomplete="organization">
                        </p>

                        <p class="lp-field">
                            <label for="d-city">City</label>
                            <input id="d-city" type="text" name="city" maxlength="90"
                                   value="{{ old('city') }}" autocomplete="address-level2">
                        </p>

                        <p class="lp-field lp-field-full">
                            <label for="d-outlets">How many outlets</label>
                            <input id="d-outlets" type="number" name="outlets" min="1" max="65535"
                                   value="{{ old('outlets') }}" placeholder="1">
                        </p>

                        <p class="lp-field lp-field-full">
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
<footer class="lp-footer">
    <div class="lp-wrap lp-footer-row">
        <div>
            <strong>{{ $site['name'] }}</strong>
            @if ($site['tagline'])
                <p class="lp-meta">{{ $site['tagline'] }}</p>
            @endif
        </div>

        @if (! empty($site['social']))
            <nav class="lp-social" aria-label="Social">
                @foreach ($site['social'] as $label => $url)
                    <a href="{{ $url }}" rel="noopener" target="_blank">{{ $label }}</a>
                @endforeach
            </nav>
        @endif

        <p class="lp-meta">&copy; {{ date('Y') }} {{ $site['name'] }}</p>
    </div>
</footer>

{{--
    One script, deferred, and nothing on the page needs it.

    It upgrades the demo form: the same post goes through fetch and the answer
    arrives as a toast, so an enquiry somebody typed is not lost to a reload.
    Blocked, failed or switched off, the browser posts the form itself and the
    visitor sees the flash message exactly as before.

    assets/js/landing.js is still the banner carousel's and is still not
    loaded — the banner is gone, so there is nothing for it to drive.
--}}
@php $publicJs = public_path('assets/js/public-forms.js'); @endphp
<script defer
        src="{{ asset('assets/js/public-forms.js') }}?v={{ file_exists($publicJs) ? filemtime($publicJs) : 1 }}"></script>

</body>
</html>
