@extends('emails.subscriptions.layout')

@section('heading', 'Payment received - thank you')

@section('intro')
    Hello {{ $recipientName }}, we have received your payment for <strong>{{ $business }}</strong>.
    Your {{ $plan }} plan now runs until
    <strong>{{ $payment->period_end?->copy()->timezone('Asia/Kolkata')->format('j M Y') }}</strong>.
@endsection

@section('details')
    <strong style="color:#111827;">Receipt</strong><br>{{ $payment->reference }}<br><br>
    <strong style="color:#111827;">Amount</strong><br>{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}<br><br>
    <strong style="color:#111827;">Paid on</strong><br>{{ $payment->paid_at?->copy()->timezone('Asia/Kolkata')->format('j M Y, g:i A') }}<br><br>
    <strong style="color:#111827;">Period</strong><br>
    {{ $payment->period_start?->copy()->timezone('Asia/Kolkata')->format('j M Y') }}
    to {{ $payment->period_end?->copy()->timezone('Asia/Kolkata')->format('j M Y') }}
@endsection

@section('cta')
    <a href="{{ $invoiceUrl }}"
       style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        View invoice
    </a>
@endsection

@section('note')
    Your invoices are always on <a href="{{ $billingUrl }}" style="color:#f97316;">your subscription page</a>.
@endsection
