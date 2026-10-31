@extends('admin.layouts.guest')

@section('title', 'Forgot your password')

@section('content')
    <x-brand />

    <h1>Forgot your password?</h1>
    <p class="lede">Enter the email you sign in with and we will send you a link to choose a new one.</p>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" data-ajax novalidate>
        @csrf

        <div class="field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                   autocomplete="username" inputmode="email"
                   aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
            @error('email')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">Send the link</button>
    </form>

    <p class="foot"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
