@extends('admin.layouts.guest')

@section('title', 'Forgotten password')

@section('content')
    {{-- Uploaded Site Logo when there is one, lettermark otherwise. --}}
    <x-brand />

    <h1>Forgotten your password?</h1>
    <p class="lede">
        Give us the email address you sign in with and we will send a link to choose a new one.
    </p>

    {{-- With JavaScript on, these are delivered as toasts instead. --}}
    <noscript>
        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif
    </noscript>

    <form method="POST" action="{{ route('admin.password.email') }}" data-ajax novalidate>
        @csrf

        <div class="field">
            <label for="email">Email address</label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                required
                autofocus
                autocomplete="username"
                inputmode="email"
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
            >
            @error('email')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">Send the link</button>
    </form>

    <p class="foot" style="margin-bottom:6px">
        <a href="{{ route('admin.login') }}">Back to sign in</a>
    </p>

    {{--
        Said here rather than only after submitting, because it is the answer
        to the question somebody on this page is actually asking: the link
        does not last long, and it will not arrive at all for an address that
        has no account.
    --}}
    <p class="foot">
        The link works once. Nothing is sent to an address without an account.
    </p>
@endsection
