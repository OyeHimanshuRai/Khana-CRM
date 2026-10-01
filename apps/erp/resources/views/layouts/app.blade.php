{{--
    ERP's signed-in page: the kit's shell, with the current hub's tabs above
    the content.

        @extends('layouts.app')
        @section('title', 'Stock')
        @section('page') ... @endsection
--}}
@extends('khana::layouts.app')

@section('user-role', auth()->user()?->roleName())

@section('content')
    @include('partials.hub-tabs')

    @yield('page')
@endsection
