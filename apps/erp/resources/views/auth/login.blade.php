@extends('khana::layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div class="auth-head">
        <x-khana::brand sub="ERP" />
        <h1 class="auth-title">Sign in</h1>
        <p class="text-sm text-muted">Stock, recipes, purchasing and food cost.</p>
    </div>

    @if (session('error'))
        <div class="alert alert-danger" role="alert" style="margin-bottom:16px">{{ session('error') }}</div>
    @endif

    @if ($throughAccounts)
        <a href="{{ route('khana.sso.login') }}" class="btn btn-primary btn-block">Sign in with Restro One Accounts</a>
        <p class="text-sm text-muted auth-foot">One sign-in for CRM and ERP.</p>
    @elseif ($people->isEmpty())
        <p class="text-sm text-muted">
            Restro One Accounts is not set up on this install, so nobody can sign in yet.
            Set <code>KHANA_ACCOUNTS_URL</code> and <code>KHANA_SSO_CLIENT_ID</code>.
        </p>
    @endif

    {{-- A developer's machine only - see App\Support\SignInMode. --}}
    @if ($people->isNotEmpty())
        <form method="POST" action="{{ route('login.local') }}" class="auth-form" style="margin-top:16px">
            @csrf
            <div class="field">
                <label class="form-label" for="user">Open ERP as</label>
                <select class="form-control" id="user" name="user" required>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}">{{ $person->name }} &middot; {{ $person->roleName() }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-block {{ $throughAccounts ? '' : 'btn-primary' }}">Open</button>
            <p class="text-xs text-muted" style="margin-top:8px">Shown on a development install only.</p>
        </form>
    @endif
@endsection
