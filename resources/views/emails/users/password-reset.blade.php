@extends('emails.subscriptions.layout')

@section('heading', 'Reset your password, '.$name)

@section('align', 'center')

@section('intro')
    Somebody asked to reset the password for your {{ $company }} account. If it was you, choose a new one below.
@endsection

@section('cta')
    <a href="{{ $resetUrl }}"
       style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        Choose a new password
    </a>
@endsection

@section('note')
    This link works for {{ $minutes }} minutes and only once. If you did not ask for it, you can ignore this email.
@endsection
