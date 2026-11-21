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
    <link rel="stylesheet" href="{{ asset('assets/css/ui.css') }}?v={{ filemtime(public_path('assets/css/ui.css')) }}">
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
            <h1>Sign in to your restaurant</h1>

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
                           maxlength="255" autocomplete="username" inputmode="email" autofocus>
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

            {{-- Shown when sign-in says the email is not verified yet. --}}
            <div class="lp-resend" data-resend @unless (str_contains((string) $errors->first('account').(string) session('error'), 'verify your email')) hidden @endunless>
                <p>Did not get the verification email?</p>
                <button type="button" class="lp-btn lp-btn-ghost" data-resend-button>Send the verification link again</button>
                <p class="lp-resend-msg" role="status" data-resend-message></p>
            </div>

            <p class="lp-meta lp-auth-foot">
                New to {{ $site['name'] }}? <a href="{{ route('register') }}">Start your free trial</a>
            </p>
        </div>
    </div>
</main>

@include('site._footer')

<script>
    (function () {
        var form = document.querySelector('form[action="{{ route('login.store') }}"]');
        var box = document.querySelector('[data-resend]');
        if (!form || !box) { return; }

        var button = box.querySelector('[data-resend-button]');
        var message = box.querySelector('[data-resend-message]');

        // The sign-in answered "verify your email first": offer to send the link again.
        form.addEventListener('ajax:error', function (event) {
            var errors = event.detail && event.detail.payload && event.detail.payload.errors;
            var text = errors && errors.account ? String(errors.account[0] || '') : '';
            if (/verify your email/i.test(text)) { box.hidden = false; }
        });

        button.addEventListener('click', function () {
            message.textContent = 'Sending...';
            button.disabled = true;

            fetch('{{ route('verification.resend') }}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json', 'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': form.querySelector('input[name=_token]').value
                },
                body: JSON.stringify({ email: form.email.value, password: form.password.value })
            }).then(function (r) { return r.json().catch(function () { return {}; }); })
              .then(function (data) {
                  message.textContent = data.message || 'Could not send it just now. Please try again in a few minutes.';
              })
              .catch(function () { message.textContent = 'Could not send it just now. Please try again in a few minutes.'; })
              .then(function () { button.disabled = false; });
        });
    })();
</script>

<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
<script src="{{ asset('assets/js/topbar.js') }}?v={{ filemtime(public_path('assets/js/topbar.js')) }}" defer></script>
</body>
</html>
