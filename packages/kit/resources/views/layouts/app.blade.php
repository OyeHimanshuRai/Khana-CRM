{{--
    Signed-in shell: sidebar, top bar, content, footer.

        @extends('khana::layouts.app')
        @section('title', 'Outlets')
        @section('content') ... @endsection

    The sidebar is the app's config('menu'); the top bar carries the outlet
    switcher (CRM and ERP), the product switcher and the person's menu. Apps
    add to the top bar with @section('header-tools') (left, beside the
    switcher) and @push('header-actions') (right, before the person).
--}}
@extends('khana::layouts.base')

@section('body')
    @include('khana::partials.sidebar')
    @include('khana::partials.header')

    <div class="app-main">
        <main class="app-content">
            @yield('content')
        </main>

        @include('khana::partials.footer')
    </div>
@endsection
