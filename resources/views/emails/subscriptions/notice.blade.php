@extends('emails.subscriptions.layout')

@use('App\Services\SubscriptionNoticeService', 'Notice')

@php
    $when = $endsAt?->copy()->timezone('Asia/Kolkata')->format('j M Y, g:i A');
@endphp

@section('heading')
    @switch($kind)
        @case(Notice::ENDING_2) Your {{ $what }} ends in 2 days @break
        @case(Notice::ENDING_1) Your {{ $what }} ends tomorrow @break
        @case(Notice::ENDING_0) Your {{ $what }} ends today @break
        @default Your {{ $what }} has ended
    @endswitch
@endsection

@section('intro')
    Hello {{ $recipientName }},
    @if ($kind === Notice::ENDED)
        the {{ $what }} for <strong>{{ $business }}</strong> ended on {{ $when }}, so the account is paused.
        Your menu, bills, customers and reports are all safe - renew and everything is back exactly as you left it.
    @else
        the {{ $what }} for <strong>{{ $business }}</strong> ends on <strong>{{ $when }}</strong>.
        Renew or choose a plan before then and your restaurant keeps running without a break.
    @endif
@endsection

@section('details')
    <strong style="color:#111827;">Plan</strong><br>{{ $plan ?? '—' }}<br><br>
    <strong style="color:#111827;">{{ $kind === Notice::ENDED ? 'Ended' : 'Ends' }}</strong><br>{{ $when }}<br><br>
    <strong style="color:#111827;">What happens {{ $kind === Notice::ENDED ? 'now' : 'then' }}</strong><br>
    Staff cannot sign in, table QR codes stop taking orders and the API is refused until the plan is renewed.
    Nothing is deleted.
@endsection

@section('cta')
    <a href="{{ $billingUrl }}"
       style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        {{ $kind === Notice::ENDED ? 'Renew now' : 'Renew or change plan' }}
    </a>
@endsection
