{{--
    Authenticated shell: sidebar + header + content + footer.

    Usage:
        @extends('admin.layouts.app')
        @section('title', 'Products')
        @section('content')  ...your page...  @endsection

    The page header/breadcrumb is a separate component so pages can omit it:
        <x-page-header title="Products" :crumbs="['Inventory' => null, 'Products' => null]" />
--}}
@extends('admin.layouts.base')

@section('body')
    @include('admin.partials.sidebar')
    @include('admin.partials.header')

    <div class="app-main">
        <main class="app-content">
            @yield('content')
        </main>

        @include('admin.partials.footer')
    </div>
@endsection
