@extends('emails.subscriptions.layout')

@section('heading', 'Confirm your email, '.$user->name)

@section('align', 'center')

@section('intro')
    Confirm this is your address to start using your {{ $company }} account.
@endsection

@section('cta')
    <a href="{{ $verifyUrl }}"
       style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        Confirm my email
    </a>
@endsection

@section('note')
    This link works for 7 days. If you did not sign up, you can ignore this email.
@endsection
