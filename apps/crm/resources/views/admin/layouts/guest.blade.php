{{--
    Unauthenticated shell: a single centred card, no sidebar or header.
    Used by the login screen and any future password-reset pages.
--}}
@extends('admin.layouts.base')

@section('body')
    <div class="auth-wrap">
        <main class="auth-card">
            @yield('content')
        </main>
    </div>
@endsection
