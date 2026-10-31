{{--
    The storefront of an outlet that is not trading right now - its company
    paused, or its subscription not yet renewed. Shown in place of the menu so
    nobody fills a cart only to be refused at checkout. Says nothing about why:
    that is between the restaurant and the platform, not the customer.
--}}
@extends('shop.layouts.app')

@section('title', 'Closed for now')

@section('content')
    <div class="shop-panel shop-panel-pad" style="max-width:560px;margin:48px auto;text-align:center;">
        <h1 class="shop-h1">{{ $shop->storefront_name ?? $shop->name }} is not taking orders online right now</h1>
        <p class="shop-card-meta" style="margin-top:12px;">
            Please check back a little later, or contact the restaurant directly
            @if ($shop->phone) on <a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop->phone) }}">{{ $shop->phone }}</a>@endif.
        </p>
    </div>
@endsection
