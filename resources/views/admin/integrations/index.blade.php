@extends('admin.layouts.app')

@section('title', 'Integrations')

@section('content')
    <x-landing-page
        title="Integrations"
        subtitle="What the product plugs into. The logo is the content here."
        route-base="admin.integrations"
        can="content.integrations"
        create-label="Add integration"
        icon="zap"
        placeholder="Search name or category…"
        :stats="$stats"
        :search="$search"
        :status="$status"
    >
        @include('admin.integrations._list')
    </x-landing-page>
@endsection
