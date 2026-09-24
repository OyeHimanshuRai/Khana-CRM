@extends('admin.layouts.app')

@section('title', 'Table Bills')

@section('content')
    <x-page-header
        title="Table Bills"
        subtitle="Every table with a party at it, and what it owes."
        :crumbs="['Counter' => null, 'Table Bills' => null]"
    >
        <x-slot:actions>
            @allows('dining.tables.view')
                <a class="btn btn-sm" href="{{ route('admin.tables.plan') }}">
                    <x-icon name="grid" :size="15" /> Floor Plan
                </a>
            @endallows

            @allows('pos.terminal.view')
                <a class="btn btn-sm" href="{{ route('admin.pos.terminal') }}">
                    <x-icon name="cart" :size="15" /> Counter
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="card" data-ajax-list="{{ route('admin.table-bills.index') }}">
        <div data-ajax-list-content>
            @include('admin.table-bills._list')
        </div>
    </div>
@endsection
