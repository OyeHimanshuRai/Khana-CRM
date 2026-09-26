@extends('khana::layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div class="auth-head">
        <x-khana::brand sub="Accounts" />
        <h1 class="auth-title">Sign in</h1>
        <p class="text-sm text-muted">One sign-in for CRM and ERP.</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="auth-form">
        @csrf

        <div class="field">
            <label class="form-label" for="identifier">Email or mobile</label>
            <input class="form-control" id="identifier" name="identifier" value="{{ old('identifier') }}"
                   autofocus autocomplete="username" required>
            @error('identifier')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" id="password" name="password" type="password"
                   autocomplete="current-password" required>
            @error('password')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="field" style="display:flex;align-items:center;justify-content:space-between">
            <label class="check" style="font-size:13px">
                <input type="checkbox" name="remember" value="1"> Keep me signed in
            </label>
            <a class="text-sm" href="{{ route('password.request') }}">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    <p class="text-sm text-muted auth-foot">
        New to Khana? <a href="{{ route('signup') }}">Start a free trial</a>.
    </p>
@endsection
