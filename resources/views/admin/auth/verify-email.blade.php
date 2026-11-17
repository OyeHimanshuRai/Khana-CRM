@extends('admin.layouts.guest')

@section('title', 'Verify your email')

@php
    /*
     | The wide logo when there is one (this is a person who has just signed up,
     | not an administrator, so no "Administration" label), the brand block
     | otherwise.
     */
    $logo = \App\Support\CompanySettings::fileUrl('site_logo');

    // A button to the inbox, for the providers where the address says which.
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    $inbox = match (true) {
        in_array($domain, ['gmail.com', 'googlemail.com'], true) => ['Open Gmail', 'https://mail.google.com/'],
        in_array($domain, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'], true) => ['Open Outlook', 'https://outlook.live.com/mail/'],
        str_starts_with($domain, 'yahoo.') || $domain === 'ymail.com' => ['Open Yahoo Mail', 'https://mail.yahoo.com/'],
        default => null,
    };
@endphp

@section('content')
    <div style="text-align:center">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ \App\Models\Setting::get('company_name', config('app.name')) }}"
                 style="display:block;margin:0 auto 22px;max-width:190px;max-height:56px;height:auto">
        @else
            <x-brand sub="" style="justify-content:center" />
        @endif

        <span style="display:inline-grid;place-items:center;width:56px;height:56px;border-radius:50%;background:var(--brand-soft);color:var(--brand);margin:0 0 14px">
            <x-icon name="mail" :size="26" />
        </span>

        <h1 style="margin:0 0 8px">Verify your email</h1>
        <p class="lede" style="margin:0 0 22px;font-size:14px;line-height:1.6">
            We sent a verification link to<br>
            <strong style="word-break:break-all">{{ $email }}</strong>.<br>
            Click it to activate your account.
        </p>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($inbox)
        <a href="{{ $inbox[1] }}" target="_blank" rel="noopener" class="btn btn-primary btn-block btn-lg">{{ $inbox[0] }}</a>
    @endif

    <form method="POST" action="{{ route('admin.verification.send') }}" data-ajax style="margin-top:10px">
        @csrf
        <button type="submit" class="btn {{ $inbox ? 'btn-ghost' : 'btn-primary' }} btn-block {{ $inbox ? '' : 'btn-lg' }}">Send the link again</button>
    </form>

    <form method="POST" action="{{ route('admin.logout') }}" style="margin-top:6px">
        @csrf
        <button type="submit" class="btn btn-ghost btn-block">Sign out</button>
    </form>

    <p class="foot">
        Not in your inbox? Check the spam folder. Signed up with the wrong address? Sign out and sign up again.
    </p>
@endsection
