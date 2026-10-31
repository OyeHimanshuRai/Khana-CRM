@extends('admin.layouts.guest')

@section('title', 'Confirm your email')

@section('content')
    <x-brand />

    <h1>Confirm your email</h1>
    <p class="lede">
        We sent a link to <strong>{{ $email }}</strong>. Open it to start using your account.
    </p>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.verification.send') }}" data-ajax>
        @csrf
        <button type="submit" class="btn btn-primary btn-block btn-lg">Send the link again</button>
    </form>

    <form method="POST" action="{{ route('admin.logout') }}" style="margin-top:12px">
        @csrf
        <button type="submit" class="btn btn-ghost btn-block">Sign out</button>
    </form>

    <p class="foot">
        Not in your inbox? Check the spam folder. Signed up with the wrong address? Sign out and sign up again.
    </p>
@endsection
