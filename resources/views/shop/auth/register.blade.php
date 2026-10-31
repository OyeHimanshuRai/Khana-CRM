@extends('shop.layouts.app')

@section('title', 'Create account')

@section('content')
    <div class="shop-auth-wrap shop-panel shop-panel-pad">
        <h1 class="shop-h1">Create an account</h1>

        <form action="{{ route('shop.register.store', $shop) }}" method="POST" data-ajax data-redirect-delay="300">
            @csrf

            <div class="shop-field">
                <label for="name">Full name</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" required>
                @error('name') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="shop-field-row">
                <div class="shop-field">
                    <label for="mobile">Mobile</label>
                    <input type="tel" name="mobile" id="mobile" value="{{ old('mobile') }}">
                    @error('mobile') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="shop-field">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email') }}">
                    @error('email') <span class="field-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="shop-field-row">
                <div class="shop-field">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" required>
                    @error('password') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="shop-field">
                    <label for="password_confirmation">Confirm password</label>
                    <input type="password" name="password_confirmation" id="password_confirmation" required>
                </div>
            </div>

            <button type="submit" class="shop-btn shop-btn-block">Create account</button>
        </form>

        <p style="margin-top:16px;font-size:14px;">
            Already have an account? <a href="{{ route('shop.login', $shop) }}" style="color:var(--shop-brand-strong);font-weight:600;">Sign in</a>
        </p>
    </div>
@endsection
