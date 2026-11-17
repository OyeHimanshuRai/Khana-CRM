@extends('emails.subscriptions.layout')

@section('heading', 'New sign-up: '.($business ?? $owner->name))

@section('intro')
    A new restaurant has just signed up on RestroOne.
@endsection

@section('details')
    @php
        // One row per fact: label on the left, value on the right. A table, not
        // line breaks, so it stays aligned and compact on a phone.
        $row = 'padding:7px 0;border-bottom:1px solid #eceff4;font-size:13px;line-height:1.5;vertical-align:top;';
        $label = $row.'width:96px;color:#7b8493;';
        $value = $row.'color:#111827;word-break:break-word;';
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr><td style="{{ $label }}">Business</td><td style="{{ $value }}"><strong>{{ $business ?? '—' }}</strong></td></tr>
        <tr><td style="{{ $label }}">Owner</td><td style="{{ $value }}">{{ $owner->name }}</td></tr>
        <tr><td style="{{ $label }}">Email</td><td style="{{ $value }}"><a href="mailto:{{ $owner->email }}" style="color:#f97316;text-decoration:none;">{{ $owner->email }}</a></td></tr>
        <tr><td style="{{ $label }}">Mobile</td><td style="{{ $value }}">{{ $owner->mobile ?: '—' }}</td></tr>
        <tr><td style="{{ $label }}">Plan</td><td style="{{ $value }}">{{ $plan ?? '—' }} &middot; {{ ucfirst((string) $period) }}</td></tr>
        <tr><td style="{{ $label }};border-bottom:0;">Trial ends</td><td style="{{ $value }};border-bottom:0;">{{ $trialEnds ? $trialEnds->copy()->timezone('Asia/Kolkata')->format('j M Y') : 'No trial - payment due now' }}</td></tr>
    </table>
@endsection

@if ($tenantUrl)
    @section('cta')
        <a href="{{ $tenantUrl }}"
           style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
            View company
        </a>
    @endsection
@endif

@section('note')
    A quick welcome call in the first week helps a trial become a customer.
@endsection
