@extends('shop.layouts.app')

@section('title', 'Your addresses')

@section('content')
    <h1 class="shop-h1">Your addresses</h1>

    <div class="shop-layout is-side-360">
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
                    {{--
                        An ordinary POST while the form beside it submits over AJAX, because
                        removing an address needs the prompt in front of it: the delegated
                        [data-ajax] handler acts on the submit event whether or not onsubmit
                        cancelled it, so dismissing the prompt would still delete the address.
                        The reload also repaints the list, which the storefront has no
                        fragment machinery to do on its own.
                    --}}
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
                    <input type="text" name="label" value="{{ old('label') }}" placeholder="e.g. Home, Farm">
                    @error('label') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="shop-field">
                    <label>Recipient name</label>
                    <input type="text" name="recipient_name" value="{{ old('recipient_name') }}" required>
                    @error('recipient_name') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="shop-field">
                    <label>Mobile</label>
                    <input type="tel" name="mobile" value="{{ old('mobile') }}" required>
                    @error('mobile') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="shop-field">
                    <label>Address line 1</label>
                    <input type="text" name="address_line1" value="{{ old('address_line1') }}" required>
                    @error('address_line1') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="shop-field">
                    <label>Address line 2</label>
                    <input type="text" name="address_line2" value="{{ old('address_line2') }}">
                    @error('address_line2') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="shop-field-row">
                    <div class="shop-field">
                        <label>City</label>
                        <input type="text" name="city" value="{{ old('city') }}">
                        @error('city') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="shop-field">
                        <label>State</label>
                        <input type="text" name="state" value="{{ old('state') }}">
                        @error('state') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="shop-field">
                    <label>Pincode</label>
                    <input type="text" name="pincode" value="{{ old('pincode') }}">
                    @error('pincode') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <label class="shop-check" style="margin-bottom:16px;">
                    <input type="checkbox" name="is_default" value="1" @checked(old('is_default'))>
                    <span>Set as default</span>
                </label>

                <button type="submit" class="shop-btn shop-btn-block">Save address</button>
            </form>
        </div>
    </div>
@endsection
