@extends('admin.layouts.guest')

@section('title', 'Set your password')

@section('content')
    {{-- Uploaded Site Logo when there is one, lettermark otherwise. --}}
    <x-brand />

    <h1>Set your password</h1>
    <p class="lede">Choose a password for your account, then sign in.</p>

    {{-- With JavaScript on, these are delivered as toasts instead. --}}
    <noscript>
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif
    </noscript>

    <form method="POST" action="{{ route('admin.password.set.store') }}" data-ajax novalidate>
        @csrf

        {{-- The token comes from the emailed link, not from anything typed. --}}
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="field">
            <label for="email">Email address</label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email', $email) }}"
                required
                autocomplete="username"
                inputmode="email"
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
            >
            @error('email')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </div>

        <div class="field">
            <label for="password">New password</label>
            <div class="pw-wrap">
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autofocus
                    autocomplete="new-password"
                    aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                >
                {{-- Wired by app.js, delegated for the whole app. --}}
                <button type="button" class="pw-toggle" data-toggle-password
                        aria-controls="password" aria-pressed="false">Show</button>
            </div>
            <div class="form-hint">Minimum 8 characters.</div>
            @error('password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <input
                id="password_confirmation"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
            >
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">Set password</button>
    </form>

    <p class="foot">
        Links expire. If this one has, ask your administrator to send another.
    </p>
@endsection
