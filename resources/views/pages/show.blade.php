<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">

    @include('site._seo', [
        'title' => $page->title.' · '.$site['name'],
        'description' => $metaDescription,
        'site' => $site,
    ])

    @include('site._favicon')
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ filemtime(public_path('assets/css/landing.css')) }}">
    <style>
        .lp-page { max-width: 760px; margin: 0 auto; padding: 40px 16px 64px; line-height: 1.7; }
        .lp-page h1 { margin: 0 0 6px; }
        .lp-page h2, .lp-page h3 { margin-top: 1.6em; }
        .lp-page .lp-meta { margin-bottom: 24px; }
        .lp-page img { max-width: 100%; height: auto; }
        .lp-page table { border-collapse: collapse; max-width: 100%; display: block; overflow-x: auto; }
        .lp-page th, .lp-page td { border: 1px solid rgba(128, 128, 128, .35); padding: 6px 10px; }
    </style>
</head>
<body class="lp">

@include('site._header', ['actions' => [['Home', route('landing'), 'ghost'], ['Sign in', route('login'), 'solid']]])

<main>
    <article class="lp-page">
        <h1>{{ $page->title }}</h1>
        <p class="lp-meta">Last updated {{ $page->updated_at?->format('j F Y') }}</p>

        {!! $page->html() !!}
    </article>
</main>

@include('site._footer')

<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
