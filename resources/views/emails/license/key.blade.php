@extends('emails.subscriptions.layout')

@section('heading', 'Your license key')

@section('intro')
    Here is the license key for <strong>{{ $tenant->name }}</strong>@if ($planName) on the <strong>{{ $planName }}</strong> plan @endif. Enter it once and your account starts working straight away.
@endsection

@section('details')
    <div style="margin:4px 0 14px;padding:14px 16px;border:1px dashed #d1d5db;border-radius:8px;background:#f9fafb;text-align:center;">
        <span style="font-family:Consolas,'Courier New',monospace;font-size:20px;font-weight:700;letter-spacing:.06em;color:#111827;">{{ $key }}</span>
    </div>
    <strong style="color:#111827;">How to use it</strong><br>
    1. Sign in to your account.<br>
    2. On the "Activate your license" page, type the key above and press Activate.<br>
    3. That is it - your plan starts immediately.
@endsection

@section('cta')
    <a href="{{ $signInUrl }}"
       style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        Sign in and activate
    </a>
@endsection

@section('note')
    This key works only once, for {{ $tenant->name }} - once it is entered it cannot be used again, so please do not share it. If it was not meant for you, or you need a new one, contact us at {{ $supportEmail ?: 'our support team' }}.
@endsection
