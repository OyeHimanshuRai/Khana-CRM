@extends('shop.layouts.app')

@section('title', 'Choose a new password')

@section('content')
    <div class="shop-auth-wrap shop-panel shop-panel-pad">
        <h1 class="shop-h1">Choose a new password</h1>

        <form action="{{ route('shop.password.update', $shop) }}" method="POST" data-ajax>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="shop-field">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email', $email) }}" required autocomplete="email">
                @error('email') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="shop-field">
                <label for="password">New password</label>
                <input type="password" name="password" id="password" required autocomplete="new-password" minlength="8">
                @error('password') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="shop-field">
                <label for="password_confirmation">Confirm password</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="shop-btn shop-btn-block">Change password</button>
        </form>
    </div>
@endsection
