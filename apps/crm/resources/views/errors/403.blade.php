@extends('admin.layouts.base')

@section('title', 'Access denied')

@section('body')
    <div class="auth-wrap">
        <main class="auth-card" style="max-width:470px;text-align:center">
            <div style="width:66px;height:66px;margin:0 auto 18px;/*border-radius:50%;*/
                        display:grid;place-items:center;
                        background:var(--danger-soft);color:var(--danger)">
                <x-icon name="x" :size="30" :width="2.2" />
            </div>

            <h1 style="font-size:24px">403 &mdash; Access denied</h1>

            <p class="lede" style="margin:8px 0 22px">
                {{ $exception?->getMessage() ?: 'You do not have permission to view this page.' }}
            </p>

            <p class="text-sm text-muted" style="margin-bottom:22px">
                If you believe this is a mistake, ask an administrator to review your role.
            </p>

            <div style="display:flex;gap:9px;justify-content:center;flex-wrap:wrap">
                @auth
                    <a class="btn btn-primary" href="{{ route('admin.dashboard') }}">Back to dashboard</a>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="btn">Sign out</button>
                    </form>
                @else
                    <a class="btn btn-primary" href="{{ route('admin.login') }}">Sign in</a>
                @endauth
            </div>
        </main>
    </div>
@endsection
