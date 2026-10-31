@extends('admin.layouts.guest')

@section('title', 'Choose a new password')

@section('content')
    <x-brand />

    <h1>Choose a new password</h1>
    <p class="lede">You will be signed out on every other device.</p>

    <form method="POST" action="{{ route('password.update') }}" data-ajax novalidate>
        @csrf

        {{-- The token comes from the emailed link, not from anything typed. --}}
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required
                   autocomplete="username" inputmode="email"
                   aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
            @error('email')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </div>

        <div class="field">
            <label for="password">New password</label>
            <div class="pw-wrap">
                <input id="password" type="password" name="password" required autofocus autocomplete="new-password"
                       aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
                <button type="button" class="pw-toggle" data-toggle-password
                        aria-controls="password" aria-pressed="false">Show</button>
            </div>
            <div class="form-hint">At least 8 characters, with letters and numbers.</div>
            @error('password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">Change password</button>
    </form>

    <p class="foot">Links expire after an hour. <a href="{{ route('password.request') }}">Ask for a new one</a>.</p>
@endsection
