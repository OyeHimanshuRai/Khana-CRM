<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ url()->current() }}">
@if (! empty($robots))
    <meta name="robots" content="{{ $robots }}">
@endif

<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $site['name'] }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ url()->current() }}">
@if (! empty($site['logo']))
    <meta property="og:image" content="{{ $site['logo'] }}">
@endif
<meta name="twitter:card" content="{{ empty($site['logo']) ? 'summary' : 'summary_large_image' }}">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
      referrerpolicy="no-referrer">
