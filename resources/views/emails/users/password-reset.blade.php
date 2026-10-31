@extends('emails.subscriptions.layout')

@section('heading', 'Reset your password, '.$name)

@section('intro')
    Somebody asked to reset the password for your {{ $company }} account. If it was you, open the link below and choose a new one.
@endsection

@section('details')
    <strong style="color:#111827;">Reset link</strong><br>
    <a href="{{ $resetUrl }}" style="color:#f97316;word-break:break-all;">{{ $resetUrl }}</a>
    <br><br>
    <span style="color:#7b8493;">It works for {{ $minutes }} minutes and only once. If you did not ask, ignore this email &mdash; your password stays as it is.</span>
@endsection

@section('cta')
    <a href="{{ $resetUrl }}"
       style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        Choose a new password
    </a>
@endsection
