@extends('emails.subscriptions.layout')

@section('heading', 'Welcome aboard, '.$user->name)

@section('intro')
    Your registration is confirmed. <strong>{{ $business }}</strong> is now on RestroOne and your account is ready.
    @if ($trialEnds)
        Your free trial of the {{ $plan }} plan runs until <strong>{{ $trialEnds->copy()->timezone('Asia/Kolkata')->format('j M Y') }}</strong>.
    @endif
@endsection

@section('details')
    <strong style="color:#111827;">Sign in with</strong><br>
    {{ $user->email }}
    <span style="color:#7b8493;">and the password you chose.</span>
@endsection

@section('cta')
    {{-- A self-signed-up account opens once its email is confirmed, so that is the button. --}}
    <a href="{{ $verifyUrl ?? $loginUrl }}"
       style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        {{ $verifyUrl ? 'Verify my email' : 'Login' }}
    </a>
@endsection

@section('note')
    @if ($verifyUrl)
        Already verified? <a href="{{ $loginUrl }}" style="color:#f97316;">Log in</a>.
    @endif
    @if ($trialEnds)
        We will email you before the trial ends.
    @else
        Choose and pay for your plan from <a href="{{ $billingUrl }}" style="color:#f97316;">your subscription page</a> to start trading.
    @endif
@endsection
