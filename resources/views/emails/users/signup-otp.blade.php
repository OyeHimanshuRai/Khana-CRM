@extends('emails.subscriptions.layout')

@section('heading', 'Your verification code')

@section('align', 'center')

@section('intro')
    Enter this code on the sign-up page to confirm your email address.
@endsection

@section('details')
    <span style="display:inline-block;font-size:32px;font-weight:700;letter-spacing:8px;color:#111827;">{{ $code }}</span>
@endsection

@section('note')
    This code works for {{ $minutes }} minutes. If you did not ask for it, you can ignore this email.
@endsection
