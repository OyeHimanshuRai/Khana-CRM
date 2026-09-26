@extends('khana::layouts.guest')

@section('title', 'Join the team')

@section('content')
    <div class="auth-head">
        <x-khana::brand sub="Accounts" />
        <h1 class="auth-title">Hello, {{ $user->name }}</h1>
        <p class="text-sm text-muted">You have been invited to {{ $business->name }}. Set a password to finish.</p>
    </div>

    <form method="POST" action="{{ route('invite.accept', $token) }}" class="auth-form">
        @csrf

        <div class="field">
            <label class="form-label">Signing in as</label>
            <input class="form-control" value="{{ $user->email ?? $user->mobile }}" disabled>
        </div>

        <div class="field">
            <label class="form-label" for="password">Choose a password</label>
            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" autofocus required>
            @error('password')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label class="form-label" for="password_confirmation">Confirm password</label>
            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Join {{ $business->name }}</button>
    </form>
@endsection
