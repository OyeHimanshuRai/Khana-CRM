{{--
    /login - the sign-in for every restaurant on the platform: owners and
    their staff, the people who bought a plan.

    Not the platform owner's door: Super Admin signs in at /admin/login, and
    each door refuses the other's accounts - see LoginRequest::authenticate().
    A restaurant's own guests sign in on its storefront, not here.

    With JavaScript, app.js posts without a reload and puts errors on the
    field that caused them; without it, a plain POST and redirect.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('site._seo', [
        'title' => 'Sign in · '.$site['name'],
        'description' => 'Sign in to your '.$site['name'].' restaurant POS to manage billing, tables, kitchen orders and reports.',
        'robots' => 'noindex,nofollow',
        'site' => $site,
    ])

    @include('site._favicon')
    <link rel="stylesheet"
          href="{{ asset('assets/css/landing.css') }}?v={{ filemtime(public_path('assets/css/landing.css')) }}">
    <link rel="stylesheet"
          href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
</head>
<body class="lp">

@include('site._header', ['actions' => [['Start free trial', route('register'), 'solid']]])

<main class="lp-section">
    <div class="lp-wrap lp-auth">
        <div class="lp-auth-card">
            <a class="lp-auth-brand" href="{{ route('landing') }}" aria-label="{{ $site['name'] }} home">
                @if ($site['logo'])
                    <img src="{{ $site['logo'] }}" alt="{{ $site['name'] }}">
                @else
                    <span>{{ $site['name'] }}</span>
                @endif
            </a>
            <p class="lp-eyebrow">For restaurants</p>
            <h1>Sign in to your restaurant</h1>
            <p class="lp-meta">Owners and staff: billing, tables, kitchen and reports.</p>

            @if (session('status'))
                <p class="lp-demo-thanks" role="status">{{ session('status') }}</p>
            @endif

            @if (session('error'))
                <ul class="lp-demo-errors" role="alert"><li>{{ session('error') }}</li></ul>
            @endif

            @if ($errors->any())
                <ul class="lp-demo-errors" role="alert">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('login.store') }}" data-ajax novalidate>
                @csrf

                <p class="lp-field field">
                    <label for="r-email">Email address</label>
                    <input id="r-email" type="email" name="email" value="{{ old('email') }}" required
                           maxlength="255" autocomplete="username" inputmode="email" autofocus
                           placeholder="you@restaurant.com">
                </p>

                <p class="lp-field field">
                    <label for="r-password">Password</label>
                    <span class="lp-auth-password">
                        <input id="r-password" type="password" name="password" required autocomplete="current-password">
                        {{-- Wired by app.js, the same toggle the admin sign-in uses. --}}
                        <button type="button" class="lp-auth-reveal" data-toggle-password aria-controls="r-password"
                                aria-pressed="false">Show</button>
                    </span>
                </p>

                <label class="lp-auth-check">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    <span>Keep me signed in</span>
                </label>

                <p class="lp-meta" style="margin:0 0 12px"><a href="{{ route('password.request') }}">Forgot your password?</a></p>

                <button type="submit" class="lp-btn lp-btn-solid lp-btn-lg lp-auth-submit">Sign in</button>
            </form>

            <p class="lp-meta lp-auth-foot">
                New to {{ $site['name'] }}? <a href="{{ route('register') }}">Start your free trial</a>
            </p>
        </div>
    </div>
</main>

@include('site._footer')

<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
