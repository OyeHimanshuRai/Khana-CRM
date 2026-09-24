@extends('shop.layouts.app')

@section('title', 'Sign in')

@section('content')
    <div class="shop-auth-wrap shop-panel shop-panel-pad">
        <h1 class="shop-h1">Sign in</h1>

        <form action="{{ route('shop.login.store', $shop) }}" method="POST" data-ajax data-redirect-delay="300">
            @csrf

            <div class="shop-field">
                <label for="login">Mobile or email</label>
                <input type="text" name="login" id="login" value="{{ old('login') }}" required>
                @error('login') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="shop-field">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required>
                @error('password') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <label class="shop-check" style="margin-bottom:16px;">
                <input type="checkbox" name="remember" value="1">
                <span>Remember me</span>
            </label>

            <button type="submit" class="shop-btn shop-btn-block">Sign in</button>
        </form>

        <p style="margin-top:16px;font-size:14px;">
            New here? <a href="{{ route('shop.register', $shop) }}" style="color:var(--shop-brand-strong);font-weight:600;">Create an account</a>
        </p>
    </div>
@endsection
