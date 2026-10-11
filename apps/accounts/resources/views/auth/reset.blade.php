@extends('khana::layouts.guest')

@section('title', 'Choose a new password')

@section('content')
    <div class="auth-head">
        <x-khana::brand sub="Accounts" />
        <h1 class="auth-title">Choose a new password</h1>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="auth-form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="field">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $email) }}" required>
            @error('email')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label class="form-label" for="password">New password</label>
            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required>
            @error('password')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label class="form-label" for="password_confirmation">Confirm password</label>
            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Reset password</button>
    </form>
@endsection
