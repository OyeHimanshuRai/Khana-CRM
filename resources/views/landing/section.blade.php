@php
    $pages = [
        'how-it-works' => [
            'How it works',
            'How a table runs',
            'How QR table ordering works',
            'See how guests order by QR code, kitchen tickets arrive instantly, and your team settles the table.',
        ],
        'features' => [
            'Features',
            'Everything the floor, the kitchen and the office need',
            'Restaurant POS Features',
            'Explore restaurant billing, QR ordering, kitchen, inventory and reporting features in one POS platform.',
        ],
        'billing' => [
            'Billing',
            'A proper tax invoice on every bill',
            'Restaurant Billing & GST Invoices',
            'Create GST invoices, accept split payments and manage dine-in, takeaway and delivery from one billing screen.',
        ],
        'faq' => [
            'FAQ',
            'Asked often',
            'Restaurant POS FAQs',
            'Answers to common questions about restaurant POS, billing, QR ordering and kitchen management.',
        ],
    ];
    [$eyebrow, $title, $seoTitle, $seoDescription] = $pages[$section];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('site._seo', [
        'title' => $seoTitle.' · '.$site['name'],
        'description' => $seoDescription,
        'site' => $site,
    ])

    @include('site._favicon')
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ filemtime(public_path('assets/css/landing.css')) }}">
</head>
<body class="lp">
@include('site._header', [
    'actions' => [['Sign in', route('login'), 'ghost'], ['Book a demo', route('demo'), 'solid']],
])

<main>
    <section class="lp-section {{ in_array($section, ['features', 'faq'], true) ? 'lp-section-alt' : '' }}">
        <div class="lp-wrap {{ $section === 'faq' ? 'lp-narrow' : '' }}">
            <header class="lp-head lp-head-center">
                <p class="lp-eyebrow">{{ $eyebrow }}</p>
                <h1 class="lp-page-title">{{ $title }}</h1>
                @if ($section === 'how-it-works')
                    <p>Scan the QR on the table — and here is everything that happens next, without a waiter running back and forth.</p>
                @elseif ($section === 'features')
                    <p>Each is a module you can switch on per outlet — the same list your plan grants, not a brochure.</p>
                @elseif ($section === 'billing')
                    <p>Take orders, calculate GST and settle bills from one counter screen.</p>
                @endif
            </header>

            @if ($section === 'how-it-works')
                <ol class="lp-steps">
                    @foreach ($steps as $step)
                        <li class="lp-step">
                            <img class="lp-step-img" src="{{ $images[$step['image']] }}" alt="" width="400" height="280" loading="lazy">
                            <div class="lp-step-body">
                                <span class="lp-step-n">Step {{ $step['n'] }}</span>
                                <h2>{{ $step['title'] }}</h2>
                                <p>{{ $step['body'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @elseif ($section === 'features')
                <div class="lp-grid lp-grid-3">
                    @foreach ($modules as $module)
                        <article class="lp-feature">
                            <span class="lp-feature-icon" aria-hidden="true"><x-icon :name="$module['icon']" :size="20" /></span>
                            <h2>{{ $module['label'] }}</h2>
                            <p>{{ $module['blurb'] }}</p>
                        </article>
                    @endforeach
                </div>
            @elseif ($section === 'billing')
                <article class="lp-spot lp-spot-flip">
                    <div class="lp-spot-media">
                        <img src="{{ $images['billing'] }}" alt="A thermal billing printer with a printed GST tax invoice"
                             width="400" height="280" loading="lazy">
                    </div>
                    <div class="lp-spot-copy">
                        <p class="lp-eyebrow">GST invoices</p>
                        <h2>Every order, accounted for</h2>
                        <ul class="lp-ticks">
                            <li>Dine-in, takeaway and delivery from the same screen</li>
                            <li>CGST and SGST calculated on every line</li>
                            <li>Print invoices with your logo, GSTIN and address</li>
                            <li>Accept cash, UPI or card, including split payments</li>
                            <li>Returns and credit notes linked to the original invoice</li>
                        </ul>
                    </div>
                </article>
            @elseif ($section === 'faq')
                @forelse ($faqs as $faq)
                    <details class="lp-faq">
                        <summary>{{ $faq->question }}</summary>
                        <div class="lp-faq-body">{{ $faq->answer }}</div>
                    </details>
                @empty
                    <p class="lp-meta">Have a question? <a href="{{ route('demo') }}">Book a demo</a> and our team will help.</p>
                @endforelse
            @endif
        </div>
    </section>

    <section class="lp-section lp-section-alt">
        <div class="lp-wrap lp-head lp-head-center">
            <h2>See it on your own menu</h2>
            <p>We can set up a demo with your restaurant's menu.</p>
            <a class="lp-btn lp-btn-solid" href="{{ route('demo') }}">Book a demo</a>
            <a class="lp-btn lp-btn-ghost" href="{{ route('pricing') }}">View pricing</a>
        </div>
    </section>
</main>

@include('site._footer')
<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
