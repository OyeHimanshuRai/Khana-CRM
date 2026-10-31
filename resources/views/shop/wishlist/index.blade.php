@extends('shop.layouts.app')

@section('title', 'Your wishlist')

@section('content')
    <h1 class="shop-h1">Your wishlist</h1>

    @if ($items->isEmpty())
        <div class="shop-empty">Nothing saved yet.</div>
    @else
        <div class="shop-grid">
            @foreach ($items as $item)
                @php $product = $item->product; @endphp
                @include('shop.catalog._product-card', ['product' => $product])
            @endforeach
        </div>
    @endif
@endsection
