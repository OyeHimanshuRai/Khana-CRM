@extends('khana::layouts.guest')

@section('title', 'Forgot password')

@section('content')
    <div class="auth-head">
        <x-khana::brand sub="Accounts" />
        <h1 class="auth-title">Reset your password</h1>
        <p class="text-sm text-muted">Enter your email and we'll send a reset link.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="auth-form">
        @csrf

        <div class="field">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" autofocus required>
            @error('email')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
    </form>

    <p class="text-sm text-muted auth-foot">
        <a href="{{ route('login') }}">Back to sign in</a>
    </p>
@endsection
