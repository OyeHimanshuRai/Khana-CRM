{{--
    Signed-out shell: one centred card. Sign-in, sign-up, invitations and
    password resets.
--}}
@extends('khana::layouts.base')

@section('body')
    <div class="auth-wrap">
        <main class="auth-card @yield('card-class')">
            @yield('content')
        </main>
    </div>
@endsection
