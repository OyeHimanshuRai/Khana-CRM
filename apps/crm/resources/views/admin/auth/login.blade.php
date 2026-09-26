@extends('admin.layouts.guest')

@section('title', 'Sign in')

@section('content')
            {{-- Uploaded Site Logo when there is one, lettermark otherwise. --}}
            <x-brand />

            <h1>Sign in</h1>
            <p class="lede">Use your administrator account to continue.</p>

            {{-- With JavaScript on, these are delivered as toasts instead. --}}
            <noscript>
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
                @endif

                {{-- Auth failures are attached to `email`; anything else is unexpected. --}}
                @if ($errors->any() && ! $errors->has('email'))
                    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
                @endif
            </noscript>

            <form method="POST" action="{{ route('admin.login.store') }}" data-ajax novalidate>
                @csrf

                <div class="field">
                    <label for="email">Email address</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="username"
                        inputmode="email"
                        placeholder="you@company.com"
                        aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                        @error('email') aria-describedby="email-error" @enderror
                    >
                    @error('email')
                        <span class="field-error" id="email-error" role="alert">{{ $message }}</span>
                    @enderror
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="pw-wrap">
                        <input
                            id="password"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                            @error('password') aria-describedby="password-error" @enderror
                        >
                        <button type="button" class="pw-toggle" data-toggle-password aria-controls="password" aria-pressed="false">
                            Show
                        </button>
                    </div>
                    @error('password')
                        <span class="field-error" id="password-error" role="alert">{{ $message }}</span>
                    @enderror
                </div>

                <div class="row-between">
                    <label class="check" for="remember">
                        <input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                        Keep me signed in
                    </label>

                    {{-- The way out for an owner who opened their own account
                         and has nobody to ask. See PasswordResetController. --}}
                    <a href="{{ route('admin.password.request') }}">Forgotten your password?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
            </form>

            {{--
                The way in for somebody who has no account yet.

                Here rather than only on the marketing page, because a
                restaurant owner who was sent this URL by a friend lands on
                this screen with nothing to type into it. See
                App\Http\Controllers\SignupController.
            --}}
            <p class="foot" style="margin-bottom:6px">
                No account yet? <a href="{{ route('signup') }}">Open one for your restaurant</a>.
            </p>

            <p class="foot">Authorised personnel only. Activity may be monitored.</p>
@endsection

{{-- The Show/Hide button is wired by app.js, delegated for the whole app. --}}
