@extends('emails.subscriptions.layout')

@section('heading', 'New sign-up: '.($business ?? $owner->name))

@section('intro')
    Somebody has just started on {{ $company }}.
@endsection

@section('details')
    <strong style="color:#111827;">Business</strong><br>{{ $business ?? '—' }}<br><br>
    <strong style="color:#111827;">Owner</strong><br>{{ $owner->name }}<br><br>
    <strong style="color:#111827;">Email</strong><br>{{ $owner->email }}<br><br>
    <strong style="color:#111827;">Mobile</strong><br>{{ $owner->mobile ?: '—' }}<br><br>
    <strong style="color:#111827;">Plan</strong><br>{{ $plan ?? '—' }} ({{ $period }})<br><br>
    <strong style="color:#111827;">Trial ends</strong><br>
    {{ $trialEnds ? $trialEnds->copy()->timezone('Asia/Kolkata')->format('j M Y, g:i A') : 'No trial - payment due now' }}
@endsection

@if ($tenantUrl)
    @section('cta')
        <a href="{{ $tenantUrl }}"
           style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
            Open the account
        </a>
    @endsection
@endif

@section('note')
    The first week is when a call turns a trial into a customer.
@endsection
