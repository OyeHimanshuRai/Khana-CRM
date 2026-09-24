@extends('admin.layouts.app')

@section('title', 'Screenshots')

@section('content')
    <x-landing-page
        title="Screenshots"
        subtitle="Pictures of the product, shown in order on the landing page."
        route-base="admin.showcases"
        can="content.showcases"
        create-label="Add screenshot"
        icon="camera"
        placeholder="Search title or caption…"
        :stats="$stats"
        :search="$search"
        :status="$status"
    >
        @include('admin.showcases._list')
    </x-landing-page>
@endsection
