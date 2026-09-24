@extends('shop.layouts.app')

@section('title', 'Checkout')

@section('content')
    <h1 class="shop-h1">Checkout</h1>

    <form action="{{ route('shop.checkout.store', $shop) }}" method="POST" data-ajax data-redirect-delay="300">
        @csrf

        <div class="shop-layout is-side-340">
            <div>
                <div class="shop-panel shop-panel-pad" style="margin-bottom:20px;">
                    <h2 class="shop-h2">Delivery address</h2>

                    @if ($addresses->isNotEmpty())
                        @foreach ($addresses as $address)
                            <label class="shop-check" style="margin-bottom:10px;">
                                <input type="radio" name="address_id" value="{{ $address->id }}" @checked($loop->first)>
                                <span>{{ $address->recipient_name }}, {{ $address->line() }} &mdash; {{ $address->mobile }}</span>
                            </label>
                        @endforeach
                        <label class="shop-check" style="margin:14px 0 10px;">
                            <input type="radio" name="address_id" value="" id="new-address-toggle">
                            <span>Use a new address</span>
                        </label>
                    @endif

                    <div id="new-address-fields" style="{{ $addresses->isNotEmpty() ? 'display:none;' : '' }}">
                        <div class="shop-field-row">
                            <div class="shop-field">
                                <label>Recipient name</label>
                                <input type="text" name="recipient_name" value="{{ old('recipient_name') }}">
                                @error('recipient_name') <span class="field-error">{{ $message }}</span> @enderror
                            </div>
                            <div class="shop-field">
                                <label>Mobile</label>
                                <input type="tel" name="mobile" value="{{ old('mobile') }}">
                                @error('mobile') <span class="field-error">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="shop-field">
                            <label>Address line 1</label>
                            <input type="text" name="address_line1" value="{{ old('address_line1') }}">
                            @error('address_line1') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="shop-field">
                            <label>Address line 2</label>
                            <input type="text" name="address_line2" value="{{ old('address_line2') }}">
                        </div>
                        <div class="shop-field-row">
                            <div class="shop-field">
                                <label>City</label>
                                <input type="text" name="city" value="{{ old('city') }}">
                            </div>
                            <div class="shop-field">
                                <label>State</label>
                                <input type="text" name="state" value="{{ old('state') }}">
                            </div>
                        </div>
                        <div class="shop-field">
                            <label>Pincode</label>
                            <input type="text" name="pincode" value="{{ old('pincode') }}">
                        </div>
                        <label class="shop-check">
                            <input type="checkbox" name="save_address" value="1" checked>
                            <span>Save this address for next time</span>
                        </label>
                    </div>
                </div>

                <div class="shop-panel shop-panel-pad">
                    <h2 class="shop-h2">Payment method</h2>
                    <label class="shop-check" style="margin-bottom:10px;">
                        <input type="radio" name="payment_method" value="cod" checked>
                        <span>Cash on delivery</span>
                    </label>
                    <label class="shop-check">
                        <input type="radio" name="payment_method" value="online">
                        <span>Pay online (shop will confirm your payment)</span>
                    </label>
                    @error('payment_method') <span class="field-error">{{ $message }}</span> @enderror

                    <div class="shop-field" style="margin-top:16px;">
                        <label>Order note (optional)</label>
                        <textarea name="customer_note" rows="2">{{ old('customer_note') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="shop-panel shop-panel-pad" style="align-self:start;">
                <h2 class="shop-h2">Order summary</h2>

                @foreach ($cart['items'] as $line)
                    <div class="shop-summary-row">
                        <span>{{ $line['product']->name }} &times; {{ rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') }}</span>
                        <span>₹{{ number_format($line['line_total'], 2) }}</span>
                    </div>
                @endforeach

                <div class="shop-field" style="margin-top:14px;">
                    <label>Coupon code</label>
                    <input type="text" name="coupon_code" value="{{ old('coupon_code') }}" placeholder="Optional">
                </div>

                <div class="shop-summary-row total">
                    <span>Total</span>
                    <span>₹{{ number_format($cart['subtotal'], 2) }}</span>
                </div>

                <button type="submit" class="shop-btn shop-btn-block" style="margin-top:14px;">Place order</button>
            </div>
        </div>
    </form>

    <script>
        var toggle = document.getElementById('new-address-toggle');
        var fields = document.getElementById('new-address-fields');
        if (toggle && fields) {
            document.querySelectorAll('input[name="address_id"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    fields.style.display = toggle.checked ? '' : 'none';
                });
            });
        }
    </script>
@endsection
