@extends('emails.subscriptions.layout')

@section('heading', 'Welcome aboard, '.$user->name)

@section('intro')
    Your {{ $company }} account for <strong>{{ $business }}</strong> is ready, and you are already signed in.
    @if ($trialEnds)
        Your free trial of the {{ $plan }} plan runs until <strong>{{ $trialEnds->copy()->timezone('Asia/Kolkata')->format('j M Y, g:i A') }}</strong>.
    @endif
@endsection

@section('details')
    <strong style="color:#111827;">Sign in with</strong><br>
    {{ $user->email }}<br>
    <span style="color:#7b8493;">and the password you chose when you signed up.</span>
    <br><br>
    <strong style="color:#111827;">Sign-in page</strong><br>
    <a href="{{ $loginUrl }}" style="color:#f97316;word-break:break-all;">{{ $loginUrl }}</a>
    <br><br>
    <strong style="color:#111827;">To get going</strong><br>
    1. Add your menu &nbsp; 2. Set up your tables and print the QR codes &nbsp; 3. Add your staff
    @if ($verifyUrl)
        <br><br>
        <strong style="color:#111827;">First, confirm this email address</strong><br>
        <a href="{{ $verifyUrl }}" style="color:#f97316;word-break:break-all;">{{ $verifyUrl }}</a>
    @endif
@endsection

@section('cta')
    {{-- A self-signed-up account opens once its email is confirmed. --}}
    <a href="{{ $verifyUrl ?? $loginUrl }}"
       style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        {{ $verifyUrl ? 'Confirm my email' : 'Open my dashboard' }}
    </a>
@endsection

@section('note')
    @if ($trialEnds)
        We will email you before the trial ends. You can choose a plan any time from
        <a href="{{ $billingUrl }}" style="color:#f97316;">your subscription page</a>.
    @else
        Choose and pay for your plan from <a href="{{ $billingUrl }}" style="color:#f97316;">your subscription page</a> to start trading.
    @endif
@endsection
