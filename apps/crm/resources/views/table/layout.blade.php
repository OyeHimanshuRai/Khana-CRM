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
    Only the payment checkout stacks anything here, and it is a provider's
    script that cannot be anything else.
--}}
@stack('scripts')

    {{--
        Deferred, and both files are tiny on purpose. The guest's journey
        works with none of this: every form on it is a real form with a real
        action, and table-forms.js only upgrades the ones marked data-ajax to
        post without a reload. A page that cannot be used until a bundle
        arrives is a page that fails on restaurant wifi.
    --}}
    <script defer src="{{ asset('assets/js/table-forms.js') }}?v={{ filemtime(public_path('assets/js/table-forms.js')) }}"></script>
    <script src="{{ asset('assets/js/table-pwa.js') }}?v={{ filemtime(public_path('assets/js/table-pwa.js')) }}"></script>
</body>
</html>
