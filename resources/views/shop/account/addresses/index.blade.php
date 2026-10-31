@extends('shop.layouts.app')

@section('title', 'Your addresses')

@section('content')
    <h1 class="shop-h1">Your addresses</h1>

    <div class="shop-layout" style="grid-template-columns:1fr 360px;">
        <div class="shop-panel shop-panel-pad">
            @if ($addresses->isEmpty())
                <p style="color:var(--shop-muted);">No saved addresses yet.</p>
            @endif

            @foreach ($addresses as $address)
                <div class="shop-line" style="grid-template-columns:1fr auto;">
                    <div>
                        <div style="font-weight:600;">
                            {{ $address->recipient_name }}
                            @if ($address->is_default)
                                <span class="shop-badge shop-badge-success">Default</span>
                            @endif
                        </div>
                        <div class="shop-card-meta">{{ $address->line() }} &middot; {{ $address->mobile }}</div>
                    </div>
                    <form action="{{ route('shop.account.addresses.destroy', [$shop, $address]) }}" method="POST" onsubmit="return confirm('Remove this address?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="shop-btn shop-btn-outline shop-btn-sm" style="border-color:var(--shop-danger);color:var(--shop-danger);">Remove</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="shop-panel shop-panel-pad" style="align-self:start;">
            <h2 class="shop-h2">Add address</h2>

            <form action="{{ route('shop.account.addresses.store', $shop) }}" method="POST" data-ajax data-redirect-delay="300">
                @csrf

                <div class="shop-field">
                    <label>Label</label>
                    <input type="text" name="label" placeholder="e.g. Home, Farm">
                </div>
                <div class="shop-field">
                    <label>Recipient name</label>
                    <input type="text" name="recipient_name" required>
                </div>
                <div class="shop-field">
                    <label>Mobile</label>
                    <input type="tel" name="mobile" required>
                </div>
                <div class="shop-field">
                    <label>Address line 1</label>
                    <input type="text" name="address_line1" required>
                </div>
                <div class="shop-field">
                    <label>Address line 2</label>
                    <input type="text" name="address_line2">
                </div>
                <div class="shop-field-row">
                    <div class="shop-field">
                        <label>City</label>
                        <input type="text" name="city">
                    </div>
                    <div class="shop-field">
                        <label>State</label>
                        <input type="text" name="state">
                    </div>
                </div>
                <div class="shop-field">
                    <label>Pincode</label>
                    <input type="text" name="pincode">
                </div>
                <label class="shop-check" style="margin-bottom:16px;">
                    <input type="checkbox" name="is_default" value="1">
                    <span>Set as default</span>
                </label>

                <button type="submit" class="shop-btn shop-btn-block">Save address</button>
            </form>
        </div>
    </div>
@endsection
