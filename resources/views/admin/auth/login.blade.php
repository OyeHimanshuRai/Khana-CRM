@extends('admin.layouts.guest')

@section('title', 'Admin sign in')

@section('content')
            {{-- Uploaded Site Logo when there is one, lettermark otherwise.
                 The platform owner's door only: restaurants sign in at /login,
                 and each door refuses the other's accounts. --}}
            <x-brand />

            <h1>Platform admin sign in</h1>
            <p class="lede">For the platform owner only.</p>

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
                    <a href="{{ route('password.request') }}" class="text-sm">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
            </form>

            <p class="foot">Authorised personnel only. Activity may be monitored.</p>
@endsection

{{-- The Show/Hide button is wired by app.js, delegated for the whole app. --}}
