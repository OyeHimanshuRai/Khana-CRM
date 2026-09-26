@extends('khana::layouts.guest')

@section('title', 'Invitation expired')

@section('content')
    <div class="auth-head">
        <x-khana::brand sub="Accounts" />
        <h1 class="auth-title">This invitation is no longer valid</h1>
        <p class="text-sm text-muted">It may have been used already, or it has expired. Ask the business owner to send a new one.</p>
    </div>

    <a href="{{ route('login') }}" class="btn btn-primary btn-block">Go to sign in</a>
@endsection
