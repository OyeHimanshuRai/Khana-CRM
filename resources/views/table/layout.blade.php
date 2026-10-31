<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{--
        No user-scalable=no. A guest reading a menu in a dim room will pinch
        to zoom, and taking that away to make the page feel "app-like" is the
        single most common accessibility mistake in this kind of screen.
    --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- A table page is for the person at the table, not for a search index. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f97316">

    <title>@yield('title', 'Order') · {{ $company }}</title>

    {{-- For the one page that posts: paying. Everything else on a guest's
         journey is an ordinary form with its own hidden token. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="stylesheet"
          href="{{ asset('assets/css/table.css') }}?v={{ filemtime(public_path('assets/css/table.css')) }}">

    {{--
        Installable (§14).

        The manifest is generated per outlet so the home-screen icon carries
        the restaurant's own name - see TableManifestController.

        The service worker caches stylesheets and icons and nothing else. A
        cached menu would show a dish that sold out an hour ago, which undoes
        the instant sold-out toggle §8 asks for by name. See public/sw.js.
    --}}
    <link rel="manifest" href="{{ route('table.manifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ ($shop ?? null)?->name ?: $company }}">
    <meta name="sw-url" content="{{ asset('sw.js') }}">
</head>
<body class="t-body">

<main class="t-shell">
    @yield('content')
</main>

{{--
    Nothing is stacked here on a normal page. The guest's journey runs without
    JavaScript on purpose - see section 15 of the overview - and the one
    exception is the payment checkout, which is a provider's script and cannot
    be anything else.
--}}
@stack('scripts')

    <script src="{{ asset('assets/js/table-pwa.js') }}?v={{ filemtime(public_path('assets/js/table-pwa.js')) }}"></script>
</body>
</html>
