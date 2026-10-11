@extends('khana::layouts.app')

@section('title', 'Home')

@section('content')
    <x-khana::page-header :title="'Hello, '.$user->name" subtitle="Open a product to start your day" />

    @if (empty($products))
        <x-khana::empty icon="inbox" title="No products yet"
            text="You have an account, but no product access. Ask your business owner to give you a role." />
    @else
        <div class="stat-grid">
            @foreach ($products as $product)
                @php $url = config('khana.urls.'.$product); @endphp
                <a class="stat" href="{{ $url ?: '#' }}" style="text-decoration:none">
                    <div class="stat-body">
                        <div class="stat-label">Open</div>
                        <div class="stat-value">{{ strtoupper($product) }}</div>
                    </div>
                    <div class="stat-icon"><x-khana::icon :name="$product === 'crm' ? 'cart' : 'package'" :size="20" /></div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
