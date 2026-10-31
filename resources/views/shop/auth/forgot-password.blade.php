@extends('shop.layouts.app')

@section('title', 'Forgot your password')

@section('content')
    <div class="shop-auth-wrap shop-panel shop-panel-pad">
        <h1 class="shop-h1">Forgot your password?</h1>
        <p class="shop-card-meta" style="margin-bottom:16px;">Enter the email on your account and we will send you a link to choose a new one.</p>

        @if (session('status'))
            <div class="shop-alert" role="status">{{ session('status') }}</div>
        @endif

        <form action="{{ route('shop.password.email', $shop) }}" method="POST" data-ajax>
            @csrf

            <div class="shop-field">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autocomplete="email">
                @error('email') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <button type="submit" class="shop-btn shop-btn-block">Send the link</button>
        </form>

        <p style="margin-top:16px;font-size:14px;">
            <a href="{{ route('shop.login', $shop) }}" style="color:var(--shop-brand-strong);">Back to sign in</a>
        </p>
    </div>
@endsection
