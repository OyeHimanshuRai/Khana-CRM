<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
@if (! empty($keywords))
    <meta name="keywords" content="{{ $keywords }}">
@endif
@php
    // The home page's address always ends in a slash, the same as the sitemap says.
    $canonical = url()->current();
    if (parse_url($canonical, PHP_URL_PATH) === null) { $canonical .= '/'; }
@endphp
<link rel="canonical" href="{{ $canonical }}">
@php
    $googleVerify = \App\Models\Setting::get('google_site_verification');
    $bingVerify = \App\Models\Setting::get('bing_site_verification');
@endphp
@if (filled($googleVerify))
    <meta name="google-site-verification" content="{{ $googleVerify }}">
@endif
@if (filled($bingVerify))
    <meta name="msvalidate.01" content="{{ $bingVerify }}">
@endif
@if (! empty($schema))
    <script type="application/ld+json">{!! $schema !!}</script>
@endif
@if (! empty($robots))
    <meta name="robots" content="{{ $robots }}">
@endif

<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $site['name'] }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
@if (! empty($site['logo']))
    <meta property="og:image" content="{{ $site['logo'] }}">
@endif
<meta name="twitter:card" content="{{ empty($site['logo']) ? 'summary' : 'summary_large_image' }}">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
      referrerpolicy="no-referrer">
