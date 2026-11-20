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
            @if (session(\App\Http\Controllers\Admin\ImpersonationController::SESSION_KEY))
                <div class="banner banner-warning" role="alert">
                    <div class="banner-body">
                        <strong>Viewing as {{ auth()->user()->name }}</strong>
                        ({{ auth()->user()->tenant?->name ?? 'company' }} owner). Everything you do here is recorded in the Activity Log.
                    </div>
                    <form method="POST" action="{{ route('admin.impersonate.stop') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Exit to Platform</button>
                    </form>
                </div>
            @endif

            {{-- A restaurant's person with no branch can sign in and still see nothing: the
                 till, the kitchen and the bills all belong to a branch. Say so, rather than
                 leave them looking at empty screens. --}}
            @if (auth()->user() && ! auth()->user()->isSuperAdmin() && auth()->user()->tenant_id && \App\Support\CurrentShop::accessible()->isEmpty())
                <div class="banner banner-warning" role="alert">
                    <div class="banner-body">
                        <strong>You have not been given a branch yet.</strong>
                        Ask the owner to tick a branch for you (Users, then your name, then Branches). Until then there is nothing to show here.
                    </div>
                </div>
            @endif

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
