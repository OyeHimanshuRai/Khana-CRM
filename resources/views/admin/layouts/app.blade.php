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
            {{-- Set at sign-in when the password is a well-known one; see RequireStrongPassword. --}}
            @if (session('password_is_weak') && ! request()->routeIs('admin.profile.*'))
                <div class="banner banner-warning" role="alert">
                    <div class="banner-body">
                        <strong>Your password is a well-known default.</strong>
                        Anyone who has read a setup guide could sign in as you.
                    </div>
                    <a class="btn btn-sm btn-primary" href="{{ route('admin.profile.edit') }}">Change password</a>
                </div>
            @endif

            @yield('content')
        </main>

        @include('admin.partials.footer')
    </div>
@endsection
