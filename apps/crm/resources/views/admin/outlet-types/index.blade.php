@extends('admin.layouts.app')

@section('title', 'Outlet Types')

@section('content')
    <x-landing-page
        title="Outlet Types"
        subtitle="The kinds of business the product suits — QSR, cafe, cloud kitchen."
        route-base="admin.outlet-types"
        can="content.outlet_types"
        create-label="Add outlet type"
        icon="building"
        placeholder="Search name or description…"
        :stats="$stats"
        :search="$search"
        :status="$status"
    >
        @include('admin.outlet-types._list')
    </x-landing-page>
@endsection
