{{--
    The chrome both signup steps share.

    Opening an account is two pages now — choose a plan, then fill in the
    details — and they are two real addresses rather than one page that hides
    half of itself. So the header, the footer and the stylesheet live here and
    neither step repeats them.

    The marketing stylesheet, not the admin one: somebody on these pages has
    not bought anything yet and should still be reading the shop window.
--}}
    <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'Open your account') — {{ $site['name'] }}</title>
    <meta name="description" content="Create your restaurant's account, pick a plan and start billing today.">

    {{-- A signup form has no business in a search index; the pricing page it
         came from is the page that should rank. --}}
    <meta name="robots" content="noindex">

    <link rel="icon" href="{{ asset('favicon.ico') }}">

    @php $landingCss = public_path('assets/css/landing.css'); @endphp
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ file_exists($landingCss) ? filemtime($landingCss) : 1 }}">
</head>
<body class="lp">

<header class="lp-header">
    <div class="lp-wrap lp-header-row">
        <a href="{{ route('landing') }}" class="lp-brand">
            @if ($site['logo'])
                <img src="{{ $site['logo'] }}" alt="{{ $site['name'] }}" height="34">
            @else
                <span class="lp-brand-text">{{ $site['name'] }}</span>
            @endif
        </a>

        <div class="lp-header-cta">
            <span class="lp-meta lp-signup-have">Already have an account?</span>
            <a class="lp-btn lp-btn-ghost" href="{{ route('admin.login') }}">Sign in</a>
        </div>
    </div>
</header>

<main class="lp-section">
    <div class="lp-wrap">
        {{--
            Where they are, in two words.

            Not decoration: the form below asks for a password, and somebody
            who cannot see that this is step two of two is somebody deciding
            whether to trust an endless form.
        --}}
        <ol class="lp-steps" aria-label="Progress">
            <li class="@if (View::getSection('step', '1') === '1') is-on @else is-done @endif">
                <span>1</span> Choose a plan
            </li>
            <li class="@if (View::getSection('step', '1') === '2') is-on @endif">
                <span>2</span> Your details
            </li>
        </ol>

        @yield('content')
    </div>
</main>

<footer class="lp-footer">
    <div class="lp-wrap">
        <p class="lp-meta">
            © {{ now()->year }} {{ $site['name'] }} ·
            <a href="{{ route('landing') }}">Back to the site</a>
        </p>
    </div>
</footer>

{{--
    Progressive enhancement, loaded last and deferred: the form on step two
    posts and redirects perfectly well on its own, and this only upgrades the
    answer to a toast. See public/assets/js/public-forms.js.
--}}
@php $publicJs = public_path('assets/js/public-forms.js'); @endphp
<script defer
        src="{{ asset('assets/js/public-forms.js') }}?v={{ file_exists($publicJs) ? filemtime($publicJs) : 1 }}"></script>

</body>
</html>
