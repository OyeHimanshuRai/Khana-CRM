@extends('admin.layouts.base')

@section('title', 'Access denied')

@php
    /*
     | Say *why*, when it can be known. The commonest 403 is a restaurant's
     | account opening a page that belongs to the platform (Settings, Plans,
     | Firebase...) - refused by PlatformAbilities whatever the role says, and
     | "you do not have the right permissions" leaves the owner hunting for a
     | role that would never help. Naming the account, and saying that this
     | page is the platform's, tells them to sign in as the platform instead.
     */
    $viewer = auth()->user();
    $permission = collect(request()->route()?->gatherMiddleware() ?? [])
        ->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
    $permission = $permission ? trim(explode(',', substr($permission, 11))[0]) : null;

    $platformPage = $viewer instanceof \App\Models\User
        && $viewer->tenant_id !== null
        && ! $viewer->isSuperAdmin()
        && $permission !== null
        && \App\Support\PlatformAbilities::isPlatformOnly($permission);
@endphp

@section('body')
    <div class="auth-wrap">
        <main class="auth-card" style="max-width:470px;text-align:center">
            <div style="width:66px;height:66px;margin:0 auto 18px;/*border-radius:50%;*/
                        display:grid;place-items:center;
                        background:var(--danger-soft);color:var(--danger)">
                <x-icon name="x" :size="30" :width="2.2" />
            </div>

            <h1 style="font-size:24px">403 &mdash; Access denied</h1>

            @if ($platformPage)
                <p class="lede" style="margin:8px 0 14px">
                    This page belongs to the platform, not to a restaurant.
                </p>

                <p class="text-sm text-muted" style="margin-bottom:22px">
                    You are signed in as <strong>{{ $viewer->email }}</strong>, a restaurant account.
                    Settings like mail, logos, plans and Firebase are shared by every restaurant,
                    so only the platform's Super Admin can open them. Sign out and sign in with the
                    Super Admin account.
                </p>
            @else
                <p class="lede" style="margin:8px 0 22px">
                    {{ $exception?->getMessage() ?: 'You do not have permission to view this page.' }}
                </p>

                <p class="text-sm text-muted" style="margin-bottom:22px">
                    @if ($viewer)
                        Signed in as <strong>{{ $viewer->email }}</strong>.
                    @endif
                    If you believe this is a mistake, ask an administrator to review your role.
                </p>
            @endif

            <div style="display:flex;gap:9px;justify-content:center;flex-wrap:wrap">
                @auth
                    <a class="btn btn-primary" href="{{ route('admin.dashboard') }}">Back to dashboard</a>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="btn">Sign out</button>
                    </form>
                @else
                    <a class="btn btn-primary" href="{{ route('login') }}">Sign in</a>
                @endauth
            </div>
        </main>
    </div>
@endsection
