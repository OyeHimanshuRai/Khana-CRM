@extends('admin.layouts.app')

@section('title', 'Testimonials')

@section('content')
    <x-landing-page
        title="Testimonials"
        subtitle="What customers say about the product, on the public page."
        route-base="admin.testimonials"
        can="content.testimonials"
        create-label="Add testimonial"
        icon="star"
        placeholder="Search quote, name or company…"
        :stats="$stats"
        :search="$search"
        :status="$status"
    >
        @include('admin.testimonials._list')
    </x-landing-page>
@endsection
