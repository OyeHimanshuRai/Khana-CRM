@extends('emails.subscriptions.layout')

@section('heading', 'New support request')

@section('intro')
    <strong>{{ $lead->name }}</strong>{{ $lead->business_name ? ' from '.$lead->business_name : '' }} wrote to support from the website. Reply to this email to answer them.
@endsection

@section('details')
    <strong style="color:#111827;">Email</strong> {{ $lead->email }}<br>
    @if ($lead->phone)<strong style="color:#111827;">Phone</strong> {{ $lead->phone }}<br>@endif
    @if ($lead->business_name)<strong style="color:#111827;">Restaurant</strong> {{ $lead->business_name }}<br>@endif
    @if ($lead->city)<strong style="color:#111827;">City</strong> {{ $lead->city }}<br>@endif
    @if ($lead->outlets)<strong style="color:#111827;">Outlets</strong> {{ $lead->outlets }}<br>@endif
    @if ($lead->message)
        <br><strong style="color:#111827;">Message</strong><br>{!! nl2br(e($lead->message)) !!}
    @endif
@endsection

@section('cta')
    <a href="{{ $adminUrl }}"
       style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        Open in the admin
    </a>
@endsection

@section('note')
    The email address was confirmed with a one-time code before the request was sent.
@endsection
