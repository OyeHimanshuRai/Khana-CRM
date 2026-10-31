@extends('emails.subscriptions.layout')

@section('heading', 'Confirm your email, '.$user->name)

@section('intro')
    Open the link below to confirm this is your address and start using your {{ $company }} account.
@endsection

@section('details')
    <strong style="color:#111827;">Confirmation link</strong><br>
    <a href="{{ $verifyUrl }}" style="color:#f97316;word-break:break-all;">{{ $verifyUrl }}</a>
    <br><br>
    <span style="color:#7b8493;">It works for 7 days. If you did not sign up, ignore this email.</span>
@endsection

@section('cta')
    <a href="{{ $verifyUrl }}"
       style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        Confirm my email
    </a>
@endsection
