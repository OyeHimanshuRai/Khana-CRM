@extends('shop.layouts.app')

@section('title', 'Your account')

@section('content')
    <h1 class="shop-h1">Your account</h1>

    <div class="shop-layout is-side-260">
        <div class="shop-panel shop-panel-pad">
            <form action="{{ route('shop.account.update', $shop) }}" method="POST" data-ajax>
                @csrf
                @method('PUT')

                <div class="shop-field">
                    <label for="name">Full name</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $customer->name) }}" required>
                    @error('name') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div class="shop-field-row">
                    <div class="shop-field">
                        <label for="mobile">Mobile</label>
                        <input type="tel" name="mobile" id="mobile" value="{{ old('mobile', $customer->mobile) }}">
                        @error('mobile') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="shop-field">
                        <label for="email">Email</label>
                        <input type="email" name="email" id="email" value="{{ old('email', $customer->email) }}">
                        @error('email') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="shop-field-row">
                    <div class="shop-field">
                        <label for="password">New password (optional)</label>
                        <input type="password" name="password" id="password">
                        @error('password') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="shop-field">
                        <label for="password_confirmation">Confirm new password</label>
                        <input type="password" name="password_confirmation" id="password_confirmation">
                    </div>
                </div>

                <button type="submit" class="shop-btn">Save changes</button>
            </form>
        </div>

        <div class="shop-panel shop-panel-pad" style="align-self:start;">
            <h2 class="shop-h2">Quick links</h2>
            <p><a href="{{ route('shop.account.addresses.index', $shop) }}">Manage addresses</a></p>
            <p><a href="{{ route('shop.orders.index', $shop) }}">Order history</a></p>
            <p><a href="{{ route('shop.wishlist.index', $shop) }}">Wishlist</a></p>
        </div>
    </div>
@endsection
