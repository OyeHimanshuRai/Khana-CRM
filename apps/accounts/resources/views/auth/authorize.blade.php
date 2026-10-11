@extends('khana::layouts.guest')

@section('title', 'Continue')

@section('content')
    {{--
        Passport's consent screen. Nobody normally sees it: every client is
        one of our own products, so a person who is signed in goes straight
        back to it (App\Models\Passport\Client). It is drawn only when a
        request asks for it by name (prompt=consent) - and Passport needs it
        to exist either way, or every sign-in fails before it starts.
    --}}
    @php $product = \Illuminate\Support\Str::before($client->name, ' sign-in'); @endphp

    <div class="auth-head">
        <x-khana::brand sub="Accounts" />
        <h1 class="auth-title">Continue to {{ $product }}?</h1>
        <p class="text-sm text-muted">
            You are signed in as {{ $user->name }}. {{ $product }} will be told who you are and which outlets you
            may open.
        </p>
    </div>

    <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="auth-form">
        @csrf
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">

        <button type="submit" class="btn btn-primary btn-block">Continue</button>
    </form>

    <form method="POST" action="{{ route('passport.authorizations.deny') }}" style="margin-top:10px">
        @csrf
        @method('DELETE')
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">

        <button type="submit" class="btn btn-block">Not now</button>
    </form>
@endsection
