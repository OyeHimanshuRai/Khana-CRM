@extends('emails.subscriptions.layout')

@section('heading', 'We received your message, '.$lead->name)

@section('intro')
    Thank you for writing to {{ $company }} support. Our team will reply to this email address shortly.
@endsection

@section('details')
    <strong style="color:#111827;">Your message</strong><br>
    @if ($lead->message){!! nl2br(e($lead->message)) !!}@else <span style="color:#7b8493;">(no message)</span>@endif
    @if ($lead->phone)<br><br><strong style="color:#111827;">Phone</strong> {{ $lead->phone }}@endif
@endsection

@section('note')
    You do not need to reply to this email. If you did not write to us, you can ignore it.
    @if ($supportEmail) To reach us again, write to <a href="mailto:{{ $supportEmail }}" style="color:#f97316;">{{ $supportEmail }}</a>.@endif
@endsection
