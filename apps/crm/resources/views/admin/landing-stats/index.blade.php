@extends('admin.layouts.app')

@section('title', 'Trust Numbers')

@section('content')
    <x-landing-page
        title="Trust Numbers"
        subtitle="The figures above the fold. Prefer a counted one over a typed one."
        route-base="admin.landing-stats"
        can="content.stats"
        create-label="Add number"
        icon="chart"
        placeholder="Search label or value…"
        :stats="$stats"
        :search="$search"
        :status="$status"
    >
        @include('admin.landing-stats._list')
    </x-landing-page>
@endsection
