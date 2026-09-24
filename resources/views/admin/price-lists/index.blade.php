@extends('admin.layouts.app')

@section('title', 'Price Lists')

@section('content')
    <x-page-header
        title="Price Lists"
        subtitle="Prices that apply only sometimes"
        :crumbs="['Menu & Stock' => null, 'Price Lists' => null]"
    >
        <x-slot:actions>
            @allows('inventory.price_lists.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.price-lists.create') }}">
                    <x-icon name="plus" :size="15" /> New Price List
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="alert alert-info" style="margin-bottom:14px">
        <strong>A price list beats the menu price while it is running.</strong>
        It applies everywhere at once — the till, the QR menu and anything reading the API —
        which is what a happy hour means. Outside its window nothing changes at all.
    </div>

    <div class="card" data-ajax-list="{{ route('admin.price-lists.index') }}">
        <div data-ajax-list-content>
            @include('admin.price-lists._list')
        </div>
    </div>
@endsection
